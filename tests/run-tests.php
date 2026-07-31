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

use SmartLogin\Address\AddressNormalizer;
use SmartLogin\Address\AddressRepository;
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
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\OTP\OtpRepository;
use SmartLogin\OTP\OtpService;
use SmartLogin\OTP\Placeholders;
use SmartLogin\Security\RateLimiter;
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

$key = AddressNormalizer::index_key( 'Phường Cầu Giấy', 'Thành phố Hà Nội' );
check( 'index_key holds the full ward name', true, false !== strpos( $key, 'phuong cau giay' ) );
check( 'index_key holds the bare ward name', true, false !== strpos( $key, '|cau giay' ) );
check( 'index_key holds the province', true, false !== strpos( $key, 'ha noi' ) );

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
section( 'AddressRepository::search' );

check( 'empty query returns nothing', array(), AddressRepository::search( '' ) );
check( 'single character returns nothing', array(), AddressRepository::search( 'c' ) );
check( 'punctuation-only returns nothing', array(), AddressRepository::search( '!!' ) );

if ( AddressRepository::is_dataset_installed() ) {
	$hits = AddressRepository::search( 'cau giay' );

	check( 'accent-free query finds something', true, count( $hits ) > 0 );

	if ( $hits ) {
		check(
			'top hit mentions Cầu Giấy',
			true,
			false !== mb_stripos( $hits[0]['ward_name'], 'Cầu Giấy' )
		);

		$accented = AddressRepository::search( 'Cầu Giấy' );
		check( 'accented query gives the same top hit', $hits[0]['ward_code'], $accented[0]['ward_code'] ?? '' );

		check( 'result carries a province code', true, '' !== $hits[0]['province_code'] );
		check( 'result carries a province name', true, '' !== $hits[0]['province_name'] );
	}

	check( 'limit is honoured', true, count( AddressRepository::search( 'xa', 5 ) ) <= 5 );
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
check( 'profile provider buttons expose link mode selectors', true, false !== strpos( $profile_form, 'data-sl-provider-mode="link"' ) );
check( 'a repeat submit cannot fire a second SMS', true, false !== strpos( $auth_script, 'initSubmitGuard' ) );
check( 'referral code moved to optional profile form', true, false !== strpos( $profile_form, 'name="smartlogin_referral_code"' ) );
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
