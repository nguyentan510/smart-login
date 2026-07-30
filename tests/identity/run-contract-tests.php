<?php
/**
 * Contract tests for the identity model.
 *
 * Every building block from docs/identity-model.md is asserted to exist with the
 * right shape, then the behaviour that depends on it is asserted. Behaviour
 * checks report PENDING while their building block is missing, so the output is
 * an ordered to-do list rather than a cascade of identical failures.
 *
 * Run with:  php tests/identity/run-contract-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

/**
 * True when a class or interface exists AND declares every listed method.
 *
 * @param string[] $methods
 */
function sl_shape_ok( string $fqn, array $methods = array() ): bool {
	if ( ! class_exists( $fqn ) && ! interface_exists( $fqn ) ) {
		return false;
	}

	foreach ( $methods as $method ) {
		if ( ! method_exists( $fqn, $method ) ) {
			return false;
		}
	}

	return true;
}

function sl_constructor_is_private( string $fqn ): bool {
	if ( ! class_exists( $fqn ) ) {
		return false;
	}

	$reflection  = new ReflectionClass( $fqn );
	$constructor = $reflection->getConstructor();

	return null !== $constructor && $constructor->isPrivate();
}

// ---------------------------------------------------------------------
sl_section( 'Value objects (Phase 1)' );

$blocks = array(
	'SmartLogin\Identity\Claim'                       => array( 'channel', 'subject' ),
	'SmartLogin\Identity\VerifiedClaim'               => array( 'channel', 'subject', 'verified_at' ),
	'SmartLogin\Identity\Resolution'                  => array( 'state', 'user_id' ),
	'SmartLogin\Identity\IdentityRecord'              => array( 'user_id', 'channel', 'subject' ),
	'SmartLogin\Identity\Channels\IdentityChannel'    => array(
		'id',
		'normalize',
		'is_valid',
		'proof_method',
		'is_self_asserted',
		'can_receive_otp',
		'label',
		'mask',
	),
	'SmartLogin\Identity\ChannelRegistry'             => array( 'register', 'get', 'enabled' ),
	'SmartLogin\Identity\OpaqueLogin'                 => array( 'generate' ),
);

foreach ( $blocks as $fqn => $methods ) {
	sl_assert(
		sprintf( '%s exists with its full shape', $fqn ),
		sl_shape_ok( $fqn, $methods ),
		'declares: ' . implode( ', ', $methods )
	);
}

// ---------------------------------------------------------------------
sl_section( 'Resolution states (Phase 3)' );

if ( class_exists( 'SmartLogin\Identity\Resolution' ) ) {
	$states = array( 'STATE_UNKNOWN', 'STATE_KNOWN', 'STATE_RETIRED', 'STATE_CONFLICT' );

	foreach ( $states as $state ) {
		sl_assert(
			sprintf( 'Resolution::%s is defined', $state ),
			defined( 'SmartLogin\Identity\Resolution::' . $state )
		);
	}

	sl_assert(
		'Resolution declares exactly four states',
		4 === count( ( new ReflectionClass( 'SmartLogin\Identity\Resolution' ) )->getConstants() ),
		'More than four means the state machine has grown an unspecified branch.'
	);
} else {
	sl_pending( 'the four resolution states', 'SmartLogin\Identity\Resolution' );
}

// ---------------------------------------------------------------------
sl_section( 'Decision table — intent x state (Phase 3)' );

/**
 * The table from identity-model.md §5, as data.
 *
 * Outcome vocabulary:
 *   create_user | create_new_user | already_registered | issue_session
 *   no_account  | issue_reset_grant | link_to_current | no_op | reject
 */
$decision_table = array(
	'register'      => array(
		'unknown'  => 'create_user',
		'known'    => 'already_registered',
		'retired'  => 'create_new_user',
		'conflict' => 'reject',
	),
	'login'         => array(
		'unknown'  => 'no_account',
		'known'    => 'issue_session',
		'retired'  => 'no_account',
		'conflict' => 'reject',
	),
	'recover'       => array(
		'unknown'  => 'no_account',
		'known'    => 'issue_reset_grant',
		'retired'  => 'no_account',
		'conflict' => 'reject',
	),
	'add_identity'  => array(
		'unknown'  => 'link_to_current',
		'known'    => 'no_op',
		'retired'  => 'link_to_current',
		'conflict' => 'reject',
	),
);

if ( sl_shape_ok( 'SmartLogin\Auth\AuthAction', array( 'decide' ) ) ) {
	foreach ( $decision_table as $intent => $row ) {
		foreach ( $row as $state => $expected ) {
			sl_check(
				sprintf( '%s x %s', $intent, strtoupper( $state ) ),
				$expected,
				\SmartLogin\Auth\AuthAction::decide( $intent, $state )
			);
		}
	}
} else {
	sl_assert( 'SmartLogin\Auth\AuthAction::decide() exists', false, 'The decision table has no implementation yet.' );

	$cells = 0;
	foreach ( $decision_table as $row ) {
		$cells += count( $row );
	}

	sl_pending( sprintf( '%d decision-table cells', $cells ), 'SmartLogin\Auth\AuthAction' );
}

// ---------------------------------------------------------------------
sl_section( 'The takeover defect is unrepresentable (Phase 3)' );

if ( sl_shape_ok( 'SmartLogin\Auth\AuthAction', array( 'decide' ) ) ) {
	sl_check(
		'recover on a RETIRED subject cannot reach the previous owner',
		'no_account',
		\SmartLogin\Auth\AuthAction::decide( 'recover', 'retired' )
	);
	sl_check(
		'login on a RETIRED subject cannot reach the previous owner',
		'no_account',
		\SmartLogin\Auth\AuthAction::decide( 'login', 'retired' )
	);
} else {
	sl_pending( 'recover/login on RETIRED yields no_account', 'SmartLogin\Auth\AuthAction' );
}

// ---------------------------------------------------------------------
sl_section( 'Proof is unforgeable (Phase 3)' );

sl_assert(
	'AuthProof has a private constructor',
	sl_constructor_is_private( 'SmartLogin\Auth\AuthProof' ),
	'Only the PROVE layer may mint proof, via fromOtp/fromOAuth/fromPassword.'
);

foreach ( array( 'from_otp', 'from_oauth', 'from_password' ) as $factory ) {
	sl_assert(
		sprintf( 'AuthProof::%s() exists', $factory ),
		sl_shape_ok( 'SmartLogin\Auth\AuthProof', array( $factory ) )
	);
}

// ---------------------------------------------------------------------
sl_section( 'A new channel costs one class (Phase 1)' );

if ( interface_exists( 'SmartLogin\Identity\Channels\IdentityChannel' )
	&& sl_shape_ok( 'SmartLogin\Identity\ChannelRegistry', array( 'register', 'get' ) ) ) {

	// A fictional channel must be usable without touching anything else.
	$telegram = new class implements \SmartLogin\Identity\Channels\IdentityChannel {
		public function id(): string {
			return 'telegram';
		}
		public function normalize( string $raw ): string {
			return ltrim( trim( $raw ), '@' );
		}
		public function is_valid( string $subject ): bool {
			return (bool) preg_match( '/^[A-Za-z0-9_]{5,32}$/', $subject );
		}
		public function proof_method(): string {
			return 'oauth';
		}
		public function is_self_asserted(): bool {
			return false;
		}
		public function can_receive_otp(): bool {
			return false;
		}
		public function label(): string {
			return 'Telegram';
		}
		public function mask( string $subject ): string {
			return '@' . substr( $subject, 0, 2 ) . '***';
		}
	};

	$registry = new \SmartLogin\Identity\ChannelRegistry();
	$registry->register( $telegram );

	sl_check( 'a third-party channel registers and resolves', 'telegram', $registry->get( 'telegram' )->id() );
	sl_check( 'its normaliser runs', 'duckling', $registry->get( 'telegram' )->normalize( ' @duckling ' ) );
} else {
	sl_pending( 'registering a fictional channel requires no other edits', 'IdentityChannel + ChannelRegistry' );
}

// ---------------------------------------------------------------------
sl_section( 'Directory is the only resolver (Phase 3)' );

sl_assert(
	'IdentityDirectory::resolve() exists',
	sl_shape_ok( 'SmartLogin\Identity\IdentityDirectory', array( 'resolve' ) )
);

sl_assert(
	'IdentityResolver has been deleted',
	! class_exists( 'SmartLogin\Identity\IdentityResolver' ),
	'The old resolver carries the get_user_by( \'login\' ) fallback that makes the takeover possible.'
);

// ---------------------------------------------------------------------
sl_section( 'Profile boundary (Phase 5)' );

sl_assert(
	'ProfileSeeder::seed_if_empty() exists',
	sl_shape_ok( 'SmartLogin\Identity\ProfileSeeder', array( 'seed_if_empty' ) ),
	'The single permitted writer of billing_* fields.'
);

// ---------------------------------------------------------------------
sl_summary( 'Identity contract' );
