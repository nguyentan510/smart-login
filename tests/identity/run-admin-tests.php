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

/*
 * 10.5. The two cases above stay exactly as they were — they still describe
 * valid configurations — and these extend them to the one 10.1 made possible:
 * a channel whose route points somewhere other than its built-in transport.
 *
 * Before 10.5 this check constructed WebhookTransport and MailTransport
 * directly, so it answered about transports the site might not be using. Here
 * the SMS gateway is configured and healthy, and the site is still broken.
 */
Settings::update(
	array(
		'identity.mode'        => 'both',
		'sms.enabled'          => 1,
		'sms.url'              => 'https://gateway.example.test/send',
		'email.enabled'        => 1,
		'delivery.route_phone' => 'automation',
		'delivery.route_email' => 'email',
		'automation.url'       => '',
	)
);
Settings::store_secret( 'automation.secret', '' );

$routed = null;

foreach ( ( new \SmartLogin\Admin\Readiness() )->checks() as $check ) {
	if ( 'delivery' === $check['key'] ) {
		$routed = $check;
	}
}

sl_check(
	'a channel routed at an unconfigured transport is reported as blocking',
	\SmartLogin\Admin\Readiness::FAIL,
	$routed['status'] ?? 'missing'
);

sl_assert(
	'and the detail names the routed transport, not the built-in one',
	false !== strpos( (string) ( $routed['detail'] ?? '' ), 'automation' ),
	'"Chưa cấu hình: SMS" would send the administrator to gateway settings that are already correct. Detail was: ' . ( $routed['detail'] ?? '' )
);

Settings::update( array( 'automation.url' => 'https://hooks.example.test/otp' ) );
Settings::store_secret( 'automation.secret', 'admin-suite-signing-secret' );

$routed_after = null;

foreach ( ( new \SmartLogin\Admin\Readiness() )->checks() as $check ) {
	if ( 'delivery' === $check['key'] ) {
		$routed_after = $check;
	}
}

sl_assert(
	'configuring the routed transport clears it',
	\SmartLogin\Admin\Readiness::FAIL !== ( $routed_after['status'] ?? 'missing' ),
	'Still blocking after the endpoint was configured: ' . ( $routed_after['detail'] ?? '' )
);

// ---------------------------------------------------------------------
sl_section( 'The spend estimate follows the channel, not the transport' );

/*
 * The stub $wpdb returns one global whatever the query, so counting by channel
 * and counting by transport are indistinguishable through it. Readiness takes
 * its repository by constructor for that reason, the same seam RateLimiter
 * already offered.
 */
class SL_Spend_Repository extends \SmartLogin\OTP\OtpRepository {

	/** @var string[] */
	public $asked = array();

	public function count_recent_all( int $seconds ): int {
		return 3;
	}

	public function count_recent_by_channel( string $channel, int $seconds ): int {
		$this->asked[] = 'by_channel:' . $channel;

		return \SmartLogin\Identity\Channels\PhoneChannel::ID === $channel ? 3 : 0;
	}

	public function count_recent_by_transport( string $transport, int $seconds ): int {
		$this->asked[] = 'by_transport:' . $transport;

		return 0;
	}
}

// Three phone codes carried by the automation transport: no row says
// transport = 'sms', so the pre-10.5 counter priced them all at nothing while
// the messages went out and the bill arrived.
Settings::update(
	array(
		'otp.sms_unit_cost'          => 350,
		'security.max_per_site_hour' => 100,
	)
);

$spend_repo = new SL_Spend_Repository();
$budget     = null;

foreach ( ( new \SmartLogin\Admin\Readiness( $spend_repo ) )->checks() as $check ) {
	if ( 'budget' === $check['key'] ) {
		$budget = $check;
	}
}

sl_assert(
	'the estimate prices phone codes whichever transport carried them',
	(bool) preg_match( '/1[.,]050/', (string) ( $budget['detail'] ?? '' ) ),
	'3 codes at 350 should read 1.050 đ. Detail was: ' . ( $budget['detail'] ?? '' )
);

sl_assert(
	'and it asks by channel rather than by transport',
	in_array( 'by_channel:phone', $spend_repo->asked, true )
		&& ! in_array( 'by_transport:sms', $spend_repo->asked, true ),
	'Counters asked: ' . implode( ', ', $spend_repo->asked )
);

Settings::store_secret( 'automation.secret', '' );

// ---------------------------------------------------------------------
sl_section( 'Choosing a gateway replaces eleven fields with three' );

// Through sanitize() and into the option, which is the path a real save takes —
// update() plants values directly and would skip the preset derivation entirely.
/*
 * Two saves, not one. Since 10.6 the gateway lives on `delivery-sms` and the OTP
 * profile on `delivery`, and a save writes only the fields carried by the tab it
 * names — so posting both together would silently drop half of it. That is the
 * boundary the split exists to create, asserted here by relying on it.
 */
$saved_delivery = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'delivery-sms',
		'sms'               => array(
			'enabled'     => 1,
			'preset'      => 'esms',
			'credentials' => array(
				'api_key'    => 'public-api-key',
				'secret_key' => 'secret-key-must-not-appear',
				'brandname'  => 'SHOPTEST',
			),
		),
	)
);

update_option( Settings::OPTION, $saved_delivery );
Settings::flush_cache();

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			Settings::TAB_FIELD => 'delivery',
			'otp'               => array( 'preset' => 'balanced' ),
		)
	)
);
Settings::flush_cache();

sl_check(
	'the gateway saved from another tab survives',
	'esms',
	Settings::get( 'sms.preset' )
);

sl_check( 'saving a preset derives the gateway URL', 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_post_json/', Settings::get( 'sms.url' ) );
sl_check( 'saving a preset derives the success condition', 'CodeResult', Settings::get( 'sms.success_path' ) );
sl_check( 'saving a preset applies the OTP profile', 300, Settings::get_int( 'otp.ttl' ) );

$delivery_html = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'delivery-sms' );
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
	if ( 'delivery-sms' === $tab ) {
		// The tab that owns the credential is allowed to show it. Since 10.6
		// that is the SMS screen, not the whole delivery family — which makes
		// this assertion stricter than it was: the three sibling tabs are now
		// checked rather than exempted along with it.
		continue;
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

/*
 * Rendered once per tab, not once in total. Since 10.6 the second-level tabs
 * appear only while their family is open, so a single render of one tab could
 * never link them all — and asserting against that render would have meant
 * either deleting the rule or exempting the new tabs from it.
 *
 * The property is unchanged and now checked more thoroughly: a tab that exists
 * in the registry must be reachable from the navigation drawn when it is the
 * active tab. A tab reachable from nowhere is a tab whose settings can only be
 * saved by typing the URL.
 */
$unlinked = array();

foreach ( array_keys( FieldRegistry::tabs() ) as $tab ) {
	$strip = sl_capture(
		static function () use ( $tab ): void {
			SettingsPage::nav( $tab );
		}
	);

	if ( null !== $strip['error'] || false === strpos( $strip['html'], 'tab=' . $tab . '"' ) ) {
		$unlinked[] = $tab;
	}
}

sl_check( 'every tab is reachable from the navigation', array(), $unlinked );

// A child tab must also link back to the rest of its family, or the split has
// produced four screens with no way between them.
$sub = sl_capture(
	static function (): void {
		SettingsPage::nav( 'delivery-automation' );
	}
);

$siblings_missing = array();

foreach ( array( 'delivery', 'delivery-sms', 'delivery-email' ) as $sibling ) {
	if ( false === strpos( $sub['html'], 'tab=' . $sibling . '"' ) ) {
		$siblings_missing[] = $sibling;
	}
}

sl_check( 'a second-level tab links to its siblings', array(), $siblings_missing );

sl_assert(
	'and the parent is highlighted in the top strip',
	false !== strpos( $sub['html'], 'nav-tab nav-tab-active' ),
	'With no top-level tab marked active, the whole delivery family looks unselected while one of its screens is open.'
);

// ---------------------------------------------------------------------
sl_section( 'Splitting the delivery screen kept every invariant' );

/*
 * The property FieldRegistry exists to guarantee, checked across the split
 * rather than within one tab: a field is drawn by exactly one screen. Four
 * screens where there was one is four chances for a field to be claimed twice
 * or by nobody, and both failures are silent.
 */
$drawn_on = array();

foreach ( array_keys( FieldRegistry::tabs() ) as $tab ) {
	$html = sl_capture(
		static function () use ( $screen, $tab ): void {
			$screen->render( $tab );
		}
	)['html'];

	foreach ( FieldRegistry::all() as $path => $field ) {
		if ( '' === (string) ( $field['tab'] ?? '' ) || ! empty( $field['conditional'] ) ) {
			continue;
		}

		if ( false !== strpos( $html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( $path ) ) ) {
			$drawn_on[ $path ][] = $tab;
		}
	}
}

$wrong_home = array();

foreach ( FieldRegistry::all() as $path => $field ) {
	if ( '' === (string) ( $field['tab'] ?? '' ) || ! empty( $field['conditional'] ) ) {
		continue;
	}

	if ( array( $field['tab'] ) !== ( $drawn_on[ $path ] ?? array() ) ) {
		$wrong_home[] = $path . ' → ' . implode( ',', $drawn_on[ $path ] ?? array( 'nowhere' ) );
	}
}

sl_check( 'every field is drawn by exactly the tab that claims it', array(), $wrong_home );

// Saving one screen of a family must not disturb its siblings. This is the
// whole reason each is a real tab rather than a JS filter over one form.
Settings::update(
	array(
		'automation.url'     => 'https://hooks.example.test/keep-me',
		'automation.timeout' => 9,
		'email.subject'      => 'Giữ nguyên {{code}}',
		'email.is_html'      => 1,
	)
);

$before_siblings = array(
	'automation.url'     => Settings::get( 'automation.url' ),
	'automation.timeout' => Settings::get_int( 'automation.timeout' ),
	'email.subject'      => Settings::get( 'email.subject' ),
	'email.is_html'      => Settings::get_int( 'email.is_html' ),
);

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			Settings::TAB_FIELD => 'delivery-sms',
			'sms'               => array(
				'enabled' => 1,
				'preset'  => \SmartLogin\GatewayPresets::CUSTOM,
				'url'     => 'https://gateway.example.test/changed',
			),
		)
	)
);
Settings::flush_cache();

$disturbed = array();

foreach ( $before_siblings as $path => $value ) {
	$now = is_int( $value ) ? Settings::get_int( $path ) : Settings::get( $path );

	if ( $now !== $value ) {
		$disturbed[] = $path;
	}
}

sl_check( 'saving the SMS screen leaves its sibling screens untouched', array(), $disturbed );
sl_check( 'and the screen that was saved did change', 'https://gateway.example.test/changed', Settings::get( 'sms.url' ) );

// ---------------------------------------------------------------------
sl_section( 'A fresh install opens on the easy gateway screen' );

// The default moved off `custom`, which used to present thirteen raw fields
// including a free-text JSON body to whoever had configured the least.
sl_check( 'the shipped default preset is the generic webhook', 'generic', FieldRegistry::get( 'sms.preset' )['default'] );

delete_option( Settings::OPTION );
Settings::flush_cache();

$fresh = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'delivery-sms' );
	}
)['html'];

sl_assert(
	'a fresh install asks for one endpoint, not a JSON body',
	false !== strpos( $fresh, \SmartLogin\Admin\FieldRenderer::name( 'sms.credentials' ) . '[endpoint]' ),
	'The generic preset asks for a single URL; anything else means the default did not take.'
);

// A site that has already chosen `custom` keeps it: sanitize() writes stored
// values, so the new default only reaches installs that have never saved.
update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			Settings::TAB_FIELD => 'delivery-sms',
			'sms'               => array( 'preset' => \SmartLogin\GatewayPresets::CUSTOM ),
		)
	)
);
Settings::flush_cache();

sl_check( 'an existing choice of Tuỳ chỉnh survives the new default', \SmartLogin\GatewayPresets::CUSTOM, Settings::get( 'sms.preset' ) );

// ---------------------------------------------------------------------
sl_section( 'The mail screen' );

delete_option( Settings::OPTION );
Settings::flush_cache();

$mail_html = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'delivery-mail' );
	}
);

sl_assert( 'the mail screen renders', null === $mail_html['error'], (string) $mail_html['error'] );

$mail_markup = $mail_html['html'];

// Grouped the way an administrator reads it, not the way the registry stores it.
$section_order = array();

foreach ( array( 'Mẫu mặc định', 'Mã xác thực', 'Cảnh báo quản trị', 'Giao diện email HTML' ) as $heading ) {
	$section_order[ $heading ] = strpos( $mail_markup, $heading );
}

sl_check(
	'every group has a heading',
	array(),
	array_keys( array_filter( $section_order, static fn( $at ): bool => false === $at ) )
);

$ordered = array_values( $section_order );
sort( $ordered );

sl_check(
	'and they appear in reading order',
	$ordered,
	array_values( $section_order )
);

/*
 * The property this screen exists for: an empty box must read as "inheriting
 * this" rather than as "this email has no subject". Without it an administrator
 * faced with eight blank fields pastes the default into all of them and loses
 * the inheritance the registry was built to provide.
 */
sl_assert(
	'an un-overridden template shows what it will actually send',
	false !== strpos( $mail_markup, 'placeholder="Mã đặt lại mật khẩu {{code}} - {{site_name}}"' ),
	'The recover subject box is empty and should be showing its inherited value as a placeholder.'
);

// Scoped tokens, beside the body they belong to.
sl_assert(
	'each body offers the tokens its own message declares',
	substr_count( $mail_markup, 'sl-message-tokens' ) >= 6,
	'Six messages have a body field; each should carry its own collapsed token list.'
);

sl_assert(
	'and an operational token is not offered beside an OTP body',
	substr_count( $mail_markup, '{{ceiling}}' ) === substr_count( $mail_markup, '{{halt_minutes}}' )
		&& substr_count( $mail_markup, '{{ceiling}}' ) > 0
		&& substr_count( $mail_markup, '{{ceiling}}' ) < 6,
	'{{ceiling}} belongs to the budget alert alone. Appearing beside every body is the silent-empty-string defect waiting to happen.'
);

// Saving one screen of the delivery family must not disturb the others — the
// same property 10.6 established, now with a fifth screen in the family.
Settings::update(
	array(
		'sms.url'        => 'https://gateway.example.test/keep',
		'automation.url' => 'https://hooks.example.test/keep',
	)
);

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			Settings::TAB_FIELD => 'delivery-mail',
			'email'             => array(
				'templates' => array(
					'recover' => array( 'subject' => 'Đặt lại: {{code}}' ),
				),
			),
		)
	)
);
Settings::flush_cache();

sl_check( 'saving the mail screen leaves the SMS gateway alone', 'https://gateway.example.test/keep', Settings::get( 'sms.url' ) );
sl_check( 'and the automation endpoint alone', 'https://hooks.example.test/keep', Settings::get( 'automation.url' ) );
sl_check( 'and the override it was given is stored', 'Đặt lại: {{code}}', Settings::get( 'email.templates.recover.subject' ) );

// ---------------------------------------------------------------------
sl_section( 'The provider badge cannot disagree with the runtime' );

/*
 * The card read ProviderCredentials::is_configured() — credentials only — while
 * what decides whether a provider actually runs is is_available(), which is
 * `enabled && is_configured` (class-google-provider.php:32-35). A site with
 * credentials saved and Kích hoạt left off therefore showed a green **Sẵn sàng**
 * while no button rendered on the front end.
 *
 * Asserted as agreement rather than by matching a string: the badge must move
 * whenever `available()` does, whatever it happens to say.
 */
$badge_states = array();

foreach (
	array(
		'off, no credentials'  => array( 'enabled' => 0, 'client_id' => '' ),
		'off, credentials set' => array( 'enabled' => 0, 'client_id' => 'client-id-here' ),
		'on, no credentials'   => array( 'enabled' => 1, 'client_id' => '' ),
		'on, credentials set'  => array( 'enabled' => 1, 'client_id' => 'client-id-here' ),
	) as $label => $state
) {
	Settings::update(
		array(
			'providers.google.enabled'   => $state['enabled'],
			'providers.google.client_id' => $state['client_id'],
		)
	);

	if ( '' !== $state['client_id'] ) {
		\SmartLogin\Auth\Providers\ProviderCredentials::store_secret( 'google', 'google-secret' );
	} else {
		\SmartLogin\Auth\Providers\ProviderCredentials::clear_secret( 'google' );
	}

	$card = sl_capture(
		static function () use ( $screen ): void {
			$screen->render( 'providers' );
		}
	)['html'];

	$runtime_ready = array_key_exists( 'google', ( new \SmartLogin\Auth\Providers\ProviderRegistry() )->available() );
	$badge_ready   = false !== strpos( $card, 'sl-provider-status is-ready' );

	$badge_states[ $label ] = array(
		'runtime' => $runtime_ready,
		'badge'   => $badge_ready,
	);
}

$disagreements = array();

foreach ( $badge_states as $label => $state ) {
	if ( $state['runtime'] !== $state['badge'] ) {
		$disagreements[] = sprintf(
			'%s — runtime %s, badge %s',
			$label,
			$state['runtime'] ? 'available' : 'unavailable',
			$state['badge'] ? 'ready' : 'not ready'
		);
	}
}

sl_check( 'the ready badge agrees with ProviderRegistry::available()', array(), $disagreements );

// Three states, not two: "credentials saved but switched off" is the one the
// old badge could not express, and it is the one an administrator hits.
sl_assert(
	'a configured but disabled provider says so rather than showing green',
	false === $badge_states['off, credentials set']['badge'],
	'This is the defect: credentials present, Kích hoạt off, and the card claimed Sẵn sàng while no button rendered anywhere.'
);

// ---------------------------------------------------------------------
sl_section( 'The provider screen puts its controls where they belong (12.1)' );

Settings::update(
	array(
		'providers.google.enabled' => 1,
		'providers.zalo.enabled'   => 0,
	)
);

$providers_html = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'providers' );
	}
)['html'];

/*
 * Rule 1. The switch is already on the screen — level with Client ID, inside the
 * table — so this asserts *position*, not presence. It is the control an
 * administrator touches repeatedly and the one the badge now reports on, and the
 * two belong beside each other.
 */
$header_at = strpos( $providers_html, 'sl-provider-card__header' );
$switch_at = strpos( $providers_html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( 'providers.google.enabled' ) . '"' );
$panel_at  = strpos( $providers_html, 'data-provider-panel="setup"' );

sl_assert(
	'the master switch sits in the card header, not in the table',
	false !== $header_at && false !== $switch_at && false !== $panel_at
		&& $switch_at > $header_at && $switch_at < $panel_at,
	'Kích hoạt is rendered as a form-table row beside Client ID. Turning a provider on and off is routine; filling in credentials happens once.'
);

/*
 * Rule 2. auto_link_email decides, for every provider, whether a verified
 * provider email may silently adopt an existing account — the most consequential
 * setting on the screen, currently rendered as a section below the grid where it
 * reads as a footnote to the second card.
 */
$policy_at = strpos( $providers_html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( 'providers.auto_link_email' ) . '"' );
$first_card = strpos( $providers_html, 'sl-provider-card' );

sl_assert(
	'a policy that governs both cards renders above both cards',
	false !== $policy_at && false !== $first_card && $policy_at < $first_card,
	'Rendered below the grid, it reads as belonging to the last card rather than to every one of them.'
);

// ---------------------------------------------------------------------
sl_section( 'A connection test cannot sign anybody in (12.2)' );

/*
 * The whole safety argument of 12.2, and the rule that has to exist before the
 * code does. callback() consumes the transaction and reaches
 * SessionIssuer::issue() — a diagnostic reusing that path would sign the
 * administrator in and, on an account that does not exist yet, create one.
 * Nobody would notice, because signing in successfully is what success looks
 * like.
 *
 * PENDING rather than a vacuous pass: 10.0 made that mistake with its rule 5 and
 * 11.0 repeated it with its rule 3, and both had to be corrected.
 */
$store_source = sl_source( 'includes/Auth/class-o-auth-transaction-store.php' );

if ( false === strpos( $store_source, "'mode'" ) ) {
	sl_pending(
		'a test transaction returns before the session issuer',
		'the transaction mode — 12.2'
	);
	sl_pending(
		'and before anything that creates a user or an identity',
		'the transaction mode — 12.2'
	);
	sl_pending(
		'a transaction with no mode still signs its user in',
		'the transaction mode — 12.2'
	);
} else {
	sl_note( 'Transaction modes exist — replace these pendings with the live assertions from the 12.2 brief.' );
}

// ---------------------------------------------------------------------
sl_summary( 'Admin screens' );
