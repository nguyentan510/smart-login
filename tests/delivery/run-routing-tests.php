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
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\Settings;

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

// The rule above cannot see the defect it exists to prevent, because the offender
// is on its allowlist. This one can: routing must consult a setting, not only a
// string test.
$sl_router_body = sl_method_body(
	sl_source( 'includes/OTP/Transports/class-transport-router.php' ),
	'transport_for'
);

sl_assert(
	'transport_for() reads the routing table',
	'' !== $sl_router_body && false !== strpos( $sl_router_body, 'delivery.route_' ),
	'The transport is decided entirely by the shape of the destination, so no site can point a channel anywhere else. See docs/delivery-routing.md D1.'
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

sl_summary( 'Delivery routing' );
