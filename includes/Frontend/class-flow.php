<?php
/**
 * Which step of the auth flow the current request should render.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

defined( 'ABSPATH' ) || exit;

class Flow {

	/** One field: the phone number or email. Both signing in and signing up start here. */
	const STEP_IDENTIFY = 'identify';

	/** The identifier is already registered — ask for the password. */
	const STEP_PASSWORD = 'password';

	/** OTP proved a new identifier; collect the name and password before creating the account. */
	const STEP_SIGNUP = 'signup';

	/** Post-registration: ask for what the profile is still missing, with a way out. */
	const STEP_ONBOARD = 'onboard';

	const STEP_OTP    = 'otp';
	const STEP_FORGOT = 'forgot';
	const STEP_RESET  = 'reset';
	const STEP_DONE   = 'done';

	/**
	 * Retained so existing links, shortcodes and theme overrides keep working.
	 * Identifier-first has no separate login and register screens, so both
	 * resolve to STEP_IDENTIFY.
	 */
	const STEP_LOGIN    = 'login';
	const STEP_REGISTER = 'register';

	/**
	 * Steps a visitor may ask for by URL.
	 *
	 * STEP_PASSWORD, STEP_SIGNUP and STEP_ONBOARD are deliberately absent: each
	 * one only means something in the presence of server-side state (a resolved
	 * identifier, a signup grant, a signed-in user). Reaching them by typing a
	 * query string would render a form with nothing behind it.
	 */
	private const PUBLIC_STEPS = array(
		self::STEP_IDENTIFY,
		self::STEP_LOGIN,
		self::STEP_REGISTER,
		self::STEP_OTP,
		self::STEP_FORGOT,
		self::STEP_RESET,
	);

	/** @var string|null */
	private static $step = null;

	/** @var array<string,mixed> */
	private static $data = array();

	/** @var array<string,string> Values to re-populate a rejected form with. */
	private static $old = array();

	public static function set( string $step, array $data = array() ): void {
		self::$step = self::canonical( $step );
		self::$data = $data;
	}

	/**
	 * @param string $fallback Step to use when nothing has been set.
	 */
	public static function step( string $fallback = self::STEP_IDENTIFY ): string {
		if ( null !== self::$step ) {
			return self::$step;
		}

		$requested = isset( $_GET['smart_login_step'] ) ? sanitize_key( wp_unslash( $_GET['smart_login_step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		return in_array( $requested, self::PUBLIC_STEPS, true ) ? self::canonical( $requested ) : self::canonical( $fallback );
	}

	/**
	 * Collapse the legacy login/register steps onto the single entry screen.
	 */
	public static function canonical( string $step ): string {
		return in_array( $step, array( self::STEP_LOGIN, self::STEP_REGISTER ), true )
			? self::STEP_IDENTIFY
			: $step;
	}

	/**
	 * @return mixed
	 */
	public static function data( string $key, $fallback = null ) {
		return self::$data[ $key ] ?? $fallback;
	}

	public static function all_data(): array {
		return self::$data;
	}

	/**
	 * Remember submitted values so a failed form does not lose the user's typing.
	 * Passwords are deliberately never retained.
	 */
	public static function remember( array $values ): void {
		unset( $values['password'], $values['password_confirm'], $values['pass'] );

		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				self::$old[ $key ] = (string) $value;
			}
		}
	}

	public static function old( string $key, string $fallback = '' ): string {
		return self::$old[ $key ] ?? $fallback;
	}

	/**
	 * Link to another step of the flow on the current page.
	 */
	public static function url( string $step ): string {
		$base = remove_query_arg( array( 'smart_login_step', 'smartlogin_welcome' ) );
		$url  = add_query_arg( 'smart_login_step', $step, $base );

		/**
		 * @param string $url
		 * @param string $step
		 */
		return (string) apply_filters( 'smart_login_step_url', $url, $step );
	}

	/**
	 * The plugin's own sign-in screen, from anywhere on the site, or ''.
	 *
	 * `url()` above appends a step to the *current* URL, which is the right
	 * answer inside the flow and useless outside it: the account page renders
	 * `[smart_account]`, so `url( STEP_FORGOT )` there points the visitor back at
	 * their own profile with a query string it does not read.
	 *
	 * That is the deferral `templates/partials/account/password.php` has carried
	 * since 14.3 — the recovery route was a sentence rather than a link because
	 * there was no third answer. `wp_lostpassword_url()` is not one: it lands on
	 * `wp-login.php`, which is exactly the leak
	 * `tests/identity/run-account-surface-tests.php` forbids elsewhere.
	 *
	 * Resolution order matches `AccountForm::edit_url()`: an explicit filter, then
	 * the page hosting the shortcode. No new setting — a site that has put the
	 * shortcode on a page has already answered the question, and one that has not
	 * gets `''` and the sentence it had before.
	 */
	public static function login_url(): string {
		/**
		 * Point the plugin's own "go and sign in" links at a specific page.
		 *
		 * @param string $url
		 */
		$filtered = (string) apply_filters( 'smart_login_login_url', '' );

		if ( '' !== $filtered ) {
			return $filtered;
		}

		return SitePage::url( array( 'smart_login', 'smart_auth' ), 'smart_login_login_page' );
	}
}
