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
