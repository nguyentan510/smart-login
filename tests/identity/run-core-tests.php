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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Identity\Channels\FederatedChannel;
use OmniWP\Identity\Channels\MailChannel;
use OmniWP\Identity\Channels\PhoneChannel;
use OmniWP\Identity\ChannelRegistry;
use OmniWP\Identity\Claim;
use OmniWP\Identity\IdentityRecord;
use OmniWP\Identity\OpaqueLogin;
use OmniWP\Identity\ProfileSeeder;
use OmniWP\Identity\Resolution;
use OmniWP\Identity\VerifiedClaim;
use OmniWP\OTP\OtpService;
use OmniWP\Settings;

function ow_ctor_is_private( string $fqn ): bool {
	$constructor = ( new ReflectionClass( $fqn ) )->getConstructor();

	return null !== $constructor && $constructor->isPrivate();
}

// ---------------------------------------------------------------------
ow_section( 'Claim — immutable, canonical by contract' );

ow_assert( 'Claim cannot be constructed directly', ow_ctor_is_private( Claim::class ) );

$claim = Claim::canonical( 'phone', ' 84969789475 ' );
ow_check( 'canonical() trims the subject', '84969789475', $claim->subject() );
ow_check( 'canonical() normalises the channel slug', 'phone', $claim->channel() );
ow_check( 'key() matches the UNIQUE index shape', 'phone:84969789475', $claim->key() );
ow_check( 'a populated claim is not empty', false, $claim->is_empty() );

ow_check( 'none() is empty', true, Claim::none()->is_empty() );
ow_check( 'a claim missing a subject is empty', true, Claim::canonical( 'phone', '' )->is_empty() );
ow_check( 'a claim missing a channel is empty', true, Claim::canonical( '', '84969789475' )->is_empty() );

ow_check( 'equal claims compare equal', true, $claim->equals( Claim::canonical( 'phone', '84969789475' ) ) );
ow_check( 'same subject in another channel is a different claim', false, $claim->equals( Claim::canonical( 'google', '84969789475' ) ) );

ow_assert(
	'Claim exposes no public property that could be rewritten',
	array() === ( new ReflectionClass( Claim::class ) )->getProperties( ReflectionProperty::IS_PUBLIC ),
	'A mutable subject could be swapped after verification.'
);

// ---------------------------------------------------------------------
ow_section( 'VerifiedClaim — proof is attached, not assumed' );

ow_assert( 'VerifiedClaim cannot be constructed directly', ow_ctor_is_private( VerifiedClaim::class ) );

$verified = VerifiedClaim::from( $claim, VerifiedClaim::PROOF_OTP, '2026-07-30 08:00:00' );
ow_check( 'it carries the channel through', 'phone', $verified->channel() );
ow_check( 'it carries the subject through', '84969789475', $verified->subject() );
ow_check( 'it records the proof method', 'otp', $verified->proof_method() );
ow_check( 'it records the verification time', '2026-07-30 08:00:00', $verified->verified_at() );
ow_check( 'the underlying claim is recoverable', 'phone:84969789475', $verified->claim()->key() );

ow_check(
	'an unrecognised proof method falls back to otp rather than being stored raw',
	'otp',
	VerifiedClaim::from( $claim, 'trust-me' )->proof_method()
);

ow_assert(
	'a defaulted timestamp is UTC in MySQL DATETIME format',
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', VerifiedClaim::from( $claim, 'otp' )->verified_at() )
);

// ---------------------------------------------------------------------
ow_section( 'Resolution — four states, no fifth' );

ow_check(
	'exactly four constants are declared',
	4,
	count( ( new ReflectionClass( Resolution::class ) )->getConstants() )
);

ow_check( 'unknown() reports its state', 'unknown', Resolution::unknown()->state() );
ow_check( 'known() reports its state', 'known', Resolution::known( 42 )->state() );
ow_check( 'retired() reports its state', 'retired', Resolution::retired( 42 )->state() );
ow_check( 'conflict() reports its state', 'conflict', Resolution::conflict()->state() );

ow_check( 'known() carries the owner', 42, Resolution::known( 42 )->user_id() );
ow_check( 'known() has an owner', true, Resolution::known( 42 )->has_owner() );

// The core of the takeover fix, asserted at the value-object level.
ow_check( 'retired() exposes NO owner', 0, Resolution::retired( 42 )->user_id() );
ow_check( 'retired() reports has_owner() false', false, Resolution::retired( 42 )->has_owner() );
ow_check( 'retired() keeps the prior owner for policy only', 42, Resolution::retired( 42 )->prior_user_id() );
ow_check( 'unknown() has no owner', false, Resolution::unknown()->has_owner() );
ow_check( 'conflict() has no owner', false, Resolution::conflict()->has_owner() );
ow_check( 'a negative user id cannot sneak through', 0, Resolution::known( -5 )->user_id() );

// ---------------------------------------------------------------------
ow_section( 'IdentityRecord — only proof creates a row' );

$record = IdentityRecord::create( 42, $verified, IdentityRecord::BY_REGISTRATION, true, array( 'hd' => 'example.com' ) );

ow_check( 'create() takes the subject from the proof', '84969789475', $record->subject() );
ow_check( 'create() takes the timestamp from the proof', '2026-07-30 08:00:00', $record->verified_at() );
ow_check( 'create() records provenance', 'registration', $record->linked_by() );
ow_check( 'create() records the primary flag', true, $record->is_primary() );

$row = $record->to_row();
ow_check( 'to_row() omits the id so insert cannot overwrite', false, array_key_exists( 'id', $row ) );

// created_at is NOT NULL with no default in the schema, so to_row() must supply
// it or every insert fails. Caught while wiring Phase 2 persistence.
ow_check( 'to_row() supplies created_at for the NOT NULL column', true, array_key_exists( 'created_at', $row ) );
ow_assert(
	'a defaulted created_at is UTC in MySQL DATETIME format',
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['created_at'] )
);
ow_check( 'from_row() preserves a stored created_at', '2026-01-02 03:04:05', IdentityRecord::from_row( array( 'created_at' => '2026-01-02 03:04:05' ) )->created_at() );
ow_check(
	'to_row() key order matches IdentityRepository::FORMATS',
	count( \OmniWP\Identity\IdentityRepository::FORMATS ),
	count( $row )
);
ow_check( 'to_row() casts the primary flag for the column', 1, $row['is_primary'] );
ow_check( 'to_row() encodes meta as JSON', '{"hd":"example.com"}', $row['meta_json'] );
ow_check( 'to_row() writes NULL rather than an empty JSON object', null, IdentityRecord::create( 42, $verified, 'otp' )->to_row()['meta_json'] );

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

ow_check( 'from_row() restores the id', 7, $hydrated->id() );
ow_check( 'from_row() decodes meta', 'example.com', $hydrated->meta()['hd'] ?? '' );
ow_check( 'from_row() round-trips to a claim', 'google:11223344', $hydrated->claim()->key() );
ow_check( 'malformed meta_json degrades to an empty array', array(), IdentityRecord::from_row( array( 'meta_json' => 'not json' ) )->meta() );

// ---------------------------------------------------------------------
ow_section( 'PhoneChannel' );

Settings::update( array( 'identity.mode' => 'both', 'identity.country_code' => '84' ) );

$phone = new PhoneChannel();

ow_check( 'id is the stored slug', 'phone', $phone->id() );
ow_check( 'local format normalises to E.164 digits', '84969789475', $phone->normalize( '0969789475' ) );
ow_check( 'spaced international format normalises', '84969789475', $phone->normalize( '+84 969 789 475' ) );
ow_check( 'normalize is idempotent', '84969789475', $phone->normalize( $phone->normalize( '0969789475' ) ) );
ow_check( 'a mobile number is valid', true, $phone->is_valid( '84969789475' ) );
ow_check( 'a landline prefix is rejected', false, $phone->is_valid( '842839123456' ) );
ow_check( 'an empty subject is rejected', false, $phone->is_valid( '' ) );
ow_check( 'proof is by one-time code', 'otp', $phone->proof_method() );
ow_check( 'the subject is self-asserted', true, $phone->is_self_asserted() );
ow_check( 'a code can be delivered', true, $phone->can_receive_otp() );
ow_check( 'masking keeps the tail only', '096••••475', $phone->mask( '84969789475' ) );

// ---------------------------------------------------------------------
ow_section( 'MailChannel' );

$mail = new MailChannel();

ow_check( 'the stored slug stays "email"', 'email', $mail->id() );
ow_check( 'normalisation lowercases and trims', 'nhu@example.com', $mail->normalize( '  NHU@Example.COM ' ) );
ow_check( 'normalize is idempotent', 'nhu@example.com', $mail->normalize( $mail->normalize( 'NHU@Example.COM' ) ) );
ow_check( 'garbage normalises to nothing', '', $mail->normalize( 'not-an-email' ) );
ow_check( 'a real address is valid', true, $mail->is_valid( 'nhu@example.com' ) );

// The security-relevant case: a placeholder address is well-formed but
// unreachable, so it must never become a claimable identity.
ow_check( 'a synthetic @phone.invalid address is rejected', false, $mail->is_valid( '84969789475@phone.invalid' ) );
ow_check( 'rejection follows the configured placeholder domain', false, $mail->is_valid( '84969789475@PHONE.INVALID' ) );
ow_check( 'a code can be delivered', true, $mail->can_receive_otp() );
ow_check( 'masking preserves the domain', 'nh••@example.com', $mail->mask( 'nhu@example.com' ) );
ow_check( 'masking keeps the first two characters', 'ng••••••••@example.com', $mail->mask( 'nguyenvanA@example.com' ) );

// Deliberate property, not an accident: the mask has a floor of two dots, so a
// three-character local part yields two rather than one. The number of dots is
// therefore not a reliable read on the real length — which is the point.
ow_check( 'a very short local part still gets two dots', 'ab••@example.com', $mail->mask( 'ab@example.com' ) );

// ---------------------------------------------------------------------
ow_section( 'FederatedChannel — a provider costs zero classes' );

$google  = new FederatedChannel( 'google', 'Google' );
$unnamed = new FederatedChannel( 'acme' );

ow_check( 'the id is the provider slug', 'google', $google->id() );
ow_check( 'an omitted label is derived', 'Acme', $unnamed->label() );
ow_check( 'subjects are only whitespace-trimmed', '108234', $google->normalize( '  108234  ' ) );
ow_check( 'case is preserved — the subject is provider-owned', 'AbC_123', $google->normalize( 'AbC_123' ) );
ow_check( 'a normal subject is valid', true, $google->is_valid( '108234' ) );
ow_check( 'an empty subject is rejected', false, $google->is_valid( '' ) );
ow_check( 'a subject at the column limit is valid', true, $google->is_valid( str_repeat( 'a', 191 ) ) );
ow_check( 'a subject past the column limit is rejected', false, $google->is_valid( str_repeat( 'a', 192 ) ) );
ow_check( 'proof is by authorization code', 'oauth', $google->proof_method() );
ow_check( 'the subject is not self-asserted', false, $google->is_self_asserted() );
ow_check( 'no code can be delivered to a provider subject', false, $google->can_receive_otp() );

// ---------------------------------------------------------------------
ow_section( 'ChannelRegistry' );

$registry = new ChannelRegistry();

ow_check( 'the three built-in channels are registered', 3, count( $registry->all() ) );
ow_check( 'phone resolves', 'phone', $registry->get( 'phone' )->id() );
ow_check( 'email resolves', 'email', $registry->get( 'email' )->id() );
ow_check( 'google resolves', 'google', $registry->get( 'google' )->id() );
ow_check( 'an unknown channel resolves to null', null, $registry->get( 'myspace' ) );

ow_check( 'claim() normalises raw input', 'phone:84969789475', $registry->claim( 'phone', '0969789475' )->key() );
ow_check( 'claim() rejects an invalid subject', true, $registry->claim( 'phone', '12345' )->is_empty() );
ow_check( 'claim() rejects an unknown channel', true, $registry->claim( 'myspace', 'anything' )->is_empty() );
ow_check( 'claim() rejects a synthetic email', true, $registry->claim( 'email', '84969789475@phone.invalid' )->is_empty() );

ow_check( 'claim_any() routes a phone number', 'phone:84969789475', $registry->claim_any( '0969789475' )->key() );
ow_check( 'claim_any() routes an email address', 'email:nhu@example.com', $registry->claim_any( 'NHU@example.com' )->key() );
ow_check( 'claim_any() rejects nonsense', true, $registry->claim_any( '???' )->is_empty() );

Settings::update( array( 'identity.mode' => 'phone_only', 'providers.google.enabled' => 0 ) );
$enabled = ( new ChannelRegistry() )->enabled();
ow_check( 'legacy id_mode=phone_only enables one channel', array( 'phone' ), array_keys( $enabled ) );
ow_check( 'a disabled channel is not claimable through claim_any()', true, ( new ChannelRegistry() )->claim_any( 'nhu@example.com' )->is_empty() );

Settings::update( array( 'channels.enabled' => array( 'email', 'google' ) ) );
ow_check(
	'an explicit channels_enabled list overrides the legacy flags',
	array( 'email', 'google' ),
	array_keys( ( new ChannelRegistry() )->enabled() )
);

Settings::update( array( 'channels.enabled' => null, 'identity.mode' => 'both' ) );

// ---------------------------------------------------------------------
ow_section( 'OpaqueLogin — the structural half of Invariant 1' );

$login = OpaqueLogin::generate();

ow_check( 'the login is prefixed', 'ow_', substr( $login, 0, 3 ) );
ow_check( 'the login is 27 characters', 27, strlen( $login ) );
ow_check( 'the login fits the wp_users column', true, strlen( $login ) <= 60 );
ow_check( 'generate() recognises its own output', true, OpaqueLogin::is_opaque( $login ) );
ow_check( 'two logins differ', false, OpaqueLogin::generate() === OpaqueLogin::generate() );

// If any of these ever pass, core could resolve a typed identifier to a user.
ow_check( 'a phone number is not a valid opaque login', false, OpaqueLogin::is_opaque( '84969789475' ) );
ow_check( 'an email address is not a valid opaque login', false, OpaqueLogin::is_opaque( 'nhu@example.com' ) );
ow_check( 'a prefixed phone number is not a valid opaque login', false, OpaqueLogin::is_opaque( 'ow_84969789475' ) );
ow_check( 'an uppercase hex login is rejected', false, OpaqueLogin::is_opaque( 'ow_ABCDEF0123456789ABCDEF01' ) );

// ---------------------------------------------------------------------
ow_section( 'A new transport costs one class, and no new intent (Phase 4)' );

// Identity channels and delivery transports are independent axes. Adding a
// transport must not require an intent constant, a schema change, or an edit to
// register / login / recover.
$zns = new class implements \OmniWP\OTP\Transports\TransportInterface {
	/** @var array<int,array<string,string>> */
	public array $sent = array();

	public function id(): string {
		return 'zns';
	}
	public function is_available(): bool {
		return true;
	}
	public function send( string $destination, string $code, array $ctx ) {
		$this->sent[] = array( 'to' => $destination, 'code' => $code );
		return true;
	}
};

$router = new \OmniWP\OTP\Transports\TransportRouter( array( 'zns' => $zns ) );

ow_check( 'a third-party transport registers', 'zns', $router->get( 'zns' )->id() );
ow_check( 'its availability is honoured', true, $router->is_available( 'zns' ) );
ow_check( 'it delivers', true, $router->send( '84969789475', '123456', array( 'transport' => 'zns' ) ) );
ow_check( 'it received the code', '123456', $zns->sent[0]['code'] ?? '' );
ow_check( 'an unknown transport is refused, not guessed', true, is_wp_error( $router->send( '84969789475', '123456', array( 'transport' => 'nope' ) ) ) );

// The property that makes the above scale: four intents, and adding channels or
// transports adds none. There were six purpose constants before, growing by one
// per feature, because change_phone / change_email / verify_email were the same
// intent applied to different channels.
$intents = array_filter(
	array_keys( ( new ReflectionClass( OtpService::class ) )->getConstants() ),
	static function ( string $name ): bool {
		return 0 === strpos( $name, 'INTENT_' );
	}
);

ow_check( 'exactly four intents exist', 4, count( $intents ) );
ow_check(
	'and they are the four from the decision table',
	array( 'register', 'login', 'recover', 'add_identity' ),
	array( OtpService::INTENT_REGISTER, OtpService::INTENT_LOGIN, OtpService::INTENT_RECOVER, OtpService::INTENT_ADD_IDENTITY )
);

// ---------------------------------------------------------------------
ow_section( 'Password policy reaches every path that sets a password (Phase 4)' );

Settings::update( array( 'signup.min_password_length' => 8 ) );

ow_check( 'a short password is refused', true, is_wp_error( \OmniWP\Auth\PasswordPolicy::validate( 'abc' ) ) );
ow_check( 'an empty password is refused', 'OMNIWP_no_password', \OmniWP\Auth\PasswordPolicy::validate( '' )->get_error_code() );
ow_check( 'a mismatched confirmation is refused', 'OMNIWP_password_mismatch', \OmniWP\Auth\PasswordPolicy::validate( 'correct-horse', 'correct-hors' )->get_error_code() );
ow_check( 'a good password passes', true, \OmniWP\Auth\PasswordPolicy::validate( 'correct-horse', 'correct-horse' ) );
ow_check( 'the configured minimum is honoured', 8, \OmniWP\Auth\PasswordPolicy::min_length() );

Settings::update( array( 'signup.min_password_length' => 2 ) );
ow_check( 'the absolute floor overrides a too-low setting', 6, \OmniWP\Auth\PasswordPolicy::min_length() );
Settings::update( array( 'signup.min_password_length' => 8 ) );

// ---------------------------------------------------------------------
ow_section( 'OMNIWP_phone_is_valid reaches Vietnamese numbers (Phase 4)' );

// The Vietnamese branch used to return before the filter ran, so the documented
// hook was dead on the default country code — the one nearly every site uses.
$phone_src = ow_source( 'includes/Identity/class-phone.php' );

ow_assert(
	'the VN branch no longer returns before the filter',
	false === strpos( $phone_src, 'return (bool) preg_match( self::VN_MOBILE_NSN, $nsn );' ),
	'Both branches must fall through to apply_filters().'
);
ow_assert(
	'there is exactly one return path through the filter',
	1 === substr_count( $phone_src, "apply_filters( 'omniwp_phone_is_valid'" )
);

// ---------------------------------------------------------------------
ow_section( 'Invariant 2 — identity seeds profile, never overwrites it (Phase 5)' );

$GLOBALS['ow_user_meta'] = array();

ow_check( 'seeding a blank field writes it', true, ProfileSeeder::seed_if_empty( 7, 'billing_phone', '0969789475' ) );
ow_check( 'the value landed', '0969789475', (string) get_user_meta( 7, 'billing_phone', true ) );

// The case the pre-refactor code got wrong. A customer whose parcels should
// reach a family member sets a different delivery number; changing their login
// phone, saving their profile, or saving the address book must all leave it be.
ow_check( 'seeding a customer-set field is refused', false, ProfileSeeder::seed_if_empty( 7, 'billing_phone', '0912345678' ) );
ow_check( 'the customer value survives', '0969789475', (string) get_user_meta( 7, 'billing_phone', true ) );

ow_check( 'a whitespace-only value counts as empty', true, ProfileSeeder::seed_if_empty( 8, 'billing_email', 'a@b.test' ) );
ow_check( 'an empty seed value is a no-op', false, ProfileSeeder::seed_if_empty( 9, 'billing_phone', '' ) );
ow_check( 'an unlisted key is refused', false, ProfileSeeder::seed_if_empty( 7, 'biling_phone', '0912345678' ) );
ow_check( 'a non-profile key is refused', false, ProfileSeeder::seed_if_empty( 7, 'user_pass', 'nope' ) );
ow_check( 'a bad user id is refused', false, ProfileSeeder::seed_if_empty( 0, 'billing_phone', '0969789475' ) );

// The other direction: the customer's own form wins, including over itself.
ow_check( 'user input overwrites', true, ProfileSeeder::set_from_user_input( 7, 'billing_phone', '0912345678' ) );
ow_check( 'and the new value is theirs', '0912345678', (string) get_user_meta( 7, 'billing_phone', true ) );
ow_check( 'user input can clear a field', true, ProfileSeeder::set_from_user_input( 7, 'billing_phone', '' ) );
ow_check( 'clearing really clears', '', (string) get_user_meta( 7, 'billing_phone', true ) );
ow_check( 'an unlisted key is still refused', false, ProfileSeeder::set_from_user_input( 7, 'billing_nonsense', 'x' ) );

ow_check(
	'seed_many writes every blank field',
	2,
	ProfileSeeder::seed_many( 7, array( 'billing_first_name' => 'Như', 'billing_email' => 'kept@example.test' ) )
);
ow_check(
	'seed_many writes nothing a second time',
	0,
	ProfileSeeder::seed_many( 7, array( 'billing_first_name' => 'Khác', 'billing_email' => 'other@example.test' ) )
);
ow_check( 'and the first values stand', 'kept@example.test', (string) get_user_meta( 7, 'billing_email', true ) );

// shipping_phone exists so a recipient number has somewhere to live, and nothing
// in the identity layer is allowed to fill it in.
ow_check( 'shipping_phone is a writable profile field', true, in_array( 'shipping_phone', ProfileSeeder::WRITABLE, true ) );

$identity_sources = ow_plugin_sources();
$seeds_shipping   = false;

foreach ( $identity_sources as $relative => $contents ) {
	if ( 'includes/Identity/class-profile-seeder.php' === $relative ) {
		continue;
	}
	if ( preg_match( "/seed_if_empty\([^;]*'shipping_phone'/", $contents ) ) {
		$seeds_shipping = true;
	}
}

ow_check( 'no identity ever seeds shipping_phone', false, $seeds_shipping );

// ---------------------------------------------------------------------
ow_section( 'Checkout uses a hook with a return value (Phase 5)' );

$woo_address = ow_source( 'includes/Address/class-woo-address.php' );

ow_assert(
	'ward substitution runs on woocommerce_checkout_posted_data',
	false !== strpos( $woo_address, "add_filter( 'woocommerce_checkout_posted_data'" ),
	'do_action() passes arrays by value, so assigning to $data in the validation hook is discarded.'
);
ow_assert(
	'normalise_posted_data returns the array',
	(bool) preg_match( '/function normalise_posted_data\([^)]*\)\s*\{.*return \$data;/s', $woo_address )
);
// The substitution line still exists — it has simply moved into the filter that
// returns the array. What must be gone is any assignment to $data inside
// validate_checkout(), whose $data is a by-value copy.
preg_match( '/function validate_checkout\(.*?\n\t\}/s', $woo_address, $validate_body );

ow_assert(
	'validate_checkout() no longer assigns to $data',
	isset( $validate_body[0] ) && false === strpos( $validate_body[0], '$data[' ),
	'do_action() hands it a copy, so any assignment there is silently thrown away.'
);
ow_assert(
	'validate_checkout() only reports errors',
	isset( $validate_body[0] ) && false !== strpos( $validate_body[0], '$errors->add(' )
);

// ---------------------------------------------------------------------
ow_section( 'Unlink cannot orphan an account (Phase 6)' );

// IdentityDirectory and IdentityRepository are both final, on purpose — they are
// the single source of truth for ownership and should not be subclassable. So the
// guard is exercised through the real objects with the stubbed $wpdb underneath,
// which tests the actual code path rather than a mock of it.
$link_service = new \OmniWP\Auth\IdentityLinkService();

$GLOBALS['ow_wpdb_var'] = 0;
ow_check( 'an account with no identities cannot unlink', false, $link_service->can_unlink( 7 ) );

$GLOBALS['ow_wpdb_var'] = 1;
ow_check( 'the last identity cannot be removed', false, $link_service->can_unlink( 7 ) );

$GLOBALS['ow_wpdb_var'] = 2;
ow_check( 'a spare identity makes removal possible', true, $link_service->can_unlink( 7 ) );

$GLOBALS['ow_wpdb_var'] = 5;
ow_check( 'and so does more than one spare', true, $link_service->can_unlink( 7 ) );

// The guard fails closed, and the escape hatch has to be asked for explicitly.
$GLOBALS['ow_wpdb_var'] = 1;
ow_check( 'no filter, no orphaning', false, $link_service->can_unlink( 7 ) );

// unlinked_providers() drives the UI: offering "link Google" to somebody whose
// Google is already linked told them nothing.
$GLOBALS['ow_wpdb_results'] = array(
	array(
		'id'          => 3,
		'user_id'     => 7,
		'channel'     => 'google',
		'subject'     => 'sub-1',
		'is_primary'  => 0,
		'verified_at' => '2026-07-30 08:00:00',
		'linked_by'   => 'oauth',
		'created_at'  => '2026-07-30 08:00:00',
	),
);

ow_check(
	'an already-linked provider is not offered again',
	array( 'acme' ),
	$link_service->unlinked_providers( 7, array( 'google', 'acme' ) )
);

// linked() is what the profile screen renders: masked, labelled, never raw.
$GLOBALS['ow_wpdb_var'] = 2;
$listed = $link_service->linked( 7 );

ow_check( 'one identity is listed', 1, count( $listed ) );
ow_check( 'it is labelled', 'Google', $listed[0]['label'] ?? '' );
ow_check( 'the subject is masked for display', 'sub-••••••', $listed[0]['masked'] ?? '' );
ow_check( 'the raw subject is still available for the form', 'sub-1', $listed[0]['subject'] ?? '' );
ow_check( 'it is marked federated', true, $listed[0]['federated'] ?? null );
ow_check( 'and removable, because a spare exists', true, $listed[0]['removable'] ?? null );

$GLOBALS['ow_wpdb_var'] = 1;
ow_check( 'with no spare it is not removable', false, $link_service->linked( 7 )[0]['removable'] ?? null );

$GLOBALS['ow_wpdb_results'] = array();
$GLOBALS['ow_wpdb_var']     = null;

// ---------------------------------------------------------------------
ow_section( 'Unlink is gated on re-authentication (Phase 6)' );

$link_src = ow_source( 'includes/Auth/class-identity-link-service.php' );

ow_assert(
	'unlink() checks the password',
	false !== strpos( $link_src, 'wp_check_password(' ),
	'A borrowed session must not be enough to detach a victim\'s provider.'
);
ow_assert(
	'the orphan guard runs before the password is even checked',
	strpos( $link_src, 'can_unlink( $user_id )' ) < strpos( $link_src, '$this->reauthenticate(' ),
	'Refusing early avoids prompting for a password on an action that cannot succeed.'
);
ow_assert(
	'ownership comes from the directory, not from the request',
	false !== strpos( $link_src, '$resolution->user_id() !== $user_id' )
);

// ---------------------------------------------------------------------
ow_section( 'Settings and Flow fallbacks actually fall back' );

// Regression test for a bug introduced during the Phase 7 phpcs cleanup: a
// mechanical rename of the $default parameter left two function bodies reading a
// variable their own signature no longer declared. PHP treats that as null, so
// php -l passed and every default silently became null. Nothing existing covered
// the fallback path, which is why it got through.
Settings::update( array( 'otp.length' => 6 ) );

ow_check( 'a known path returns its value', 6, Settings::get( 'otp.length' ) );
ow_check( 'an unknown path returns the fallback', 'fallback-value', Settings::get( 'no.such.path', 'fallback-value' ) );
ow_check( 'an unknown path with no fallback returns null', null, Settings::get( 'no.such.path' ) );
ow_check( 'get_int falls back too', 42, Settings::get_int( 'no.such.path', 42 ) );
ow_check( 'get_int defaults to zero', 0, Settings::get_int( 'no.such.path' ) );

// A path that stops short of a leaf must not hand back the branch: callers
// expect a scalar, and returning the whole subtree would make is_on() true for
// any group that happens to be non-empty.
ow_check( 'a partial path returns the branch it names', true, is_array( Settings::get( 'otp' ) ) );
ow_check( 'a flat legacy key no longer resolves', null, Settings::get( 'otp_length' ) );

ow_check( 'Flow::data falls back', 'none', \OmniWP\Frontend\Flow::data( 'no_such_key', 'none' ) );
ow_check( 'Flow::old falls back', 'empty', \OmniWP\Frontend\Flow::old( 'no_such_key', 'empty' ) );
ow_check( 'Flow::step falls back', 'otp', \OmniWP\Frontend\Flow::step( 'otp' ) );

// Identifier-first has no separate login and register screens. The two legacy
// step names survive so existing links and shortcodes keep resolving, and both
// land on the single entry screen rather than on a step that no longer exists.
ow_check( 'the legacy login step collapses onto the entry screen', 'identify', \OmniWP\Frontend\Flow::step( 'login' ) );
ow_check( 'the legacy register step collapses onto the entry screen', 'identify', \OmniWP\Frontend\Flow::step( 'register' ) );

// Steps that only mean something alongside server-side state must not be
// reachable by typing a query string. Rendering the signup form to somebody
// with no verified identifier behind it would be a form with nothing under it.
$_GET['OMNIWP_step'] = 'otp';
ow_check( 'a public step can be requested by URL', 'otp', \OmniWP\Frontend\Flow::step( 'identify' ) );

foreach ( array( 'password', 'signup', 'onboard' ) as $ow_private_step ) {
	$_GET['OMNIWP_step'] = $ow_private_step;
	ow_check(
		sprintf( 'the %s step cannot be reached by URL', $ow_private_step ),
		'identify',
		\OmniWP\Frontend\Flow::step( 'identify' )
	);
}

unset( $_GET['OMNIWP_step'] );

// ---------------------------------------------------------------------
ow_summary( 'Identity core' );
