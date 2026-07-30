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

sl_forbid_pattern(
	'checkout does not rely on the value-passed $data of woocommerce_after_checkout_validation',
	'/woocommerce_after_checkout_validation/',
	array(),
	'do_action() passes arrays by value, so the ward-name substitution is discarded. Use woocommerce_checkout_posted_data, which has a return value.'
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

sl_forbid_pattern(
	'the external_identities table is gone (folded into identities)',
	'/external_identities/',
	array(),
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
	'OTP purposes are not enumerated per feature',
	'/PURPOSE_CHANGE_PHONE|PURPOSE_CHANGE_EMAIL|PURPOSE_VERIFY_EMAIL/',
	array(),
	'Six purposes conflate proof-of-control with business intent. Replace with (channel, intent) so a new channel adds no constants.'
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
sl_summary( 'Identity fitness' );
