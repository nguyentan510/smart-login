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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\FieldRegistry;
use OmniWP\Identity\Phone;
use OmniWP\OTP\OtpRepository;
use OmniWP\OTP\Transports\WebhookTransport;
use OmniWP\Security\AuditLog;
use OmniWP\Security\Client;
use OmniWP\Security\RateLimiter;

// =====================================================================
ow_section( 'Rule 8 — every settings key the plugin reads is declared (9.9)' );

// The only rule here that fails on a defect already in production, which makes
// it the one whose red output proves the detector works rather than proving a
// feature is absent. Calls whose first argument is a variable are skipped: the
// field renderer reads by dynamic path by design, and flagging those would make
// the rule cry wolf on day one.
$ow_declared   = array_keys( FieldRegistry::all() );
$ow_undeclared = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( ! preg_match_all( "/Settings::(?:get|get_int|is_on)\(\s*'([^']+)'/", $ow_contents, $ow_m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_m[1] as $ow_capture ) {
		if ( in_array( $ow_capture[0], $ow_declared, true ) ) {
			continue;
		}

		$ow_line         = substr_count( substr( $ow_contents, 0, (int) $ow_capture[1] ), "\n" ) + 1;
		$ow_undeclared[] = $ow_relative . ':' . $ow_line . '  →  ' . $ow_capture[0];
	}
}

ow_assert(
	'no Settings::get() reads a key FieldRegistry does not declare',
	array() === $ow_undeclared,
	'A dot path that misses resolves to the fallback silently, so the stored value never takes effect. This is how the configured retention has been ignored since the settings rewrite.'
);

foreach ( $ow_undeclared as $ow_offender ) {
	ow_note( '→ ' . $ow_offender );
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
$ow_mapped = array_values( \OmniWP\Auth\AccountProvisioner::EMAIL_IDENTITY_FLAG );

ow_check(
	'8b — every settings path held in a provider flag map is declared too',
	'',
	implode( ', ', array_diff( $ow_mapped, $ow_declared ) )
);

ow_assert(
	'8b — and the map is not empty, so the rule has a subject',
	array() !== $ow_mapped,
	'A rule that passes because there is nothing to check states the opposite of the truth.'
);

// =====================================================================
ow_section( 'Rule 3 — a slow gateway must not hold a PHP worker (9.3)' );

$ow_long_sleeps = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( ! preg_match_all( '/usleep\(\s*([0-9_]+)\s*\)/', $ow_contents, $ow_m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_m[1] as $ow_i => $ow_capture ) {
		$ow_micros = (int) str_replace( '_', '', $ow_capture[0] );

		if ( $ow_micros <= 500000 ) {
			continue;
		}

		$ow_line          = substr_count( substr( $ow_contents, 0, (int) $ow_m[0][ $ow_i ][1] ), "\n" ) + 1;
		$ow_long_sleeps[] = sprintf( '%s:%d  →  %d ms', $ow_relative, $ow_line, (int) round( $ow_micros / 1000 ) );
	}
}

ow_assert(
	'3a — no usleep() over 500 ms anywhere in the plugin',
	array() === $ow_long_sleeps,
	'A sleep inside the request path is a worker held against the pool. At 10 req/s a 2s backoff occupies more workers than a typical PHP-FPM pool has.'
);

foreach ( $ow_long_sleeps as $ow_offender ) {
	ow_note( '→ ' . $ow_offender );
}

$ow_timeout_field = FieldRegistry::all()['sms.timeout'] ?? array();

ow_assert(
	'3b — FieldRegistry caps sms.timeout at 15 seconds or less',
	isset( $ow_timeout_field['max'] ) && (int) $ow_timeout_field['max'] <= 15,
	sprintf( 'Currently max=%s. The ceiling has to be unreachable from the settings screen, not merely un-chosen.', $ow_timeout_field['max'] ?? 'unset' )
);

$ow_dispatch = ow_method_body( ow_source( 'includes/OTP/Transports/class-webhook-transport.php' ), 'dispatch' );

// Deliberately not pinned to one expression. The first version of this rule
// matched `min( <digits>, Settings::get_int( 'sms.timeout'` and stayed red
// against a working clamp written as
// `min( self::MAX_TIMEOUT, max( 1, Settings::get_int( … ) ) )` — it was testing a
// spelling, not a property. What matters is that the read is bounded by a named
// ceiling; the behaviour itself is proved in run-tests.php, which drives a real
// dispatch and reads the timeout off the outgoing request.
ow_assert(
	'3c — a hard ceiling constant exists and is 15s or less',
	defined( WebhookTransport::class . '::MAX_TIMEOUT' ) && WebhookTransport::MAX_TIMEOUT <= 15,
	'The registry clamp runs on save. A site that stored 30 under the old maximum keeps it until somebody re-saves that tab, so the ceiling has to bind where the value is read.'
);

ow_assert(
	'3c — dispatch() applies that ceiling to the setting it reads',
	false !== strpos( $ow_dispatch, 'MAX_TIMEOUT' )
		&& false !== strpos( $ow_dispatch, "Settings::get_int( 'sms.timeout'" ),
	'Reading sms.timeout without bounding it against MAX_TIMEOUT puts the worker-exhaustion bug straight back.'
);

// =====================================================================
ow_section( 'Rule 1 — the send limiter consults a site-wide counter (9.1)' );

/**
 * A repository that records which counter was asked, rather than a smarter $wpdb.
 *
 * The stub wpdb is deliberately dumb — get_var() returns one global whatever the
 * query — so every count_* method is indistinguishable through it and a test
 * driven that way would be theatre. RateLimiter already injects its repository,
 * and nothing in the chain is final, so the seam needed for an honest assertion
 * is one the production code already offers.
 */
class ow_Spy_Otp_Repository extends OtpRepository {

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

$ow_spy = new ow_Spy_Otp_Repository();
$ow_ok  = ( new RateLimiter( $ow_spy ) )->check_otp_send( '84969789475', 'register' );

ow_assert(
	'check_otp_send() asks for a site-wide count',
	in_array( 'site_wide', $ow_spy->asked, true ),
	sprintf(
		'Asked only for: %s. Every one of those is scoped to a single destination or a single IP, so an attacker rotating both meets no ceiling at all.',
		implode( ', ', $ow_spy->asked ) ?: 'nothing'
	)
);

// =====================================================================
ow_section( 'Rule 2 — a country code outside the allowlist is refused (9.2)' );

ow_assert(
	'a Vietnamese mobile is still valid',
	Phone::is_valid( '84969789475' ),
	'The allowlist must not break the default configuration it exists to protect.'
);

ow_assert(
	'a number outside the allowed country codes is refused',
	false === Phone::is_valid( '254712345678' ),
	'is_valid() applies carrier-prefix rules only to 84 and falls through to a generic 8-15 digit check for everything else. That is the precondition SMS pumping needs: codes aimed at a premium range in a revenue-sharing country.'
);

// =====================================================================
ow_section( 'Rule 4 — the identify step is rate limited before it answers (9.4)' );

/*
 * Repointed in 19.1, when the state machine moved out of FormController into
 * FlowEngine so a second transport could drive it. The rule is about the
 * ordering inside the step, and the step is now `FlowEngine::identify()`;
 * `FormController::handle_identify()` is one line that delegates.
 *
 * The rule did its job on the way past: it went red the moment the body moved,
 * which is exactly what a rule reading a method body is for. It is repointed
 * rather than relaxed — the ordering it asserts is the ordering that keeps the
 * lookup from being a free enumeration oracle.
 */
$ow_identify = ow_method_body( ow_source( 'includes/Auth/class-flow-engine.php' ), 'identify' );

ow_assert(
	'FlowEngine::identify() was found, so the ordering rule has a subject',
	'' !== $ow_identify,
	'A body-reading rule with no body reports green for want of anything to inspect.'
);

// The offset comparison below is only sound while there is exactly one lookup in
// this method. That is a property of today's tree, not a law — so it is asserted
// rather than assumed. A second resolve() added later would otherwise make the
// ordering check meaningless while it kept reporting green.
ow_check(
	'identify() performs exactly one directory lookup',
	1,
	substr_count( $ow_identify, 'resolve(' )
);

$ow_pos_guard  = strpos( $ow_identify, 'check_identify' );
$ow_pos_lookup = strpos( $ow_identify, 'resolve(' );

ow_assert(
	'check_identify() is called before the directory lookup',
	false !== $ow_pos_guard && false !== $ow_pos_lookup && $ow_pos_guard < $ow_pos_lookup,
	'RateLimiter is reached only from inside OtpService::issue(), i.e. the "no such account" branch. A subject that exists returns the password screen having passed no limiter, so the registered numbers can be enumerated at zero cost — and the README claims otherwise.'
);

// Pinning today's call site alone would let the gap reopen through a route added
// later. 19.1 added the /identify REST route this comment used to say did not
// exist — and it passes for the right reason rather than by exemption: the route
// delegates to FlowEngine, which spends the budget before it resolves anything.
// One state machine, one limiter, both transports.
$ow_unguarded_lookups = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( false === strpos( $ow_contents, '->resolve(' ) ) {
		continue;
	}

	// Each exemption is a resolve() an anonymous visitor cannot drive as an
	// oracle, and each one has to be justified here rather than waved through.
	$ow_allowed = array(
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

	if ( in_array( $ow_relative, $ow_allowed, true ) ) {
		continue;
	}

	if ( false === strpos( $ow_contents, 'check_identify' ) ) {
		$ow_unguarded_lookups[] = $ow_relative;
	}
}

ow_assert(
	'no entry point resolves a subject without spending the identify budget',
	array() === $ow_unguarded_lookups,
	'A REST /identify route added later would reopen the enumeration oracle that this sub-phase closed on the form path.'
);

foreach ( $ow_unguarded_lookups as $ow_offender ) {
	ow_note( '→ ' . $ow_offender );
}

// =====================================================================
ow_section( 'Rule 9 — the login path has a ceiling of its own (9.6)' );

// Password spraying — one common password against ten thousand accounts from one
// address — never trips a lock keyed on (identity, IP): each account records a
// single failure and none reaches five. Structural rather than behavioural,
// because the behaviour is pinned in run-tests.php; what this stops is somebody
// quietly deleting the second counter later.
$ow_limiter_src = ow_source( 'includes/Security/class-rate-limiter.php' );

// Checked in two hops rather than as one string in one body. Delegating to a
// private helper is ordinary structure, not evasion, and a rule that forbids it
// is testing a spelling — the mistake rule 3c made in 9.3. Each hop still means
// something: delete the call and the first fails, gut the helper and the second
// does.
ow_assert(
	'record_login_failure() reaches the IP counter',
	false !== strpos( ow_method_body( $ow_limiter_src, 'record_login_failure' ), 'record_ip_failure' ),
	'A lock keyed on (identity, IP) gives the sprayer a fresh budget per account, which is the whole attack.'
);

ow_assert(
	'record_ip_failure() is keyed on the address alone',
	false !== strpos( ow_method_body( $ow_limiter_src, 'record_ip_failure' ), 'ip_lock_key' ),
	'Mixing the identity back into this key would recreate the hole it was added to close.'
);

ow_assert(
	'gate_lockout() consults the IP lock as well as the identity lock',
	false !== strpos(
		ow_method_body( ow_source( 'includes/Auth/class-login-handler.php' ), 'gate_lockout' ),
		'ip_lock_remaining'
	),
	'A counter nothing reads is a counter that does not exist. gate_lockout() is on the authenticate filter, so it covers wp-login.php, WooCommerce and application passwords in one place.'
);

// =====================================================================
ow_section( 'Rule 6 — every REST route callback reaches the form guard (9.7)' );

$ow_rest_src  = ow_source( 'includes/Frontend/class-rest-controller.php' );
$ow_routes    = ow_method_body( $ow_rest_src, 'register_routes' );
$ow_callbacks = array();

if ( preg_match_all( "/=>\s*'(handle_[a-z_]+)'/", $ow_routes, $ow_m ) ) {
	$ow_callbacks = array_unique( $ow_m[1] );
}

ow_assert(
	'register_routes() declares at least one callback to check',
	array() !== $ow_callbacks,
	'The extraction found nothing, which means the rule is measuring its own regex rather than the code.'
);

// Reachability, not repetition. The guard lives in the shared permission
// callback, so eleven copies would be eleven chances to forget the twelfth. What
// has to hold is that every route is gated and the gate calls the guard.
$ow_permissions = array();

if ( preg_match_all( "/'permission_callback'\s*=>\s*array\(\s*\\\$this,\s*'([a-z_]+)'/", $ow_routes, $ow_m ) ) {
	$ow_permissions = array_unique( $ow_m[1] );
}

ow_assert(
	'every route is gated by a permission callback this class owns',
	array() !== $ow_permissions
		&& array() === array_diff( $ow_permissions, array( 'check_permission', 'check_contact_permission' ) ),
	'A route registered with __return_true, or with a gate not listed here, bypasses the guard entirely.'
);

ow_assert(
	'check_permission() runs RequestGuard::verify_rest()',
	false !== strpos( ow_method_body( $ow_rest_src, 'check_permission' ), 'verify_rest' ),
	'Ten of eleven callbacks called nothing before 9.7, and the JS sent no honeypot and no timestamp — so even the one that did call it had nothing to inspect.'
);

ow_assert(
	'check_contact_permission() delegates to check_permission()',
	false !== strpos( ow_method_body( $ow_rest_src, 'check_contact_permission' ), 'check_permission' ),
	'The authenticated routes must not acquire a second, weaker gate.'
);

// The other half of 9.7: a guard with nothing to inspect is inert, not strict.
ow_assert(
	'the JS sends the honeypot and the signed timestamp',
	false !== strpos( ow_source( 'assets/js/omniwp.js' ), 'OMNIWP_ts' )
		&& false !== strpos( ow_source( 'assets/js/omniwp.js' ), 'OMNIWP_website' ),
	'RequestGuard::verify_rest() can only check fields the client actually sends.'
);

// =====================================================================
ow_section( 'Rule 10 — the challenge is adaptive, closed and quiet (9.8)' );

$ow_captcha_file = 'includes/Security/class-captcha.php';
$ow_captcha_src  = ow_source( $ow_captcha_file );

ow_assert(
	'a Captcha class exists',
	'' !== $ow_captcha_src,
	'9.8 adds includes/Security/class-captcha.php.'
);

// Fail closed. This is the opposite of the Client::ip() decision in 9.5 and
// deliberately so: there, failing open protects legitimate CLI traffic against
// an attack the budget already covers; here, the only thing failing open
// protects is the attacker.
ow_assert(
	'verification that cannot complete refuses the request',
	'' !== $ow_captcha_src && class_exists( '\OmniWP\Security\Captcha' )
		&& false === \OmniWP\Security\Captcha::verify_token( 'anything', 'https://127.0.0.1:9/never' ),
	'A network error, a timeout or a malformed response must all be a refusal.'
);

// Adaptive is the default, so the widget has to be absent on a quiet day or it
// is a conversion bug wearing a security label.
ow_assert(
	'adaptive mode reads the pressure signals rather than showing always',
	'' !== $ow_captcha_src
		&& false !== strpos( $ow_captcha_src, 'halted_for' )
		&& false !== strpos( $ow_captcha_src, 'count_recent_all' ),
	'Adaptive mode is defined by the budget and breaker state it consults; without them it is just "always" with extra steps.'
);

// Same discipline as 9.3: an outbound call in the request path is a worker held.
ow_assert(
	'the verification call is bounded by a hard timeout',
	'' !== $ow_captcha_src && false !== strpos( $ow_captcha_src, 'MAX_TIMEOUT' ),
	'A captcha endpoint that hangs must not become the worker-exhaustion bug a second time.'
);

ow_forbid_pattern(
	'the captcha secret never reaches the DOM',
	'/value="[^"]*captcha_secret/',
	array(),
	'Secrets are write-only in this plugin: stored encrypted, never echoed back into a form.'
);

// =====================================================================
ow_section( 'Rule 7 — the audit log stops amplifying the attack it records (9.9)' );

$GLOBALS['wpdb']->writes = array();

for ( $ow_i = 0; $ow_i < 600; $ow_i++ ) {
	AuditLog::record( AuditLog::LOGIN_FAILED, '096••••475', array( 'reason' => 'test' ) );
}

$ow_written = count( $GLOBALS['wpdb']->writes );

ow_assert(
	'600 identical events do not become 600 rows',
	$ow_written < 600,
	sprintf( '%d rows written. An attack costs the operator an unbounded INSERT stream and a table that outgrows its daily sweep — the audit log is currently an amplifier for the attack it exists to record.', $ow_written )
);

$GLOBALS['wpdb']->writes = array();

// =====================================================================
ow_section( 'Rule 5 — a proxy header is trusted only from a trusted peer (9.5)' );

$_SERVER['REMOTE_ADDR']            = '203.0.113.9';
$_SERVER['HTTP_CF_CONNECTING_IP']  = '198.51.100.7';

remove_all_filters( 'OMNIWP_trust_proxy_headers' );

ow_check(
	'with trust off, REMOTE_ADDR wins',
	'203.0.113.9',
	Client::ip()
);

ow_assert(
	'Client::in_cidr() exists',
	method_exists( Client::class, 'in_cidr' ),
	'Trust has to be conditional on the peer, which needs a CIDR match. 9.5 adds it.'
);

add_filter( 'omniwp_trust_proxy_headers',
	static function () {
		return true;
	}
);

ow_assert(
	'with trust on but no trusted CIDR configured, the header is still ignored',
	'203.0.113.9' === Client::ip(),
	'A bare "trust the headers" flag is worse than nothing: an attacker who reaches the origin directly then sets CF-Connecting-IP per request and dissolves every per-IP limit in the plugin. The header may only be trusted when the peer is.'
);

remove_all_filters( 'OMNIWP_trust_proxy_headers' );

ow_summary( 'Abuse boundary' );
