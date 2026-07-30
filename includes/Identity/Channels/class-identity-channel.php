<?php
/**
 * One identity namespace: phone numbers, email addresses, a federated provider.
 *
 * "Channel" means exactly this throughout the project. The OTP delivery
 * mechanism is a *transport*, not a channel — Phase 4 renames OTP\Channels to
 * OTP\Transports to retire the collision.
 *
 * Implementing this interface and registering the instance is the entire cost of
 * supporting a new identifier type. Nothing in register / login / recover needs
 * to change, and no new OTP purpose constants appear.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity\Channels;

defined( 'ABSPATH' ) || exit;

interface IdentityChannel {

	/**
	 * Stable slug stored in the `channel` column. Never change it for an
	 * existing channel: every stored row would be orphaned.
	 */
	public function id(): string;

	/**
	 * Raw user or provider input to a canonical subject.
	 *
	 * Must be idempotent — normalize( normalize( $x ) ) === normalize( $x ) —
	 * because subjects round-trip through the database and through JSON.
	 * Returns '' for input that cannot be interpreted.
	 */
	public function normalize( string $raw ): string;

	/**
	 * Whether a canonical subject may enter smartlogin_identities.
	 *
	 * Stricter than "well formed": a placeholder address is a valid email but
	 * must never become a claimable identity.
	 */
	public function is_valid( string $subject ): bool;

	/**
	 * How control over a subject in this channel is demonstrated.
	 *
	 * @return string VerifiedClaim::PROOF_OTP or VerifiedClaim::PROOF_OAUTH.
	 */
	public function proof_method(): string;

	/**
	 * True when the user types the subject in themselves, so it arrives
	 * unproven. False for federated subjects, which the provider asserts.
	 */
	public function is_self_asserted(): bool;

	/**
	 * Whether a one-time code can be delivered to a subject in this channel.
	 */
	public function can_receive_otp(): bool;

	/**
	 * Human-readable channel name, translated.
	 */
	public function label(): string;

	/**
	 * Subject reduced to something safe to display or log.
	 */
	public function mask( string $subject ): string;
}
