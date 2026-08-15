<?php
/**
 * Uninstall routine. Only destroys data when the admin explicitly opted in.
 *
 * @package OmniWP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$omniwp_settings = get_option( 'OMNIWP_settings', array() );

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
if ( empty( $omniwp_settings['advanced']['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}OmniWP_otp" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}OmniWP_audit" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}OmniWP_identities" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}OmniWP_identity_history" ); // phpcs:ignore WordPress.DB

// Options. The two secret stores are listed separately from the settings option
// because that is where they live: a sealed secret never round-trips through
// OMNIWP_settings, so deleting that one leaves them behind. The captcha
// entry is the pre-10.2 location and stays until no install can still hold one.
delete_option( 'OMNIWP_settings' );
delete_option( 'OMNIWP_provider_secrets' );
delete_option( 'OMNIWP_field_secrets' );
delete_option( 'OMNIWP_captcha_secret' );
delete_option( 'OMNIWP_db_version' );
// The page cache AccountForm::shortcode_page_url() writes. Missing here since Phase 8,
// and found by the install gate's after-uninstall survey rather than by reading: the
// rule asks the database what survived instead of asking the code what it wrote.
delete_option( 'OMNIWP_account_page' );

// User meta created by the plugin.
$omniwp_meta_keys = array(
	'OmniWP_phone',
	'OmniWP_phone_verified_at',
	'OmniWP_email_verified_at',
	'OmniWP_dob',
	'OmniWP_gender',
	'OmniWP_referral_code',
	'OmniWP_synthetic_email',
	'OmniWP_known_devices',
	'OmniWP_ward_code',
	'OmniWP_shipping_ward_code',
	'_OmniWP_onboarding_seen_at',
	'_OmniWP_onboarding_source',
	'_OmniWP_profile_notice_version',
	'_OmniWP_profile_gate',
	'_OmniWP_pending_contact',
);

foreach ( $omniwp_meta_keys as $omniwp_meta_key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $omniwp_meta_key ) ); // phpcs:ignore WordPress.DB
}

// Scheduled events.
wp_clear_scheduled_hook( 'OMNIWP_cleanup' );
