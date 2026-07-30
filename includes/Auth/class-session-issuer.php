<?php
/**
 * The sole owner of WordPress authentication-cookie issuance.
 *
 * An AuthProof is a mandatory first argument, not an optional context field.
 * Because AuthProof has a private constructor and only three factories, all of
 * which live in the PROVE layer, a caller that has verified nothing has nothing
 * to pass — so "no session without proof" is enforced by the type system rather
 * than by remembering to check.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class SessionIssuer {

	/**
	 * @return AuthResult|WP_Error
	 */
	public function issue( AuthProof $proof, WP_User $user, AuthContext $context, bool $remember = true ) {
		if ( $user->ID <= 0 ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
		}

		// Proof bound to a specific account must be proof about THIS account.
		// Password proof is always bound; OTP and OAuth proof may be bound to 0
		// when they prove a subject that predates the user row (registration).
		if ( $proof->user_id() > 0 && $proof->user_id() !== (int) $user->ID ) {
			return new WP_Error(
				'smart_login_proof_mismatch',
				__( 'Phiên xác thực không hợp lệ.', 'smart-login' )
			);
		}

		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, $remember );
		do_action( 'wp_login', $user->user_login, $user );

		return new AuthResult(
			(int) $user->ID,
			$context,
			( new ProfileCompletionService() )->status( (int) $user->ID )
		);
	}
}
