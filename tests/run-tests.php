<?php
/**
 * Standalone tests for the pure-logic parts of Smart Login.
 *
 * Run with:  php tests/run-tests.php
 *
 * These cover the two places most likely to be wrong and cheapest to test:
 * phone normalisation and the template placeholder engine, plus name splitting
 * and date-of-birth parsing.
 *
 * @package SmartLogin
 */

require __DIR__ . '/stubs.php';

use SmartLogin\Address\AddressFields;
use SmartLogin\Address\AddressNormalizer;
use SmartLogin\Address\AddressRepository;
use SmartLogin\Address\WooAddress;
use SmartLogin\Auth\AuthContext;
use SmartLogin\Auth\OAuthTransactionStore;
use SmartLogin\Auth\PendingSession;
use SmartLogin\Auth\ProviderAuthController;
use SmartLogin\Auth\Providers\GoogleIdTokenVerifier;
use SmartLogin\Auth\Providers\LoginProviderInterface;
use SmartLogin\Auth\Providers\OAuthAuthorizationUrl;
use SmartLogin\Auth\Providers\ProviderIdentity;
use SmartLogin\Auth\Providers\ProviderCredentials;
use SmartLogin\Auth\Providers\ProviderRedirect;
use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\UserManager;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\OTP\OtpRepository;
use SmartLogin\OTP\OtpService;
use SmartLogin\OTP\Placeholders;
use SmartLogin\Installer;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\Client;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Security\RequestGuard;
use SmartLogin\Settings;

$passed = 0;
$failed = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function check( string $label, $expected, $actual ): void {
	global $passed, $failed;

	if ( $expected === $actual ) {
		++$passed;
		return;
	}

	++$failed;
	printf(
		"  FAIL  %s\n         expected: %s\n         actual:   %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

function section( string $title ): void {
	echo "\n" . $title . "\n";
}

class SecurityTestOtpRepository extends OtpRepository {

	/** @var array<string,mixed> */
	public $row;

	/** @var bool */
	public $consume_result = true;

	/** @var int */
	public $consume_calls = 0;

	public function __construct( array $row ) {
		$this->row = $row;
	}

	public function find_by_token( string $token ): ?array {
		return 'test-token' === $token ? $this->row : null;
	}

	public function consume_if_open( int $id ): bool {
		++$this->consume_calls;
		return $this->consume_result;
	}
}

class FakeLoginProvider implements LoginProviderInterface {
	private $provider_id;
	private $available;

	public function __construct( string $provider_id, bool $available ) {
		$this->provider_id = $provider_id;
		$this->available   = $available;
	}

	public function id(): string {
		return $this->provider_id;
	}

	public function label(): string {
		return strtoupper( $this->provider_id );
	}

	public function name(): string {
		return ucfirst( $this->provider_id );
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function begin( string $return_url = '', bool $linking = false ): ProviderRedirect {
		return new ProviderRedirect( 'https://provider.example.test/start' );
	}

	public function complete( array $request ) {
		return new ProviderIdentity( array( 'provider' => $this->provider_id, 'subject' => 'subject-1' ) );
	}
}

// ---------------------------------------------------------------------
section( 'Provider contracts and OAuth transaction safety' );

$identity = new ProviderIdentity(
	array(
		'provider'       => 'Google',
		'subject'        => 'google-subject',
		'email'          => 'USER@Example.COM',
		'email_verified' => true,
		'claims'         => array( 'sub' => 'google-subject' ),
	)
);
check( 'provider id is canonical', 'google', $identity->provider );
check( 'provider email is canonical', 'user@example.com', $identity->email );
check( 'verified provider email stays verified', true, $identity->email_verified );

$registry = new ProviderRegistry(
	array(
		new FakeLoginProvider( 'enabled', true ),
		new FakeLoginProvider( 'disabled', false ),
	)
);
check( 'registry returns provider by id', true, $registry->get( 'enabled' ) instanceof LoginProviderInterface );
check( 'registry only exposes configured providers', array( 'enabled' ), array_keys( $registry->available() ) );

$oauth = new OAuthTransactionStore();
$transaction = $oauth->create( 'google', 'https://example.test/account', false, 0 );
check( 'OAuth transaction stores a nonce', true, '' !== $transaction['nonce'] );
check( 'OAuth transaction stores a PKCE verifier', true, strlen( $transaction['pkce_verifier'] ) >= 64 );
$consumed = $oauth->consume( $transaction['state'], 'google' );
check( 'OAuth state can be consumed once', true, is_array( $consumed ) );
check( 'OAuth state replay is rejected', true, is_wp_error( $oauth->consume( $transaction['state'], 'google' ) ) );

$context = new AuthContext( array( 'auth_method' => 'google', 'provider' => 'Google', 'is_new_user' => true ) );
check( 'AuthContext canonicalises provider', 'google', $context->provider );
check( 'AuthContext generates correlation id', 32, strlen( $context->correlation_id ) );

$bad_google_token = ( new GoogleIdTokenVerifier() )->verify( 'not-a-jwt' );
check( 'malformed Google ID token fails closed', 'smart_login_google_claims', $bad_google_token->get_error_code() );

// ---------------------------------------------------------------------
section( 'OAuth authorization URL safety' );

$callback_url = 'http://localhost:10004/wp-admin/admin-post.php?action=smart_login_provider_callback&provider=google';
$authorize_url = OAuthAuthorizationUrl::build(
	'https://accounts.google.com/o/oauth2/v2/auth',
	array(
		'client_id'    => 'client.apps.googleusercontent.com',
		'redirect_uri' => $callback_url,
		'response_type' => 'code',
	)
);
$authorize_query = array();
parse_str( (string) parse_url( $authorize_url, PHP_URL_QUERY ), $authorize_query );
check( 'nested OAuth callback survives query parsing', $callback_url, $authorize_query['redirect_uri'] ?? '' );
check( 'callback provider does not escape into OAuth query', false, array_key_exists( 'provider', $authorize_query ) );
check( 'nested callback ampersand is RFC3986 encoded', true, false !== strpos( $authorize_url, '%26provider%3Dgoogle' ) );
check( 'provider failures leave admin-post callback', 'https://example.test/?smart_login_step=login', ProviderAuthController::failure_url() );

// ---------------------------------------------------------------------
section( 'Provider credential storage' );

Settings::update(
	array(
		'providers.google.client_id' => 'google-client-from-settings',
		'providers.zalo.app_id'      => 'zalo-app-from-settings',
	)
);
check( 'Google client ID falls back to Settings', 'google-client-from-settings', ProviderCredentials::client_id( 'google' ) );
check( 'Zalo app ID falls back to Settings', 'zalo-app-from-settings', ProviderCredentials::client_id( 'zalo' ) );

$google_secret = 'google-secret-must-not-be-plaintext';
check( 'Google secret can be encrypted', true, ProviderCredentials::store_secret( 'google', $google_secret ) );
$encrypted_credentials = get_option( ProviderCredentials::SECRET_OPTION, array() );
check( 'encrypted option does not contain plaintext secret', false, false !== strpos( wp_json_encode( $encrypted_credentials ), $google_secret ) );
check( 'encrypted Google secret round-trips', $google_secret, ProviderCredentials::secret( 'google' ) );
check( 'Google provider is configured from Settings', true, ProviderCredentials::is_configured( 'google' ) );

Settings::sanitize(
	array_merge(
		Settings::all(),
		array(
			'google_client_secret' => '',
			'google_clear_secret'  => 0,
		)
	)
);
check( 'blank secret input preserves encrypted secret', $google_secret, ProviderCredentials::secret( 'google' ) );
check( 'explicit secret clear succeeds', true, ProviderCredentials::clear_secret( 'google' ) );
check( 'cleared secret is no longer available', '', ProviderCredentials::secret( 'google' ) );

// ---------------------------------------------------------------------
section( 'Phone::normalize — Vietnamese input formats' );

$cases = array(
	'0969789475'       => '84969789475',
	'+84969789475'     => '84969789475',
	'84969789475'      => '84969789475',
	'0084969789475'    => '84969789475',
	'969789475'        => '84969789475',
	'096 978 9475'     => '84969789475',
	'096-978-9475'     => '84969789475',
	'(096) 978.9475'   => '84969789475',
	' +84 96 978 9475' => '84969789475',
	'0906123456'       => '84906123456',
	'0328888888'       => '84328888888',
);

foreach ( $cases as $input => $expected ) {
	check( "normalize('{$input}')", $expected, Phone::normalize( $input ) );
}

check( "normalize('') is empty", '', Phone::normalize( '' ) );
check( "normalize('abc') is empty", '', Phone::normalize( 'abc' ) );
check( 'normalize rejects over-long input', '', Phone::normalize( '+8412345678901234567' ) );

// ---------------------------------------------------------------------
section( 'Phone::is_valid — carrier prefixes' );

$valid = array( '84969789475', '84328888888', '84523456789', '84706543210', '84812345678', '84987654321' );

foreach ( $valid as $number ) {
	check( "is_valid('{$number}')", true, Phone::is_valid( $number ) );
}

$invalid = array(
	'84123456789', // 1x is not a mobile prefix.
	'84212345678', // 2x is a landline area code.
	'84312345678', // 31 is not allocated.
	'84512345678', // 51 is not allocated.
	'8471234567',  // Too short.
	'',
	'84abc456789',
);

foreach ( $invalid as $number ) {
	check( "is_valid('{$number}') is false", false, Phone::is_valid( $number ) );
}

// ---------------------------------------------------------------------
section( 'Phone — country-code allowlist (Phase 9.2)' );

// Empty means "the default country code only", not "anything". The whole
// migration story rests on that reading, so it is the first thing asserted.
Settings::update( array( 'identity.allowed_country_codes' => '' ) );

check( 'an empty allowlist falls back to the default country code', array( '84' ), Phone::allowed_codes() );
check( 'a Vietnamese mobile is still valid', true, Phone::is_valid( '84969789475' ) );

// The pumping case. Before 9.2 this returned true: is_valid() applied carrier
// rules only to 84 and let every other country through a generic length check.
check( 'a Kenyan number is refused at the default', false, Phone::is_valid( '254712345678' ) );
check( 'a UK number is refused at the default', false, Phone::is_valid( '447700900123' ) );

Settings::update( array( 'identity.allowed_country_codes' => '84, 254' ) );
check( 'widening the list admits the country', true, Phone::is_valid( '254712345678' ) );
check( 'and does not admit one still off the list', false, Phone::is_valid( '447700900123' ) );
check( 'the list tolerates spaces and separators', array( '84', '254' ), Phone::allowed_codes() );

// A widened list must not weaken the numbering-plan check on the codes that
// have one. 84 keeps its carrier prefixes whatever else is allowed.
check( 'a widened list does not weaken the Vietnamese prefix check', false, Phone::is_valid( '84123456789' ) );

// The documented escape hatch has to survive the new branch, in both
// directions — a filter that can only tighten is not the last word.
Settings::update( array( 'identity.allowed_country_codes' => '84' ) );
add_filter(
	'smart_login_phone_is_valid',
	static function ( $valid, $canonical ) {
		return 0 === strpos( $canonical, '254' ) ? true : $valid;
	},
	10,
	2
);

check( 'the filter can still admit a number the allowlist refused', true, Phone::is_valid( '254712345678' ) );
remove_all_filters( 'smart_login_phone_is_valid' );

Settings::update( array( 'identity.allowed_country_codes' => '' ) );

// ---------------------------------------------------------------------
section( 'Phone::to_local / mask' );

check( 'to_local', '0969789475', Phone::to_local( '84969789475' ) );
check( 'mask hides the middle', '096••••475', Phone::mask( '84969789475' ) );
check( 'mask_email', 'ng••••@example.com', Phone::mask_email( 'nguyen@example.com' ) );
check( 'mask_email on a short local part', 'ab••@x.vn', Phone::mask_email( 'ab@x.vn' ) );

// ---------------------------------------------------------------------
section( 'Phone::looks_like_phone' );

check( 'digits look like a phone', true, Phone::looks_like_phone( '0969789475' ) );
check( 'an email does not', false, Phone::looks_like_phone( 'a@b.com' ) );
check( 'a word does not', false, Phone::looks_like_phone( 'nguyenvan' ) );

// ---------------------------------------------------------------------
section( 'RateLimiter — canonical login identity' );

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$limiter                = new RateLimiter( new OtpRepository() );
$login_key              = new ReflectionMethod( $limiter, 'login_key' );
$login_key->setAccessible( true );

$lock_keys = array();
foreach ( array( '0969789475', '+84969789475', '096 978 9475', '096-978-9475' ) as $variant ) {
	$lock_keys[] = $login_key->invoke( $limiter, $variant );
}

check( 'phone formatting variants share one lock key', 1, count( array_unique( $lock_keys ) ) );

// ---------------------------------------------------------------------
section( 'RequestGuard::verify_rest — parity with the form path (Phase 9.7)' );

check( 'a tripped honeypot is refused', true, is_wp_error( RequestGuard::verify_rest( 'rest', array( 'smart_login_website' => 'bot' ) ) ) );

// A stamp younger than the minimum fill time is a machine, not a person.
$fresh = RequestGuard::stamp( 'rest' );
check( 'a stamp from this instant is too fast', 'smart_login_too_fast', RequestGuard::verify_rest( 'rest', array( 'smart_login_ts' => $fresh ) )->get_error_code() );

// A forged signature must not pass, whatever the timestamp says.
check( 'a forged stamp is refused', 'smart_login_bad_stamp', RequestGuard::verify_rest( 'rest', array( 'smart_login_ts' => ( time() - 60 ) . '.deadbeef' ) )->get_error_code() );

// Signed for a different action is a different secret.
check( 'a stamp signed for another action is refused', 'smart_login_bad_stamp', RequestGuard::verify_rest( 'rest', array( 'smart_login_ts' => RequestGuard::stamp( 'login' ) ) )->get_error_code() );

// The assertion that keeps this from being a breaking change: a cookie-less
// native client sends no stamp at all and must still be served.
check( 'a request with no stamp still passes', true, RequestGuard::verify_rest( 'rest', array() ) );

// ---------------------------------------------------------------------
section( 'AuditLog — the log stops amplifying the attack (Phase 9.9)' );

Settings::update(
	array(
		'advanced.audit_enabled'           => 1,
		'security.audit_max_per_event_hour' => 10,
	)
);

$GLOBALS['sl_transients'] = array();
$GLOBALS['wpdb']->writes  = array();

for ( $i = 0; $i < 200; $i++ ) {
	AuditLog::record( AuditLog::LOGIN_FAILED, '096••••475', array( 'reason' => 'test' ) );
}

$written = count( $GLOBALS['wpdb']->writes );

// Ten detailed rows, then one summary saying the rest were dropped.
check( '200 identical events become 11 rows', 11, $written );

$events = array_map(
	static function ( $write ) {
		return $write['data']['event'] ?? '';
	},
	$GLOBALS['wpdb']->writes
);

check( 'the last row is a summary', 'login_failed_summary', end( $events ) );
check( 'only one summary is written', 1, count( array_filter( $events, static fn( $e ) => 'login_failed_summary' === $e ) ) );

// A lockout is the record of the attack succeeding. Dropping it to survive the
// flood would throw away the one row worth keeping.
$GLOBALS['wpdb']->writes = array();

for ( $i = 0; $i < 50; $i++ ) {
	AuditLog::record( AuditLog::LOCKOUT, '096••••475', array( 'minutes' => 15 ) );
}

check( 'lockout events are never sampled', 50, count( $GLOBALS['wpdb']->writes ) );

$GLOBALS['wpdb']->writes  = array();
$GLOBALS['sl_transients'] = array();
Settings::update( array( 'security.audit_max_per_event_hour' => 0 ) );

for ( $i = 0; $i < 30; $i++ ) {
	AuditLog::record( AuditLog::OTP_FAILED, '096••••475' );
}

check( 'a cap of 0 records everything', 30, count( $GLOBALS['wpdb']->writes ) );

Settings::update( array( 'security.audit_max_per_event_hour' => 500 ) );
$GLOBALS['wpdb']->writes  = array();
$GLOBALS['sl_transients'] = array();

// ---------------------------------------------------------------------
section( 'Installer::cleanup — the configured retention finally applies (Phase 9.9)' );

// This read flat keys the settings rewrite had renamed, so it always fell back
// to the hardcoded 7/90 and the operator's setting had never once taken effect.
// Asserted through the SQL the stub captures, because the bug was invisible from
// every other angle.
Settings::update(
	array(
		'advanced.otp_retention_days'   => 3,
		'advanced.audit_retention_days' => 11,
	)
);

$GLOBALS['wpdb']->writes = array();
Installer::cleanup();

$queries = array_column( $GLOBALS['wpdb']->writes, 'sql' );

check( 'cleanup issues two deletes', 2, count( $queries ) );
check( 'the OTP delete uses the configured 3 days', true, false !== strpos( (string) ( $queries[0] ?? '' ), gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ) ) );
check( 'the audit delete uses the configured 11 days', true, false !== strpos( (string) ( $queries[1] ?? '' ), gmdate( 'Y-m-d', time() - ( 11 * DAY_IN_SECONDS ) ) ) );

Settings::update(
	array(
		'advanced.otp_retention_days'   => 7,
		'advanced.audit_retention_days' => 90,
	)
);
$GLOBALS['wpdb']->writes = array();

// ---------------------------------------------------------------------
section( 'RateLimiter — password spraying has a ceiling (Phase 9.6)' );

Settings::update(
	array(
		'security.max_login_failures_per_ip_hour' => 5,
		'security.ip_lockout_minutes'             => 15,
		'login.max_attempts'                      => 5,
	)
);

$GLOBALS['sl_transients'] = array();
$_SERVER['REMOTE_ADDR']   = '203.0.113.40';

$spray = new RateLimiter( new OtpRepository() );

// Five failures against five *different* accounts. The per-account counter never
// gets past one, which is exactly why spraying used to walk straight through.
foreach ( array( '0969789101', '0969789102', '0969789103', '0969789104', '0969789105' ) as $victim ) {
	$spray->record_login_failure( $victim );
}

check( 'no single account is locked by a spray', 0, $spray->login_lock_remaining( '0969789101' ) );
check( 'but the address is', true, $spray->ip_lock_remaining() > 0 );

// A different address is untouched — the ceiling must not be global.
$_SERVER['REMOTE_ADDR'] = '203.0.113.41';
check( 'a different address is unaffected', 0, $spray->ip_lock_remaining() );

// A success on one account must not clear the sweep counter: one correct
// password among a thousand guesses is what a successful spray looks like.
$_SERVER['REMOTE_ADDR'] = '203.0.113.40';
$spray->clear_login_failures( '0969789101' );
check( 'a success does not clear the address-wide lock', true, $spray->ip_lock_remaining() > 0 );

// The per-account lock still works on its own, unchanged by any of this.
$GLOBALS['sl_transients'] = array();
$_SERVER['REMOTE_ADDR']   = '203.0.113.42';
Settings::update( array( 'security.max_login_failures_per_ip_hour' => 0 ) );

for ( $i = 0; $i < 5; $i++ ) {
	$spray->record_login_failure( '0969789200' );
}

check( 'the per-account lock still trips at its own threshold', true, $spray->login_lock_remaining( '0969789200' ) > 0 );
check( 'and 0 disables the address ceiling', 0, $spray->ip_lock_remaining() );

Settings::update(
	array(
		'security.max_login_failures_per_ip_hour' => 30,
		'security.ip_lockout_minutes'             => 15,
	)
);
$GLOBALS['sl_transients'] = array();

// ---------------------------------------------------------------------
section( 'Client::in_cidr — v4 and v6 (Phase 9.5)' );

check( 'exact v4 host', true, Client::in_cidr( '1.2.3.4', '1.2.3.4' ) );
check( 'v4 inside /24', true, Client::in_cidr( '1.2.3.200', '1.2.3.0/24' ) );
check( 'v4 outside /24', false, Client::in_cidr( '1.2.4.1', '1.2.3.0/24' ) );
check( 'v4 on a non-byte boundary, inside', true, Client::in_cidr( '173.245.63.255', '173.245.48.0/20' ) );
check( 'v4 on a non-byte boundary, outside', false, Client::in_cidr( '173.245.64.0', '173.245.48.0/20' ) );
check( 'v6 inside /32', true, Client::in_cidr( '2400:cb00:1::5', '2400:cb00::/32' ) );
check( 'v6 outside /32', false, Client::in_cidr( '2400:cb01::1', '2400:cb00::/32' ) );
check( 'v4 address against a v6 range never matches', false, Client::in_cidr( '1.2.3.4', '2400:cb00::/32' ) );
check( 'v6 address against a v4 range never matches', false, Client::in_cidr( '2400:cb00::1', '1.2.3.0/24' ) );

// The dangerous parse. `10.0.0.0/oops` must not become /0 and trust everything,
// which is what a plain (int) cast on the suffix would have produced.
check( 'a non-numeric prefix length is rejected, not read as /0', false, Client::in_cidr( '203.0.113.7', '10.0.0.0/oops' ) );
check( 'an out-of-range prefix length is rejected', false, Client::in_cidr( '1.2.3.4', '1.2.3.0/33' ) );
check( 'garbage is rejected', false, Client::in_cidr( '1.2.3.4', 'not-an-address' ) );
check( 'an empty range matches nothing', false, Client::in_cidr( '1.2.3.4', '' ) );

// ---------------------------------------------------------------------
section( 'Client::ip — a header is trusted only from a trusted peer (Phase 9.5)' );

$GLOBALS['sl_filters']            = array();
$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

Settings::update(
	array(
		'security.trust_proxy'         => 0,
		'security.trusted_proxy_cidrs' => '',
	)
);
check( 'trust off: REMOTE_ADDR wins', '203.0.113.9', Client::ip() );

// The configuration the whole design exists to refuse: trust enabled, but no
// range says which peers may be believed.
Settings::update( array( 'security.trust_proxy' => 1 ) );
check( 'trust on with no ranges: the header is still ignored', '203.0.113.9', Client::ip() );

// An untrusted peer sending the identical header gets nowhere. This is the
// origin-bypass case — attacker reaches the origin directly and forges a fresh
// client IP per request.
Settings::update( array( 'security.trusted_proxy_cidrs' => '198.51.100.0/24' ) );
check( 'a peer outside the ranges is not believed', '203.0.113.9', Client::ip() );

// The same header from a peer inside the ranges is honoured.
Settings::update( array( 'security.trusted_proxy_cidrs' => '203.0.113.0/24' ) );
check( 'a peer inside the ranges is believed', '198.51.100.7', Client::ip() );

// A trusted peer forwarding rubbish must not produce a bogus identity.
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check( 'an unparseable forwarded value falls back to the peer', '203.0.113.9', Client::ip() );

// X-Forwarded-For is a chain; the client is leftmost.
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.8, 70.41.3.18';
check( 'the leftmost entry of a forwarded chain is the client', '198.51.100.8', Client::ip() );

// The legacy filter enables trust but no longer grants it on its own: with no
// ranges configured there is still no peer it is willing to believe.
Settings::update(
	array(
		'security.trust_proxy'         => 0,
		'security.trusted_proxy_cidrs' => '',
	)
);
add_filter( 'smart_login_trust_proxy_headers', '__return_true' );
check( 'the legacy filter alone does not grant trust', '203.0.113.9', Client::ip() );

// Paired with a range filter, a managed deployment still works without settings.
add_filter(
	'smart_login_trusted_proxy_cidrs',
	static function () {
		return array( '203.0.113.0/24' );
	}
);
check( 'filter-configured deployments still work', '198.51.100.8', Client::ip() );

$GLOBALS['sl_filters'] = array();
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
Settings::update(
	array(
		'security.trust_proxy'         => 0,
		'security.trusted_proxy_cidrs' => '',
	)
);

// ---------------------------------------------------------------------
section( 'RateLimiter — the identify oracle costs something (Phase 9.4)' );

Settings::update( array( 'security.max_identify_per_ip_hour' => 3 ) );
$GLOBALS['sl_transients'] = array();
$_SERVER['REMOTE_ADDR']   = '203.0.113.20';

$idfy = new RateLimiter( new OtpRepository() );

// Three different identifiers. Keying on the identity would give each its own
// budget, which is exactly the hole being closed, so all three must share one.
check( 'first lookup allowed', true, true === $idfy->check_identify( '0969789001' ) );
check( 'second lookup allowed', true, true === $idfy->check_identify( '0969789002' ) );
check( 'third lookup allowed', true, true === $idfy->check_identify( '0969789003' ) );

$over = $idfy->check_identify( '0969789004' );
check( 'a fourth distinct identifier from one IP is refused', true, is_wp_error( $over ) );
check( 'the refusal is the identify limit', 'smart_login_identify_limit', is_wp_error( $over ) ? $over->get_error_code() : '' );

// The whole point: refusal must not depend on whether the subject exists, or
// the limit becomes the oracle it was meant to close.
$_SERVER['REMOTE_ADDR'] = '203.0.113.21';
check( 'a different IP has its own budget', true, true === $idfy->check_identify( '0969789004' ) );

Settings::update( array( 'security.max_identify_per_ip_hour' => 0 ) );
$GLOBALS['sl_transients'] = array();
$_SERVER['REMOTE_ADDR']   = '203.0.113.22';
for ( $i = 0; $i < 10; $i++ ) {
	$idfy->check_identify( '096978900' . $i );
}
check( 'a limit of 0 disables the check', true, true === $idfy->check_identify( '0969789999' ) );

Settings::update( array( 'security.max_identify_per_ip_hour' => 30 ) );
$GLOBALS['sl_transients'] = array();

// ---------------------------------------------------------------------
section( 'RateLimiter — site-wide budget and kill switch (Phase 9.1)' );

/**
 * Counts what it was asked for, so a ceiling can be driven without SQL.
 *
 * The stub wpdb returns one global whatever the query is, which makes every
 * count_* method indistinguishable through it. RateLimiter injects its
 * repository, so the honest instrument is a repository, not a smarter wpdb.
 */
class SmartLoginBudgetRepo extends OtpRepository {

	/** @var int Rows the site is pretending to have sent this hour. */
	public $sent = 0;

	/** @var int How many times a counting query was run. */
	public $counted = 0;

	public function last_sent_at( string $destination, string $intent ): int {
		return 0;
	}

	public function count_recent_by_destination( string $destination, int $seconds ): int {
		++$this->counted;
		return 0;
	}

	public function count_recent_by_ip( ?string $ip_binary, int $seconds ): int {
		++$this->counted;
		return 0;
	}

	public function count_recent_all( int $seconds ): int {
		++$this->counted;
		return $this->sent;
	}
}

Settings::update(
	array(
		'security.max_per_site_hour' => 2,
		'security.max_per_site_day'  => 0,
		'security.halt_minutes'      => 60,
		'otp.max_per_destination_hour' => 0,
		'otp.max_per_ip_hour'          => 0,
		'otp.resend_cooldown'          => 0,
	)
);

RateLimiter::resume();
$GLOBALS['sl_mails']    = array();
$GLOBALS['sl_options']['admin_email'] = 'ops@example.test';

$budget_repo = new SmartLoginBudgetRepo();
$budget      = new RateLimiter( $budget_repo );

// Two sends to two different destinations from two different addresses. Neither
// is near any per-destination or per-IP limit; both are disabled above.
$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
check( 'first send is allowed', true, true === $budget->check_otp_send( '84969789001', 'register' ) );

$budget_repo->sent      = 1;
$_SERVER['REMOTE_ADDR'] = '203.0.113.12';
check( 'second send is allowed', true, true === $budget->check_otp_send( '84969789002', 'register' ) );

// The third crosses the ceiling. A third destination and a third address, so
// nothing but the site-wide counter can be refusing it.
$budget_repo->sent      = 2;
$_SERVER['REMOTE_ADDR'] = '203.0.113.13';
$refused                = $budget->check_otp_send( '84969789003', 'register' );

check( 'a third distinct destination on a third IP is refused', true, is_wp_error( $refused ) );
check( 'the refusal does not name the site budget', 'smart_login_unavailable', is_wp_error( $refused ) ? $refused->get_error_code() : '' );
check( 'the halt is recorded', true, $budget->halted_for() > 0 );
check( 'crossing the ceiling mails the admin once', 1, count( $GLOBALS['sl_mails'] ) );

// While halted, the limiter must shed load rather than add to it: no counting
// query at all, and still no second mail.
$budget_repo->counted = 0;
$again                = $budget->check_otp_send( '84969789004', 'register' );

check( 'a halted site is refused', true, is_wp_error( $again ) );
check( 'a halted site runs no counting query', 0, $budget_repo->counted );
check( 'a halted site does not mail again', 1, count( $GLOBALS['sl_mails'] ) );

RateLimiter::resume();
check( 'resume() clears the halt', 0, $budget->halted_for() );

// A ceiling of 0 means unlimited, not "refuse everything" — the setting help
// says so, and reading it the other way would take a site offline on save.
Settings::update( array( 'security.max_per_site_hour' => 0 ) );
$budget_repo->sent = 9999;
check( 'a ceiling of 0 disables the limit', true, true === $budget->check_otp_send( '84969789005', 'register' ) );

Settings::update(
	array(
		'security.max_per_site_hour' => 100,
		'security.max_per_site_day'  => 500,
	)
);
RateLimiter::resume();

// ---------------------------------------------------------------------
section( 'UserManager::split_name — Vietnamese name order' );

$name = UserManager::split_name( 'Nguyễn Ngọc Tân' );
check( 'given name is last token', 'Tân', $name['first'] );
check( 'family name is the rest', 'Nguyễn Ngọc', $name['last'] );

$mono = UserManager::split_name( 'Tân' );
check( 'mononym first', 'Tân', $mono['first'] );
check( 'mononym last is empty', '', $mono['last'] );

$spaced = UserManager::split_name( '  Trần   Thị  Mai  ' );
check( 'collapses whitespace (first)', 'Mai', $spaced['first'] );
check( 'collapses whitespace (last)', 'Trần Thị', $spaced['last'] );

// ---------------------------------------------------------------------
section( 'RegisterHandler::parse_dob' );

require_once SMART_LOGIN_DIR . 'includes/Auth/class-register-handler.php';

check( 'dd/mm/yyyy', '1994-10-05', \SmartLogin\Auth\RegisterHandler::parse_dob( '05/10/1994' ) );
check( 'yyyy-mm-dd', '1994-10-05', \SmartLogin\Auth\RegisterHandler::parse_dob( '1994-10-05' ) );
check( 'dd-mm-yyyy', '1994-10-05', \SmartLogin\Auth\RegisterHandler::parse_dob( '05-10-1994' ) );
check( 'rejects day 32', '', \SmartLogin\Auth\RegisterHandler::parse_dob( '32/10/1994' ) );
check( 'rejects month 13', '', \SmartLogin\Auth\RegisterHandler::parse_dob( '05/13/1994' ) );
check( 'rejects a future date', '', \SmartLogin\Auth\RegisterHandler::parse_dob( '01/01/2999' ) );
check( 'rejects year 1800', '', \SmartLogin\Auth\RegisterHandler::parse_dob( '01/01/1800' ) );
check( 'rejects gibberish', '', \SmartLogin\Auth\RegisterHandler::parse_dob( 'hôm qua' ) );
check( 'empty stays empty', '', \SmartLogin\Auth\RegisterHandler::parse_dob( '' ) );

// ---------------------------------------------------------------------
section( 'Placeholders — JSON safety' );

$map = Placeholders::build(
	'84969789475',
	'123456',
	array(
		'purpose'     => 'register',
		'channel'     => 'sms',
		'ttl_seconds' => 300,
	)
);

check( 'phone token', '84969789475', $map['phone'] );
check( 'phone_local token', '0969789475', $map['phone_local'] );
check( 'phone_plus token', '+84969789475', $map['phone_plus'] );
check( 'email token is empty for SMS', '', $map['email'] );
check( 'code token', '123456', $map['code'] );
check( 'ttl_minutes token', '5', $map['ttl_minutes'] );

$body = Placeholders::render(
	'{"phone":"{{phone_local}}","content":"{{code}} - {{site_name}}"}',
	$map,
	array( Placeholders::class, 'json_escape' )
);

check( 'rendered body is valid JSON', true, null !== json_decode( $body ) );

$decoded = json_decode( $body, true );
check( 'JSON phone value', '0969789475', $decoded['phone'] );
check( 'JSON content value', '123456 - Cửa hàng Demo', $decoded['content'] );

// A site name containing a quote must not break the payload.
$map['site_name'] = 'Shop "ABC" \\ Ltd';
$body             = Placeholders::render( '{"content":"{{site_name}}"}', $map, array( Placeholders::class, 'json_escape' ) );

check( 'quotes in values keep JSON valid', true, null !== json_decode( $body ) );
check( 'quoted value round-trips', 'Shop "ABC" \\ Ltd', json_decode( $body, true )['content'] );

// Form-encoded rendering.
$query = Placeholders::render( 'to={{phone_local}}&msg={{code}}', $map, 'rawurlencode' );
check( 'form-encoded query', 'to=0969789475&msg=123456', $query );

// Email destination flips the tokens around.
$email_map = Placeholders::build( 'a@b.com', '999', array( 'ttl_seconds' => 120 ) );
check( 'email token set', 'a@b.com', $email_map['email'] );
check( 'phone token empty for email', '', $email_map['phone'] );
check( 'ttl_minutes rounds', '2', $email_map['ttl_minutes'] );

$delivery_map = Placeholders::build( '84969789475', '123456', array( 'delivery_id' => 'delivery-123' ) );
check( 'delivery id token', 'delivery-123', $delivery_map['delivery_id'] );

// ---------------------------------------------------------------------
section( 'OTP security boundaries' );

Settings::update( array( 'advanced.audit_enabled' => 0 ) );

$otp_row = array(
	'id'          => 7,
	'token'       => 'test-token',
	'intent'      => OtpService::INTENT_RECOVER,
	'destination' => '84969789475',
	'code_hash'   => hash_hmac( 'sha256', '123456', wp_salt( 'auth' ) ),
	'payload'     => array( 'user_id' => 42 ),
	'attempts'    => 0,
	'consumed_at' => null,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 300 ),
);

$intent_repo = new SecurityTestOtpRepository( $otp_row );
$intent_otp  = new OtpService( $intent_repo );
$mismatch    = $intent_otp->verify( 'test-token', '123456', OtpService::INTENT_LOGIN );

check( 'cross-intent OTP is rejected', true, is_wp_error( $mismatch ) );
check( 'cross-intent error code', 'smart_login_wrong_intent', $mismatch->get_error_code() );
check( 'intent mismatch does not consume the row', 0, $intent_repo->consume_calls );

$verified = $intent_otp->verify( 'test-token', '123456', OtpService::INTENT_RECOVER );
check( 'matching intent verifies', true, is_array( $verified ) );
check( 'successful verification claims once', 1, $intent_repo->consume_calls );

$replay_repo                 = new SecurityTestOtpRepository( $otp_row );
$replay_repo->consume_result = false;
$replay                      = ( new OtpService( $replay_repo ) )->verify( 'test-token', '123456', OtpService::INTENT_RECOVER );

check( 'losing an atomic consume race is rejected', true, is_wp_error( $replay ) );
check( 'atomic consume race uses replay error', 'smart_login_otp_used', $replay->get_error_code() );

// ---------------------------------------------------------------------
section( 'Password reset grant — single use' );

$grant = PendingSession::grant_password_reset( 42 );
check( 'first grant consume returns user', 42, PendingSession::consume_password_reset( $grant ) );
check( 'second grant consume is rejected', 0, PendingSession::consume_password_reset( $grant ) );

$failed_delete_grant                      = PendingSession::grant_password_reset( 43 );
$GLOBALS['sl_transient_delete_fail']      = true;
$failed_delete_result                     = PendingSession::consume_password_reset( $failed_delete_grant );
$GLOBALS['sl_transient_delete_fail']      = false;

check( 'grant fails closed when atomic delete loses', 0, $failed_delete_result );

// ---------------------------------------------------------------------
section( 'Webhook retry — idempotency gate' );

$webhook_options = array(
	'sms.enabled'            => 1,
	'sms.url'                => 'https://gateway.example.test/send',
	'sms.method'             => 'POST',
	'sms.content_type'       => 'application/json',
	'sms.headers'            => array(),
	'sms.body'               => '{"code":"{{code}}","delivery":"{{delivery_id}}"}',
	'sms.timeout'            => 3,
	'sms.retry'              => 1,
	'sms.idempotency_header' => '',
);

Settings::update( $webhook_options );
$GLOBALS['sl_http_requests'] = array();
( new WebhookTransport() )->dispatch( '84969789475', '123456', array( 'intent' => OtpService::INTENT_LOGIN ) );
check( 'retry is disabled without an idempotency contract', 1, count( $GLOBALS['sl_http_requests'] ) );

$webhook_options['sms.idempotency_header'] = 'Idempotency-Key';
Settings::update( $webhook_options );
$GLOBALS['sl_http_requests'] = array();
( new WebhookTransport() )->dispatch( '84969789475', '123456', array( 'intent' => OtpService::INTENT_LOGIN ) );

$first_id  = $GLOBALS['sl_http_requests'][0]['args']['headers']['Idempotency-Key'] ?? '';
$second_id = $GLOBALS['sl_http_requests'][1]['args']['headers']['Idempotency-Key'] ?? '';

check( 'idempotent webhook may retry once', 2, count( $GLOBALS['sl_http_requests'] ) );
check( 'retry carries a non-empty delivery id', true, '' !== $first_id );
check( 'both delivery attempts share one id', $first_id, $second_id );

// ---------------------------------------------------------------------
section( 'Circuit breaker — a dead gateway stops costing workers (Phase 9.3)' );

// A transport that always fails, and counts how often it was actually called.
// The whole point of the breaker is that this counter stops rising.
class SmartLoginDeadTransport implements \SmartLogin\OTP\Transports\TransportInterface {

	public $calls = 0;

	public function id(): string {
		return 'sms';
	}

	public function is_available(): bool {
		return true;
	}

	public function send( string $destination, string $code, array $ctx ) {
		++$this->calls;

		return new WP_Error( 'boom', 'gateway down' );
	}
}

Settings::update(
	array(
		'security.breaker_threshold' => 3,
		'security.breaker_cooldown'  => 300,
	)
);

delete_transient( 'smart_login_breaker_sms' );
$GLOBALS['sl_mails'] = array();

$dead        = new SmartLoginDeadTransport();
$dead_router = new TransportRouter( array( 'sms' => $dead ) );

for ( $i = 0; $i < 3; $i++ ) {
	$dead_router->send( '84969789475', '123456', array( 'transport' => 'sms' ) );
}

check( 'the transport is called until the threshold', 3, $dead->calls );

$blocked = $dead_router->send( '84969789475', '123456', array( 'transport' => 'sms' ) );

check( 'the next send is refused', true, is_wp_error( $blocked ) );
check( 'and never reaches the transport', 3, $dead->calls );
check( 'the refusal is the breaker, not the gateway', 'smart_login_transport_down', $blocked->get_error_code() );
check( 'opening the breaker mails the admin once', 1, count( $GLOBALS['sl_mails'] ) );

// Cooldown elapsed: exactly one probe goes through, and a single failure puts
// the breaker straight back rather than waiting for another three.
$breaker_state               = get_transient( 'smart_login_breaker_sms' );
$breaker_state['open_until'] = time() - 1;
set_transient( 'smart_login_breaker_sms', $breaker_state, 3600 );

$dead_router->send( '84969789475', '123456', array( 'transport' => 'sms' ) );
check( 'after the cooldown one probe is allowed through', 4, $dead->calls );

$reblocked = $dead_router->send( '84969789475', '123456', array( 'transport' => 'sms' ) );
check( 'a failed probe re-opens on one strike', true, is_wp_error( $reblocked ) );
check( 'and the transport is spared again', 4, $dead->calls );

// A success clears the history outright, so an intermittent gateway does not
// accumulate its way to a permanent outage.
class SmartLoginLiveTransport extends SmartLoginDeadTransport {

	public function send( string $destination, string $code, array $ctx ) {
		++$this->calls;

		return true;
	}
}

delete_transient( 'smart_login_breaker_sms' );
$live        = new SmartLoginLiveTransport();
$live_router = new TransportRouter( array( 'sms' => $live ) );

$live_router->send( '84969789475', '123456', array( 'transport' => 'sms' ) );
check( 'a success leaves no breaker state behind', false, get_transient( 'smart_login_breaker_sms' ) );

// Threshold 0 disables the breaker entirely.
Settings::update( array( 'security.breaker_threshold' => 0 ) );
delete_transient( 'smart_login_breaker_sms' );
$never = new SmartLoginDeadTransport();
$never_router = new TransportRouter( array( 'sms' => $never ) );

for ( $i = 0; $i < 6; $i++ ) {
	$never_router->send( '84969789475', '123456', array( 'transport' => 'sms' ) );
}

check( 'threshold 0 disables the breaker', 6, $never->calls );

Settings::update(
	array(
		'security.breaker_threshold' => 5,
		'security.breaker_cooldown'  => 300,
	)
);
delete_transient( 'smart_login_breaker_sms' );

// ---------------------------------------------------------------------
section( 'Webhook timeout — the ceiling that actually binds (Phase 9.3)' );

// The registry clamp runs on save. A value stored under the old maximum of 30
// survives until somebody re-saves that tab, so the read-time clamp is what
// protects the sites that never do.
Settings::update( array_merge( $webhook_options, array( 'sms.timeout' => 30 ) ) );
$GLOBALS['sl_http_requests'] = array();
( new WebhookTransport() )->dispatch( '84969789475', '123456', array( 'intent' => OtpService::INTENT_LOGIN ) );

check(
	'a stored timeout above the ceiling is clamped at read time',
	WebhookTransport::MAX_TIMEOUT,
	$GLOBALS['sl_http_requests'][0]['args']['timeout'] ?? 0
);

Settings::update( array_merge( $webhook_options, array( 'sms.timeout' => 4 ) ) );
$GLOBALS['sl_http_requests'] = array();
( new WebhookTransport() )->dispatch( '84969789475', '123456', array( 'intent' => OtpService::INTENT_LOGIN ) );

check( 'a timeout under the ceiling is left alone', 4, $GLOBALS['sl_http_requests'][0]['args']['timeout'] ?? 0 );

// ---------------------------------------------------------------------
section( 'AddressNormalizer — Vietnamese diacritics' );

check( 'slug strips tones', 'cau giay', AddressNormalizer::slug( 'Cầu Giấy' ) );
check( 'slug handles đ', 'dak lak', AddressNormalizer::slug( 'Đắk Lắk' ) );
check( 'slug handles lowercase đ', 'da nang do son', AddressNormalizer::slug( 'Đà Nẵng đồ sơn' ) );
check( 'slug on a full name', 'thi xa son tay', AddressNormalizer::slug( 'Thị xã Sơn Tây' ) );
check( 'slug collapses punctuation', 'phuong 1', AddressNormalizer::slug( 'Phường 1' ) );
check( 'slug on ư/ơ', 'phuoc long dong xoai', AddressNormalizer::slug( 'Phước Long Đồng Xoài' ) );
check( 'slug trims', 'ha noi', AddressNormalizer::slug( '  Hà   Nội  ' ) );

check( 'unaccent keeps case', 'Cau Giay', AddressNormalizer::unaccent( 'Cầu Giấy' ) );
check( 'unaccent uppercase Đ', 'Da Nang', AddressNormalizer::unaccent( 'Đà Nẵng' ) );

check( 'strip_prefix phuong', 'cau giay', AddressNormalizer::strip_prefix( 'phuong cau giay' ) );
check( 'strip_prefix xa', 'tan lap', AddressNormalizer::strip_prefix( 'xa tan lap' ) );
check( 'strip_prefix dac khu', 'phu quoc', AddressNormalizer::strip_prefix( 'dac khu phu quoc' ) );
check( 'strip_prefix thanh pho', 'ha noi', AddressNormalizer::strip_prefix( 'thanh pho ha noi' ) );
check( 'strip_prefix leaves other names alone', 'cau giay', AddressNormalizer::strip_prefix( 'cau giay' ) );

// ---------------------------------------------------------------------
section( 'AddressRepository — dataset' );

if ( ! AddressRepository::is_dataset_installed() ) {
	echo "  SKIPPED — no dataset installed yet.\n";
	echo "  Generate it with: php bin/build-address-data.php <source.json>\n";
} else {
	$provinces = AddressRepository::provinces();

	check( 'exactly 34 provinces', 34, count( $provinces ) );

	$ward_total = AddressRepository::count_wards();
	check(
		'ward count is in the post-2025 range (3200-3400), got ' . $ward_total,
		true,
		$ward_total >= 3200 && $ward_total <= 3400
	);

	// Every province must have wards; an empty one means a broken source file.
	$empty = array();
	foreach ( array_keys( $provinces ) as $province_code ) {
		if ( ! AddressRepository::wards( (string) $province_code ) ) {
			$empty[] = $province_code;
		}
	}
	check( 'no province is missing its wards', array(), $empty );

	// Cross-province lookup must fail — this is the tamper check.
	$first_province = (string) array_key_first( $provinces );
	$other_province = (string) array_keys( $provinces )[1];
	$first_ward     = (string) array_key_first( AddressRepository::wards( $first_province ) );

	check( 'ward resolves inside its own province', true, null !== AddressRepository::find_ward( $first_ward, $first_province ) );
	check( 'ward does NOT resolve in another province', null, AddressRepository::find_ward( $first_ward, $other_province ) );
	check( 'is_valid_pair accepts a real pair', true, AddressRepository::is_valid_pair( $first_ward, $first_province ) );
	check( 'is_valid_pair rejects a crossed pair', false, AddressRepository::is_valid_pair( $first_ward, $other_province ) );
	check( 'unknown ward code is rejected', false, AddressRepository::is_valid_pair( '99999', $first_province ) );
}

// ---------------------------------------------------------------------
section( 'Address boundary — one set of keys, two hosts (Phase 8.5)' );

/*
 * The profile card and WooCommerce's own Addresses tab edit the same address.
 * They must therefore leave the same three values behind, in the same shapes:
 * billing_state holds the province CODE (shipping zones match on it),
 * billing_city holds the ward NAME (order emails and invoices print it), and
 * the ward code lives in its own meta key.
 *
 * Two writers for one address is the arrangement most likely to rot quietly —
 * nothing errors when they drift, the data just stops agreeing with itself.
 */
if ( AddressRepository::is_dataset_installed() ) {
	$boundary_province = (string) array_key_first( AddressRepository::provinces() );
	$boundary_wards    = AddressRepository::wards( $boundary_province );
	$boundary_ward     = (string) array_key_first( $boundary_wards );
	$boundary_name     = $boundary_wards[ $boundary_ward ]['name'];

	// Host 1: the plugin's own form.
	$clean = AddressFields::validate(
		array(
			AddressFields::FIELD_PROVINCE => $boundary_province,
			AddressFields::FIELD_WARD     => $boundary_ward,
			AddressFields::FIELD_STREET   => '12 Trần Duy Hưng',
		)
	);
	check( 'the profile form accepts a real pair', false, is_wp_error( $clean ) );

	if ( ! is_wp_error( $clean ) ) {
		AddressFields::save_for_user( 4001, $clean );
	}

	// Host 2: WooCommerce saved its own form, then WooAddress resolves the code
	// it posted into the name Woo wants to store.
	update_user_meta( 4002, 'billing_state', $boundary_province );
	update_user_meta( 4002, 'billing_city', $boundary_ward );
	update_user_meta( 4002, 'billing_address_1', '12 Trần Duy Hưng' );
	( new WooAddress() )->store_customer_ward( 4002, 'billing' );

	foreach ( array( 'billing_state', 'billing_city', 'billing_address_1', AddressFields::META_WARD_CODE ) as $shared_key ) {
		check(
			'both hosts agree on ' . $shared_key,
			get_user_meta( 4001, $shared_key, true ),
			get_user_meta( 4002, $shared_key, true )
		);
	}

	check( 'province is stored as a code, so shipping zones still match', $boundary_province, get_user_meta( 4001, 'billing_state', true ) );
	check( 'ward is stored as a name, so invoices read properly', $boundary_name, get_user_meta( 4001, 'billing_city', true ) );
	check( 'the ward code is kept alongside it', $boundary_ward, get_user_meta( 4001, AddressFields::META_WARD_CODE, true ) );

	// Shipping is left alone on purpose: a customer may deliver somewhere other
	// than they are billed, and the profile card only ever edits the default.
	check( 'the profile form never touches shipping', '', get_user_meta( 4001, 'shipping_city', true ) );
}

// ---------------------------------------------------------------------
section( 'Identifier-first authentication UI contract' );

$auth_template     = file_get_contents( dirname( __DIR__ ) . '/templates/form-auth.php' );
$password_template = file_get_contents( dirname( __DIR__ ) . '/templates/form-password.php' );
$signup_template   = file_get_contents( dirname( __DIR__ ) . '/templates/form-signup.php' );
$onboard_template  = file_get_contents( dirname( __DIR__ ) . '/templates/onboarding.php' );
$success_template  = file_get_contents( dirname( __DIR__ ) . '/templates/registered-success.php' );
$auth_script   = file_get_contents( dirname( __DIR__ ) . '/assets/js/smart-login.js' );
$profile_form  = file_get_contents( dirname( __DIR__ ) . '/templates/woocommerce/form-edit-account.php' );
$template_loader = file_get_contents( dirname( __DIR__ ) . '/includes/Frontend/class-template-loader.php' );
$settings_page = file_get_contents( dirname( __DIR__ ) . '/includes/Admin/class-settings-page.php' );
$admin_script  = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );
$provider_controller = file_get_contents( dirname( __DIR__ ) . '/includes/Auth/class-provider-auth-controller.php' );

check( 'template loader does not shadow partial name arguments', true, false !== strpos( $template_loader, 'render( string $template_name' ) );

// Step 1 asks one question. The login/register tab pair that used to live here
// made the visitor declare which one they needed before the site would say.
check( 'entry screen offers no login/register choice', false, false !== strpos( $auth_template, 'data-sl-auth-tab' ) );
check( 'entry screen uses one HTML form', 1, substr_count( $auth_template, '<form ' ) );
check( 'entry screen collects exactly one identifier', 1, substr_count( $auth_template, 'name="identity"' ) );
check( 'entry screen collects no password', false, false !== strpos( $auth_template, 'partials/password-field' ) );
check( 'entry screen carries its own guard', true, false !== strpos( $auth_template, "RequestGuard::fields( 'identify' )" ) );
check( 'entry screen posts the identify action', true, false !== strpos( $auth_template, 'value="identify"' ) );

// Step 2a: a known identifier. The guard stays the login guard, because this
// is still a login — only the field order changed.
check( 'password step guards as a login', true, false !== strpos( $password_template, "RequestGuard::fields( 'login', 'login_' )" ) );
check( 'password step echoes the identifier back', true, false !== strpos( $password_template, 'name="identity"' ) );
check( 'password step marks its origin so failures return to it', true, false !== strpos( $password_template, 'name="sl_from_password"' ) );
check( 'password step offers a way to correct the identifier', true, false !== strpos( $password_template, 'STEP_IDENTIFY' ) );

// Step 3: one password box. The show/hide toggle already does what a second
// box was there for, and every extra field on this screen costs completions.
check( 'signup step asks for one password, not two', 1, substr_count( $signup_template, "'partials/password-field'" ) );
check( 'signup step has no confirmation field', false, false !== strpos( $signup_template, 'password_confirm' ) );
check( 'signup step requires the terms', true, false !== strpos( $signup_template, 'name="terms"' ) );
check( 'signup step carries the grant rather than the identity', true, false !== strpos( $signup_template, 'name="grant"' ) && false === strpos( $signup_template, 'name="identity"' ) );
check( 'signup step does not collect date of birth', false, false !== strpos( $signup_template, 'name="dob"' ) );
check( 'signup step does not collect gender', false, false !== strpos( $signup_template, 'name="gender"' ) );
check( 'signup step does not collect referral code', false, false !== strpos( $signup_template, 'name="referral_code"' ) );

// The welcome screen asks and accepts no for an answer.
/*
 * Registration must redirect before the welcome screen renders, and this is a
 * correctness rule rather than a style one.
 *
 * Drawing the welcome screen straight into the sign-in response mints its nonce
 * in the same request that set the auth cookie. wp_get_session_token() reads
 * that cookie out of $_COOKIE, which setcookie() does not populate until the
 * browser sends it back, so the nonce is bound to an empty session token and the
 * first submit on the welcome screen fails with "Phiên làm việc đã hết hạn".
 *
 * Found by registering on a real site; no suite here can reproduce it, because
 * none of them has a WordPress session. So what is asserted instead is the shape
 * of the fix: after_registration() hands over to a redirect and does not set a
 * step for the same request to render.
 */
$form_controller = file_get_contents( dirname( __DIR__ ) . '/includes/Frontend/class-form-controller.php' );

preg_match( '/private function after_registration\(.*?\n\t\}/s', $form_controller, $after_registration );
$after_registration_src = $after_registration[0] ?? '';

check( 'after_registration was located', true, '' !== $after_registration_src );
check( 'registration redirects rather than rendering in the POST response', true, false !== strpos( $after_registration_src, '$this->redirect(' ) );
check( 'registration does not render the welcome screen inline', false, false !== strpos( $after_registration_src, 'Flow::set(' ) );

check( 'onboarding always offers a way out', true, false !== strpos( $onboard_template, 'name="sl_skip"' ) );
check( 'onboarding never asks for a password', false, false !== strpos( $onboard_template, 'partials/password-field' ) );
check( 'onboarding never embeds contact verification', false, false !== strpos( $onboard_template, 'data-sl-contact' ) );
check( 'onboarding states why each field is worth giving', true, false !== strpos( $onboard_template, 'sl-hint--reason' ) );
check( 'success screen no longer redirects on a timer', false, false !== strpos( $success_template, 'setTimeout' ) );

check( 'OAuth providers render below the entry form', true, strrpos( $auth_template, 'sl-provider-buttons' ) > strrpos( $auth_template, '</form>' ) );
check( 'OAuth login buttons expose stable browser selectors', true, false !== strpos( $auth_template, 'data-sl-provider=' ) && false !== strpos( $auth_template, 'data-sl-provider-mode="login"' ) );
// 8.2 moved this block out of the WooCommerce template and into the section
// partial that now owns it. The selector is the contract this assertion
// protects, so it follows the markup rather than the file it used to live in.
$profile_providers = file_get_contents( dirname( __DIR__ ) . '/templates/partials/account/providers.php' );

check( 'profile provider buttons expose link mode selectors', true, false !== strpos( $profile_providers, 'data-sl-provider-mode="link"' ) );
check( 'a repeat submit cannot fire a second SMS', true, false !== strpos( $auth_script, 'initSubmitGuard' ) );
// ---------------------------------------------------------------------
section( 'Every tabbed setting is actually rendered' );

/*
 * The defect this replaces, stated once so the assertions below read as what
 * they are.
 *
 * The old screen posted the entire option on every save. Keys outside the open
 * tab survived as hidden inputs; keys claimed by the open tab were expected back
 * from its own controls. A key listed in tab_fields() but drawn by nothing was
 * therefore absent from both, and sanitize() read that absence as an unchecked
 * checkbox and stored a zero.
 *
 * `field_email_optional` defaulted to 1, was claimed by the Chung tab, and was
 * drawn nowhere. One press of Lưu flipped it, and every phone-only account began
 * reporting a missing required Email it could not supply, because the Email box
 * on the profile form is readonly by design.
 *
 * The schema now declares each setting once, and a save writes only the fields
 * carried by the tab it names. These tests assert that behaviour directly rather
 * than grepping the screen for control names — they post payloads and read back
 * what sanitize() produced.
 */

/**
 * Read a dot path out of a nested settings array.
 *
 * @return mixed
 */
function sl_dig_setting( array $source, string $path ) {
	$node = $source;

	foreach ( explode( '.', $path ) as $segment ) {
		if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
			return null;
		}

		$node = $node[ $segment ];
	}

	return $node;
}

/**
 * Build the request shape FieldRenderer::name() produces for a dot path.
 *
 * @param mixed $value
 */
function sl_post_setting( array &$payload, string $path, $value ): void {
	$segments = explode( '.', $path );
	$leaf     = array_pop( $segments );
	$node     = &$payload;

	foreach ( $segments as $segment ) {
		if ( ! isset( $node[ $segment ] ) || ! is_array( $node[ $segment ] ) ) {
			$node[ $segment ] = array();
		}

		$node = &$node[ $segment ];
	}

	$node[ $leaf ] = $value;

	unset( $node );
}

/**
 * A value the field will accept unchanged, so a round-trip mismatch means the
 * plumbing lost it rather than the sanitiser rejecting it.
 *
 * @return mixed
 */
function sl_sample_value( string $path, array $field ) {
	/*
	 * The two preset selects are asked for their "custom" value on purpose.
	 * Any other choice makes the save derive half of its own tab from the
	 * preset, which is correct behaviour but means the derived fields cannot
	 * round-trip. That derivation has its own assertions in the admin suite;
	 * here the subject is the plumbing, so the presets are told to stand aside.
	 */
	if ( 'sms.preset' === $path ) {
		return \SmartLogin\GatewayPresets::CUSTOM;
	}

	if ( 'otp.preset' === $path ) {
		return \SmartLogin\OtpPresets::CUSTOM;
	}

	if ( 'credentials' === ( $field['type'] ?? '' ) ) {
		return array( 'api_key' => 'sample-key' );
	}

	// A field with its own sanitiser needs a sample that sanitiser accepts,
	// otherwise the round-trip measures the rule rather than the plumbing.
	switch ( $field['sanitize'] ?? '' ) {
		case 'country_code':
			return '84';

		case 'domain':
			return 'sample.invalid';

		case 'header_name':
			return 'X-Sample-Key';

		case 'headers':
			return array( array( 'key' => 'X-Test', 'value' => 'sample' ) );
	}

	switch ( $field['type'] ?? 'text' ) {
		case 'checkbox':
			return 1;

		case 'number':
			$min = (int) ( $field['min'] ?? 1 );
			$max = (int) ( $field['max'] ?? ( $min + 10 ) );

			return (int) floor( ( $min + $max ) / 2 );

		case 'select':
			$choices = array_keys( $field['choices'] ?? array() );

			return (string) end( $choices );

		case 'url':
			return 'https://example.test/page';

		case 'email':
			return 'admin@example.test';

		case 'headers':
			return array( array( 'key' => 'X-Test', 'value' => 'sample' ) );

		default:
			return 'sample-value';
	}
}

$registry_tabs = \SmartLogin\FieldRegistry::tabs();

foreach ( \SmartLogin\FieldRegistry::all() as $path => $field ) {
	if ( ! isset( $field['type'] ) || ! array_key_exists( 'default', $field ) ) {
		check( sprintf( '%s declares a type and a default', $path ), true, false );
	}

	$tab = $field['tab'] ?? '';

	if ( '' !== $tab && ! isset( $registry_tabs[ $tab ] ) ) {
		check( sprintf( '%s names a real tab', $path ), true, false );
	}
}

check(
	'defaults() covers exactly the registry',
	array(),
	array_values(
		array_filter(
			array_keys( \SmartLogin\FieldRegistry::all() ),
			static fn( string $path ): bool => null === sl_dig_setting( Settings::defaults(), $path )
				&& null !== \SmartLogin\FieldRegistry::get( $path )['default']
		)
	)
);

$settings_before = Settings::all();

foreach ( array_keys( $registry_tabs ) as $registry_tab ) {
	$tab_fields = \SmartLogin\FieldRegistry::for_tab( $registry_tab );

	check( sprintf( 'tab "%s" draws at least one field', $registry_tab ), true, count( $tab_fields ) > 0 );

	// 1. A full save of this tab lands every one of its own values...
	$payload = array( Settings::TAB_FIELD => $registry_tab );

	foreach ( $tab_fields as $path => $field ) {
		sl_post_setting( $payload, $path, sl_sample_value( $path, $field ) );
	}

	$saved   = Settings::sanitize( $payload );
	$dropped = array();

	foreach ( $tab_fields as $path => $field ) {
		// A conditional field has no control unless another setting calls for
		// one, so there is nothing for it to round-trip here. `sms.credentials`
		// is the case: under the custom gateway it draws no inputs and keeps
		// whatever was stored. The admin suite covers it with a real preset
		// selected, which is the only state in which it is editable.
		if ( ! empty( $field['conditional'] ) ) {
			continue;
		}

		if ( sl_dig_setting( $saved, $path ) !== sl_sample_value( $path, $field ) ) {
			$dropped[] = $path;
		}
	}

	check( sprintf( 'saving "%s" keeps every value it posted', $registry_tab ), array(), $dropped );

	// ...and touches nothing belonging to another tab.
	$collateral = array();

	foreach ( \SmartLogin\FieldRegistry::all() as $path => $field ) {
		if ( ( $field['tab'] ?? '' ) === $registry_tab ) {
			continue;
		}

		if ( sl_dig_setting( $saved, $path ) !== sl_dig_setting( $settings_before, $path ) ) {
			$collateral[] = $path;
		}
	}

	check( sprintf( 'saving "%s" changes no other tab', $registry_tab ), array(), $collateral );

	// 2. The exact shape of the old bug: a save carrying nothing but its tab
	//    name. Checkboxes on that tab were genuinely unticked and must clear;
	//    checkboxes anywhere else were never on screen and must not move.
	$empty      = Settings::sanitize( array( Settings::TAB_FIELD => $registry_tab ) );
	$phantom    = array();
	$not_leared = array();

	foreach ( \SmartLogin\FieldRegistry::all() as $path => $field ) {
		if ( 'checkbox' !== ( $field['type'] ?? '' ) ) {
			continue;
		}

		if ( ( $field['tab'] ?? '' ) === $registry_tab ) {
			if ( 0 !== sl_dig_setting( $empty, $path ) ) {
				$not_leared[] = $path;
			}

			continue;
		}

		if ( sl_dig_setting( $empty, $path ) !== sl_dig_setting( $settings_before, $path ) ) {
			$phantom[] = $path;
		}
	}

	check( sprintf( 'an empty "%s" save clears only its own checkboxes', $registry_tab ), array(), $not_leared );
	check( sprintf( 'an empty "%s" save cannot zero another tab', $registry_tab ), array(), $phantom );
}

// A save that cannot say which tab it came from writes nothing at all.
check(
	'a save with no tab is a no-op',
	$settings_before,
	Settings::sanitize( array( 'identity' => array( 'mode' => 'email_only' ) ) )
);

check( 'the dead require_verification switch is gone', false, false !== strpos( $settings_page, 'require_verification' ) );

// ---------------------------------------------------------------------
section( 'Provider settings UI' );

$provider_cards = file_get_contents( dirname( __DIR__ ) . '/includes/Admin/class-provider-cards.php' );

check( 'provider settings render one card per provider', 2, substr_count( $provider_cards, '$this->card(' ) );
check( 'provider settings expose inline docs tabs', true, false !== strpos( $provider_cards, 'data-provider-tab="docs"' ) );
check( 'provider settings expose secret inputs without stored values', true, false !== strpos( $provider_cards, "'google_client_secret'" ) && false !== strpos( $provider_cards, "'zalo_app_secret'" ) && false !== strpos( $provider_cards, 'value=""' ) );
check( 'provider settings expose read-only callback URLs', true, false !== strpos( $provider_cards, 'data-provider-callback' ) && false !== strpos( $provider_cards, 'readonly' ) );

// The screen that used to hold all of the above is now routing only. Size is a
// blunt proxy, but it is the property that made the old class impossible to
// keep honest, so it is worth pinning.
check( 'the settings page no longer renders fields itself', false, false !== strpos( $settings_page, 'form-table' ) );
check( 'admin script switches provider setup and docs panels', true, false !== strpos( $admin_script, 'initProviderCard' ) && false !== strpos( $admin_script, 'data-provider-panel' ) );
check( 'provider failure no longer redirects from current callback URL', false, false !== strpos( $provider_controller, 'wp_safe_redirect( Flow::url(' ) );

// ---------------------------------------------------------------------
printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
