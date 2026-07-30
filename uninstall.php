<?php
/**
 * Uninstall routine. Only destroys data when the admin explicitly opted in.
 *
 * @package SmartLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$smart_login_settings = get_option( 'smart_login_settings', array() );

if ( empty( $smart_login_settings['delete_data_on_uninstall'] ) ) {
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

// Options.
delete_option( 'smart_login_settings' );
delete_option( 'smart_login_provider_secrets' );
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
