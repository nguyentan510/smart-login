<?php
/**
 * Forgot password: OTP → short-lived grant → new password.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\IdentityResolver;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Settings;
use WP_Error;
use WP_Session_Tokens;

defined( 'ABSPATH' ) || exit;

class PasswordResetHandler {

	/** @var OtpService */
	private $otp;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp ?? new OtpService();
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
				'smart_login_no_identity',
				sprintf(
					/* translators: %s: identifier label. */
					__( 'Vui lòng nhập %s.', 'smart-login' ),
					mb_strtolower( IdentityResolver::identifier_label() )
				)
			);
		}

		$identity = IdentityResolver::classify( $raw );

		if ( '' === $identity['value'] ) {
			return new WP_Error( 'smart_login_bad_identity', __( 'Thông tin không hợp lệ.', 'smart-login' ) );
		}

		$user = IdentityResolver::resolve( $raw );

		if ( ! $user ) {
			/**
			 * Whether to tell the visitor that an identifier is not registered.
			 * Registration already reveals this, so it is on by default; turn it
			 * off for a stricter anti-enumeration posture.
			 *
			 * @param bool $reveal
			 */
			if ( apply_filters( 'smart_login_reset_reveal_unknown', true ) ) {
				return new WP_Error(
					'smart_login_unknown_identity',
					__( 'Thông tin này chưa được đăng ký. Vui lòng kiểm tra lại hoặc tạo tài khoản mới.', 'smart-login' )
				);
			}

			return new WP_Error(
				'smart_login_reset_generic',
				__( 'Nếu thông tin hợp lệ, mã xác thực sẽ được gửi tới bạn.', 'smart-login' )
			);
		}

		// A phone-registered account has no real inbox to fall back on.
		$destination = $identity['value'];

		if ( IdentityResolver::TYPE_EMAIL === $identity['type'] && UserManager::is_synthetic_email( $destination ) ) {
			return new WP_Error( 'smart_login_bad_identity', __( 'Thông tin không hợp lệ.', 'smart-login' ) );
		}

		$result = $this->otp->issue(
			$destination,
			OtpService::PURPOSE_RESET,
			array( 'user_id' => $user->ID ),
			array( 'user_name' => $user->display_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PendingSession::start( $result['token'], OtpService::PURPOSE_RESET );

		return $result;
	}

	/**
	 * Step 2: exchange a correct code for a one-time reset grant.
	 *
	 * @return string|WP_Error The grant token.
	 */
	public function verify( string $token, string $code ) {
		$row = $this->otp->verify( $token, $code, OtpService::PURPOSE_RESET );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( OtpService::PURPOSE_RESET !== $row['purpose'] ) {
			return new WP_Error( 'smart_login_wrong_purpose', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) );
		}

		$user_id = (int) ( $row['payload']['user_id'] ?? 0 );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
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
				'smart_login_grant_expired',
				__( 'Phiên đặt lại mật khẩu đã hết hạn. Vui lòng thực hiện lại.', 'smart-login' )
			);
		}

		$password = (string) wp_unslash( $input['password'] ?? '' );
		$confirm  = (string) wp_unslash( $input['password_confirm'] ?? '' );
		$min      = max( 6, Settings::get_int( 'min_password_length', 8 ) );

		if ( mb_strlen( $password ) < $min ) {
			// The grant was consumed, so hand back a fresh one rather than
			// making the user redo the whole OTP flow over a typo.
			return new WP_Error(
				'smart_login_weak_password',
				sprintf(
					/* translators: %d: minimum length. */
					__( 'Mật khẩu phải có ít nhất %d ký tự.', 'smart-login' ),
					$min
				),
				array( 'grant' => PendingSession::grant_password_reset( $user_id ) )
			);
		}

		if ( $password !== $confirm ) {
			return new WP_Error(
				'smart_login_password_mismatch',
				__( 'Mật khẩu nhập lại không khớp.', 'smart-login' ),
				array( 'grant' => PendingSession::grant_password_reset( $user_id ) )
			);
		}

		wp_set_password( $password, $user_id );

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
		do_action( 'smart_login_password_reset', $user_id );

		return $user_id;
	}
}
