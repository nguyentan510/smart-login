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
use SmartLogin\OTP\Transports\TransportInterface;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\Security\RateLimiter;
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

/** Build a service whose transport succeeds or fails on demand. */
function sl_service_with( SL_Fake_Otp_Repository $repo, bool $succeeds ): OtpService {
	return new OtpService(
		$repo,
		new TransportRouter( array( 'sms' => new SL_Fake_Transport( $succeeds ) ) ),
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

$sl_signer = sl_source( 'includes/OTP/Transports/class-envelope-signer.php' );

if ( '' === $sl_signer ) {
	sl_pending(
		'no automation code calls wp_remote_* outside the signer',
		'EnvelopeSigner — 10.3'
	);
} else {
	sl_forbid_pattern(
		'no automation code calls wp_remote_* outside the signer',
		'/wp_remote_(?:request|post|get)\(/',
		array(
			// The one place a signed envelope is put on the wire.
			'includes/OTP/Transports/class-envelope-signer.php',
			// Pre-existing transports, out of the automation namespace.
			'includes/OTP/Transports/class-webhook-transport.php',
			'includes/Security/class-captcha.php',
			'includes/Auth/Providers/class-google-provider.php',
			'includes/Auth/Providers/class-zalo-provider.php',
			'includes/Auth/Providers/class-google-id-token-verifier.php',
		),
		'An unsigned request carrying an OTP is the failure mode HMAC exists to prevent.'
	);
}

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

if ( '' === sl_source( 'includes/OTP/Transports/class-event-bus.php' ) ) {
	sl_pending(
		'a bus failure leaves issue() returning a result and the OTP row intact',
		'EventBus — 10.4'
	);
	sl_pending(
		'the bus breaker and the transport breaker are separate keys',
		'EventBus — 10.4'
	);
} else {
	sl_note( 'EventBus exists — replace these pendings with the live assertions from the 10.4 brief.' );
}

// =====================================================================
sl_section( 'Rule 7 — the automation endpoint refuses plaintext HTTP (10.3)' );

if ( null === FieldRegistry::get( 'automation.url' ) ) {
	sl_pending(
		'saving an http:// endpoint stores nothing and keeps the previous value',
		'automation.url — 10.3'
	);
} else {
	Settings::update( array( 'automation.url' => 'https://hooks.example.com/otp' ) );

	$sl_saved = Settings::sanitize(
		array(
			Settings::TAB_FIELD => (string) ( FieldRegistry::get( 'automation.url' )['tab'] ?? '' ),
			'automation'        => array( 'url' => 'http://hooks.example.com/otp' ),
		)
	);

	sl_check(
		'saving an http:// endpoint keeps the previous value',
		'https://hooks.example.com/otp',
		$sl_saved['automation']['url'] ?? ''
	);
}

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

sl_summary( 'Delivery routing' );
