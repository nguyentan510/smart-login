<?php
/**
 * Evidence that whoever is asking controls the account they are asking about.
 *
 * The constructor is private and the three factories are the only way in, so an
 * AuthProof cannot be conjured by a caller that has not actually verified
 * anything. SessionIssuer requires one, which turns "no session without proof"
 * from a code-review convention into a type error.
 *
 * This is the smallest change that closes a real gap: before it, any code path
 * holding a WP_User could mint a login cookie.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\VerifiedClaim;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class AuthProof {

	const METHOD_OTP      = 'otp';
	const METHOD_OAUTH    = 'oauth';
	const METHOD_PASSWORD = 'password';

	private string $method;
	private ?VerifiedClaim $claim;
	private int $user_id;

	private function __construct( string $method, ?VerifiedClaim $claim, int $user_id ) {
		$this->method  = $method;
		$this->claim   = $claim;
		$this->user_id = $user_id;
	}

	/**
	 * A one-time code delivered to the subject was entered correctly.
	 */
	public static function from_otp( VerifiedClaim $claim, int $user_id = 0 ): self {
		return new self( self::METHOD_OTP, $claim, max( 0, $user_id ) );
	}

	/**
	 * A federated authorization-code exchange completed, and its signature,
	 * audience, expiry and nonce were all checked.
	 */
	public static function from_oauth( VerifiedClaim $claim, int $user_id = 0 ): self {
		return new self( self::METHOD_OAUTH, $claim, max( 0, $user_id ) );
	}

	/**
	 * wp_check_password() succeeded for this user.
	 *
	 * No claim is attached: a password proves control of an account, not of any
	 * particular subject.
	 */
	public static function from_password( WP_User $user ): self {
		return new self( self::METHOD_PASSWORD, null, (int) $user->ID );
	}

	public function method(): string {
		return $this->method;
	}

	/**
	 * The proven subject, or null for password proof.
	 */
	public function claim(): ?VerifiedClaim {
		return $this->claim;
	}

	/**
	 * The user this proof is bound to, or 0 when it proves a subject rather than
	 * an account — registration, for instance, proves the phone before the user
	 * row exists.
	 */
	public function user_id(): int {
		return $this->user_id;
	}
}
