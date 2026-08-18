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
 * @package OmniWP
 */

require __DIR__ . '/../harness.php';

// ---------------------------------------------------------------------
ow_section( 'Invariant 1 — only the identities table answers "who owns this subject" (Phase 3)' );

ow_forbid_pattern(
	'no identity lookup through get_user_by( \'login\', … )',
	"/get_user_by\(\s*'login'/",
	array(),
	'user_login is permanently stale after a phone change and is resolved by WordPress core at authenticate priority 20, before any plugin code. See identity-model.md §3.'
);

ow_forbid_pattern(
	'no identity lookup through the OmniWP_phone user meta',
	'/META_PHONE\s*,\s*\R?\s*\'meta_value\'|meta_key\'?\s*=>\s*self::META_PHONE/',
	array(),
	'Phone ownership lives in OmniWP_identities, not usermeta. Replace with IdentityDirectory::resolve().'
);

ow_forbid_pattern(
	'no get_users() meta_query used as an identity index',
	'/get_users\(\s*\R?\s*array\(\s*\R?\s*\'meta_key\'/',
	array(),
	'A usermeta JOIN is both the wrong source of truth and a slow query the code already flags with phpcs:ignore WordPress.DB.SlowDBQuery.'
);

ow_require_companion(
	'every wp_insert_user() call derives user_login from OpaqueLogin',
	'/wp_insert_user\(/',
	'/OpaqueLogin::/',
	'user_login must be an opaque, never-typed value so core cannot resolve a phone or email to a user.'
);

// ---------------------------------------------------------------------
ow_section( 'Invariant 2 — identity seeds profile only when empty; profile never writes identity (Phase 5)' );

ow_forbid_pattern(
	'billing_* user meta is written only by ProfileSeeder',
	"/update_user_meta\([^;]*'billing_/",
	array( 'includes/Identity/class-profile-seeder.php' ),
	'A login phone must never overwrite a delivery contact. Route every write through ProfileSeeder::seed_if_empty().'
);

// The hook itself is fine — it is the only place with a WP_Error to add to. What
// is not fine is depending on its $data, which do_action() passes by value. So
// the rule is a pairing: anything using it must also use the filter that
// actually returns the posted array.
ow_require_companion(
	'checkout mutates posted data on a filter, not on the validation action',
	'/woocommerce_after_checkout_validation/',
	'/woocommerce_checkout_posted_data/',
	'do_action() passes arrays by value, so a substitution made in the validation hook is discarded. woocommerce_checkout_posted_data has a return value.'
);

// ---------------------------------------------------------------------
ow_section( 'Schema and wiring (Phase 2)' );

$bootstrap = ow_source( 'omniwp.php' );
preg_match( "/OMNIWP_DB_VERSION',\s*'(\d+)'/", $bootstrap, $db_version );
$version = isset( $db_version[1] ) ? (int) $db_version[1] : 0;

/*
 * A version exists and is a positive integer. It used to demand `>= 3`, because
 * Phase 2 needed a bump for maybe_upgrade() to run and wrote its own floor into the
 * rule. Phase 15 reset the version to 1 — there is nothing to upgrade from — and this
 * went red on a change it has no opinion about.
 *
 * Second time in two phases a pinned version number has done that; the abuse gate's
 * literal 5 was the first. What the rule is for is that the constant is present and
 * parses, because maybe_upgrade() compares against it and a missing constant would
 * make every load think an upgrade is due.
 */
ow_assert(
	'OMNIWP_DB_VERSION is a positive integer',
	$version >= 1,
	sprintf( 'found %d — maybe_upgrade() compares against this constant on every load.', $version )
);

$installer = ow_source( 'includes/class-installer.php' );

// Anchored on `function ` so the existing external_identities_table() accessor
// cannot satisfy this by substring match.
ow_assert(
	'Installer exposes the identities table name',
	false !== strpos( $installer, 'function identities_table' ),
	'Add Installer::identities_table() alongside the existing otp/audit accessors.'
);

ow_assert(
	'Installer exposes the identity history table name',
	false !== strpos( $installer, 'function identity_history_table' ),
	'Add Installer::identity_history_table().'
);

// The allowlist is gone with the code it excused. Phase 2 kept two references —
// Installer::drop_legacy_tables() and the uninstall routine — and said both should go
// once no installation could still carry the table. Phase 15 is that moment, so the
// rule is absolute now rather than absolute-except-here.
ow_forbid_pattern(
	'the external_identities table is not named anywhere',
	'/external_identities/',
	array(),
	'Federated providers stop being a special case; one table serves every channel.'
);

$uninstall = ow_source( 'uninstall.php' );

ow_assert(
	'uninstall.php removes the ward code meta keys',
	false !== strpos( $uninstall, 'OmniWP_ward_code' )
		&& false !== strpos( $uninstall, 'OmniWP_shipping_ward_code' ),
	'Both keys are written by the address module but were never cleaned up.'
);

// ---------------------------------------------------------------------
ow_section( 'Proof cannot be forged (Phase 3)' );

$issuer = ow_source( 'includes/Auth/class-session-issuer.php' );

ow_assert(
	'SessionIssuer::issue() requires an AuthProof',
	(bool) preg_match( '/function issue\(\s*AuthProof/', $issuer ),
	'The current signature accepts WP_User directly, so any caller can mint a session without demonstrating proof of control.'
);

ow_forbid_pattern(
	'wp_set_auth_cookie() is called only by SessionIssuer',
	'/wp_set_auth_cookie\(/',
	array( 'includes/Auth/class-session-issuer.php' ),
	'Session issuance must stay a single choke point.'
);

// ---------------------------------------------------------------------
ow_section( 'Proof and intent are separate concerns (Phase 4)' );

ow_forbid_pattern(
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
ow_require_companion(
	'password policy is applied wherever a password is set',
	'/(?:=>|=|\breturn\b)\s*wp_hash_password\(|^\s*wp_set_password\(/m',
	'/PasswordPolicy::validate\(/',
	'Both registration and reset must run omniwp_validate_password.'
);

ow_forbid_pattern(
	'"channel" is not overloaded — OTP delivery lives under OTP\\Transports',
	'/OmniWP\\\\OTP\\\\Channels|namespace OmniWP\\\\OTP\\\\Channels/',
	array(),
	'Rename OTP\\Channels to OTP\\Transports so "channel" only ever means an identity namespace.'
);

$phone = ow_source( 'includes/Identity/class-phone.php' );

ow_assert(
	'OMNIWP_phone_is_valid applies to Vietnamese numbers too',
	false === strpos( $phone, 'return (bool) preg_match( self::VN_MOBILE_NSN, $nsn );' ),
	'The Vietnamese branch returns before the filter runs, so the documented hook is dead on the default 84 country code.'
);

// ---------------------------------------------------------------------
ow_section( 'Identity lifecycle (Phase 6)' );

// Retiring an identity is how an account loses a way in. Every caller outside the
// repository and the directory must go through the service that carries the
// orphan guard, so a future feature cannot detach the last identity by accident.
ow_forbid_pattern(
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
$ow_release_callers = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_code ) {
	if ( 'includes/Identity/class-identity-repository.php' === $ow_relative ) {
		continue;
	}

	if ( false !== strpos( $ow_code, 'retire_all_for_user' ) ) {
		$ow_release_callers[] = $ow_relative;
	}
}

ow_assert(
	'releasing a deleted user\'s identities has a production caller',
	array() !== $ow_release_callers,
	'Nothing calls retire_all_for_user(), so wp_delete_user() strands every row it owned and those subjects can never be registered again.'
);

ow_assert(
	'and something is hooked to deleted_user to be that caller',
	false !== strpos( ow_source( 'includes/class-plugin.php' ), 'deleted_user' ),
	'The capability is only reachable if WordPress calls it; the hook is the wiring.'
);

// ---------------------------------------------------------------------
ow_section( 'This plugin upgrades from nothing (Phase 15)' );

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
$ow_legacy_surfaces = array(
	'migrate_settings_shape'    => 'settings arrays flatter than 1.0.1',
	'legacy_key_map'            => 'the flat-to-nested key map',
	'recreate_renamed_tables'   => 'installs at db_version < 4',
	'drop_legacy_tables'        => 'a table deleted in Phase 2',
	'LEGACY_SECRETS'            => 'secrets stored before 10.2 moved them',
	'backfill_provider_emails'  => 'accounts created before 14.4',
	'OMNIWP_external_identities' => 'the superseded identity table',
);

foreach ( $ow_legacy_surfaces as $ow_needle => $ow_serves ) {
	$ow_found = array();

	foreach ( ow_plugin_sources() as $ow_relative => $ow_code ) {
		if ( false !== strpos( $ow_code, $ow_needle ) ) {
			$ow_found[] = $ow_relative;
		}
	}

	// uninstall.php is not in ow_plugin_sources() — it runs without the plugin loaded
	// — so it is checked by name, because that is where two of these live.
	if ( false !== strpos( ow_source( 'uninstall.php' ), $ow_needle ) ) {
		$ow_found[] = 'uninstall.php';
	}

	ow_check(
		sprintf( 'nothing carries %s (%s)', $ow_needle, $ow_serves ),
		'',
		implode( ', ', $ow_found )
	);
}

$ow_shims = array( 'templates/form-login.php', 'templates/form-register.php' );

foreach ( $ow_shims as $ow_shim ) {
	ow_assert(
		sprintf( '%s is gone', $ow_shim ),
		! is_file( dirname( __DIR__, 2 ) . '/' . $ow_shim ),
		'A shim README documents as unused is a file somebody will override and wonder why nothing happens.'
	);
}

ow_assert(
	'the webhook tester accepts only the current field name',
	false === strpos( ow_source( 'includes/Admin/class-webhook-tester.php' ), "'channel'" ),
	'10.2 kept the old name because the admin JS posted it. The JS posts transport now.'
);

// ---------------------------------------------------------------------
ow_section( 'Documentation is not allowed to describe things that do not exist' );

/*
 * This project has now found a README asserting a control that is not there three
 * times, and the third was created by 15.3 deleting two templates the README still
 * described. Reading more carefully is not a fix; these are.
 */
$ow_readme = ow_source( 'README.md' );
$ow_root   = dirname( __DIR__, 2 );
$ow_ghosts = array();

/*
 * Every `<name>.php` the README names must exist somewhere the plugin ships. Anchored
 * on the extension rather than a path, because the README names them bare.
 *
 * Two limits, stated rather than glossed:
 *   - WordPress core filenames are skipped; the README legitimately names wp-login.php.
 *   - A name that exists in ANY of the searched directories passes, so it cannot catch
 *     a README claiming a file lives in templates/ when it actually only lives in
 *     templates/woocommerce/. `form-login.php` is exactly that case today, and it is
 *     why the sentence at README.md:242 had to be corrected by hand rather than by
 *     this rule. What the rule does catch is a name that exists nowhere — which is
 *     what form-register.php became.
 */
$ow_core_files = array( 'wp-login.php', 'wp-config.php', 'wp-settings.php', 'wp-load.php' );
if ( preg_match_all( '/`([a-z0-9-]+\.php)`/', $ow_readme, $ow_named ) ) {
	foreach ( array_unique( $ow_named[1] ) as $ow_file ) {
		if ( 'omniwp.php' === $ow_file || 'uninstall.php' === $ow_file
			|| in_array( $ow_file, $ow_core_files, true ) ) {
			continue;
		}

		if ( ! is_file( $ow_root . '/templates/' . $ow_file )
			&& ! is_file( $ow_root . '/templates/partials/' . $ow_file )
			&& ! is_file( $ow_root . '/templates/woocommerce/' . $ow_file )
			&& ! is_file( $ow_root . '/bin/' . $ow_file ) ) {
			$ow_ghosts[] = $ow_file;
		}
	}
}

ow_check(
	'README names no template that has been deleted',
	'',
	implode( ', ', $ow_ghosts )
);

// readme.txt is what WordPress.org reads. A Stable tag behind the constant ships a
// version nobody can install; ahead of it ships one that does not exist.
preg_match( "/OMNIWP_VERSION',\s*'([^']+)'/", ow_source( 'omniwp.php' ), $ow_ver );
preg_match( '/^Stable tag:\s*(.+)$/m', ow_source( 'readme.txt' ), $ow_stable );

ow_check(
	'readme.txt Stable tag matches OMNIWP_VERSION',
	trim( $ow_ver[1] ?? 'no constant' ),
	trim( $ow_stable[1] ?? 'no stable tag' )
);

/*
 * The string catalogue matches the tree.
 *
 * It went stale by 76 strings across five phases — the security tab, the delivery
 * screens, the mail templates, the mail surface — and surfaced only because 14.3
 * regenerated it for an unrelated reason. Nothing was watching. This is the watch.
 *
 * Shelled out rather than reimplemented: a second extractor would drift from the one
 * that writes the file, and then the rule would be checking itself.
 */
$ow_pot_check = 1;
$ow_pot_out   = array();
exec(
	escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/build-pot.php' ) . ' --check 2>&1',
	$ow_pot_out,
	$ow_pot_check
);

ow_assert(
	'languages/omniwp.pot is current',
	0 === $ow_pot_check,
	implode( ' | ', $ow_pot_out ) . ' — run: php bin/build-pot.php'
);

ow_assert(
	'the shipped version has a changelog entry',
	false !== strpos( ow_source( 'readme.txt' ), '= ' . trim( $ow_ver[1] ?? 'x' ) . ' =' ),
	'A version with no entry tells the reader the release changed nothing.'
);

ow_require_companion(
	'unlink checks the orphan guard and re-authenticates',
	'/function unlink\(/',
	'/can_unlink\(.*\R?.*|wp_check_password\(/',
	'Removing the last identity would lock the owner out with no recovery path, because user_login is opaque.'
);

// Account surface duplication rules live in their own suite —
// identity/run-account-surface-tests.php — registered `spec` until 8.2 extracts
// the partials that make them green.

// ---------------------------------------------------------------------
ow_section( 'The account form does not throw away what was typed (Phase 8.1)' );

// These are green and required, not spec: 8.1 is a bug fix, and the point of
// pinning it here is that the next person cannot reintroduce the reload without
// the build saying so. Structural assertions, not behavioural ones — the browser
// checks are listed in docs/account-surface/8.1-stop-data-loss.md and have to be
// run against a live install.
$ow_js = ow_source( 'assets/js/omniwp.js' );

ow_assert(
	'verifying a contact does not reload the page',
	false === strpos( $ow_js, 'window.location.reload()' ),
	'A reload discards every unsaved field on the account form, and leaves the form posting the previous address while the account already holds the new one.'
);

ow_assert(
	'the contact inputs intercept Enter',
	(bool) preg_match( "/valueInput\.addEventListener\(\s*'keydown'/", $ow_js ),
	'Enter in an unnamed input triggers implicit form submission: the typed value is discarded and the rest of the form saves as a side effect.'
);

ow_assert(
	'unsaved edits are guarded before navigation',
	false !== strpos( $ow_js, "'beforeunload'" ),
	'The provider link buttons are plain <a> elements sitting in the middle of a long form.'
);

ow_assert(
	'resend works without a token the page no longer has',
	(bool) preg_match( '/token \? \{ token: token \} : \{ type: type \}/', $ow_js ),
	'The token lives only in the browser, so a reload strands the pending flow with no way to ask for a new code.'
);

$ow_contact_service = ow_source( 'includes/Auth/class-contact-verification-service.php' );

ow_assert(
	'the pending row carries the token the client lost',
	(bool) preg_match( "/'token'\s*=>/", $ow_contact_service ),
	'resend-by-type needs the server to hold the token, and pending() must keep not returning it — a token a template can print is a token that ends up in a page.'
);

// ---------------------------------------------------------------------
ow_section( 'Removed features stay removed' );

// "The old thing is gone" is half a rule; the other half is "nothing points at
// the old thing". Both features were deleted on request, and each had reached
// into settings, REST, JS, the generated dataset and the uninstall routine —
// exactly the spread that leaves a stub behind somewhere.

ow_forbid_pattern(
	'the referral code is gone from shipped code',
	'/referral/i',
	array( 'uninstall.php' ),
	'Removed as unnecessary. uninstall.php keeps the meta key so installs that already wrote one are still cleaned up.'
);

ow_forbid_pattern(
	'the address quick-search is gone from shipped code',
	'/quick_search|data-sl-quick|search-index|index_key/',
	array(),
	'Removed as unnecessary, along with its REST route, its 312 KB generated index and the build step that produced it.'
);

ow_assert(
	'the generated search index is no longer shipped',
	! is_readable( dirname( __DIR__, 2 ) . '/data/search-index.php' ),
	'data/search-index.php is dead weight once nothing searches it.'
);

// ---------------------------------------------------------------------
ow_section( 'Every referenced OmniWP class exists on disk' );

/**
 * Resolve a class name to its file the way omniwp.php's autoloader does.
 */
function ow_class_file( string $fqn ): string {
	$relative = substr( $fqn, strlen( 'OmniWP\\' ) );
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

foreach ( ow_plugin_sources() as $relative => $contents ) {
	$referenced = array();

	// `use OmniWP\Foo\Bar;`
	if ( preg_match_all( '/^use\s+(OmniWP\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $contents, $imports ) ) {
		$referenced = array_merge( $referenced, $imports[1] );
	}

	// Inline `\OmniWP\Foo\Bar::` or `new \OmniWP\Foo\Bar(`
	if ( preg_match_all( '/\\\\(OmniWP\\\\[A-Za-z0-9_\\\\]+?)(?:::|\s*\()/', $contents, $inline ) ) {
		$referenced = array_merge( $referenced, $inline[1] );
	}

	foreach ( array_unique( $referenced ) as $fqn ) {
		$file = ow_class_file( $fqn );

		if ( '' === ow_source( $file ) ) {
			$missing[] = $relative . ' → ' . $fqn;
		}
	}
}

if ( $missing ) {
	++$GLOBALS['ow_harness']['failed'];
	printf( "  FAIL     every referenced OmniWP class resolves to a file\n" );
	printf( "           A reference to a deleted class is a fatal error the moment that line runs.\n" );

	foreach ( array_unique( $missing ) as $offender ) {
		printf( "           → %s\n", $offender );
	}
} else {
	++$GLOBALS['ow_harness']['passed'];
}

// ---------------------------------------------------------------------
/*
 * Every way callback() can refuse leaves a row behind.
 *
 * Three of its four failure branches recorded PROVIDER_FAILED and the fourth —
 * the one guarding AccountProvisioner::resolve() — did not. That branch is the
 * one a visitor meets when the provider account is already linked elsewhere, so
 * the single most confusing failure in the whole flow was the only one that
 * wrote nothing anywhere. Three clicks on "Liên kết" left an empty log and the
 * diagnosis had to be rebuilt by hand.
 *
 * Source-level on purpose: callback() ends in exit(), so no suite in this repo
 * can call it. This asks the weaker question it can actually answer — is there
 * a record before each refusal — and would have gone red on the tree that had
 * the gap.
 */
$ow_callback = ow_source( 'includes/Auth/class-provider-auth-controller.php' );
$ow_body     = '';

if ( preg_match( '/public function callback\(\).*?\n\t\}\n/s', $ow_callback, $ow_match ) ) {
	$ow_body = $ow_match[0];
}

ow_assert(
	'callback() is still a method this rule can read',
	'' !== $ow_body,
	'The rule below is vacuous unless the body was found, and a rule that passes for want of a subject states the opposite of the truth.'
);

$ow_unrecorded = 0;

foreach ( explode( '$this->fail(', $ow_body ) as $ow_index => $ow_before ) {
	if ( 0 === $ow_index ) {
		continue;
	}

	// The text preceding this fail() call, back to the previous one.
	if ( false === strpos( $ow_before, 'AuditLog::record(' ) && false === strpos( $ow_before, 'PROVIDER_FAILED' ) ) {
		$ow_segments = explode( '$this->fail(', $ow_body );
		$ow_preceding = $ow_segments[ $ow_index - 1 ];

		if ( false === strpos( $ow_preceding, 'AuditLog::record(' ) ) {
			++$ow_unrecorded;
		}
	}
}

ow_check(
	'every refusal in callback() writes an audit row first',
	0,
	$ow_unrecorded
);

// ---------------------------------------------------------------------
/*
 * Zalo Login is gone, and this is what says so.
 *
 * A removal crosses every boundary a rename does, and this repo has been bitten
 * five times by exactly that. "Did we get all of it?" is a question of belief
 * until something can answer it, so this walks the shipped source and names
 * every survivor.
 *
 * One allowlist entry, and it is not an exception being excused — Zalo OA/ZNS
 * is a *different feature*, an OTP delivery channel, which the provider card's
 * own help text used to spell out because the two were confused before. A
 * grep-and-delete removal would have taken it with them.
 */
$ow_zalo_allowed = array(
	// Zalo ZNS: an OTP transport, not a login provider.
	'includes/OTP/Transports/class-transport-router.php',
);

$ow_zalo_survivors = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_code ) {
	if ( in_array( $ow_relative, $ow_zalo_allowed, true ) ) {
		continue;
	}

	if ( false !== stripos( $ow_code, 'zalo' ) ) {
		$ow_zalo_survivors[] = $ow_relative;
	}
}

// templates/ and assets/ are not PHP-only, so they are walked separately rather
// than trusted to the source list above.
foreach ( array( 'assets/css/omniwp.css', 'assets/js/omniwp.js' ) as $ow_asset ) {
	$ow_asset_code = ow_source( $ow_asset );

	if ( '' !== $ow_asset_code && false !== stripos( $ow_asset_code, 'zalo' ) ) {
		$ow_zalo_survivors[] = $ow_asset;
	}
}

if ( $ow_zalo_survivors ) {
	++$GLOBALS['ow_harness']['failed'];
	printf( "  FAIL     no shipped file mentions Zalo Login\n" );
	printf( "           A removal that leaves references behind is a half-removal, and the half that stays is the half nobody tests.\n" );

	foreach ( $ow_zalo_survivors as $ow_offender ) {
		printf( "           → %s\n", $ow_offender );
	}
} else {
	++$GLOBALS['ow_harness']['passed'];
}

ow_assert(
	'the Zalo provider class is gone from disk',
	'' === ow_source( 'includes/Auth/Providers/class-zalo-provider.php' ),
	'The file is still there, so the autoloader can still reach it and a stray reference would still resolve.'
);

// ---------------------------------------------------------------------
ow_section( 'Hook names are one namespace, and every listener has a speaker' );

/*
 * Two rules over one scan, both written after a one-character defect that took
 * four integration gates offline without turning anything red.
 *
 * tests/integration/run-provider-gates.php listened on `omniwp_setting` while
 * Settings::get() fires `omniwp_setting`. WordPress hook names are
 * case-sensitive, so the filter never matched; the gate silently inherited the
 * host site's own configuration, and the self-check it carries for exactly this
 * reason exited 2 — taking the provider, abuse, delivery and install gates with
 * it. The self-check worked. Nothing told anyone the fix was never applied.
 *
 * The scan reaches tests/ on purpose. ow_plugin_sources() excludes it, and
 * tests/ is where the defect lived, so a rule reading only includes/ would have
 * stayed green straight through it.
 *
 * Literal tags only. Three hooks are named by class constant (CLEANUP_HOOK and
 * friends) and a constant cannot drift from itself, which is the argument for
 * using one — not an exemption this rule has to carry.
 */
$ow_hook_fired    = array( 'apply_filters', 'apply_filters_ref_array', 'do_action', 'do_action_ref_array' );
$ow_hook_listened = array( 'add_filter', 'add_action' );
$ow_hook_calls    = array_merge( $ow_hook_fired, $ow_hook_listened );

$ow_hook_files = array();
foreach ( array( 'includes', 'templates', 'tests' ) as $ow_dir ) {
	$ow_walk = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/' . $ow_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $ow_walk as $ow_file ) {
		if ( $ow_file->isFile() && 'php' === strtolower( $ow_file->getExtension() ) ) {
			$ow_hook_files[] = $ow_file->getPathname();
		}
	}
}
$ow_hook_files[] = dirname( __DIR__, 2 ) . '/omniwp.php';
$ow_hook_files[] = dirname( __DIR__, 2 ) . '/uninstall.php';

$ow_root       = str_replace( '\\', '/', dirname( __DIR__, 2 ) ) . '/';
$ow_miscased   = array();
$ow_spoken     = array();
$ow_heard      = array();
$ow_hook_regex = '/\b(' . implode( '|', $ow_hook_calls ) . ')\s*\(\s*[\'"]([^\'"]+)[\'"]/';

foreach ( $ow_hook_files as $ow_path ) {
	$ow_code = is_readable( $ow_path ) ? (string) file_get_contents( $ow_path ) : '';
	if ( '' === $ow_code ) {
		continue;
	}

	$ow_relative = str_replace( array( '\\', $ow_root ), array( '/', '' ), $ow_path );

	if ( ! preg_match_all( $ow_hook_regex, $ow_code, $ow_matches, PREG_SET_ORDER ) ) {
		continue;
	}

	foreach ( $ow_matches as $ow_match ) {
		list( , $ow_call, $ow_tag ) = $ow_match;

		// Somebody else's hook is somebody else's naming problem.
		if ( false === stripos( $ow_tag, 'omniwp' ) ) {
			continue;
		}

		/*
		 * `wp_ajax_{$action}` and `admin_post_{$action}` are core's, and core is
		 * what fires them. The half that belongs to this plugin is the action
		 * name spliced into the middle, so the casing rule applies to that and
		 * the speaker rule does not apply at all — holding the plugin
		 * responsible for firing a hook WordPress owns would make the rule
		 * unsatisfiable, and an unsatisfiable rule gets an allowlist entry
		 * instead of a fix.
		 */
		$ow_core_owned = false;
		$ow_name       = $ow_tag;

		foreach ( array( 'wp_ajax_nopriv_', 'wp_ajax_', 'admin_post_nopriv_', 'admin_post_' ) as $ow_core_prefix ) {
			if ( 0 === strpos( $ow_tag, $ow_core_prefix ) ) {
				$ow_core_owned = true;
				$ow_name       = substr( $ow_tag, strlen( $ow_core_prefix ) );
				break;
			}
		}

		if ( 0 !== strpos( $ow_name, 'omniwp_' ) ) {
			$ow_miscased[ $ow_name ][] = $ow_relative;
		}

		if ( $ow_core_owned ) {
			continue;
		}

		if ( in_array( $ow_call, $ow_hook_fired, true ) ) {
			$ow_spoken[ strtolower( $ow_tag ) ] = true;
		} else {
			$ow_heard[ $ow_tag ][] = $ow_relative;
		}
	}
}

ow_assert(
	'every OmniWP hook tag is lowercase omniwp_*',
	array() === $ow_miscased,
	'WordPress compares hook names byte for byte, so two casings are two hooks. Offenders: '
		. implode( '; ', array_map(
			static fn( string $ow_t ): string => $ow_t . ' (' . count( array_unique( $ow_miscased[ $ow_t ] ) ) . ' file)',
			array_keys( $ow_miscased )
		) )
);

$ow_orphan_listeners = array();
foreach ( $ow_heard as $ow_tag => $ow_where ) {
	if ( ! isset( $ow_spoken[ strtolower( $ow_tag ) ] ) ) {
		$ow_orphan_listeners[ $ow_tag ] = array_unique( $ow_where );
	}
}

ow_assert(
	'every OmniWP hook listened to is a hook something fires',
	array() === $ow_orphan_listeners,
	'A listener on a tag nobody fires is a no-op that reads like configuration. Offenders: '
		. implode( '; ', array_map(
			static fn( string $ow_t ): string => $ow_t . ' ← ' . implode( ', ', $ow_orphan_listeners[ $ow_t ] ),
			array_keys( $ow_orphan_listeners )
		) )
);

// ---------------------------------------------------------------------
ow_section( 'An extension the plugin cannot run without is an extension it names' );

/*
 * `Requires PHP: 8.0` is the only runtime requirement this plugin has ever
 * stated, and it was not the only one it had. Ten `mb_*` calls sit in shipped
 * code with no `function_exists()` between them and a fatal — password reset,
 * address normalisation, voucher validation and the account-hub sidebar among
 * them.
 *
 * WordPress does not close this. core's compat.php supplies `_mb_substr()` and
 * `_mb_strlen()` for its own internals; there is no fallback anywhere for
 * `mb_strtolower()`, `mb_strtoupper()` or `mb_convert_encoding()`. On a host
 * without ext-mbstring the plugin does not degrade, it dies.
 *
 * The rule is a declaration check rather than a ban. Vietnamese text genuinely
 * needs multibyte handling and a hand-rolled substitute would be worse than the
 * dependency. What was wrong was leaving it unsaid, so the fix is that every
 * `mb_*` the code reaches for is listed in one place, and that place is what the
 * readiness screen reports on.
 */
$ow_mb_used = array();
foreach ( ow_plugin_sources() as $ow_relative => $ow_code ) {
	if ( preg_match_all( '/\b(mb_[a-z_]+)\s*\(/', $ow_code, $ow_mb_hits ) ) {
		foreach ( $ow_mb_hits[1] as $ow_fn ) {
			$ow_mb_used[ $ow_fn ][ $ow_relative ] = true;
		}
	}
}

/*
 * Read out of the source, not off the class. This suite loads harness.php and
 * nothing else — no autoloader, no stubs — so `class_exists()` here answers
 * "false" for every class in the plugin and a rule built on it would pass by
 * never finding anything to check. That is the vacuous-rule failure 10.0 and
 * 11.0 both had to correct, so it gets caught once rather than again.
 */
$ow_mb_declared = array();
if ( preg_match(
	'/const\s+MBSTRING_FUNCTIONS\s*=\s*array\((.*?)\);/s',
	ow_source( 'includes/Admin/class-readiness.php' ),
	$ow_mb_const
) ) {
	preg_match_all( "/'([a-z_]+)'/", $ow_mb_const[1], $ow_mb_names );
	$ow_mb_declared = $ow_mb_names[1];
}

ow_assert(
	'Readiness::MBSTRING_FUNCTIONS parses and is not empty',
	array() !== $ow_mb_declared,
	'The rules below compare against this list, so an unreadable constant would make both of them vacuous.'
);

$ow_mb_undeclared = array_diff( array_keys( $ow_mb_used ), $ow_mb_declared );

ow_assert(
	'every mb_* function the plugin calls is declared in Readiness::MBSTRING_FUNCTIONS',
	array() === $ow_mb_undeclared,
	'An undeclared extension call is a fatal the readiness screen cannot warn about. Undeclared: '
		. implode( ', ', $ow_mb_undeclared )
);

// The other direction: a list that outlives its call sites starts describing a
// dependency the plugin no longer has, and readiness would keep demanding it.
ow_assert(
	'Readiness::MBSTRING_FUNCTIONS names nothing the plugin has stopped calling',
	array() === array_diff( $ow_mb_declared, array_keys( $ow_mb_used ) ),
	'Stale entries: ' . implode( ', ', array_diff( $ow_mb_declared, array_keys( $ow_mb_used ) ) )
);

ow_assert(
	'readiness reports on the mbstring extension',
	false !== strpos( ow_source( 'includes/Admin/class-readiness.php' ), "'mbstring'" ),
	'The dependency is declared but nothing tells an administrator whether their host has it.'
);

// ---------------------------------------------------------------------
ow_summary( 'Identity fitness' );
