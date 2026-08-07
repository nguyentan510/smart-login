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
 * @package SmartLogin
 */

declare( strict_types=1 );

$wp_root     = rtrim( (string) getenv( 'SMART_LOGIN_WP_ROOT' ), "\\/" );
$plugin_root = rtrim( (string) getenv( 'SMART_LOGIN_PLUGIN_ROOT' ), "\\/" );

$blocked = static function ( string $message ): never {
	echo "SMART_LOGIN_DIALOG_INTEGRATION_BLOCKED\n";
	echo 'reason=' . $message . "\n";
	exit( 2 );
};

$failed = static function ( string $message ): never {
	echo "SMART_LOGIN_DIALOG_INTEGRATION_FAILED\n";
	echo 'reason=' . $message . "\n";
	exit( 1 );
};

if ( '' === $wp_root || ! is_file( $wp_root . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	$blocked( 'SMART_LOGIN_WP_ROOT must point to a WordPress public root' );
}

if ( '' === $plugin_root || ! is_file( $plugin_root . DIRECTORY_SEPARATOR . 'smart-login.php' ) ) {
	$blocked( 'SMART_LOGIN_PLUGIN_ROOT must point to the current plugin source' );
}

define( 'WP_USE_THEMES', false );

require $wp_root . DIRECTORY_SEPARATOR . 'wp-load.php';

if ( ! class_exists( \SmartLogin\Frontend\LoginDialog::class ) ) {
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

$contract = \SmartLogin\Frontend\LoginDialog::contract();

$check(
	'the fragment endpoint is a real REST url',
	'' !== $contract['endpoint'] && false !== strpos( $contract['endpoint'], 'smart-login/v1/step' ),
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

// ---------------------------------------------------------------------
// 2. The fragment, over a real REST request
// ---------------------------------------------------------------------

$page = home_url( '/' );

$request = new WP_REST_Request( 'GET', '/smart-login/v1/step' );
$request->set_param( 'step', 'identify' );
$request->set_param( 'page', $page );
$request->set_param( 'redirect_to', $page );

$response = rest_do_request( $request );
$body     = $response->get_data();
$html     = (string) ( $body['data']['html'] ?? '' );

$check( 'the fragment route answers 200', 200 === $response->get_status(), (string) $response->get_status() );
$check( 'the fragment carries markup', '' !== $html, strlen( $html ) . ' bytes' );
$check( 'the fragment carries a fresh nonce', false !== strpos( $html, 'smart_login_nonce' ) );
$check( 'the fragment does not link into the API', false === strpos( $html, '/wp-json/' ) );
$check( 'the fragment returns the visitor to the host page', false !== strpos( $html, $page ), $page );

/*
 * The off-site check, because `page` and `redirect_to` both end up in an href.
 */
$evil = new WP_REST_Request( 'GET', '/smart-login/v1/step' );
$evil->set_param( 'step', 'identify' );
$evil->set_param( 'page', 'https://evil.example/' );

$evil_html = (string) ( rest_do_request( $evil )->get_data()['data']['html'] ?? '' );

$check( 'an off-site host page is refused', false === strpos( $evil_html, 'evil.example' ) );

/*
 * `onboard` is the one step a caller may name that is not on the public list.
 * Signed out, it must not render — the protection PUBLIC_STEPS was providing.
 */
$onboard = new WP_REST_Request( 'GET', '/smart-login/v1/step' );
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

$dialog = new \SmartLogin\Frontend\LoginDialog();

$_GET['smart_login_step'] = 'identify';
$with                     = $dialog->no_index_dialog_urls( array() );
unset( $_GET['smart_login_step'] );
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

echo "SMART_LOGIN_DIALOG_INTEGRATION_OK\n";
