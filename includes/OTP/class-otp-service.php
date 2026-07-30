<?php
/**
 * Issuing and verifying one-time codes.
 *
 * Codes are generated with a CSPRNG, stored only as an HMAC, and compared in
 * constant time. The plaintext exists in memory just long enough to hand to a
 * channel.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP;

use SmartLogin\Identity\Phone;
use SmartLogin\OTP\Channels\ChannelRouter;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\Client;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class OtpService {

	const PURPOSE_REGISTER     = 'register';
	const PURPOSE_RESET        = 'reset';
	const PURPOSE_LOGIN        = 'login';
	const PURPOSE_CHANGE_PHONE = 'change_phone';
	const PURPOSE_CHANGE_EMAIL = 'change_email';
	const PURPOSE_VERIFY_EMAIL = 'verify_email';

	/** @var OtpRepository */
	private $repo;

	/** @var ChannelRouter */
	private $router;

	/** @var RateLimiter */
	private $limiter;

	public function __construct( ?OtpRepository $repo = null, ?ChannelRouter $router = null, ?RateLimiter $limiter = null ) {
		$this->repo    = $repo ?? new OtpRepository();
		$this->router  = $router ?? new ChannelRouter();
		$this->limiter = $limiter ?? new RateLimiter( $this->repo );
	}

	/**
	 * Generate, store and deliver a code.
	 *
	 * @param string $destination Canonical phone digits or email address.
	 * @param string $purpose     One of the PURPOSE_* constants.
	 * @param array  $payload     Data to carry until verification (registration form).
	 * @param array  $ctx         Extra template context, e.g. user_name.
	 * @return array|WP_Error {
	 *     @type string $token        Opaque handle for the verify step.
	 *     @type string $masked       Destination safe to show on screen.
	 *     @type int    $expires_in   Seconds.
	 *     @type int    $resend_after Seconds.
	 *     @type string $channel      sms|email
	 * }
	 */
	public function issue( string $destination, string $purpose, array $payload = array(), array $ctx = array() ) {
		if ( '' === $destination ) {
			return new WP_Error( 'smart_login_no_destination', __( 'Thiếu số điện thoại hoặc email nhận mã.', 'smart-login' ) );
		}

		$allowed = $this->limiter->check_otp_send( $destination, $purpose );

		if ( is_wp_error( $allowed ) ) {
			AuditLog::record(
				AuditLog::RATE_LIMITED,
				RateLimiter::mask_identity( $destination ),
				array(
					'purpose' => $purpose,
					'reason'  => $allowed->get_error_code(),
				)
			);

			return $allowed;
		}

		$channel = $ctx['channel'] ?? $this->router->channel_for( $destination );
		$ttl     = max( 60, Settings::get_int( 'otp_ttl', 300 ) );
		$code    = $this->generate_code();

		// Only the newest code for a destination/purpose may be redeemed.
		$this->repo->consume_open_codes( $destination, $purpose );

		$token   = bin2hex( random_bytes( 32 ) );
		$now     = time();
		$expires = $now + $ttl;

		$row_id = $this->repo->insert(
			array(
				'token'       => $token,
				'purpose'     => $purpose,
				'channel'     => $channel,
				'destination' => $destination,
				'code_hash'   => $this->hash_code( $code ),
				'payload'     => $payload,
				'resend_of'   => $ctx['resend_of'] ?? null,
				'ip'          => Client::ip_binary(),
				'created_at'  => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);

		if ( ! $row_id ) {
			return new WP_Error( 'smart_login_db', __( 'Không tạo được mã xác thực. Vui lòng thử lại.', 'smart-login' ) );
		}

		$send_ctx = array_merge(
			$ctx,
			array(
				'purpose'     => $purpose,
				'channel'     => $channel,
				'ttl_seconds' => $ttl,
				'expires_ts'  => $expires,
			)
		);

		$sent = $this->router->send( $destination, $code, $send_ctx );

		if ( is_wp_error( $sent ) ) {
			// Roll the row back so the user is not stranded on an OTP screen
			// waiting for a message that was never sent — and so the failed
			// attempt does not eat into their hourly quota.
			$this->repo->delete( $row_id );

			AuditLog::record(
				AuditLog::OTP_SEND_FAILED,
				RateLimiter::mask_identity( $destination ),
				array(
					'purpose' => $purpose,
					'channel' => $channel,
					'error'   => $sent->get_error_message(),
				)
			);

			return $sent;
		}

		AuditLog::record(
			AuditLog::OTP_SENT,
			RateLimiter::mask_identity( $destination ),
			array(
				'purpose' => $purpose,
				'channel' => $channel,
			)
		);

		/**
		 * Fires after a code has been delivered successfully.
		 *
		 * @param string $destination
		 * @param array  $send_ctx
		 */
		do_action( 'smart_login_otp_sent', $destination, $send_ctx );

		$result = array(
			'token'        => $token,
			'masked'       => $this->mask( $destination ),
			'destination'  => $this->mask( $destination ),
			'expires_in'   => $ttl,
			'resend_after' => Settings::get_int( 'otp_resend_cooldown', 60 ),
			'channel'      => $channel,
		);

		if ( $this->dev_mode_active() ) {
			$result['dev_code'] = $code;
		}

		return $result;
	}

	/**
	 * Check a code against its token.
	 *
	 * @param string $expected_purpose Optional server-side purpose binding.
	 * @return array|WP_Error The consumed row (including `payload`) on success.
	 */
	public function verify( string $token, string $code, string $expected_purpose = '' ) {
		$token = trim( $token );
		$code  = preg_replace( '/\D/', '', trim( $code ) );

		if ( '' === $token || '' === $code ) {
			return new WP_Error( 'smart_login_otp_missing', __( 'Vui lòng nhập mã xác thực.', 'smart-login' ) );
		}

		$row = $this->repo->find_by_token( $token );

		if ( ! $row ) {
			return new WP_Error( 'smart_login_otp_invalid', __( 'Mã xác thực không hợp lệ. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
		}

		if ( '' !== $expected_purpose && $expected_purpose !== $row['purpose'] ) {
			return new WP_Error( 'smart_login_wrong_purpose', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) );
		}

		$masked = RateLimiter::mask_identity( $row['destination'] );

		if ( ! empty( $row['consumed_at'] ) ) {
			return new WP_Error( 'smart_login_otp_used', __( 'Mã xác thực đã được sử dụng. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
		}

		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			AuditLog::record( AuditLog::OTP_EXPIRED, $masked, array( 'purpose' => $row['purpose'] ) );

			return new WP_Error( 'smart_login_otp_expired', __( 'Mã xác thực đã hết hạn. Vui lòng bấm "Gửi lại".', 'smart-login' ) );
		}

		$max = max( 1, Settings::get_int( 'otp_max_attempts', 5 ) );

		if ( (int) $row['attempts'] >= $max ) {
			$this->repo->mark_consumed( (int) $row['id'] );

			return new WP_Error( 'smart_login_otp_locked', __( 'Bạn đã nhập sai quá số lần cho phép. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
		}

		if ( ! hash_equals( $row['code_hash'], $this->hash_code( $code ) ) ) {
			$attempts = $this->repo->increment_attempts( (int) $row['id'] );
			$left     = max( 0, $max - $attempts );

			AuditLog::record(
				AuditLog::OTP_FAILED,
				$masked,
				array(
					'purpose'  => $row['purpose'],
					'attempts' => $attempts,
				)
			);

			if ( $left <= 0 ) {
				$this->repo->mark_consumed( (int) $row['id'] );

				return new WP_Error( 'smart_login_otp_locked', __( 'Bạn đã nhập sai quá số lần cho phép. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
			}

			return new WP_Error(
				'smart_login_otp_wrong',
				sprintf(
					/* translators: %d: remaining attempts. */
					_n( 'Mã xác thực không đúng. Bạn còn %d lần thử.', 'Mã xác thực không đúng. Bạn còn %d lần thử.', $left, 'smart-login' ),
					$left
				),
				array( 'attempts_left' => $left )
			);
		}

		if ( ! $this->repo->consume_if_open( (int) $row['id'] ) ) {
			return new WP_Error( 'smart_login_otp_used', __( 'Mã xác thực đã được sử dụng. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
		}

		AuditLog::record( AuditLog::OTP_VERIFIED, $masked, array( 'purpose' => $row['purpose'] ) );

		return $row;
	}

	/**
	 * Issue a fresh code for an existing pending flow, keeping its payload.
	 *
	 * @return array|WP_Error Same shape as issue().
	 */
	public function resend( string $token ) {
		$row = $this->repo->find_by_token( $token );

		if ( ! $row ) {
			return new WP_Error( 'smart_login_otp_invalid', __( 'Phiên xác thực không hợp lệ. Vui lòng bắt đầu lại.', 'smart-login' ) );
		}

		return $this->issue(
			$row['destination'],
			$row['purpose'],
			is_array( $row['payload'] ) ? $row['payload'] : array(),
			array(
				'channel'   => $row['channel'],
				'resend_of' => (int) $row['id'],
				'user_name' => $row['payload']['full_name'] ?? '',
			)
		);
	}

	/**
	 * Read a pending flow without consuming it (used to re-render the OTP screen).
	 *
	 * @return array|null
	 */
	public function peek( string $token ): ?array {
		$row = $this->repo->find_by_token( $token );

		if ( ! $row || ! empty( $row['consumed_at'] ) ) {
			return null;
		}

		return $row;
	}

	public function seconds_left( array $row ): int {
		return max( 0, strtotime( $row['expires_at'] . ' UTC' ) - time() );
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	private function generate_code(): string {
		$length = min( 8, max( 4, Settings::get_int( 'otp_length', 6 ) ) );
		$max    = (int) str_repeat( '9', $length );
		$code   = str_pad( (string) random_int( 0, $max ), $length, '0', STR_PAD_LEFT );

		/**
		 * Override code generation (e.g. to exclude confusing sequences).
		 *
		 * @param string $code
		 * @param int    $length
		 */
		return (string) apply_filters( 'smart_login_otp_code', $code, $length );
	}

	private function hash_code( string $code ): string {
		return hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
	}

	private function mask( string $destination ): string {
		return ( false !== strpos( $destination, '@' ) )
			? Phone::mask_email( $destination )
			: Phone::mask( $destination );
	}

	/**
	 * Dev mode reveals codes on screen. It requires all three of:
	 * the setting, WP_DEBUG, and a non-production environment type — so
	 * flipping the checkbox on a live site does nothing.
	 */
	public function dev_mode_active(): bool {
		return Settings::is_on( 'dev_mode' )
			&& defined( 'WP_DEBUG' ) && WP_DEBUG
			&& 'production' !== wp_get_environment_type();
	}
}
