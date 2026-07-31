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
		self::migrate_settings_shape();
		update_option( self::DB_VERSION_OPTION, SMART_LOGIN_DB_VERSION );
	}

	/**
	 * Move a flat pre-1.0.1 settings array onto the nested dot-path schema.
	 *
	 * The plugin is unreleased, so this is not for anybody's production site —
	 * it is here so the development installs that already have a working gateway
	 * and OAuth credentials configured do not silently reset to defaults on the
	 * first load after the schema change. Reconfiguring a webhook by hand to
	 * prove a refactor is a bad use of anyone's afternoon.
	 *
	 * Runs once, keyed off the DB version, and is a no-op on a fresh install
	 * because there is no flat key to find.
	 */
	private static function migrate_settings_shape(): void {
		$stored = get_option( Settings::OPTION, null );

		if ( ! is_array( $stored ) || ! $stored ) {
			return;
		}

		$moved = array();

		foreach ( self::legacy_key_map() as $old => $path ) {
			if ( array_key_exists( $old, $stored ) ) {
				$moved[ $path ] = $stored[ $old ];
			}
		}

		if ( ! $moved ) {
			return;
		}

		// update() plants each dot path onto the hydrated current value, so
		// anything the map does not mention keeps its registry default.
		Settings::update( $moved );
	}

	/**
	 * Old flat key => new dot path.
	 *
	 * `require_verification` is deliberately absent: nothing ever read it.
	 *
	 * @return array<string,string>
	 */
	private static function legacy_key_map(): array {
		return array(
			'id_mode'                      => 'identity.mode',
			'default_country_code'         => 'identity.country_code',
			'synthetic_email_domain'       => 'identity.synthetic_domain',
			'min_password_length'          => 'signup.min_password_length',
			'terms_url'                    => 'signup.terms_url',
			'redirect_after_register'      => 'signup.redirect_register',
			'redirect_after_login'         => 'signup.redirect_login',
			'login_max_attempts'           => 'login.max_attempts',
			'login_lockout_minutes'        => 'login.lockout_minutes',
			'login_otp_new_device'         => 'login.otp_new_device',
			'google_enabled'               => 'providers.google.enabled',
			'google_client_id'             => 'providers.google.client_id',
			'zalo_enabled'                 => 'providers.zalo.enabled',
			'zalo_app_id'                  => 'providers.zalo.app_id',
			'provider_auto_link_email'     => 'providers.auto_link_email',
			'otp_length'                   => 'otp.length',
			'otp_ttl'                      => 'otp.ttl',
			'otp_max_attempts'             => 'otp.max_attempts',
			'otp_resend_cooldown'          => 'otp.resend_cooldown',
			'otp_max_per_destination_hour' => 'otp.max_per_destination_hour',
			'otp_max_per_ip_hour'          => 'otp.max_per_ip_hour',
			'webhook_enabled'              => 'sms.enabled',
			'webhook_url'                  => 'sms.url',
			'webhook_method'               => 'sms.method',
			'webhook_content_type'         => 'sms.content_type',
			'webhook_headers'              => 'sms.headers',
			'webhook_body'                 => 'sms.body',
			'webhook_timeout'              => 'sms.timeout',
			'webhook_success_path'         => 'sms.success_path',
			'webhook_success_value'        => 'sms.success_value',
			'webhook_retry'                => 'sms.retry',
			'webhook_idempotency_header'   => 'sms.idempotency_header',
			'email_enabled'                => 'email.enabled',
			'email_from_name'              => 'email.from_name',
			'email_from_address'           => 'email.from_address',
			'email_subject'                => 'email.subject',
			'email_body'                   => 'email.body',
			'email_is_html'                => 'email.is_html',
			'field_email_optional'         => 'profile.email_optional',
			'field_dob'                    => 'profile.dob',
			'field_gender'                 => 'profile.gender',
			'address_enabled'              => 'address.enabled',
			'address_required_in_profile'  => 'address.required_in_profile',
			'address_hide_postcode'        => 'address.hide_postcode',
			'woo_replace_login_form'       => 'woo.replace_login_form',
			'woo_sync_billing_phone'       => 'woo.sync_billing_phone',
			'woo_relax_billing_email'      => 'woo.relax_billing_email',
			'woo_block_synthetic_emails'   => 'woo.block_synthetic_emails',
			'audit_enabled'                => 'advanced.audit_enabled',
			'audit_retention_days'         => 'advanced.audit_retention_days',
			'otp_retention_days'           => 'advanced.otp_retention_days',
			'dev_mode'                     => 'advanced.dev_mode',
			'delete_data_on_uninstall'     => 'advanced.delete_data_on_uninstall',
		);
	}

	public static function otp_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartlogin_otp';
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartlogin_audit';
	}

	public static function identities_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartlogin_identities';
	}

	public static function identity_history_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartlogin_identity_history';
	}

	/**
	 * Every table definition, keyed by table name.
	 *
	 * Split out of install_tables() so the same SQL can be handed to dbDelta in
	 * dry-run mode by pending_schema_changes().
	 *
	 * @return array<string,string>
	 */
	public static function schema(): array {
		global $wpdb;

		$charset  = $wpdb->get_charset_collate();
		$otp      = self::otp_table();
		$audit    = self::audit_table();
		$identity = self::identities_table();
		$history  = self::identity_history_table();

		// `intent` is what the visitor is trying to do (register|login|recover|
		// add_identity) and `identity_channel` is which namespace the destination
		// belongs to. `transport` is how the code travels (sms|email) — a separate
		// axis, and the column that used to be confusingly called `channel`.
		$sql_otp = "CREATE TABLE {$otp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token CHAR(64) NOT NULL,
			intent VARCHAR(20) NOT NULL,
			identity_channel VARCHAR(32) NOT NULL DEFAULT '',
			transport VARCHAR(20) NOT NULL,
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
			KEY dest_intent (destination(100), intent, consumed_at),
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

		// The authorization index. One row per subject that currently has an
		// owner; UNIQUE KEY subject_owner is what makes ownership single-valued.
		// No email column: an email is an identity in its own right (channel
		// 'email'), not an attribute of another identity. Duplicating it here
		// would recreate the multiple-representations problem this table exists
		// to remove. Provider claims live in meta_json for forensics only.
		$sql_identities = "CREATE TABLE {$identity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(32) NOT NULL,
			subject VARCHAR(191) NOT NULL,
			is_primary TINYINT UNSIGNED NOT NULL DEFAULT 0,
			verified_at DATETIME NOT NULL,
			linked_by VARCHAR(32) NOT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subject_owner (channel, subject),
			KEY user_channel (user_id, channel)
		) {$charset};";

		// The old frame: append-only, never consulted for authentication.
		$sql_identity_history = "CREATE TABLE {$history} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(32) NOT NULL,
			subject VARCHAR(191) NOT NULL,
			event VARCHAR(20) NOT NULL,
			reason VARCHAR(64) NULL,
			actor VARCHAR(32) NOT NULL,
			occurred_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY subject_lookup (channel, subject),
			KEY user_id (user_id),
			KEY event_occurred (event, occurred_at)
		) {$charset};";

		return array(
			$otp      => $sql_otp,
			$audit    => $sql_audit,
			$identity => $sql_identities,
			$history  => $sql_identity_history,
		);
	}

	private static function install_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::recreate_renamed_tables();

		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}

		self::drop_legacy_tables();
	}

	/**
	 * Drop tables whose columns were renamed, so dbDelta can rebuild them.
	 *
	 * dbDelta only ever adds columns. It cannot rename `purpose` to `intent` or
	 * `channel` to `transport`, so without this the old NOT NULL columns would
	 * survive with no default and every insert would fail.
	 *
	 * The OTP table is safe to drop: it holds nothing but unexpired one-time
	 * codes, which live for minutes. The worst outcome is a visitor mid-flow
	 * during an upgrade having to request a new code.
	 */
	private static function recreate_renamed_tables(): void {
		global $wpdb;

		// Version 4 renamed the OTP columns; anything at or above it is fine.
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= 4 ) {
			return;
		}

		$otp     = self::otp_table();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$otp}", 0 ); // phpcs:ignore WordPress.DB

		if ( is_array( $columns ) && in_array( 'purpose', $columns, true ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$otp}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * What dbDelta would still change, without changing it.
	 *
	 * A healthy installed schema returns an empty array. The integration gate
	 * asserts that, which turns "dbDelta is idempotent" from an assumption into a
	 * measurement — the failure mode being guarded against is a definition
	 * dbDelta cannot match, causing an ALTER TABLE on every single request.
	 *
	 * @return array<string,string>
	 */
	public static function pending_schema_changes(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$pending = array();

		foreach ( self::schema() as $sql ) {
			$pending = array_merge( $pending, (array) dbDelta( $sql, false ) );
		}

		return $pending;
	}

	/**
	 * Remove tables from superseded designs.
	 *
	 * `smart_login_external_identities` held federated identities before they
	 * stopped being a special case. DROP IF EXISTS makes this safe to run on
	 * every activation, and safe on installs that never had the table.
	 *
	 * This method is the one legitimate reference to that name anywhere in the
	 * plugin, which is why tests/identity/run-fitness-tests.php allowlists this
	 * file. Delete both once no installation can still be carrying the table.
	 */
	private static function drop_legacy_tables(): void {
		global $wpdb;

		$legacy = $wpdb->prefix . 'smart_login_external_identities';

		$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB
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
