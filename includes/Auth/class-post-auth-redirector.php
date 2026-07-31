<?php
/**
 * Decides the next safe page after a successful authentication.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class PostAuthRedirector {

	/**
	 * A new account goes to the welcome screen once; everybody else goes where
	 * they were heading.
	 *
	 * There is deliberately no branch here that traps an account with an
	 * incomplete profile. That used to exist — it set a `smartlogin_gate` flag
	 * nothing ever read, so the UI said "bắt buộc" while nothing enforced it.
	 * Onboarding asks, and takes no for an answer.
	 */
	public function redirect( AuthResult $result, string $requested = '' ): string {
		$profiles = new ProfileCompletionService();

		if ( $result->is_new_user && ! $profiles->has_seen( $result->user_id ) ) {
			$profiles->mark_seen( $result->user_id, $result->auth_method );
			$url                  = add_query_arg( 'smartlogin_welcome', '1', self::profile_url() );
			$filtered             = (string) apply_filters( 'smart_login_post_register_redirect', $url, $result->user_id );
			$result->redirect_url = $this->safe( $filtered, $url );
			return $result->redirect_url;
		}

		$requested = wp_validate_redirect( $requested, '' );
		if ( '' !== $requested ) {
			$url = $requested;
		} else {
			$configured = trim( (string) Settings::get( 'redirect_after_login', '' ) );
			$url        = '' !== $configured ? $configured : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
		}

		$filtered             = (string) apply_filters( 'smart_login_post_login_redirect', $url );
		$result->redirect_url = $this->safe( $filtered, home_url( '/' ) );
		return $result->redirect_url;
	}

	private function safe( string $url, string $fallback ): string {
		$safe_fallback = wp_validate_redirect( $fallback, home_url( '/' ) );
		return wp_validate_redirect( $url, $safe_fallback );
	}

	public static function profile_url(): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'edit-account' );
		}

		return admin_url( 'profile.php' );
	}
}
