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

// =====================================================================
sl_section( 'Rule 6 — every REST route callback reaches the form guard (9.7)' );

$sl_rest_src   = sl_source( 'includes/Frontend/class-rest-controller.php' );
$sl_routes     = sl_method_body( $sl_rest_src, 'register_routes' );
$sl_unguarded  = array();
$sl_callbacks  = array();

if ( preg_match_all( "/=>\s*'(handle_[a-z_]+)'/", $sl_routes, $sl_m ) ) {
	$sl_callbacks = array_unique( $sl_m[1] );
}

foreach ( $sl_callbacks as $sl_callback ) {
	if ( false === strpos( sl_method_body( $sl_rest_src, $sl_callback ), 'verify_rest' ) ) {
		$sl_unguarded[] = $sl_callback . '()';
	}
}

sl_assert(
	'register_routes() declares at least one callback to check',
	array() !== $sl_callbacks,
	'The extraction found nothing, which means the rule is measuring its own regex rather than the code.'
);

sl_assert(
	sprintf( 'all %d route callbacks call RequestGuard::verify_rest()', count( $sl_callbacks ) ),
	array() === $sl_unguarded,
	'verify_rest() is called from handle_register() and nowhere else. And the JS sends no honeypot and no timestamp, so even where it is called there is nothing for it to inspect.'
);

foreach ( $sl_unguarded as $sl_offender ) {
	sl_note( '→ ' . $sl_offender );
}

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
