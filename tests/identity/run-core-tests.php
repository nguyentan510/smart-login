<?php
/**
 * Behavioural tests for the pure identity core (Phase 1).
 *
 * No database, no WordPress runtime beyond the stubs. Everything here is
 * implemented, so this suite is `required` in run-all.php from the moment it
 * lands — it protects Phase 1 against regressions from Phase 2 onward.
 *
 * Run with:  php tests/identity/run-core-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Identity\Channels\FederatedChannel;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Identity\ChannelRegistry;
use SmartLogin\Identity\Claim;
use SmartLogin\Identity\IdentityRecord;
use SmartLogin\Identity\OpaqueLogin;
use SmartLogin\Identity\Resolution;
use SmartLogin\Identity\VerifiedClaim;
use SmartLogin\Settings;

function sl_ctor_is_private( string $fqn ): bool {
	$constructor = ( new ReflectionClass( $fqn ) )->getConstructor();

	return null !== $constructor && $constructor->isPrivate();
}

// ---------------------------------------------------------------------
sl_section( 'Claim — immutable, canonical by contract' );

sl_assert( 'Claim cannot be constructed directly', sl_ctor_is_private( Claim::class ) );

$claim = Claim::canonical( 'phone', ' 84969789475 ' );
sl_check( 'canonical() trims the subject', '84969789475', $claim->subject() );
sl_check( 'canonical() normalises the channel slug', 'phone', $claim->channel() );
sl_check( 'key() matches the UNIQUE index shape', 'phone:84969789475', $claim->key() );
sl_check( 'a populated claim is not empty', false, $claim->is_empty() );

sl_check( 'none() is empty', true, Claim::none()->is_empty() );
sl_check( 'a claim missing a subject is empty', true, Claim::canonical( 'phone', '' )->is_empty() );
sl_check( 'a claim missing a channel is empty', true, Claim::canonical( '', '84969789475' )->is_empty() );

sl_check( 'equal claims compare equal', true, $claim->equals( Claim::canonical( 'phone', '84969789475' ) ) );
sl_check( 'same subject in another channel is a different claim', false, $claim->equals( Claim::canonical( 'zalo', '84969789475' ) ) );

sl_assert(
	'Claim exposes no public property that could be rewritten',
	array() === ( new ReflectionClass( Claim::class ) )->getProperties( ReflectionProperty::IS_PUBLIC ),
	'A mutable subject could be swapped after verification.'
);

// ---------------------------------------------------------------------
sl_section( 'VerifiedClaim — proof is attached, not assumed' );

sl_assert( 'VerifiedClaim cannot be constructed directly', sl_ctor_is_private( VerifiedClaim::class ) );

$verified = VerifiedClaim::from( $claim, VerifiedClaim::PROOF_OTP, '2026-07-30 08:00:00' );
sl_check( 'it carries the channel through', 'phone', $verified->channel() );
sl_check( 'it carries the subject through', '84969789475', $verified->subject() );
sl_check( 'it records the proof method', 'otp', $verified->proof_method() );
sl_check( 'it records the verification time', '2026-07-30 08:00:00', $verified->verified_at() );
sl_check( 'the underlying claim is recoverable', 'phone:84969789475', $verified->claim()->key() );

sl_check(
	'an unrecognised proof method falls back to otp rather than being stored raw',
	'otp',
	VerifiedClaim::from( $claim, 'trust-me' )->proof_method()
);

sl_assert(
	'a defaulted timestamp is UTC in MySQL DATETIME format',
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', VerifiedClaim::from( $claim, 'otp' )->verified_at() )
);

// ---------------------------------------------------------------------
sl_section( 'Resolution — four states, no fifth' );

sl_check(
	'exactly four constants are declared',
	4,
	count( ( new ReflectionClass( Resolution::class ) )->getConstants() )
);

sl_check( 'unknown() reports its state', 'unknown', Resolution::unknown()->state() );
sl_check( 'known() reports its state', 'known', Resolution::known( 42 )->state() );
sl_check( 'retired() reports its state', 'retired', Resolution::retired( 42 )->state() );
sl_check( 'conflict() reports its state', 'conflict', Resolution::conflict()->state() );

sl_check( 'known() carries the owner', 42, Resolution::known( 42 )->user_id() );
sl_check( 'known() has an owner', true, Resolution::known( 42 )->has_owner() );

// The core of the takeover fix, asserted at the value-object level.
sl_check( 'retired() exposes NO owner', 0, Resolution::retired( 42 )->user_id() );
sl_check( 'retired() reports has_owner() false', false, Resolution::retired( 42 )->has_owner() );
sl_check( 'retired() keeps the prior owner for policy only', 42, Resolution::retired( 42 )->prior_user_id() );
sl_check( 'unknown() has no owner', false, Resolution::unknown()->has_owner() );
sl_check( 'conflict() has no owner', false, Resolution::conflict()->has_owner() );
sl_check( 'a negative user id cannot sneak through', 0, Resolution::known( -5 )->user_id() );

// ---------------------------------------------------------------------
sl_section( 'IdentityRecord — only proof creates a row' );

$record = IdentityRecord::create( 42, $verified, IdentityRecord::BY_REGISTRATION, true, array( 'hd' => 'example.com' ) );

sl_check( 'create() takes the subject from the proof', '84969789475', $record->subject() );
sl_check( 'create() takes the timestamp from the proof', '2026-07-30 08:00:00', $record->verified_at() );
sl_check( 'create() records provenance', 'registration', $record->linked_by() );
sl_check( 'create() records the primary flag', true, $record->is_primary() );

$row = $record->to_row();
sl_check( 'to_row() omits the id so insert cannot overwrite', false, array_key_exists( 'id', $row ) );

// created_at is NOT NULL with no default in the schema, so to_row() must supply
// it or every insert fails. Caught while wiring Phase 2 persistence.
sl_check( 'to_row() supplies created_at for the NOT NULL column', true, array_key_exists( 'created_at', $row ) );
sl_assert(
	'a defaulted created_at is UTC in MySQL DATETIME format',
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['created_at'] )
);
sl_check( 'from_row() preserves a stored created_at', '2026-01-02 03:04:05', IdentityRecord::from_row( array( 'created_at' => '2026-01-02 03:04:05' ) )->created_at() );
sl_check(
	'to_row() key order matches IdentityRepository::FORMATS',
	count( \SmartLogin\Identity\IdentityRepository::FORMATS ),
	count( $row )
);
sl_check( 'to_row() casts the primary flag for the column', 1, $row['is_primary'] );
sl_check( 'to_row() encodes meta as JSON', '{"hd":"example.com"}', $row['meta_json'] );
sl_check( 'to_row() writes NULL rather than an empty JSON object', null, IdentityRecord::create( 42, $verified, 'otp' )->to_row()['meta_json'] );

$hydrated = IdentityRecord::from_row(
	array(
		'id'          => 7,
		'user_id'     => 42,
		'channel'     => 'google',
		'subject'     => '11223344',
		'is_primary'  => 0,
		'verified_at' => '2026-07-30 08:00:00',
		'linked_by'   => 'oauth',
		'meta_json'   => '{"hd":"example.com"}',
	)
);

sl_check( 'from_row() restores the id', 7, $hydrated->id() );
sl_check( 'from_row() decodes meta', 'example.com', $hydrated->meta()['hd'] ?? '' );
sl_check( 'from_row() round-trips to a claim', 'google:11223344', $hydrated->claim()->key() );
sl_check( 'malformed meta_json degrades to an empty array', array(), IdentityRecord::from_row( array( 'meta_json' => 'not json' ) )->meta() );

// ---------------------------------------------------------------------
sl_section( 'PhoneChannel' );

Settings::update( array( 'id_mode' => 'both', 'default_country_code' => '84' ) );

$phone = new PhoneChannel();

sl_check( 'id is the stored slug', 'phone', $phone->id() );
sl_check( 'local format normalises to E.164 digits', '84969789475', $phone->normalize( '0969789475' ) );
sl_check( 'spaced international format normalises', '84969789475', $phone->normalize( '+84 969 789 475' ) );
sl_check( 'normalize is idempotent', '84969789475', $phone->normalize( $phone->normalize( '0969789475' ) ) );
sl_check( 'a mobile number is valid', true, $phone->is_valid( '84969789475' ) );
sl_check( 'a landline prefix is rejected', false, $phone->is_valid( '842839123456' ) );
sl_check( 'an empty subject is rejected', false, $phone->is_valid( '' ) );
sl_check( 'proof is by one-time code', 'otp', $phone->proof_method() );
sl_check( 'the subject is self-asserted', true, $phone->is_self_asserted() );
sl_check( 'a code can be delivered', true, $phone->can_receive_otp() );
sl_check( 'masking keeps the tail only', '096••••475', $phone->mask( '84969789475' ) );

// ---------------------------------------------------------------------
sl_section( 'MailChannel' );

$mail = new MailChannel();

sl_check( 'the stored slug stays "email"', 'email', $mail->id() );
sl_check( 'normalisation lowercases and trims', 'nhu@example.com', $mail->normalize( '  NHU@Example.COM ' ) );
sl_check( 'normalize is idempotent', 'nhu@example.com', $mail->normalize( $mail->normalize( 'NHU@Example.COM' ) ) );
sl_check( 'garbage normalises to nothing', '', $mail->normalize( 'not-an-email' ) );
sl_check( 'a real address is valid', true, $mail->is_valid( 'nhu@example.com' ) );

// The security-relevant case: a placeholder address is well-formed but
// unreachable, so it must never become a claimable identity.
sl_check( 'a synthetic @phone.invalid address is rejected', false, $mail->is_valid( '84969789475@phone.invalid' ) );
sl_check( 'rejection follows the configured placeholder domain', false, $mail->is_valid( '84969789475@PHONE.INVALID' ) );
sl_check( 'a code can be delivered', true, $mail->can_receive_otp() );
sl_check( 'masking preserves the domain', 'nh••@example.com', $mail->mask( 'nhu@example.com' ) );
sl_check( 'masking keeps the first two characters', 'ng••••••••@example.com', $mail->mask( 'nguyenvanA@example.com' ) );

// Deliberate property, not an accident: the mask has a floor of two dots, so a
// three-character local part yields two rather than one. The number of dots is
// therefore not a reliable read on the real length — which is the point.
sl_check( 'a very short local part still gets two dots', 'ab••@example.com', $mail->mask( 'ab@example.com' ) );

// ---------------------------------------------------------------------
sl_section( 'FederatedChannel — google and zalo cost zero classes' );

$google = new FederatedChannel( 'google', 'Google' );
$zalo   = new FederatedChannel( 'zalo' );

sl_check( 'the id is the provider slug', 'google', $google->id() );
sl_check( 'an omitted label is derived', 'Zalo', $zalo->label() );
sl_check( 'subjects are only whitespace-trimmed', '108234', $google->normalize( '  108234  ' ) );
sl_check( 'case is preserved — the subject is provider-owned', 'AbC_123', $google->normalize( 'AbC_123' ) );
sl_check( 'a normal subject is valid', true, $google->is_valid( '108234' ) );
sl_check( 'an empty subject is rejected', false, $google->is_valid( '' ) );
sl_check( 'a subject at the column limit is valid', true, $google->is_valid( str_repeat( 'a', 191 ) ) );
sl_check( 'a subject past the column limit is rejected', false, $google->is_valid( str_repeat( 'a', 192 ) ) );
sl_check( 'proof is by authorization code', 'oauth', $google->proof_method() );
sl_check( 'the subject is not self-asserted', false, $google->is_self_asserted() );
sl_check( 'no code can be delivered to a provider subject', false, $google->can_receive_otp() );

// ---------------------------------------------------------------------
sl_section( 'ChannelRegistry' );

$registry = new ChannelRegistry();

sl_check( 'the four built-in channels are registered', 4, count( $registry->all() ) );
sl_check( 'phone resolves', 'phone', $registry->get( 'phone' )->id() );
sl_check( 'email resolves', 'email', $registry->get( 'email' )->id() );
sl_check( 'google resolves', 'google', $registry->get( 'google' )->id() );
sl_check( 'an unknown channel resolves to null', null, $registry->get( 'myspace' ) );

sl_check( 'claim() normalises raw input', 'phone:84969789475', $registry->claim( 'phone', '0969789475' )->key() );
sl_check( 'claim() rejects an invalid subject', true, $registry->claim( 'phone', '12345' )->is_empty() );
sl_check( 'claim() rejects an unknown channel', true, $registry->claim( 'myspace', 'anything' )->is_empty() );
sl_check( 'claim() rejects a synthetic email', true, $registry->claim( 'email', '84969789475@phone.invalid' )->is_empty() );

sl_check( 'claim_any() routes a phone number', 'phone:84969789475', $registry->claim_any( '0969789475' )->key() );
sl_check( 'claim_any() routes an email address', 'email:nhu@example.com', $registry->claim_any( 'NHU@example.com' )->key() );
sl_check( 'claim_any() rejects nonsense', true, $registry->claim_any( '???' )->is_empty() );

Settings::update( array( 'id_mode' => 'phone_only', 'google_enabled' => 0, 'zalo_enabled' => 0 ) );
$enabled = ( new ChannelRegistry() )->enabled();
sl_check( 'legacy id_mode=phone_only enables one channel', array( 'phone' ), array_keys( $enabled ) );
sl_check( 'a disabled channel is not claimable through claim_any()', true, ( new ChannelRegistry() )->claim_any( 'nhu@example.com' )->is_empty() );

Settings::update( array( 'channels_enabled' => array( 'email', 'google' ) ) );
sl_check(
	'an explicit channels_enabled list overrides the legacy flags',
	array( 'email', 'google' ),
	array_keys( ( new ChannelRegistry() )->enabled() )
);

Settings::update( array( 'channels_enabled' => null, 'id_mode' => 'both' ) );

// ---------------------------------------------------------------------
sl_section( 'OpaqueLogin — the structural half of Invariant 1' );

$login = OpaqueLogin::generate();

sl_check( 'the login is prefixed', 'sl_', substr( $login, 0, 3 ) );
sl_check( 'the login is 27 characters', 27, strlen( $login ) );
sl_check( 'the login fits the wp_users column', true, strlen( $login ) <= 60 );
sl_check( 'generate() recognises its own output', true, OpaqueLogin::is_opaque( $login ) );
sl_check( 'two logins differ', false, OpaqueLogin::generate() === OpaqueLogin::generate() );

// If any of these ever pass, core could resolve a typed identifier to a user.
sl_check( 'a phone number is not a valid opaque login', false, OpaqueLogin::is_opaque( '84969789475' ) );
sl_check( 'an email address is not a valid opaque login', false, OpaqueLogin::is_opaque( 'nhu@example.com' ) );
sl_check( 'a prefixed phone number is not a valid opaque login', false, OpaqueLogin::is_opaque( 'sl_84969789475' ) );
sl_check( 'an uppercase hex login is rejected', false, OpaqueLogin::is_opaque( 'sl_ABCDEF0123456789ABCDEF01' ) );

// ---------------------------------------------------------------------
sl_summary( 'Identity core' );
