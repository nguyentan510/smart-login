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
 * The fitness suite now resolves every OmniWP class reference to a file,
 * which catches a deleted class. It cannot catch a deleted *method* on a class
 * that still exists — same failure mode, same fatal. Actually running the
 * template is the only thing that catches both.
 *
 * Run with:  php tests/identity/run-template-tests.php
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Settings;

/*
 * Credentials as deployment constants rather than stored secrets: the entry
 * screen only draws a provider button for a provider that is *configured*, and
 * ProviderCredentials::is_configured() reads the constants before it reaches
 * SecretBox. Going through SecretBox here would make this suite depend on
 * openssl, which is exactly the dependency the mail work has already been bitten
 * by. The values are never dialled — nothing in this suite completes an OAuth
 * round trip.
 */
define( 'OMNIWP_GOOGLE_CLIENT_ID', 'google-client-for-render' );
define( 'OMNIWP_GOOGLE_CLIENT_SECRET', 'google-secret-for-render' );

Settings::update(
	array(
		'identity.mode'              => 'both',
		'providers.google.enabled'   => 1,
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
$GLOBALS['ow_logged_in']      = true;
$GLOBALS['ow_current_user_id'] = 7;

$fixtures = require __DIR__ . '/../template-fixtures.php';

// ---------------------------------------------------------------------
ow_section( 'Every template renders without throwing' );

$root = dirname( __DIR__, 2 ) . '/templates/';

foreach ( $fixtures as $template => $args ) {
	$file = $root . $template . '.php';

	if ( ! is_readable( $file ) ) {
		ow_assert( sprintf( '%s exists', $template ), false, 'Fixture names a template that is not there.' );
		continue;
	}

	$GLOBALS['ow_template_warnings'] = array();

	set_error_handler(
		static function ( int $severity, string $message ) {
			$GLOBALS['ow_template_warnings'][] = $message;
			return true;
		}
	);

	ob_start();

	try {
		( static function ( string $ow_file, array $ow_args ): void {
			extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $ow_file;
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

	$warnings = $GLOBALS['ow_template_warnings'];

	ow_assert(
		sprintf( '%s renders', $template ),
		null === $error,
		(string) $error
	);

	if ( null !== $error ) {
		continue;
	}

	ow_assert(
		sprintf( '%s emits no PHP notice', $template ),
		array() === $warnings,
		implode( ' | ', array_slice( $warnings, 0, 3 ) )
	);

	ow_assert(
		sprintf( '%s produces markup', $template ),
		'' !== trim( $html ),
		'Rendered empty, which usually means an early return on a missing variable.'
	);
}

// ---------------------------------------------------------------------
ow_section( 'The ward select explains why it is inert (Phase 8.5)' );

/*
 * The fixture above renders it with a province already chosen, which is the
 * state where there is nothing to explain. This renders the other one — the
 * state a first-time visitor actually sees.
 */
$ow_render_address = static function ( array $wards ) use ( $fixtures, $root ): string {
	$args = $fixtures['partials/address-fields'];
	$args['wards'] = $wards;
	$args['values']['ward_code'] = $wards ? $args['values']['ward_code'] : '';

	ob_start();
	( static function ( string $ow_file, array $ow_args ): void {
		extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $ow_file;
	} )( $root . 'partials/address-fields.php', $args );

	return (string) ob_get_clean();
};

$ow_waiting = $ow_render_address( array() );
$ow_ready   = $ow_render_address( $fixtures['partials/address-fields']['wards'] );

ow_assert(
	'a ward select with nothing to offer says so',
	false !== strpos( $ow_waiting, 'Chọn Tỉnh/Thành phố trước' ),
	'A grey box that never says why reads as broken rather than as waiting.'
);

ow_assert(
	'the explanation is attached to the control, not just near it',
	(bool) preg_match( '/aria-describedby="[^"]*-ward-hint"/', $ow_waiting ),
	'Screen readers get the reason only if the select points at it.'
);

ow_assert(
	'the explanation is absent once wards are available',
	false === strpos( $ow_ready, 'Chọn Tỉnh/Thành phố trước' ),
	'An instruction that no longer applies is worse than none.'
);

// ---------------------------------------------------------------------
ow_section( 'The entry screen says less, and wears each brand as that brand ships it' );

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
 *      Identity branding guidelines require the four-colour G, unrecoloured.
 *
 * These render the block rather than grepping the template because the mark has
 * to survive being *composed* — it now comes from the provider object, and a
 * grep over form-auth.php would no longer see it at all.
 */
$ow_render_auth = static function () use ( $fixtures, $root ): string {
	ob_start();
	( static function ( string $ow_file, array $ow_args ): void {
		extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $ow_file;
	} )( $root . 'form-auth.php', $fixtures['form-auth'] );

	return (string) ob_get_clean();
};

$ow_auth = $ow_render_auth();

preg_match( '/<p class="sl-lead">(.*?)<\/p>/s', $ow_auth, $ow_lead_match );
$ow_lead = trim( html_entity_decode( wp_strip_all_tags( $ow_lead_match[1] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
$ow_lead = trim( preg_replace( '/\s+/u', ' ', $ow_lead ) );

ow_assert(
	'the entry screen still introduces itself',
	'' !== $ow_lead,
	'Deleting the lead outright is not the fix being asked for; shortening it is.'
);

ow_assert(
	'the lead is one sentence',
	substr_count( $ow_lead, '.' ) <= 1,
	'Second sentence still there: ' . $ow_lead
);

ow_assert(
	'the lead is short enough to be read rather than skipped',
	mb_strlen( $ow_lead, 'UTF-8' ) <= 60,
	sprintf( '%d characters: %s', mb_strlen( $ow_lead, 'UTF-8' ), $ow_lead )
);

/*
 * The divider and the buttons were both saying "tiếp tục với": "Hoặc tiếp tục
 * nhanh với" / "Tiếp tục với Google". The button copy is the one that cannot
 * move — "Continue with Google" is one of the strings Google's guidelines
 * permit — so the divider is the one that gives way.
 */
preg_match( '/<div class="sl-divider"><span>(.*?)<\/span>/s', $ow_auth, $ow_divider_match );
$ow_divider = trim( html_entity_decode( $ow_divider_match[1] ?? '', ENT_QUOTES, 'UTF-8' ) );

ow_assert(
	'the divider does not repeat what every button under it already says',
	'' !== $ow_divider && false === mb_stripos( $ow_divider, 'tiếp tục' ),
	'Divider reads: ' . $ow_divider
);

// One anchor per provider, sliced out so a rule about one brand cannot be
// satisfied by something another provider happens to render.
preg_match_all( '/<a\b[^>]*data-sl-provider="([a-z]+)"[^>]*>(.*?)<\/a>/s', $ow_auth, $ow_buttons, PREG_SET_ORDER );

$ow_marks = array();

foreach ( $ow_buttons as $ow_button ) {
	$ow_marks[ $ow_button[1] ] = $ow_button[2];
}

ow_assert(
	'every shipped provider button renders',
	isset( $ow_marks['google'] ),
	'Rendered: ' . implode( ', ', array_keys( $ow_marks ) ) . ' — the rules below describe what is on screen.'
);

$ow_google = $ow_marks['google'] ?? '';

foreach ( array( 'google' => $ow_google ) as $ow_id => $ow_markup ) {
	ow_assert(
		sprintf( 'the %s button carries a real mark, not a letter in a circle', $ow_id ),
		false !== strpos( $ow_markup, '<svg' ),
		'A monogram the plugin drew itself is not the brand, and for Google it is a guideline violation.'
	);
}

ow_assert(
	'the Google mark keeps all four of its colours',
	4 === count(
		array_filter(
			array( '#4285F4', '#34A853', '#FBBC05', '#EA4335' ),
			static fn( string $hex ): bool => false !== stripos( $ow_google, $hex )
		)
	),
	'Google forbids recolouring the G, including flattening it to one colour.'
);

foreach ( array( 'google' => $ow_google ) as $ow_id => $ow_markup ) {
	ow_assert(
		sprintf( 'the %s mark is not repainted by the button it sits in', $ow_id ),
		false === stripos( $ow_markup, 'currentColor' ),
		'currentColor makes the mark inherit the button text colour, which is the recolouring every brand prohibits.'
	);
}

/*
 * Where the mark lives matters as much as what it looks like. The template used
 * to hold `'google' === $ow_provider->id() ? 'G' : 'Z'`, which is a two-provider
 * assumption written into markup: the next provider inherits the other one's
 * letter and nobody finds out until it is on screen. The brand belongs to the
 * provider object, beside label() and name().
 */
$ow_auth_source = ow_source( 'templates/form-auth.php' );

ow_assert(
	'the template does not decide which brand it is drawing',
	false === strpos( $ow_auth_source, "'google' ===" ),
	'A per-provider branch in a foreach is the two-provider assumption that name() and label() already avoid.'
);

$ow_css = ow_source( 'assets/css/omniwp.css' );

// Scoped to the one rule block: other things on this stylesheet are round on
// purpose, and a stylesheet-wide ban on 50% would forbid the step markers and the
// success mark along with it.
preg_match( '/\.sl-provider-icon\s*\{(.*?)\}/s', $ow_css, $ow_icon_rule );

ow_assert(
	'the stylesheet no longer draws a circle around the mark',
	false === strpos( $ow_icon_rule[1] ?? '', 'border-radius: 50%' ),
	'The circle existed to contain a letter. A brand mark inside it is a logo in a badge nobody designed.'
);

ow_assert(
	'the select is only disabled while it is empty',
	false !== strpos( $ow_waiting, 'disabled' ) && false === strpos( $ow_ready, 'disabled' )
);

// ---------------------------------------------------------------------
ow_section( 'Every template on disk is either rendered here or excluded in writing' );

/*
 * Without this, adding a template adds no coverage and nobody notices. That is
 * how a deleted class survived in two templates for four phases.
 *
 * Phase 8.2 extracts six section partials under partials/account/. Each one
 * fails this check the moment it lands and passes once it has a fixture above,
 * which is the mechanism that makes "extend the smoke test" automatic rather
 * than remembered.
 */
$ow_uncovered_ok = array(
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
	'account-hub'                => 'account hub container',
	'account-hub/address-modal'  => 'account hub modal',
	'account-hub/logout-modal'   => 'account hub modal',
	'account-hub/order-modal'    => 'account hub modal',
	'account-hub/settings-sheet' => 'account hub modal',
	'account-hub/sidebar'        => 'account hub partial',
	'account-hub/tab-address'    => 'account hub tab',
	'account-hub/tab-contact'    => 'account hub tab',
	'account-hub/tab-orders'     => 'account hub tab',
	'account-hub/tab-profile'    => 'account hub tab',
	'account-hub/tab-security'   => 'account hub tab',
	'account-hub/tab-vouchers'   => 'account hub tab',
	'account-hub/voucher-modal'  => 'account hub modal',
	'ecommerce/review-order'     => 'ecommerce product review table',
);

$ow_on_disk = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $ow_file ) {
	if ( ! $ow_file->isFile() || 'php' !== strtolower( $ow_file->getExtension() ) ) {
		continue;
	}

	$ow_relative  = str_replace( '\\', '/', substr( $ow_file->getPathname(), strlen( $root ) ) );
	$ow_on_disk[] = substr( $ow_relative, 0, -4 );
}

sort( $ow_on_disk );

$ow_missing_fixture = array_diff( $ow_on_disk, array_keys( $fixtures ), array_keys( $ow_uncovered_ok ) );

ow_assert(
	'every template has a fixture or a written-down exclusion',
	array() === $ow_missing_fixture,
	'No fixture, so it is never executed: ' . implode( ', ', $ow_missing_fixture )
);

// The exclusion list must not outlive what it excludes.
$ow_stale = array_diff( array_keys( $ow_uncovered_ok ), $ow_on_disk );

ow_assert(
	'the exclusion list names no template that is gone',
	array() === $ow_stale,
	'Stale entries hide the next real gap: ' . implode( ', ', $ow_stale )
);

// ---------------------------------------------------------------------
ow_section( 'The password step has a way forward without a password (14.3)' );

/*
 * An account provisioned by a provider holds a random password nobody has seen, so
 * the box on this screen cannot be filled. The route out must not ask the visitor to
 * retype the identifier they just typed, and it must not be a second entry point to
 * an OTP send — it posts the existing `forgot` action, which 9.4 metered.
 */
$ow_pw = ow_capture(
	static function (): void {
		( static function ( string $ow_file, array $ow_args ): void {
			extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $ow_file;
		} )(
			dirname( __DIR__, 2 ) . '/templates/form-password.php',
			array( 'notices' => array(), 'identity' => '0969789475' )
		);
	}
);

ow_assert( 'the password step still renders', null === $ow_pw['error'], (string) $ow_pw['error'] );

preg_match_all( '/<form\b[^>]*>(.*?)<\/form>/s', $ow_pw['html'], $ow_forms );

$ow_recover = '';

foreach ( $ow_forms[1] ?? array() as $ow_form_body ) {
	if ( false !== strpos( $ow_form_body, 'value="forgot"' ) ) {
		$ow_recover = $ow_form_body;
	}
}

ow_assert(
	'a second form posts the recovery action',
	'' !== $ow_recover,
	'Without it the only route out of an unfillable password box is retyping the identifier.'
);

ow_assert(
	'and it carries the identifier already held, so nothing is retyped',
	false !== strpos( $ow_recover, 'name="identity" value="0969789475"' ),
	'An empty identity here would post a blank recovery and refuse.'
);

// A form without its own guard fields is refused by RequestGuard::verify(), so the
// button would look like a route and be a dead end.
ow_assert(
	'and it carries its own guard fields',
	false !== strpos( $ow_recover, 'name="_wpnonce"' ) || false !== strpos( $ow_recover, 'nonce' ),
	'RequestGuard::verify( \'forgot\' ) rejects a post without them.'
);

ow_assert(
	'the primary form still posts the login action',
	false !== strpos( $ow_pw['html'], 'value="login"' ),
	'The recovery route must be an addition, not a replacement of signing in.'
);

// ---------------------------------------------------------------------
ow_section( 'The security section asks the directory, not user_email (14.6)' );

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
$ow_render_password = static function ( array $rows ): array {
	$GLOBALS['ow_wpdb_results'] = $rows;
	$GLOBALS['ow_wpdb_row']     = null;
	$GLOBALS['ow_wpdb_var']     = 0;

	$ow_out = ow_capture(
		static function (): void {
			( static function ( string $ow_file, array $ow_args ): void {
				extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
				include $ow_file;
			} )(
				dirname( __DIR__, 2 ) . '/templates/partials/account/password.php',
				( new \OmniWP\Frontend\AccountForm( 7, \OmniWP\Frontend\AccountForm::CONTEXT_STANDALONE ) )->args_for( 'password' )
			);
		}
	);

	$GLOBALS['ow_wpdb_results'] = array();

	return $ow_out;
};

$ow_no_contact = $ow_render_password( array() );

ow_assert(
	'the security section renders with no contact identity',
	null === $ow_no_contact['error'],
	(string) $ow_no_contact['error']
);

ow_assert(
	'a provider-only account is offered no current-password box',
	false === strpos( $ow_no_contact['html'], 'name="password_current"' ),
	'wp_users.user_email is real here and there is still no email identity — the Google-first shape. The box cannot be filled, and save_password() refuses without it.'
);

ow_assert(
	'and it is pointed at the contact section instead',
	false !== strpos( $ow_no_contact['html'], 'sl-section-contact' ),
	'An identifier is the missing piece, not a password. Offering step two first is what the current screen does wrong.'
);

$ow_with_contact = $ow_render_password(
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

ow_assert(
	'an account with an email identity still gets all three boxes',
	false !== strpos( $ow_with_contact['html'], 'name="password_current"' )
		&& false !== strpos( $ow_with_contact['html'], 'name="password_1"' )
		&& false !== strpos( $ow_with_contact['html'], 'name="password_2"' ),
	'The branch must not take away password changing from accounts that can use it.'
);

// The refusal that stays: this sub-phase stops offering a form it cannot serve, and
// deliberately does not weaken re-authentication for the case it now hides.
ow_assert(
	'save_password() still requires the current password',
	false !== strpos(
		ow_source( 'includes/Frontend/class-form-controller.php' ),
		'wp_check_password( (string) ( $post[\'password_current\'] ?? \'\' )'
	),
	'On an account with a verified email, planting a password without re-auth creates a login route that did not exist.'
);

// ---------------------------------------------------------------------
ow_section( 'The entry point is the only entry point' );

// The two shims form-login.php and form-register.php were deleted in 15.3. They were
// never loaded, and README told theme authors to override form-auth.php instead — so
// anybody who overrode a shim got no effect and no explanation. What is left to assert
// is the half that still matters: the flow renders form-auth and nothing else.
$shortcodes = ow_source( 'includes/Frontend/class-shortcodes.php' );

ow_assert(
	'the login/register flow renders form-auth',
	false !== strpos( $shortcodes, "'form-auth'" )
);

ow_assert(
	'no deleted shim is referenced anywhere',
	false === strpos( $shortcodes, "'form-login'" )
		&& false === strpos( $shortcodes, "'form-register'" ),
	'Deleting a template that something still names is a fatal on the page that names it.'
);

// ---------------------------------------------------------------------
ow_summary( 'Templates' );
