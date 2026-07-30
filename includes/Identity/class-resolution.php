<?php
/**
 * The answer to "who owns this subject right now?".
 *
 * Exactly four states exist, and the contract test asserts that this class
 * declares exactly four constants. A fifth would mean the state machine grew a
 * branch that docs/identity-model.md §5 does not describe, and the ACT decision
 * table would have an undefined column.
 *
 * RETIRED is the state that makes the account-takeover defect unrepresentable:
 * a subject with no current owner but a recorded past one resolves here, and the
 * decision table maps both `login` and `recover` on RETIRED to "no account".
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class Resolution {

	const STATE_UNKNOWN  = 'unknown';
	const STATE_KNOWN    = 'known';
	const STATE_RETIRED  = 'retired';
	const STATE_CONFLICT = 'conflict';

	private string $state;
	private int $user_id;
	private int $prior_user_id;

	private function __construct( string $state, int $user_id, int $prior_user_id ) {
		$this->state         = $state;
		$this->user_id       = $user_id;
		$this->prior_user_id = $prior_user_id;
	}

	public static function unknown(): self {
		return new self( self::STATE_UNKNOWN, 0, 0 );
	}

	public static function known( int $user_id ): self {
		return new self( self::STATE_KNOWN, max( 0, $user_id ), 0 );
	}

	/**
	 * No active owner, but history records one.
	 *
	 * The previous owner is carried for policy and support use only — adding
	 * friction to a re-registration, or explaining a support ticket. Treating it
	 * as an owner is the defect; see identity-model.md §1.
	 */
	public static function retired( int $prior_user_id = 0 ): self {
		return new self( self::STATE_RETIRED, 0, max( 0, $prior_user_id ) );
	}

	public static function conflict(): self {
		return new self( self::STATE_CONFLICT, 0, 0 );
	}

	public function state(): string {
		return $this->state;
	}

	/**
	 * The owning user, or 0. Non-zero only for KNOWN.
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	public function prior_user_id(): int {
		return $this->prior_user_id;
	}

	public function is_known(): bool {
		return self::STATE_KNOWN === $this->state;
	}

	/**
	 * Whether an authentication decision may act on user_id(). RETIRED
	 * deliberately answers false even though prior_user_id() is populated.
	 */
	public function has_owner(): bool {
		return self::STATE_KNOWN === $this->state && $this->user_id > 0;
	}
}
