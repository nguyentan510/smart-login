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

use SmartLogin\Identity\ChannelRegistry;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Identity\Claim;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\VerifiedClaim;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\Client;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class OtpService {

	/**
	 * Intents, matching AuthAction. There were six PURPOSE_* constants before
	 * this refactor, and the set grew with every feature because it conflated
	 * proof-of-control with business intent: change_phone, change_email and
	 * verify_email are all the same intent applied to different channels.
	 *
	 * Four intents now cover every flow, and a new channel or transport adds
	 * none. See docs/identity-model.md §6.
	 */
	const INTENT_REGISTER     = 'register';
	const INTENT_LOGIN        = 'login';
	const INTENT_RECOVER      = 'recover';
	const INTENT_ADD_IDENTITY = 'add_identity';

	/** @var OtpRepository */
	private $repo;

	/** @var TransportRouter */
	private $router;

	/** @var RateLimiter */
	private $limiter;

	public function __construct( ?OtpRepository $repo = null, ?TransportRouter $router = null, ?RateLimiter $limiter = null ) {
		$this->repo    = $repo ?? new OtpRepository();
		$this->router  = $router ?? new TransportRouter();
		$this->limiter = $limiter ?? new RateLimiter( $this->repo );
	}

	/**
	 * Generate, store and deliver a code.
	 *
	 * @param string $destination Canonical phone digits or email address.
	 * @param string $intent      One of the INTENT_* constants.
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
	public function issue( string $destination, string $intent, array $payload = array(), array $ctx = array() ) {
		if ( '' === $destination ) {
			return new WP_Error( 'smart_login_no_destination', __( 'Thiếu số điện thoại hoặc email nhận mã.', 'smart-login' ) );
		}

		$allowed = $this->limiter->check_otp_send( $destination, $intent );

		if ( is_wp_error( $allowed ) ) {
			AuditLog::record(
				AuditLog::RATE_LIMITED,
				RateLimiter::mask_identity( $destination ),
				array(
					'intent' => $intent,
					'reason' => $allowed->get_error_code(),
				)
			);

			return $allowed;
		}

		$transport = $ctx['transport'] ?? $this->router->transport_for( $destination );
		$ttl       = max( 60, Settings::get_int( 'otp.ttl', 300 ) );
		$code      = $this->generate_code();

		$token   = bin2hex( random_bytes( 32 ) );
		$now     = time();
		$expires = $now + $ttl;

		$row_id = $this->repo->insert(
			array(
				'token'            => $token,
				'intent'           => $intent,
				// Derived when nothing supplies it, rather than stored empty.
				// This column used to be blank on every code issued by a handler
				// that did not happen to pass a claim — login and password reset
				// among them — which left anything counting by channel guessing
				// from the destination string later. Asking the routing authority
				// keeps that test in the one file allowed to make it.
				'identity_channel' => (string) ( $payload['channel'] ?? $ctx['identity_channel'] ?? '' )
					?: TransportRouter::channel_for( $destination ),
				'transport'        => $transport,
				'destination'      => $destination,
				'code_hash'        => $this->hash_code( $code ),
				'payload'          => $payload,
				'resend_of'        => $ctx['resend_of'] ?? null,
				'ip'               => Client::ip_binary(),
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'       => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);

		if ( ! $row_id ) {
			return new WP_Error( 'smart_login_db', __( 'Không tạo được mã xác thực. Vui lòng thử lại.', 'smart-login' ) );
		}

		$send_ctx = array_merge(
			$ctx,
			array(
				'intent'      => $intent,
				'transport'   => $transport,
				'ttl_seconds' => $ttl,
				'expires_ts'  => $expires,
			)
		);

		$sent = $this->router->send( $destination, $code, $send_ctx );

		if ( is_wp_error( $sent ) ) {
			// Roll the row back so the user is not stranded on an OTP screen
			// waiting for a message that was never sent — and so the failed
			// attempt does not eat into their hourly quota. Nothing else is
			// undone here, because nothing else has happened yet: the codes
			// already in the user's hands are retired below, on success only.
			$this->repo->delete( $row_id );

			AuditLog::record(
				AuditLog::OTP_SEND_FAILED,
				RateLimiter::mask_identity( $destination ),
				array(
					'intent'    => $intent,
					'transport' => $transport,
					'error'     => $sent->get_error_message(),
				)
			);

			return $sent;
		}

		// Only now is the newest code the only one that works. This used to run
		// before the send, which read as "keep the table tidy" and behaved as
		// "a gateway failure takes the user's working code with it": the old row
		// was consumed, the new one was rolled back, and the screen then told
		// them the code they were holding had already been used.
		$this->repo->consume_open_codes( $destination, $intent, $row_id );

		AuditLog::record(
			AuditLog::OTP_SENT,
			RateLimiter::mask_identity( $destination ),
			array(
				'intent'    => $intent,
				'transport' => $transport,
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
			'resend_after' => Settings::get_int( 'otp.resend_cooldown', 60 ),
			'transport'    => $transport,
		);

		if ( $this->dev_mode_active() ) {
			$result['dev_code'] = $code;
		}

		return $result;
	}

	/**
	 * Check a code against its token.
	 *
	 * @param string $token
	 * @param string $code
	 * @param string $expected_intent Optional server-side intent binding.
	 * @return array|WP_Error The consumed row (including `payload`) on success.
	 */
	public function verify( string $token, string $code, string $expected_intent = '' ) {
		$token = trim( $token );
		$code  = preg_replace( '/\D/', '', trim( $code ) );

		if ( '' === $token || '' === $code ) {
			return new WP_Error( 'smart_login_otp_missing', __( 'Vui lòng nhập mã xác thực.', 'smart-login' ) );
		}

		$row = $this->repo->find_by_token( $token );

		if ( ! $row ) {
			return new WP_Error( 'smart_login_otp_invalid', __( 'Mã xác thực không hợp lệ. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
		}

		if ( '' !== $expected_intent && $expected_intent !== $row['intent'] ) {
			return new WP_Error( 'smart_login_wrong_intent', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) );
		}

		$masked = RateLimiter::mask_identity( $row['destination'] );

		if ( ! empty( $row['consumed_at'] ) ) {
			return new WP_Error( 'smart_login_otp_used', __( 'Mã xác thực đã được sử dụng. Vui lòng yêu cầu mã mới.', 'smart-login' ) );
		}

		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			AuditLog::record( AuditLog::OTP_EXPIRED, $masked, array( 'intent' => $row['intent'] ) );

			return new WP_Error( 'smart_login_otp_expired', __( 'Mã xác thực đã hết hạn. Vui lòng bấm "Gửi lại".', 'smart-login' ) );
		}

		$max = max( 1, Settings::get_int( 'otp.max_attempts', 5 ) );

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
					'intent'   => $row['intent'],
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

		AuditLog::record( AuditLog::OTP_VERIFIED, $masked, array( 'intent' => $row['intent'] ) );

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
			$row['intent'],
			is_array( $row['payload'] ) ? $row['payload'] : array(),
			array(
				'transport'        => $row['transport'],
				'identity_channel' => (string) ( $row['identity_channel'] ?? '' ),
				'resend_of'        => (int) $row['id'],
				'user_name'        => $row['payload']['full_name'] ?? '',
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

	/**
	 * Turn a successfully consumed OTP row into proof of control.
	 *
	 * This is the PROVE boundary for the OTP method: only a row that came back
	 * from verify() should reach here, and the resulting VerifiedClaim is what
	 * AuthProof::from_otp() and the identity directory require.
	 *
	 * The identity channel is taken from the row's payload where the flow
	 * recorded one, and otherwise inferred from the destination. It deliberately
	 * does not consult ChannelRegistry::enabled(): a code already sent to a
	 * subject must remain verifiable even if an admin disables that channel
	 * mid-flow.
	 *
	 * Note the row's own `channel` column is the delivery transport (sms|email),
	 * which is a different axis — see docs/identity-model.md §6.
	 */
	public function verified_claim( array $row ): VerifiedClaim {
		$destination = (string) ( $row['destination'] ?? '' );
		$payload     = is_array( $row['payload'] ?? null ) ? $row['payload'] : array();
		$channel_id  = (string) ( $row['identity_channel'] ?? '' );

		if ( '' === $channel_id ) {
			$channel_id = (string) ( $payload['channel'] ?? '' );
		}

		if ( '' === $channel_id ) {
			$channel_id = ( false !== strpos( $destination, '@' ) ) ? MailChannel::ID : PhoneChannel::ID;
		}

		$channel = ( new ChannelRegistry() )->get( $channel_id );
		$subject = $channel ? $channel->normalize( $destination ) : $destination;

		return VerifiedClaim::from( Claim::canonical( $channel_id, $subject ), VerifiedClaim::PROOF_OTP );
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	/**
	 * A code of the configured length, zero-padded so every code is that length.
	 */
	private function generate_code(): string {
		$length = min( 8, max( 4, Settings::get_int( 'otp.length', 6 ) ) );
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
		return Settings::is_on( 'advanced.dev_mode' )
			&& defined( 'WP_DEBUG' ) && WP_DEBUG
			&& 'production' !== wp_get_environment_type();
	}
}
