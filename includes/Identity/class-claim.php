<?php
/**
 * An unproven assertion of the form "this subject is mine".
 *
 * A Claim is what the IDENTIFY phase produces: a channel plus a canonical
 * subject, with no proof attached and no database access performed. It is the
 * only shape the RESOLVE phase accepts.
 *
 * Unlike AuthContext and ProviderIdentity, which expose public properties, this
 * object hides its state behind accessors and a private constructor. That is
 * deliberate: a mutable subject could be swapped after verification, which is
 * precisely the class of defect docs/identity-model.md exists to remove.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class Claim {

	private string $channel;
	private string $subject;

	private function __construct( string $channel, string $subject ) {
		$this->channel = $channel;
		$this->subject = $subject;
	}

	/**
	 * Build a claim from an ALREADY canonical subject.
	 *
	 * The name is a warning at every call site: this constructor performs no
	 * normalisation. Callers holding raw user input must go through
	 * ChannelRegistry::claim(), which normalises and validates first.
	 */
	public static function canonical( string $channel, string $subject ): self {
		return new self( sanitize_key( $channel ), trim( $subject ) );
	}

	/**
	 * The absent claim. Returned instead of null so callers can keep using the
	 * same type and check is_empty() rather than guarding against null.
	 */
	public static function none(): self {
		return new self( '', '' );
	}

	public function channel(): string {
		return $this->channel;
	}

	public function subject(): string {
		return $this->subject;
	}

	public function is_empty(): bool {
		return '' === $this->channel || '' === $this->subject;
	}

	/**
	 * Stable composite key, e.g. `phone:84969789475`. Matches the
	 * UNIQUE (channel, subject) index in smartlogin_identities.
	 */
	public function key(): string {
		return $this->channel . ':' . $this->subject;
	}

	public function equals( self $other ): bool {
		return $this->channel === $other->channel && $this->subject === $other->subject;
	}
}
