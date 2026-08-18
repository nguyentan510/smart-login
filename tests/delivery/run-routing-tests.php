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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\FieldRegistry;
use OmniWP\OTP\OtpRepository;
use OmniWP\OTP\OtpService;
use OmniWP\OTP\Transports\AutomationEndpoint;
use OmniWP\OTP\Transports\EnvelopeSigner;
use OmniWP\OTP\Transports\EventBus;
use OmniWP\OTP\Transports\TransportInterface;
use OmniWP\Security\AuditLog;
use OmniWP\OTP\Transports\TransportRouter;
use OmniWP\OTP\Transports\WebhookTransport;
use OmniWP\Security\Captcha;
use OmniWP\Security\RateLimiter;
use OmniWP\Security\SecretBox;
use OmniWP\Settings;

/**
 * An in-memory OTP store, so the ordering inside issue() can be asserted on
 * behaviour rather than on the shape of the source.
 *
 * The stub $wpdb cannot serve this: it does not parse SQL, so consume and insert
 * are indistinguishable through it. OtpService takes its repository by
 * constructor, so the seam needed for an honest assertion is one the production
 * code already offers.
 */
class ow_Fake_Otp_Repository extends OtpRepository {

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
class ow_Fake_Transport implements TransportInterface {

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
			: new WP_Error( 'ow_test_gateway_down', 'gateway down' );
	}
}

/**
 * A transport that is registered but not configured — the shape a site adds
 * through `omniwp_otp_transports` and then forgets to fill in.
 *
 * Deliberately implements nothing beyond TransportInterface: whatever lets the
 * router describe this refusal must be optional, or every transport written
 * against the published contract fatals on upgrade.
 */
class ow_Unconfigured_Transport implements TransportInterface {

	/** @var string */
	private $id;

	public function __construct( string $id ) {
		$this->id = $id;
	}

	public function id(): string {
		return $this->id;
	}

	public function is_available(): bool {
		return false;
	}

	public function send( string $destination, string $code, array $ctx ) {
		return new WP_Error( 'ow_test_send_reached', 'send() must not be reached on an unavailable transport' );
	}
}

/** Limits are 9's subject, not this suite's. */
class ow_Allow_Limiter extends RateLimiter {

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
function ow_service_with( ow_Fake_Otp_Repository $repo, bool $succeeds ): OtpService {
	return new OtpService(
		$repo,
		new TransportRouter(
			array(
				'sms'   => new ow_Fake_Transport( $succeeds ),
				'email' => new ow_Fake_Transport( $succeeds ),
			)
		),
		new ow_Allow_Limiter( $repo )
	);
}

// =====================================================================
ow_section( 'Rule 1 — one place decides how a code travels (10.1)' );

/*
 * Testing a destination for '@' is legitimate in several places: it tells a
 * phone from an email identity, it decides which placeholder blanks, it masks a
 * value for the log. What must not spread is the *transport* decision.
 *
 * That distinction is not greppable, so the rule is drawn one step back: the
 * test itself is forbidden outside this list, and joining the list means editing
 * this file and writing down why. Six entries today, each justified:
 */
$ow_at_test_allowed = array(
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

ow_forbid_pattern(
	'no new file learns to tell an email from a phone by itself',
	"/strpos\(\s*\\\$[A-Za-z_]+,\s*'@'\s*\)/",
	$ow_at_test_allowed,
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
$ow_routing_router = new TransportRouter(
	array(
		'sms'        => new ow_Fake_Transport( true ),
		'email'      => new ow_Fake_Transport( true ),
		'automation' => new ow_Fake_Transport( true ),
	)
);

/*
 * 10.1's two assertions here required a stored setting to move the answer. 20.1
 * reversed that decision — the argument is D1 in docs/sending-a-code.md — and
 * Rule 14 below now asserts the opposite property, behaviourally: no stored
 * value may move it. They are not both keepable, and the newer one is the one an
 * administrator walked into.
 *
 * What survives unchanged is the pair underneath, which never depended on the
 * table: the channel decides, and it decides the same way it did before 10.1.
 */
ow_check( 'a phone number defaults to the SMS gateway', 'sms', $ow_routing_router->transport_for( '84969789475' ) );
ow_check( 'an email address defaults to wp_mail()', 'email', $ow_routing_router->transport_for( 'ban@example.com' ) );

// A stored value naming a transport nothing registers must not resolve to
// nothing: a filter that used to add a transport can be removed at any time.
ow_check(
	'an unresolvable stored route falls back to the built-in',
	'sms',
	( static function () use ( $ow_routing_router ): string {
		Settings::update( array( 'delivery.route_phone' => 'a-transport-nobody-registered' ) );
		$answer = $ow_routing_router->transport_for( '84969789475' );
		Settings::update( array( 'delivery.route_phone' => 'sms' ) );

		return $answer;
	} )()
);

// =====================================================================
ow_section( 'Rule 2 — secret storage holds no per-field branch (10.2)' );

$ow_store_body = ow_method_body( ow_source( 'includes/class-settings.php' ), 'store_secret' );

ow_assert(
	'Settings::store_secret() names no individual field',
	'' !== $ow_store_body && ! preg_match( "/'[A-Za-z0-9_.]*_secret'/", $ow_store_body ),
	'A secret field whose path nobody added to this branch is pruned from the option array anyway (class-settings.php:219) and stored nowhere. That is a control which accepts input and discards it in silence.'
);

// =====================================================================
ow_section( 'Rule 3 — every declared secret field round-trips (10.2)' );

$ow_secret_fields = array_filter(
	FieldRegistry::all(),
	static fn( array $field ): bool => 'secret' === ( $field['type'] ?? '' )
);

ow_assert(
	'the registry declares at least one secret field',
	array() !== $ow_secret_fields,
	'Nothing to check. If this fails the rule below is meaningless, not passing.'
);

$ow_has_reader = method_exists( Settings::class, 'read_secret' );

ow_assert(
	'Settings exposes a generic reader for secret fields',
	$ow_has_reader,
	'Captcha::secret() is the only way to read a stored secret, and it is bound to one field. Without a generic reader a second secret field cannot be verified to have been stored at all.'
);

if ( ! $ow_has_reader ) {
	foreach ( array_keys( $ow_secret_fields ) as $ow_path ) {
		ow_pending(
			sprintf( 'the value saved for "%s" can be read back', $ow_path ),
			'Settings::read_secret() — 10.2'
		);
	}
} else {
	foreach ( array_keys( $ow_secret_fields ) as $ow_path ) {
		$ow_field = FieldRegistry::get( $ow_path );
		$ow_input = array( Settings::TAB_FIELD => (string) ( $ow_field['tab'] ?? '' ) );
		$ow_parts = explode( '.', $ow_path );
		$ow_leaf  = array_pop( $ow_parts );
		$ow_node  = &$ow_input;

		foreach ( $ow_parts as $ow_part ) {
			if ( ! isset( $ow_node[ $ow_part ] ) ) {
				$ow_node[ $ow_part ] = array();
			}
			$ow_node = &$ow_node[ $ow_part ];
		}

		$ow_node[ $ow_leaf ] = 'round-trip-' . md5( $ow_path );
		unset( $ow_node );

		update_option( Settings::OPTION, Settings::sanitize( $ow_input ) );

		ow_check(
			sprintf( 'the value saved for "%s" can be read back', $ow_path ),
			'round-trip-' . md5( $ow_path ),
			Settings::read_secret( $ow_path )
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

ow_check(
	'a secret written through the path-keyed store reads back',
	'sealed-after-15-3',
	Captcha::secret()
);

Captcha::clear_secret();

ow_check( 'and clearing empties it', '', Captcha::secret() );

// The other half of the same property: the plaintext must not survive in the
// option array. This one can be checked today, and does pass — absorb_secret_fields()
// prunes unconditionally. It is here so 10.2 cannot fix the storage by removing
// the pruning.
$ow_leaked = array();

foreach ( array_keys( $ow_secret_fields ) as $ow_path ) {
	$ow_field = FieldRegistry::get( $ow_path );
	$ow_probe = array(
		Settings::TAB_FIELD => (string) ( $ow_field['tab'] ?? '' ),
	);

	$ow_parts = explode( '.', $ow_path );
	$ow_leaf  = array_pop( $ow_parts );
	$ow_node  = &$ow_probe;

	foreach ( $ow_parts as $ow_part ) {
		if ( ! isset( $ow_node[ $ow_part ] ) ) {
			$ow_node[ $ow_part ] = array();
		}
		$ow_node = &$ow_node[ $ow_part ];
	}

	$ow_node[ $ow_leaf ] = 'must-not-persist-in-the-option';
	unset( $ow_node );

	if ( false !== strpos( wp_json_encode( Settings::sanitize( $ow_probe ) ), 'must-not-persist-in-the-option' ) ) {
		$ow_leaked[] = $ow_path;
	}
}

ow_assert(
	'no secret survives in the settings option',
	array() === $ow_leaked,
	'A secret in the option array is a secret the field renderer can echo back into a page: ' . implode( ', ', $ow_leaked )
);

// =====================================================================
ow_section( 'Rule 4 — automation sends only through the signer (10.3)' );

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
ow_forbid_pattern(
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
		'includes/Auth/Providers/class-google-id-token-verifier.php',
	),
	'An unsigned request carrying an OTP is the failure mode HMAC exists to prevent.'
);

Settings::update( array( 'automation.url' => 'https://hooks.example.com/otp' ) );
Settings::store_secret( 'automation.secret', 'shared-signing-secret' );

/*
 * Driven through the endpoint rather than through a transport since 20.1. The
 * OTP half of this endpoint retired into the signed SMS provider, where Rule 18
 * asserts the same property over the bytes that provider transmits. What is left
 * here is the bus, and the bus signs through exactly this call.
 */
$ow_endpoint                   = new AutomationEndpoint();
$GLOBALS['ow_http_requests']   = array();
$GLOBALS['ow_http_response']   = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true}',
);

$ow_sent = $ow_endpoint->post(
	AutomationEndpoint::base_envelope( 'otp.send', 'fixed-delivery-id' ) + array(
		'channel' => 'phone',
		'code'    => '482913',
	),
	true
);

ow_assert( 'the endpoint delivers on a 2xx', ! is_wp_error( $ow_sent ), 'Expected a response, got a WP_Error.' );

$ow_request = $GLOBALS['ow_http_requests'][0] ?? array();
$ow_body    = (string) ( $ow_request['args']['body'] ?? '' );
$ow_headers = (array) ( $ow_request['args']['headers'] ?? array() );
$ow_payload = json_decode( $ow_body, true );

ow_check( 'the envelope names the event', 'otp.send', $ow_payload['event'] ?? '' );
ow_check( 'the envelope names the channel explicitly', 'phone', $ow_payload['channel'] ?? '' );
ow_check( 'the envelope carries the code', '482913', $ow_payload['code'] ?? '' );

// The signature must be computed over the exact bytes sent. Recomputing it from
// a re-encode would pass on any implementation and prove nothing, so this signs
// the transmitted string itself.
ow_check(
	'the signature verifies against the body as transmitted',
	'sha256=' . hash_hmac( 'sha256', $ow_body, 'shared-signing-secret' ),
	$ow_headers['X-omniwp-Signature'] ?? ''
);

ow_assert(
	'the receiver is given what it needs to reject a replay',
	! empty( $ow_headers['X-omniwp-Timestamp'] ) && ! empty( $ow_headers['X-omniwp-Delivery'] ),
	'A signature alone does not stop the same envelope being posted again.'
);

ow_check(
	'the send is bounded by the same ceiling as every other channel',
	true,
	( (int) ( $ow_request['args']['timeout'] ?? 0 ) ) <= WebhookTransport::MAX_TIMEOUT
);

// Without a secret there is no signature, so the endpoint would receive live
// codes it cannot authenticate. That configuration is not offered.
Settings::store_secret( 'automation.secret', '' );
ow_check( 'no secret means the endpoint is not configured', false, $ow_endpoint->is_configured() );
Settings::store_secret( 'automation.secret', 'shared-signing-secret' );

unset( $GLOBALS['ow_http_response'] );

// =====================================================================
ow_section( 'Rule 5 — retired by 20.1' );

/*
 * 10.1 asked whether every choice in `delivery.route_phone` and
 * `delivery.route_email` named a transport the router could resolve, and it
 * reported PENDING rather than passing when the fields were absent — because a
 * rule that passes for want of a subject reports the opposite of the truth.
 *
 * 20.1 removed the subject deliberately. The successor is Rule 14, and it holds
 * a strictly stronger property: not "every routing choice resolves" but "no
 * setting names a transport at all", which makes a dangling choice
 * unrepresentable instead of merely absent. Leaving this rule here would report
 * PENDING for ever against a table that is never coming back.
 *
 * Retired rather than deleted silently, because a rule that vanishes from a
 * suite with no note is indistinguishable from one somebody quietly removed to
 * get a green run.
 */
ow_note( 'superseded by Rule 14 — no setting names a transport (20.1)' );

// =====================================================================
ow_section( 'Rule 6 — a failing bus never reaches the OTP path (10.4)' );

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
$GLOBALS['ow_http_response'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => 'bus is down',
);

$ow_bus_repo             = new ow_Fake_Otp_Repository();
$GLOBALS['ow_http_requests'] = array();

$ow_issued = ow_service_with( $ow_bus_repo, true )->issue( '84900000009', OtpService::INTENT_LOGIN );

ow_assert(
	'a failing bus leaves issue() returning a result',
	is_array( $ow_issued ),
	'The bus reached the OTP path. It must never be able to: ' . ( is_wp_error( $ow_issued ) ? $ow_issued->get_error_message() : '' )
);

ow_check( 'and the OTP row survives', 1, count( $ow_bus_repo->live_rows() ) );

// The two breakers must be different keys, or an analytics endpoint going down
// stops sign-in. Asserted on the transient names rather than on behaviour,
// because the behaviour only diverges on the day it matters.
ow_assert(
	'the bus breaker and the transport breaker are separate keys',
	EventBus::BREAKER_ID !== ( new WebhookTransport() )->id(),
	'Sharing a breaker would let a dead bus endpoint open the circuit that OTP delivery consults.'
);

// What went out, and what must not have.
$ow_bus_bodies = array();

foreach ( $GLOBALS['ow_http_requests'] as $ow_req ) {
	$ow_decoded = json_decode( (string) ( $ow_req['args']['body'] ?? '' ), true );

	if ( is_array( $ow_decoded ) && ( $ow_decoded['event'] ?? '' ) === AuditLog::OTP_SENT ) {
		$ow_bus_bodies[] = array(
			'payload' => $ow_decoded,
			'args'    => $ow_req['args'],
		);
	}
}

ow_check( 'a subscribed event produces exactly one request', 1, count( $ow_bus_bodies ) );

if ( $ow_bus_bodies ) {
	$ow_env = $ow_bus_bodies[0]['payload'];

	// array_key_exists, not empty(): a masked or blank code would still be a
	// code-shaped field the receiver could come to depend on.
	ow_check( 'the bus envelope has no code key at all', false, array_key_exists( 'code', $ow_env ) );

	ow_check(
		'the destination is masked, as the audit log already masks it',
		true,
		false === strpos( (string) ( $ow_env['destination'] ?? '' ), '84900000009' )
	);

	ow_check( 'the bus does not wait for an answer', false, $ow_bus_bodies[0]['args']['blocking'] ?? true );
}

// An unsubscribed event must produce no request at all — not a filtered one.
Settings::update( array( 'automation.events' => array() ) );
$GLOBALS['ow_http_requests'] = array();

ow_service_with( new ow_Fake_Otp_Repository(), true )->issue( '84900000010', OtpService::INTENT_LOGIN );

ow_check( 'an unsubscribed event produces no request', 0, count( $GLOBALS['ow_http_requests'] ) );

// Recursion: the failure record goes through AuditLog::record(), which
// dispatches. One attempt, not a chain.
Settings::update( array( 'automation.events' => array( AuditLog::AUTOMATION_BUS_FAILED ) ) );
$GLOBALS['ow_http_requests'] = array();

( new EventBus() )->dispatch( AuditLog::AUTOMATION_BUS_FAILED, '', array() );

ow_check(
	'reporting a bus failure does not re-enter the bus',
	1,
	count( $GLOBALS['ow_http_requests'] )
);

// A stored event name that no longer exists must not survive a save.
$ow_events_saved = Settings::sanitize(
	array(
		Settings::TAB_FIELD => (string) ( FieldRegistry::get( 'automation.events' )['tab'] ?? '' ),
		'automation'        => array(
			'events' => array( '', AuditLog::LOGIN_SUCCESS, 'an_event_that_was_removed' ),
		),
	)
);

ow_check(
	'only known event names are stored',
	array( AuditLog::LOGIN_SUCCESS ),
	$ow_events_saved['automation']['events'] ?? array()
);

unset( $GLOBALS['ow_http_response'] );
Settings::update( array( 'automation.events' => array() ) );
Settings::store_secret( 'automation.secret', '' );

// =====================================================================
ow_section( 'Rule 7 — the automation endpoint refuses plaintext HTTP (10.3)' );

Settings::update( array( 'automation.url' => 'https://hooks.example.com/otp' ) );

$ow_tab = (string) ( FieldRegistry::get( 'automation.url' )['tab'] ?? '' );

$ow_rejected = Settings::sanitize(
	array(
		Settings::TAB_FIELD => $ow_tab,
		'automation'        => array( 'url' => 'http://hooks.example.com/otp' ),
	)
);

// Keeping the previous value matters as much as the rejection. Blanking it on a
// mistyped scheme would leave a channel routed at an endpoint that is not there.
ow_check(
	'saving an http:// endpoint keeps the previous value',
	'https://hooks.example.com/otp',
	$ow_rejected['automation']['url'] ?? ''
);

$ow_accepted = Settings::sanitize(
	array(
		Settings::TAB_FIELD => $ow_tab,
		'automation'        => array( 'url' => 'https://other.example.com/hook' ),
	)
);

ow_check(
	'an https:// endpoint saves normally',
	'https://other.example.com/hook',
	$ow_accepted['automation']['url'] ?? ''
);

// =====================================================================
ow_section( 'Rule 10 — a channel routed at an unconfigured transport fails closed (10.3)' );

Settings::update( array( 'automation.url' => '' ) );

$ow_unconfigured = new TransportRouter(
	array(
		'sms'        => new ow_Fake_Transport( true ),
		'email'      => new ow_Fake_Transport( true ),
		'automation' => new ow_Unconfigured_Transport( 'automation' ),
	)
);

// Named on the context, because 20.1 removed the setting that used to name it.
// The property under test never was the routing table — it is that a transport
// which cannot send refuses, and says which one it was.
$ow_closed = $ow_unconfigured->send( '84969789475', '482913', array( 'intent' => 'login', 'transport' => 'automation' ) );

ow_assert(
	'an unconfigured automation endpoint refuses rather than falling through',
	is_wp_error( $ow_closed ),
	'A routed transport that cannot send must say so. Silently using the built-in would mean the routing table is advisory, and an administrator who pointed a channel somewhere would never learn it did not go there.'
);

/*
 * Failing closed is half the rule; saying which door closed is the other half.
 *
 * The refusal above used to be worded by a two-branch ternary — 'sms' or, for
 * everything else, email — so a phone number routed at the automation endpoint
 * came back as "Kênh email chưa được cấu hình". The user had typed a phone
 * number, the router had routed it correctly, and the screen still named a
 * channel nothing had touched. A message that misnames the failure sends the
 * administrator to the wrong settings tab, which is worse than no message.
 */
ow_assert(
	'the refusal names the transport that actually failed',
	is_wp_error( $ow_closed ) && false !== stripos( $ow_closed->get_error_message(), 'automation' ),
	'A phone routed at the automation endpoint must be refused in the automation endpoint\'s name. Got: ' . ( is_wp_error( $ow_closed ) ? $ow_closed->get_error_message() : 'no error at all' )
);

/*
 * The general case, which is the one a ternary can never satisfy. The transport
 * map is open — `omniwp_otp_transports` exists so a site can add ZNS or an
 * in-app push — and a message chosen by a fixed list of ids describes the
 * transports somebody thought of at the time. This one is not on any list.
 */
$ow_third_party = new TransportRouter(
	array(
		'sms'   => new ow_Fake_Transport( true ),
		'email' => new ow_Fake_Transport( true ),
		'zns'   => new ow_Unconfigured_Transport( 'zns' ),
	)
);

$ow_third_party_refusal = $ow_third_party->send( '84969789475', '482913', array( 'intent' => 'login', 'transport' => 'zns' ) );

ow_assert(
	'a transport registered by a filter is not described as one of the built-ins',
	is_wp_error( $ow_third_party_refusal )
		&& false === stripos( $ow_third_party_refusal->get_error_message(), 'email' )
		&& false === stripos( $ow_third_party_refusal->get_error_message(), 'SMS' ),
	'An unavailable transport must be described as itself. Borrowing the wording of a built-in tells the user a channel failed that was never asked to do anything. Got: ' . ( is_wp_error( $ow_third_party_refusal ) ? $ow_third_party_refusal->get_error_message() : 'no error at all' )
);

ow_assert(
	'an unavailable transport is refused before its own send() runs',
	is_wp_error( $ow_third_party_refusal ) && 'ow_test_send_reached' !== $ow_third_party_refusal->get_error_code(),
	'The router checks availability so a transport that cannot work is never called. Reaching send() would also feed the circuit breaker a failure that says nothing about the gateway.'
);

Settings::update(
	array(
		'delivery.route_phone' => 'sms',
		'automation.url'       => '',
	)
);

// =====================================================================
ow_section( 'Rule 14 — no setting names a transport (20.1)' );

/*
 * 10.1 made the transport a setting so a site could reach an automation platform
 * for phone delivery. 20.2 reaches the same platform through a gateway preset,
 * which was already there and is more flexible, so the setting now buys nothing
 * and costs a 2x2 matrix with one cell that delivers nothing and says nothing.
 * An administrator walked into that cell; see docs/sending-a-code.md.
 *
 * Asserted against the registry rather than against a file, so the way this
 * comes back — `delivery.route_whatsapp` for the next channel — fails too.
 */
$ow_route_settings = array_values(
	array_filter(
		array_keys( FieldRegistry::all() ),
		static fn( string $key ): bool => 1 === preg_match( '/^delivery\.route_/', $key )
	)
);

ow_check( 'no setting names the transport for a channel', array(), $ow_route_settings );

/*
 * The positive half. Forbidding the setting leaves the replacement unstated, and
 * "the channel decides" has to be true of the router, not just absent from the
 * form. Behavioural: no stored value may move the answer.
 */
$ow_fixed_router = new TransportRouter(
	array(
		'sms'        => new ow_Fake_Transport( true ),
		'email'      => new ow_Fake_Transport( true ),
		'automation' => new ow_Fake_Transport( true ),
	)
);

$ow_before_setting = $ow_fixed_router->transport_for( '84969789475' );
Settings::update( array( 'delivery.route_phone' => 'automation' ) );
$ow_after_setting = $ow_fixed_router->transport_for( '84969789475' );
Settings::update( array( 'delivery.route_phone' => 'sms' ) );

ow_check( 'no stored value can change which transport serves a phone', $ow_before_setting, $ow_after_setting );

// =====================================================================
ow_section( 'Rule 15 — the bus and the OTP path share no setting (20.2)' );

/*
 * Rule 6 asserts the *runtime* separation: a failing bus never reaches the OTP
 * path. 10.4 shipped that while the *configuration* was still shared, so turning
 * on an event stream silently configured an OTP transport as well. Four settings
 * sit on both paths today, all of them through AutomationEndpoint.
 *
 * The two paths are declared rather than discovered, because TransportRouter
 * does not expose its map and 20.0 changes no production file. The companion
 * check below is what stops the declaration from being edited into a pass.
 */
$ow_otp_path_files = array(
	'includes/OTP/Transports/class-webhook-transport.php',
	'includes/OTP/Transports/class-mail-transport.php',
	// AutomationTransport and AutomationEndpoint left this list in 20.1, when
	// the transport role retired into the signed provider. That is what empties
	// the intersection; nothing about the bus's own settings changed.
);

$ow_bus_files = array(
	'includes/OTP/Transports/class-event-bus.php',
	'includes/OTP/Transports/class-automation-endpoint.php',
);

/**
 * Settings keys named in a set of files, grounded in the registry.
 *
 * Filtered against `FieldRegistry::all()` so an ordinary string that happens to
 * look like a dot path — a JSON key, a filter name — cannot inflate the answer.
 *
 * @param string[] $relative_files
 * @return string[]
 */
function ow_setting_keys_in( array $relative_files ): array {
	$known = array_flip( array_keys( FieldRegistry::all() ) );
	$found = array();

	foreach ( $relative_files as $relative ) {
		if ( preg_match_all( "/'([a-z_]+\.[a-z_]+)'/", ow_source( $relative ), $matches ) ) {
			foreach ( $matches[1] as $key ) {
				if ( isset( $known[ $key ] ) ) {
					$found[ $key ] = true;
				}
			}
		}
	}

	ksort( $found );

	return array_keys( $found );
}

$ow_shared_settings = array_values(
	array_intersect( ow_setting_keys_in( $ow_otp_path_files ), ow_setting_keys_in( $ow_bus_files ) )
);

ow_check( 'no setting is read by both the OTP path and the event bus', array(), $ow_shared_settings );

/*
 * Without this, Rule 15 is satisfied by deleting a line from the list above.
 * Every transport in the tree must be accounted for on the OTP side.
 */
$ow_unaccounted = array();

foreach ( ow_plugin_sources() as $relative => $contents ) {
	if ( 0 !== strpos( $relative, 'includes/OTP/Transports/' ) ) {
		continue;
	}

	if ( false === strpos( $contents, 'implements TransportInterface' ) ) {
		continue;
	}

	if ( ! in_array( $relative, $ow_otp_path_files, true ) ) {
		$ow_unaccounted[] = $relative;
	}
}

ow_check( 'every transport in the tree is on the declared OTP path', array(), $ow_unaccounted );

// =====================================================================
ow_section( 'Rule 18 — a signed provider signs what it sends (20.2)' );

/*
 * The control this sub-phase exists to preserve. D2's first draft would have
 * signed a rendered body template; `class-envelope-signer.php:3-14` explains at
 * length why that makes a signature decorative. So the assertion is deliberately
 * not "a signature header is present" — it recomputes the HMAC over the bytes the
 * transport actually produced, which is the only version of this test that would
 * have failed the design it replaced.
 */
Settings::update(
	array(
		'sms.enabled'       => 1,
		'sms.preset'        => 'signed',
		'sms.signed_url'    => 'https://automation.example/hook',
		'sms.headers'       => array(
			// An administrator trying to take the signature over, deliberately.
			array(
				'key'   => EnvelopeSigner::SIGNATURE_HEADER,
				'value' => 'sha256=forged',
			),
			array(
				'key'   => 'X-Tenant',
				'value' => 'acme',
			),
		),
	)
);

// Secrets travel through the path-keyed store, not the option array.
Settings::store_secret( 'sms.signed_secret', 'test-signing-key' );

$GLOBALS['ow_http_requests'] = array();
$GLOBALS['ow_http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true}',
);

( new WebhookTransport() )->send( '84969789475', '482913', array( 'intent' => 'register' ) );

$ow_signed_request = $GLOBALS['ow_http_requests'][0] ?? null;

$ow_sent_body    = (string) ( $ow_signed_request['args']['body'] ?? '' );
$ow_sent_headers = (array) ( $ow_signed_request['args']['headers'] ?? array() );
$ow_sent_sig     = (string) ( $ow_sent_headers[ EnvelopeSigner::SIGNATURE_HEADER ] ?? '' );

ow_assert(
	'a signed provider produces a request at all',
	null !== $ow_signed_request,
	'Nothing was sent, so everything below is vacuous.'
);

ow_check(
	'the signature verifies against the transmitted bytes',
	'sha256=' . hash_hmac( 'sha256', $ow_sent_body, 'test-signing-key' ),
	$ow_sent_sig
);

ow_assert(
	'the body is the envelope, not a rendered template',
	is_array( json_decode( $ow_sent_body, true ) )
		&& '482913' === (string) ( json_decode( $ow_sent_body, true )['code'] ?? '' ),
	'A signed provider must build its payload in code. Got: ' . substr( $ow_sent_body, 0, 120 )
);

ow_assert(
	'an administrator header cannot replace the signature',
	'sha256=forged' !== $ow_sent_sig,
	'Configured headers may add, never replace — otherwise the one control that makes this endpoint safe is switchable from the settings screen.'
);

ow_check(
	'an administrator header that collides with nothing still travels',
	'acme',
	(string) ( $ow_sent_headers['X-Tenant'] ?? '' )
);

ow_assert(
	'the signed endpoint is where the request went',
	'https://automation.example/hook' === (string) ( $ow_signed_request['url'] ?? '' ),
	'Got: ' . (string) ( $ow_signed_request['url'] ?? '(none)' )
);

Settings::update(
	array(
		'sms.enabled'       => 0,
		'sms.preset'        => 'generic',
		'sms.signed_url'    => '',
		'sms.headers'       => array(),
	)
);

Settings::store_secret( 'sms.signed_secret', '' );

// =====================================================================
ow_section( 'Rule 19 — an automation-routed site survives the upgrade (20.3)' );

/*
 * The load-bearing rule of the phase. 10.1 shipped with defaults that reproduced
 * existing behaviour byte for byte; this phase deletes two settings sites have
 * deliberately set, so the only thing standing between an upgrade and a site that
 * silently stops delivering codes is this function.
 *
 * Asserted three ways, because "it migrated" is not one property:
 *   1. a phone-routed install arrives fully configured on the signed provider
 *   2. an email-routed install, which has no destination, produces a notice
 *   3. a site that never used automation is not touched at all
 */
$ow_migrator = array( \OmniWP\Installer::class, 'migrate_automation_delivery' );

ow_assert(
	'the migration exists',
	is_callable( $ow_migrator ),
	'Installer::migrate_automation_delivery() — 20.3'
);

/** Put the option into a known pre-upgrade shape and forget every secret. */
function ow_seed_pre_upgrade( array $settings ): void {
	Settings::store_secret( 'automation.secret', '' );
	Settings::store_secret( 'sms.signed_secret', '' );
	update_option( Settings::OPTION, array() );
	delete_option( \OmniWP\Installer::MIGRATION_NOTICE_OPTION );
	Settings::update( $settings );
}

if ( is_callable( $ow_migrator ) ) {
	// 1. Phone routed at automation: the configuration has to arrive intact.
	ow_seed_pre_upgrade(
		array(
			'delivery.route_phone' => 'automation',
			'automation.url'       => 'https://n8n.example/otp',
			'sms.preset'           => 'generic',
			'sms.enabled'          => 0,
		)
	);
	Settings::store_secret( 'automation.secret', 'the-original-key' );

	$ow_migrator();

	ow_check( 'the endpoint arrives', 'https://n8n.example/otp', (string) Settings::get( 'sms.signed_url' ) );
	ow_check( 'the provider is the signed one', 'signed', (string) Settings::get( 'sms.preset' ) );
	ow_check( 'the channel is left switched on', 1, (int) Settings::get( 'sms.enabled' ) );
	ow_check( 'the signing key reads back', 'the-original-key', Settings::read_secret( 'sms.signed_secret' ) );

	ow_assert(
		'the source configuration is not erased',
		'https://n8n.example/otp' === (string) Settings::get( 'automation.url' ),
		'20.4 decides the bus role. Erasing the source before the destination has been used in anger is unrecoverable.'
	);

	// Idempotence, asserted rather than assumed. The version guard is not the
	// only thing that can call this — a support script or a second activation can.
	$ow_after_first = Settings::all();
	$ow_migrator();

	ow_check( 'migrating twice is the same as migrating once', $ow_after_first, Settings::all() );

	// 2. Email routed at automation: nothing to migrate, so it must be reported.
	ow_seed_pre_upgrade(
		array(
			'delivery.route_email' => 'automation',
			'automation.url'       => 'https://n8n.example/otp',
		)
	);
	Settings::store_secret( 'automation.secret', 'the-original-key' );

	$ow_migrator();

	$ow_notices = (array) get_option( \OmniWP\Installer::MIGRATION_NOTICE_OPTION, array() );

	ow_assert(
		'an email route that cannot be migrated is reported',
		array() !== $ow_notices,
		'The site loses a delivery capability. An upgrade hook may not choose a replacement, but it may not stay quiet either.'
	);

	ow_assert(
		'the report names the setting',
		false !== strpos( implode( ' ', $ow_notices ), 'route_email' ),
		'A notice that does not name what changed sends the administrator hunting. Got: ' . implode( ' | ', $ow_notices )
	);

	/*
	 * 3. Routed at automation with no endpoint — the shape the reporting install
	 * was actually in, and the one that turns a migration into a demolition.
	 *
	 * There is nothing to move: the site was not delivering through automation,
	 * because there was no automation to deliver through. Migrating anyway would
	 * point `sms.preset` at a signed provider with no endpoint *and* overwrite the
	 * gateway the administrator had configured on the way past. That is the
	 * failure `Installer::cleanup()`'s flat retention keys already cost this
	 * project once: a hook rewriting a setting it was not asked about.
	 */
	ow_seed_pre_upgrade(
		array(
			'delivery.route_phone' => 'automation',
			'automation.url'       => '',
			'sms.preset'           => 'generic',
			'sms.url'              => 'https://n8n.example/local-otp',
			'sms.enabled'          => 1,
		)
	);

	$ow_migrator();

	ow_check( 'an empty endpoint does not overwrite the configured gateway', 'generic', (string) Settings::get( 'sms.preset' ) );
	ow_check( 'and does not overwrite its URL', 'https://n8n.example/local-otp', (string) Settings::get( 'sms.url' ) );

	ow_assert(
		'a route pointing at nothing is reported instead',
		false !== strpos( implode( ' ', (array) get_option( \OmniWP\Installer::MIGRATION_NOTICE_OPTION, array() ) ), 'route_phone' ),
		'The site was routed at an endpoint it never configured, so it has not been delivering. Silence here is how it stays that way.'
	);

	// 4. A site that never used automation keeps its own gateway untouched.
	ow_seed_pre_upgrade(
		array(
			'delivery.route_phone' => 'sms',
			'sms.preset'           => 'esms',
			'sms.url'              => 'https://rest.esms.vn/x',
		)
	);

	$ow_migrator();

	ow_check( 'an unrelated site keeps its provider', 'esms', (string) Settings::get( 'sms.preset' ) );
	ow_check( 'and keeps its gateway URL', 'https://rest.esms.vn/x', (string) Settings::get( 'sms.url' ) );
	ow_check(
		'and is reported nothing',
		array(),
		(array) get_option( \OmniWP\Installer::MIGRATION_NOTICE_OPTION, array() )
	);
} else {
	foreach ( array( 'the endpoint arrives', 'the signing key reads back', 'an unrelated site keeps its provider' ) as $ow_blocked ) {
		ow_pending( $ow_blocked, 'Installer::migrate_automation_delivery() — 20.3' );
	}
}

ow_seed_pre_upgrade( array( 'delivery.route_phone' => 'sms' ) );

// =====================================================================
ow_section( 'Rule 8 — a failed send leaves the code already delivered usable (10.7)' );

/*
 * A lifecycle rule rather than a routing one, riding in this suite because a
 * third file for two rules costs more than it explains — and because 10.3
 * multiplies exactly this failure surface by adding a transport the site does
 * not operate.
 */
$ow_repo = new ow_Fake_Otp_Repository();

// One code, delivered.
$ow_first = ow_service_with( $ow_repo, true )->issue( '84900000001', OtpService::INTENT_LOGIN );

ow_assert(
	'the first code is issued',
	is_array( $ow_first ),
	'Setup failed, so everything below is meaningless: ' . ( is_wp_error( $ow_first ) ? $ow_first->get_error_message() : 'unknown' )
);

// The user is now holding it. They press Gửi lại and the gateway is down.
$ow_repo->ops = array();
$ow_second    = ow_service_with( $ow_repo, false )->issue( '84900000001', OtpService::INTENT_LOGIN );

ow_assert(
	'a failed resend reports the failure',
	is_wp_error( $ow_second ),
	'The send failed but issue() returned success.'
);

ow_check(
	'the code the user is holding is still redeemable',
	1,
	count( $ow_repo->live_rows() )
);

ow_assert(
	'nothing is consumed before the send is known to have worked',
	! in_array( 'consume', array_slice( $ow_repo->ops, 0, array_search( 'insert', $ow_repo->ops, true ) ?: 0 ), true )
		&& 1 === count( $ow_repo->live_rows() ),
	'consume_open_codes() runs at class-otp-service.php:100, before the send at :136. A gateway failure therefore destroys a code the user already has and the rollback leaves them with neither. Operations seen: ' . implode( ' → ', $ow_repo->ops )
);

// The other half: on success the newest code must still be the only one.
$ow_repo->ops = array();
$ow_third     = ow_service_with( $ow_repo, true )->issue( '84900000001', OtpService::INTENT_LOGIN );

ow_assert(
	'a successful resend does retire the previous code',
	is_array( $ow_third ) && 1 === count( $ow_repo->live_rows() ),
	'Moving the consume must not lose the property it exists for: exactly one live code per destination and intent.'
);

// =====================================================================
ow_section( 'Rule 9 — every outbound channel has a ceiling on worker time (10.7)' );

$ow_clamps_smtp = false;

foreach ( ow_plugin_sources() as $ow_contents ) {
	if ( false !== strpos( $ow_contents, 'phpmailer_init' ) ) {
		$ow_clamps_smtp = true;
		break;
	}
}

ow_assert(
	'the SMTP send is bounded like the HTTP send',
	$ow_clamps_smtp,
	sprintf(
		'WebhookTransport caps one send at %ds and explains why. wp_mail() is uncapped: PHPMailer defaults to Timeout = Timelimit = 300, twenty times the ceiling, on the channel that is enabled by default. The breaker bounds how often a dead channel is called, not how long one call may hold a worker.',
		WebhookTransport::MAX_TIMEOUT
	)
);

// The clamp is right for a six-digit code and wrong for a WooCommerce invoice,
// so "registered" is only half the property — it must also be gone afterwards.
Settings::update( array( 'email.enabled' => 1 ) );

$ow_mail = new OmniWP\OTP\Transports\MailTransport();
$ow_mail->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );

ow_assert(
	'the clamp is removed once the plugin\'s own mail is sent',
	! has_filter( 'phpmailer_init' ),
	'A ceiling left registered applies to every later wp_mail() on the request, including mail this plugin did not send.'
);

$ow_probe = new stdClass();
$ow_mail->clamp_timeout( $ow_probe );

ow_check(
	'the ceiling matches the one the HTTP send uses',
	WebhookTransport::MAX_TIMEOUT,
	$ow_probe->Timeout ?? 0
);

// =====================================================================
ow_section( 'Rule 13 — every issued code records the channel it belongs to (10.5)' );

Settings::update( array( 'delivery.route_phone' => 'sms' ) );

$ow_channel_repo = new ow_Fake_Otp_Repository();
ow_service_with( $ow_channel_repo, true )->issue( '84900000011', OtpService::INTENT_LOGIN );
ow_service_with( $ow_channel_repo, true )->issue( 'nguoi.dung@example.com', OtpService::INTENT_LOGIN );

$ow_recorded = array();

foreach ( $ow_channel_repo->rows as $ow_row ) {
	$ow_recorded[] = (string) ( $ow_row['identity_channel'] ?? '' );
}

sort( $ow_recorded );

// Left empty, the column made every per-channel count a guess from the
// destination string — which is the read-time compensation 10.5 exists to avoid.
ow_check( 'the channel is stored, not left blank', array( 'email', 'phone' ), $ow_recorded );

Settings::store_secret( 'automation.secret', '' );

ow_summary( 'Delivery routing' );
