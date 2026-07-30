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
		add_shortcode( 'smart_address', array( $this, 'render_address' ) );
	}

	/**
	 * Standalone address picker, usable inside any form on the site.
	 *
	 * Renders only the fields — the surrounding <form>, nonce and submit button
	 * belong to whoever is embedding it.
	 */
	public function render_address( $atts = array() ): string {
		if ( ! Settings::is_on( 'address_enabled' ) ) {
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
		return $this->render_flow( Flow::STEP_LOGIN, (array) $atts );
	}

	public function render_register( $atts = array() ): string {
		return $this->render_flow( Flow::STEP_REGISTER, (array) $atts );
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

		if ( is_user_logged_in() && Flow::STEP_DONE !== $step ) {
			return TemplateLoader::render(
				'logged-in',
				array(
					'user'     => wp_get_current_user(),
					'notices'  => Notices::all(),
					'my_account' => \SmartLogin\Auth\LoginHandler::post_login_redirect(),
				)
			);
		}

		$common = array(
			'notices' => Notices::all(),
			'atts'    => $atts,
		);

		switch ( $step ) {
			case Flow::STEP_REGISTER:
				return TemplateLoader::render(
					'form-auth',
					$common + $this->register_args() + array( 'active_tab' => Flow::STEP_REGISTER )
				);

			case Flow::STEP_OTP:
				return TemplateLoader::render( 'form-otp', $common + $this->otp_args() );

			case Flow::STEP_FORGOT:
				return TemplateLoader::render( 'form-forgot', $common );

			case Flow::STEP_RESET:
				return TemplateLoader::render( 'form-reset', $common + array( 'grant' => (string) Flow::data( 'grant', '' ) ) );

			case Flow::STEP_DONE:
				return TemplateLoader::render(
					'registered-success',
					$common + array(
						'redirect' => (string) Flow::data( 'redirect', home_url( '/' ) ),
						'user_id'  => (int) Flow::data( 'user_id', 0 ),
					)
				);

			case Flow::STEP_LOGIN:
			default:
				return TemplateLoader::render(
					'form-auth',
					$common + $this->register_args() + array( 'active_tab' => Flow::STEP_LOGIN )
				);
		}
	}

	private function register_args(): array {
		return array(
			'terms_url' => (string) Settings::get( 'terms_url', '' ),
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
			'otp_length'   => min( 8, max( 4, Settings::get_int( 'otp_length', 6 ) ) ),
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
