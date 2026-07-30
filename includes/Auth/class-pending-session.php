<?php
/**
 * Keeps track of the OTP flow the browser is currently in.
 *
 * The OTP token lives in an HttpOnly cookie rather than a hidden field, so it
 * never leaks through a Referer header, browser history or a copy-pasted URL.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class PendingSession {

	const COOKIE = 'smart_login_flow';

	/** Extra grace on top of the code TTL so the "expired" screen can render. */
	const GRACE = 900;

	/**
	 * Remember the token for the pending flow.
	 */
	public static function start( string $token, string $intent ): void {
		$value = wp_json_encode(
			array(
				'token'  => $token,
				'intent' => $intent,
			)
		);

		self::set_cookie( self::sign( $value ), time() + Settings::get_int( 'otp_ttl', 300 ) + self::GRACE );
	}

	/**
	 * @return array{token:string,intent:string}|null
	 */
	public static function get(): ?array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$value = self::unsign( $raw );

		if ( null === $value ) {
			return null;
		}

		$data = json_decode( $value, true );

		if ( ! is_array( $data ) || empty( $data['token'] ) ) {
			return null;
		}

		return array(
			'token'  => (string) $data['token'],
			'intent' => (string) ( $data['intent'] ?? '' ),
		);
	}

	public static function token(): string {
		$data = self::get();

		return $data ? $data['token'] : '';
	}

	public static function clear(): void {
		self::set_cookie( '', time() - DAY_IN_SECONDS );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Short-lived grant issued after a password-reset OTP has been verified.
	 * Separate from the OTP row so a verified code cannot be replayed.
	 *
	 * @return string The grant token to carry into the new-password form.
	 */
	public static function grant_password_reset( int $user_id ): string {
		$grant = bin2hex( random_bytes( 32 ) );

		set_transient( 'smart_login_reset_' . $grant, $user_id, 10 * MINUTE_IN_SECONDS );

		return $grant;
	}

	/**
	 * @return int User ID, or 0 when the grant is unknown/expired.
	 */
	public static function consume_password_reset( string $grant ): int {
		$grant = preg_replace( '/[^a-f0-9]/', '', $grant );

		if ( '' === $grant ) {
			return 0;
		}

		$user_id = (int) get_transient( 'smart_login_reset_' . $grant );

		if ( $user_id > 0 && delete_transient( 'smart_login_reset_' . $grant ) ) {
			return $user_id;
		}

		return 0;
	}

	// -----------------------------------------------------------------

	private static function set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		if ( '' === $value ) {
			return;
		}

		// Make the value readable within the same request.
		$_COOKIE[ self::COOKIE ] = $value;
	}

	private static function sign( string $value ): string {
		$encoded = base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}

	/**
	 * @return string|null Null when the signature does not match.
	 */
	private static function unsign( string $signed ): ?string {
		if ( false === strpos( $signed, '.' ) ) {
			return null;
		}

		list( $encoded, $sig ) = explode( '.', $signed, 2 );

		if ( ! hash_equals( hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ), $sig ) ) {
			return null;
		}

		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		return false === $decoded ? null : $decoded;
	}
}
