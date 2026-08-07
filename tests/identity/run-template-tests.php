<?php
/**
 * Render every template and fail on anything that throws.
 *
 * This exists because of a real fatal that four other gates all missed. Phase 3
 * deleted Identity\IdentityResolver and removed its callers from includes/ — but
 * not from templates/, which kept calling IdentityResolver::identifier_label().
 * The WooCommerce My Account page fatalled on every load, for four phases.
 *
 * Why nothing caught it:
 *
 *   php -l          checks syntax; PHP resolves class names at RUN time
 *   contract tests  asserted the class was gone, never that nobody referenced it
 *   regression      inspects template source as a string, never executes it
 *   integration     exercises REST and provisioning, renders no template
 *
 * The fitness suite now resolves every SmartLogin class reference to a file,
 * which catches a deleted class. It cannot catch a deleted *method* on a class
 * that still exists — same failure mode, same fatal. Actually running the
 * template is the only thing that catches both.
 *
 * Run with:  php tests/identity/run-template-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Settings;

/*
 * Credentials as deployment constants rather than stored secrets: the entry
 * screen only draws a provider button for a provider that is *configured*, and
 * ProviderCredentials::is_configured() reads the constants before it reaches
 * SecretBox. Going through SecretBox here would make this suite depend on
 * openssl, which is exactly the dependency the mail work has already been bitten
 * by. The values are never dialled — nothing in this suite completes an OAuth
 * round trip.
 */
define( 'SMART_LOGIN_GOOGLE_CLIENT_ID', 'google-client-for-render' );
define( 'SMART_LOGIN_GOOGLE_CLIENT_SECRET', 'google-secret-for-render' );
define( 'SMART_LOGIN_ZALO_APP_ID', 'zalo-app-for-render' );
define( 'SMART_LOGIN_ZALO_APP_SECRET', 'zalo-secret-for-render' );

Settings::update(
	array(
		'identity.mode'              => 'both',
		'providers.google.enabled'   => 1,
		'providers.zalo.enabled'     => 0,
		'address.enabled'            => 1,
		'otp.length'                 => 6,
		'signup.min_password_length' => 8,
	)
);

/**
 * Variables each template documents in its @var block, with plausible values.
 *
 * Where a template reads something not listed here it will emit a PHP warning,
 * which this runner turns into a failure — an undeclared variable in a template
 * is a bug whether or not it happens to render.
 */
// The WooCommerce account template resolves its own account rather than taking
// one as an argument, because Woo calls it with no arguments at all.
$GLOBALS['sl_logged_in']      = true;
$GLOBALS['sl_current_user_id'] = 7;

$fixtures = require __DIR__ . '/../template-fixtures.php';

// ---------------------------------------------------------------------
sl_section( 'Every template renders without throwing' );

$root = dirname( __DIR__, 2 ) . '/templates/';

foreach ( $fixtures as $template => $args ) {
	$file = $root . $template . '.php';

	if ( ! is_readable( $file ) ) {
		sl_assert( sprintf( '%s exists', $template ), false, 'Fixture names a template that is not there.' );
		continue;
	}

	$GLOBALS['sl_template_warnings'] = array();

	set_error_handler(
		static function ( int $severity, string $message ) {
			$GLOBALS['sl_template_warnings'][] = $message;
			return true;
		}
	);

	ob_start();

	try {
		( static function ( string $sl_file, array $sl_args ): void {
			extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $sl_file;
		} )( $file, $args );

		$html  = (string) ob_get_clean();
		$error = null;
	} catch ( Throwable $exception ) {
		ob_end_clean();
		$html  = '';
		$error = get_class( $exception ) . ': ' . $exception->getMessage();
	} finally {
		restore_error_handler();
	}

	$warnings = $GLOBALS['sl_template_warnings'];

	sl_assert(
		sprintf( '%s renders', $template ),
		null === $error,
		(string) $error
	);

	if ( null !== $error ) {
		continue;
	}

	sl_assert(
		sprintf( '%s emits no PHP notice', $template ),
		array() === $warnings,
		implode( ' | ', array_slice( $warnings, 0, 3 ) )
	);

	sl_assert(
		sprintf( '%s produces markup', $template ),
		'' !== trim( $html ),
		'Rendered empty, which usually means an early return on a missing variable.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'The ward select explains why it is inert (Phase 8.5)' );

/*
 * The fixture above renders it with a province already chosen, which is the
 * state where there is nothing to explain. This renders the other one — the
 * state a first-time visitor actually sees.
 */
$sl_render_address = static function ( array $wards ) use ( $fixtures, $root ): string {
	$args = $fixtures['partials/address-fields'];
	$args['wards'] = $wards;
	$args['values']['ward_code'] = $wards ? $args['values']['ward_code'] : '';

	ob_start();
	( static function ( string $sl_file, array $sl_args ): void {
		extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $sl_file;
	} )( $root . 'partials/address-fields.php', $args );

	return (string) ob_get_clean();
};

$sl_waiting = $sl_render_address( array() );
$sl_ready   = $sl_render_address( $fixtures['partials/address-fields']['wards'] );

sl_assert(
	'a ward select with nothing to offer says so',
	false !== strpos( $sl_waiting, 'Chọn Tỉnh/Thành phố trước' ),
	'A grey box that never says why reads as broken rather than as waiting.'
);

sl_assert(
	'the explanation is attached to the control, not just near it',
	(bool) preg_match( '/aria-describedby="[^"]*-ward-hint"/', $sl_waiting ),
	'Screen readers get the reason only if the select points at it.'
);

sl_assert(
	'the explanation is absent once wards are available',
	false === strpos( $sl_ready, 'Chọn Tỉnh/Thành phố trước' ),
	'An instruction that no longer applies is worse than none.'
);

// ---------------------------------------------------------------------
sl_section( 'The entry screen says less, and wears each brand as that brand ships it' );

/*
 * Two defects, one screen, both found by reading the rendered page rather than
 * the source:
 *
 *   1. The lead paragraph ran to two sentences, and the second one described the
 *      *server* ("Chúng tôi sẽ tự nhận ra bạn đã có tài khoản hay chưa") — a
 *      sentence the visitor cannot act on, sitting between the heading and the
 *      only input on the page. The heading already says "Đăng nhập hoặc đăng ký",
 *      which is the same promise in four words.
 *
 *   2. The provider buttons drew a letter in a circle — "G", "Z" — instead of the
 *      brands' own marks. For Google that is not a matter of taste: the Google
 *      Identity branding guidelines require the four-colour G, unrecoloured. And
 *      the two buttons were not even each other's equals, because only Zalo had
 *      a colour, so a row meant to read as two peers read as one recommendation.
 *
 * These render the block rather than grepping the template because the mark has
 * to survive being *composed* — it now comes from the provider object, and a
 * grep over form-auth.php would no longer see it at all.
 */
$sl_render_auth = static function () use ( $fixtures, $root ): string {
	ob_start();
	( static function ( string $sl_file, array $sl_args ): void {
		extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $sl_file;
	} )( $root . 'form-auth.php', $fixtures['form-auth'] );

	return (string) ob_get_clean();
};

// Zalo is off in the suite-wide settings so that the common render exercises the
// one-provider case. Both brands have to be on screen at once for the rules
// below, which are about the pair.
Settings::update( array( 'providers.zalo.enabled' => 1 ) );
$sl_auth = $sl_render_auth();
Settings::update( array( 'providers.zalo.enabled' => 0 ) );

preg_match( '/<p class="sl-lead">(.*?)<\/p>/s', $sl_auth, $sl_lead_match );
$sl_lead = trim( html_entity_decode( wp_strip_all_tags( $sl_lead_match[1] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
$sl_lead = trim( preg_replace( '/\s+/u', ' ', $sl_lead ) );

sl_assert(
	'the entry screen still introduces itself',
	'' !== $sl_lead,
	'Deleting the lead outright is not the fix being asked for; shortening it is.'
);

sl_assert(
	'the lead is one sentence',
	substr_count( $sl_lead, '.' ) <= 1,
	'Second sentence still there: ' . $sl_lead
);

sl_assert(
	'the lead is short enough to be read rather than skipped',
	mb_strlen( $sl_lead, 'UTF-8' ) <= 60,
	sprintf( '%d characters: %s', mb_strlen( $sl_lead, 'UTF-8' ), $sl_lead )
);

/*
 * The divider and the buttons were both saying "tiếp tục với": "Hoặc tiếp tục
 * nhanh với" / "Tiếp tục với Google". The button copy is the one that cannot
 * move — "Continue with Google" is one of the strings Google's guidelines
 * permit — so the divider is the one that gives way.
 */
preg_match( '/<div class="sl-divider"><span>(.*?)<\/span>/s', $sl_auth, $sl_divider_match );
$sl_divider = trim( html_entity_decode( $sl_divider_match[1] ?? '', ENT_QUOTES, 'UTF-8' ) );

sl_assert(
	'the divider does not repeat what every button under it already says',
	'' !== $sl_divider && false === mb_stripos( $sl_divider, 'tiếp tục' ),
	'Divider reads: ' . $sl_divider
);

// One anchor per provider, sliced out so a rule about Google cannot be satisfied
// by something Zalo happens to render.
preg_match_all( '/<a\b[^>]*data-sl-provider="([a-z]+)"[^>]*>(.*?)<\/a>/s', $sl_auth, $sl_buttons, PREG_SET_ORDER );

$sl_marks = array();

foreach ( $sl_buttons as $sl_button ) {
	$sl_marks[ $sl_button[1] ] = $sl_button[2];
}

sl_assert(
	'both provider buttons render',
	isset( $sl_marks['google'], $sl_marks['zalo'] ),
	'Rendered: ' . implode( ', ', array_keys( $sl_marks ) ) . ' — the rules below describe the pair.'
);

$sl_google = $sl_marks['google'] ?? '';
$sl_zalo   = $sl_marks['zalo'] ?? '';

foreach ( array( 'google' => $sl_google, 'zalo' => $sl_zalo ) as $sl_id => $sl_markup ) {
	sl_assert(
		sprintf( 'the %s button carries a real mark, not a letter in a circle', $sl_id ),
		false !== strpos( $sl_markup, '<svg' ),
		'A monogram the plugin drew itself is not the brand, and for Google it is a guideline violation.'
	);
}

sl_assert(
	'the Google mark keeps all four of its colours',
	4 === count(
		array_filter(
			array( '#4285F4', '#34A853', '#FBBC05', '#EA4335' ),
			static fn( string $hex ): bool => false !== stripos( $sl_google, $hex )
		)
	),
	'Google forbids recolouring the G, including flattening it to one colour.'
);

sl_assert(
	'the Zalo mark uses the blue Zalo actually ships',
	false !== stripos( $sl_zalo, '#0068FF' ),
	'#0b74e5 was this plugin\'s approximation of it, not Zalo\'s value.'
);

foreach ( array( 'google' => $sl_google, 'zalo' => $sl_zalo ) as $sl_id => $sl_markup ) {
	sl_assert(
		sprintf( 'the %s mark is not repainted by the button it sits in', $sl_id ),
		false === stripos( $sl_markup, 'currentColor' ),
		'currentColor makes the mark inherit the button text colour, which is the recolouring both brands prohibit.'
	);
}

/*
 * Where the mark lives matters as much as what it looks like. The template used
 * to hold `'google' === $sl_provider->id() ? 'G' : 'Z'`, which is a two-provider
 * assumption written into markup: a third provider gets the Zalo letter and
 * nobody finds out until it is on screen. The brand belongs to the provider
 * object, beside label() and name().
 */
$sl_auth_source = sl_source( 'templates/form-auth.php' );

sl_assert(
	'the template does not decide which brand it is drawing',
	false === strpos( $sl_auth_source, "'google' ===" ),
	'A per-provider branch in a foreach is the two-provider assumption that name() and label() already avoid.'
);

$sl_css = sl_source( 'assets/css/smart-login.css' );

// Scoped to the one rule block: other things on this stylesheet are round on
// purpose, and a stylesheet-wide ban on 50% would forbid the step markers and the
// success mark along with it.
preg_match( '/\.sl-provider-icon\s*\{(.*?)\}/s', $sl_css, $sl_icon_rule );

sl_assert(
	'the stylesheet no longer draws a circle around the mark',
	false === strpos( $sl_icon_rule[1] ?? '', 'border-radius: 50%' ),
	'The circle existed to contain a letter. A brand mark inside it is a logo in a badge nobody designed.'
);

sl_assert(
	'the stylesheet holds no invented Zalo blue',
	false === stripos( $sl_css, '#0b74e5' ) && false === stripos( $sl_css, '#075eb8' ),
	'Two shades, neither of them Zalo\'s.'
);

sl_assert(
	'the select is only disabled while it is empty',
	false !== strpos( $sl_waiting, 'disabled' ) && false === strpos( $sl_ready, 'disabled' )
);

// ---------------------------------------------------------------------
sl_section( 'Every template on disk is either rendered here or excluded in writing' );

/*
 * Without this, adding a template adds no coverage and nobody notices. That is
 * how a deleted class survived in two templates for four phases.
 *
 * Phase 8.2 extracts six section partials under partials/account/. Each one
 * fails this check the moment it lands and passes once it has a fixture above,
 * which is the mechanism that makes "extend the smoke test" automatic rather
 * than remembered.
 */
$sl_uncovered_ok = array(
	// Documented shims. README points theme authors at form-auth.php; these two
	// are never loaded, which the assertions below this section keep true.

	// form-edit-account was here until 8.2 and now has a fixture instead.
	'woocommerce/form-login'     => 'needs a WooCommerce runtime',

	// Not a page: it is the wrapper for an HTML email, and it is rendered — by
	// tests/mail/run-template-tests.php, which drives it through MailTransport
	// and asserts the marker, the accent, the footer and the code all arrive.
	// Excluded from this suite because a fixture here would render it into a
	// browser context it never sees, not because nothing renders it.
	'mail/layout'                => 'covered by the mail suite, not a page template',
);

$sl_on_disk = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $sl_file ) {
	if ( ! $sl_file->isFile() || 'php' !== strtolower( $sl_file->getExtension() ) ) {
		continue;
	}

	$sl_relative  = str_replace( '\\', '/', substr( $sl_file->getPathname(), strlen( $root ) ) );
	$sl_on_disk[] = substr( $sl_relative, 0, -4 );
}

sort( $sl_on_disk );

$sl_missing_fixture = array_diff( $sl_on_disk, array_keys( $fixtures ), array_keys( $sl_uncovered_ok ) );

sl_assert(
	'every template has a fixture or a written-down exclusion',
	array() === $sl_missing_fixture,
	'No fixture, so it is never executed: ' . implode( ', ', $sl_missing_fixture )
);

// The exclusion list must not outlive what it excludes.
$sl_stale = array_diff( array_keys( $sl_uncovered_ok ), $sl_on_disk );

sl_assert(
	'the exclusion list names no template that is gone',
	array() === $sl_stale,
	'Stale entries hide the next real gap: ' . implode( ', ', $sl_stale )
);

// ---------------------------------------------------------------------
sl_section( 'The password step has a way forward without a password (14.3)' );

/*
 * An account provisioned by a provider holds a random password nobody has seen, so
 * the box on this screen cannot be filled. The route out must not ask the visitor to
 * retype the identifier they just typed, and it must not be a second entry point to
 * an OTP send — it posts the existing `forgot` action, which 9.4 metered.
 */
$sl_pw = sl_capture(
	static function (): void {
		( static function ( string $sl_file, array $sl_args ): void {
			extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $sl_file;
		} )(
			dirname( __DIR__, 2 ) . '/templates/form-password.php',
			array( 'notices' => array(), 'identity' => '0969789475' )
		);
	}
);

sl_assert( 'the password step still renders', null === $sl_pw['error'], (string) $sl_pw['error'] );

preg_match_all( '/<form\b[^>]*>(.*?)<\/form>/s', $sl_pw['html'], $sl_forms );

$sl_recover = '';

foreach ( $sl_forms[1] ?? array() as $sl_form_body ) {
	if ( false !== strpos( $sl_form_body, 'value="forgot"' ) ) {
		$sl_recover = $sl_form_body;
	}
}

sl_assert(
	'a second form posts the recovery action',
	'' !== $sl_recover,
	'Without it the only route out of an unfillable password box is retyping the identifier.'
);

sl_assert(
	'and it carries the identifier already held, so nothing is retyped',
	false !== strpos( $sl_recover, 'name="identity" value="0969789475"' ),
	'An empty identity here would post a blank recovery and refuse.'
);

// A form without its own guard fields is refused by RequestGuard::verify(), so the
// button would look like a route and be a dead end.
sl_assert(
	'and it carries its own guard fields',
	false !== strpos( $sl_recover, 'name="_wpnonce"' ) || false !== strpos( $sl_recover, 'nonce' ),
	'RequestGuard::verify( \'forgot\' ) rejects a post without them.'
);

sl_assert(
	'the primary form still posts the login action',
	false !== strpos( $sl_pw['html'], 'value="login"' ),
	'The recovery route must be an addition, not a replacement of signing in.'
);

// ---------------------------------------------------------------------
sl_section( 'The security section asks the directory, not user_email (14.6)' );

/*
 * An account with no email or phone identity cannot sign in by anything it could
 * type, so a password is not the missing piece and the three boxes cannot be filled:
 * password_current is a 64-character random string its owner has never seen.
 *
 * The fixture user's address is `user@example.test` — real, not synthetic. That is
 * deliberately the Google-first shape, and it is the case a predicate built on
 * `is_synthetic_email()` gets wrong: it would answer "has a contact" and render the
 * unfillable form. The two renders below differ only in what the directory returns.
 */
$sl_render_password = static function ( array $rows ): array {
	$GLOBALS['sl_wpdb_results'] = $rows;
	$GLOBALS['sl_wpdb_row']     = null;
	$GLOBALS['sl_wpdb_var']     = 0;

	$sl_out = sl_capture(
		static function (): void {
			( static function ( string $sl_file, array $sl_args ): void {
				extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
				include $sl_file;
			} )(
				dirname( __DIR__, 2 ) . '/templates/partials/account/password.php',
				( new \SmartLogin\Frontend\AccountForm( 7, \SmartLogin\Frontend\AccountForm::CONTEXT_STANDALONE ) )->args_for( 'password' )
			);
		}
	);

	$GLOBALS['sl_wpdb_results'] = array();

	return $sl_out;
};

$sl_no_contact = $sl_render_password( array() );

sl_assert(
	'the security section renders with no contact identity',
	null === $sl_no_contact['error'],
	(string) $sl_no_contact['error']
);

sl_assert(
	'a provider-only account is offered no current-password box',
	false === strpos( $sl_no_contact['html'], 'name="password_current"' ),
	'wp_users.user_email is real here and there is still no email identity — the Google-first shape. The box cannot be filled, and save_password() refuses without it.'
);

sl_assert(
	'and it is pointed at the contact section instead',
	false !== strpos( $sl_no_contact['html'], 'sl-section-contact' ),
	'An identifier is the missing piece, not a password. Offering step two first is what the current screen does wrong.'
);

$sl_with_contact = $sl_render_password(
	array(
		array(
			'id'          => 5,
			'user_id'     => 7,
			'channel'     => 'email',
			'subject'     => 'user@example.test',
			'is_primary'  => 1,
			'verified_at' => '2026-01-01 00:00:00',
			'linked_by'   => 'otp',
			'meta_json'   => '',
			'created_at'  => '2026-01-01 00:00:00',
		),
	)
);

sl_assert(
	'an account with an email identity still gets all three boxes',
	false !== strpos( $sl_with_contact['html'], 'name="password_current"' )
		&& false !== strpos( $sl_with_contact['html'], 'name="password_1"' )
		&& false !== strpos( $sl_with_contact['html'], 'name="password_2"' ),
	'The branch must not take away password changing from accounts that can use it.'
);

// The refusal that stays: this sub-phase stops offering a form it cannot serve, and
// deliberately does not weaken re-authentication for the case it now hides.
sl_assert(
	'save_password() still requires the current password',
	false !== strpos(
		sl_source( 'includes/Frontend/class-form-controller.php' ),
		'wp_check_password( (string) ( $post[\'password_current\'] ?? \'\' )'
	),
	'On an account with a verified email, planting a password without re-auth creates a login route that did not exist.'
);

// ---------------------------------------------------------------------
sl_section( 'The entry point is the only entry point' );

// The two shims form-login.php and form-register.php were deleted in 15.3. They were
// never loaded, and README told theme authors to override form-auth.php instead — so
// anybody who overrode a shim got no effect and no explanation. What is left to assert
// is the half that still matters: the flow renders form-auth and nothing else.
$shortcodes = sl_source( 'includes/Frontend/class-shortcodes.php' );

sl_assert(
	'the login/register flow renders form-auth',
	false !== strpos( $shortcodes, "'form-auth'" )
);

sl_assert(
	'no deleted shim is referenced anywhere',
	false === strpos( $shortcodes, "'form-login'" )
		&& false === strpos( $shortcodes, "'form-register'" ),
	'Deleting a template that something still names is a fatal on the page that names it.'
);

// ---------------------------------------------------------------------
sl_summary( 'Templates' );
