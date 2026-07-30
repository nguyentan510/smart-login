<?php
/**
 * Which step of the auth flow the current request should render.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

defined( 'ABSPATH' ) || exit;

class Flow {

	const STEP_LOGIN    = 'login';
	const STEP_REGISTER = 'register';
	const STEP_OTP      = 'otp';
	const STEP_FORGOT   = 'forgot';
	const STEP_RESET    = 'reset';
	const STEP_DONE     = 'done';

	/** @var string|null */
	private static $step = null;

	/** @var array<string,mixed> */
	private static $data = array();

	/** @var array<string,string> Values to re-populate a rejected form with. */
	private static $old = array();

	public static function set( string $step, array $data = array() ): void {
		self::$step = $step;
		self::$data = $data;
	}

	/**
	 * @param string $fallback Step to use when nothing has been set.
	 */
	public static function step( string $fallback = self::STEP_LOGIN ): string {
		if ( null !== self::$step ) {
			return self::$step;
		}

		$requested = isset( $_GET['smart_login_step'] ) ? sanitize_key( wp_unslash( $_GET['smart_login_step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$allowed = array( self::STEP_LOGIN, self::STEP_REGISTER, self::STEP_OTP, self::STEP_FORGOT, self::STEP_RESET );

		return in_array( $requested, $allowed, true ) ? $requested : $fallback;
	}

	/**
	 * @return mixed
	 */
	public static function data( string $key, $default = null ) {
		return self::$data[ $key ] ?? $default;
	}

	public static function all_data(): array {
		return self::$data;
	}

	/**
	 * Remember submitted values so a failed form does not lose the user's typing.
	 * Passwords are deliberately never retained.
	 */
	public static function remember( array $values ): void {
		unset( $values['password'], $values['password_confirm'], $values['pass'] );

		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				self::$old[ $key ] = (string) $value;
			}
		}
	}

	public static function old( string $key, string $default = '' ): string {
		return self::$old[ $key ] ?? $default;
	}

	/**
	 * Link to another step of the flow on the current page.
	 */
	public static function url( string $step ): string {
		$base = remove_query_arg( array( 'smart_login_step', 'smartlogin_welcome' ) );
		$url  = add_query_arg( 'smart_login_step', $step, $base );

		/**
		 * @param string $url
		 * @param string $step
		 */
		return (string) apply_filters( 'smart_login_step_url', $url, $step );
	}
}
