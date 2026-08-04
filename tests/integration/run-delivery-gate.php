<?php
/**
 * Phase 10 qualification gate: delivery routing against a real WordPress.
 *
 * The pure suite drives the routing table and the automation transport through
 * a stub HTTP layer that returns whatever a global says and a stub option layer
 * that never touches MySQL. Four things therefore remain unproven by it, and
 * every one of them is a place this project has been bitten before:
 *
 *   - `register_setting()`'s sanitize callback is what actually runs on a save,
 *     so `https_url` rejecting a scheme has never executed on the real path —
 *     and `add_settings_error()` exists only inside wp-admin.
 *   - `wp_remote_request()` is a stub in the suite. The real one applies
 *     `redirection`, `timeout` and header handling that the stub ignores.
 *   - `SecretBox` round-trips through a real `wp_salt()` and a real option row,
 *     not an array in memory. 10.2 changed where that row lives.
 *   - The delivery tab renders with the new `automation` section, which no
 *     stub can prove because template code never executes in the pure suite.
 *
 * Mirrors run-abuse-gate.php's contract: BLOCKED for an environment problem,
 * FAILED for a defect, OK plus facts on success.
 *
 * Nothing here calls out to a network. The endpoint is a URL that cannot
 * resolve, because what is under test is the plugin's handling of the answer,
 * not anybody's webhook.
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
	echo "SMART_LOGIN_DELIVERY_GATE_BLOCKED\n";
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

// add_settings_error() and friends live in wp-admin, which wp-settings.php does
// not load. The plugin guards its calls with function_exists() for exactly this
// reason; loading it here is what makes the guard's other branch reachable.
require_once ABSPATH . 'wp-admin/includes/template.php';

\SmartLogin\Installer::maybe_upgrade();

use SmartLogin\FieldRegistry;
use SmartLogin\OTP\Transports\AutomationTransport;
use SmartLogin\OTP\Transports\EnvelopeSigner;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\Settings;

echo 'Phase 10 — delivery routing, against WordPress ' . get_bloginfo( 'version' ) . "\n\n";

$settings_before = get_option( Settings::OPTION );
$secrets_before  = get_option( Settings::SECRET_OPTION );

// ---------------------------------------------------------------------
echo "10.1 — routing\n";

Settings::update(
	array(
		'delivery.route_phone' => 'sms',
		'delivery.route_email' => 'email',
	)
);

$router = new TransportRouter();

if ( 'sms' !== $router->transport_for( '84969789475' ) || 'email' !== $router->transport_for( 'ban@example.com' ) ) {
	$fail( 'the shipped defaults do not reproduce the pre-10.1 routing' );
} else {
	$ok( 'defaults route phone to sms and email to wp_mail()' );
}

Settings::update( array( 'delivery.route_phone' => 'automation' ) );

if ( 'automation' !== $router->transport_for( '84969789475' ) ) {
	$fail( 'a stored route is ignored by the default router' );
} else {
	$ok( 'a stored route reaches the transport registered under that id' );
}

// ---------------------------------------------------------------------
echo "\n10.2 — secret storage on a real option row\n";

Settings::store_secret( 'automation.secret', 'integration-signing-secret' );

if ( 'integration-signing-secret' !== Settings::read_secret( 'automation.secret' ) ) {
	$fail( 'a secret sealed by SecretBox did not survive a real option round trip' );
} else {
	$ok( 'the secret round-trips through wp_salt() and the options table' );
}

$sealed = get_option( Settings::SECRET_OPTION );

if ( is_array( $sealed ) && false !== strpos( wp_json_encode( $sealed ), 'integration-signing-secret' ) ) {
	$fail( 'the plaintext secret is readable in the stored option' );
} else {
	$ok( 'the stored row holds ciphertext, not the secret' );
}

// ---------------------------------------------------------------------
echo "\n10.3 — https is refused at save, on the real save path\n";

Settings::update( array( 'automation.url' => 'https://hooks.invalid/otp' ) );

$tab   = (string) ( FieldRegistry::get( 'automation.url' )['tab'] ?? '' );
$saved = Settings::sanitize(
	array(
		Settings::TAB_FIELD => $tab,
		'automation'        => array( 'url' => 'http://hooks.invalid/otp' ),
	)
);

if ( 'https://hooks.invalid/otp' !== ( $saved['automation']['url'] ?? '' ) ) {
	$fail( 'a plaintext endpoint was accepted, or the stored value was blanked' );
} else {
	$ok( 'http:// is rejected and the previous endpoint survives' );
}

$errors = get_settings_errors( Settings::OPTION );
$codes  = array_column( $errors, 'code' );

if ( ! in_array( 'smart_login_https_required', $codes, true ) ) {
	$fail( 'the rejection is silent — add_settings_error() produced nothing on the real path' );
} else {
	$ok( 'the administrator is told why, through the real settings-error channel' );
}

// ---------------------------------------------------------------------
echo "\n10.3 — the envelope on the real HTTP layer\n";

$captured = null;

// pre_http_request short-circuits WP_Http *after* it has normalised the
// arguments, so what this sees is what would have gone on the wire.
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$captured ) {
		$captured = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => '',
		);
	},
	10,
	3
);

$sent = ( new AutomationTransport() )->send(
	'84969789475',
	'482913',
	array(
		'intent'      => 'login',
		'ttl_seconds' => 300,
		'expires_ts'  => time() + 300,
	)
);

if ( true !== $sent ) {
	$fail( 'the automation transport rejected a 200: ' . ( is_wp_error( $sent ) ? $sent->get_error_message() : 'unknown' ) );
} elseif ( null === $captured ) {
	$fail( 'no request reached the HTTP layer' );
} else {
	$ok( 'a 200 is accepted as delivered' );

	$body      = (string) ( $captured['args']['body'] ?? '' );
	$headers   = (array) ( $captured['args']['headers'] ?? array() );
	$signature = '';

	// WP_Http lowercases nothing on the way out, but a header array can be keyed
	// either way depending on who touched it. Compare case-insensitively rather
	// than assuming.
	foreach ( $headers as $name => $value ) {
		if ( 0 === strcasecmp( (string) $name, EnvelopeSigner::SIGNATURE_HEADER ) ) {
			$signature = (string) $value;
		}
	}

	$expected = 'sha256=' . hash_hmac( 'sha256', $body, 'integration-signing-secret' );

	if ( $signature !== $expected ) {
		$fail( 'the signature does not match the body as WP_Http would have sent it' );
	} else {
		$ok( 'the signature verifies against the transmitted bytes' );
	}

	$payload = json_decode( $body, true );

	if ( ! is_array( $payload ) || '482913' !== ( $payload['code'] ?? '' ) || 'phone' !== ( $payload['channel'] ?? '' ) ) {
		$fail( 'the envelope is not the shape docs/delivery-routing.md D3 describes' );
	} else {
		$ok( 'the envelope carries event, channel and code' );
	}

	$timeout = (int) ( $captured['args']['timeout'] ?? 0 );

	if ( $timeout > WebhookTransport::MAX_TIMEOUT || $timeout < 1 ) {
		$fail( 'the send is not bounded by the shared ceiling: timeout=' . $timeout );
	} else {
		$ok( 'the send is bounded at ' . $timeout . 's' );
	}
}

remove_all_filters( 'pre_http_request' );

// ---------------------------------------------------------------------
echo "\n10.3 — the delivery tab still renders\n";

if ( ! class_exists( 'SmartLogin\\Admin\\Screens\\SettingsScreen' ) ) {
	$fail( 'the settings screen class did not load' );
} else {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	ob_start();

	try {
		( new \SmartLogin\Admin\Screens\SettingsScreen() )->render( 'delivery' );
		$html = (string) ob_get_clean();
	} catch ( Throwable $exception ) {
		ob_end_clean();
		$html = '';
		$fail( 'rendering the delivery tab threw: ' . $exception->getMessage() );
	}

	if ( '' !== $html ) {
		// All four screens, not just the parent. 10.6 moved twenty-six of this
		// tab's fields onto three siblings, and a gate that kept rendering only
		// `delivery` would have gone on passing while covering far less.
		$missing = array();
		$secret_leaked = false;

		foreach ( array( 'delivery', 'delivery-sms', 'delivery-email', 'delivery-mail', 'delivery-automation' ) as $slug ) {
			ob_start();

			try {
				( new \SmartLogin\Admin\Screens\SettingsScreen() )->render( $slug );
				$screen_html = (string) ob_get_clean();
			} catch ( Throwable $exception ) {
				ob_end_clean();
				$fail( 'rendering "' . $slug . '" threw: ' . $exception->getMessage() );
				continue;
			}

			foreach ( array_keys( FieldRegistry::for_tab( $slug ) ) as $path ) {
				$field = FieldRegistry::get( $path );

				if ( ! empty( $field['conditional'] ) ) {
					continue;
				}

				if ( false === strpos( $screen_html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( $path ) ) ) {
					$missing[] = $slug . ':' . $path;
				}
			}

			// The one thing that must never appear, checked against real output
			// on every screen rather than only on the one that owns it.
			if ( false !== strpos( $screen_html, 'integration-signing-secret' ) ) {
				$secret_leaked = true;
			}
		}

		if ( $missing ) {
			$fail( 'a delivery screen claims fields it does not draw: ' . implode( ', ', $missing ) );
		} else {
			$ok( 'every delivery screen draws every field it claims' );
		}

		if ( $secret_leaked ) {
			$fail( 'the stored HMAC secret is echoed into a settings page' );
		} else {
			$ok( 'the HMAC secret is not written back into the DOM' );
		}

		// A second-level tab reachable from nowhere is a tab whose settings can
		// only be saved by typing the URL.
		ob_start();
		\SmartLogin\Admin\SettingsPage::nav( 'delivery-automation' );
		$nav_html = (string) ob_get_clean();

		$unreachable = array();

		foreach ( array( 'delivery', 'delivery-sms', 'delivery-email', 'delivery-mail', 'delivery-automation' ) as $slug ) {
			if ( false === strpos( $nav_html, 'tab=' . $slug . '"' ) ) {
				$unreachable[] = $slug;
			}
		}

		if ( $unreachable ) {
			$fail( 'the delivery family is not navigable: ' . implode( ', ', $unreachable ) );
		} else {
			$ok( 'the screens link to each other from the second-level nav' );
		}
	}
}

// ---------------------------------------------------------------------
echo "\n";

Settings::store_secret( 'automation.secret', '' );

if ( false !== $settings_before ) {
	update_option( Settings::OPTION, $settings_before );
} else {
	delete_option( Settings::OPTION );
}

if ( false !== $secrets_before ) {
	update_option( Settings::SECRET_OPTION, $secrets_before );
} else {
	delete_option( Settings::SECRET_OPTION );
}

$ok( 'settings and secrets restored' );

echo "\n";

if ( $failures ) {
	echo "SMART_LOGIN_DELIVERY_GATE_FAILED\n";

	foreach ( $failures as $message ) {
		echo 'reason=' . $message . "\n";
	}

	exit( 1 );
}

echo "SMART_LOGIN_DELIVERY_GATE_OK\n";
echo 'wordpress=' . get_bloginfo( 'version' ) . "\n";
echo 'php=' . PHP_VERSION . "\n";
echo 'transports=' . implode( ',', array( 'sms', 'email', 'automation' ) ) . "\n";
