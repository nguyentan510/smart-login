<?php
/**
 * Send-rate and brute-force limits.
 *
 * OTP send limits are derived from rows already in the OTP table, so there is
 * no extra storage to keep in sync. Login lockouts use transients because they
 * are short-lived and high-frequency.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Security;

use SmartLogin\Identity\ChannelRegistry;
use SmartLogin\OTP\OtpRepository;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RateLimiter {

	/** @var OtpRepository */
	private $repo;

	public function __construct( ?OtpRepository $repo = null ) {
		$this->repo = $repo ?? new OtpRepository();
	}

	/**
	 * Gate an OTP send request.
	 *
	 * @param string $destination Canonical phone or email.
	 * @param string $intent      register|login|recover|add_identity
	 * @return true|WP_Error
	 */
	public function check_otp_send( string $destination, string $intent ) {
		$cooldown = Settings::get_int( 'otp_resend_cooldown', 60 );
		$last     = $this->repo->last_sent_at( $destination, $intent );

		if ( $last > 0 && ( time() - $last ) < $cooldown ) {
			$wait = $cooldown - ( time() - $last );

			return new WP_Error(
				'smart_login_cooldown',
				sprintf(
					/* translators: %d: seconds remaining. */
					__( 'Vui lòng đợi %d giây trước khi yêu cầu mã mới.', 'smart-login' ),
					$wait
				),
				array( 'retry_after' => $wait )
			);
		}

		$per_dest = Settings::get_int( 'otp_max_per_destination_hour', 5 );
		if ( $per_dest > 0 && $this->repo->count_recent_by_destination( $destination, HOUR_IN_SECONDS ) >= $per_dest ) {
			return new WP_Error(
				'smart_login_dest_limit',
				__( 'Bạn đã yêu cầu quá nhiều mã xác thực. Vui lòng thử lại sau 1 giờ.', 'smart-login' ),
				array( 'retry_after' => HOUR_IN_SECONDS )
			);
		}

		$per_ip = Settings::get_int( 'otp_max_per_ip_hour', 10 );
		if ( $per_ip > 0 && $this->repo->count_recent_by_ip( Client::ip_binary(), HOUR_IN_SECONDS ) >= $per_ip ) {
			return new WP_Error(
				'smart_login_ip_limit',
				__( 'Hệ thống ghi nhận quá nhiều yêu cầu từ thiết bị của bạn. Vui lòng thử lại sau.', 'smart-login' ),
				array( 'retry_after' => HOUR_IN_SECONDS )
			);
		}

		/**
		 * Last word on whether a code may be sent.
		 *
		 * @param true|WP_Error $result
		 * @param string        $destination
		 * @param string        $intent
		 */
		return apply_filters( 'smart_login_check_otp_send', true, $destination, $intent );
	}

	// -----------------------------------------------------------------
	// Login brute-force
	// -----------------------------------------------------------------

	/**
	 * Lockouts are keyed on the canonical subject, so `0969789475`,
	 * `+84 969 789 475` and `84969789475` all share one counter instead of
	 * offering an attacker three independent budgets.
	 *
	 * This is a normalisation call, not an ownership lookup — the registry never
	 * touches the database here.
	 */
	private function login_key( string $identity ): string {
		$identity = trim( wp_unslash( $identity ) );
		$claim    = ( new ChannelRegistry() )->claim_any( $identity );
		$canonical = $claim->is_empty() ? strtolower( $identity ) : $claim->subject();

		return 'smart_login_lock_' . md5( $canonical . '|' . Client::ip() );
	}

	/**
	 * @return int Seconds remaining, 0 when not locked.
	 */
	public function login_lock_remaining( string $identity ): int {
		$data = get_transient( $this->login_key( $identity ) );

		if ( ! is_array( $data ) || empty( $data['locked_until'] ) ) {
			return 0;
		}

		return max( 0, (int) $data['locked_until'] - time() );
	}

	public function record_login_failure( string $identity ): void {
		$max = Settings::get_int( 'login_max_attempts', 5 );

		if ( $max <= 0 ) {
			return;
		}

		$key  = $this->login_key( $identity );
		$data = get_transient( $key );
		$data = is_array( $data ) ? $data : array(
			'count'        => 0,
			'locked_until' => 0,
		);

		++$data['count'];

		$window = max( HOUR_IN_SECONDS, Settings::get_int( 'login_lockout_minutes', 15 ) * MINUTE_IN_SECONDS );

		if ( $data['count'] >= $max ) {
			$minutes              = max( 1, Settings::get_int( 'login_lockout_minutes', 15 ) );
			$data['locked_until'] = time() + ( $minutes * MINUTE_IN_SECONDS );
			$data['count']        = 0;
			$window               = $minutes * MINUTE_IN_SECONDS;

			AuditLog::record( AuditLog::LOCKOUT, self::mask_identity( $identity ), array( 'minutes' => $minutes ) );
		}

		set_transient( $key, $data, $window );
	}

	public function clear_login_failures( string $identity ): void {
		delete_transient( $this->login_key( $identity ) );
	}

	/**
	 * Mask any identifier for logging without knowing its type.
	 */
	public static function mask_identity( string $identity ): string {
		if ( false !== strpos( $identity, '@' ) ) {
			return \SmartLogin\Identity\Phone::mask_email( $identity );
		}

		return \SmartLogin\Identity\Phone::mask( $identity );
	}
}
