<?php
/**
 * Handles every non-JS form submission and decides which step renders next.
 *
 * Runs on template_redirect, i.e. before any output, so cookies can still be
 * set and redirects still work.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Auth\LoginHandler;
use SmartLogin\Auth\AuthContext;
use SmartLogin\Auth\AuthProof;
use SmartLogin\Auth\PostAuthRedirector;
use SmartLogin\Auth\SessionIssuer;
use SmartLogin\Auth\PasswordResetHandler;
use SmartLogin\Auth\PendingSession;
use SmartLogin\Auth\RegisterHandler;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Security\RequestGuard;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class FormController {

	const ACTION_FIELD = 'smart_login_action';

	/** @var OtpService|null */
	private $otp;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp;
	}

	/**
	 * Built on demand: the channel router fires a filter, and constructing it at
	 * plugins_loaded would run before other plugins can hook it.
	 */
	private function otp(): OtpService {
		if ( null === $this->otp ) {
			$this->otp = new OtpService();
		}

		return $this->otp;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'dispatch' ), 5 );
	}

	public function dispatch(): void {
		Notices::absorb_flash();

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- verified per-action below.
		$post = wp_unslash( $_POST );

		switch ( $action ) {
			case 'register':
				$this->handle_register( $post );
				break;

			case 'verify_otp':
				$this->handle_verify_otp( $post );
				break;

			case 'resend_otp':
				$this->handle_resend_otp( $post );
				break;

			case 'login':
				$this->handle_login( $post );
				break;

			case 'forgot':
				$this->handle_forgot( $post );
				break;

			case 'reset_password':
				$this->handle_reset_password( $post );
				break;
		}
	}

	// -----------------------------------------------------------------

	private function handle_register( array $post ): void {
		if ( empty( $post['identity'] ) && array_key_exists( 'register_identity', $post ) ) {
			$post['identity'] = $post['register_identity'];
		}

		// WooCommerce/theme wrappers may use password_1/password_2 names.
		// Normalize them before the shared registration handler validates input.
		if ( empty( $post['password'] ) && array_key_exists( 'password_1', $post ) ) {
			$post['password'] = $post['password_1'];
		}

		if ( empty( $post['password'] ) && array_key_exists( 'register_password', $post ) ) {
			$post['password'] = $post['register_password'];
		}

		if ( empty( $post['password_confirm'] ) && array_key_exists( 'password_2', $post ) ) {
			$post['password_confirm'] = $post['password_2'];
		}

		if ( empty( $post['password_confirm'] ) && array_key_exists( 'register_password_confirm', $post ) ) {
			$post['password_confirm'] = $post['register_password_confirm'];
		}

		Flow::remember( $post );

		$guard = RequestGuard::verify( 'register', $post, 'register_' );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_REGISTER );
			return;
		}

		$handler = new RegisterHandler( $this->otp() );
		$result  = $handler->start( $post );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result, Flow::STEP_REGISTER );
			return;
		}

		Flow::set( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_REGISTER ) );
	}

	private function handle_verify_otp( array $post ): void {
		$guard = RequestGuard::verify( 'otp', $post );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_OTP );
			return;
		}

		$session = PendingSession::get();

		if ( ! $session ) {
			$this->fail(
				new WP_Error( 'smart_login_no_session', __( 'Phiên xác thực đã hết hạn. Vui lòng thực hiện lại.', 'smart-login' ) ),
				Flow::STEP_LOGIN
			);
			return;
		}

		$code = $this->extract_code( $post );

		switch ( $session['intent'] ) {
			case OtpService::INTENT_REGISTER:
				$this->finish_registration( $session['token'], $code );
				break;

			case OtpService::INTENT_RECOVER:
				$this->finish_reset_verification( $session['token'], $code );
				break;

			case OtpService::INTENT_LOGIN:
				$this->finish_device_login( $session['token'], $code );
				break;

			default:
				$this->fail(
					new WP_Error( 'smart_login_bad_purpose', __( 'Phiên xác thực không hợp lệ.', 'smart-login' ) ),
					Flow::STEP_LOGIN
				);
		}
	}

	private function finish_registration( string $token, string $code ): void {
		$handler = new RegisterHandler( $this->otp() );
		$user_id = $handler->complete( $token, $code );

		if ( is_wp_error( $user_id ) ) {
			$this->fail_otp( $user_id, $token );
			return;
		}

		// Mirrors the "CHÚC MỪNG" screen: confirm first, then on to the profile.
		Flow::set(
			Flow::STEP_DONE,
			array(
				'user_id'  => $user_id,
				'redirect' => RegisterHandler::post_register_redirect( $user_id ),
			)
		);
	}

	private function finish_reset_verification( string $token, string $code ): void {
		$handler = new PasswordResetHandler( $this->otp() );
		$grant   = $handler->verify( $token, $code );

		if ( is_wp_error( $grant ) ) {
			$this->fail_otp( $grant, $token );
			return;
		}

		PendingSession::clear();

		Flow::set( Flow::STEP_RESET, array( 'grant' => $grant ) );
	}

	private function finish_device_login( string $token, string $code ): void {
		$row = $this->otp()->verify( $token, $code, OtpService::INTENT_LOGIN );

		if ( is_wp_error( $row ) ) {
			$this->fail_otp( $row, $token );
			return;
		}

		$user_id = (int) ( $row['payload']['user_id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			$this->fail(
				new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) ),
				Flow::STEP_LOGIN
			);
			return;
		}

		$context = new AuthContext( array( 'auth_method' => 'otp', 'user_id' => $user_id, 'intended_url' => (string) ( $row['payload']['redirect_to'] ?? '' ) ) );
		$proof   = AuthProof::from_otp( $this->otp()->verified_claim( $row ), $user_id );
		$result = ( new SessionIssuer() )->issue( $proof, $user, $context, ! isset( $row['payload']['remember'] ) || ! empty( $row['payload']['remember'] ) );

		PendingSession::clear();

		$this->redirect( is_wp_error( $result ) ? LoginHandler::post_login_redirect( $context->intended_url ) : ( new PostAuthRedirector() )->redirect( $result, $context->intended_url ) );
	}

	private function handle_resend_otp( array $post ): void {
		$guard = RequestGuard::verify( 'otp', $post );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_OTP );
			return;
		}

		$session = PendingSession::get();

		if ( ! $session ) {
			$this->fail(
				new WP_Error( 'smart_login_no_session', __( 'Phiên xác thực đã hết hạn. Vui lòng thực hiện lại.', 'smart-login' ) ),
				Flow::STEP_LOGIN
			);
			return;
		}

		$result = $this->otp()->resend( $session['token'] );

		if ( is_wp_error( $result ) ) {
			// Keep the user on the OTP screen; the old code may still be valid.
			Notices::add_wp_error( $result );
			$this->restore_otp_step( $session['token'], $session['intent'] );
			return;
		}

		PendingSession::start( $result['token'], $session['intent'] );

		Notices::add( __( 'Đã gửi lại mã xác thực.', 'smart-login' ), 'success' );

		Flow::set( Flow::STEP_OTP, $result + array( 'intent' => $session['intent'] ) );
	}

	private function handle_login( array $post ): void {
		if ( empty( $post['identity'] ) && array_key_exists( 'login_identity', $post ) ) {
			$post['identity'] = $post['login_identity'];
		}

		if ( empty( $post['password'] ) && array_key_exists( 'login_password', $post ) ) {
			$post['password'] = $post['login_password'];
		}

		Flow::remember( array( 'identity' => $post['identity'] ?? '' ) );

		$guard = RequestGuard::verify( 'login', $post, 'login_' );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_LOGIN );
			return;
		}

		$identity = trim( (string) ( $post['identity'] ?? '' ) );
		$password = (string) ( $post['password'] ?? '' );
		$remember = ! empty( $post['remember'] );

		if ( '' === $identity || '' === $password ) {
			$this->fail(
				new WP_Error( 'smart_login_empty', __( 'Vui lòng nhập đầy đủ thông tin đăng nhập.', 'smart-login' ) ),
				Flow::STEP_LOGIN
			);
			return;
		}

		$handler = new LoginHandler();
		$user    = $handler->attempt( $identity, $password, $remember );

		if ( $user instanceof WP_User ) {
			$redirect_to = (string) ( $post['redirect_to'] ?? '' );
			$context = new AuthContext( array( 'auth_method' => 'password', 'user_id' => $user->ID, 'intended_url' => $redirect_to ) );
			$result = ( new SessionIssuer() )->issue( AuthProof::from_password( $user ), $user, $context, $remember );
			$this->redirect( is_wp_error( $result ) ? LoginHandler::post_login_redirect( $redirect_to ) : ( new PostAuthRedirector() )->redirect( $result, $redirect_to ) );
			return;
		}

		if ( is_wp_error( $user ) && 'smart_login_needs_otp' === $user->get_error_code() ) {
			$data = (array) $user->get_error_data();
			$this->start_device_otp( (int) ( $data['user_id'] ?? 0 ), (string) ( $post['redirect_to'] ?? '' ), $remember );
			return;
		}

		$this->fail(
			$user instanceof WP_Error ? $user : new WP_Error( 'smart_login_failed', __( 'Đăng nhập không thành công.', 'smart-login' ) ),
			Flow::STEP_LOGIN
		);
	}

	/**
	 * Password was right but the device is new: send a code before letting them in.
	 */
	private function start_device_otp( int $user_id, string $redirect_to, bool $remember = true ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			$this->fail( new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) ), Flow::STEP_LOGIN );
			return;
		}

		$phone       = (string) get_user_meta( $user_id, UserManager::META_PHONE, true );
		$destination = '' !== $phone ? $phone : $user->user_email;

		if ( '' === $phone && UserManager::is_synthetic_email( $user->user_email ) ) {
			// Nothing to send to — let them in rather than locking them out.
			$this->complete_login( $user, $redirect_to, $remember );
			return;
		}

		$result = $this->otp()->issue(
			$destination,
			OtpService::INTENT_LOGIN,
			array(
				'user_id'     => $user_id,
				'redirect_to' => $redirect_to,
				'remember'    => $remember,
			),
			array( 'user_name' => $user->display_name )
		);

		if ( is_wp_error( $result ) ) {
			$this->fail( $result, Flow::STEP_LOGIN );
			return;
		}

		PendingSession::start( $result['token'], OtpService::INTENT_LOGIN );

		Notices::add( __( 'Thiết bị mới được phát hiện. Vui lòng nhập mã xác thực vừa gửi cho bạn.', 'smart-login' ), 'info' );

		Flow::set( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_LOGIN ) );
	}

	private function complete_login( WP_User $user, string $redirect_to, bool $remember = true ): void {
		$result = ( new SessionIssuer() )->issue( AuthProof::from_password( $user ), $user, new AuthContext( array( 'auth_method' => 'password', 'user_id' => $user->ID, 'intended_url' => $redirect_to ) ), $remember );
		$this->redirect( is_wp_error( $result ) ? LoginHandler::post_login_redirect( $redirect_to ) : ( new PostAuthRedirector() )->redirect( $result, $redirect_to ) );
	}

	private function handle_forgot( array $post ): void {
		Flow::remember( array( 'identity' => $post['identity'] ?? '' ) );

		$guard = RequestGuard::verify( 'forgot', $post );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_FORGOT );
			return;
		}

		$handler = new PasswordResetHandler( $this->otp() );
		$result  = $handler->start( $post );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result, Flow::STEP_FORGOT );
			return;
		}

		Flow::set( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_RECOVER ) );
	}

	private function handle_reset_password( array $post ): void {
		$guard = RequestGuard::verify( 'reset', $post );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_RESET );
			return;
		}

		$grant   = (string) ( $post['grant'] ?? '' );
		$handler = new PasswordResetHandler( $this->otp() );
		$result  = $handler->complete( $grant, $post );

		if ( is_wp_error( $result ) ) {
			Notices::add_wp_error( $result );

			$data = $result->get_error_data();

			if ( is_array( $data ) && ! empty( $data['grant'] ) ) {
				Flow::set( Flow::STEP_RESET, array( 'grant' => $data['grant'] ) );
				return;
			}

			Flow::set( Flow::STEP_FORGOT );
			return;
		}

		Notices::flash( __( 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập bằng mật khẩu mới.', 'smart-login' ), 'success' );

		$this->redirect( Flow::url( Flow::STEP_LOGIN ) );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * The OTP screen renders one input per digit; JS mirrors them into a single
	 * hidden field, and this joins them when JS is unavailable.
	 */
	private function extract_code( array $post ): string {
		$code = preg_replace( '/\D/', '', (string) ( $post['otp_code'] ?? '' ) );

		if ( '' !== $code ) {
			return $code;
		}

		if ( empty( $post['otp_digit'] ) || ! is_array( $post['otp_digit'] ) ) {
			return '';
		}

		$digits = array_map(
			static function ( $value ) {
				return preg_replace( '/\D/', '', (string) $value );
			},
			$post['otp_digit']
		);

		return implode( '', $digits );
	}

	private function fail( WP_Error $error, string $step ): void {
		Notices::add_wp_error( $error );
		Flow::set( $step );
	}

	/**
	 * An OTP attempt failed: stay on the OTP screen unless the code is gone
	 * for good, in which case send the user back to the start.
	 */
	private function fail_otp( WP_Error $error, string $token ): void {
		Notices::add_wp_error( $error );

		$fatal = array( 'smart_login_otp_invalid', 'smart_login_otp_used', 'smart_login_wrong_purpose', 'smart_login_exists' );

		if ( in_array( $error->get_error_code(), $fatal, true ) ) {
			PendingSession::clear();
			Flow::set( Flow::STEP_LOGIN );
			return;
		}

		$session = PendingSession::get();
		$this->restore_otp_step( $token, $session['intent'] ?? '' );
	}

	/**
	 * Re-render the OTP screen with the live countdown of the existing code.
	 */
	private function restore_otp_step( string $token, string $intent ): void {
		$row = $this->otp()->peek( $token );

		if ( ! $row ) {
			Flow::set(
				Flow::STEP_OTP,
				array(
					'intent'     => $intent,
					'expires_in' => 0,
				)
			);
			return;
		}

		Flow::set(
			Flow::STEP_OTP,
			array(
				'intent'       => $intent,
				'masked'       => RateLimiter::mask_identity( $row['destination'] ),
				'expires_in'   => $this->otp()->seconds_left( $row ),
				'resend_after' => 0,
				'transport'    => $row['transport'],
			)
		);
	}

	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
