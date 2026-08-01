<?php
/**
 * Shortcodes rendering the auth flow anywhere on the site.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Address\AddressFields;
use SmartLogin\Auth\PendingSession;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\OtpService;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class Shortcodes {

	public function register(): void {
		add_shortcode( 'smart_auth', array( $this, 'render_login' ) );
		add_shortcode( 'smart_login', array( $this, 'render_login' ) );
		add_shortcode( 'smart_register', array( $this, 'render_register' ) );
		add_shortcode( 'smart_verify_otp', array( $this, 'render_otp' ) );
		add_shortcode( 'smart_forgot_password', array( $this, 'render_forgot' ) );
		add_shortcode( 'smart_profile', array( $this, 'render_profile' ) );
		add_shortcode( 'smart_account', array( $this, 'render_account' ) );
		add_shortcode( 'smart_address', array( $this, 'render_address' ) );
	}

	/**
	 * The editable account surface, on any page, with or without WooCommerce.
	 *
	 * [smart_profile] shows a member what is on file; this lets them change it.
	 * Until Phase 8.3 the only way to edit anything was the WooCommerce account
	 * page, so a site without WooCommerce had no profile editing at all and the
	 * summary's "Cập nhật ngay" link pointed into wp-admin.
	 */
	public function render_account( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_flow( Flow::STEP_IDENTIFY, (array) $atts );
		}

		Assets::enqueue();

		return TemplateLoader::render(
			'account',
			array(
				'sl_form' => new AccountForm( get_current_user_id(), AccountForm::CONTEXT_STANDALONE ),
				'notices' => Notices::all(),
			)
		);
	}

	/**
	 * Standalone address picker, usable inside any form on the site.
	 *
	 * Renders only the fields — the surrounding <form>, nonce and submit button
	 * belong to whoever is embedding it.
	 */
	public function render_address( $atts = array() ): string {
		if ( ! Settings::is_on( 'address.enabled' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'required' => 'yes',
			),
			(array) $atts,
			'smart_address'
		);

		$values = is_user_logged_in()
			? AddressFields::get_for_user( get_current_user_id() )
			: array();

		return AddressFields::render(
			array(
				'values'   => $values ?: null,
				'required' => 'no' !== $atts['required'],
			)
		);
	}

	public function render_login( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_IDENTIFY, (array) $atts );
	}

	/**
	 * Identifier-first has no separate registration screen — the entry step
	 * works out which one the visitor needs. The shortcode survives because
	 * pages are already using it, and only changes the framing of step 1.
	 */
	public function render_register( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_IDENTIFY, (array) $atts + array( 'mode' => 'register' ) );
	}

	public function render_otp( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_OTP, (array) $atts );
	}

	public function render_forgot( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_FORGOT, (array) $atts );
	}

	/**
	 * The whole state machine in one place, so a single page can host every step.
	 */
	public function render_flow( string $default_step, array $atts = array() ): string {
		Assets::enqueue();

		$step = Flow::step( $default_step );

		// A just-registered member arrives back here by redirect carrying the
		// welcome flag. Both the native flow and an OAuth signup land this way, so
		// this is the only place that decides to show onboarding.
		if ( self::is_welcome_request() ) {
			$step = Flow::STEP_ONBOARD;
		}

		// STEP_ONBOARD renders for a user who has just been signed in, so it is
		// the one authenticated step this box may show.
		if ( is_user_logged_in() && ! in_array( $step, array( Flow::STEP_DONE, Flow::STEP_ONBOARD ), true ) ) {
			return TemplateLoader::render(
				'logged-in',
				array(
					'user'       => wp_get_current_user(),
					'notices'    => Notices::all(),
					'my_account' => \SmartLogin\Auth\LoginHandler::post_login_redirect(),
				)
			);
		}

		$common = array(
			'notices' => Notices::all(),
			'atts'    => $atts,
		);

		switch ( $step ) {
			case Flow::STEP_PASSWORD:
				return TemplateLoader::render(
					'form-password',
					$common + array( 'identity' => (string) Flow::data( 'identity', Flow::old( 'identity' ) ) )
				);

			case Flow::STEP_SIGNUP:
				return TemplateLoader::render(
					'form-signup',
					$common + array(
						'grant'        => (string) Flow::data( 'grant', '' ),
						'terms_url'    => (string) Settings::get( 'signup.terms_url', '' ),
						'min_password' => max( 6, Settings::get_int( 'signup.min_password_length', 8 ) ),
					)
				);

			case Flow::STEP_OTP:
				return TemplateLoader::render( 'form-otp', $common + $this->otp_args() );

			case Flow::STEP_FORGOT:
				return TemplateLoader::render( 'form-forgot', $common );

			case Flow::STEP_RESET:
				return TemplateLoader::render( 'form-reset', $common + array( 'grant' => (string) Flow::data( 'grant', '' ) ) );

			case Flow::STEP_ONBOARD:
				return TemplateLoader::render( 'onboarding', $common + self::onboarding_args() );

			case Flow::STEP_DONE:
				return TemplateLoader::render(
					'registered-success',
					$common + array(
						'redirect' => (string) Flow::data( 'redirect', home_url( '/' ) ),
						'user_id'  => (int) Flow::data( 'user_id', 0 ),
					)
				);

			case Flow::STEP_IDENTIFY:
			default:
				return TemplateLoader::render( 'form-auth', $common + $this->identify_args( $atts ) );
		}
	}

	/**
	 * @param array $atts Shortcode attributes; `mode=register` only changes the
	 *                    wording, never which form is shown.
	 */
	private function identify_args( array $atts ): array {
		return array(
			'mode'      => 'register' === ( $atts['mode'] ?? '' ) ? 'register' : 'login',
			'terms_url' => (string) Settings::get( 'signup.terms_url', '' ),
		);
	}

	/**
	 * Arguments for the welcome screen, whether it was reached in place after a
	 * registration or by landing on the account page with ?smartlogin_welcome=1.
	 */
	/**
	 * Whether this request is a member returning from registration.
	 */
	public static function is_welcome_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only presentation switch.
		return is_user_logged_in() && ! empty( $_GET['smartlogin_welcome'] );
	}

	public static function onboarding_args(): array {
		$user_id  = (int) Flow::data( 'user_id', get_current_user_id() );
		$profiles = new \SmartLogin\Auth\ProfileCompletionService();
		$fields   = Flow::data( 'fields', null );

		// Marked here rather than before the redirect that brought us: the welcome
		// counts as delivered once it is actually on screen.
		if ( $user_id > 0 && ! $profiles->has_seen( $user_id ) ) {
			$profiles->mark_seen( $user_id, 'otp' );
		}

		return array(
			'user'          => $user_id > 0 ? get_userdata( $user_id ) : wp_get_current_user(),
			'fields'        => is_array( $fields ) ? $fields : $profiles->onboarding_fields( $user_id ),
			// Where "Hoàn tất" and "Để sau" both lead. post_register_redirect()
			// honours signup.redirect_register for a new account and is side-effect
			// free once the welcome has been marked seen just above.
			'redirect'      => (string) Flow::data( 'redirect', \SmartLogin\Auth\RegisterHandler::post_register_redirect( $user_id ) ),
			'address'       => Settings::is_on( 'address.enabled' )
				? AddressFields::get_for_user( $user_id )
				: array(),
			// Onboarding has no email input — changing one needs its own OTP
			// round-trip — so the screen points at the profile page instead.
			'email_missing' => UserManager::user_has_synthetic_email( $user_id ),
		);
	}

	/**
	 * Rebuild the OTP screen state, falling back to the live row when the page
	 * was reloaded rather than reached through a form post.
	 */
	private function otp_args(): array {
		$intent       = (string) Flow::data( 'intent', '' );
		$masked       = (string) Flow::data( 'masked', '' );
		$expires_in   = Flow::data( 'expires_in', null );
		$resend_after = (int) Flow::data( 'resend_after', 0 );
		$transport    = (string) Flow::data( 'transport', 'sms' );

		if ( null === $expires_in ) {
			$service = new OtpService();
			$session = PendingSession::get();
			$row     = $session ? $service->peek( $session['token'] ) : null;

			if ( $row ) {
				$intent     = $session['intent'];
				$masked     = \SmartLogin\Security\RateLimiter::mask_identity( $row['destination'] );
				$expires_in = $service->seconds_left( $row );
				$transport  = $row['transport'];
			} else {
				$expires_in = 0;
			}
		}

		return array(
			'intent'       => $intent,
			'masked'       => $masked,
			'expires_in'   => (int) $expires_in,
			'resend_after' => $resend_after,
			'transport'    => $transport,
			'otp_length'   => min( 8, max( 4, Settings::get_int( 'otp.length', 6 ) ) ),
			'dev_code'     => (string) Flow::data( 'dev_code', '' ),
			'has_session'  => (bool) PendingSession::token(),
		);
	}

	/**
	 * Profile summary with the "complete your profile" nudge from the plan.
	 */
	public function render_profile( $atts = array() ): string {
		Assets::enqueue();

		if ( ! is_user_logged_in() ) {
			return $this->render_flow( Flow::STEP_LOGIN, (array) $atts );
		}

		$user_id = get_current_user_id();
		$status  = ( new \SmartLogin\Auth\ProfileCompletionService() )->status( $user_id );

		return TemplateLoader::render(
			'profile-summary',
			array(
				'user'      => wp_get_current_user(),
				'notices'   => Notices::all(),
				'missing'   => UserManager::missing_profile_fields( $user_id ),
				'status'    => $status,
				'pending'   => ( new \SmartLogin\Auth\ContactVerificationService() )->pending( $user_id ),
				'phone'     => (string) get_user_meta( $user_id, UserManager::META_PHONE, true ),
				'synthetic' => UserManager::user_has_synthetic_email( $user_id ),
				'welcome'   => ! empty( $_GET['smartlogin_welcome'] ), // phpcs:ignore WordPress.Security.NonceVerification
			)
		);
	}
}
