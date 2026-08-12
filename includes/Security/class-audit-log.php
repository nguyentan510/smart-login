<?php
/**
 * Append-only audit trail for authentication events.
 *
 * Never records OTP codes, passwords or full identifiers in the clear.
 *
 * @package OmniWP
 */

namespace OmniWP\Security;

use OmniWP\Installer;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class AuditLog {

	const REGISTER_STARTED       = 'register_started';
	const REGISTER_REFUSED       = 'register_refused';
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
	const CONTACT_CANCELLED      = 'contact_cancelled';
	const AUTOMATION_BUS_FAILED  = 'automation_bus_failed';

	/**
	 * Every event name, derived from the constants above.
	 *
	 * Generated rather than typed out, so a constant added later is subscribable
	 * without a second edit — the same argument FieldRegistry makes for settings.
	 * Array constants are skipped; only the string ones are events.
	 *
	 * @return string[]
	 */
	public static function events(): array {
		static $events = null;

		if ( null !== $events ) {
			return $events;
		}

		$events = array();

		foreach ( ( new \ReflectionClass( self::class ) )->getConstants() as $value ) {
			if ( is_string( $value ) ) {
				$events[] = $value;
			}
		}

		sort( $events );

		return $events;
	}

	/**
	 * @param string $event           One of the constants above.
	 * @param string $identity_masked Already masked, e.g. 096••••475.
	 * @param array  $meta            Extra context; secrets are stripped.
	 * @param int    $user_id         0 when unknown.
	 */
	/**
	 * Events that are never sampled, whatever the volume.
	 *
	 * All low-volume and all forensically load-bearing: a cap that discards them
	 * to survive a flood has thrown away the record of the flood. Everything else
	 * degrades to one aggregated row per hour.
	 */
	const NEVER_SAMPLED = array(
		self::LOCKOUT,
		self::USER_REGISTERED,
		self::PASSWORD_RESET,
		self::OTP_BUDGET_HALTED,
		self::TRANSPORT_BREAKER_OPEN,
		self::IDENTITY_RETIRED,
		self::PROVIDER_LOGIN,
		self::PROVIDER_FAILED,
		self::PROVIDER_LINKED,
		self::PROVIDER_UNLINKED,
		// The only record that a fire-and-forget channel failed. Sampling the
		// evidence of a silent failure is the worst of both.
		self::AUTOMATION_BUS_FAILED,
	);

	public static function record( string $event, string $identity_masked = '', array $meta = array(), int $user_id = 0 ): void {
		if ( ! Settings::is_on( 'advanced.audit_enabled' ) ) {
			return;
		}

		if ( ! self::may_write( $event ) ) {
			return;
		}

		$meta = self::scrub( $meta );

		/*
		 * The bus rides the same funnel, and deliberately the same cap.
		 *
		 * 9.9 made this log stop amplifying the attack it records: past the
		 * hourly cap an event writes one summary row instead of thousands. An
		 * outbound HTTP call costs strictly more than an INSERT, so a bus that
		 * fired below the cap would rebuild that defect one layer up and aim it
		 * at somebody else's server.
		 *
		 * The cost is a real coupling, written down here and in the help text
		 * rather than discovered: turning the audit log off turns the bus off
		 * too, because `may_write()` is never reached.
		 */
		( new \OmniWP\OTP\Transports\EventBus() )->dispatch( $event, $identity_masked, $meta, $user_id );

		self::write( $event, $identity_masked, $meta, $user_id );
	}

	/**
	 * The insert itself, past every gate.
	 *
	 * Split out so the hourly summary row can be written without re-entering the
	 * cap that produced it — which would either drop the summary or recurse.
	 */
	private static function write( string $event, string $identity_masked, array $meta, int $user_id ): void {
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
	 * Decide whether this event may still write its own row this hour.
	 *
	 * Until 9.9 the log wrote one row per failed request, so an attack cost the
	 * operator an unbounded INSERT stream and a table that outgrew its daily
	 * sweep — the audit log was an amplifier for the very attack it exists to
	 * record. Worse, the kill switch did not stop it: RateLimiter returns before
	 * the counting queries, but OtpService still records RATE_LIMITED on every
	 * blocked request, so a halted site was shedding three SELECTs and keeping
	 * one INSERT.
	 *
	 * Past the cap the event stops writing individual rows and writes one
	 * aggregated row per hour instead, so the signal that something is happening
	 * survives even though the detail does not.
	 */
	private static function may_write( string $event ): bool {
		if ( in_array( $event, self::NEVER_SAMPLED, true ) ) {
			return true;
		}

		$cap = Settings::get_int( 'security.audit_max_per_event_hour', 500 );

		if ( $cap <= 0 ) {
			return true;
		}

		$key   = 'OMNIWP_audit_' . md5( $event . '|' . gmdate( 'YmdH' ) );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		if ( $count < $cap ) {
			return true;
		}

		// Exactly at the cap, write the one summary row that says the rest were
		// dropped. Above it, write nothing at all.
		if ( $count === $cap ) {
			self::write(
				$event . '_summary',
				'',
				array(
					'capped_at' => $cap,
					'note'      => 'further events of this type are not recorded this hour',
				),
				0
			);
		}

		return false;
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
