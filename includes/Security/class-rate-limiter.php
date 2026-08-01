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
		// Cheapest check first, and deliberately so. While the site is halted
		// this returns on one option read, without the three counting queries
		// below — so a sustained attack sheds load instead of adding to it.
		// That is what makes the kill switch an availability control as well as
		// a spend control.
		if ( $this->halted_for() > 0 ) {
			return self::unavailable();
		}

		$cooldown = Settings::get_int( 'otp.resend_cooldown', 60 );
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

		$per_dest = Settings::get_int( 'otp.max_per_destination_hour', 5 );
		if ( $per_dest > 0 && $this->repo->count_recent_by_destination( $destination, HOUR_IN_SECONDS ) >= $per_dest ) {
			return new WP_Error(
				'smart_login_dest_limit',
				__( 'Bạn đã yêu cầu quá nhiều mã xác thực. Vui lòng thử lại sau 1 giờ.', 'smart-login' ),
				array( 'retry_after' => HOUR_IN_SECONDS )
			);
		}

		$per_ip = Settings::get_int( 'otp.max_per_ip_hour', 10 );

		// count_recent_by_ip( null ) returns 0, so a request with no usable
		// address switches the per-IP limit off. That stays — CLI, cron and
		// unusual SAPI contexts legitimately have no REMOTE_ADDR, and the
		// site-wide budget already covers what this misses. What does not stay is
		// the silence: a limit that has quietly stopped applying should be
		// visible in the log rather than inferred from a surprise.
		if ( $per_ip > 0 && null === Client::ip_binary() ) {
			$this->warn_once(
				'smart_login_warned_no_ip',
				'no_client_ip',
				'The per-IP send limit cannot apply to a request with no usable address.'
			);
		}

		if ( $per_ip > 0 && $this->repo->count_recent_by_ip( Client::ip_binary(), HOUR_IN_SECONDS ) >= $per_ip ) {
			return new WP_Error(
				'smart_login_ip_limit',
				__( 'Hệ thống ghi nhận quá nhiều yêu cầu từ thiết bị của bạn. Vui lòng thử lại sau.', 'smart-login' ),
				array( 'retry_after' => HOUR_IN_SECONDS )
			);
		}

		// The site-wide ceilings. Every limit above is scoped to one destination
		// or one IP, which are exactly the two axes an attacker rotates; these
		// are the only ones a botnet cannot spread its way around.
		$per_site_hour = Settings::get_int( 'security.max_per_site_hour', 100 );
		if ( $per_site_hour > 0 && $this->repo->count_recent_all( HOUR_IN_SECONDS ) >= $per_site_hour ) {
			return $this->halt( 'hour', $per_site_hour );
		}

		$per_site_day = Settings::get_int( 'security.max_per_site_day', 500 );
		if ( $per_site_day > 0 && $this->repo->count_recent_all( DAY_IN_SECONDS ) >= $per_site_day ) {
			return $this->halt( 'day', $per_site_day );
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

	/**
	 * Record an operational warning at most once an hour.
	 *
	 * Throttled because the conditions worth warning about are the ones that
	 * repeat on every request, and an audit log that floods is one nobody reads —
	 * the same reasoning 9.9 applies to high-volume events.
	 */
	private function warn_once( string $transient, string $reason, string $detail ): void {
		if ( get_transient( $transient ) ) {
			return;
		}

		set_transient( $transient, 1, HOUR_IN_SECONDS );

		AuditLog::record(
			AuditLog::RATE_LIMITED,
			'',
			array(
				'reason' => $reason,
				'detail' => $detail,
			)
		);
	}

	// -----------------------------------------------------------------
	// Identifier lookup
	// -----------------------------------------------------------------

	/**
	 * Gate the identifier-first lookup, before it answers.
	 *
	 * The lookup **is** the oracle: it reports whether a subject is registered by
	 * which screen it returns. Until this existed, `RateLimiter` was reached only
	 * from inside `OtpService::issue()` — the "no such account" branch — so a
	 * subject that *did* exist went straight to the password screen having passed
	 * no limit at all. The site's registered numbers could be enumerated at zero
	 * cost and unbounded rate, and the README claimed otherwise.
	 *
	 * Keyed on IP alone. The identity is the attacker's variable; keying on it
	 * would hand out a fresh budget per guess, which is the defect being fixed.
	 *
	 * @return true|WP_Error
	 */
	public function check_identify( string $identity ) {
		$max = Settings::get_int( 'security.max_identify_per_ip_hour', 30 );

		if ( $max <= 0 ) {
			return true;
		}

		$ip = Client::ip();

		if ( '' === $ip ) {
			// No address to attribute this to. Fail open, as the OTP per-IP limit
			// does, because the site-wide budget already covers what this misses
			// and CLI or unusual SAPI contexts are legitimate.
			return true;
		}

		// A fixed hourly bucket rather than a rolling window: it expires on its
		// own, needs no sweeping, and costs one transient. It allows roughly a
		// 2x burst across the hour boundary, which is accepted — a rolling
		// window would cost more than the problem does.
		$key   = 'smart_login_idfy_' . md5( $ip . '|' . gmdate( 'YmdH' ) );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			AuditLog::record(
				AuditLog::RATE_LIMITED,
				self::mask_identity( $identity ),
				array( 'reason' => 'identify' )
			);

			return new WP_Error(
				'smart_login_identify_limit',
				__( 'Hệ thống ghi nhận quá nhiều yêu cầu từ thiết bị của bạn. Vui lòng thử lại sau.', 'smart-login' ),
				array( 'retry_after' => HOUR_IN_SECONDS )
			);
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	// -----------------------------------------------------------------
	// Site-wide budget and kill switch
	// -----------------------------------------------------------------

	/** Where the halt deadline lives. An option, not a transient — see halt(). */
	const HALT_OPTION = 'smart_login_otp_halted_until';

	/**
	 * Seconds left on the site-wide halt, 0 when sending is open.
	 */
	public function halted_for(): int {
		$until = (int) get_option( self::HALT_OPTION, 0 );

		return max( 0, $until - time() );
	}

	/**
	 * Trip the kill switch and refuse the send.
	 *
	 * The deadline is an option rather than a transient because a transient can
	 * be evicted by an object-cache flush, and an evicted spend limit fails open
	 * at the exact moment it is being leaned on.
	 *
	 * @param string $window `hour` or `day`, for the audit record only.
	 * @param int    $ceiling The limit that was reached.
	 */
	private function halt( string $window, int $ceiling ): WP_Error {
		$minutes = max( 1, Settings::get_int( 'security.halt_minutes', 60 ) );
		$already = $this->halted_for() > 0;

		update_option( self::HALT_OPTION, time() + ( $minutes * MINUTE_IN_SECONDS ), false );

		// Only the request that moved the site from open to halted reports it.
		// Detecting the transition here rather than at the call site is what
		// stops a burst of concurrent requests from sending a mail each.
		if ( ! $already ) {
			AuditLog::record(
				AuditLog::OTP_BUDGET_HALTED,
				'',
				array(
					'window'  => $window,
					'ceiling' => $ceiling,
					'minutes' => $minutes,
				)
			);

			$this->notify_admin( $window, $ceiling, $minutes );
		}

		return self::unavailable();
	}

	/**
	 * One mail per halt, to the site admin.
	 */
	private function notify_admin( string $window, int $ceiling, int $minutes ): void {
		$to = (string) get_option( 'admin_email', '' );

		if ( '' === $to ) {
			return;
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Đã tạm dừng gửi mã xác thực', 'smart-login' ),
				get_bloginfo( 'name' )
			),
			sprintf(
				/* translators: 1: ceiling, 2: hour or day, 3: minutes halted. */
				__(
					"Smart Login đã chạm trần %1\$d mã xác thực trong một %2\$s và tạm dừng gửi trong %3\$d phút.\n\nĐây thường là dấu hiệu bị lạm dụng để đốt tin nhắn. Hãy mở Smart Login → Nhật ký để xem lưu lượng gần đây trước khi nâng trần.",
					'smart-login'
				),
				$ceiling,
				'day' === $window ? __( 'ngày', 'smart-login' ) : __( 'giờ', 'smart-login' ),
				$minutes
			)
		);
	}

	/**
	 * What the visitor is told, whichever ceiling tripped.
	 *
	 * Naming the site budget would tell an attacker their attack landed, and
	 * tells an ordinary visitor something they cannot act on.
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error(
			'smart_login_unavailable',
			__( 'Hệ thống đang tạm thời không gửi được mã. Vui lòng thử lại sau.', 'smart-login' ),
			array( 'retry_after' => MINUTE_IN_SECONDS * 15 )
		);
	}

	/**
	 * Clear the halt. The admin screen's "resume sending" control.
	 */
	public static function resume(): void {
		delete_option( self::HALT_OPTION );
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
		$identity  = trim( wp_unslash( $identity ) );
		$claim     = ( new ChannelRegistry() )->claim_any( $identity );
		$canonical = $claim->is_empty() ? strtolower( $identity ) : $claim->subject();

		return 'smart_login_lock_' . md5( $canonical . '|' . Client::ip() );
	}

	/**
	 * The second counter, keyed on the address alone.
	 *
	 * `login_key()` mixes the identity in, which is right for brute force against
	 * one account and useless against spraying: one common password tried across
	 * ten thousand accounts records a single failure on each, and none of them
	 * ever reaches `login.max_attempts`. Spraying is the more common of the two
	 * attacks and it walked straight through until this existed.
	 */
	private function ip_lock_key(): string {
		return 'smart_login_iplock_' . md5( (string) Client::ip() );
	}

	/**
	 * @return int Seconds remaining on the address-wide lock, 0 when open.
	 */
	public function ip_lock_remaining(): int {
		if ( Settings::get_int( 'security.max_login_failures_per_ip_hour', 30 ) <= 0 ) {
			return 0;
		}

		if ( '' === Client::ip() ) {
			return 0;
		}

		$data = get_transient( $this->ip_lock_key() );

		if ( ! is_array( $data ) || empty( $data['locked_until'] ) ) {
			return 0;
		}

		return max( 0, (int) $data['locked_until'] - time() );
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
		$this->record_ip_failure();

		$max = Settings::get_int( 'login.max_attempts', 5 );

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

		$window = max( HOUR_IN_SECONDS, Settings::get_int( 'login.lockout_minutes', 15 ) * MINUTE_IN_SECONDS );

		if ( $data['count'] >= $max ) {
			$minutes              = max( 1, Settings::get_int( 'login.lockout_minutes', 15 ) );
			$data['locked_until'] = time() + ( $minutes * MINUTE_IN_SECONDS );
			$data['count']        = 0;
			$window               = $minutes * MINUTE_IN_SECONDS;

			AuditLog::record( AuditLog::LOCKOUT, self::mask_identity( $identity ), array( 'minutes' => $minutes ) );
		}

		set_transient( $key, $data, $window );
	}

	/**
	 * Count one failure against the address, whoever it was aimed at.
	 *
	 * Every failure counts, including ones for identifiers that resolve to
	 * nothing: spraying uses valid identifiers, and excluding unresolved ones
	 * would hand an attacker a free way to probe which is which.
	 */
	private function record_ip_failure(): void {
		$max = Settings::get_int( 'security.max_login_failures_per_ip_hour', 30 );

		if ( $max <= 0 || '' === Client::ip() ) {
			return;
		}

		$key  = $this->ip_lock_key();
		$data = get_transient( $key );
		$data = is_array( $data ) ? $data : array(
			'count'        => 0,
			'locked_until' => 0,
		);

		++$data['count'];

		$window = HOUR_IN_SECONDS;

		if ( $data['count'] >= $max ) {
			$minutes              = max( 1, Settings::get_int( 'security.ip_lockout_minutes', 15 ) );
			$data['locked_until'] = time() + ( $minutes * MINUTE_IN_SECONDS );
			$data['count']        = 0;
			$window               = $minutes * MINUTE_IN_SECONDS;

			AuditLog::record(
				AuditLog::LOCKOUT,
				'',
				array(
					'scope'   => 'ip',
					'minutes' => $minutes,
				)
			);
		}

		set_transient( $key, $data, $window );
	}

	/**
	 * A success clears the account's own counter, but deliberately **not** the
	 * address-wide one. One correct password among a thousand guesses is what a
	 * successful spray looks like, so letting it reset the sweep counter would
	 * hand the attacker a way to keep going indefinitely.
	 */
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
