<?php
/**
 * A claim whose control has been demonstrated.
 *
 * Produced only by the PROVE phase — an OTP that verified, or an OAuth callback
 * whose signature, audience and nonce all checked out. Everything downstream
 * that writes to smartlogin_identities requires one of these, so an unproven
 * subject cannot reach the identity table.
 *
 * The timestamp uses gmdate() rather than current_time() to keep the identity
 * core free of WordPress runtime dependencies. Both yield UTC; gmdate() is what
 * OtpService already uses for the same reason.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class VerifiedClaim {

	/** Proof obtained by a one-time code delivered to the subject. */
	const PROOF_OTP = 'otp';

	/** Proof obtained by a federated authorization-code exchange. */
	const PROOF_OAUTH = 'oauth';

	private Claim $claim;
	private string $proof_method;
	private string $verified_at;

	private function __construct( Claim $claim, string $proof_method, string $verified_at ) {
		$this->claim        = $claim;
		$this->proof_method = $proof_method;
		$this->verified_at  = $verified_at;
	}

	/**
	 * @param string $verified_at 'Y-m-d H:i:s' in UTC. Defaults to now.
	 */
	public static function from( Claim $claim, string $proof_method, string $verified_at = '' ): self {
		return new self(
			$claim,
			self::PROOF_OAUTH === $proof_method ? self::PROOF_OAUTH : self::PROOF_OTP,
			'' !== $verified_at ? $verified_at : gmdate( 'Y-m-d H:i:s' )
		);
	}

	public function claim(): Claim {
		return $this->claim;
	}

	public function channel(): string {
		return $this->claim->channel();
	}

	public function subject(): string {
		return $this->claim->subject();
	}

	public function proof_method(): string {
		return $this->proof_method;
	}

	public function verified_at(): string {
		return $this->verified_at;
	}
}
