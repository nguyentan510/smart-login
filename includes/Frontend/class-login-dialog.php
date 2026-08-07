<?php
/**
 * The sign-in dialog: its shell, its launcher, and the contract they share.
 *
 * Two-stage assets. A small launcher is enqueued site-wide and owns the trigger
 * contract and nothing else; the stylesheet and the flow script arrive on the
 * first open. The alternative — 1,512 lines of CSS and 664 of JS on every page
 * of the site against the possibility of a click — is the cost this split
 * exists to decline, and declining it in writing is cheaper than answering a
 * performance complaint later.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

defined( 'ABSPATH' ) || exit;

class LoginDialog {

	const HANDLE = 'smart-login-launcher';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_launcher' ) );
		add_filter( 'wp_robots', array( $this, 'no_index_dialog_urls' ) );

		// Late, so every form on the page has closed. See the template's header
		// for what happens when a form lands inside another one.
		add_action( 'wp_footer', array( $this, 'render_shell' ), 100 );
	}

	/**
	 * Whether the dialog is available on this request.
	 *
	 * No setting, by decision: the scope chosen for Phase 19 has no admin
	 * screen, and a checkbox can be added later by one `FieldRegistry` row. This
	 * filter is the off switch, and it is documented in README.md rather than
	 * left to be discovered.
	 */
	public static function enabled(): bool {
		/**
		 * Turn the site-wide sign-in dialog off.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'smart_login_popup_enabled', ! is_user_logged_in() );
	}

	/**
	 * Keep the dialog-open variant of a URL out of the index.
	 *
	 * This is the cost of the canonical trigger being a query parameter, and the
	 * sub-phase that chose it owns the cost rather than leaving it to be found in
	 * Search Console: `?smart_login_step=identify` makes a second URL for the
	 * same content. The page's own canonical tag is untouched, so the two are
	 * already declared to be one page; this stops the variant being crawled at
	 * all.
	 *
	 * A fragment would not have this problem, which is the one thing it is
	 * better at — and not enough to outweigh being invisible to the server.
	 *
	 * @param array $robots
	 * @return array
	 */
	public function no_index_dialog_urls( $robots ): array {
		$robots = (array) $robots;

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only presentation switch.
		if ( ! empty( $_GET['smart_login_step'] ) ) {
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/**
	 * Enqueue the launcher, unconditionally.
	 *
	 * Deliberately not `Assets::maybe_enqueue()`'s test. That one requires
	 * `is_singular()` *and* a shortcode in `post_content`, which means shop
	 * archives, category listings and search results — three of the places a
	 * "đăng ký nhanh" button belongs — load nothing at all. A dialog that works
	 * on every page cannot be gated on the page containing a form.
	 */
	public function enqueue_launcher(): void {
		if ( ! self::enabled() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			SMART_LOGIN_URL . 'assets/js/smart-login-launcher.js',
			array(),
			SMART_LOGIN_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'SmartLoginDialog', self::contract() );
	}

	/**
	 * Everything the launcher may not decide for itself.
	 *
	 * The step allowlist is localized rather than restated in JavaScript. It
	 * already exists — `Flow::PUBLIC_STEPS`, allowlisted since the step could
	 * first be asked for by URL — and a second copy in a script is how the two
	 * drift. That is rule 9, and the defect it forbids was in the first draft of
	 * this phase's plan.
	 */
	public static function contract(): array {
		return array(
			'endpoint'  => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/step' ) ),
			// The canonical trigger. `#login` is an alias the launcher resolves
			// to this, because a fragment is never sent to the server and so can
			// never be acted on before first paint.
			'param'     => 'smart_login_step',
			'steps'     => Flow::public_steps(),
			'aliases'   => self::aliases(),
			'loginUrl'  => Flow::login_url(),
			'assets'    => array(
				'css' => SMART_LOGIN_URL . 'assets/css/smart-login.css',
				'js'  => SMART_LOGIN_URL . 'assets/js/smart-login.js',
			),
			'dialogCss' => SMART_LOGIN_URL . 'assets/css/smart-login-dialog.css',
			'dialogJs'  => SMART_LOGIN_URL . 'assets/js/smart-login-dialog.js',
			// Finding 6: the fill-time floor was written for a form that loads
			// with the page. The dialog keeps submit disabled until the fragment
			// it is showing is old enough, so a visitor whose password manager
			// fills instantly never meets "Bạn thao tác quá nhanh".
			'minFill'   => \SmartLogin\Security\RequestGuard::MIN_FILL_SECONDS,
			'i18n'      => array(
				'failed' => __( 'Không tải được biểu mẫu. Vui lòng thử lại.', 'smart-login' ),
			),
		);
	}

	/**
	 * Short spellings of a step, for links written by hand.
	 *
	 * A map rather than a chain of special cases, and every value has to be a
	 * step `Flow` already allows — an alias that resolved to something else
	 * would be a second allowlist wearing a shorter name.
	 *
	 * @return array<string,string>
	 */
	public static function aliases(): array {
		$map = array(
			'login'      => Flow::STEP_IDENTIFY,
			'dang-nhap'  => Flow::STEP_IDENTIFY,
			'register'   => Flow::STEP_REGISTER,
			'dang-ky'    => Flow::STEP_REGISTER,
			'forgot'     => Flow::STEP_FORGOT,
			'quen-mat-khau' => Flow::STEP_FORGOT,
		);

		/**
		 * Add or remove a URL-fragment spelling of a step.
		 *
		 * @param array<string,string> $map
		 */
		$map = (array) apply_filters( 'smart_login_dialog_aliases', $map );

		return array_filter(
			$map,
			static function ( $step ): bool {
				return in_array( (string) $step, Flow::public_steps(), true );
			}
		);
	}

	/**
	 * Print the shell.
	 *
	 * It carries no state, so it is safe on a cached page — which is the whole
	 * argument for fetching the form rather than embedding it.
	 */
	public function render_shell(): void {
		if ( ! self::enabled() ) {
			return;
		}

		TemplateLoader::output(
			'login-dialog',
			array( 'title' => FragmentRenderer::title( Flow::STEP_IDENTIFY ) )
		);
	}
}
