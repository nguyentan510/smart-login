<?php
/**
 * Abuse-boundary guard rails.
 *
 * Normative spec: docs/abuse-boundary.md. Brief: docs/abuse-boundary/9.0-guard-rails.md.
 *
 * This suite lands **red on purpose**. Nine of its ten assertions describe
 * controls that do not exist yet, and the tenth describes a defect that has been
 * shipping since the settings rewrite. A rule written after its fix cannot fail,
 * and a rule that has never failed is a comment — so every rule here is
 * demonstrated failing on the tree that still contains the gap, and the red
 * output goes in the commit message as the evidence.
 *
 * Each rule names the sub-phase that turns it green, so a red run doubles as a
 * progress report. Registered `spec` in run-all.php; promoted to `required` the
 * moment it goes green, for the reason Phase 5 promoted the identity suites.
 *
 * Run with:  php tests/security/run-abuse-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\FieldRegistry;
use SmartLogin\Identity\Phone;
use SmartLogin\OTP\OtpRepository;
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\Client;
use SmartLogin\Security\RateLimiter;

// =====================================================================
sl_section( 'Rule 8 — every settings key the plugin reads is declared (9.9)' );

// The only rule here that fails on a defect already in production, which makes
// it the one whose red output proves the detector works rather than proving a
// feature is absent. Calls whose first argument is a variable are skipped: the
// field renderer reads by dynamic path by design, and flagging those would make
// the rule cry wolf on day one.
$sl_declared   = array_keys( FieldRegistry::all() );
$sl_undeclared = array();

foreach ( sl_plugin_sources() as $sl_relative => $sl_contents ) {
	if ( ! preg_match_all( "/Settings::(?:get|get_int|is_on)\(\s*'([^']+)'/", $sl_contents, $sl_m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $sl_m[1] as $sl_capture ) {
		if ( in_array( $sl_capture[0], $sl_declared, true ) ) {
			continue;
		}

		$sl_line         = substr_count( substr( $sl_contents, 0, (int) $sl_capture[1] ), "\n" ) + 1;
		$sl_undeclared[] = $sl_relative . ':' . $sl_line . '  →  ' . $sl_capture[0];
	}
}

sl_assert(
	'no Settings::get() reads a key FieldRegistry does not declare',
	array() === $sl_undeclared,
	'A dot path that misses resolves to the fallback silently, so the stored value never takes effect. This is how the configured retention has been ignored since the settings rewrite.'
);

foreach ( $sl_undeclared as $sl_offender ) {
	sl_note( '→ ' . $sl_offender );
}

/*
 * The same rule for keys a map holds rather than a call site.
 *
 * 14.4 needed a per-provider setting, and the natural spelling —
 * `Settings::is_on( 'providers.' . $slug . '.email_identity' )` — went red above,
 * correctly. The fix was a constant map of literal paths, which the regex cannot
 * see: the first argument is a variable, and rule 8 skips those on purpose so the
 * field renderer does not make it cry wolf. That is a hole this map would otherwise
 * open in the rule that exists to catch exactly this mistake, so the map is asserted
 * directly.
 */
$sl_mapped = array_values( \SmartLogin\Auth\AccountProvisioner::EMAIL_IDENTITY_FLAG );

sl_check(
	'8b — every settings path held in a provider flag map is declared too',
	'',
	implode( ', ', array_diff( $sl_mapped, $sl_declared ) )
);

sl_assert(
	'8b — and the map is not empty, so the rule has a subject',
	array() !== $sl_mapped,
	'A rule that passes because there is nothing to check states the opposite of the truth.'
);

// =====================================================================
sl_section( 'Rule 3 — a slow gateway must not hold a PHP worker (9.3)' );

$sl_long_sleeps = array();

foreach ( sl_plugin_sources() as $sl_relative => $sl_contents ) {
	if ( ! preg_match_all( '/usleep\(\s*([0-9_]+)\s*\)/', $sl_contents, $sl_m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $sl_m[1] as $sl_i => $sl_capture ) {
		$sl_micros = (int) str_replace( '_', '', $sl_capture[0] );

		if ( $sl_micros <= 500000 ) {
			continue;
		}

		$sl_line          = substr_count( substr( $sl_contents, 0, (int) $sl_m[0][ $sl_i ][1] ), "\n" ) + 1;
		$sl_long_sleeps[] = sprintf( '%s:%d  →  %d ms', $sl_relative, $sl_line, (int) round( $sl_micros / 1000 ) );
	}
}

sl_assert(
	'3a — no usleep() over 500 ms anywhere in the plugin',
	array() === $sl_long_sleeps,
	'A sleep inside the request path is a worker held against the pool. At 10 req/s a 2s backoff occupies more workers than a typical PHP-FPM pool has.'
);

foreach ( $sl_long_sleeps as $sl_offender ) {
	sl_note( '→ ' . $sl_offender );
}

$sl_timeout_field = FieldRegistry::all()['sms.timeout'] ?? array();

sl_assert(
	'3b — FieldRegistry caps sms.timeout at 15 seconds or less',
	isset( $sl_timeout_field['max'] ) && (int) $sl_timeout_field['max'] <= 15,
	sprintf( 'Currently max=%s. The ceiling has to be unreachable from the settings screen, not merely un-chosen.', $sl_timeout_field['max'] ?? 'unset' )
);

$sl_dispatch = sl_method_body( sl_source( 'includes/OTP/Transports/class-webhook-transport.php' ), 'dispatch' );

// Deliberately not pinned to one expression. The first version of this rule
// matched `min( <digits>, Settings::get_int( 'sms.timeout'` and stayed red
// against a working clamp written as
// `min( self::MAX_TIMEOUT, max( 1, Settings::get_int( … ) ) )` — it was testing a
// spelling, not a property. What matters is that the read is bounded by a named
// ceiling; the behaviour itself is proved in run-tests.php, which drives a real
// dispatch and reads the timeout off the outgoing request.
sl_assert(
	'3c — a hard ceiling constant exists and is 15s or less',
	defined( WebhookTransport::class . '::MAX_TIMEOUT' ) && WebhookTransport::MAX_TIMEOUT <= 15,
	'The registry clamp runs on save. A site that stored 30 under the old maximum keeps it until somebody re-saves that tab, so the ceiling has to bind where the value is read.'
);

sl_assert(
	'3c — dispatch() applies that ceiling to the setting it reads',
	false !== strpos( $sl_dispatch, 'MAX_TIMEOUT' )
		&& false !== strpos( $sl_dispatch, "Settings::get_int( 'sms.timeout'" ),
	'Reading sms.timeout without bounding it against MAX_TIMEOUT puts the worker-exhaustion bug straight back.'
);

// =====================================================================
sl_section( 'Rule 1 — the send limiter consults a site-wide counter (9.1)' );

/**
 * A repository that records which counter was asked, rather than a smarter $wpdb.
 *
 * The stub wpdb is deliberately dumb — get_var() returns one global whatever the
 * query — so every count_* method is indistinguishable through it and a test
 * driven that way would be theatre. RateLimiter already injects its repository,
 * and nothing in the chain is final, so the seam needed for an honest assertion
 * is one the production code already offers.
 */
class SL_Spy_Otp_Repository extends OtpRepository {

	/** @var string[] */
	public $asked = array();

	public function last_sent_at( string $destination, string $intent ): int {
		$this->asked[] = 'last_sent_at';

		return 0;
	}

	public function count_recent_by_destination( string $destination, int $seconds ): int {
		$this->asked[] = 'by_destination';

		return 0;
	}

	public function count_recent_by_ip( ?string $ip_binary, int $seconds ): int {
		$this->asked[] = 'by_ip';

		return 0;
	}

	/** Does not exist on the parent yet — 9.1 adds it. */
	public function count_recent_all( int $seconds ): int {
		$this->asked[] = 'site_wide';

		return 0;
	}
}

$sl_spy = new SL_Spy_Otp_Repository();
$sl_ok  = ( new RateLimiter( $sl_spy ) )->check_otp_send( '84969789475', 'register' );

sl_assert(
	'check_otp_send() asks for a site-wide count',
	in_array( 'site_wide', $sl_spy->asked, true ),
	sprintf(
		'Asked only for: %s. Every one of those is scoped to a single destination or a single IP, so an attacker rotating both meets no ceiling at all.',
		implode( ', ', $sl_spy->asked ) ?: 'nothing'
	)
);

// =====================================================================
sl_section( 'Rule 2 — a country code outside the allowlist is refused (9.2)' );

sl_assert(
	'a Vietnamese mobile is still valid',
	Phone::is_valid( '84969789475' ),
	'The allowlist must not break the default configuration it exists to protect.'
);

sl_assert(
	'a number outside the allowed country codes is refused',
	false === Phone::is_valid( '254712345678' ),
	'is_valid() applies carrier-prefix rules only to 84 and falls through to a generic 8-15 digit check for everything else. That is the precondition SMS pumping needs: codes aimed at a premium range in a revenue-sharing country.'
);

// =====================================================================
sl_section( 'Rule 4 — the identify step is rate limited before it answers (9.4)' );

$sl_identify = sl_method_body( sl_source( 'includes/Frontend/class-form-controller.php' ), 'handle_identify' );

// The offset comparison below is only sound while there is exactly one lookup in
// this method. That is a property of today's tree, not a law — so it is asserted
// rather than assumed. A second resolve() added later would otherwise make the
// ordering check meaningless while it kept reporting green.
sl_check(
	'handle_identify() performs exactly one directory lookup',
	1,
	substr_count( $sl_identify, 'resolve(' )
);

$sl_pos_guard  = strpos( $sl_identify, 'check_identify' );
$sl_pos_lookup = strpos( $sl_identify, 'resolve(' );

sl_assert(
	'check_identify() is called before the directory lookup',
	false !== $sl_pos_guard && false !== $sl_pos_lookup && $sl_pos_guard < $sl_pos_lookup,
	'RateLimiter is reached only from inside OtpService::issue(), i.e. the "no such account" branch. A subject that exists returns the password screen having passed no limiter, so the registered numbers can be enumerated at zero cost — and the README claims otherwise.'
);

// Pinning today's call site alone would let the gap reopen through a route added
// later. There is no /identify REST route now; the rule is what keeps any future
// one from resolving a subject without spending the same budget.
$sl_unguarded_lookups = array();

foreach ( sl_plugin_sources() as $sl_relative => $sl_contents ) {
	if ( false === strpos( $sl_contents, '->resolve(' ) ) {
		continue;
	}

	// Each exemption is a resolve() an anonymous visitor cannot drive as an
	// oracle, and each one has to be justified here rather than waved through.
	$sl_allowed = array(
		// The directory is the thing being called, not a caller.
		'includes/Identity/class-identity-directory.php',
		// Behind wp_authenticate(); metered by the login lockout instead.
		'includes/Auth/class-login-handler.php',
		// Requires a signed-in user.
		'includes/Auth/class-identity-link-service.php',
		'includes/Auth/class-contact-verification-service.php',
		// Reached only after an OTP has been verified, so the code that got here
		// was already paid for at issue time.
		'includes/Auth/class-account-provisioner.php',
		'includes/Auth/class-register-handler.php',
		'includes/Identity/class-user-manager.php',
		// Runs on the provider callback, which carries a signed OAuth state.
		'includes/Auth/class-provider-auth-controller.php',
	);

	if ( in_array( $sl_relative, $sl_allowed, true ) ) {
		continue;
	}

	if ( false === strpos( $sl_contents, 'check_identify' ) ) {
		$sl_unguarded_lookups[] = $sl_relative;
	}
}

sl_assert(
	'no entry point resolves a subject without spending the identify budget',
	array() === $sl_unguarded_lookups,
	'A REST /identify route added later would reopen the enumeration oracle that this sub-phase closed on the form path.'
);

foreach ( $sl_unguarded_lookups as $sl_offender ) {
	sl_note( '→ ' . $sl_offender );
}

// =====================================================================
sl_section( 'Rule 9 — the login path has a ceiling of its own (9.6)' );

// Password spraying — one common password against ten thousand accounts from one
// address — never trips a lock keyed on (identity, IP): each account records a
// single failure and none reaches five. Structural rather than behavioural,
// because the behaviour is pinned in run-tests.php; what this stops is somebody
// quietly deleting the second counter later.
$sl_limiter_src = sl_source( 'includes/Security/class-rate-limiter.php' );

// Checked in two hops rather than as one string in one body. Delegating to a
// private helper is ordinary structure, not evasion, and a rule that forbids it
// is testing a spelling — the mistake rule 3c made in 9.3. Each hop still means
// something: delete the call and the first fails, gut the helper and the second
// does.
sl_assert(
	'record_login_failure() reaches the IP counter',
	false !== strpos( sl_method_body( $sl_limiter_src, 'record_login_failure' ), 'record_ip_failure' ),
	'A lock keyed on (identity, IP) gives the sprayer a fresh budget per account, which is the whole attack.'
);

sl_assert(
	'record_ip_failure() is keyed on the address alone',
	false !== strpos( sl_method_body( $sl_limiter_src, 'record_ip_failure' ), 'ip_lock_key' ),
	'Mixing the identity back into this key would recreate the hole it was added to close.'
);

sl_assert(
	'gate_lockout() consults the IP lock as well as the identity lock',
	false !== strpos(
		sl_method_body( sl_source( 'includes/Auth/class-login-handler.php' ), 'gate_lockout' ),
		'ip_lock_remaining'
	),
	'A counter nothing reads is a counter that does not exist. gate_lockout() is on the authenticate filter, so it covers wp-login.php, WooCommerce and application passwords in one place.'
);

// =====================================================================
sl_section( 'Rule 6 — every REST route callback reaches the form guard (9.7)' );

$sl_rest_src  = sl_source( 'includes/Frontend/class-rest-controller.php' );
$sl_routes    = sl_method_body( $sl_rest_src, 'register_routes' );
$sl_callbacks = array();

if ( preg_match_all( "/=>\s*'(handle_[a-z_]+)'/", $sl_routes, $sl_m ) ) {
	$sl_callbacks = array_unique( $sl_m[1] );
}

sl_assert(
	'register_routes() declares at least one callback to check',
	array() !== $sl_callbacks,
	'The extraction found nothing, which means the rule is measuring its own regex rather than the code.'
);

// Reachability, not repetition. The guard lives in the shared permission
// callback, so eleven copies would be eleven chances to forget the twelfth. What
// has to hold is that every route is gated and the gate calls the guard.
$sl_permissions = array();

if ( preg_match_all( "/'permission_callback'\s*=>\s*array\(\s*\\\$this,\s*'([a-z_]+)'/", $sl_routes, $sl_m ) ) {
	$sl_permissions = array_unique( $sl_m[1] );
}

sl_assert(
	'every route is gated by a permission callback this class owns',
	array() !== $sl_permissions
		&& array() === array_diff( $sl_permissions, array( 'check_permission', 'check_contact_permission' ) ),
	'A route registered with __return_true, or with a gate not listed here, bypasses the guard entirely.'
);

sl_assert(
	'check_permission() runs RequestGuard::verify_rest()',
	false !== strpos( sl_method_body( $sl_rest_src, 'check_permission' ), 'verify_rest' ),
	'Ten of eleven callbacks called nothing before 9.7, and the JS sent no honeypot and no timestamp — so even the one that did call it had nothing to inspect.'
);

sl_assert(
	'check_contact_permission() delegates to check_permission()',
	false !== strpos( sl_method_body( $sl_rest_src, 'check_contact_permission' ), 'check_permission' ),
	'The authenticated routes must not acquire a second, weaker gate.'
);

// The other half of 9.7: a guard with nothing to inspect is inert, not strict.
sl_assert(
	'the JS sends the honeypot and the signed timestamp',
	false !== strpos( sl_source( 'assets/js/smart-login.js' ), 'smart_login_ts' )
		&& false !== strpos( sl_source( 'assets/js/smart-login.js' ), 'smart_login_website' ),
	'RequestGuard::verify_rest() can only check fields the client actually sends.'
);

// =====================================================================
sl_section( 'Rule 10 — the challenge is adaptive, closed and quiet (9.8)' );

$sl_captcha_file = 'includes/Security/class-captcha.php';
$sl_captcha_src  = sl_source( $sl_captcha_file );

sl_assert(
	'a Captcha class exists',
	'' !== $sl_captcha_src,
	'9.8 adds includes/Security/class-captcha.php.'
);

// Fail closed. This is the opposite of the Client::ip() decision in 9.5 and
// deliberately so: there, failing open protects legitimate CLI traffic against
// an attack the budget already covers; here, the only thing failing open
// protects is the attacker.
sl_assert(
	'verification that cannot complete refuses the request',
	'' !== $sl_captcha_src && class_exists( '\SmartLogin\Security\Captcha' )
		&& false === \SmartLogin\Security\Captcha::verify_token( 'anything', 'https://127.0.0.1:9/never' ),
	'A network error, a timeout or a malformed response must all be a refusal.'
);

// Adaptive is the default, so the widget has to be absent on a quiet day or it
// is a conversion bug wearing a security label.
sl_assert(
	'adaptive mode reads the pressure signals rather than showing always',
	'' !== $sl_captcha_src
		&& false !== strpos( $sl_captcha_src, 'halted_for' )
		&& false !== strpos( $sl_captcha_src, 'count_recent_all' ),
	'Adaptive mode is defined by the budget and breaker state it consults; without them it is just "always" with extra steps.'
);

// Same discipline as 9.3: an outbound call in the request path is a worker held.
sl_assert(
	'the verification call is bounded by a hard timeout',
	'' !== $sl_captcha_src && false !== strpos( $sl_captcha_src, 'MAX_TIMEOUT' ),
	'A captcha endpoint that hangs must not become the worker-exhaustion bug a second time.'
);

sl_forbid_pattern(
	'the captcha secret never reaches the DOM',
	'/value="[^"]*captcha_secret/',
	array(),
	'Secrets are write-only in this plugin: stored encrypted, never echoed back into a form.'
);

// =====================================================================
sl_section( 'Rule 7 — the audit log stops amplifying the attack it records (9.9)' );

$GLOBALS['wpdb']->writes = array();

for ( $sl_i = 0; $sl_i < 600; $sl_i++ ) {
	AuditLog::record( AuditLog::LOGIN_FAILED, '096••••475', array( 'reason' => 'test' ) );
}

$sl_written = count( $GLOBALS['wpdb']->writes );

sl_assert(
	'600 identical events do not become 600 rows',
	$sl_written < 600,
	sprintf( '%d rows written. An attack costs the operator an unbounded INSERT stream and a table that outgrows its daily sweep — the audit log is currently an amplifier for the attack it exists to record.', $sl_written )
);

$GLOBALS['wpdb']->writes = array();

// =====================================================================
sl_section( 'Rule 5 — a proxy header is trusted only from a trusted peer (9.5)' );

$_SERVER['REMOTE_ADDR']            = '203.0.113.9';
$_SERVER['HTTP_CF_CONNECTING_IP']  = '198.51.100.7';

remove_all_filters( 'smart_login_trust_proxy_headers' );

sl_check(
	'with trust off, REMOTE_ADDR wins',
	'203.0.113.9',
	Client::ip()
);

sl_assert(
	'Client::in_cidr() exists',
	method_exists( Client::class, 'in_cidr' ),
	'Trust has to be conditional on the peer, which needs a CIDR match. 9.5 adds it.'
);

add_filter(
	'smart_login_trust_proxy_headers',
	static function () {
		return true;
	}
);

sl_assert(
	'with trust on but no trusted CIDR configured, the header is still ignored',
	'203.0.113.9' === Client::ip(),
	'A bare "trust the headers" flag is worse than nothing: an attacker who reaches the origin directly then sets CF-Connecting-IP per request and dissolves every per-IP limit in the plugin. The header may only be trusted when the peer is.'
);

remove_all_filters( 'smart_login_trust_proxy_headers' );

sl_summary( 'Abuse boundary' );
