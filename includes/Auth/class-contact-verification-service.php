<?php
/**
 * Verifies a newly supplied contact before changing canonical user data.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Identity\IdentityDirectory;
use SmartLogin\Identity\IdentityRecord;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\ProfileSeeder;
use SmartLogin\Identity\UserManager;
use SmartLogin\Identity\VerifiedClaim;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactVerificationService {

	const META_PENDING = '_smartlogin_pending_contact';

	private OtpService $otp;
	private IdentityDirectory $directory;

	public function __construct( ?OtpService $otp = null, ?IdentityDirectory $directory = null ) {
		$this->otp       = $otp ?? new OtpService();
		$this->directory = $directory ?? new IdentityDirectory();
	}

	/**
	 * The public 'phone'/'email' type maps to a channel id. They differ for
	 * email: the class is MailChannel but the stored channel is 'email'.
	 */
	private function channel_for( string $type ): string {
		return 'phone' === $type ? PhoneChannel::ID : MailChannel::ID;
	}

	/** @return array|WP_Error */
	public function start( int $user_id, string $type, string $value ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
		}

		if ( ! in_array( $type, array( 'phone', 'email' ), true ) ) {
			return new WP_Error( 'smart_login_bad_contact', __( 'Thông tin liên hệ không hợp lệ.', 'smart-login' ) );
		}

		$claim = $this->directory->channels()->claim( $this->channel_for( $type ), $value );

		if ( $claim->is_empty() ) {
			return new WP_Error(
				'phone' === $type ? 'smart_login_bad_phone' : 'smart_login_bad_contact',
				'phone' === $type
					? __( 'Số điện thoại không hợp lệ.', 'smart-login' )
					: __( 'Thông tin liên hệ không hợp lệ.', 'smart-login' )
			);
		}

		$destination = $claim->subject();

		// ADD_IDENTITY on a KNOWN subject is a no-op when it is already ours and a
		// conflict otherwise. RETIRED is claimable: whoever holds the number now
		// gets to prove it.
		$resolution = $this->directory->resolve( $claim );
		$decision   = AuthAction::for_resolution( AuthAction::ADD_IDENTITY, $resolution );

		if ( AuthAction::LINK_TO_CURRENT !== $decision && $resolution->user_id() !== $user_id ) {
			return new WP_Error(
				'smart_login_contact_exists',
				'phone' === $type
					? __( 'Số điện thoại này đã thuộc về tài khoản khác.', 'smart-login' )
					: __( 'Email này đã thuộc về tài khoản khác.', 'smart-login' )
			);
		}

		// One intent for every channel. This is the line that used to need a new
		// PURPOSE_* constant each time a channel was added.
		$intent = OtpService::INTENT_ADD_IDENTITY;

		$result = $this->otp->issue(
			$destination,
			$intent,
			array(
				'user_id'      => $user_id,
				'contact_type' => $type,
			),
			array( 'user_name' => $user->display_name )
		);
		if ( ! is_wp_error( $result ) ) {
			$this->remember_pending( $user_id, $type, $result );
			AuditLog::record( AuditLog::CONTACT_PENDING, RateLimiter::mask_identity( $destination ), array( 'type' => $type ), $user_id );
		}
		return $result;
	}

	/**
	 * Send a fresh code for whatever this account already has in flight.
	 *
	 * The OTP token lives in a JavaScript variable on the page that started the
	 * flow, so a reload loses it and the only control left — "Gửi lại mã" — sits
	 * inside a block that renders hidden. The account was told it could resend
	 * and then given nothing to press.
	 *
	 * The server never lost anything: the pending row knows the destination and
	 * the intent. So the client asks by type and the token stays here, which is
	 * also why this is not simply exposed through pending() — a token a template
	 * can print is a token that ends up in a page.
	 *
	 * @return array|WP_Error
	 */
	public function resend( int $user_id, string $type ) {
		$pending = get_user_meta( $user_id, self::META_PENDING, true );
		$expired = ! is_array( $pending ) || (int) ( $pending['expires_at'] ?? 0 ) <= time();

		if ( $expired ) {
			delete_user_meta( $user_id, self::META_PENDING );
		}

		if ( $expired || ( $pending['type'] ?? '' ) !== $type || '' === (string) ( $pending['token'] ?? '' ) ) {
			return new WP_Error(
				'smart_login_contact_session',
				__( 'Không còn mã nào đang chờ xác thực. Hãy gửi mã mới.', 'smart-login' )
			);
		}

		$result = $this->otp->resend( (string) $pending['token'] );

		// resend() issues a new row, so the stored token is stale the moment it
		// succeeds. Not updating it would make the second resend fail.
		if ( ! is_wp_error( $result ) ) {
			$this->remember_pending( $user_id, $type, $result );
		}

		return $result;
	}

	/**
	 * Record what is in flight, so a reload can pick the flow back up.
	 *
	 * @param int    $user_id Account the code was issued for.
	 * @param string $type    'phone' or 'email'.
	 * @param array  $result  Output of OtpService::issue().
	 */
	private function remember_pending( int $user_id, string $type, array $result ): void {
		update_user_meta(
			$user_id,
			self::META_PENDING,
			array(
				'type'       => $type,
				'masked'     => (string) $result['masked'],
				'token'      => (string) $result['token'],
				'expires_at' => time() + (int) $result['expires_in'],
			)
		);
	}

	/** @return array|WP_Error */
	public function verify( int $user_id, string $token, string $code, string $type ) {
		if ( ! in_array( $type, array( 'phone', 'email' ), true ) ) {
			return new WP_Error( 'smart_login_bad_contact', __( 'Thông tin liên hệ không hợp lệ.', 'smart-login' ) );
		}
		$row = $this->otp->verify( $token, $code, OtpService::INTENT_ADD_IDENTITY );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( (int) ( $row['payload']['user_id'] ?? 0 ) !== $user_id || ( $row['payload']['contact_type'] ?? '' ) !== $type ) {
			return new WP_Error( 'smart_login_contact_session', __( 'Phiên xác thực thông tin liên hệ không hợp lệ.', 'smart-login' ) );
		}

		$destination = (string) $row['destination'];
		$now         = current_time( 'mysql', true );

		$claim = $this->directory->channels()->claim( $this->channel_for( $type ), $destination );

		if ( $claim->is_empty() ) {
			return new WP_Error( 'smart_login_bad_contact', __( 'Thông tin liên hệ không hợp lệ.', 'smart-login' ) );
		}

		$owner = $this->directory->resolve( $claim );

		if ( $owner->has_owner() && $owner->user_id() !== $user_id ) {
			return new WP_Error(
				'smart_login_contact_exists',
				'phone' === $type
					? __( 'Số điện thoại này đã thuộc về tài khoản khác.', 'smart-login' )
					: __( 'Email này đã thuộc về tài khoản khác.', 'smart-login' )
			);
		}

		$verified = VerifiedClaim::from( $claim, VerifiedClaim::PROOF_OTP );

		// Retiring the previous subject is what keeps the model honest: it is what
		// makes the old identifier resolve as RETIRED afterwards, and RETIRED is what
		// stops it reaching this account ever again. Overwriting a meta value — which
		// is all the pre-refactor code did — left the old identifier live.
		//
		// The email half of that write, plus its four derived mirrors, moved to
		// UserManager::adopt_verified_email() in 14.2. This method is where the
		// correct version of it lived, so that is the body which was extracted.
		if ( 'email' === $type ) {
			$adopted = UserManager::adopt_verified_email( $user_id, $verified, IdentityRecord::BY_OTP, $this->directory );

			if ( is_wp_error( $adopted ) ) {
				return $adopted;
			}
		} else {
			if ( ! $this->directory->replace_in_channel( $user_id, $verified, IdentityRecord::BY_OTP ) ) {
				return new WP_Error( 'smart_login_contact_exists', __( 'Không thể cập nhật thông tin liên hệ.', 'smart-login' ) );
			}

			// Derived mirrors; see UserManager::create_verified_user().
			update_user_meta( $user_id, UserManager::META_PHONE, $destination );
			update_user_meta( $user_id, UserManager::META_PHONE_VERIFIED, $now );
			if ( Settings::is_on( 'woo.sync_billing_phone' ) ) {
				// Seed, not overwrite: changing the login phone must not silently
				// change where the customer's orders get delivered.
				ProfileSeeder::seed_if_empty( $user_id, 'billing_phone', Phone::to_local( $destination ) );
			}
		}

		delete_user_meta( $user_id, self::META_PENDING );
		AuditLog::record( AuditLog::CONTACT_VERIFIED, RateLimiter::mask_identity( $destination ), array( 'type' => $type ), $user_id );
		return array(
			'type'    => $type,
			'value'   => $destination,
			// What the screen should now show. `value` is canonical — a phone is
			// stored E.164 — and the field it replaces was rendered with
			// Phone::to_local(). Formatting it here keeps the client from
			// reimplementing that rule and then drifting from it.
			'display' => 'phone' === $type ? Phone::to_local( $destination ) : $destination,
		);
	}

	public function pending( int $user_id ): array {
		$pending = get_user_meta( $user_id, self::META_PENDING, true );
		if ( ! is_array( $pending ) || (int) ( $pending['expires_at'] ?? 0 ) <= time() ) {
			delete_user_meta( $user_id, self::META_PENDING );
			return array();
		}
		return array(
			'type'       => sanitize_key( (string) ( $pending['type'] ?? '' ) ),
			'masked'     => sanitize_text_field( (string) ( $pending['masked'] ?? '' ) ),
			'expires_at' => (int) $pending['expires_at'],
		);
	}
}
