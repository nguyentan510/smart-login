<?php
/**
 * One place that decides whether a password is acceptable.
 *
 * It existed only inside RegisterHandler before, so the documented
 * `smart_login_validate_password` filter ran on registration but not on reset —
 * a site enforcing "must contain a digit" could have that requirement bypassed
 * simply by going through Forgot password. A policy applied on one of two paths
 * is not a policy.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PasswordPolicy {

	/** Floor beneath which the setting cannot go. */
	const ABSOLUTE_MIN = 6;

	public static function min_length(): int {
		return max( self::ABSOLUTE_MIN, Settings::get_int( 'min_password_length', 8 ) );
	}

	/**
	 * @param string      $password     Plaintext, never sanitised — any
	 *                                  transformation would change the password.
	 * @param string|null $confirmation Second field, or null when there isn't one.
	 * @return true|WP_Error
	 */
	public static function validate( string $password, ?string $confirmation = null ) {
		if ( '' === $password ) {
			return new WP_Error( 'smart_login_no_password', __( 'Vui lòng nhập mật khẩu.', 'smart-login' ) );
		}

		$min = self::min_length();

		if ( mb_strlen( $password ) < $min ) {
			return new WP_Error(
				'smart_login_weak_password',
				sprintf(
					/* translators: %d: minimum length. */
					__( 'Mật khẩu phải có ít nhất %d ký tự.', 'smart-login' ),
					$min
				)
			);
		}

		if ( null !== $confirmation && $confirmation !== $password ) {
			return new WP_Error( 'smart_login_password_mismatch', __( 'Mật khẩu nhập lại không khớp.', 'smart-login' ) );
		}

		/**
		 * Enforce an additional password policy.
		 *
		 * Runs on registration AND on password reset. Return a WP_Error to refuse.
		 *
		 * @param true|WP_Error $result
		 * @param string        $password
		 */
		$extra = apply_filters( 'smart_login_validate_password', true, $password );

		return is_wp_error( $extra ) ? $extra : true;
	}
}
