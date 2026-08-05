<?php
/**
 * Zalo Login adapter. Endpoint values can be overridden by filters because
 * Zalo applications may be provisioned against different API versions.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

use SmartLogin\Auth\OAuthTransactionStore;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ZaloProvider implements LoginProviderInterface {

	private OAuthTransactionStore $transactions;

	public function __construct( ?OAuthTransactionStore $transactions = null ) {
		$this->transactions = $transactions ?? new OAuthTransactionStore();
	}

	public function id(): string {
		return 'zalo'; }
	public function label(): string {
		return __( 'Tiếp tục với Zalo', 'smart-login' ); }

	public function name(): string {
		return 'Zalo'; }

	/**
	 * Zalo's blue speech bubble.
	 *
	 * #0068FF is Zalo's blue. The two shades this replaced — #0b74e5 border and
	 * #075eb8 text — were neither of them Zalo's; they were the plugin's guess at
	 * it, which is the same category of mistake as drawing "Z" in a circle and
	 * calling it a logo.
	 *
	 * **The silhouette is an approximation and the wordmark is not here.** Zalo's
	 * mark sets "Zalo" in its own lettering inside the bubble, and that vector is
	 * not something to reconstruct from memory — a logo drawn from recollection
	 * is a wrong logo, only a more convincing one. Drop the official asset from
	 * Zalo's brand kit in through `smart_login_provider_icon_svg` and this is
	 * replaced without patching the plugin. Written down here rather than in a
	 * ticket because here is where somebody with the real file will look.
	 */
	/**
	 * Zalo's own artwork: the rounded speech bubble with the wordmark inside it.
	 *
	 * The geometry is Zalo's, not this plugin's. What was here before was a
	 * hand-drawn bubble with a `Z` in it — recognisable, and not the mark.
	 *
	 * One value was changed from the supplied file: its blue is `#2962ff`, and
	 * this ships `#0068FF`, which is the value the template suite requires and
	 * the one that replaced this plugin's earlier `#0b74e5` guess. The two
	 * disagree and the difference is visible; if `#2962ff` is the current
	 * official value then this constant and that assertion both want updating
	 * together, which is why the colour appears once here rather than inline in
	 * each path.
	 */
	const BRAND_BLUE = '#0068FF';

	public function icon_svg(): string {
		$blue = self::BRAND_BLUE;

		return '<svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">'
			. '<path fill="' . $blue . '" d="M15,36V6.827l-1.211-0.811C8.64,8.083,5,13.112,5,19v10c0,7.732,6.268,14,14,14h10c4.722,0,8.883-2.348,11.417-5.931V36H15z"/>'
			. '<path fill="#EEEEEE" d="M29,5H19c-1.845,0-3.601,0.366-5.214,1.014C10.453,9.25,8,14.528,8,19c0,6.771,0.936,10.735,3.712,14.607c0.216,0.301,0.357,0.653,0.376,1.022c0.043,0.835-0.129,2.365-1.634,3.742c-0.162,0.148-0.059,0.419,0.16,0.428c0.942,0.041,2.843-0.014,4.797-0.877c0.557-0.246,1.191-0.203,1.729,0.083C20.453,39.764,24.333,40,28,40c4.676,0,9.339-1.04,12.417-2.916C42.038,34.799,43,32.014,43,29V19C43,11.268,36.732,5,29,5z"/>'
			. '<path fill="' . $blue . '" d="M36.75,27C34.683,27,33,25.317,33,23.25s1.683-3.75,3.75-3.75s3.75,1.683,3.75,3.75S38.817,27,36.75,27z M36.75,21c-1.24,0-2.25,1.01-2.25,2.25s1.01,2.25,2.25,2.25S39,24.49,39,23.25S37.99,21,36.75,21z"/>'
			. '<path fill="' . $blue . '" d="M31.5,27h-1c-0.276,0-0.5-0.224-0.5-0.5V18h1.5V27z"/>'
			. '<path fill="' . $blue . '" d="M27,19.75v0.519c-0.629-0.476-1.403-0.769-2.25-0.769c-2.067,0-3.75,1.683-3.75,3.75S22.683,27,24.75,27c0.847,0,1.621-0.293,2.25-0.769V26.5c0,0.276,0.224,0.5,0.5,0.5h1v-7.25H27z M24.75,25.5c-1.24,0-2.25-1.01-2.25-2.25S23.51,21,24.75,21S27,22.01,27,23.25S25.99,25.5,24.75,25.5z"/>'
			. '<path fill="' . $blue . '" d="M21.25,18h-8v1.5h5.321L13,26h0.026c-0.163,0.211-0.276,0.463-0.276,0.75V27h7.5c0.276,0,0.5-0.224,0.5-0.5v-1h-5.321L21,19h-0.026c0.163-0.211,0.276-0.463,0.276-0.75V18z"/>'
			. '</svg>';
	}

	public function is_available(): bool {
		return Settings::is_on( 'providers.zalo.enabled' )
			&& ProviderCredentials::is_configured( $this->id() );
	}

	public function begin( string $return_url = '', bool $linking = false ): ProviderRedirect {
		$transaction = $this->transactions->create( $this->id(), $return_url, $linking, get_current_user_id() );
		$params      = array(
			'app_id'                => ProviderCredentials::client_id( $this->id() ),
			'redirect_uri'          => $this->callback_url(),
			'state'                 => $transaction['state'],
			'code_challenge'        => OAuthTransactionStore::challenge( $transaction['pkce_verifier'] ),
			'code_challenge_method' => 'S256',
		);
		$url         = (string) apply_filters( 'smart_login_zalo_authorize_url', 'https://oauth.zaloapp.com/v4/permission' );
		return new ProviderRedirect( OAuthAuthorizationUrl::build( $url, $params ) );
	}

	/** @return ProviderIdentity|WP_Error */
	public function complete( array $request ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'smart_login_provider_unavailable', __( 'Zalo Login chưa được cấu hình.', 'smart-login' ) );
		}
		$transaction = $request['_transaction'] ?? null;
		$code        = trim( (string) ( $request['code'] ?? '' ) );
		if ( ! is_array( $transaction ) || '' === $code ) {
			return new WP_Error( 'smart_login_zalo_callback', __( 'Zalo không trả về mã xác thực hợp lệ.', 'smart-login' ) );
		}

		$token_url = (string) apply_filters( 'smart_login_zalo_token_url', 'https://oauth.zaloapp.com/v4/access_token' );
		$args      = array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'app_id'        => ProviderCredentials::client_id( $this->id() ),
				'app_secret'    => ProviderCredentials::secret( $this->id() ),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->callback_url(),
				'code_verifier' => (string) $transaction['pkce_verifier'],
			),
		);
		$args      = (array) apply_filters( 'smart_login_zalo_token_request', $args, $transaction );
		$tokens    = $this->json_response( wp_remote_post( $token_url, $args ), 'smart_login_zalo_token' );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$access_token = (string) ( $tokens['access_token'] ?? '' );
		if ( '' === $access_token ) {
			return new WP_Error( 'smart_login_zalo_token', __( 'Zalo không trả về access token.', 'smart-login' ) );
		}

		$profile_url = (string) apply_filters( 'smart_login_zalo_profile_url', 'https://graph.zalo.me/v2.0/me?fields=id,name,picture,email' );
		$profile_url = add_query_arg( 'access_token', $access_token, $profile_url );
		$profile     = $this->json_response( wp_remote_get( $profile_url, array( 'timeout' => 15 ) ), 'smart_login_zalo_profile' );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$data    = is_array( $profile['data'] ?? null ) ? $profile['data'] : $profile;
		$subject = trim( (string) ( $data['id'] ?? $data['user_id'] ?? '' ) );
		if ( '' === $subject ) {
			return new WP_Error( 'smart_login_zalo_profile', __( 'Zalo không trả về định danh tài khoản.', 'smart-login' ) );
		}

		$picture = $data['picture'] ?? '';
		if ( is_array( $picture ) ) {
			$picture = $picture['data']['url'] ?? $picture['url'] ?? '';
		}

		return new ProviderIdentity(
			array(
				'provider'       => $this->id(),
				'subject'        => $subject,
				'email'          => $data['email'] ?? '',
				'email_verified' => ! empty( $data['email_verified'] ),
				'display_name'   => $data['name'] ?? '',
				'avatar'         => $picture,
				'claims'         => array_intersect_key( $data, array_flip( array( 'id', 'name', 'email', 'email_verified' ) ) ),
			)
		);
	}

	public function callback_url(): string {
		return ProviderCredentials::redirect_uri( $this->id() );
	}

	/** @return array|WP_Error */
	private function json_response( $response, string $code ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( $code, __( 'Không thể kết nối tới Zalo. Vui lòng thử lại.', 'smart-login' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new WP_Error( $code, __( 'Zalo từ chối yêu cầu đăng nhập.', 'smart-login' ) );
		}
		return $data;
	}
}
