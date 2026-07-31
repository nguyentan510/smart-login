<?php
/**
 * Google OpenID Connect authorization-code provider.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

use SmartLogin\Auth\OAuthTransactionStore;
use SmartLogin\Settings;
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
		return __( 'Tiếp tục với Google', 'smart-login' ); }

	public function name(): string {
		return 'Google'; }

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
			return new WP_Error( 'smart_login_provider_unavailable', __( 'Google Login chưa được cấu hình.', 'smart-login' ) );
		}

		$transaction = $request['_transaction'] ?? null;
		$code        = trim( (string) ( $request['code'] ?? '' ) );
		if ( ! is_array( $transaction ) || '' === $code ) {
			return new WP_Error( 'smart_login_google_callback', __( 'Google không trả về mã xác thực hợp lệ.', 'smart-login' ) );
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

		$tokens = $this->json_response( $response, 'smart_login_google_token' );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$id_token = (string) ( $tokens['id_token'] ?? '' );
		if ( '' === $id_token ) {
			return new WP_Error( 'smart_login_google_token', __( 'Google không trả về ID token.', 'smart-login' ) );
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
			return new WP_Error( 'smart_login_google_claims', __( 'Google ID token không đạt điều kiện xác thực.', 'smart-login' ) );
		}

		$subject = trim( (string) ( $claims['sub'] ?? '' ) );
		if ( '' === $subject ) {
			return new WP_Error( 'smart_login_google_claims', __( 'Google không trả về định danh tài khoản.', 'smart-login' ) );
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
			return new WP_Error( $code, __( 'Không thể kết nối tới Google. Vui lòng thử lại.', 'smart-login' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new WP_Error( $code, __( 'Google từ chối yêu cầu đăng nhập.', 'smart-login' ) );
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
