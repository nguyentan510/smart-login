<?php
/**
 * The only place that answers "who owns this subject?".
 *
 * Everything that used to reach for wp_users.user_login, the smartlogin_phone
 * user meta, or a get_users() meta_query now goes through here. That is
 * Invariant 1 from docs/identity-model.md, and tests/identity/run-fitness-tests.php
 * fails the build if another route reappears.
 *
 * RESOLVE performs no writes. It is safe to call on any request, including ones
 * that will go on to reject the visitor.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class IdentityDirectory {

	private IdentityRepository $identities;
	private ChannelRegistry $channels;

	public function __construct( ?IdentityRepository $identities = null, ?ChannelRegistry $channels = null ) {
		$this->identities = $identities ?? new IdentityRepository();
		$this->channels   = $channels ?? new ChannelRegistry();
	}

	public function channels(): ChannelRegistry {
		return $this->channels;
	}

	public function identities(): IdentityRepository {
		return $this->identities;
	}

	/**
	 * Current ownership of a subject.
	 *
	 * Never returns CONFLICT: `UNIQUE KEY subject_owner` makes at most one owner
	 * possible, so ambiguity cannot exist in this table. CONFLICT is reachable
	 * only where two WordPress users share an email address, which the federated
	 * auto-link path detects for itself.
	 */
	public function resolve( Claim $claim ): Resolution {
		if ( $claim->is_empty() ) {
			return Resolution::unknown();
		}

		$record = $this->identities->find( $claim );

		if ( $record ) {
			return Resolution::known( $record->user_id() );
		}

		// No current owner. History decides between "never seen" and "given up",
		// and the caller must treat the latter as ownerless — reading it as an
		// owner is the defect this class exists to prevent.
		$prior = $this->identities->history()->last_retired_owner( $claim );

		return $prior > 0 ? Resolution::retired( $prior ) : Resolution::unknown();
	}

	/**
	 * Normalise raw input for a known channel, then resolve it.
	 */
	public function resolve_in( string $channel, string $raw ): Resolution {
		return $this->resolve( $this->channels->claim( $channel, $raw ) );
	}

	/**
	 * Resolve a single identifier field that accepts a phone number or an email.
	 */
	public function resolve_any( string $raw ): Resolution {
		return $this->resolve( $this->channels->claim_any( $raw ) );
	}

	/**
	 * Take ownership of a proven subject.
	 *
	 * @return bool False when the subject is already owned.
	 */
	public function link( int $user_id, VerifiedClaim $claim, string $linked_by, bool $primary = false, array $meta = array() ): bool {
		return $this->identities->claim(
			IdentityRecord::create( $user_id, $claim, $linked_by, $primary, $meta )
		);
	}

	/**
	 * End ownership. This is what makes a subject resolve as RETIRED afterwards,
	 * so a contact change must always come through here rather than simply
	 * overwriting a value somewhere.
	 *
	 * @return int The previous owner, or 0.
	 */
	public function retire( Claim $claim, string $reason = '', string $actor = 'self' ): int {
		return $this->identities->retire( $claim, $reason, $actor );
	}

	/**
	 * Replace a user's identity in one channel with a newly proven one.
	 *
	 * Retire-then-claim in a fixed order, so the old subject is always released
	 * before the new one is taken. A failure to claim leaves the user with one
	 * fewer identity rather than with two conflicting ones, and the history rows
	 * explain what happened.
	 *
	 * @return bool False when the new subject could not be claimed.
	 */
	public function replace_in_channel( int $user_id, VerifiedClaim $claim, string $linked_by, string $reason = 'contact_changed' ): bool {
		foreach ( $this->identities->for_user( $user_id, $claim->channel() ) as $existing ) {
			if ( $existing->subject() === $claim->subject() ) {
				return true; // Already theirs; nothing to do.
			}

			$this->identities->retire( $existing->claim(), $reason );
		}

		return $this->link( $user_id, $claim, $linked_by, true );
	}

	/**
	 * @return IdentityRecord[]
	 */
	public function for_user( int $user_id, string $channel = '' ): array {
		return $this->identities->for_user( $user_id, $channel );
	}

	/**
	 * The subject a user is primarily reachable at in one channel.
	 */
	public function primary_subject( int $user_id, string $channel ): string {
		$record = $this->identities->primary_for( $user_id, $channel );

		return $record ? $record->subject() : '';
	}

	/**
	 * Where to send a one-time code for an account: the primary phone, falling
	 * back to the primary email.
	 *
	 * Returns an empty claim when the account has nothing reachable, which the
	 * caller must handle rather than guessing.
	 */
	public function otp_destination( int $user_id ): Claim {
		foreach ( array( Channels\PhoneChannel::ID, Channels\MailChannel::ID ) as $channel_id ) {
			$channel = $this->channels->get( $channel_id );

			if ( ! $channel || ! $channel->can_receive_otp() ) {
				continue;
			}

			$subject = $this->primary_subject( $user_id, $channel_id );

			if ( '' !== $subject ) {
				return Claim::canonical( $channel_id, $subject );
			}
		}

		return Claim::none();
	}

	/**
	 * The user behind a subject, or null. A thin convenience over resolve() for
	 * callers that only care about the KNOWN case — it deliberately returns null
	 * for RETIRED.
	 */
	public function owner( Claim $claim ): ?\WP_User {
		$resolution = $this->resolve( $claim );

		if ( ! $resolution->has_owner() ) {
			return null;
		}

		$user = get_userdata( $resolution->user_id() );

		return $user ?: null;
	}
}
