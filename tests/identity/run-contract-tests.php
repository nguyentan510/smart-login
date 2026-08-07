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
sl_section( 'The email identity (Phase 14)' );

/*
 * One fact — this account owns this address — is stored in wp_users and in the
 * identities table, and a provider login writes only the first. These rules pin
 * the consequences that are assertable without MySQL. The two doors that need a
 * store which actually stores are in tests/integration/run-provider-gates.php:
 * this $wpdb stub does not parse SQL, on purpose, and reversing that decision to
 * suit one phase would be the wrong trade.
 */

// Rule 1 (14.1) — an address wp_users already holds must not buy an OTP.
//
// The email channel has to be enabled explicitly. Without it claim_any() cannot
// build an email claim at all, and the refusal that comes back is
// "Số điện thoại không hợp lệ" — a pass for the wrong reason, which is the
// mistake 10.0 and 11.0 both recorded.
\SmartLogin\Settings::update( array( 'channels.enabled' => array( 'email', 'google' ) ) );

$GLOBALS['sl_users_by_email'] = array( 'taken@example.test' => 7 );
$GLOBALS['sl_wpdb_row']       = null;
$GLOBALS['sl_wpdb_var']       = 0;

/**
 * OTP rows written since a mark. Counted by table rather than in total: the
 * refusal legitimately writes one audit row, and a rule that forbids *any* write
 * would be measuring the wrong thing — the harm is a code being spent, not a
 * record being kept.
 */
function sl_otp_writes_since( int $mark ): int {
	$otp   = \SmartLogin\Installer::otp_table();
	$found = 0;

	foreach ( array_slice( $GLOBALS['wpdb']->writes, $mark ) as $write ) {
		if ( 'insert' === $write['op'] && $otp === ( $write['table'] ?? '' ) ) {
			++$found;
		}
	}

	return $found;
}

$sl_before  = count( $GLOBALS['wpdb']->writes );
$sl_refusal = null;

try {
	$sl_refusal = ( new \SmartLogin\Auth\RegisterHandler() )->start_identity(
		array( 'identity' => 'taken@example.test' )
	);
} catch ( \Throwable $e ) {
	$sl_refusal = new WP_Error( 'smart_login_threw', get_class( $e ) . ': ' . $e->getMessage() );
}

// The code is asserted, not merely that something failed: this call can refuse
// for at least three unrelated reasons, and two of them would report success at
// guarding an address they never looked at.
sl_check(
	'a registration OTP is refused for an address wp_users already holds',
	'smart_login_identity_taken',
	is_wp_error( $sl_refusal ) ? $sl_refusal->get_error_code() : 'no refusal at all'
);

sl_check(
	'and no code is spent while refusing',
	0,
	sl_otp_writes_since( $sl_before )
);

// The other half of the same rule, and the one that matters more: this guard sits
// on the happy path of every registration on the site, so a wrong predicate here
// closes signup for everybody. Asserted by its absence of refusal, not by reading
// the branch.
$GLOBALS['sl_users_by_email'] = array();
$sl_unused                    = null;

try {
	$sl_unused = ( new \SmartLogin\Auth\RegisterHandler() )->start_identity(
		array( 'identity' => 'brand.new@example.test' )
	);
} catch ( \Throwable $e ) {
	$sl_unused = new WP_Error( 'smart_login_threw', get_class( $e ) );
}

sl_assert(
	'an address nobody holds still starts a registration',
	! is_wp_error( $sl_unused )
		|| 'smart_login_identity_taken' !== $sl_unused->get_error_code(),
	'The guard must refuse only what create_verified_user() would refuse anyway.'
);

// Rule 2 (14.2) — one writer for a verified email.
if ( method_exists( 'SmartLogin\Identity\UserManager', 'adopt_verified_email' ) ) {
	$sl_offenders = array();

	foreach ( sl_plugin_sources() as $sl_relative => $sl_code ) {
		if ( 'includes/Identity/class-user-manager.php' === $sl_relative ) {
			continue;
		}

		if ( preg_match( '/\bwp_update_user\s*\(/', $sl_code )
			&& false !== strpos( $sl_code, 'META_EMAIL_VERIFIED' ) ) {
			$sl_offenders[] = $sl_relative;
		}
	}

	sl_check(
		'no file outside UserManager pairs a user_email write with META_EMAIL_VERIFIED',
		'',
		implode( ', ', $sl_offenders )
	);
	/*
	 * The order inside the writer, which its docblock calls load-bearing. The
	 * directory write can lose a race; user_email must not have moved when it does,
	 * or the account is left with an address disagreeing with its identity — the
	 * state this phase exists to remove. Asserted by forcing the claim to fail,
	 * because "documentation is not evidence" and this project has twice found a
	 * docblock describing a control that was not there.
	 */
	$GLOBALS['sl_user_updates']        = array();
	$GLOBALS['sl_wpdb_results']        = array();
	$GLOBALS['sl_wpdb_insert_result']  = false;

	$sl_adopted = \SmartLogin\Identity\UserManager::adopt_verified_email(
		42,
		\SmartLogin\Identity\VerifiedClaim::from(
			( new \SmartLogin\Identity\ChannelRegistry() )->claim( 'email', 'race@example.test' ),
			\SmartLogin\Identity\VerifiedClaim::PROOF_OTP
		)
	);

	sl_assert(
		'a lost race on the identity row is reported, not swallowed',
		is_wp_error( $sl_adopted )
	);

	sl_check(
		'and user_email has not moved when it is',
		0,
		count( $GLOBALS['sl_user_updates'] )
	);

	$GLOBALS['sl_wpdb_insert_result'] = 1;

	// A non-email claim must not reach this writer at all: the channel is the
	// subject of the method, not a parameter it tolerates.
	$sl_wrong_channel = \SmartLogin\Identity\UserManager::adopt_verified_email(
		42,
		\SmartLogin\Identity\VerifiedClaim::from(
			( new \SmartLogin\Identity\ChannelRegistry() )->claim( 'phone', '0961234567' ),
			\SmartLogin\Identity\VerifiedClaim::PROOF_OTP
		)
	);

	sl_check(
		'a phone claim is refused by the email writer',
		'smart_login_not_an_email',
		is_wp_error( $sl_wrong_channel ) ? $sl_wrong_channel->get_error_code() : 'accepted'
	);
} else {
	sl_pending( 'one writer owns a verified email', 'UserManager::adopt_verified_email() (14.2)' );
}

// Rule 4 (14.4) — the two per-provider defaults, pinned because they are the
// security-relevant half of the decision and a default is the value almost every
// site will run. Google on: it asserts email_verified, and that assertion already
// decides the account's user_email. A provider that does not assert it is absent
// from AccountProvisioner::EMAIL_IDENTITY_FLAG and gets no row at all.
$sl_fields = \SmartLogin\FieldRegistry::all();

sl_check(
	'Google verified email is an identity by default',
	1,
	(int) ( $sl_fields['providers.google.email_identity']['default'] ?? -1 )
);

sl_check(
	'a provider that cannot assert verification declares no flag',
	array( 'google' ),
	array_keys( \SmartLogin\Auth\AccountProvisioner::EMAIL_IDENTITY_FLAG )
);

// Rule 3 — proof is required to reach the directory. Passes today, and must keep
// passing: it is what stops 14.2 becoming a way to mint identities. A rule that
// arrives beside the feature it guards cannot catch that feature breaking it.
$sl_link = new \ReflectionMethod( 'SmartLogin\Identity\IdentityDirectory', 'link' );
$sl_swap = new \ReflectionMethod( 'SmartLogin\Identity\IdentityDirectory', 'replace_in_channel' );

sl_check(
	'IdentityDirectory::link() only accepts a VerifiedClaim',
	'SmartLogin\Identity\VerifiedClaim',
	(string) ( $sl_link->getParameters()[1]->getType() ?? '' )
);

sl_check(
	'IdentityDirectory::replace_in_channel() only accepts a VerifiedClaim',
	'SmartLogin\Identity\VerifiedClaim',
	(string) ( $sl_swap->getParameters()[1]->getType() ?? '' )
);

// ---------------------------------------------------------------------
sl_summary( 'Identity contract' );
