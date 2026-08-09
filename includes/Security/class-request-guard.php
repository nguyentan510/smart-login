<?php
/**
 * Form integrity: nonce, honeypot and minimum fill time.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class RequestGuard {

	const NONCE_FIELD    = 'smart_login_nonce';
	const HONEYPOT_FIELD = 'smart_login_website';
	const TIME_FIELD     = 'smart_login_ts';

	/** Humans need at least this long to fill a form. */
	const MIN_FILL_SECONDS = 2;

	/** Forms older than this are stale; the nonce would be near expiry anyway. */
	const MAX_FORM_AGE = 3600;

	/**
	 * Echo the hidden fields every plugin form must carry.
	 */
	public static function fields( string $action, string $prefix = '' ): void {
		$nonce_field = self::NONCE_FIELD . $prefix;
		$time_field  = self::TIME_FIELD . $prefix;
		$honeypot    = self::HONEYPOT_FIELD . $prefix;

		wp_nonce_field( 'smart_login_' . $action, $nonce_field );

		$ts    = time();
		$stamp = $ts . '.' . self::sign( $ts, $action );

		printf(
			'<input type="hidden" name="%1$s" value="%2$s" />',
			esc_attr( $time_field ),
			esc_attr( $stamp )
		);

		// Honeypot: hidden from humans, irresistible to naive bots.
		printf(
			'<div class="smart-login-hp" aria-hidden="true"><label>%1$s<input type="text" name="%2$s" value="" tabindex="-1" autocomplete="off" /></label></div>',
			esc_html__( 'Để trống ô này', 'smart-login' ),
			esc_attr( $honeypot )
		);
	}

	private static function sign( int $ts, string $action ): string {
		return hash_hmac( 'sha256', $ts . '|' . $action, wp_salt( 'nonce' ) );
	}

	/**
	 * A signed timestamp on its own, for clients that are not HTML forms.
	 *
	 * fields() prints hidden inputs; the JSON path needs the same value as a
	 * string it can put in a request body. Same signature, same verifier — the
	 * point is that there is one implementation rather than two that drift.
	 */
	public static function stamp( string $action ): string {
		$ts = time();

		return $ts . '.' . self::sign( $ts, $action );
	}

	/**
	 * @param string $action
	 * @param array  $request Usually $_POST (unslashed by the caller is not required).
	 * @param string $prefix
	 * @return true|WP_Error
	 */
	public static function verify( string $action, array $request, string $prefix = '' ) {
		$nonce_field = self::NONCE_FIELD . $prefix;
		$time_field  = self::TIME_FIELD . $prefix;
		$honeypot    = self::HONEYPOT_FIELD . $prefix;
		$nonce       = isset( $request[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $request[ $nonce_field ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'smart_login_' . $action ) ) {
			return new WP_Error(
				'smart_login_bad_nonce',
				__( 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.', 'smart-login' )
			);
		}

		// Honeypot must be untouched.
		if ( ! empty( $request[ $honeypot ] ) ) {
			AuditLog::record(
				AuditLog::RATE_LIMITED,
				'',
				array(
					'reason' => 'honeypot',
					'action' => $action,
				)
			);

			return new WP_Error(
				'smart_login_bot',
				__( 'Yêu cầu không hợp lệ.', 'smart-login' )
			);
		}

		$stamp = isset( $request[ $time_field ] ) ? sanitize_text_field( wp_unslash( $request[ $time_field ] ) ) : '';

		if ( false !== strpos( $stamp, '.' ) ) {
			list( $ts, $sig ) = explode( '.', $stamp, 2 );
			$ts               = (int) $ts;

			if ( ! hash_equals( self::sign( $ts, $action ), $sig ) ) {
				return new WP_Error( 'smart_login_bad_stamp', __( 'Yêu cầu không hợp lệ.', 'smart-login' ) );
			}

			$age = time() - $ts;

			if ( $age < self::MIN_FILL_SECONDS ) {
				return new WP_Error(
					'smart_login_too_fast',
					__( 'Bạn thao tác quá nhanh. Vui lòng thử lại.', 'smart-login' )
				);
			}

			if ( $age > self::MAX_FORM_AGE ) {
				return new WP_Error(
					'smart_login_stale',
					__( 'Biểu mẫu đã hết hạn. Vui lòng tải lại trang.', 'smart-login' )
				);
			}
		}

		return true;
	}

	/**
	 * REST variant: the nonce is the standard wp_rest cookie nonce, and the
	 * honeypot/timing fields ride along in the JSON body.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_rest( string $action, array $params ) {
		if ( ! empty( $params[ self::HONEYPOT_FIELD ] ) ) {
			return new WP_Error( 'smart_login_bot', __( 'Yêu cầu không hợp lệ.', 'smart-login' ) );
		}

		$stamp = isset( $params[ self::TIME_FIELD ] ) ? sanitize_text_field( wp_unslash( $params[ self::TIME_FIELD ] ) ) : '';

		// Verified only when present, exactly as the form path does. A cookie-less
		// native client that sends no stamp must not be locked out — this is
		// parity work, not a new requirement, and treating absence as failure
		// would make it a breaking change wearing a security label.
		if ( false === strpos( $stamp, '.' ) ) {
			return true;
		}

		list( $ts, $sig ) = explode( '.', $stamp, 2 );
		$ts               = (int) $ts;

		if ( ! hash_equals( self::sign( $ts, $action ), $sig ) ) {
			return new WP_Error( 'smart_login_bad_stamp', __( 'Yêu cầu không hợp lệ.', 'smart-login' ) );
		}

		$age = time() - $ts;

		if ( $age < self::MIN_FILL_SECONDS ) {
			return new WP_Error(
				'smart_login_too_fast',
				__( 'Bạn thao tác quá nhanh. Vui lòng thử lại.', 'smart-login' )
			);
		}

		if ( $age > self::MAX_FORM_AGE ) {
			return new WP_Error(
				'smart_login_stale',
				__( 'Biểu mẫu đã hết hạn. Vui lòng tải lại trang.', 'smart-login' )
			);
		}

		return true;
	}
}
