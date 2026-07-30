<?php
/**
 * Table creation, upgrades and scheduled maintenance.
 *
 * @package SmartLogin
 */

namespace SmartLogin;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION_OPTION = 'smart_login_db_version';
	const CLEANUP_HOOK      = 'smart_login_cleanup';

	public static function activate(): void {
		self::install_tables();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}

		update_option( self::DB_VERSION_OPTION, SMART_LOGIN_DB_VERSION );

		// Endpoints registered by the Woo integration need fresh rewrite rules.
		set_transient( 'smart_login_flush_rewrite', 1, MINUTE_IN_SECONDS );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load; cheap option read, only does work after an upgrade.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === SMART_LOGIN_DB_VERSION ) {
			return;
		}

		self::install_tables();
		update_option( self::DB_VERSION_OPTION, SMART_LOGIN_DB_VERSION );
	}

	public static function otp_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartlogin_otp';
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartlogin_audit';
	}

	public static function external_identities_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smart_login_external_identities';
	}

	private static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$otp     = self::otp_table();
		$audit   = self::audit_table();
		$external = self::external_identities_table();

		$sql_otp = "CREATE TABLE {$otp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token CHAR(64) NOT NULL,
			purpose VARCHAR(20) NOT NULL,
			channel VARCHAR(10) NOT NULL,
			destination VARCHAR(191) NOT NULL,
			code_hash CHAR(64) NOT NULL,
			payload LONGTEXT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			resend_of BIGINT UNSIGNED NULL,
			ip VARBINARY(16) NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			consumed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY dest_purpose (destination(100), purpose, consumed_at),
			KEY expires_at (expires_at),
			KEY ip_created (ip, created_at)
		) {$charset};";

		$sql_audit = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(40) NOT NULL,
			identity_masked VARCHAR(64) NULL,
			ip VARBINARY(16) NULL,
			user_agent_hash CHAR(40) NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_created (event, created_at),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};";

		$sql_external = "CREATE TABLE {$external} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider VARCHAR(32) NOT NULL,
			provider_subject VARCHAR(191) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(191) NULL,
			email_verified TINYINT UNSIGNED NOT NULL DEFAULT 0,
			profile_json LONGTEXT NULL,
			linked_by VARCHAR(32) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_subject (provider, provider_subject),
			KEY user_id (user_id),
			KEY email (email)
		) {$charset};";

		dbDelta( $sql_otp );
		dbDelta( $sql_audit );
		dbDelta( $sql_external );
	}

	/**
	 * Daily housekeeping: drop spent OTP rows and stale audit entries.
	 */
	public static function cleanup(): void {
		global $wpdb;

		$otp_days   = max( 1, Settings::get_int( 'otp_retention_days', 7 ) );
		$audit_days = max( 1, Settings::get_int( 'audit_retention_days', 90 ) );

		$otp_table   = self::otp_table();
		$audit_table = self::audit_table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$otp_table} WHERE expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL
				gmdate( 'Y-m-d H:i:s', time() - ( $otp_days * DAY_IN_SECONDS ) )
			)
		);

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$audit_table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL
				gmdate( 'Y-m-d H:i:s', time() - ( $audit_days * DAY_IN_SECONDS ) )
			)
		);
	}
}
