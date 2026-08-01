<?php
/**
 * Phase 9 qualification gate: the abuse boundary against a real WordPress.
 *
 * The pure suite drives every control in this phase, but it drives them through
 * a stub `$wpdb` that never parses SQL and a stub option layer that never
 * touches MySQL. Three things therefore remain unproven by it, and all three are
 * exactly the kind this project has been bitten by before:
 *
 *   - `KEY created_at` actually exists after dbDelta, and dbDelta is idempotent
 *     with it present — a definition dbDelta cannot match causes an ALTER TABLE
 *     on every request, silently.
 *   - `OtpRepository::count_recent_all()` is real SQL that has never executed.
 *   - The `security` tab's fields render, and the readiness rows do not fatal.
 *
 * Mirrors run-wordpress-gate.php's contract: BLOCKED for an environment
 * problem, FAILED for a defect, OK plus facts on success.
 *
 * @package SmartLogin
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'SMART_LOGIN_WP_ROOT' ), "\\/" );
$db_host     = (string) getenv( 'SMART_LOGIN_DB_HOST' );
$db_name     = (string) getenv( 'SMART_LOGIN_DB_NAME' );
$db_user     = (string) getenv( 'SMART_LOGIN_DB_USER' );
$db_pass     = (string) getenv( 'SMART_LOGIN_DB_PASSWORD' );
$prefix      = (string) getenv( 'SMART_LOGIN_DB_PREFIX' );
$plugin_root = rtrim( (string) getenv( 'SMART_LOGIN_PLUGIN_ROOT' ), "\\/" );

// `$sl_plugin`, not `$plugin`: wp-settings.php uses $plugin as a loop variable
// and unset()s it. See the note in run-wordpress-gate.php.
$sl_plugin = $plugin_root . DIRECTORY_SEPARATOR . 'smart-login.php';

$blocked = static function ( string $message ): never {
	echo "SMART_LOGIN_ABUSE_GATE_BLOCKED\n";
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

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'SMART_LOGIN_WP_ROOT must point to a WordPress public root' );
}
if ( '' === $plugin_root || ! is_file( $sl_plugin ) ) {
	$blocked( 'SMART_LOGIN_PLUGIN_ROOT must point to the current plugin source' );
}
if ( '' === $db_host || '' === $db_name || '' === $db_user ) {
	$blocked( 'SMART_LOGIN_DB_HOST, SMART_LOGIN_DB_NAME and SMART_LOGIN_DB_USER are required' );
}

$prefix = '' === $prefix ? 'wp_' : $prefix;

if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'SMART_LOGIN_DB_PREFIX contains unsupported characters' );
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

if ( ! class_exists( 'SmartLogin\\Installer' ) ) {
	require_once $sl_plugin;
}

global $wpdb;

if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
	$blocked( 'WordPress did not initialise wpdb' );
}

\SmartLogin\Installer::maybe_upgrade();

echo "Phase 9 — abuse boundary, against WordPress " . get_bloginfo( 'version' ) . "\n\n";

// ---------------------------------------------------------------------
echo "9.1 — schema\n";

$otp_table = \SmartLogin\Installer::otp_table();
$indexes   = $wpdb->get_results( "SHOW INDEX FROM {$otp_table}", ARRAY_A ); // phpcs:ignore WordPress.DB
$by_name   = array();

foreach ( (array) $indexes as $row ) {
	$by_name[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
}

if ( ! isset( $by_name['created_at'] ) ) {
	$fail( 'the OTP table has no KEY created_at — the site-wide count will table-scan on every send' );
} else {
	$ok( 'KEY created_at exists (' . implode( ',', $by_name['created_at'] ) . ')' );
}

// The claim made in the schema comment: a composite index cannot serve a
// created_at-only predicate. Asserted by asking MySQL which index it picks.
$explain = $wpdb->get_row( // phpcs:ignore WordPress.DB
	"EXPLAIN SELECT COUNT(*) FROM {$otp_table} WHERE created_at > '2000-01-01 00:00:00'",
	ARRAY_A
);
$chosen  = (string) ( $explain['key'] ?? '' );

if ( 'created_at' === $chosen ) {
	$ok( 'MySQL chooses KEY created_at for the site-wide count' );
} else {
	// Not a failure on a near-empty table: the optimiser may prefer a scan when
	// there is nothing to scan. Reported rather than asserted.
	echo '  note  optimiser chose "' . ( '' === $chosen ? 'none' : $chosen ) . '" — expected on a small table' . "\n";
}

$pending = \SmartLogin\Installer::pending_schema_changes();

if ( $pending ) {
	$fail( 'dbDelta still wants changes after upgrade: ' . wp_json_encode( $pending ) );
} else {
	$ok( 'dbDelta is idempotent with the new index present' );
}

if ( '5' !== (string) get_option( \SmartLogin\Installer::DB_VERSION_OPTION ) ) {
	$fail( 'db_version is ' . get_option( \SmartLogin\Installer::DB_VERSION_OPTION ) . ', expected 5' );
} else {
	$ok( 'db_version is 5' );
}

// ---------------------------------------------------------------------
echo "\n9.1 — count_recent_all() as real SQL\n";

$repo = new \SmartLogin\OTP\OtpRepository();

try {
	$before = $repo->count_recent_all( HOUR_IN_SECONDS );
	$ok( 'count_recent_all() executes, returned ' . $before );
} catch ( Throwable $e ) {
	$fail( 'count_recent_all() threw: ' . $e->getMessage() );
	$before = 0;
}

if ( '' !== (string) $wpdb->last_error ) {
	$fail( 'count_recent_all() left a database error: ' . $wpdb->last_error );
}

// Insert a row and confirm the counter moves. A COUNT that always returns 0
// would pass every stub test and cap nothing at all.
$probe_token = 'gate' . bin2hex( random_bytes( 14 ) );
$probe_id    = $repo->insert(
	array(
		'token'       => $probe_token,
		'intent'      => 'register',
		'transport'   => 'sms',
		'destination' => '84900000000',
		'code_hash'   => str_repeat( 'a', 64 ),
		'payload'     => array(),
		'ip'          => null,
		'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 300 ),
	)
);

if ( ! $probe_id ) {
	$fail( 'could not insert a probe OTP row: ' . $wpdb->last_error );
} else {
	$after = $repo->count_recent_all( HOUR_IN_SECONDS );

	if ( $after === $before + 1 ) {
		$ok( 'the counter moves with a real row (' . $before . ' → ' . $after . ')' );
	} else {
		$fail( 'count_recent_all() did not move: ' . $before . ' → ' . $after );
	}

	$repo->delete( $probe_id );

	if ( $repo->count_recent_all( HOUR_IN_SECONDS ) !== $before ) {
		$fail( 'the probe row was not cleaned up' );
	} else {
		$ok( 'probe row removed, count back to ' . $before );
	}
}

// ---------------------------------------------------------------------
echo "\n9.1 / 9.3 / 9.5 — settings surface\n";

$expected_fields = array(
	'security.max_per_site_hour',
	'security.max_per_site_day',
	'security.halt_minutes',
	'security.max_identify_per_ip_hour',
	'security.breaker_threshold',
	'security.breaker_cooldown',
	'security.trust_proxy',
	'security.trusted_proxy_cidrs',
	'identity.allowed_country_codes',
);

$declared = \SmartLogin\FieldRegistry::all();

foreach ( $expected_fields as $path ) {
	if ( ! isset( $declared[ $path ] ) ) {
		$fail( 'FieldRegistry does not declare ' . $path );
	}
}

$security_tab = \SmartLogin\FieldRegistry::for_tab( 'security' );

if ( count( $security_tab ) < 8 ) {
	$fail( 'the security tab draws only ' . count( $security_tab ) . ' fields' );
} else {
	$ok( 'the security tab draws ' . count( $security_tab ) . ' fields' );
}

// Every field a tab claims must sit under a section that tab renders, or it
// silently vanishes from the form — the defect FieldRegistry exists to prevent.
$sections = \SmartLogin\FieldRegistry::sections();

foreach ( $security_tab as $path => $field ) {
	if ( ! isset( $sections[ $field['section'] ] ) ) {
		$fail( $path . ' names an unknown section: ' . $field['section'] );
	}
}

$ok( 'every security field sits under a declared section' );

// ---------------------------------------------------------------------
echo "\n9.1 / 9.5 — readiness rows execute against the real site\n";

try {
	$checks = ( new \SmartLogin\Admin\Readiness() )->checks();
	$keys   = array_column( $checks, 'key' );

	foreach ( array( 'budget', 'proxy' ) as $needed ) {
		if ( ! in_array( $needed, $keys, true ) ) {
			$fail( 'readiness has no "' . $needed . '" row' );
		}
	}

	foreach ( $checks as $check ) {
		foreach ( array( 'key', 'label', 'status', 'detail', 'action', 'action_label' ) as $field ) {
			if ( ! isset( $check[ $field ] ) ) {
				$fail( 'readiness row ' . ( $check['key'] ?? '?' ) . ' is missing ' . $field );
			}
		}
	}

	$ok( 'readiness renders ' . count( $checks ) . ' well-formed rows including budget and proxy' );
} catch ( Throwable $e ) {
	$fail( 'readiness threw: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------
echo "\n9.5 — in_cidr on this PHP build\n";

$cidr_cases = array(
	array( '173.245.63.255', '173.245.48.0/20', true ),
	array( '173.245.64.0', '173.245.48.0/20', false ),
	array( '2400:cb00:1::5', '2400:cb00::/32', true ),
	array( '2400:cb01::1', '2400:cb00::/32', false ),
	array( '203.0.113.7', '10.0.0.0/oops', false ),
	array( '1.2.3.4', '2400:cb00::/32', false ),
);

$cidr_ok = true;

foreach ( $cidr_cases as $case ) {
	if ( \SmartLogin\Security\Client::in_cidr( $case[0], $case[1] ) !== $case[2] ) {
		$fail( 'in_cidr( ' . $case[0] . ', ' . $case[1] . ' ) is wrong on PHP ' . PHP_VERSION );
		$cidr_ok = false;
	}
}

if ( $cidr_ok ) {
	$ok( 'six CIDR cases agree on PHP ' . PHP_VERSION );
}

// ---------------------------------------------------------------------
echo "\n9.1 — the kill switch, end to end\n";

$halt_before = get_option( \SmartLogin\Security\RateLimiter::HALT_OPTION );

\SmartLogin\Security\RateLimiter::resume();

$limiter = new \SmartLogin\Security\RateLimiter( $repo );

if ( $limiter->halted_for() > 0 ) {
	$fail( 'resume() did not clear the halt option' );
} else {
	$ok( 'resume() clears the halt' );
}

// Drive the real option round trip rather than the stub's array.
update_option( \SmartLogin\Security\RateLimiter::HALT_OPTION, time() + 600, false );

if ( $limiter->halted_for() <= 0 ) {
	$fail( 'a stored halt deadline is not read back' );
} else {
	$ok( 'a stored halt deadline is read back (' . $limiter->halted_for() . 's)' );
}

$refused = $limiter->check_otp_send( '84969789475', 'register' );

if ( ! is_wp_error( $refused ) || 'smart_login_unavailable' !== $refused->get_error_code() ) {
	$fail( 'a halted site still allowed a send' );
} else {
	$ok( 'a halted site refuses with smart_login_unavailable' );
}

\SmartLogin\Security\RateLimiter::resume();

if ( false !== $halt_before ) {
	update_option( \SmartLogin\Security\RateLimiter::HALT_OPTION, $halt_before, false );
}

$ok( 'halt state restored' );

// ---------------------------------------------------------------------
echo "\n";

if ( $failures ) {
	echo "SMART_LOGIN_ABUSE_GATE_FAILED\n";

	foreach ( $failures as $message ) {
		echo 'reason=' . $message . "\n";
	}

	exit( 1 );
}

echo "SMART_LOGIN_ABUSE_GATE_OK\n";
echo 'wordpress=' . get_bloginfo( 'version' ) . "\n";
echo 'php=' . PHP_VERSION . "\n";
echo 'db_version=' . get_option( \SmartLogin\Installer::DB_VERSION_OPTION ) . "\n";
echo 'otp_table=' . $otp_table . "\n";
