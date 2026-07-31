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
		// A conditional field may draw nothing depending on another setting.
		// Those are covered by their own assertions below, where the condition
		// is set up deliberately.
		if ( ! empty( $field['conditional'] ) ) {
			continue;
		}

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
sl_section( 'The overview screen renders and reports blockers' );

$overview = sl_capture(
	static function (): void {
		( new \SmartLogin\Admin\Screens\OverviewScreen() )->render();
	}
);

sl_assert( 'overview renders', null === $overview['error'], (string) $overview['error'] );
sl_assert(
	'overview emits no PHP notice',
	array() === $overview['warnings'],
	implode( ' | ', array_slice( $overview['warnings'], 0, 3 ) )
);
sl_assert( 'overview produces markup', '' !== trim( $overview['html'] ) );

$readiness = new \SmartLogin\Admin\Readiness();
$statuses  = array( \SmartLogin\Admin\Readiness::OK, \SmartLogin\Admin\Readiness::WARN, \SmartLogin\Admin\Readiness::FAIL, \SmartLogin\Admin\Readiness::OFF );
$malformed = array();

foreach ( $readiness->checks() as $check ) {
	foreach ( array( 'key', 'label', 'status', 'detail', 'action', 'action_label' ) as $required ) {
		if ( ! isset( $check[ $required ] ) ) {
			$malformed[] = ( $check['key'] ?? '?' ) . ':' . $required;
		}
	}

	if ( ! in_array( $check['status'] ?? '', $statuses, true ) ) {
		$malformed[] = ( $check['key'] ?? '?' ) . ':status';
	}
}

sl_check( 'every check is well formed', array(), $malformed );

/*
 * The default install cannot send a code: identity.mode is phone-only and
 * sms.enabled is off, so the SMS transport is unavailable and nothing can reach
 * a phone number. That combination shipped with no warning anywhere in the
 * admin, and the first visitor to press Đăng ký was the one who found out.
 */
Settings::update(
	array(
		'identity.mode' => 'phone_only',
		'sms.enabled'   => 0,
		'sms.url'       => '',
		'email.enabled' => 1,
	)
);

$delivery = null;

foreach ( ( new \SmartLogin\Admin\Readiness() )->checks() as $check ) {
	if ( 'delivery' === $check['key'] ) {
		$delivery = $check;
	}
}

sl_check(
	'a phone-only site with no SMS channel is reported as blocking',
	\SmartLogin\Admin\Readiness::FAIL,
	$delivery['status'] ?? 'missing'
);

// Deliberately not asserting is_ready() here. Under these stubs the table check
// also fails, so the aggregate would come back false whether or not the delivery
// check worked — a test that passes for a reason it is not testing.

// Configuring the webhook clears it.
Settings::update(
	array(
		'sms.enabled' => 1,
		'sms.url'     => 'https://gateway.example.test/send',
	)
);

$delivery_after = null;

foreach ( ( new \SmartLogin\Admin\Readiness() )->checks() as $check ) {
	if ( 'delivery' === $check['key'] ) {
		$delivery_after = $check;
	}
}

sl_check(
	'configuring the gateway clears the blocker',
	\SmartLogin\Admin\Readiness::OK,
	$delivery_after['status'] ?? 'missing'
);

// ---------------------------------------------------------------------
sl_section( 'Choosing a gateway replaces eleven fields with three' );

// Through sanitize() and into the option, which is the path a real save takes —
// update() plants values directly and would skip the preset derivation entirely.
$saved_delivery = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'delivery',
		'sms'               => array(
			'enabled'     => 1,
			'preset'      => 'esms',
			'credentials' => array(
				'api_key'    => 'public-api-key',
				'secret_key' => 'secret-key-must-not-appear',
				'brandname'  => 'SHOPTEST',
			),
		),
		'otp'               => array( 'preset' => 'balanced' ),
	)
);

update_option( Settings::OPTION, $saved_delivery );
Settings::flush_cache();

sl_check( 'saving a preset derives the gateway URL', 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_post_json/', Settings::get( 'sms.url' ) );
sl_check( 'saving a preset derives the success condition', 'CodeResult', Settings::get( 'sms.success_path' ) );
sl_check( 'saving a preset applies the OTP profile', 300, Settings::get_int( 'otp.ttl' ) );

$delivery_html = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'delivery' );
	}
)['html'];

$missing_credentials = array();

foreach ( array_keys( \SmartLogin\GatewayPresets::credentials( 'esms' ) ) as $credential ) {
	if ( false === strpos( $delivery_html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( 'sms.credentials' ) . '[' . $credential . ']"' ) ) {
		$missing_credentials[] = $credential;
	}
}

sl_check( 'the preset draws every credential it asks for', array(), $missing_credentials );

sl_assert(
	'the derived request is shown for checking',
	false !== strpos( $delivery_html, 'rest.esms.vn' ),
	'The administrator cannot verify what will be sent.'
);

sl_assert(
	'a secret credential is never echoed into the form',
	false === strpos( $delivery_html, 'secret-key-must-not-appear' ),
	'The stored secret is present in the page source.'
);

sl_assert(
	'a non-secret credential is shown so it can be corrected',
	false !== strpos( $delivery_html, 'public-api-key' )
);

// The derivation itself: credentials in, a valid request out.
$resolved = \SmartLogin\GatewayPresets::resolve(
	'esms',
	array(
		'api_key'    => 'K',
		'secret_key' => 'S',
		'brandname'  => 'B',
	)
);

sl_check( 'the derived URL comes from the preset', 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_post_json/', $resolved['sms.url'] );
sl_assert( 'the derived body is valid JSON', null !== json_decode( $resolved['sms.body'], true ), $resolved['sms.body'] );
sl_assert( 'the derived body still carries the code placeholder', false !== strpos( $resolved['sms.body'], '{{code}}' ) );
sl_assert( 'no credential placeholder survives derivation', false === strpos( $resolved['sms.body'], '{{cred:' ) );
sl_check( 'the success condition comes from the preset', '100', $resolved['sms.success_value'] );

sl_check( 'the custom preset derives nothing', array(), \SmartLogin\GatewayPresets::resolve( \SmartLogin\GatewayPresets::CUSTOM, array() ) );

$bodies_ok = array();

foreach ( \SmartLogin\GatewayPresets::all() as $slug => $preset ) {
	if ( \SmartLogin\GatewayPresets::is_custom( $slug ) || 'application/json' !== ( $preset['content_type'] ?? '' ) ) {
		continue;
	}

	$filled = \SmartLogin\GatewayPresets::resolve( $slug, array_fill_keys( array_keys( $preset['credentials'] ), 'x' ) );

	if ( null === json_decode( $filled['sms.body'], true ) ) {
		$bodies_ok[] = $slug;
	}
}

sl_check( 'every JSON preset produces a parseable body', array(), $bodies_ok );

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
