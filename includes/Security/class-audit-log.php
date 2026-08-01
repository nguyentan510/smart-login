<?php
/**
 * Append-only audit trail for authentication events.
 *
 * Never records OTP codes, passwords or full identifiers in the clear.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Security;

use SmartLogin\Installer;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class AuditLog {

	const REGISTER_STARTED       = 'register_started';
	const OTP_SENT               = 'otp_sent';
	const OTP_SEND_FAILED        = 'otp_send_failed';
	const OTP_VERIFIED           = 'otp_verified';
	const OTP_FAILED             = 'otp_failed';
	const OTP_EXPIRED            = 'otp_expired';
	const USER_REGISTERED        = 'user_registered';
	const LOGIN_SUCCESS          = 'login_success';
	const LOGIN_FAILED           = 'login_failed';
	const LOCKOUT                = 'lockout';
	const RATE_LIMITED           = 'rate_limited';
	const OTP_BUDGET_HALTED      = 'otp_budget_halted';
	const TRANSPORT_BREAKER_OPEN = 'transport_breaker_open';
	const PASSWORD_RESET         = 'password_reset';
	const PROVIDER_LOGIN         = 'provider_login';
	const PROVIDER_FAILED        = 'provider_failed';
	const PROVIDER_LINKED        = 'provider_linked';
	const PROVIDER_UNLINKED      = 'provider_unlinked';
	const IDENTITY_RETIRED       = 'identity_retired';
	const CONTACT_PENDING        = 'contact_pending';
	const CONTACT_VERIFIED       = 'contact_verified';

	/**
	 * @param string $event           One of the constants above.
	 * @param string $identity_masked Already masked, e.g. 096••••475.
	 * @param array  $meta            Extra context; secrets are stripped.
	 * @param int    $user_id         0 when unknown.
	 */
	public static function record( string $event, string $identity_masked = '', array $meta = array(), int $user_id = 0 ): void {
		if ( ! Settings::is_on( 'advanced.audit_enabled' ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::audit_table(),
			array(
				'user_id'         => $user_id ?: null,
				'event'           => substr( $event, 0, 40 ),
				'identity_masked' => substr( $identity_masked, 0, 64 ),
				'ip'              => Client::ip_binary(),
				'user_agent_hash' => Client::user_agent_hash(),
				'meta'            => wp_json_encode( self::scrub( $meta ) ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Remove anything that must never reach persistent storage.
	 */
	private static function scrub( array $meta ): array {
		$forbidden = array( 'code', 'otp', 'password', 'pass', 'pass_hash', 'user_pass', 'token', 'secret', 'authorization', 'api_key', 'apikey' );

		foreach ( $meta as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $forbidden, true ) ) {
				$meta[ $key ] = '***';
				continue;
			}

			if ( is_array( $value ) ) {
				$meta[ $key ] = self::scrub( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 500 ) {
				$meta[ $key ] = substr( $value, 0, 500 ) . '…';
			}
		}

		return $meta;
	}

	/**
	 * Recent entries for the admin screen.
	 */
	public static function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table = Installer::audit_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
