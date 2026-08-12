<?php
/**
 * Phase 15 install gate: the whole lifecycle, once, on real ground.
 *
 * Every other gate runs against a site somebody installed by hand months ago, so
 * `activate()` → tables → defaults → first use → `uninstall.php` has never executed
 * end to end in one run. That is the path every future site takes and the only one
 * nothing covers.
 *
 * The final assertion is the reason this exists: after uninstall, **no option, table
 * or user meta carrying this plugin's prefixes may survive**. A query rather than a
 * list of names — a list has to be kept in step with the code, which is precisely the
 * mistake it would be there to catch. It already has one: 14.5 added
 * `OMNIWP_email_backfill_cursor` and never added it to `uninstall.php`.
 *
 * DESTRUCTIVE. It uninstalls the plugin twice: once to reach clean ground and once as
 * the subject.
 *
 * TWO locks, and the second exists because the first was not enough. The env flag only
 * ever asks the operator whether they meant it — and on the day this gate was written
 * its operator ran it five times against a site somebody was configuring in a browser,
 * wiping the settings option each time. The person filling in the form watched their
 * work vanish and reported a save bug. It was not a save bug; it was this.
 *
 * So the gate also refuses a site whose settings differ from the registry defaults. A
 * configured site is somebody's work, and a test must not be able to destroy it because
 * a flag was left set.
 *
 * Mirrors run-wordpress-gate.php's contract: BLOCKED for an environment problem,
 * FAILED for a defect, OK plus facts on success.
 *
 * @package OmniWP
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'OMNIWP_WP_ROOT' ), "\\/" );
$db_host     = (string) getenv( 'OMNIWP_DB_HOST' );
$db_name     = (string) getenv( 'OMNIWP_DB_NAME' );
$db_user     = (string) getenv( 'OMNIWP_DB_USER' );
$db_pass     = (string) getenv( 'OMNIWP_DB_PASSWORD' );
$prefix      = (string) getenv( 'OMNIWP_DB_PREFIX' );
$plugin_root = rtrim( (string) getenv( 'OMNIWP_PLUGIN_ROOT' ), "\\/" );
$destructive = '1' === (string) getenv( 'OMNIWP_DESTRUCTIVE_OK' );

// `$ow_plugin`, not `$plugin`: wp-settings.php uses $plugin as a loop variable.
$ow_plugin = $plugin_root . DIRECTORY_SEPARATOR . 'omniwp.php';

$blocked = static function ( string $message ): never {
	echo "OMNIWP_INSTALL_GATE_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$failures = array();

$fail = static function ( string $message ) use ( &$failures ): void {
	$failures[] = $message;
};

$ok = static function ( string $label ): void {
	echo '  ok    ' . $label . "\n";
};

if ( ! $destructive ) {
	$blocked( 'this gate drops the plugin\'s tables and options; set OMNIWP_DESTRUCTIVE_OK=1 to allow it' );
}

$force = '1' === (string) getenv( 'OMNIWP_DISCARD_SETTINGS' );
if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'OMNIWP_WP_ROOT must point to a WordPress public root' );
}
if ( '' === $plugin_root || ! is_file( $ow_plugin ) ) {
	$blocked( 'OMNIWP_PLUGIN_ROOT must point to the current plugin source' );
}
if ( '' === $db_host || '' === $db_name || '' === $db_user ) {
	$blocked( 'OMNIWP_DB_HOST, OMNIWP_DB_NAME and OMNIWP_DB_USER are required' );
}

$prefix = '' === $prefix ? 'wp_' : $prefix;

if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'OMNIWP_DB_PREFIX contains unsupported characters' );
}

define( 'ABSPATH', $wp_root . DIRECTORY_SEPARATOR );
define( 'DB_NAME', $db_name );
define( 'DB_USER', $db_user );
define( 'DB_PASSWORD', $db_pass );
define( 'DB_HOST', $db_host );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', false );
define( 'WP_PLUGIN_DIR', dirname( $plugin_root ) );
define( 'WP_PLUGIN_URL', 'https://example.test/wp-content/plugins' );
$table_prefix = $prefix;

try {
	require $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php';
} catch ( Throwable $exception ) {
	$blocked( 'WordPress bootstrap failed: ' . $exception->getMessage() );
}

if ( ! class_exists( 'OmniWP\\Installer' ) ) {
	require_once $ow_plugin;
}

global $wpdb;

if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
	$blocked( 'WordPress did not initialise wpdb' );
}

/*
 * The second lock: a site somebody has configured is not a test fixture.
 *
 * Compared against the registry defaults rather than a marker option, because a marker
 * is one more thing to remember and this has to hold for a site nobody remembered
 * anything about.
 */
$configured = array();

foreach ( \OmniWP\FieldRegistry::all() as $ow_path => $ow_field ) {
	if ( in_array( $ow_field['type'] ?? '', array( 'secret', 'passthrough' ), true ) ) {
		continue;
	}

	if ( null === $ow_field['default'] ) {
		continue;
	}

	// Loose: the form posts strings and the registry declares scalars.
	if ( \OmniWP\Settings::get( $ow_path ) != $ow_field['default'] ) { // phpcs:ignore WordPress.PHP.StrictComparisons
		$configured[] = $ow_path;
	}
}

if ( $configured && ! $force ) {
	echo "OMNIWP_INSTALL_GATE_BLOCKED\n";
	echo 'reason=this site has settings that differ from the defaults and this gate would destroy them: '
		. implode( ', ', array_slice( $configured, 0, 5 ) )
		. ( count( $configured ) > 5 ? ' (+' . ( count( $configured ) - 5 ) . ' more)' : '' )
		. '. Point the gate at a scratch site, or set OMNIWP_DISCARD_SETTINGS=1 if losing them is intended.'
		. "\n";
	exit( 2 );
}

echo 'Phase 15 — the install lifecycle, against WordPress ' . get_bloginfo( 'version' ) . "\n\n";

/**
 * Everything this plugin owns that is still in the database.
 *
 * Prefix-matched rather than enumerated. `OmniWP_` and `OMNIWP_` are both in
 * use — the tables and user meta chose one, the options the other — and a rule that
 * knew only one of them would pass while half the data survived.
 *
 * @return array{tables:string[],options:string[],meta:string[]}
 */
$survey = static function () use ( $wpdb ): array {
	$like = $wpdb->esc_like( $wpdb->prefix ) . '%OmniWP%';

	return array(
		'tables'  => (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) // phpcs:ignore WordPress.DB.PreparedSQL
		),
		'options' => (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'smart\\_login\\_%'
			    OR option_name LIKE 'OmniWP\\_%'
			 ORDER BY option_name" // phpcs:ignore WordPress.DB.PreparedSQL
		),
		'meta'    => (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
			 WHERE meta_key LIKE '%OmniWP\\_%'
			 ORDER BY meta_key" // phpcs:ignore WordPress.DB.PreparedSQL
		),
	);
};

/**
 * Run uninstall.php the way WordPress runs it, opt-in included.
 */
$uninstall = static function () use ( $plugin_root ): void {
	$settings = get_option( 'OMNIWP_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$settings['advanced']['delete_data_on_uninstall'] = 1;
	update_option( 'OMNIWP_settings', $settings );

	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'omniwp/omniwp.php' );
	}

	require $plugin_root . DIRECTORY_SEPARATOR . 'uninstall.php';
};

try {
	// -----------------------------------------------------------------
	// Clean ground. Uninstall is used as setup here and as the subject below.
	// -----------------------------------------------------------------
	$before = $survey();
	echo '  note  before: ' . count( $before['tables'] ) . ' table(s), '
		. count( $before['options'] ) . ' option(s), '
		. count( $before['meta'] ) . " meta key(s)\n";

	$uninstall();
	wp_cache_flush();

	// -----------------------------------------------------------------
	// 1. Install.
	// -----------------------------------------------------------------
	\OmniWP\Installer::activate();
	wp_cache_flush();

	$expected_tables = array(
		\OmniWP\Installer::otp_table(),
		\OmniWP\Installer::audit_table(),
		\OmniWP\Installer::identities_table(),
		\OmniWP\Installer::identity_history_table(),
	);

	$installed = $survey();

	foreach ( $expected_tables as $table ) {
		if ( ! in_array( $table, $installed['tables'], true ) ) {
			$fail( 'activate() did not create ' . $table );
		}
	}

	if ( ! $failures ) {
		$ok( 'activate() creates all four tables on empty ground' );
	}

	$pending = \OmniWP\Installer::pending_schema_changes();

	if ( $pending ) {
		$fail( 'dbDelta still wants changes on a fresh install: ' . wp_json_encode( $pending ) );
	} else {
		$ok( 'dbDelta is idempotent immediately after activate()' );
	}

	$stored_version = (string) get_option( \OmniWP\Installer::DB_VERSION_OPTION );

	if ( (string) OMNIWP_DB_VERSION !== $stored_version ) {
		$fail( 'db_version is ' . $stored_version . ' after activate(), expected ' . OMNIWP_DB_VERSION );
	} else {
		$ok( 'activate() records db_version ' . $stored_version );
	}

	$settings = get_option( 'OMNIWP_settings', null );

	if ( ! is_array( $settings ) || ! $settings ) {
		$fail( 'activate() left no settings option behind' );
	} else {
		$ok( 'activate() seeds the settings option' );
	}

	/*
	 * A fresh install must answer for every field the registry declares.
	 *
	 * Asserted with a sentinel rather than against null. The first version of this
	 * rule required a non-null value and went red on `channels.enabled`, whose default
	 * is null **on purpose** — `ChannelRegistry` reads it and derives the enabled set
	 * from `identity.mode` when it finds nothing. The property that matters is that the
	 * path resolves at all, so `Settings::get()` never reaches its fallback.
	 */
	$sentinel         = '__ow_install_gate_unset__';
	$missing_defaults = array();

	foreach ( \OmniWP\FieldRegistry::all() as $path => $field ) {
		/*
		 * A declared null default is skipped, because the settings layer cannot express
		 * the difference. `Settings::get()` substitutes the caller's fallback whenever
		 * the hydrated value is null, so a field declared null is indistinguishable
		 * from a path that does not exist — and `channels.enabled` is declared null on
		 * purpose, for `ChannelRegistry` to derive from `identity.mode` instead.
		 *
		 * The gap that leaves — a typo'd path reading as a null default — is covered
		 * from the other side by the abuse suite's rule 8, which fails on any
		 * `Settings::get()` naming a key `FieldRegistry` does not declare.
		 */
		if ( null === $field['default'] ) {
			continue;
		}

		if ( $sentinel === \OmniWP\Settings::get( $path, $sentinel ) ) {
			$missing_defaults[] = $path;
		}
	}

	if ( $missing_defaults ) {
		$fail( 'settings paths resolve to null on a fresh install: ' . implode( ', ', array_slice( $missing_defaults, 0, 5 ) ) );
	} else {
		$ok( 'every declared settings path resolves on a fresh install' );
	}

	// -----------------------------------------------------------------
	// 2. Use it, so every table and option that gets written has been written.
	// -----------------------------------------------------------------
	$user_login = 'ow_install_' . strtolower( wp_generate_password( 8, false, false ) );
	$user_id    = wp_insert_user(
		array(
			'user_login' => $user_login,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $user_login . '@example.test',
			'role'       => 'subscriber',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		$blocked( 'could not create the install-gate fixture user' );
	}

	$repository = new \OmniWP\Identity\IdentityRepository();
	$claim      = \OmniWP\Identity\Claim::canonical( 'email', $user_login . '@example.test' );

	if ( ! $repository->claim(
		\OmniWP\Identity\IdentityRecord::create(
			(int) $user_id,
			\OmniWP\Identity\VerifiedClaim::from( $claim, \OmniWP\Identity\VerifiedClaim::PROOF_OTP ),
			\OmniWP\Identity\IdentityRecord::BY_REGISTRATION,
			true
		)
	) ) {
		$fail( 'a fresh install could not claim an identity' );
	} else {
		$ok( 'the identities table accepts a claim on a fresh install' );
	}

	update_user_meta( (int) $user_id, \OmniWP\Identity\UserManager::META_PHONE, '84969789475' );
	update_user_meta( (int) $user_id, \OmniWP\Identity\UserManager::META_EMAIL_VERIFIED, current_time( 'mysql', true ) );

	$otp = ( new \OmniWP\OTP\OtpService() )->issue(
		$user_login . '@example.test',
		\OmniWP\OTP\OtpService::INTENT_LOGIN,
		array( 'user_id' => (int) $user_id )
	);

	if ( is_wp_error( $otp ) ) {
		// Not a failure: delivery depends on a configured transport, and this gate is
		// about storage. Reported so the run says what it did and did not exercise.
		echo '  note  OTP issue returned ' . $otp->get_error_code() . " — storage exercised through the audit log only\n";
	} else {
		$ok( 'a fresh install issues an OTP' );
	}

	\OmniWP\Security\AuditLog::record(
		\OmniWP\Security\AuditLog::LOGIN_SUCCESS,
		'install-gate',
		array( 'context' => 'install_gate' ),
		(int) $user_id
	);

	$used = $survey();
	echo '  note  in use: ' . count( $used['tables'] ) . ' table(s), '
		. count( $used['options'] ) . ' option(s), '
		. count( $used['meta'] ) . " meta key(s)\n";

	// -----------------------------------------------------------------
	// 3. Uninstall, and account for every trace.
	// -----------------------------------------------------------------
	//
	// The fixture user goes first. Deleting it afterwards fires 14.7's `deleted_user`
	// hook against tables uninstall.php has just dropped — which prints a WordPress
	// database error and tells us nothing, because on a real site the hook is not
	// registered when the plugin is being removed.
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $user_id );

	$uninstall();
	wp_cache_flush();

	$after = $survey();

	if ( $after['tables'] ) {
		$fail( 'tables survived uninstall: ' . implode( ', ', $after['tables'] ) );
	} else {
		$ok( 'no table survives uninstall' );
	}

	if ( $after['options'] ) {
		$fail( 'options survived uninstall: ' . implode( ', ', $after['options'] ) );
	} else {
		$ok( 'no option survives uninstall' );
	}

	if ( $after['meta'] ) {
		$fail( 'user meta survived uninstall: ' . implode( ', ', $after['meta'] ) );
	} else {
		$ok( 'no user meta survives uninstall' );
	}

	// Leave the site installed. A gate that ends with the plugin uninstalled would
	// make every other gate BLOCKED on the next run.
	\OmniWP\Installer::activate();
} catch ( Throwable $exception ) {
	echo "OMNIWP_INSTALL_GATE_FAILED\n";
	echo 'reason=install gate raised an exception: ' . $exception->getMessage() . "\n";
	exit( 1 );
}

if ( $failures ) {
	echo "OMNIWP_INSTALL_GATE_FAILED\n";

	foreach ( $failures as $message ) {
		echo 'reason=' . $message . "\n";
	}

	exit( 1 );
}

echo "\nOMNIWP_INSTALL_GATE_OK\n";
echo 'wordpress=' . get_bloginfo( 'version' ) . "\n";
echo 'db_version=' . get_option( \OmniWP\Installer::DB_VERSION_OPTION ) . "\n";
