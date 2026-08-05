<?php
/**
 * Delivery-routing guard rails.
 *
 * Normative spec: docs/delivery-routing.md.
 * Brief: docs/delivery-routing/10.0-guard-rails.md.
 *
 * This suite lands **red on purpose**. Two of its rules describe a defect that
 * is in the tree today; four describe controls that do not exist yet and report
 * PENDING rather than passing vacuously. A rule written after its fix cannot
 * fail, and a rule that has never failed is a comment — so every rule here is
 * demonstrated failing, or explicitly blocked, before the code it guards exists.
 *
 * Each rule names the sub-phase that turns it green, so a red run doubles as a
 * progress report. Registered `spec` in run-all.php; promoted to `required` the
 * moment it goes green, for the reason Phase 5 promoted the identity suites.
 *
 * Run with:  php tests/delivery/run-routing-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\FieldRegistry;
use SmartLogin\OTP\OtpRepository;
use SmartLogin\OTP\OtpService;
use SmartLogin\OTP\Transports\AutomationTransport;
use SmartLogin\OTP\Transports\EventBus;
use SmartLogin\OTP\Transports\TransportInterface;
use SmartLogin\Security\AuditLog;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\Security\Captcha;
use SmartLogin\Security\RateLimiter;
use SmartLogin\Security\SecretBox;
use SmartLogin\Settings;

/**
 * An in-memory OTP store, so the ordering inside issue() can be asserted on
 * behaviour rather than on the shape of the source.
 *
 * The stub $wpdb cannot serve this: it does not parse SQL, so consume and insert
 * are indistinguishable through it. OtpService takes its repository by
 * constructor, so the seam needed for an honest assertion is one the production
 * code already offers.
 */
class SL_Fake_Otp_Repository extends OtpRepository {

	/** @var array<int,array<string,mixed>> */
	public $rows = array();

	/** @var string[] Operation names, in the order issue() performed them. */
	public $ops = array();

	/** @var int */
	private $next_id = 1;

	public function consume_open_codes( string $destination, string $intent, int $except_id = 0 ): void {
		$this->ops[] = 'consume';

		foreach ( $this->rows as $id => $row ) {
			if ( $id === $except_id ) {
				continue;
			}

			if ( $row['destination'] === $destination && $row['intent'] === $intent && null === $row['consumed_at'] ) {
				$this->rows[ $id ]['consumed_at'] = '2026-08-02 00:00:00';
			}
		}
	}

	public function insert( array $data ): int {
		$this->ops[] = 'insert';

		$id                       = $this->next_id++;
		$data['consumed_at']      = null;
		$data['id']               = $id;
		$this->rows[ $id ]        = $data;

		return $id;
	}

	public function delete( int $id ): void {
		$this->ops[] = 'delete';

		unset( $this->rows[ $id ] );
	}

	/** @return array<int,array<string,mixed>> Rows still redeemable. */
	public function live_rows(): array {
		return array_filter( $this->rows, static fn( array $row ): bool => null === $row['consumed_at'] );
	}
}

/** A transport whose outcome the test chooses. */
class SL_Fake_Transport implements TransportInterface {

	/** @var bool */
	private $succeeds;

	public function __construct( bool $succeeds ) {
		$this->succeeds = $succeeds;
	}

	public function id(): string {
		return 'sms';
	}

	public function is_available(): bool {
		return true;
	}

	public function send( string $destination, string $code, array $ctx ) {
		return $this->succeeds
			? true
			: new WP_Error( 'sl_test_gateway_down', 'gateway down' );
	}
}

/** Limits are 9's subject, not this suite's. */
class SL_Allow_Limiter extends RateLimiter {

	public function check_otp_send( string $destination, string $intent ) {
		return true;
	}
}

/**
 * Build a service whose transports succeed or fail on demand.
 *
 * Both channels are registered, not only `sms`: with the map holding one id the
 * router resolves an email destination to a transport that is not there, the
 * send fails and the row is rolled back — so a test about anything else would
 * silently be a test about a missing transport.
 */
function sl_service_with( SL_Fake_Otp_Repository $repo, bool $succeeds ): OtpService {
	return new OtpService(
		$repo,
		new TransportRouter(
			array(
				'sms'   => new SL_Fake_Transport( $succeeds ),
				'email' => new SL_Fake_Transport( $succeeds ),
			)
		),
		new SL_Allow_Limiter( $repo )
	);
}

// =====================================================================
sl_section( 'Rule 1 — one place decides how a code travels (10.1)' );

/*
 * Testing a destination for '@' is legitimate in several places: it tells a
 * phone from an email identity, it decides which placeholder blanks, it masks a
 * value for the log. What must not spread is the *transport* decision.
 *
 * That distinction is not greppable, so the rule is drawn one step back: the
 * test itself is forbidden outside this list, and joining the list means editing
 * this file and writing down why. Six entries today, each justified:
 */
$sl_at_test_allowed = array(
	// The routing authority itself. After 10.1 this is the only entry here whose
	// answer is a transport; before it, it is the whole problem.
	'includes/OTP/Transports/class-transport-router.php',
	// Derives the identity channel (phone|email) and the masked display form.
	// A property of the identifier, not of how it will be delivered.
	'includes/OTP/class-otp-service.php',
	// Presentation: decides whether {{phone}} or {{email}} blanks.
	'includes/OTP/class-placeholders.php',
	// Rejects an email offered where a phone number is expected, and finds the
	// local part when masking one.
	'includes/Identity/class-phone.php',
	// Builds and recognises the synthetic address for a phone-only account.
	'includes/Identity/class-user-manager.php',
	// Masks an identity before it reaches the audit log.
	'includes/Security/class-rate-limiter.php',
);

sl_forbid_pattern(
	'no new file learns to tell an email from a phone by itself',
	"/strpos\(\s*\\\$[A-Za-z_]+,\s*'@'\s*\)/",
	$sl_at_test_allowed,
	'Six files may do this and each is justified in the allowlist above. A seventh means a transport decision is spreading — route through TransportRouter instead.'
);

/*
 * The rule above cannot see the defect it exists to prevent, because the
 * offender is on its allowlist. These can.
 *
 * Asserted on behaviour rather than on the source: a structural check for the
 * setting's name inside transport_for() would have gone red the moment 10.1 put
 * the paths in a class constant, which is a better shape and not a regression.
 * What matters is that changing the setting changes the answer.
 */
$sl_routing_router = new TransportRouter(
	array(
		'sms'        => new SL_Fake_Transport( true ),
		'email'      => new SL_Fake_Transport( true ),
		'automation' => new SL_Fake_Transport( true ),
	)
);

sl_check(
	'a phone destination follows the routing table',
	'automation',
	( static function () use ( $sl_routing_router ): string {
		Settings::update( array( 'delivery.route_phone' => 'automation' ) );
		$answer = $sl_routing_router->transport_for( '84969789475' );
		Settings::update( array( 'delivery.route_phone' => 'sms' ) );

		return $answer;
	} )()
);

sl_check(
	'an email destination follows the routing table',
	'automation',
	( static function () use ( $sl_routing_router ): string {
		Settings::update( array( 'delivery.route_email' => 'automation' ) );
		$answer = $sl_routing_router->transport_for( 'ban@example.com' );
		Settings::update( array( 'delivery.route_email' => 'email' ) );

		return $answer;
	} )()
);

// The defaults must reproduce what the '@' test used to answer, byte for byte.
// This is the whole no-migration argument, so it is asserted directly rather
// than inferred from the suites staying green.
sl_check( 'a phone number defaults to the SMS gateway', 'sms', $sl_routing_router->transport_for( '84969789475' ) );
sl_check( 'an email address defaults to wp_mail()', 'email', $sl_routing_router->transport_for( 'ban@example.com' ) );

// A stored value naming a transport nothing registers must not resolve to
// nothing: a filter that used to add a transport can be removed at any time.
sl_check(
	'an unresolvable stored route falls back to the built-in',
	'sms',
	( static function () use ( $sl_routing_router ): string {
		Settings::update( array( 'delivery.route_phone' => 'a-transport-nobody-registered' ) );
		$answer = $sl_routing_router->transport_for( '84969789475' );
		Settings::update( array( 'delivery.route_phone' => 'sms' ) );

		return $answer;
	} )()
);

// =====================================================================
sl_section( 'Rule 2 — secret storage holds no per-field branch (10.2)' );

$sl_store_body = sl_method_body( sl_source( 'includes/class-settings.php' ), 'store_secret' );

sl_assert(
	'Settings::store_secret() names no individual field',
	'' !== $sl_store_body && ! preg_match( "/'[A-Za-z0-9_.]*_secret'/", $sl_store_body ),
	'A secret field whose path nobody added to this branch is pruned from the option array anyway (class-settings.php:219) and stored nowhere. That is a control which accepts input and discards it in silence.'
);

// =====================================================================
sl_section( 'Rule 3 — every declared secret field round-trips (10.2)' );

$sl_secret_fields = array_filter(
	FieldRegistry::all(),
	static fn( array $field ): bool => 'secret' === ( $field['type'] ?? '' )
);

sl_assert(
	'the registry declares at least one secret field',
	array() !== $sl_secret_fields,
	'Nothing to check. If this fails the rule below is meaningless, not passing.'
);

$sl_has_reader = method_exists( Settings::class, 'read_secret' );

sl_assert(
	'Settings exposes a generic reader for secret fields',
	$sl_has_reader,
	'Captcha::secret() is the only way to read a stored secret, and it is bound to one field. Without a generic reader a second secret field cannot be verified to have been stored at all.'
);

if ( ! $sl_has_reader ) {
	foreach ( array_keys( $sl_secret_fields ) as $sl_path ) {
		sl_pending(
			sprintf( 'the value saved for "%s" can be read back', $sl_path ),
			'Settings::read_secret() — 10.2'
		);
	}
} else {
	foreach ( array_keys( $sl_secret_fields ) as $sl_path ) {
		$sl_field = FieldRegistry::get( $sl_path );
		$sl_input = array( Settings::TAB_FIELD => (string) ( $sl_field['tab'] ?? '' ) );
		$sl_parts = explode( '.', $sl_path );
		$sl_leaf  = array_pop( $sl_parts );
		$sl_node  = &$sl_input;

		foreach ( $sl_parts as $sl_part ) {
			if ( ! isset( $sl_node[ $sl_part ] ) ) {
				$sl_node[ $sl_part ] = array();
			}
			$sl_node = &$sl_node[ $sl_part ];
		}

		$sl_node[ $sl_leaf ] = 'round-trip-' . md5( $sl_path );
		unset( $sl_node );

		update_option( Settings::OPTION, Settings::sanitize( $sl_input ) );

		sl_check(
			sprintf( 'the value saved for "%s" can be read back', $sl_path ),
			'round-trip-' . md5( $sl_path ),
			Settings::read_secret( $sl_path )
		);
	}
}

/*
 * 10.2's pre-move fixture went with the fallback it tested, in 15.3.
 *
 * It wrote a secret the way the pre-10.2 code did and asserted the reader still found
 * it, then asserted that clearing reached that copy too — because erasing only the new
 * location resurrected the secret on the next read. Both were real, and both describe a
 * database that no longer exists: 15.1 wiped it and there is no earlier location left
 * to fall back to.
 *
 * What survives is the half that is still true of every install: a secret written
 * through the path-keyed store reads back, and clearing it empties it.
 */
Settings::store_secret( Captcha::SECRET_PATH, 'sealed-after-15-3' );

sl_check(
	'a secret written through the path-keyed store reads back',
	'sealed-after-15-3',
	Captcha::secret()
);

Captcha::clear_secret();

sl_check( 'and clearing empties it', '', Captcha::secret() );

// The other half of the same property: the plaintext must not survive in the
// option array. This one can be checked today, and does pass — absorb_secret_fields()
// prunes unconditionally. It is here so 10.2 cannot fix the storage by removing
// the pruning.
$sl_leaked = array();

foreach ( array_keys( $sl_secret_fields ) as $sl_path ) {
	$sl_field = FieldRegistry::get( $sl_path );
	$sl_probe = array(
		Settings::TAB_FIELD => (string) ( $sl_field['tab'] ?? '' ),
	);

	$sl_parts = explode( '.', $sl_path );
	$sl_leaf  = array_pop( $sl_parts );
	$sl_node  = &$sl_probe;

	foreach ( $sl_parts as $sl_part ) {
		if ( ! isset( $sl_node[ $sl_part ] ) ) {
			$sl_node[ $sl_part ] = array();
		}
		$sl_node = &$sl_node[ $sl_part ];
	}

	$sl_node[ $sl_leaf ] = 'must-not-persist-in-the-option';
	unset( $sl_node );

	if ( false !== strpos( wp_json_encode( Settings::sanitize( $sl_probe ) ), 'must-not-persist-in-the-option' ) ) {
		$sl_leaked[] = $sl_path;
	}
}

sl_assert(
	'no secret survives in the settings option',
	array() === $sl_leaked,
	'A secret in the option array is a secret the field renderer can echo back into a page: ' . implode( ', ', $sl_leaked )
);

// =====================================================================
sl_section( 'Rule 4 — automation sends only through the signer (10.3)' );

/*
 * 10.0 wrote this rule as "only the signer may call wp_remote_*", which assumed
 * the signer would also be the sender. It is not: EnvelopeSigner::sign() returns
 * a body and its headers and puts nothing on the wire, because a class that
 * signs and sends can only be asked to do one of them at a time by anyone who
 * later wants the other.
 *
 * So the structural half of the rule is "one sender", and the property that
 * actually matters — the sender signs — is asserted on behaviour below, where
 * the transmitted bytes are checked against the HMAC.
 */
sl_forbid_pattern(
	'exactly one file puts an automation request on the wire',
	'/wp_remote_(?:request|post|get)\(/',
	array(
		// The sender, and its only job. 10.4 moved the call here out of
		// AutomationTransport so the bus could reuse it — this rule is what
		// noticed, which is the argument for expressing it structurally at all.
		// Both roles therefore sign, because signing is the first thing post()
		// does and there is no path around it.
		'includes/OTP/Transports/class-automation-endpoint.php',
		// Pre-existing callers, none of them carrying an OTP envelope.
		'includes/OTP/Transports/class-webhook-transport.php',
		'includes/Security/class-captcha.php',
		'includes/Auth/Providers/class-google-provider.php',
		'includes/Auth/Providers/class-zalo-provider.php',
		'includes/Auth/Providers/class-google-id-token-verifier.php',
	),
	'An unsigned request carrying an OTP is the failure mode HMAC exists to prevent.'
);

Settings::update(
	array(
		'automation.url'     => 'https://hooks.example.com/otp',
		'delivery.route_phone' => 'automation',
	)
);
Settings::store_secret( 'automation.secret', 'shared-signing-secret' );

$sl_automation                 = new SmartLogin\OTP\Transports\AutomationTransport();
$GLOBALS['sl_http_requests']   = array();
$GLOBALS['sl_http_response']   = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true}',
);

$sl_sent = $sl_automation->send(
	'84969789475',
	'482913',
	array(
		'intent'      => 'login',
		'ttl_seconds' => 300,
		'expires_ts'  => 1754136300,
	)
);

sl_assert( 'the automation transport delivers on a 2xx', true === $sl_sent, 'Expected true, got a WP_Error.' );

$sl_request = $GLOBALS['sl_http_requests'][0] ?? array();
$sl_body    = (string) ( $sl_request['args']['body'] ?? '' );
$sl_headers = (array) ( $sl_request['args']['headers'] ?? array() );
$sl_payload = json_decode( $sl_body, true );

sl_check( 'the envelope names the event', 'otp.send', $sl_payload['event'] ?? '' );
sl_check( 'the envelope names the channel explicitly', 'phone', $sl_payload['channel'] ?? '' );
sl_check( 'the envelope carries the code', '482913', $sl_payload['code'] ?? '' );

// The signature must be computed over the exact bytes sent. Recomputing it from
// a re-encode would pass on any implementation and prove nothing, so this signs
// the transmitted string itself.
sl_check(
	'the signature verifies against the body as transmitted',
	'sha256=' . hash_hmac( 'sha256', $sl_body, 'shared-signing-secret' ),
	$sl_headers['X-Smart-Login-Signature'] ?? ''
);

sl_assert(
	'the receiver is given what it needs to reject a replay',
	! empty( $sl_headers['X-Smart-Login-Timestamp'] ) && ! empty( $sl_headers['X-Smart-Login-Delivery'] ),
	'A signature alone does not stop the same envelope being posted again.'
);

sl_check(
	'the send is bounded by the same ceiling as every other channel',
	true,
	( (int) ( $sl_request['args']['timeout'] ?? 0 ) ) <= WebhookTransport::MAX_TIMEOUT
);

// Without a secret there is no signature, so the endpoint would receive live
// codes it cannot authenticate. That configuration is not offered.
Settings::store_secret( 'automation.secret', '' );
sl_check( 'no secret means the transport is not available', false, $sl_automation->is_available() );
Settings::store_secret( 'automation.secret', 'shared-signing-secret' );

unset( $GLOBALS['sl_http_response'] );

// =====================================================================
sl_section( 'Rule 5 — the routing table cannot dangle (10.1)' );

$sl_route_fields = array_filter(
	FieldRegistry::all(),
	static fn( string $path ): bool => 0 === strpos( $path, 'delivery.route_' ),
	ARRAY_FILTER_USE_KEY
);

if ( array() === $sl_route_fields ) {
	// Deliberately not a pass. With no route fields declared the loop below has
	// nothing to iterate, and a rule that passes because its subject is absent
	// reports the opposite of the truth.
	sl_pending(
		'every routing choice names a transport the router can resolve',
		'delivery.route_phone / delivery.route_email — 10.1'
	);
} else {
	$sl_router  = new TransportRouter();
	$sl_dangled = array();

	foreach ( $sl_route_fields as $sl_path => $sl_field ) {
		foreach ( array_keys( (array) ( $sl_field['choices'] ?? array() ) ) as $sl_choice ) {
			if ( ! $sl_router->get( (string) $sl_choice ) ) {
				$sl_dangled[] = $sl_path . ' → ' . $sl_choice;
			}
		}
	}

	sl_assert(
		'every routing choice names a transport the router can resolve',
		array() === $sl_dangled,
		'A choice the router cannot resolve fails closed at send time with nothing on screen to explain it: ' . implode( ', ', $sl_dangled )
	);
}

// =====================================================================
sl_section( 'Rule 6 — a failing bus never reaches the OTP path (10.4)' );

Settings::update(
	array(
		'automation.url'          => 'https://hooks.example.com/otp',
		'advanced.audit_enabled'  => 1,
		'automation.events'       => array( AuditLog::OTP_SENT ),
		'delivery.route_phone'    => 'sms',
	)
);
Settings::store_secret( 'automation.secret', 'shared-signing-secret' );

// The bus endpoint is down; the SMS transport is fine.
$GLOBALS['sl_http_response'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => 'bus is down',
);

$sl_bus_repo             = new SL_Fake_Otp_Repository();
$GLOBALS['sl_http_requests'] = array();

$sl_issued = sl_service_with( $sl_bus_repo, true )->issue( '84900000009', OtpService::INTENT_LOGIN );

sl_assert(
	'a failing bus leaves issue() returning a result',
	is_array( $sl_issued ),
	'The bus reached the OTP path. It must never be able to: ' . ( is_wp_error( $sl_issued ) ? $sl_issued->get_error_message() : '' )
);

sl_check( 'and the OTP row survives', 1, count( $sl_bus_repo->live_rows() ) );

// The two breakers must be different keys, or an analytics endpoint going down
// stops sign-in. Asserted on the transient names rather than on behaviour,
// because the behaviour only diverges on the day it matters.
sl_assert(
	'the bus breaker and the transport breaker are separate keys',
	EventBus::BREAKER_ID !== ( new AutomationTransport() )->id(),
	'Sharing a breaker would let a dead bus endpoint open the circuit that OTP delivery consults.'
);

// What went out, and what must not have.
$sl_bus_bodies = array();

foreach ( $GLOBALS['sl_http_requests'] as $sl_req ) {
	$sl_decoded = json_decode( (string) ( $sl_req['args']['body'] ?? '' ), true );

	if ( is_array( $sl_decoded ) && ( $sl_decoded['event'] ?? '' ) === AuditLog::OTP_SENT ) {
		$sl_bus_bodies[] = array(
			'payload' => $sl_decoded,
			'args'    => $sl_req['args'],
		);
	}
}

sl_check( 'a subscribed event produces exactly one request', 1, count( $sl_bus_bodies ) );

if ( $sl_bus_bodies ) {
	$sl_env = $sl_bus_bodies[0]['payload'];

	// array_key_exists, not empty(): a masked or blank code would still be a
	// code-shaped field the receiver could come to depend on.
	sl_check( 'the bus envelope has no code key at all', false, array_key_exists( 'code', $sl_env ) );

	sl_check(
		'the destination is masked, as the audit log already masks it',
		true,
		false === strpos( (string) ( $sl_env['destination'] ?? '' ), '84900000009' )
	);

	sl_check( 'the bus does not wait for an answer', false, $sl_bus_bodies[0]['args']['blocking'] ?? true );
}

// An unsubscribed event must produce no request at all — not a filtered one.
Settings::update( array( 'automation.events' => array() ) );
$GLOBALS['sl_http_requests'] = array();

sl_service_with( new SL_Fake_Otp_Repository(), true )->issue( '84900000010', OtpService::INTENT_LOGIN );

sl_check( 'an unsubscribed event produces no request', 0, count( $GLOBALS['sl_http_requests'] ) );

// Recursion: the failure record goes through AuditLog::record(), which
// dispatches. One attempt, not a chain.
Settings::update( array( 'automation.events' => array( AuditLog::AUTOMATION_BUS_FAILED ) ) );
$GLOBALS['sl_http_requests'] = array();

( new EventBus() )->dispatch( AuditLog::AUTOMATION_BUS_FAILED, '', array() );

sl_check(
	'reporting a bus failure does not re-enter the bus',
	1,
	count( $GLOBALS['sl_http_requests'] )
);

// A stored event name that no longer exists must not survive a save.
$sl_events_saved = Settings::sanitize(
	array(
		Settings::TAB_FIELD => (string) ( FieldRegistry::get( 'automation.events' )['tab'] ?? '' ),
		'automation'        => array(
			'events' => array( '', AuditLog::LOGIN_SUCCESS, 'an_event_that_was_removed' ),
		),
	)
);

sl_check(
	'only known event names are stored',
	array( AuditLog::LOGIN_SUCCESS ),
	$sl_events_saved['automation']['events'] ?? array()
);

unset( $GLOBALS['sl_http_response'] );
Settings::update( array( 'automation.events' => array() ) );
Settings::store_secret( 'automation.secret', '' );

// =====================================================================
sl_section( 'Rule 7 — the automation endpoint refuses plaintext HTTP (10.3)' );

Settings::update( array( 'automation.url' => 'https://hooks.example.com/otp' ) );

$sl_tab = (string) ( FieldRegistry::get( 'automation.url' )['tab'] ?? '' );

$sl_rejected = Settings::sanitize(
	array(
		Settings::TAB_FIELD => $sl_tab,
		'automation'        => array( 'url' => 'http://hooks.example.com/otp' ),
	)
);

// Keeping the previous value matters as much as the rejection. Blanking it on a
// mistyped scheme would leave a channel routed at an endpoint that is not there.
sl_check(
	'saving an http:// endpoint keeps the previous value',
	'https://hooks.example.com/otp',
	$sl_rejected['automation']['url'] ?? ''
);

$sl_accepted = Settings::sanitize(
	array(
		Settings::TAB_FIELD => $sl_tab,
		'automation'        => array( 'url' => 'https://other.example.com/hook' ),
	)
);

sl_check(
	'an https:// endpoint saves normally',
	'https://other.example.com/hook',
	$sl_accepted['automation']['url'] ?? ''
);

// =====================================================================
sl_section( 'Rule 10 — a channel routed at an unconfigured transport fails closed (10.3)' );

Settings::update( array( 'automation.url' => '' ) );

$sl_unconfigured = new TransportRouter(
	array(
		'sms'        => new SL_Fake_Transport( true ),
		'email'      => new SL_Fake_Transport( true ),
		'automation' => new SmartLogin\OTP\Transports\AutomationTransport(),
	)
);

Settings::update( array( 'delivery.route_phone' => 'automation' ) );

$sl_closed = $sl_unconfigured->send( '84969789475', '482913', array( 'intent' => 'login' ) );

sl_assert(
	'an unconfigured automation endpoint refuses rather than falling through',
	is_wp_error( $sl_closed ),
	'A routed transport that cannot send must say so. Silently using the built-in would mean the routing table is advisory, and an administrator who pointed a channel somewhere would never learn it did not go there.'
);

Settings::update(
	array(
		'delivery.route_phone' => 'sms',
		'automation.url'       => '',
	)
);

// =====================================================================
sl_section( 'Rule 8 — a failed send leaves the code already delivered usable (10.7)' );

/*
 * A lifecycle rule rather than a routing one, riding in this suite because a
 * third file for two rules costs more than it explains — and because 10.3
 * multiplies exactly this failure surface by adding a transport the site does
 * not operate.
 */
$sl_repo = new SL_Fake_Otp_Repository();

// One code, delivered.
$sl_first = sl_service_with( $sl_repo, true )->issue( '84900000001', OtpService::INTENT_LOGIN );

sl_assert(
	'the first code is issued',
	is_array( $sl_first ),
	'Setup failed, so everything below is meaningless: ' . ( is_wp_error( $sl_first ) ? $sl_first->get_error_message() : 'unknown' )
);

// The user is now holding it. They press Gửi lại and the gateway is down.
$sl_repo->ops = array();
$sl_second    = sl_service_with( $sl_repo, false )->issue( '84900000001', OtpService::INTENT_LOGIN );

sl_assert(
	'a failed resend reports the failure',
	is_wp_error( $sl_second ),
	'The send failed but issue() returned success.'
);

sl_check(
	'the code the user is holding is still redeemable',
	1,
	count( $sl_repo->live_rows() )
);

sl_assert(
	'nothing is consumed before the send is known to have worked',
	! in_array( 'consume', array_slice( $sl_repo->ops, 0, array_search( 'insert', $sl_repo->ops, true ) ?: 0 ), true )
		&& 1 === count( $sl_repo->live_rows() ),
	'consume_open_codes() runs at class-otp-service.php:100, before the send at :136. A gateway failure therefore destroys a code the user already has and the rollback leaves them with neither. Operations seen: ' . implode( ' → ', $sl_repo->ops )
);

// The other half: on success the newest code must still be the only one.
$sl_repo->ops = array();
$sl_third     = sl_service_with( $sl_repo, true )->issue( '84900000001', OtpService::INTENT_LOGIN );

sl_assert(
	'a successful resend does retire the previous code',
	is_array( $sl_third ) && 1 === count( $sl_repo->live_rows() ),
	'Moving the consume must not lose the property it exists for: exactly one live code per destination and intent.'
);

// =====================================================================
sl_section( 'Rule 9 — every outbound channel has a ceiling on worker time (10.7)' );

$sl_clamps_smtp = false;

foreach ( sl_plugin_sources() as $sl_contents ) {
	if ( false !== strpos( $sl_contents, 'phpmailer_init' ) ) {
		$sl_clamps_smtp = true;
		break;
	}
}

sl_assert(
	'the SMTP send is bounded like the HTTP send',
	$sl_clamps_smtp,
	sprintf(
		'WebhookTransport caps one send at %ds and explains why. wp_mail() is uncapped: PHPMailer defaults to Timeout = Timelimit = 300, twenty times the ceiling, on the channel that is enabled by default. The breaker bounds how often a dead channel is called, not how long one call may hold a worker.',
		WebhookTransport::MAX_TIMEOUT
	)
);

// The clamp is right for a six-digit code and wrong for a WooCommerce invoice,
// so "registered" is only half the property — it must also be gone afterwards.
Settings::update( array( 'email.enabled' => 1 ) );

$sl_mail = new SmartLogin\OTP\Transports\MailTransport();
$sl_mail->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );

sl_assert(
	'the clamp is removed once the plugin\'s own mail is sent',
	! has_filter( 'phpmailer_init' ),
	'A ceiling left registered applies to every later wp_mail() on the request, including mail this plugin did not send.'
);

$sl_probe = new stdClass();
$sl_mail->clamp_timeout( $sl_probe );

sl_check(
	'the ceiling matches the one the HTTP send uses',
	WebhookTransport::MAX_TIMEOUT,
	$sl_probe->Timeout ?? 0
);

// =====================================================================
sl_section( 'Rule 13 — every issued code records the channel it belongs to (10.5)' );

Settings::update( array( 'delivery.route_phone' => 'sms' ) );

$sl_channel_repo = new SL_Fake_Otp_Repository();
sl_service_with( $sl_channel_repo, true )->issue( '84900000011', OtpService::INTENT_LOGIN );
sl_service_with( $sl_channel_repo, true )->issue( 'nguoi.dung@example.com', OtpService::INTENT_LOGIN );

$sl_recorded = array();

foreach ( $sl_channel_repo->rows as $sl_row ) {
	$sl_recorded[] = (string) ( $sl_row['identity_channel'] ?? '' );
}

sort( $sl_recorded );

// Left empty, the column made every per-channel count a guess from the
// destination string — which is the read-time compensation 10.5 exists to avoid.
sl_check( 'the channel is stored, not left blank', array( 'email', 'phone' ), $sl_recorded );

Settings::store_secret( 'automation.secret', '' );

sl_summary( 'Delivery routing' );
