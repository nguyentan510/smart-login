<?php
/**
 * Table creation, upgrades and scheduled maintenance.
 *
 * @package OmniWP
 */

namespace OmniWP;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION_OPTION = 'OMNIWP_db_version';

	/**
	 * Where an upgrade leaves a message it could not act on by itself.
	 *
	 * Not a transient. A notice that expires is a notice nobody read, and the
	 * configurations this records are ones a human has to decide about.
	 */
	const MIGRATION_NOTICE_OPTION = 'OMNIWP_migration_notices';
	const CLEANUP_HOOK            = 'OMNIWP_cleanup';

	public static function activate(): void {
		self::migrate_legacy_data();
		self::install_tables();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}

		update_option( self::DB_VERSION_OPTION, OMNIWP_DB_VERSION );

		// Endpoints registered by the Woo integration need fresh rewrite rules.
		set_transient( 'OMNIWP_flush_rewrite', 1, MINUTE_IN_SECONDS );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load; cheap option read, only does work after an upgrade.
	 *
	 * Phase 15 deleted every migration this plugin had: each was written for the
	 * development installs that existed when its change landed, ran on one machine, and
	 * was never exercised by a fresh install — which is what 14.5's cursor defect was
	 * worth. The comment that replaced them said the next one goes here, in the same
	 * commit as the change that needs it, deliberately and in writing.
	 *
	 * This is that one. Dropping a login provider leaves two pieces of stored state that
	 * no screen can reach any more: its block in the settings option, and its secret
	 * sealed in the provider secret store. A secret with no interface left to clear it
	 * is a liability that outlives the feature it belonged to.
	 *
	 * Identity rows are deliberately **not** touched. They are customer data, deleting
	 * them is not reversible, and an account whose only identity came from the removed
	 * provider needs a human decision rather than an upgrade hook that runs while
	 * nobody is watching.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === OMNIWP_DB_VERSION ) {
			return;
		}

		self::install_tables();
		self::forget_unshipped_providers();
		self::migrate_automation_delivery();
		update_option( self::DB_VERSION_OPTION, OMNIWP_DB_VERSION );
	}

	/**
	 * Migrate legacy Smart Login options and database tables to OmniWP.
	 */
	public static function migrate_legacy_data(): void {
		global $wpdb;

		// 1. Migrate settings option if omniwp_settings does not exist yet.
		if ( false === get_option( Settings::OPTION, false ) ) {
			$legacy = get_option( 'smart_login_settings', false );
			if ( false === $legacy ) {
				$legacy = get_option( 'smart_login_options', false );
			}

			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				add_option( Settings::OPTION, array_merge( Settings::defaults(), $legacy ) );
			}
		}

		// 2. Migrate legacy database tables wp_sl_* to wp_ow_*
		$table_pairs = array(
			'sl_otp'              => 'ow_otp',
			'sl_audit'            => 'ow_audit',
			'sl_identities'       => 'ow_identities',
			'sl_identity_history' => 'ow_identity_history',
			'sl_addresses'        => 'ow_addresses',
		);

		foreach ( $table_pairs as $old_suffix => $new_suffix ) {
			$old_table = $wpdb->prefix . $old_suffix;
			$new_table = $wpdb->prefix . $new_suffix;

			$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
			$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;

			if ( $old_exists && ! $new_exists ) {
				$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" );
			}
		}
	}

	/**
	 * Carry an automation-routed site onto the signed provider.
	 *
	 * The second migration this plugin has, and the first one that cannot be
	 * skipped. 10.1 shipped with defaults reproducing existing behaviour byte for
	 * byte, so an install that ignored the upgrade was still correct. Phase 20
	 * deletes two settings that sites have deliberately set, and an install that
	 * ignored this one would stop delivering codes without saying anything.
	 *
	 * Reads the stored option directly rather than through `Settings::get()`. By
	 * the time this runs on most installs, 20.1 will have removed
	 * `delivery.route_*` from the registry, and a hydrated read drops paths the
	 * registry does not declare — so the hydrated value of the very setting this
	 * function exists to read would be empty.
	 *
	 * Idempotent by inspection rather than by the version guard alone: a support
	 * script, a second activation or a restored database can all call it twice,
	 * and the guard only covers the path through `maybe_upgrade()`.
	 *
	 * Deliberately **not** destructive. `automation.url` and `automation.secret`
	 * stay exactly where they are — 20.4 decides the bus role, and erasing a
	 * source before the destination has been used in anger is unrecoverable.
	 */
	public static function migrate_automation_delivery(): void {
		$settings = get_option( Settings::OPTION, array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$notices  = array();
		$endpoint = trim( (string) ( $settings['automation']['url'] ?? '' ) );

		if ( 'automation' === (string) ( $settings['delivery']['route_phone'] ?? '' ) && '' === $endpoint ) {
			// Routed at an endpoint that was never configured, which is the state
			// the reporting install was in. There is nothing to move, and moving
			// nothing is not harmless: it would point the provider at a signed
			// endpoint that does not exist *and* overwrite the gateway the
			// administrator had configured on the way past. That is the failure
			// `Installer::cleanup()`'s flat retention keys already cost this
			// project once — a hook rewriting a setting it was not asked about.
			$notices['route_phone'] = __(
				'Smart Login: cài đặt <code>delivery.route_phone</code> đang trỏ tới endpoint automation nhưng endpoint đó chưa được cấu hình, nên mã gửi tới số điện thoại chưa từng đi được. Cách định tuyến này không còn nữa — hãy vào tab Kênh SMS và chọn nhà cung cấp.',
				'omniwp'
			);
		} elseif ( 'automation' === (string) ( $settings['delivery']['route_phone'] ?? '' )
			&& GatewayPresets::ENVELOPE_SIGNED !== (string) ( $settings['sms']['preset'] ?? '' ) ) {

			$settings['sms']['preset']     = GatewayPresets::ENVELOPE_SIGNED;
			$settings['sms']['signed_url'] = $endpoint;
			// Left on rather than merely configured: the channel was delivering
			// before the upgrade, and arriving switched off would be the silent
			// failure this whole sub-phase exists to prevent.
			$settings['sms']['enabled'] = 1;

			update_option( Settings::OPTION, $settings );

			// Through the secret store on both sides. `absorb_secret_fields()`
			// prunes plaintext out of the option array, so copying the raw block
			// would faithfully copy an absence.
			$secret = Settings::read_secret( 'automation.secret' );

			if ( '' !== $secret ) {
				Settings::store_secret( 'sms.signed_secret', $secret );
			}
		}

		if ( 'automation' === (string) ( $settings['delivery']['route_email'] ?? '' ) ) {
			// Named, not summarised. There is no email-side equivalent of the
			// signed provider — an external email sender was deferred in
			// delivery-routing.md D5 and this phase does not add one — so the site
			// loses a capability. Choosing a replacement is not an upgrade hook's
			// decision; saying which setting stopped meaning anything is.
			$notices['route_email'] = __(
				'Smart Login: cài đặt <code>delivery.route_email</code> đang gửi mã email qua endpoint automation, và cách gửi này không còn nữa. Mã gửi tới email sẽ đi qua <code>wp_mail()</code>. Nếu bạn cần một bên thứ ba gửi email, hãy cấu hình một plugin SMTP.',
				'omniwp'
			);
		}

		if ( $notices ) {
			update_option(
				self::MIGRATION_NOTICE_OPTION,
				$notices + (array) get_option( self::MIGRATION_NOTICE_OPTION, array() )
			);
		}
	}

	/**
	 * Drop stored state belonging to any provider this plugin no longer ships.
	 *
	 * Asks `ProviderCredentials` what is shipped rather than naming what was removed.
	 * A cleanup that names the provider it was written for is correct exactly once and
	 * then becomes a line nobody dares delete; this one is correct for the next removal
	 * too, and it needs no edit to be.
	 */
	private static function forget_unshipped_providers(): void {
		$shipped  = array_keys( \OmniWP\Auth\Providers\ProviderCredentials::PROVIDERS );
		$settings = get_option( Settings::OPTION, array() );
		$blocks   = is_array( $settings ) && isset( $settings['providers'] ) && is_array( $settings['providers'] )
			? $settings['providers']
			: array();
		$changed  = false;

		foreach ( $blocks as $key => $value ) {
			// Only a provider's own block, which is an array. `auto_link_email` is a
			// scalar policy that lives alongside them and belongs to no provider.
			if ( ! is_array( $value ) || in_array( $key, $shipped, true ) ) {
				continue;
			}

			\OmniWP\Security\SecretBox::forget( \OmniWP\Auth\Providers\ProviderCredentials::SECRET_OPTION, (string) $key );
			unset( $settings['providers'][ $key ] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( Settings::OPTION, $settings );
		}
	}

	public static function otp_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'OmniWP_otp';
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'OmniWP_audit';
	}

	public static function identities_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'OmniWP_identities';
	}

	public static function identity_history_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'OmniWP_identity_history';
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
			KEY ip_created (ip, created_at),
			KEY created_at (created_at)
		) {$charset};";

		// `created_at` is not redundant against `ip_created`. MySQL can only use
		// a composite index from its leftmost column, so a site-wide count —
		// WHERE created_at > ? with no IP predicate — cannot use (ip, created_at)
		// and would table-scan. RateLimiter runs that count on every send.

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

		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
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
	 * Daily housekeeping: drop spent OTP rows and stale audit entries.
	 */
	public static function cleanup(): void {
		global $wpdb;

		// Dot paths. These read `otp_retention_days` and `audit_retention_days`
		// until 9.9 — flat keys the settings rewrite had renamed two hundred lines
		// above, in the migration map at self::migrate_flat_settings(). Settings
		// resolves by dot path, so both reads missed, both fell back to the
		// literal, and the retention an operator configured had never once taken
		// effect. Rule 8 in tests/security/run-abuse-tests.php now fails the build
		// on any settings key the registry does not declare.
		$otp_days   = max( 1, Settings::get_int( 'advanced.otp_retention_days', 7 ) );
		$audit_days = max( 1, Settings::get_int( 'advanced.audit_retention_days', 90 ) );

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
