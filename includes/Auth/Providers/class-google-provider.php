<?php
/**
 * Google OpenID Connect authorization-code provider.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth\Providers;

use OmniWP\Auth\OAuthTransactionStore;
use OmniWP\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GoogleProvider implements LoginProviderInterface {

	private OAuthTransactionStore $transactions;

	public function __construct( ?OAuthTransactionStore $transactions = null ) {
		$this->transactions = $transactions ?? new OAuthTransactionStore();
	}

	public function id(): string {
		return 'google'; }
	public function label(): string {
		return __( 'Tiếp tục với Google', 'omniwp' ); }

	public function name(): string {
		return 'Google'; }

	/**
	 * Google's own G, at its own four colours.
	 *
	 * Reproduced from the mark Google ships with its sign-in button assets. The
	 * hex values are normative — the branding guidelines allow the full-colour G
	 * on a white button and forbid recolouring it, so this is one of the few
	 * strings in the plugin that a site owner should not be tempted to theme.
	 */
	public function icon_svg(): string {
		return '<svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">'
			. '<path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64v5.52h7.11c4.16-3.83 6.56-9.47 6.56-16.17z"/>'
			. '<path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.7C7.96 41.07 15.4 46 24 46z"/>'
			. '<path fill="#FBBC05" d="M11.69 28.18C11.25 26.86 11 25.45 11 24s.25-2.86.69-4.18v-5.7H4.34C2.85 17.09 2 20.45 2 24s.85 6.91 2.34 9.88l7.35-5.7z"/>'
			. '<path fill="#EA4335" d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z"/>'
			. '</svg>';
	}

	public function is_available(): bool {
		return Settings::is_on( 'providers.google.enabled' )
			&& ProviderCredentials::is_configured( $this->id() );
	}

	public function begin( string $return_url = '', bool $linking = false ): ProviderRedirect {
		$transaction = $this->transactions->create( $this->id(), $return_url, $linking, get_current_user_id() );
		$params      = array(
			'client_id'             => ProviderCredentials::client_id( $this->id() ),
			'redirect_uri'          => $this->callback_url(),
			'response_type'         => 'code',
			'scope'                 => 'openid email profile',
			'state'                 => $transaction['state'],
			'nonce'                 => $transaction['nonce'],
			'code_challenge'        => OAuthTransactionStore::challenge( $transaction['pkce_verifier'] ),
			'code_challenge_method' => 'S256',
			'prompt'                => 'select_account',
		);

		return new ProviderRedirect( OAuthAuthorizationUrl::build( 'https://accounts.google.com/o/oauth2/v2/auth', $params ) );
	}

	/** @return ProviderIdentity|WP_Error */
	public function complete( array $request ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'OMNIWP_provider_unavailable', __( 'Google Login chưa được cấu hình.', 'omniwp' ) );
		}

		$transaction = $request['_transaction'] ?? null;
		$code        = trim( (string) ( $request['code'] ?? '' ) );
		if ( ! is_array( $transaction ) || '' === $code ) {
			return new WP_Error( 'OMNIWP_google_callback', __( 'Google không trả về mã xác thực hợp lệ.', 'omniwp' ) );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => ProviderCredentials::client_id( $this->id() ),
					'client_secret' => ProviderCredentials::secret( $this->id() ),
					'redirect_uri'  => $this->callback_url(),
					'grant_type'    => 'authorization_code',
					'code_verifier' => (string) $transaction['pkce_verifier'],
				),
			)
		);

		$tokens = $this->json_response( $response, 'OMNIWP_google_token' );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$id_token = (string) ( $tokens['id_token'] ?? '' );
		if ( '' === $id_token ) {
			return new WP_Error( 'OMNIWP_google_token', __( 'Google không trả về ID token.', 'omniwp' ) );
		}

		$claims = ( new GoogleIdTokenVerifier() )->verify( $id_token );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$issuer   = (string) ( $claims['iss'] ?? '' );
		$audience = $claims['aud'] ?? '';
		$expires  = (int) ( $claims['exp'] ?? 0 );
		$nonce    = (string) ( $claims['nonce'] ?? '' );
		if (
			! in_array( $issuer, array( 'https://accounts.google.com', 'accounts.google.com' ), true )
			|| ! $this->audience_matches( $audience )
			|| $expires <= time()
			|| ! hash_equals( (string) $transaction['nonce'], $nonce )
		) {
			return new WP_Error( 'OMNIWP_google_claims', __( 'Google ID token không đạt điều kiện xác thực.', 'omniwp' ) );
		}

		$subject = trim( (string) ( $claims['sub'] ?? '' ) );
		if ( '' === $subject ) {
			return new WP_Error( 'OMNIWP_google_claims', __( 'Google không trả về định danh tài khoản.', 'omniwp' ) );
		}

		return new ProviderIdentity(
			array(
				'provider'       => $this->id(),
				'subject'        => $subject,
				'email'          => $claims['email'] ?? '',
				'email_verified' => filter_var( $claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				'display_name'   => $claims['name'] ?? '',
				'avatar'         => $claims['picture'] ?? '',
				'claims'         => $this->safe_claims( $claims ),
			)
		);
	}

	public function callback_url(): string {
		return ProviderCredentials::redirect_uri( $this->id() );
	}

	/** @return array|WP_Error */
	private function json_response( $response, string $code ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( $code, __( 'Không thể kết nối tới Google. Vui lòng thử lại.', 'omniwp' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new WP_Error( $code, __( 'Google từ chối yêu cầu đăng nhập.', 'omniwp' ) );
		}
		return $data;
	}

	private function safe_claims( array $claims ): array {
		return array_intersect_key( $claims, array_flip( array( 'sub', 'email', 'email_verified', 'name', 'picture', 'hd', 'iss', 'aud' ) ) );
	}

	private function audience_matches( $audience ): bool {
		$client_id = ProviderCredentials::client_id( $this->id() );
		if ( is_array( $audience ) ) {
			return in_array( $client_id, array_map( 'strval', $audience ), true );
		}
		return hash_equals( $client_id, (string) $audience );
	}
}
