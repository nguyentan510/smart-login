<?php
/**
 * The sole owner of WordPress authentication-cookie issuance.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class SessionIssuer {

	public function issue( WP_User $user, AuthContext $context, bool $remember = true ) {
		if ( $user->ID <= 0 ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
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
