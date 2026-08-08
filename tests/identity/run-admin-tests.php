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

// ---------------------------------------------------------------------
/**
 * Mirrors SettingsScreen::field_applies(), deliberately.
 *
 * A second copy of a rule is normally the defect this project hunts, but a gate
 * that asked the screen whether the screen was right would assert nothing. The
 * duplication is the test.
 *
 * @param array $field Field spec.
 */
function sl_show_if_holds( array $field ): bool {
	foreach ( (array) ( $field['show_if'] ?? array() ) as $path => $expected ) {
		if ( (string) Settings::get( $path, '' ) !== (string) $expected ) {
			return false;
		}
	}

	return true;
}

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

		// A `show_if` field is drawn only while its condition holds, so the
		// invariant applies *under* the condition rather than unconditionally.
		// This is not an exemption like `conditional` above: the field says which
		// setting decides it, so the deliberate pass further down sets that
		// setting and requires the field to appear. A field that is never drawn
		// under any value of its own condition still fails.
		if ( ! sl_show_if_holds( $field ) ) {
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
sl_section( 'A provider secret that is really the provider id' );

/*
 * The save-time rule refuses this pair, but it cannot reach a site that already
 * holds one — and this site did, for as long as Zalo login had been switched on.
 * Zalo answered -14004 "Invalid secret key" to every attempt and the readiness
 * screen, whose whole job is to say what is wrong before a visitor finds out,
 * reported the provider as OK.
 */
Settings::update(
	array(
		'providers.google.enabled'   => 1,
		'providers.google.client_id' => '2895898387789399761',
	)
);
\SmartLogin\Auth\Providers\ProviderCredentials::store_secret( 'google', '2895898387789399761' );

$provider_check = null;

foreach ( ( new \SmartLogin\Admin\Readiness() )->checks() as $check ) {
	if ( 'providers' === $check['key'] ) {
		$provider_check = $check;
	}
}

sl_check(
	'a secret equal to the app id is reported',
	\SmartLogin\Admin\Readiness::WARN,
	$provider_check['status'] ?? 'missing'
);

sl_assert(
	'and the detail names the provider that holds it',
	false !== stripos( (string) ( $provider_check['detail'] ?? '' ), 'google' ),
	'An administrator with two providers configured needs to know which one to open. Detail was: ' . ( $provider_check['detail'] ?? '' )
);

\SmartLogin\Auth\Providers\ProviderCredentials::store_secret( 'google', 'a-secret-that-is-not-the-app-id' );

$provider_check_after = null;

foreach ( ( new \SmartLogin\Admin\Readiness() )->checks() as $check ) {
	if ( 'providers' === $check['key'] ) {
		$provider_check_after = $check;
	}
}

sl_check(
	'a correct pair clears it',
	\SmartLogin\Admin\Readiness::OK,
	$provider_check_after['status'] ?? 'missing'
);

Settings::update( array( 'providers.google.enabled' => 0 ) );
\SmartLogin\Auth\Providers\ProviderCredentials::clear_secret( 'google' );

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
		// `show_if` fields answer this question in their own pass, where the
		// condition is set. Here they would read as "drawn nowhere".
		if ( '' === (string) ( $field['tab'] ?? '' ) || ! empty( $field['conditional'] ) || ! sl_show_if_holds( $field ) ) {
			continue;
		}

		if ( false !== strpos( $html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( $path ) ) ) {
			$drawn_on[ $path ][] = $tab;
		}
	}
}

$wrong_home = array();

foreach ( FieldRegistry::all() as $path => $field ) {
	if ( '' === (string) ( $field['tab'] ?? '' ) || ! empty( $field['conditional'] ) || ! sl_show_if_holds( $field ) ) {
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

/*
 * Grouped the way an administrator reads it, not the way the registry stores it.
 *
 * 13.1 removed the "Cảnh báo quản trị" heading on purpose: the alerts are rows
 * in the message list now, beside the OTP messages, because a list that shows
 * four of six messages is not a list. The heading it used to carry is gone
 * rather than empty, and this assertion follows the screen instead of pinning
 * the layout it had in 11.4.
 */
$section_order = array();

foreach ( array( 'Mẫu mặc định', 'Mã xác thực', 'Giao diện email HTML' ) as $heading ) {
	$section_order[ $heading ] = strpos( $mail_markup, $heading );
}

// The alerts did not vanish with their heading — they are in the list.
foreach ( array( 'budget_halted', 'breaker_open' ) as $alert ) {
	sl_assert(
		sprintf( 'the "%s" alert is listed with the other messages', $alert ),
		false !== strpos( $mail_markup, 'data-mail-message="' . $alert . '"' ),
		'Dropping its section heading must not drop the message.'
	);
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

/*
 * Moving a control out of the field renderer is where a layout change turns into
 * a data change. The header draws the checkbox itself, so it also has to carry
 * the hidden companion input — without it an unticked box posts nothing,
 * sanitize() reads absence as "not on this tab", and a provider could be
 * switched on but never off.
 *
 * Asserted through sanitize() rather than by reading the markup, because the
 * markup is exactly what changed.
 */
Settings::update( array( 'providers.google.enabled' => 1 ) );

$saved_off = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'providers',
		'providers'         => array( 'google' => array( 'enabled' => '0' ) ),
	)
);

sl_check( 'a provider can be switched off from the header', 0, $saved_off['providers']['google']['enabled'] ?? 'missing' );

$saved_on = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'providers',
		'providers'         => array( 'google' => array( 'enabled' => '1' ) ),
	)
);

sl_check( 'and switched back on', 1, $saved_on['providers']['google']['enabled'] ?? 'missing' );

/*
 * Exactly two inputs carry this name: the hidden `0` and the checkbox `1`. Four
 * would mean the field is drawn in both its new home and its old one, and since
 * the last one posted wins, which value is stored would be decided by markup
 * order — the failure mode of moving a control without removing it.
 *
 * Counted rather than matched as one string: the attributes are on separate
 * lines, so a contiguous `name="…" value="1"` never appears and asserting on it
 * would have failed against correct markup.
 */
sl_check(
	'the switch is drawn in one place only',
	2,
	substr_count( $providers_html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( 'providers.google.enabled' ) . '"' )
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
/*
 * Structural half. The branch has to sit after the identity read — everything
 * above it is what the test exercises — and before AccountProvisioner, which is
 * the first thing that writes. Asserted on ordering inside callback(), because
 * "there is a branch" says nothing about which side of the writes it is on.
 */
$callback_body = sl_method_body(
	sl_source( 'includes/Auth/class-provider-auth-controller.php' ),
	'callback'
);

$test_branch_at  = strpos( $callback_body, 'report_test' );
$provisioner_at  = strpos( $callback_body, 'AccountProvisioner' );
$issuer_at       = strpos( $callback_body, 'SessionIssuer' );

sl_assert(
	'a test transaction returns before the session issuer',
	false !== $test_branch_at && false !== $issuer_at && $test_branch_at < $issuer_at,
	'callback() reaches SessionIssuer::issue(). A diagnostic that fell through would sign the administrator in.'
);

sl_assert(
	'and before anything that creates a user or an identity',
	false !== $test_branch_at && false !== $provisioner_at && $test_branch_at < $provisioner_at,
	'AccountProvisioner resolves or provisions. A test that reached it would create an account, and nobody would notice — signing in successfully is what success looks like.'
);

/*
 * Behavioural half. Transactions live in a transient with a ten-minute TTL, so
 * one created before this deploy has no `mode` key at all. Defaulting the wrong
 * way would turn every in-flight redirect across the deploy into a test that
 * silently refused to sign its user in.
 */
sl_check(
	'a transaction with no mode still signs its user in',
	false,
	\SmartLogin\Auth\OAuthTransactionStore::is_test( array( 'provider' => 'google' ) )
);

sl_check(
	'a login transaction is not a test',
	false,
	\SmartLogin\Auth\OAuthTransactionStore::is_test( ( new \SmartLogin\Auth\OAuthTransactionStore() )->create( 'google' ) )
);

sl_check(
	'a test transaction is',
	true,
	\SmartLogin\Auth\OAuthTransactionStore::is_test(
		( new \SmartLogin\Auth\OAuthTransactionStore( \SmartLogin\Auth\OAuthTransactionStore::MODE_TEST ) )->create( 'google' )
	)
);

// An unknown value must not become a test by accident — the constructor
// normalises rather than trusting whatever it is handed.
sl_check(
	'only the exact test mode counts as one',
	false,
	\SmartLogin\Auth\OAuthTransactionStore::is_test(
		( new \SmartLogin\Auth\OAuthTransactionStore( 'TEST' ) )->create( 'google' )
	)
);

// ---------------------------------------------------------------------
sl_section( 'A preset does not overwrite the fields it shares a tab with (15.5)' );

/*
 * Reported from a real screen: fill the inputs on Gửi mã, press Lưu thay đổi, and
 * everything comes back as it was.
 *
 * `otp.preset` lives on the `delivery` tab, and so do the six fields the preset owns.
 * sanitize() plants the posted values and then re-applies the preset over the top, so
 * every save of that tab discards what the administrator typed. Reproduced against the
 * real site before this rule was written: otp.ttl 300 -> 301 came back 300.
 */
Settings::update(
	array(
		'otp.preset' => 'balanced',
		'otp.ttl'    => 300,
	)
);

$sl_edit = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'delivery',
		'otp'               => array(
			'preset' => 'balanced',
			'ttl'    => 301,
		),
	)
);

sl_check(
	'a hand-edited OTP value survives the save',
	301,
	(int) ( $sl_edit['otp']['ttl'] ?? 0 )
);

// And the screen must not go on claiming a profile the stored values no longer match.
sl_check(
	'and the profile says custom once a value diverges from it',
	\SmartLogin\OtpPresets::CUSTOM,
	(string) ( $sl_edit['otp']['preset'] ?? '' )
);

/*
 * The second defect, which the first was hiding.
 *
 * A field the post does not carry used to be sanitised anyway, and null sanitises to
 * zero — which a number field clamps up to its minimum. Re-applying the profile
 * happened to overwrite the six fields where that would have been visible, so removing
 * the overwrite is what exposed it: otp.ttl 300 → 60 and otp.length 6 → 4, silently.
 *
 * A browser posts every input it draws, so this needs a partial post to show — which is
 * exactly what a programmatic caller, a REST client or a future partial form is.
 */
Settings::update( array( 'otp.preset' => 'balanced', 'otp.ttl' => 300, 'otp.length' => 6 ) );

$sl_partial = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'delivery',
		'otp'               => array( 'preset' => 'balanced' ),
	)
);

sl_check(
	'a field the post omits keeps its stored value, not its minimum',
	300,
	(int) ( $sl_partial['otp']['ttl'] ?? 0 )
);

sl_check(
	'and so does another on the same tab',
	6,
	(int) ( $sl_partial['otp']['length'] ?? 0 )
);

// The exception that stops this becoming "ignore absent fields": a browser posts
// nothing for an unchecked box, so absence is the value.
Settings::update( array( 'sms.enabled' => 1 ) );

$sl_unchecked = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'delivery-sms',
		'sms'               => array( 'preset' => 'custom' ),
	)
);

sl_check(
	'an unchecked checkbox still turns off',
	0,
	(int) ( $sl_unchecked['sms']['enabled'] ?? -1 )
);

// The other direction still has to work, or choosing a profile stops meaning anything.
$sl_pick = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'delivery',
		'otp'               => array(
			'preset' => 'strict',
			'ttl'    => 301,
		),
	)
);

sl_check(
	'choosing a different profile applies it',
	120,
	(int) ( $sl_pick['otp']['ttl'] ?? 0 )
);

sl_check(
	'and the chosen profile is what gets stored',
	'strict',
	(string) ( $sl_pick['otp']['preset'] ?? '' )
);

// ---------------------------------------------------------------------
sl_section( 'Saving survives WordPress sanitising twice (15.6)' );

/*
 * Captured from the real screen: choose "Cả hai", press Lưu thay đổi, and the page
 * comes back saying "Chỉ số điện thoại". The log of that request is the whole story.
 *
 *   sanitize IN  _sl_tab "auth"        -> OUT identity {"mode":"both"}
 *   sanitize IN  _sl_tab "(MISSING)"   -> OUT identity {"mode":"phone_only"}
 *
 * WordPress applies a sanitize filter more than once. `update_option()` sanitises,
 * then — when the stored value equals the value registered as the setting's default —
 * routes the write through `add_option()`, which sanitises **again**
 * (wp-includes/option.php:884 and :1111). The second pass receives the first pass's
 * output, which carries no `_sl_tab`, so `posted_fields()` found nothing, `$clean`
 * stayed at the stored values, and the correct answer was thrown away.
 *
 * The trigger is "stored == registered default", which is exactly the state a freshly
 * activated site is in. So this defect was invisible on any site that had ever saved
 * anything, and certain on one that had not.
 */
Settings::update( array( 'identity.mode' => 'phone_only' ) );

$sl_posted = array(
	Settings::TAB_FIELD => 'auth',
	'identity'          => array(
		'mode'                  => 'both',
		'country_code'          => '84',
		'allowed_country_codes' => '',
		'synthetic_domain'      => 'phone.invalid',
	),
);

$sl_first = Settings::sanitize( $sl_posted );

sl_check(
	'the first pass takes the posted value',
	'both',
	(string) ( $sl_first['identity']['mode'] ?? '' )
);

// The second pass is handed the first pass's output. It has no tab, because the
// output is registry-shaped and _sl_tab is not a registry field.
sl_assert(
	'and its output carries no tab field, which is what breaks the second pass',
	! isset( $sl_first[ Settings::TAB_FIELD ] )
);

$sl_second = Settings::sanitize( $sl_first );

sl_check(
	'the second pass keeps it',
	'both',
	(string) ( $sl_second['identity']['mode'] ?? '' )
);

// Idempotent, not merely non-destructive: a third application must agree with the
// second, or the value depends on how many times WordPress happens to filter it.
sl_check(
	'and a third pass agrees with the second',
	json_encode( $sl_second ),
	json_encode( Settings::sanitize( $sl_second ) )
);

// The tab scope still has to work, or this fix would have traded one bug for a
// bigger one: a save on one tab must not write another tab's fields.
Settings::update( array( 'identity.mode' => 'phone_only', 'otp.ttl' => 300 ) );

$sl_scoped = Settings::sanitize(
	array(
		Settings::TAB_FIELD => 'auth',
		'identity'          => array( 'mode' => 'both' ),
		// A delivery field posted on the auth tab must be ignored.
		'otp'               => array( 'ttl' => 999 ),
	)
);

sl_check( 'a tab-scoped save writes its own tab', 'both', (string) ( $sl_scoped['identity']['mode'] ?? '' ) );
sl_check( 'and does not write another tab field', 300, (int) ( $sl_scoped['otp']['ttl'] ?? 0 ) );

// ---------------------------------------------------------------------
sl_section( 'A conditional field is drawn when its condition holds (20.2)' );

/*
 * The other half of the `show_if` invariant. The coverage loop above skips a
 * field whose condition is false; without this, declaring `show_if` would be a
 * way to make a field disappear from the gate entirely — which is exactly the
 * failure that gate exists to catch, wearing a new hat.
 */
$sl_conditional_fields = array_filter(
	FieldRegistry::all(),
	static fn( array $field ): bool => ! empty( $field['show_if'] )
);

sl_assert(
	'at least one field declares a condition',
	array() !== $sl_conditional_fields,
	'Nothing to check, so the assertions below would pass vacuously.'
);

$sl_undrawn_when_shown = array();

foreach ( $sl_conditional_fields as $sl_path => $sl_field ) {
	$sl_restore = array();

	foreach ( (array) $sl_field['show_if'] as $sl_dep => $sl_value ) {
		$sl_restore[ $sl_dep ] = Settings::get( $sl_dep );
		Settings::update( array( $sl_dep => $sl_value ) );
	}

	$sl_shown_html = sl_capture(
		static function () use ( $screen, $sl_field ): void {
			$screen->render( (string) $sl_field['tab'] );
		}
	)['html'];

	if ( false === strpos( $sl_shown_html, 'name="' . \SmartLogin\Admin\FieldRenderer::name( $sl_path ) ) ) {
		$sl_undrawn_when_shown[] = $sl_path;
	}

	Settings::update( $sl_restore );
}

sl_check( 'every conditional field is drawn once its condition holds', array(), $sl_undrawn_when_shown );

// ---------------------------------------------------------------------
sl_section( 'Rule 16 — one label per concept (20.5)' );

/*
 * A string check, and it will annoy somebody in six months. The reason is
 * written here rather than left to be re-derived: this vocabulary took a working
 * install down to zero delivered codes, and the diagnosis cost a session with
 * database access. See docs/sending-a-code.md D4.
 *
 * Every visible string in the registry, by owner, so a failure names the thing
 * that has to change rather than the count that changed.
 */
$sl_visible_strings = array();

foreach ( FieldRegistry::tabs() as $sl_slug => $sl_label ) {
	$sl_visible_strings[ 'tab:' . $sl_slug ] = array( $sl_label );
}

foreach ( FieldRegistry::sections() as $sl_slug => $sl_label ) {
	$sl_visible_strings[ 'section:' . $sl_slug ] = array( $sl_label );
}

foreach ( FieldRegistry::all() as $sl_key => $sl_field ) {
	$sl_visible_strings[ $sl_key ] = array_merge(
		array( (string) ( $sl_field['label'] ?? '' ), (string) ( $sl_field['help'] ?? '' ) ),
		array_values( (array) ( $sl_field['choices'] ?? array() ) )
	);
}

/**
 * Which owners show a string, exactly or as a substring.
 *
 * @param array<string,string[]> $strings
 * @return string[]
 */
function sl_owners_showing( array $strings, string $needle, bool $exact ): array {
	$owners = array();

	foreach ( $strings as $owner => $values ) {
		foreach ( $values as $value ) {
			if ( $exact ? ( $needle === $value ) : ( '' !== $value && false !== strpos( $value, $needle ) ) ) {
				$owners[] = $owner;
				continue 2;
			}
		}
	}

	return $owners;
}

// Today: the identity provider's section, the SMS vendor, and the captcha
// vendor. Three concepts, one word, and two of them sit on delivery screens.
sl_check(
	'"Nhà cung cấp" names at most one concept',
	array(),
	array_slice( sl_owners_showing( $sl_visible_strings, 'Nhà cung cấp', true ), 1 )
);

// "Webhook" belongs to the event tab. On an SMS screen it is the word that sent
// an n8n URL to the wrong half of the plugin.
sl_check(
	'no SMS setting says "Webhook"',
	array(),
	array_values(
		array_filter(
			sl_owners_showing( $sl_visible_strings, 'Webhook', false ),
			static fn( string $owner ): bool => 0 === strpos( $owner, 'sms.' )
		)
	)
);

// "Automation" named a category of product rather than a behaviour.
sl_check(
	'no tab or section is called "Automation"',
	array(),
	array_values(
		array_filter(
			sl_owners_showing( $sl_visible_strings, 'Automation', false ),
			static fn( string $owner ): bool => 0 === strpos( $owner, 'tab:' ) || 0 === strpos( $owner, 'section:' )
		)
	)
);

// ---------------------------------------------------------------------
sl_section( 'Rule 17 — an enabled channel says whether it is serving anything (20.6)' );

/*
 * delivery-routing.md D2 required this two phases ago and no screen does it:
 *
 *   "configured but not delivering" and "broken" look identical otherwise
 *
 * Rendered with the exact configuration from the report — the channel enabled,
 * a real gateway URL saved, and routing pointed somewhere else — because that is
 * the state the administrator was looking at while nothing was being delivered.
 */
$sl_status_restore = array(
	'sms.enabled'          => Settings::get( 'sms.enabled' ),
	'sms.url'              => Settings::get( 'sms.url' ),
	'delivery.route_phone' => Settings::get( 'delivery.route_phone' ),
);

Settings::update(
	array(
		'sms.enabled'          => 1,
		'sms.url'              => 'https://gateway.example/send',
		'delivery.route_phone' => 'automation',
	)
);

$sl_idle_html = sl_capture(
	static function () use ( $screen ): void {
		$screen->render( 'delivery-sms' );
	}
)['html'];

Settings::update( $sl_status_restore );

sl_assert(
	'a channel screen declares whether it is serving a channel',
	false !== strpos( $sl_idle_html, 'data-sl-channel-status' ),
	'The screen renders no status at all, so an administrator cannot tell a configured channel from a serving one.'
);

sl_assert(
	'the reported configuration renders as not serving',
	false !== strpos( $sl_idle_html, 'data-sl-channel-status="idle"' ),
	'Enabled, a real URL saved, and nothing routed to it — the screen must say so. This is the exact state that produced the report.'
);

// ---------------------------------------------------------------------
sl_summary( 'Admin screens' );
