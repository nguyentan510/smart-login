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

	/** Sign the person in at the end. What every transaction used to be. */
	const MODE_LOGIN = 'login';

	/**
	 * Exercise the round trip and stop.
	 *
	 * A connection test has to be a real OAuth exchange — a redirect URI cannot
	 * be verified any other way, since no provider will answer "is this URI
	 * registered". What makes that safe rather than reckless is this mode: the
	 * callback returns after reading the identity and before anything writes.
	 */
	const MODE_TEST = 'test';

	/** @var string */
	private $mode;

	/**
	 * The mode is set on the store, not passed to create().
	 *
	 * Providers build their own transaction inside `begin()`, and `begin()` is
	 * on `LoginProviderInterface` — a documented extension point that
	 * `smart_login_providers` invites third parties to implement. Widening that
	 * signature would break every implementation that already matches it, so the
	 * mode arrives through the collaborator both shipped providers already take
	 * by constructor instead.
	 */
	public function __construct( string $mode = self::MODE_LOGIN ) {
		$this->mode = self::MODE_TEST === $mode ? self::MODE_TEST : self::MODE_LOGIN;
	}

	public function create( string $provider, string $return_url = '', bool $linking = false, int $user_id = 0 ): array {
		$state = $this->random();
		$data  = array(
			'provider'      => sanitize_key( $provider ),
			'nonce'         => $this->random(),
			'pkce_verifier' => $this->random( 64 ),
			'return_url'    => wp_validate_redirect( $return_url, '' ),
			'linking'       => $linking,
			'mode'          => $this->mode,
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

		$key  = self::PREFIX . $state;
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $data ) || sanitize_key( $provider ) !== ( $data['provider'] ?? '' ) ) {
			return new WP_Error( 'smart_login_oauth_state', __( 'Phiên đăng nhập nhà cung cấp đã hết hạn hoặc đã được sử dụng.', 'smart-login' ) );
		}

		return $data;
	}

	/**
	 * Is this a diagnostic round trip?
	 *
	 * A transaction created before this mode existed has no `mode` key and is a
	 * login, because that is all there was. Defaulting the other way would turn
	 * every in-flight redirect across the deploy into a test that silently
	 * refused to sign its user in.
	 *
	 * @param array|mixed $transaction As returned by consume().
	 */
	public static function is_test( $transaction ): bool {
		return is_array( $transaction ) && self::MODE_TEST === ( $transaction['mode'] ?? self::MODE_LOGIN );
	}

	public static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function random( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}
}
