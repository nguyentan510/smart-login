<?php
/**
 * One step of the flow, rendered as HTML for a caller that is not a page.
 *
 * The dialog fetches its markup instead of carrying it. That is not a
 * performance decision, it is a correctness one: `RequestGuard::fields()` writes
 * a nonce and an hour-limited signed timestamp into the markup, and markup
 * printed into every page is markup a full-page cache serves to every anonymous
 * visitor. Behind LiteSpeed, WP Rocket or Cloudflare an embedded form is a dead
 * nonce for everybody, on production only, on a site the developer cannot
 * reproduce. Fetching mints both per request.
 *
 * The templates are the ones the shortcode uses — `Shortcodes::render_step()`,
 * the shared half of `render_flow()`. A second set of templates for the dialog
 * would be a second source of truth, and this project has watched a rename
 * cross an untested boundary six times.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Auth\FlowDecision;
use OmniWP\Auth\FlowEngine;
use OmniWP\OTP\OtpService;

defined( 'ABSPATH' ) || exit;

class FragmentRenderer {

	/** @var OtpService|null */
	private $otp;

	public function __construct( ?OtpService $otp = null ) {
		$this->otp = $otp;
	}

	/**
	 * Render a step for a given host page.
	 *
	 * @param string $step         One of Flow's public steps, or a step the
	 *                             server itself just chose.
	 * @param string $page         Validated URL of the page the dialog is on.
	 * @param string $redirect_to  Validated URL to return to after signing in.
	 * @param array  $data         Step arguments, when a decision supplied them.
	 * @return array{step:string,html:string,title:string}
	 */
	public function render( string $step, string $page, string $redirect_to = '', array $data = array() ): array {
		Flow::set_base( $page, $redirect_to );

		/*
		 * The welcome screen is the one step a caller may ask for that is not on
		 * `Flow::PUBLIC_STEPS`, and only because the round trip through a
		 * provider cannot carry a decision home: the visitor comes back with
		 * `OmniWP_welcome=1` in the URL and the dialog reopens on it.
		 *
		 * So it is allowed, and then checked. Rendering onboarding for somebody
		 * who is not signed in would draw a form about an account that is not
		 * theirs — which is the argument `Flow::PUBLIC_STEPS` makes for keeping
		 * it off the list in the first place.
		 */
		if ( Flow::STEP_ONBOARD === $step && ! is_user_logged_in() ) {
			$step = Flow::STEP_IDENTIFY;
			$data = array();
		}

		// A step chosen by the server — password, signup, onboard — carries the
		// state that makes it meaningful. One asked for by a caller does not, so
		// it goes through Flow::step(), which allowlists it.
		if ( array() !== $data || in_array( $step, array( Flow::STEP_PASSWORD, Flow::STEP_SIGNUP, Flow::STEP_ONBOARD, Flow::STEP_DONE ), true ) ) {
			Flow::set( $step, $data );
		}

		$html = ( new Shortcodes() )->render_step( Flow::canonical( $step ) );

		return array(
			'step'  => Flow::step( $step ),
			'html'  => $html,
			'title' => self::title( Flow::step( $step ) ),
		);
	}

	/**
	 * Run a submitted step and render whatever comes next.
	 *
	 * The engine is the form-guarded one, deliberately: the dialog posts the
	 * fields of a real rendered form — nonce, signed timestamp, honeypot — so it
	 * is checked exactly as a page submit is. `FlowEngine::for_rest()` is for
	 * JSON clients that hold none of those.
	 *
	 * @return array{step?:string,html?:string,title?:string,redirect?:string,notices:array}
	 */
	public function submit( string $action, array $input, string $page, string $redirect_to = '' ): array {
		Flow::set_base( $page, $redirect_to );

		// in_place(): this flow draws its own screens, so a new member gets the
		// welcome step rendered here rather than a redirect to the account page —
		// or to wp-admin, on a site without WooCommerce. See AuthContext.
		$decision = ( new FlowEngine( $this->otp ) )->in_place()->handle( $action, $input );

		if ( null === $decision ) {
			return array(
				'notices'  => array(),
				'redirect' => $page,
			);
		}

		if ( array() !== $decision->remember ) {
			Flow::remember( $decision->remember );
		}

		// Notices ride inside the rendered fragment, because `partials/notices`
		// is already inside every template and a second channel would be a
		// second place for a message to be shown or forgotten. They are returned
		// alongside as well, for the one case with no fragment to put them in: a
		// decision that redirects.
		foreach ( $decision->notices as $notice ) {
			Notices::add( $notice['message'], $notice['type'] );
		}

		if ( $decision->is_redirect() ) {
			return array(
				'redirect' => $decision->redirect,
				'notices'  => $this->public_notices( $decision ),
			);
		}

		return $this->render( $decision->step, $page, $redirect_to, $decision->data )
			+ array( 'notices' => $this->public_notices( $decision ) );
	}

	/**
	 * @return array<int,array{type:string,message:string}>
	 */
	private function public_notices( FlowDecision $decision ): array {
		$out = array();

		foreach ( $decision->notices as $notice ) {
			$out[] = array(
				'type'    => $notice['type'],
				'message' => $notice['message'],
			);
		}

		return $out;
	}

	/**
	 * The dialog's accessible name for a step.
	 *
	 * A modal without one announces itself as "dialog" and nothing else. It
	 * lives here rather than in the template because the shell is what carries
	 * `aria-labelledby`, and the shell is not part of the fragment.
	 */
	public static function title( string $step ): string {
		switch ( Flow::canonical( $step ) ) {
			case Flow::STEP_OTP:
				return __( 'Nhập mã xác thực', 'omniwp' );

			case Flow::STEP_FORGOT:
			case Flow::STEP_RESET:
				return __( 'Khôi phục mật khẩu', 'omniwp' );

			case Flow::STEP_SIGNUP:
				return __( 'Tạo tài khoản', 'omniwp' );

			case Flow::STEP_ONBOARD:
				return __( 'Chào mừng bạn', 'omniwp' );

			case Flow::STEP_PASSWORD:
				return __( 'Nhập mật khẩu', 'omniwp' );
		}

		/*
		 * "Đăng nhập", not "Đăng nhập hoặc đăng ký".
		 *
		 * The page-hosted screen says the longer thing because there it is the
		 * only heading, and it has to reassure a visitor who does not know which
		 * branch they need. In the dialog it was the *second* place those four
		 * words appeared — the shell's bar and the fragment's own `<h2>`, forty
		 * pixels apart, saying the same sentence twice.
		 *
		 * The reassurance is not lost: the identifier-first flow never asks
		 * anybody to choose, and the lead sentence below the bar now says what
		 * the visitor gets rather than restating the title.
		 */
		return __( 'Đăng nhập', 'omniwp' );
	}
}
