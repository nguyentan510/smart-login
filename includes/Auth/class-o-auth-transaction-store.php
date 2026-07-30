<?php
/**
 * One-time OAuth state, nonce and PKCE verifier storage.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OAuthTransactionStore {

	const PREFIX = 'smart_login_oauth_';
	const TTL    = 600;

	public function create( string $provider, string $return_url = '', bool $linking = false, int $user_id = 0 ): array {
		$state = $this->random();
		$data = array(
			'provider'      => sanitize_key( $provider ),
			'nonce'         => $this->random(),
			'pkce_verifier' => $this->random( 64 ),
			'return_url'    => wp_validate_redirect( $return_url, '' ),
			'linking'       => $linking,
			'user_id'       => $user_id,
			'created_at'    => time(),
		);

		set_transient( self::PREFIX . $state, $data, self::TTL );

		return $data + array( 'state' => $state );
	}

	/** @return array|WP_Error */
	public function consume( string $state, string $provider ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,128}$/', $state ) ) {
			return new WP_Error( 'smart_login_oauth_state', __( 'Phiên đăng nhập nhà cung cấp không hợp lệ.', 'smart-login' ) );
		}

		$key = self::PREFIX . $state;
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $data ) || sanitize_key( $provider ) !== ( $data['provider'] ?? '' ) ) {
			return new WP_Error( 'smart_login_oauth_state', __( 'Phiên đăng nhập nhà cung cấp đã hết hạn hoặc đã được sử dụng.', 'smart-login' ) );
		}

		return $data;
	}

	public static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function random( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}
}
