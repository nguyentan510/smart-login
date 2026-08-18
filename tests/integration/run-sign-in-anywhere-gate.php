<?php
/**
 * Phase 19 qualification gate: the dialog against a real WordPress.
 *
 * Four of the things this phase changed cannot be checked without one, and the
 * project has a Postscript about exactly that — four gates once missed a fatal
 * that only a real WordPress could show.
 *
 *   1. the launcher reaching pages `Assets::maybe_enqueue()` never did
 *   2. the fragment route answering a real REST request
 *   3. `wp_robots` marking the dialog-open variant noindex
 *   4. the guest-cart merge, which hangs off `wp_login` firing
 *
 * Opt-in, like every other gate here: without the environment variables it
 * reports BLOCKED rather than passing quietly.
 *
 * @package OmniWP
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'OMNIWP_WP_ROOT' ), "\\/" );
$plugin_root = rtrim( (string) getenv( 'OMNIWP_PLUGIN_ROOT' ), "\\/" );
$db_host     = (string) getenv( 'OMNIWP_DB_HOST' );
$db_name     = (string) getenv( 'OMNIWP_DB_NAME' );
$db_user     = (string) getenv( 'OMNIWP_DB_USER' );
$db_pass     = (string) getenv( 'OMNIWP_DB_PASSWORD' );
$prefix      = (string) getenv( 'OMNIWP_DB_PREFIX' );

$blocked = static function ( string $message ): never {
	echo "OMNIWP_DIALOG_INTEGRATION_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$failed = static function ( string $message ): never {
	echo "OMNIWP_DIALOG_INTEGRATION_FAILED\n";
	echo 'reason=' . $message . "\n";
	exit( 1 );
};

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'OMNIWP_WP_ROOT must point to a WordPress public root' );
}

if ( '' === $plugin_root || ! is_file( $plugin_root . DIRECTORY_SEPARATOR . 'omniwp.php' ) ) {
	$blocked( 'OMNIWP_PLUGIN_ROOT must point to the current plugin source' );
}

if ( '' === $db_host || '' === $db_name || '' === $db_user ) {
	$blocked( 'database connection variables are incomplete' );
}

if ( '' === $prefix ) {
	$prefix = 'wp_';
}

if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	$blocked( 'OMNIWP_DB_PREFIX contains unsupported characters' );
}

/*
 * Bootstrap through wp-settings.php with the connection taken from the
 * environment, which is what the other four gates in this directory do.
 *
 * This gate used to require wp-load.php and let the site's own wp-config.php
 * supply the credentials. That works only when the runner and the site agree
 * about where MySQL is, and here they do not: the site is served by Local on
 * port 10005 while the gate runs under the XAMPP build that has mbstring and
 * openssl. WordPress answered with `Error establishing a database connection`,
 * printed its die page — and exited 0, so the wrapper script read the gate as
 * having passed. Two failures compounding: an unreachable database, and a
 * failure mode that looked like success.
 */
define( 'WP_USE_THEMES', false );
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
$table_prefix = $prefix; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

require $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php';

global $wpdb;
if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb || '' !== (string) $wpdb->last_error ) {
	$blocked( 'WordPress database bootstrap is not healthy' );
}

if ( ! class_exists( \OmniWP\Frontend\LoginDialog::class ) ) {
	$blocked( 'the plugin is not active on this install' );
}

$checks = array();

/**
 * @param string $label
 * @param bool   $passed
 * @param string $detail
 */
$check = static function ( string $label, bool $passed, string $detail = '' ) use ( &$checks ): void {
	$checks[] = array(
		'label'  => $label,
		'passed' => $passed,
		'detail' => $detail,
	);
};

// ---------------------------------------------------------------------
// 1. The contract the launcher is localized with
// ---------------------------------------------------------------------

$contract = \OmniWP\Frontend\LoginDialog::contract();

$check(
	'the fragment endpoint is a real REST url',
	'' !== $contract['endpoint'] && false !== strpos( $contract['endpoint'], 'omniwp/v1/step' ),
	$contract['endpoint']
);

$check(
	'the step allowlist arrives from PHP',
	is_array( $contract['steps'] ) && in_array( 'identify', $contract['steps'], true ),
	implode( ',', (array) $contract['steps'] )
);

$check(
	'every alias resolves to an allowed step',
	array() === array_diff( array_values( $contract['aliases'] ), (array) $contract['steps'] ),
	implode( ',', array_values( $contract['aliases'] ) )
);

$check(
	'capture names at least one url the plugin can resolve',
	array() !== $contract['captured'],
	implode( ' ', $contract['captured'] )
);

/*
 * 19.12. The welcome screen's address picker is inert markup until `address.js`
 * upgrades it, and the enqueue the template asks for happens inside a REST
 * request where there is no `wp_enqueue_scripts` to answer — so the contract is
 * the only way that file reaches the dialog.
 *
 * Checked here as well as in rule 16 because the two know different things: the
 * rule reads a stubbed URL, and this one resolves it against the install's real
 * plugin directory and asks whether a file is actually there.
 */
$address_js = (string) ( $contract['addressJs'] ?? '' );

$check(
	'the dialog can fetch the address picker script',
	'' !== $address_js && false !== strpos( $address_js, 'address.js' ),
	$address_js
);

$check(
	'and that url is a file on disk',
	'' !== $address_js && is_file( $plugin_root . '/assets/js/' . basename( $address_js ) ),
	$plugin_root . '/assets/js/' . basename( $address_js )
);

$check(
	'the picker config travels with it',
	false !== strpos( (string) ( $contract['address']['restUrl'] ?? '' ), 'omniwp/v1' )
		&& '' !== (string) ( $contract['address']['i18n']['chooseWard'] ?? '' ),
	(string) ( $contract['address']['restUrl'] ?? '' )
);

/*
 * And the ward route the picker calls, over a real REST request.
 *
 * The province is taken from the dataset rather than written here. The first
 * draft asked for `79` — the official code for Hồ Chí Minh, and not a code this
 * dataset uses — so the route answered `200` with an empty list and the check
 * read as a broken endpoint. A rule carrying its own copy of the data stops
 * testing the data.
 */
$province_codes = array_keys( \OmniWP\Address\AddressRepository::provinces() );
$first_province = (string) ( $province_codes[0] ?? '' );

$wards     = rest_do_request( new WP_REST_Request( 'GET', '/omniwp/v1/address/wards/' . $first_province ) );
$ward_list = (array) $wards->get_data();

$check(
	'the ward route answers with a list',
	'' !== $first_province && 200 === $wards->get_status() && array() !== $ward_list,
	'province ' . $first_province . ' → ' . $wards->get_status() . ', ' . count( $ward_list ) . ' wards'
);

// ---------------------------------------------------------------------
// 2. The fragment, over a real REST request
// ---------------------------------------------------------------------

$page = home_url( '/' );

$request = new WP_REST_Request( 'GET', '/omniwp/v1/step' );
$request->set_param( 'step', 'identify' );
$request->set_param( 'page', $page );
$request->set_param( 'redirect_to', $page );

$response = rest_do_request( $request );
$body     = $response->get_data();
$html     = (string) ( $body['data']['html'] ?? '' );

$check( 'the fragment route answers 200', 200 === $response->get_status(), (string) $response->get_status() );
$check( 'the fragment carries markup', '' !== $html, strlen( $html ) . ' bytes' );
$check( 'the fragment carries a fresh nonce', false !== strpos( $html, 'OMNIWP_nonce' ) );
$check( 'the fragment does not link into the API', false === strpos( $html, '/wp-json/' ) );
$check( 'the fragment returns the visitor to the host page', false !== strpos( $html, $page ), $page );

/*
 * The off-site check, because `page` and `redirect_to` both end up in an href.
 */
$evil = new WP_REST_Request( 'GET', '/omniwp/v1/step' );
$evil->set_param( 'step', 'identify' );
$evil->set_param( 'page', 'https://evil.example/' );

$evil_html = (string) ( rest_do_request( $evil )->get_data()['data']['html'] ?? '' );

$check( 'an off-site host page is refused', false === strpos( $evil_html, 'evil.example' ) );

/*
 * `onboard` is the one step a caller may name that is not on the public list.
 * Signed out, it must not render — the protection PUBLIC_STEPS was providing.
 */
$onboard = new WP_REST_Request( 'GET', '/omniwp/v1/step' );
$onboard->set_param( 'step', 'onboard' );
$onboard->set_param( 'page', $page );

$onboard_body = rest_do_request( $onboard )->get_data();

$check(
	'a signed-out visitor cannot request the welcome screen',
	'onboard' !== ( $onboard_body['data']['step'] ?? '' ),
	(string) ( $onboard_body['data']['step'] ?? '?' )
);

// ---------------------------------------------------------------------
// 3. noindex on the dialog-open variant
// ---------------------------------------------------------------------

$dialog = new \OmniWP\Frontend\LoginDialog();

$_GET['OMNIWP_step'] = 'identify';
$with                     = $dialog->no_index_dialog_urls( array() );
unset( $_GET['OMNIWP_step'] );
$without = $dialog->no_index_dialog_urls( array() );

$check( 'the dialog-open variant is noindex', ! empty( $with['noindex'] ) );
$check( 'an ordinary page is not', empty( $without['noindex'] ) );

// ---------------------------------------------------------------------
// 4. The cart that already survives
// ---------------------------------------------------------------------

/*
 * Finding 13. WooCommerce merges a guest cart with the member's saved one only
 * when `_woocommerce_load_saved_cart_after_login` is set, and that meta is
 * written by `wc_user_logged_in()` on `wp_login`. `SessionIssuer` fires it.
 *
 * Asserted against the real hook table rather than against the source, because
 * the source is what rule 11 already reads and this gate exists to check the
 * thing a real WordPress knows and a string does not.
 */
if ( ! function_exists( 'wc_user_logged_in' ) ) {
	$check( 'WooCommerce is not active, so the cart merge is not applicable', true, 'skipped' );
} else {
	$check(
		'WooCommerce still hangs its cart merge on wp_login',
		false !== has_action( 'wp_login', 'wc_user_logged_in' ),
		'has_action( wp_login, wc_user_logged_in )'
	);
}

$issuer = (string) file_get_contents( $plugin_root . '/includes/Auth/class-session-issuer.php' );

$check(
	'the session issuer fires wp_login',
	false !== strpos( $issuer, "do_action( 'wp_login'" )
);

// ---------------------------------------------------------------------

$fails = 0;

foreach ( $checks as $row ) {
	printf(
		"%s  %s%s\n",
		$row['passed'] ? 'PASS' : 'FAIL',
		$row['label'],
		'' !== $row['detail'] ? '  (' . $row['detail'] . ')' : ''
	);

	$fails += $row['passed'] ? 0 : 1;
}

printf( "\n%d checks, %d failed\n", count( $checks ), $fails );

if ( $fails > 0 ) {
	$failed( $fails . ' check(s) failed' );
}

echo "OMNIWP_DIALOG_INTEGRATION_OK\n";
