<?php
/**
 * A federated provider as an identity namespace.
 *
 * Concrete and parameterised rather than abstract, so a second provider costs
 * no classes at all:
 *
 *     new FederatedChannel( 'google', 'Google' )
 *
 * Subjects are opaque provider-owned strings — Google's `sub`, and whatever the
 * next provider calls its own. They are never transformed, only length-checked,
 * because the provider defines their format and a "helpful" normalisation here
 * would silently break matching.
 *
 * @package OmniWP
 */

namespace OmniWP\Identity\Channels;

use OmniWP\Identity\VerifiedClaim;

defined( 'ABSPATH' ) || exit;

final class FederatedChannel implements IdentityChannel {

	/** Matches the `subject` column width in OmniWP_identities. */
	const MAX_SUBJECT_LENGTH = 191;

	private string $id;
	private string $label;

	public function __construct( string $id, string $label = '' ) {
		$this->id    = sanitize_key( $id );
		$this->label = '' !== $label ? $label : ucfirst( $this->id );
	}

	public function id(): string {
		return $this->id;
	}

	/**
	 * Whitespace only. The subject belongs to the provider's namespace and is
	 * compared byte-for-byte.
	 */
	public function normalize( string $raw ): string {
		return trim( $raw );
	}

	public function is_valid( string $subject ): bool {
		return '' !== $subject && strlen( $subject ) <= self::MAX_SUBJECT_LENGTH;
	}

	public function proof_method(): string {
		return VerifiedClaim::PROOF_OAUTH;
	}

	public function is_self_asserted(): bool {
		return false;
	}

	public function can_receive_otp(): bool {
		return false;
	}

	public function label(): string {
		return $this->label;
	}

	/**
	 * Provider subjects carry no inherent meaning to a user, so there is nothing
	 * useful to reveal. Show enough to correlate two log lines, no more.
	 */
	public function mask( string $subject ): string {
		if ( '' === $subject ) {
			return '';
		}

		return substr( $subject, 0, 4 ) . str_repeat( '•', 6 );
	}
}
