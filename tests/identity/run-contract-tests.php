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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

/**
 * True when a class or interface exists AND declares every listed method.
 *
 * @param string[] $methods
 */
function ow_shape_ok( string $fqn, array $methods = array() ): bool {
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

function ow_constructor_is_private( string $fqn ): bool {
	if ( ! class_exists( $fqn ) ) {
		return false;
	}

	$reflection  = new ReflectionClass( $fqn );
	$constructor = $reflection->getConstructor();

	return null !== $constructor && $constructor->isPrivate();
}

// ---------------------------------------------------------------------
ow_section( 'Value objects (Phase 1)' );

$blocks = array(
	'OmniWP\Identity\Claim'                       => array( 'channel', 'subject' ),
	'OmniWP\Identity\VerifiedClaim'               => array( 'channel', 'subject', 'verified_at' ),
	'OmniWP\Identity\Resolution'                  => array( 'state', 'user_id' ),
	'OmniWP\Identity\IdentityRecord'              => array( 'user_id', 'channel', 'subject' ),
	'OmniWP\Identity\Channels\IdentityChannel'    => array(
		'id',
		'normalize',
		'is_valid',
		'proof_method',
		'is_self_asserted',
		'can_receive_otp',
		'label',
		'mask',
	),
	'OmniWP\Identity\ChannelRegistry'             => array( 'register', 'get', 'enabled' ),
	'OmniWP\Identity\OpaqueLogin'                 => array( 'generate' ),
);

foreach ( $blocks as $fqn => $methods ) {
	ow_assert(
		sprintf( '%s exists with its full shape', $fqn ),
		ow_shape_ok( $fqn, $methods ),
		'declares: ' . implode( ', ', $methods )
	);
}

// ---------------------------------------------------------------------
ow_section( 'Resolution states (Phase 3)' );

if ( class_exists( 'OmniWP\Identity\Resolution' ) ) {
	$states = array( 'STATE_UNKNOWN', 'STATE_KNOWN', 'STATE_RETIRED', 'STATE_CONFLICT' );

	foreach ( $states as $state ) {
		ow_assert(
			sprintf( 'Resolution::%s is defined', $state ),
			defined( 'OmniWP\Identity\Resolution::' . $state )
		);
	}

	ow_assert(
		'Resolution declares exactly four states',
		4 === count( ( new ReflectionClass( 'OmniWP\Identity\Resolution' ) )->getConstants() ),
		'More than four means the state machine has grown an unspecified branch.'
	);
} else {
	ow_pending( 'the four resolution states', 'OmniWP\Identity\Resolution' );
}

// ---------------------------------------------------------------------
ow_section( 'Decision table — intent x state (Phase 3)' );

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

if ( ow_shape_ok( 'OmniWP\Auth\AuthAction', array( 'decide' ) ) ) {
	foreach ( $decision_table as $intent => $row ) {
		foreach ( $row as $state => $expected ) {
			ow_check(
				sprintf( '%s x %s', $intent, strtoupper( $state ) ),
				$expected,
				\OmniWP\Auth\AuthAction::decide( $intent, $state )
			);
		}
	}
} else {
	ow_assert( 'OmniWP\Auth\AuthAction::decide() exists', false, 'The decision table has no implementation yet.' );

	$cells = 0;
	foreach ( $decision_table as $row ) {
		$cells += count( $row );
	}

	ow_pending( sprintf( '%d decision-table cells', $cells ), 'OmniWP\Auth\AuthAction' );
}

// ---------------------------------------------------------------------
ow_section( 'The takeover defect is unrepresentable (Phase 3)' );

if ( ow_shape_ok( 'OmniWP\Auth\AuthAction', array( 'decide' ) ) ) {
	ow_check(
		'recover on a RETIRED subject cannot reach the previous owner',
		'no_account',
		\OmniWP\Auth\AuthAction::decide( 'recover', 'retired' )
	);
	ow_check(
		'login on a RETIRED subject cannot reach the previous owner',
		'no_account',
		\OmniWP\Auth\AuthAction::decide( 'login', 'retired' )
	);
} else {
	ow_pending( 'recover/login on RETIRED yields no_account', 'OmniWP\Auth\AuthAction' );
}

// ---------------------------------------------------------------------
ow_section( 'Proof is unforgeable (Phase 3)' );

ow_assert(
	'AuthProof has a private constructor',
	ow_constructor_is_private( 'OmniWP\Auth\AuthProof' ),
	'Only the PROVE layer may mint proof, via fromOtp/fromOAuth/fromPassword.'
);

foreach ( array( 'from_otp', 'from_oauth', 'from_password' ) as $factory ) {
	ow_assert(
		sprintf( 'AuthProof::%s() exists', $factory ),
		ow_shape_ok( 'OmniWP\Auth\AuthProof', array( $factory ) )
	);
}

// ---------------------------------------------------------------------
ow_section( 'A new channel costs one class (Phase 1)' );

if ( interface_exists( 'OmniWP\Identity\Channels\IdentityChannel' )
	&& ow_shape_ok( 'OmniWP\Identity\ChannelRegistry', array( 'register', 'get' ) ) ) {

	// A fictional channel must be usable without touching anything else.
	$telegram = new class implements \OmniWP\Identity\Channels\IdentityChannel {
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

	$registry = new \OmniWP\Identity\ChannelRegistry();
	$registry->register( $telegram );

	ow_check( 'a third-party channel registers and resolves', 'telegram', $registry->get( 'telegram' )->id() );
	ow_check( 'its normaliser runs', 'duckling', $registry->get( 'telegram' )->normalize( ' @duckling ' ) );
} else {
	ow_pending( 'registering a fictional channel requires no other edits', 'IdentityChannel + ChannelRegistry' );
}

// ---------------------------------------------------------------------
ow_section( 'Directory is the only resolver (Phase 3)' );

ow_assert(
	'IdentityDirectory::resolve() exists',
	ow_shape_ok( 'OmniWP\Identity\IdentityDirectory', array( 'resolve' ) )
);

ow_assert(
	'IdentityResolver has been deleted',
	! class_exists( 'OmniWP\Identity\IdentityResolver' ),
	'The old resolver carries the get_user_by( \'login\' ) fallback that makes the takeover possible.'
);

// ---------------------------------------------------------------------
ow_section( 'Profile boundary (Phase 5)' );

ow_assert(
	'ProfileSeeder::seed_if_empty() exists',
	ow_shape_ok( 'OmniWP\Identity\ProfileSeeder', array( 'seed_if_empty' ) ),
	'The single permitted writer of billing_* fields.'
);

// ---------------------------------------------------------------------
ow_section( 'The email identity (Phase 14)' );

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
\OmniWP\Settings::update( array( 'channels.enabled' => array( 'email', 'google' ) ) );

$GLOBALS['ow_users_by_email'] = array( 'taken@example.test' => 7 );
$GLOBALS['ow_wpdb_row']       = null;
$GLOBALS['ow_wpdb_var']       = 0;

/**
 * OTP rows written since a mark. Counted by table rather than in total: the
 * refusal legitimately writes one audit row, and a rule that forbids *any* write
 * would be measuring the wrong thing — the harm is a code being spent, not a
 * record being kept.
 */
function ow_otp_writes_since( int $mark ): int {
	$otp   = \OmniWP\Installer::otp_table();
	$found = 0;

	foreach ( array_slice( $GLOBALS['wpdb']->writes, $mark ) as $write ) {
		if ( 'insert' === $write['op'] && $otp === ( $write['table'] ?? '' ) ) {
			++$found;
		}
	}

	return $found;
}

$ow_before  = count( $GLOBALS['wpdb']->writes );
$ow_refusal = null;

try {
	$ow_refusal = ( new \OmniWP\Auth\RegisterHandler() )->start_identity(
		array( 'identity' => 'taken@example.test' )
	);
} catch ( \Throwable $e ) {
	$ow_refusal = new WP_Error( 'OMNIWP_threw', get_class( $e ) . ': ' . $e->getMessage() );
}

// The code is asserted, not merely that something failed: this call can refuse
// for at least three unrelated reasons, and two of them would report success at
// guarding an address they never looked at.
ow_check(
	'a registration OTP is refused for an address wp_users already holds',
	'OMNIWP_identity_taken',
	is_wp_error( $ow_refusal ) ? $ow_refusal->get_error_code() : 'no refusal at all'
);

ow_check(
	'and no code is spent while refusing',
	0,
	ow_otp_writes_since( $ow_before )
);

// The other half of the same rule, and the one that matters more: this guard sits
// on the happy path of every registration on the site, so a wrong predicate here
// closes signup for everybody. Asserted by its absence of refusal, not by reading
// the branch.
$GLOBALS['ow_users_by_email'] = array();
$ow_unused                    = null;

try {
	$ow_unused = ( new \OmniWP\Auth\RegisterHandler() )->start_identity(
		array( 'identity' => 'brand.new@example.test' )
	);
} catch ( \Throwable $e ) {
	$ow_unused = new WP_Error( 'OMNIWP_threw', get_class( $e ) );
}

ow_assert(
	'an address nobody holds still starts a registration',
	! is_wp_error( $ow_unused )
		|| 'OMNIWP_identity_taken' !== $ow_unused->get_error_code(),
	'The guard must refuse only what create_verified_user() would refuse anyway.'
);

// Rule 2 (14.2) — one writer for a verified email.
if ( method_exists( 'OmniWP\Identity\UserManager', 'adopt_verified_email' ) ) {
	$ow_offenders = array();

	foreach ( ow_plugin_sources() as $ow_relative => $ow_code ) {
		if ( 'includes/Identity/class-user-manager.php' === $ow_relative ) {
			continue;
		}

		if ( preg_match( '/\bwp_update_user\s*\(/', $ow_code )
			&& false !== strpos( $ow_code, 'META_EMAIL_VERIFIED' ) ) {
			$ow_offenders[] = $ow_relative;
		}
	}

	ow_check(
		'no file outside UserManager pairs a user_email write with META_EMAIL_VERIFIED',
		'',
		implode( ', ', $ow_offenders )
	);
	/*
	 * The order inside the writer, which its docblock calls load-bearing. The
	 * directory write can lose a race; user_email must not have moved when it does,
	 * or the account is left with an address disagreeing with its identity — the
	 * state this phase exists to remove. Asserted by forcing the claim to fail,
	 * because "documentation is not evidence" and this project has twice found a
	 * docblock describing a control that was not there.
	 */
	$GLOBALS['ow_user_updates']        = array();
	$GLOBALS['ow_wpdb_results']        = array();
	$GLOBALS['ow_wpdb_insert_result']  = false;

	$ow_adopted = \OmniWP\Identity\UserManager::adopt_verified_email(
		42,
		\OmniWP\Identity\VerifiedClaim::from(
			( new \OmniWP\Identity\ChannelRegistry() )->claim( 'email', 'race@example.test' ),
			\OmniWP\Identity\VerifiedClaim::PROOF_OTP
		)
	);

	ow_assert(
		'a lost race on the identity row is reported, not swallowed',
		is_wp_error( $ow_adopted )
	);

	ow_check(
		'and user_email has not moved when it is',
		0,
		count( $GLOBALS['ow_user_updates'] )
	);

	$GLOBALS['ow_wpdb_insert_result'] = 1;

	// A non-email claim must not reach this writer at all: the channel is the
	// subject of the method, not a parameter it tolerates.
	$ow_wrong_channel = \OmniWP\Identity\UserManager::adopt_verified_email(
		42,
		\OmniWP\Identity\VerifiedClaim::from(
			( new \OmniWP\Identity\ChannelRegistry() )->claim( 'phone', '0961234567' ),
			\OmniWP\Identity\VerifiedClaim::PROOF_OTP
		)
	);

	ow_check(
		'a phone claim is refused by the email writer',
		'OMNIWP_not_an_email',
		is_wp_error( $ow_wrong_channel ) ? $ow_wrong_channel->get_error_code() : 'accepted'
	);
} else {
	ow_pending( 'one writer owns a verified email', 'UserManager::adopt_verified_email() (14.2)' );
}

// Rule 4 (14.4) — the two per-provider defaults, pinned because they are the
// security-relevant half of the decision and a default is the value almost every
// site will run. Google on: it asserts email_verified, and that assertion already
// decides the account's user_email. A provider that does not assert it is absent
// from AccountProvisioner::EMAIL_IDENTITY_FLAG and gets no row at all.
$ow_fields = \OmniWP\FieldRegistry::all();

ow_check(
	'Google verified email is an identity by default',
	1,
	(int) ( $ow_fields['providers.google.email_identity']['default'] ?? -1 )
);

ow_check(
	'a provider that cannot assert verification declares no flag',
	array( 'google' ),
	array_keys( \OmniWP\Auth\AccountProvisioner::EMAIL_IDENTITY_FLAG )
);

// Rule 3 — proof is required to reach the directory. Passes today, and must keep
// passing: it is what stops 14.2 becoming a way to mint identities. A rule that
// arrives beside the feature it guards cannot catch that feature breaking it.
$ow_link = new \ReflectionMethod( 'OmniWP\Identity\IdentityDirectory', 'link' );
$ow_swap = new \ReflectionMethod( 'OmniWP\Identity\IdentityDirectory', 'replace_in_channel' );

ow_check(
	'IdentityDirectory::link() only accepts a VerifiedClaim',
	'OmniWP\Identity\VerifiedClaim',
	(string) ( $ow_link->getParameters()[1]->getType() ?? '' )
);

ow_check(
	'IdentityDirectory::replace_in_channel() only accepts a VerifiedClaim',
	'OmniWP\Identity\VerifiedClaim',
	(string) ( $ow_swap->getParameters()[1]->getType() ?? '' )
);

// ---------------------------------------------------------------------
ow_summary( 'Identity contract' );
