<?php
/**
 * Uninstall routine. Only destroys data when the admin explicitly opted in.
 *
 * @package SmartLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$smart_login_settings = get_option( 'smart_login_settings', array() );

/*
 * `advanced.delete_data_on_uninstall`, not the flat key this read for years.
 *
 * The settings rewrite made the option nested and Installer::migrate_flat_keys()
 * lists this exact pair, so the flat subscript resolved to null on every install
 * that had ever been migrated — which is all of them. empty( null ) is true, so
 * the routine returned immediately and an administrator who ticked *Xoá dữ liệu
 * khi gỡ* got nothing deleted, silently.
 *
 * The plugin is not loaded here, so this cannot ask Settings for the value and
 * has to know the shape. tests/run-tests.php asserts every subscript chain in
 * this file names a path FieldRegistry declares, which is the only thing that
 * would have caught the original.
 *
 * No fallback to the flat key. The first version of this fix kept one, and the
 * new rule immediately flagged it — correctly, because a rule cannot tell a
 * deliberate legacy read from the typo it exists to catch. It also protected
 * against nothing: Installer::maybe_upgrade() runs on every load and migrates
 * the shape before an uninstall could ever be reached.
 */
if ( empty( $smart_login_settings['advanced']['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Tables. The last entry is from a superseded design and is dropped for the
// benefit of installations that predate the identity model.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smartlogin_otp" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smartlogin_audit" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smartlogin_identities" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smartlogin_identity_history" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smart_login_external_identities" ); // phpcs:ignore WordPress.DB

// Options. The two secret stores are listed separately from the settings option
// because that is where they live: a sealed secret never round-trips through
// smart_login_settings, so deleting that one leaves them behind. The captcha
// entry is the pre-10.2 location and stays until no install can still hold one.
delete_option( 'smart_login_settings' );
delete_option( 'smart_login_provider_secrets' );
delete_option( 'smart_login_field_secrets' );
delete_option( 'smart_login_captcha_secret' );
delete_option( 'smart_login_db_version' );

// User meta created by the plugin.
$smart_login_meta_keys = array(
	'smartlogin_phone',
	'smartlogin_phone_verified_at',
	'smartlogin_email_verified_at',
	'smartlogin_dob',
	'smartlogin_gender',
	'smartlogin_referral_code',
	'smartlogin_synthetic_email',
	'smartlogin_known_devices',
	'smartlogin_ward_code',
	'smartlogin_shipping_ward_code',
	'_smartlogin_onboarding_seen_at',
	'_smartlogin_onboarding_source',
	'_smartlogin_profile_notice_version',
	'_smartlogin_profile_gate',
	'_smartlogin_pending_contact',
);

foreach ( $smart_login_meta_keys as $smart_login_meta_key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $smart_login_meta_key ) ); // phpcs:ignore WordPress.DB
}

// Scheduled events.
wp_clear_scheduled_hook( 'smart_login_cleanup' );
