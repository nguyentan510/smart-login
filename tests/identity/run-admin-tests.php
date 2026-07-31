<?php
/**
 * Render every admin screen and fail on anything that throws.
 *
 * The template gate exists because a deleted class survived in two templates for
 * four phases and fatalled My Account, and none of the four gates in front of it
 * executed display code. The admin screens had exactly the same exposure: syntax
 * lint does not resolve classes at run time, the fitness rule catches a deleted
 * class but not a deleted method, and no suite ever called a render method.
 *
 * So this runner does the crude thing that actually works — it renders the
 * screens.
 *
 * Run with:  php tests/identity/run-admin-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../admin-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Admin\Screens\AuditScreen;
use SmartLogin\Admin\Screens\SettingsScreen;
use SmartLogin\Admin\SettingsPage;
use SmartLogin\FieldRegistry;
use SmartLogin\Settings;

/**
 * Run a renderer and report what happened, treating a PHP notice as a failure —
 * an undeclared variable on a settings screen is a bug whether or not the page
 * happens to come out looking right.
 *
 * @return array{html:string,error:?string,warnings:array}
 */
function sl_capture( callable $render ): array {
	$GLOBALS['sl_admin_warnings'] = array();

	set_error_handler(
		static function ( int $severity, string $message ) {
			$GLOBALS['sl_admin_warnings'][] = $message;
			return true;
		}
	);

	ob_start();

	try {
		$render();
		$html  = (string) ob_get_clean();
		$error = null;
	} catch ( Throwable $exception ) {
		ob_end_clean();
		$html  = '';
		$error = get_class( $exception ) . ': ' . $exception->getMessage();
	} finally {
		restore_error_handler();
	}

	return array(
		'html'     => $html,
		'error'    => $error,
		'warnings' => $GLOBALS['sl_admin_warnings'],
	);
}

// ---------------------------------------------------------------------
sl_section( 'Every settings tab renders' );

$screen = new SettingsScreen();

foreach ( array_keys( FieldRegistry::tabs() ) as $tab ) {
	$result = sl_capture(
		static function () use ( $screen, $tab ): void {
			$screen->render( $tab );
		}
	);

	sl_assert( sprintf( 'tab "%s" renders', $tab ), null === $result['error'], (string) $result['error'] );

	if ( null !== $result['error'] ) {
		continue;
	}

	sl_assert(
		sprintf( 'tab "%s" emits no PHP notice', $tab ),
		array() === $result['warnings'],
		implode( ' | ', array_slice( $result['warnings'], 0, 3 ) )
	);

	sl_assert( sprintf( 'tab "%s" produces markup', $tab ), '' !== trim( $result['html'] ) );

	// The tab must actually carry its own fields into the form, and must say
	// which tab it is — without that hidden input a save writes nothing.
	sl_assert(
		sprintf( 'tab "%s" names itself for the save', $tab ),
		false !== strpos( $result['html'], 'name="' . Settings::OPTION . '[' . Settings::TAB_FIELD . ']" value="' . $tab . '"' ),
		'Settings::sanitize() would treat this save as a no-op.'
	);

	$missing = array();

	foreach ( FieldRegistry::for_tab( $tab ) as $path => $field ) {
		// Prefix, not exact: a repeater draws `…[sms][headers][0][key]`, so the
		// base name is where its inputs start rather than the whole attribute.
		// The trailing `]` in the base keeps `sms.url` from matching a
		// hypothetical `sms.url_extra`.
		if ( false === strpos( $result['html'], 'name="' . \SmartLogin\Admin\FieldRenderer::name( $path ) ) ) {
			$missing[] = $path;
		}
	}

	// This is the invariant the old screen could not hold: a field claimed by a
	// tab and drawn by nothing. Here it is checked against the rendered HTML
	// rather than against a second list that has to be maintained by hand.
	sl_check( sprintf( 'tab "%s" draws every field it claims', $tab ), array(), $missing );
}

// ---------------------------------------------------------------------
sl_section( 'An unknown tab falls back rather than fataling' );

$fallback = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'no-such-tab' );
	}
);

sl_assert( 'an unknown tab still renders', null === $fallback['error'], (string) $fallback['error'] );
sl_assert( 'an unknown tab produces markup', '' !== trim( $fallback['html'] ) );

// ---------------------------------------------------------------------
sl_section( 'Secrets never reach the DOM' );

// Every tab used to echo the entire option back through hidden inputs so the
// other tabs could survive a save — gateway credentials included. Saving per
// tab removed the need; this asserts the leak did not come back.
Settings::update(
	array(
		'sms.headers' => array(
			array(
				'key'   => 'Authorization',
				'value' => 'Bearer super-secret-gateway-token',
			),
		),
	)
);

foreach ( array_keys( FieldRegistry::tabs() ) as $tab ) {
	if ( 'delivery' === $tab ) {
		continue; // The tab that owns the credential is allowed to show it.
	}

	$rendered = sl_capture(
		static function () use ( $screen, $tab ): void {
			$screen->render( $tab );
		}
	);

	sl_assert(
		sprintf( 'tab "%s" does not leak the gateway token', $tab ),
		false === strpos( $rendered['html'], 'super-secret-gateway-token' ),
		'A credential from another tab is present in this page source.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'The audit screen renders' );

$audit = sl_capture(
	static function (): void {
		( new AuditScreen() )->render();
	}
);

sl_assert( 'audit screen renders', null === $audit['error'], (string) $audit['error'] );
sl_assert( 'audit screen produces markup', '' !== trim( $audit['html'] ) );

// ---------------------------------------------------------------------
sl_section( 'The tab strip covers the registry' );

$nav = sl_capture(
	static function (): void {
		SettingsPage::nav( 'auth' );
	}
);

sl_assert( 'nav renders', null === $nav['error'], (string) $nav['error'] );

$unlinked = array();

foreach ( array_keys( FieldRegistry::tabs() ) as $tab ) {
	if ( false === strpos( $nav['html'], 'tab=' . $tab ) ) {
		$unlinked[] = $tab;
	}
}

sl_check( 'every tab is reachable from the strip', array(), $unlinked );

// ---------------------------------------------------------------------
sl_summary( 'Admin screens' );
