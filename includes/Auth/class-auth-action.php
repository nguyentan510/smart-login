<?php
/**
 * The ACT phase: what to do, given an intent and a resolution state.
 *
 * A lookup table rather than nested conditionals, because the interesting
 * property is completeness. Four intents times four states is sixteen cells, and
 * every one is stated explicitly — including the boring ones — so a missing case
 * is visible instead of falling through to whatever the last `else` did.
 *
 * The load-bearing cells are `login × RETIRED` and `recover × RETIRED`. Both map
 * to "no account". A subject whose owner gave it up has no owner, so there is
 * nothing for an attacker holding a recycled phone number to reach. The
 * pre-refactor code reported KNOWN for that case because it resolved ownership
 * from wp_users.user_login, and that is the account-takeover path this table
 * removes by construction. See docs/identity-model.md §5.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\Resolution;

defined( 'ABSPATH' ) || exit;

final class AuthAction {

	// Intents.
	const REGISTER     = 'register';
	const LOGIN        = 'login';
	const RECOVER      = 'recover';
	const ADD_IDENTITY = 'add_identity';

	// Outcomes.
	const CREATE_USER        = 'create_user';
	const CREATE_NEW_USER    = 'create_new_user';
	const ALREADY_REGISTERED = 'already_registered';
	const ISSUE_SESSION      = 'issue_session';
	const ISSUE_RESET_GRANT  = 'issue_reset_grant';
	const NO_ACCOUNT         = 'no_account';
	const LINK_TO_CURRENT    = 'link_to_current';
	const NO_OP              = 'no_op';
	const REJECT             = 'reject';

	/**
	 * @return array<string,array<string,string>>
	 */
	public static function table(): array {
		return array(
			self::REGISTER     => array(
				Resolution::STATE_UNKNOWN  => self::CREATE_USER,
				Resolution::STATE_KNOWN    => self::ALREADY_REGISTERED,
				// A previous owner existed, but this is a different person now.
				// A fresh account, plus a history note that the subject was reused.
				Resolution::STATE_RETIRED  => self::CREATE_NEW_USER,
				Resolution::STATE_CONFLICT => self::REJECT,
			),
			self::LOGIN        => array(
				Resolution::STATE_UNKNOWN  => self::NO_ACCOUNT,
				Resolution::STATE_KNOWN    => self::ISSUE_SESSION,
				Resolution::STATE_RETIRED  => self::NO_ACCOUNT,
				Resolution::STATE_CONFLICT => self::REJECT,
			),
			self::RECOVER      => array(
				Resolution::STATE_UNKNOWN  => self::NO_ACCOUNT,
				Resolution::STATE_KNOWN    => self::ISSUE_RESET_GRANT,
				Resolution::STATE_RETIRED  => self::NO_ACCOUNT,
				Resolution::STATE_CONFLICT => self::REJECT,
			),
			self::ADD_IDENTITY => array(
				Resolution::STATE_UNKNOWN  => self::LINK_TO_CURRENT,
				// Caller compares the owner with the signed-in user: same user is a
				// no-op, a different one is a conflict it must refuse.
				Resolution::STATE_KNOWN    => self::NO_OP,
				Resolution::STATE_RETIRED  => self::LINK_TO_CURRENT,
				Resolution::STATE_CONFLICT => self::REJECT,
			),
		);
	}

	/**
	 * Anything unrecognised is refused. A typo in an intent must not open a door.
	 */
	public static function decide( string $intent, string $state ): string {
		return self::table()[ $intent ][ $state ] ?? self::REJECT;
	}

	public static function for_resolution( string $intent, Resolution $resolution ): string {
		return self::decide( $intent, $resolution->state() );
	}
}
