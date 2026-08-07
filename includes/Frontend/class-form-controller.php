<?php
/**
 * Handles every non-JS form submission and applies what the flow decided.
 *
 * Runs on template_redirect, i.e. before any output, so cookies can still be
 * set and redirects still work.
 *
 * Since 19.1 this class no longer *decides* anything about the sign-in flow. It
 * routes a posted action to `FlowEngine`, which returns a `FlowDecision`, and
 * then applies that decision the way an HTML page has to: notices into the
 * request bag or a flash cookie, a step into `Flow::set()`, a destination into
 * `wp_safe_redirect(); exit;`.
 *
 * The reason is the dialog. It posts the same steps over REST and cannot reuse
 * a method that ends in `exit`, and two implementations of the state machine
 * that decides who gets signed in is not a risk this project takes — it has
 * watched a rename cross an untested boundary five times.
 *
 * What stayed: `save_profile` and `unlink_identity`. Those are account-surface
 * actions performed by somebody already signed in, not steps of the sign-in
 * flow, and they have always belonged here.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Auth\FlowDecision;
use SmartLogin\Auth\FlowEngine;
use SmartLogin\Auth\IdentityLinkService;
use SmartLogin\OTP\OtpService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class FormController {

	const ACTION_FIELD = 'smart_login_action';

	/** @var OtpService|null */
	private $otp;

	/** @var FlowEngine|null */
	private $engine = null;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp;
	}

	/**
	 * Built on demand: the channel router fires a filter, and constructing it at
	 * plugins_loaded would run before other plugins can hook it.
	 */
	private function engine(): FlowEngine {
		if ( null === $this->engine ) {
			$this->engine = new FlowEngine( $this->otp );
		}

		return $this->engine;
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
				return;

			case 'signup':
				$this->handle_signup( $post );
				return;

			case 'onboard':
				$this->handle_onboard( $post );
				return;

			case 'register':
				$this->handle_register( $post );
				return;

			case 'verify_otp':
				$this->handle_verify_otp( $post );
				return;

			case 'resend_otp':
				$this->handle_resend_otp( $post );
				return;

			case 'login':
				$this->handle_login( $post );
				return;

			case 'forgot':
				$this->handle_forgot( $post );
				return;

			case 'reset_password':
				$this->handle_reset_password( $post );
				return;

			case 'unlink_identity':
				$this->handle_unlink_identity( $post );
				return;

			case 'save_profile':
				$this->handle_save_profile( $post );
				return;
		}
	}

	// -----------------------------------------------------------------
	// The flow: decided by FlowEngine, applied here
	// -----------------------------------------------------------------

	private function handle_identify( array $post ): void {
		$this->apply( $this->engine()->identify( $post ) );
	}

	private function handle_signup( array $post ): void {
		$this->apply( $this->engine()->signup( $post ) );
	}

	private function handle_onboard( array $post ): void {
		$this->apply( $this->engine()->onboard( $post ) );
	}

	private function handle_register( array $post ): void {
		$this->apply( $this->engine()->register( $post ) );
	}

	private function handle_verify_otp( array $post ): void {
		$this->apply( $this->engine()->verify_otp( $post ) );
	}

	private function handle_resend_otp( array $post ): void {
		$this->apply( $this->engine()->resend_otp( $post ) );
	}

	private function handle_login( array $post ): void {
		$this->apply( $this->engine()->login( $post ) );
	}

	private function handle_forgot( array $post ): void {
		$this->apply( $this->engine()->forgot( $post ) );
	}

	private function handle_reset_password( array $post ): void {
		$this->apply( $this->engine()->reset_password( $post ) );
	}

	/**
	 * Turn a decision into an HTML response.
	 *
	 * Order matters and is the order the handlers used to write it in: remember
	 * the typed values first so a re-rendered form keeps them, then the notices,
	 * then either a step or a redirect.
	 *
	 * `flash` versus `add` is not a style choice — a redirect discards anything
	 * not persisted, so a message attached to a redirecting outcome has to go
	 * through the cookie.
	 */
	private function apply( FlowDecision $decision ): void {
		if ( array() !== $decision->remember ) {
			Flow::remember( $decision->remember );
		}

		foreach ( $decision->notices as $notice ) {
			if ( $notice['flash'] ) {
				Notices::flash( $notice['message'], $notice['type'] );
				continue;
			}

			Notices::add( $notice['message'], $notice['type'] );
		}

		if ( $decision->is_redirect() ) {
			$this->redirect( $decision->redirect );
			return;
		}

		if ( '' !== $decision->step ) {
			Flow::set( $decision->step, $decision->data );
		}
	}

	// -----------------------------------------------------------------
	// The account surface
	// -----------------------------------------------------------------

	/**
	 * Save the account profile without WooCommerce.
	 *
	 * On the WooCommerce account page WC_Form_Handler::save_account_details() does
	 * this, and must keep doing it — third-party plugins hook
	 * woocommerce_save_account_details. Everywhere else there was nothing at all:
	 * deactivate WooCommerce and the plugin had no way for a member to edit their
	 * own profile. See docs/account-surface.md.
	 *
	 * Email is deliberately absent. It only ever moves through the OTP flow, and
	 * block_unverified_email_change() rejects every other route.
	 */
	private function handle_save_profile( array $post ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id  = get_current_user_id();
		$redirect = wp_validate_redirect( (string) ( $post['_redirect'] ?? '' ), '' );
		$redirect = '' !== $redirect ? $redirect : (string) ( wp_get_referer() ?: home_url( '/' ) );

		// A plain nonce, as handle_unlink_identity() uses. RequestGuard's honeypot
		// and minimum fill time are aimed at anonymous forms; on a profile page the
		// visitor is already authenticated, and the timing check would reject
		// somebody who only flipped one radio button and pressed save.
		if ( ! wp_verify_nonce( (string) ( $post['_wpnonce'] ?? '' ), 'smart_login_save_profile' ) ) {
			Notices::flash( __( 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.', 'smart-login' ), 'error' );
			$this->redirect( $redirect );
			return;
		}

		// The account form names its fields for WooCommerce, which is the surface
		// that cannot be changed. Normalising here rather than renaming the inputs
		// keeps one set of markup serving both save paths — the same trick
		// WooIntegration::prepare_account_post() plays in the other direction.
		foreach ( array(
			'smartlogin_full_name' => 'full_name',
			'smartlogin_dob'       => 'dob',
			'smartlogin_gender'    => 'gender',
		) as $sl_posted => $sl_internal ) {
			if ( isset( $post[ $sl_posted ] ) && ! isset( $post[ $sl_internal ] ) ) {
				$post[ $sl_internal ] = $post[ $sl_posted ];
			}
		}

		// The same writer the welcome screen uses. It reports an address problem
		// by putting a notice on the decision rather than flashing it itself, so
		// this method stays the one place that decides what survives the redirect.
		$decision = new FlowDecision();

		$this->engine()->save_onboarding( $decision, $user_id, $post );

		foreach ( $decision->notices as $notice ) {
			Notices::flash( $notice['message'], $notice['type'] );
		}

		$password = $this->save_password( $user_id, $post );

		if ( is_wp_error( $password ) ) {
			Notices::flash( $password->get_error_message(), 'error' );
			$this->redirect( $redirect );
			return;
		}

		Notices::flash( __( 'Đã lưu thông tin của bạn.', 'smart-login' ), 'success' );
		$this->redirect( $redirect );
	}

	/**
	 * Change the password, but only when one was actually typed.
	 *
	 * Blank fields must save the rest of the form untouched — that is what "để
	 * trống nếu không muốn thay đổi" promises.
	 *
	 * wp_update_user() rather than wp_set_password(): for the current user it
	 * re-issues the auth cookie, so changing your own password does not sign you
	 * out mid-edit. wp_set_password() would, and re-authenticating here would mean
	 * minting a session outside SessionIssuer.
	 *
	 * @return true|WP_Error
	 */
	private function save_password( int $user_id, array $post ) {
		$new = (string) ( $post['password_1'] ?? '' );

		if ( '' === $new ) {
			return true;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
		}

		if ( ! wp_check_password( (string) ( $post['password_current'] ?? '' ), $user->user_pass, $user_id ) ) {
			return new WP_Error( 'smart_login_bad_password', __( 'Mật khẩu hiện tại không đúng.', 'smart-login' ) );
		}

		if ( (string) ( $post['password_2'] ?? '' ) !== $new ) {
			return new WP_Error( 'smart_login_password_mismatch', __( 'Hai mật khẩu mới không khớp.', 'smart-login' ) );
		}

		$policy = \SmartLogin\Auth\PasswordPolicy::validate( $new );

		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		$updated = wp_update_user(
			array(
				'ID'        => $user_id,
				'user_pass' => $new,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// After the write and not before: a failed update must not leave a date
		// claiming a password changed when it did not.
		\SmartLogin\Security\SecurityMeta::record_password_change( $user_id );

		return true;
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

	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
