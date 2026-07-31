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

use SmartLogin\Address\AddressFields;
use SmartLogin\Auth\LoginHandler;
use SmartLogin\Auth\AuthAction;
use SmartLogin\Auth\AuthContext;
use SmartLogin\Auth\AuthProof;
use SmartLogin\Auth\IdentityLinkService;
use SmartLogin\Auth\PostAuthRedirector;
use SmartLogin\Auth\ProfileCompletionService;
use SmartLogin\Auth\SessionIssuer;
use SmartLogin\Auth\PasswordResetHandler;
use SmartLogin\Auth\PendingSession;
use SmartLogin\Auth\RegisterHandler;
use SmartLogin\Identity\IdentityDirectory;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Security\RequestGuard;
use SmartLogin\Settings;
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

		if ( 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- verified per-action below.
		$post = wp_unslash( $_POST );

		switch ( $action ) {
			case 'identify':
				$this->handle_identify( $post );
				break;

			case 'signup':
				$this->handle_signup( $post );
				break;

			case 'onboard':
				$this->handle_onboard( $post );
				break;

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

			case 'unlink_identity':
				$this->handle_unlink_identity( $post );
				break;
		}
	}

	/**
	 * Detach an identity from the signed-in account, without JavaScript.
	 *
	 * Redirects back to the same page either way so the notice renders in place
	 * rather than on a bare POST response.
	 */
	private function handle_unlink_identity( array $post ): void {
		if ( ! is_user_logged_in() ) {
			Notices::add( __( 'Bạn cần đăng nhập để thực hiện việc này.', 'smart-login' ) );
			return;
		}

		if ( ! isset( $post['_wpnonce'] ) || ! wp_verify_nonce( $post['_wpnonce'], 'smart_login_unlink_identity' ) ) {
			Notices::add( __( 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.', 'smart-login' ) );
			return;
		}

		$result = ( new IdentityLinkService() )->unlink(
			get_current_user_id(),
			sanitize_key( (string) ( $post['channel'] ?? '' ) ),
			(string) ( $post['subject'] ?? '' ),
			(string) ( $post['password'] ?? '' )
		);

		// flash(), not add(): the redirect below discards anything not persisted.
		if ( is_wp_error( $result ) ) {
			Notices::flash( $result->get_error_message(), 'error' );
		} else {
			Notices::flash( __( 'Đã bỏ liên kết.', 'smart-login' ), 'success' );
		}

		$redirect = (string) ( $post['_redirect'] ?? '' );
		$this->redirect( '' !== $redirect ? $redirect : home_url( '/' ) );
	}

	// -----------------------------------------------------------------
	// Identifier-first
	// -----------------------------------------------------------------

	/**
	 * Step 1: one identifier, and the flow works out the rest.
	 *
	 * A registered subject goes to the password screen; anything else starts a
	 * registration. The visitor is never asked to declare up front whether they
	 * already have an account — they usually do not know, and getting it wrong
	 * used to mean an error message and a retyped form.
	 */
	private function handle_identify( array $post ): void {
		$identity = trim( (string) ( $post['identity'] ?? '' ) );

		Flow::remember( array( 'identity' => $identity ) );

		$guard = RequestGuard::verify( 'identify', $post );

		if ( is_wp_error( $guard ) ) {
			$this->fail( $guard, Flow::STEP_IDENTIFY );
			return;
		}

		if ( '' === $identity ) {
			$this->fail(
				new WP_Error(
					'smart_login_no_identity',
					sprintf(
						/* translators: %s: identifier label, e.g. "số điện thoại". */
						__( 'Vui lòng nhập %s.', 'smart-login' ),
						mb_strtolower( RegisterHandler::identifier_label() )
					)
				),
				Flow::STEP_IDENTIFY
			);
			return;
		}

		$directory = new IdentityDirectory();
		$claim     = $directory->channels()->claim_any( $identity );

		if ( $claim->is_empty() ) {
			$this->fail(
				new WP_Error(
					'smart_login_bad_identity',
					sprintf(
						/* translators: %s: identifier label, e.g. "Số điện thoại". */
						__( '%s không hợp lệ.', 'smart-login' ),
						RegisterHandler::identifier_label()
					)
				),
				Flow::STEP_IDENTIFY
			);
			return;
		}

		$decision = AuthAction::for_resolution( AuthAction::LOGIN, $directory->resolve( $claim ) );

		if ( AuthAction::ISSUE_SESSION === $decision ) {
			$this->password_step( $identity );
			return;
		}

		// Anything else means there is nothing to sign in to. NO_ACCOUNT covers
		// both "never seen" and "the previous owner gave this subject up", and
		// both are a new account here. REJECT is unreachable — resolve() cannot
		// return CONFLICT — and start_identity() refuses it in any case, so the
		// fall-through is not load-bearing.
		$result = ( new RegisterHandler( $this->otp() ) )->start_identity( array( 'identity' => $identity ) );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result, Flow::STEP_IDENTIFY );
			return;
		}

		Flow::set( Flow::STEP_OTP, $result + array( 'intent' => OtpService::INTENT_REGISTER ) );
	}

	private function password_step( string $identity ): void {
		Flow::set( Flow::STEP_PASSWORD, array( 'identity' => $identity ) );
	}

	/**
	 * Step 3 of a registration: name and password, against an already-proven
	 * identifier held server-side behind the grant.
	 */
	private function handle_signup( array $post ): void {
		Flow::remember( $post );

		$grant = (string) ( $post['grant'] ?? '' );
		$guard = RequestGuard::verify( 'signup', $post );

		if ( is_wp_error( $guard ) ) {
			// The grant was not consumed, so it is still good for the retry.
			$this->fail_signup( $guard, $grant );
			return;
		}

		$user_id = ( new RegisterHandler( $this->otp() ) )->finish_signup( $grant, $post );

		if ( is_wp_error( $user_id ) ) {
			$this->fail_signup( $user_id, '' );
			return;
		}

		$this->after_registration( (int) $user_id );
	}

	/**
	 * @param WP_Error $error          Carries a fresh grant when one was issued.
	 * @param string   $fallback_grant Used when the failure happened before the
	 *                                 grant was spent, so no fresh one exists.
	 */
	private function fail_signup( WP_Error $error, string $fallback_grant ): void {
		Notices::add_wp_error( $error );

		$data  = $error->get_error_data();
		$grant = is_array( $data ) && ! empty( $data['grant'] ) ? (string) $data['grant'] : $fallback_grant;

		if ( '' === $grant ) {
			// No proof left to build an account on; the identifier must be
			// verified again from the start.
			Flow::set( Flow::STEP_IDENTIFY );
			return;
		}

		Flow::set( Flow::STEP_SIGNUP, array( 'grant' => $grant ) );
	}

	/**
	 * The account exists and the user is signed in. Show the welcome screen in
	 * place rather than redirecting to a profile form.
	 */
	private function after_registration( int $user_id ): void {
		$profiles = new ProfileCompletionService();

		// Mark it seen now, because it is being shown now. This also settles what
		// post_register_redirect() returns below: with the welcome already
		// delivered it resolves to the ordinary destination instead of looping
		// back to the profile page.
		$profiles->mark_seen( $user_id, 'otp' );

		Flow::set(
			Flow::STEP_ONBOARD,
			array(
				'user_id'  => $user_id,
				'fields'   => $profiles->onboarding_fields( $user_id ),
				'redirect' => RegisterHandler::post_register_redirect( $user_id ),
			)
		);
	}

	/**
	 * Save whatever the welcome screen collected, then get out of the way.
	 *
	 * Every field here is optional by construction: "Để sau" posts the same form
	 * with nothing filled in, and that is a valid outcome rather than a
	 * validation error.
	 */
	private function handle_onboard( array $post ): void {
		$redirect = wp_validate_redirect( (string) ( $post['redirect_to'] ?? '' ), '' );
		$redirect = '' !== $redirect ? $redirect : LoginHandler::post_login_redirect();

		if ( ! is_user_logged_in() ) {
			$this->redirect( $redirect );
			return;
		}

		// "Để sau" writes nothing, so it is let through without the form guard.
		// RequestGuard enforces a two-second minimum fill time, and somebody who
		// decides immediately is exactly who that button is for — failing them
		// for answering too quickly would be the opposite of taking no for an
		// answer.
		if ( ! empty( $post['sl_skip'] ) ) {
			$this->redirect( $redirect );
			return;
		}

		$guard = RequestGuard::verify( 'onboard', $post );

		if ( is_wp_error( $guard ) ) {
			// Stay on the screen rather than redirecting: a redirect here would
			// throw away everything they had just typed.
			Notices::add_wp_error( $guard );
			Flow::set( Flow::STEP_ONBOARD, array( 'redirect' => $redirect ) );
			return;
		}

		$this->save_onboarding( get_current_user_id(), $post );

		$this->redirect( $redirect );
	}

	private function save_onboarding( int $user_id, array $post ): void {
		$full_name = sanitize_text_field( (string) ( $post['full_name'] ?? '' ) );

		if ( '' !== trim( $full_name ) ) {
			$names = UserManager::split_name( $full_name );

			wp_update_user(
				array(
					'ID'           => $user_id,
					'first_name'   => $names['first'],
					'last_name'    => '' !== $names['last'] ? $names['last'] : $names['first'],
					'display_name' => $full_name,
				)
			);
		}

		$dob = RegisterHandler::parse_dob( (string) ( $post['dob'] ?? '' ) );

		if ( '' !== $dob ) {
			update_user_meta( $user_id, UserManager::META_DOB, $dob );
		}

		$gender = sanitize_key( (string) ( $post['gender'] ?? '' ) );

		if ( in_array( $gender, array( 'male', 'female', 'other' ), true ) ) {
			update_user_meta( $user_id, UserManager::META_GENDER, $gender );
		}

		if ( ! isset( $post[ AddressFields::FIELD_PROVINCE ] ) ) {
			return;
		}

		// Never required on this screen, whatever address_required_in_profile
		// says: a welcome that can be failed is not a welcome.
		$address = AddressFields::validate( $post, false );

		if ( is_wp_error( $address ) ) {
			Notices::flash( $address->get_error_message(), 'error' );
			return;
		}

		AddressFields::save_for_user( $user_id, $address );
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

	/**
	 * The code checked out. Either the account can be created right now, or the
	 * name and password are still to come — see RegisterHandler::verify().
	 */
	private function finish_registration( string $token, string $code ): void {
		$result = ( new RegisterHandler( $this->otp() ) )->verify( $token, $code );

		if ( is_wp_error( $result ) ) {
			$this->fail_otp( $result, $token );
			return;
		}

		if ( isset( $result['grant'] ) ) {
			Flow::set( Flow::STEP_SIGNUP, array( 'grant' => (string) $result['grant'] ) );
			return;
		}

		$this->after_registration( (int) $result['user_id'] );
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

		$context = new AuthContext(
			array(
				'auth_method'  => 'otp',
				'user_id'      => $user_id,
				'intended_url' => (string) ( $row['payload']['redirect_to'] ?? '' ),
			)
		);
		$proof   = AuthProof::from_otp( $this->otp()->verified_claim( $row ), $user_id );
		$result  = ( new SessionIssuer() )->issue( $proof, $user, $context, ! isset( $row['payload']['remember'] ) || ! empty( $row['payload']['remember'] ) );

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

		$identity = trim( (string) ( $post['identity'] ?? '' ) );

		// Identifier-first posts from the password screen, which already knows the
		// identifier is registered. A rejected password must land back there
		// rather than throwing the visitor out to step 1 to retype it.
		$fail_step = empty( $post['sl_from_password'] ) ? Flow::STEP_IDENTIFY : Flow::STEP_PASSWORD;
		$fail_data = Flow::STEP_PASSWORD === $fail_step ? array( 'identity' => $identity ) : array();

		$guard = RequestGuard::verify( 'login', $post, 'login_' );

		if ( is_wp_error( $guard ) ) {
			Notices::add_wp_error( $guard );
			Flow::set( $fail_step, $fail_data );
			return;
		}

		$password = (string) ( $post['password'] ?? '' );
		$remember = ! empty( $post['remember'] );

		if ( '' === $identity || '' === $password ) {
			Notices::add( __( 'Vui lòng nhập đầy đủ thông tin đăng nhập.', 'smart-login' ) );
			Flow::set( $fail_step, $fail_data );
			return;
		}

		$handler = new LoginHandler();
		$user    = $handler->attempt( $identity, $password );

		if ( $user instanceof WP_User ) {
			$redirect_to = (string) ( $post['redirect_to'] ?? '' );
			$context     = new AuthContext(
				array(
					'auth_method'  => 'password',
					'user_id'      => $user->ID,
					'intended_url' => $redirect_to,
				)
			);
			$result      = ( new SessionIssuer() )->issue( AuthProof::from_password( $user ), $user, $context, $remember );
			$this->redirect( is_wp_error( $result ) ? LoginHandler::post_login_redirect( $redirect_to ) : ( new PostAuthRedirector() )->redirect( $result, $redirect_to ) );
			return;
		}

		if ( is_wp_error( $user ) && 'smart_login_needs_otp' === $user->get_error_code() ) {
			$data = (array) $user->get_error_data();
			$this->start_device_otp( (int) ( $data['user_id'] ?? 0 ), (string) ( $post['redirect_to'] ?? '' ), $remember );
			return;
		}

		Notices::add_wp_error(
			$user instanceof WP_Error ? $user : new WP_Error( 'smart_login_failed', __( 'Đăng nhập không thành công.', 'smart-login' ) )
		);
		Flow::set( $fail_step, $fail_data );
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
		$result = ( new SessionIssuer() )->issue(
			AuthProof::from_password( $user ),
			$user,
			new AuthContext(
				array(
					'auth_method'  => 'password',
					'user_id'      => $user->ID,
					'intended_url' => $redirect_to,
				)
			),
			$remember
		);
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
				// Derived from when the code actually went out, not reset to zero.
				// Showing an enabled "Gửi lại" while RateLimiter still holds the
				// cooldown open only buys the visitor an error message.
				'resend_after' => $this->resend_wait( $row ),
				'transport'    => $row['transport'],
			)
		);
	}

	/**
	 * Seconds left on the resend cooldown for a pending code.
	 */
	private function resend_wait( array $row ): int {
		$sent_at = strtotime( ( $row['created_at'] ?? '' ) . ' UTC' );

		if ( ! $sent_at ) {
			return 0;
		}

		return max( 0, Settings::get_int( 'otp_resend_cooldown', 60 ) - ( time() - $sent_at ) );
	}

	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
