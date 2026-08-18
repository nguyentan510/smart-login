<?php
/**
 * Forgot password: OTP → short-lived grant → new password.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth;

use OmniWP\Identity\IdentityDirectory;
use OmniWP\Identity\UserManager;
use OmniWP\OTP\OtpService;
use OmniWP\Security\AuditLog;
use OmniWP\Security\RateLimiter;
use OmniWP\Security\SecurityMeta;
use OmniWP\Settings;
use WP_Error;
use WP_Session_Tokens;

defined( 'ABSPATH' ) || exit;

class PasswordResetHandler {

	/** @var OtpService */
	private $otp;

	private IdentityDirectory $directory;

	public function __construct( ?OtpService $otp = null, ?IdentityDirectory $directory = null ) {
		$this->otp       = $otp ?? new OtpService();
		$this->directory = $directory ?? new IdentityDirectory();
	}

	/**
	 * Step 1: send a reset code to a registered identifier.
	 *
	 * @return array|WP_Error Result of OtpService::issue().
	 */
	public function start( array $input ) {
		$raw = trim( (string) wp_unslash( $input['identity'] ?? '' ) );

		if ( '' === $raw ) {
			return new WP_Error(
				'OMNIWP_no_identity',
				sprintf(
					/* translators: %s: identifier label. */
					__( 'Vui lòng nhập %s.', 'omniwp' ),
					mb_strtolower( RegisterHandler::identifier_label() )
				)
			);
		}

		// The same budget the identifier-first screen spends, and for the same
		// reason. When the subject is unknown this method returns below without
		// issuing a code, so it never reaches RateLimiter::check_otp_send() —
		// leaving forgot-password as a free, unmetered enumeration oracle even
		// after the identify screen was closed. Two doors, one lock.
		$allowed = ( new RateLimiter() )->check_identify( $raw );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// A synthetic placeholder address is rejected by MailChannel::is_valid(),
		// so it cannot produce a claim at all — no special case needed here.
		$claim = $this->directory->channels()->claim_any( $raw );

		if ( $claim->is_empty() ) {
			return new WP_Error( 'OMNIWP_bad_identity', __( 'Thông tin không hợp lệ.', 'omniwp' ) );
		}

		$resolution = $this->directory->resolve( $claim );
		$decision   = AuthAction::for_resolution( AuthAction::RECOVER, $resolution );

		// The whole point of the refactor lands on this branch. A subject whose
		// owner gave it up resolves RETIRED, the table maps that to NO_ACCOUNT,
		// and the previous owner is unreachable. The pre-refactor code resolved
		// ownership from wp_users.user_login, reported the old owner, and sent a
		// reset code to whoever now holds the recycled number.
		if ( AuthAction::ISSUE_RESET_GRANT !== $decision ) {
			/**
			 * Whether to tell the visitor that an identifier is not registered.
			 * Registration already reveals this, so it is on by default; turn it
			 * off for a stricter anti-enumeration posture.
			 *
			 * @param bool $reveal
			 */
			if ( AuthAction::NO_ACCOUNT === $decision && apply_filters( 'omniwp_reset_reveal_unknown', true ) ) {
				return new WP_Error(
					'OMNIWP_unknown_identity',
					__( 'Thông tin này chưa được đăng ký. Vui lòng kiểm tra lại hoặc tạo tài khoản mới.', 'omniwp' )
				);
			}

			return new WP_Error(
				'OMNIWP_reset_generic',
				__( 'Nếu thông tin hợp lệ, mã xác thực sẽ được gửi tới bạn.', 'omniwp' )
			);
		}

		$user = get_userdata( $resolution->user_id() );

		if ( ! $user ) {
			return new WP_Error( 'OMNIWP_no_user', __( 'Không tìm thấy tài khoản.', 'omniwp' ) );
		}

		$result = $this->otp->issue(
			$claim->subject(),
			OtpService::INTENT_RECOVER,
			array( 'user_id' => $user->ID ),
			array( 'user_name' => $user->display_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PendingSession::start( $result['token'], OtpService::INTENT_RECOVER );

		return $result;
	}

	/**
	 * Step 2: exchange a correct code for a one-time reset grant.
	 *
	 * @return string|WP_Error The grant token.
	 */
	public function verify( string $token, string $code ) {
		$row = $this->otp->verify( $token, $code, OtpService::INTENT_RECOVER );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( OtpService::INTENT_RECOVER !== $row['intent'] ) {
			return new WP_Error( 'OMNIWP_wrong_purpose', __( 'Phiên xác thực không hợp lệ.', 'omniwp' ) );
		}

		$user_id = (int) ( $row['payload']['user_id'] ?? 0 );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'OMNIWP_no_user', __( 'Không tìm thấy tài khoản.', 'omniwp' ) );
		}

		return PendingSession::grant_password_reset( $user_id );
	}

	/**
	 * Step 3: set the new password and invalidate every existing session.
	 *
	 * @return int|WP_Error User ID.
	 */
	public function complete( string $grant, array $input ) {
		$user_id = PendingSession::consume_password_reset( $grant );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'OMNIWP_grant_expired',
				__( 'Phiên đặt lại mật khẩu đã hết hạn. Vui lòng thực hiện lại.', 'omniwp' )
			);
		}

		$password = (string) wp_unslash( $input['password'] ?? '' );
		$confirm  = (string) wp_unslash( $input['password_confirm'] ?? '' );

		// The same policy as registration, including the
		// omniwp_validate_password filter, which used to apply only there.
		$verdict = PasswordPolicy::validate( $password, $confirm );

		if ( is_wp_error( $verdict ) ) {
			// The grant was consumed, so hand back a fresh one rather than making
			// the user redo the whole OTP flow over a typo.
			return new WP_Error(
				$verdict->get_error_code(),
				$verdict->get_error_message(),
				array( 'grant' => PendingSession::grant_password_reset( $user_id ) )
			);
		}

		wp_set_password( $password, $user_id );
		SecurityMeta::record_password_change( $user_id );

		// Kick every other session; a reset is meaningless if a stolen cookie survives.
		$tokens = WP_Session_Tokens::get_instance( $user_id );
		$tokens->destroy_all();

		PendingSession::clear();

		$user = get_userdata( $user_id );

		AuditLog::record(
			AuditLog::PASSWORD_RESET,
			$user ? RateLimiter::mask_identity( (string) get_user_meta( $user_id, UserManager::META_PHONE, true ) ?: $user->user_email ) : '',
			array(),
			$user_id
		);

		/**
		 * @param int $user_id
		 */
		do_action( 'omniwp_password_reset', $user_id );

		return $user_id;
	}
}
