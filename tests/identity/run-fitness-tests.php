<?php
/**
 * Architecture fitness tests for the identity model.
 *
 * These do not exercise behaviour. They scan the shipped source and fail when
 * the structure of the code violates an invariant from docs/identity-model.md.
 *
 * Why source scanning rather than behavioural assertions: an invariant that
 * depends on everyone remembering it decays. One that fails the build survives
 * contributors who never read the spec. Each rule names the phase that turns it
 * green, so a red run doubles as a progress report.
 *
 * Run with:  php tests/identity/run-fitness-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../harness.php';

// ---------------------------------------------------------------------
sl_section( 'Invariant 1 — only the identities table answers "who owns this subject" (Phase 3)' );

sl_forbid_pattern(
	'no identity lookup through get_user_by( \'login\', … )',
	"/get_user_by\(\s*'login'/",
	array(),
	'user_login is permanently stale after a phone change and is resolved by WordPress core at authenticate priority 20, before any plugin code. See identity-model.md §3.'
);

sl_forbid_pattern(
	'no identity lookup through the smartlogin_phone user meta',
	'/META_PHONE\s*,\s*\R?\s*\'meta_value\'|meta_key\'?\s*=>\s*self::META_PHONE/',
	array(),
	'Phone ownership lives in smartlogin_identities, not usermeta. Replace with IdentityDirectory::resolve().'
);

sl_forbid_pattern(
	'no get_users() meta_query used as an identity index',
	'/get_users\(\s*\R?\s*array\(\s*\R?\s*\'meta_key\'/',
	array(),
	'A usermeta JOIN is both the wrong source of truth and a slow query the code already flags with phpcs:ignore WordPress.DB.SlowDBQuery.'
);

sl_require_companion(
	'every wp_insert_user() call derives user_login from OpaqueLogin',
	'/wp_insert_user\(/',
	'/OpaqueLogin::/',
	'user_login must be an opaque, never-typed value so core cannot resolve a phone or email to a user.'
);

// ---------------------------------------------------------------------
sl_section( 'Invariant 2 — identity seeds profile only when empty; profile never writes identity (Phase 5)' );

sl_forbid_pattern(
	'billing_* user meta is written only by ProfileSeeder',
	"/update_user_meta\([^;]*'billing_/",
	array( 'includes/Identity/class-profile-seeder.php' ),
	'A login phone must never overwrite a delivery contact. Route every write through ProfileSeeder::seed_if_empty().'
);

// The hook itself is fine — it is the only place with a WP_Error to add to. What
// is not fine is depending on its $data, which do_action() passes by value. So
// the rule is a pairing: anything using it must also use the filter that
// actually returns the posted array.
sl_require_companion(
	'checkout mutates posted data on a filter, not on the validation action',
	'/woocommerce_after_checkout_validation/',
	'/woocommerce_checkout_posted_data/',
	'do_action() passes arrays by value, so a substitution made in the validation hook is discarded. woocommerce_checkout_posted_data has a return value.'
);

// ---------------------------------------------------------------------
sl_section( 'Schema and wiring (Phase 2)' );

$bootstrap = sl_source( 'smart-login.php' );
preg_match( "/SMART_LOGIN_DB_VERSION',\s*'(\d+)'/", $bootstrap, $db_version );
$version = isset( $db_version[1] ) ? (int) $db_version[1] : 0;

sl_assert(
	'SMART_LOGIN_DB_VERSION is at least 3',
	$version >= 3,
	sprintf( 'found %d — the two identity tables require a version bump so Installer::maybe_upgrade() runs.', $version )
);

$installer = sl_source( 'includes/class-installer.php' );

// Anchored on `function ` so the existing external_identities_table() accessor
// cannot satisfy this by substring match.
sl_assert(
	'Installer exposes the identities table name',
	false !== strpos( $installer, 'function identities_table' ),
	'Add Installer::identities_table() alongside the existing otp/audit accessors.'
);

sl_assert(
	'Installer exposes the identity history table name',
	false !== strpos( $installer, 'function identity_history_table' ),
	'Add Installer::identity_history_table().'
);

// Two allowlisted references remain, both of which exist only to remove the
// table: Installer::drop_legacy_tables() and the uninstall routine. They are the
// migration itself, not a dependency on it. Both should be deleted once no
// installation can still be carrying the table.
sl_forbid_pattern(
	'the external_identities table is only ever dropped, never used',
	'/external_identities/',
	array( 'includes/class-installer.php', 'uninstall.php' ),
	'Federated providers stop being a special case; one table serves every channel.'
);

$uninstall = sl_source( 'uninstall.php' );

sl_assert(
	'uninstall.php removes the ward code meta keys',
	false !== strpos( $uninstall, 'smartlogin_ward_code' )
		&& false !== strpos( $uninstall, 'smartlogin_shipping_ward_code' ),
	'Both keys are written by the address module but were never cleaned up.'
);

// ---------------------------------------------------------------------
sl_section( 'Proof cannot be forged (Phase 3)' );

$issuer = sl_source( 'includes/Auth/class-session-issuer.php' );

sl_assert(
	'SessionIssuer::issue() requires an AuthProof',
	(bool) preg_match( '/function issue\(\s*AuthProof/', $issuer ),
	'The current signature accepts WP_User directly, so any caller can mint a session without demonstrating proof of control.'
);

sl_forbid_pattern(
	'wp_set_auth_cookie() is called only by SessionIssuer',
	'/wp_set_auth_cookie\(/',
	array( 'includes/Auth/class-session-issuer.php' ),
	'Session issuance must stay a single choke point.'
);

// ---------------------------------------------------------------------
sl_section( 'Proof and intent are separate concerns (Phase 4)' );

sl_forbid_pattern(
	'OTP intents are not enumerated per channel',
	'/OtpService::PURPOSE_|PURPOSE_CHANGE_PHONE|PURPOSE_CHANGE_EMAIL|PURPOSE_VERIFY_EMAIL/',
	array(),
	'Six purposes conflated proof-of-control with business intent. Four INTENT_* constants cover every flow, and a new channel adds none.'
);

// The password policy filter must not be reachable from only one of the two
// paths that set a password. A policy applied on one path is not a policy.
// Anchored on call syntax, not on the function name appearing anywhere: a
// docblock that merely says "Output of wp_hash_password()" is documentation, not
// a place a password is set.
sl_require_companion(
	'password policy is applied wherever a password is set',
	'/(?:=>|=|\breturn\b)\s*wp_hash_password\(|^\s*wp_set_password\(/m',
	'/PasswordPolicy::validate\(/',
	'Both registration and reset must run smart_login_validate_password.'
);

sl_forbid_pattern(
	'"channel" is not overloaded — OTP delivery lives under OTP\\Transports',
	'/SmartLogin\\\\OTP\\\\Channels|namespace SmartLogin\\\\OTP\\\\Channels/',
	array(),
	'Rename OTP\\Channels to OTP\\Transports so "channel" only ever means an identity namespace.'
);

$phone = sl_source( 'includes/Identity/class-phone.php' );

sl_assert(
	'smart_login_phone_is_valid applies to Vietnamese numbers too',
	false === strpos( $phone, 'return (bool) preg_match( self::VN_MOBILE_NSN, $nsn );' ),
	'The Vietnamese branch returns before the filter runs, so the documented hook is dead on the default 84 country code.'
);

// ---------------------------------------------------------------------
sl_section( 'Identity lifecycle (Phase 6)' );

// Retiring an identity is how an account loses a way in. Every caller outside the
// repository and the directory must go through the service that carries the
// orphan guard, so a future feature cannot detach the last identity by accident.
sl_forbid_pattern(
	'identities are retired only through the directory or the link service',
	'/->retire\(/',
	array(
		'includes/Identity/class-identity-repository.php',
		'includes/Identity/class-identity-directory.php',
		'includes/Auth/class-identity-link-service.php',
	),
	'IdentityLinkService::unlink() is the only user-facing path, and it refuses to remove the last identity.'
);

/*
 * A capability nobody calls is a capability nobody has (14.7).
 *
 * `IdentityRepository::retire_all_for_user()` has existed since Phase 2 with a
 * default reason of literally `'user_deleted'`, and until 14.7 the only callers were
 * two lines in the integration gate. So deleting a WordPress user left its identity
 * rows live: the subject stayed claimed by an account that no longer existed, and
 * `create_verified_user()` refused that number or address as "already registered"
 * for ever.
 *
 * This rule is narrow on purpose. "Every public repository method has a caller" would
 * be the general form and would go red on things that are legitimately API surface
 * for other plugins. This one names the method whose absence of a caller was a defect.
 */
$sl_release_callers = array();

foreach ( sl_plugin_sources() as $sl_relative => $sl_code ) {
	if ( 'includes/Identity/class-identity-repository.php' === $sl_relative ) {
		continue;
	}

	if ( false !== strpos( $sl_code, 'retire_all_for_user' ) ) {
		$sl_release_callers[] = $sl_relative;
	}
}

sl_assert(
	'releasing a deleted user\'s identities has a production caller',
	array() !== $sl_release_callers,
	'Nothing calls retire_all_for_user(), so wp_delete_user() strands every row it owned and those subjects can never be registered again.'
);

sl_assert(
	'and something is hooked to deleted_user to be that caller',
	false !== strpos( sl_source( 'includes/class-plugin.php' ), 'deleted_user' ),
	'The capability is only reachable if WordPress calls it; the hook is the wiring.'
);

// ---------------------------------------------------------------------
sl_section( 'This plugin upgrades from nothing (Phase 15)' );

/*
 * refactor-plan.md has said since Phase 0 that the project has never run in
 * production and carries no migration burden. Eleven phases wrote migration code
 * anyway, each for the development installs that existed at the time. These rules
 * name every one of those surfaces.
 *
 * They are worth keeping after the deletions, not only during them. The habit being
 * retired is writing an upgrade path by reflex alongside the change, and a rule is
 * what makes the next reflex visible in review. When there is genuinely something to
 * migrate, the rule is edited in the same commit as the migration — deliberately, in
 * writing, which is the whole difference.
 */
$sl_legacy_surfaces = array(
	'migrate_settings_shape'    => 'settings arrays flatter than 1.0.1',
	'legacy_key_map'            => 'the flat-to-nested key map',
	'recreate_renamed_tables'   => 'installs at db_version < 4',
	'drop_legacy_tables'        => 'a table deleted in Phase 2',
	'LEGACY_SECRETS'            => 'secrets stored before 10.2 moved them',
	'backfill_provider_emails'  => 'accounts created before 14.4',
	'smart_login_external_identities' => 'the superseded identity table',
);

foreach ( $sl_legacy_surfaces as $sl_needle => $sl_serves ) {
	$sl_found = array();

	foreach ( sl_plugin_sources() as $sl_relative => $sl_code ) {
		if ( false !== strpos( $sl_code, $sl_needle ) ) {
			$sl_found[] = $sl_relative;
		}
	}

	// uninstall.php is not in sl_plugin_sources() — it runs without the plugin loaded
	// — so it is checked by name, because that is where two of these live.
	if ( false !== strpos( sl_source( 'uninstall.php' ), $sl_needle ) ) {
		$sl_found[] = 'uninstall.php';
	}

	sl_check(
		sprintf( 'nothing carries %s (%s)', $sl_needle, $sl_serves ),
		'',
		implode( ', ', $sl_found )
	);
}

$sl_shims = array( 'templates/form-login.php', 'templates/form-register.php' );

foreach ( $sl_shims as $sl_shim ) {
	sl_assert(
		sprintf( '%s is gone', $sl_shim ),
		! is_file( dirname( __DIR__, 2 ) . '/' . $sl_shim ),
		'A shim README documents as unused is a file somebody will override and wonder why nothing happens.'
	);
}

sl_assert(
	'the webhook tester accepts only the current field name',
	false === strpos( sl_source( 'includes/Admin/class-webhook-tester.php' ), "'channel'" ),
	'10.2 kept the old name because the admin JS posted it. The JS posts transport now.'
);

sl_require_companion(
	'unlink checks the orphan guard and re-authenticates',
	'/function unlink\(/',
	'/can_unlink\(.*\R?.*|wp_check_password\(/',
	'Removing the last identity would lock the owner out with no recovery path, because user_login is opaque.'
);

// Account surface duplication rules live in their own suite —
// identity/run-account-surface-tests.php — registered `spec` until 8.2 extracts
// the partials that make them green.

// ---------------------------------------------------------------------
sl_section( 'The account form does not throw away what was typed (Phase 8.1)' );

// These are green and required, not spec: 8.1 is a bug fix, and the point of
// pinning it here is that the next person cannot reintroduce the reload without
// the build saying so. Structural assertions, not behavioural ones — the browser
// checks are listed in docs/account-surface/8.1-stop-data-loss.md and have to be
// run against a live install.
$sl_js = sl_source( 'assets/js/smart-login.js' );

sl_assert(
	'verifying a contact does not reload the page',
	false === strpos( $sl_js, 'window.location.reload()' ),
	'A reload discards every unsaved field on the account form, and leaves the form posting the previous address while the account already holds the new one.'
);

sl_assert(
	'the contact inputs intercept Enter',
	(bool) preg_match( "/valueInput\.addEventListener\(\s*'keydown'/", $sl_js ),
	'Enter in an unnamed input triggers implicit form submission: the typed value is discarded and the rest of the form saves as a side effect.'
);

sl_assert(
	'unsaved edits are guarded before navigation',
	false !== strpos( $sl_js, "'beforeunload'" ),
	'The provider link buttons are plain <a> elements sitting in the middle of a long form.'
);

sl_assert(
	'resend works without a token the page no longer has',
	(bool) preg_match( '/token \? \{ token: token \} : \{ type: type \}/', $sl_js ),
	'The token lives only in the browser, so a reload strands the pending flow with no way to ask for a new code.'
);

$sl_contact_service = sl_source( 'includes/Auth/class-contact-verification-service.php' );

sl_assert(
	'the pending row carries the token the client lost',
	(bool) preg_match( "/'token'\s*=>/", $sl_contact_service ),
	'resend-by-type needs the server to hold the token, and pending() must keep not returning it — a token a template can print is a token that ends up in a page.'
);

// ---------------------------------------------------------------------
sl_section( 'Removed features stay removed' );

// "The old thing is gone" is half a rule; the other half is "nothing points at
// the old thing". Both features were deleted on request, and each had reached
// into settings, REST, JS, the generated dataset and the uninstall routine —
// exactly the spread that leaves a stub behind somewhere.

sl_forbid_pattern(
	'the referral code is gone from shipped code',
	'/referral/i',
	array( 'uninstall.php' ),
	'Removed as unnecessary. uninstall.php keeps the meta key so installs that already wrote one are still cleaned up.'
);

sl_forbid_pattern(
	'the address quick-search is gone from shipped code',
	'/quick_search|data-sl-quick|search-index|index_key/',
	array(),
	'Removed as unnecessary, along with its REST route, its 312 KB generated index and the build step that produced it.'
);

sl_assert(
	'the generated search index is no longer shipped',
	! is_readable( dirname( __DIR__, 2 ) . '/data/search-index.php' ),
	'data/search-index.php is dead weight once nothing searches it.'
);

// ---------------------------------------------------------------------
sl_section( 'Every referenced SmartLogin class exists on disk' );

/**
 * Resolve a class name to its file the way smart-login.php's autoloader does.
 */
function sl_class_file( string $fqn ): string {
	$relative = substr( $fqn, strlen( 'SmartLogin\\' ) );
	$parts    = explode( '\\', $relative );
	$short    = array_pop( $parts );
	$kebab    = strtolower( preg_replace( '/(?<!^)([A-Z])/', '-$1', $short ) );

	return 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' ) . 'class-' . $kebab . '.php';
}

// A deleted class leaves callers behind, and PHP only notices when the line runs.
// php -l cannot see it, and no unit test renders a template — which is exactly
// how templates/form-auth.php kept calling IdentityResolver for four phases after
// it was removed, fatalling the WooCommerce My Account page on every load.
$missing = array();

foreach ( sl_plugin_sources() as $relative => $contents ) {
	$referenced = array();

	// `use SmartLogin\Foo\Bar;`
	if ( preg_match_all( '/^use\s+(SmartLogin\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $contents, $imports ) ) {
		$referenced = array_merge( $referenced, $imports[1] );
	}

	// Inline `\SmartLogin\Foo\Bar::` or `new \SmartLogin\Foo\Bar(`
	if ( preg_match_all( '/\\\\(SmartLogin\\\\[A-Za-z0-9_\\\\]+?)(?:::|\s*\()/', $contents, $inline ) ) {
		$referenced = array_merge( $referenced, $inline[1] );
	}

	foreach ( array_unique( $referenced ) as $fqn ) {
		$file = sl_class_file( $fqn );

		if ( '' === sl_source( $file ) ) {
			$missing[] = $relative . ' → ' . $fqn;
		}
	}
}

if ( $missing ) {
	++$GLOBALS['sl_harness']['failed'];
	printf( "  FAIL     every referenced SmartLogin class resolves to a file\n" );
	printf( "           A reference to a deleted class is a fatal error the moment that line runs.\n" );

	foreach ( array_unique( $missing ) as $offender ) {
		printf( "           → %s\n", $offender );
	}
} else {
	++$GLOBALS['sl_harness']['passed'];
}

// ---------------------------------------------------------------------
sl_summary( 'Identity fitness' );
