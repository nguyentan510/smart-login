<?php
/**
 * Decides the next safe page after a successful authentication.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth;

use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class PostAuthRedirector {

	/**
	 * A new account goes to the welcome screen once; everybody else goes where
	 * they were heading.
	 *
	 * There is deliberately no branch here that traps an account with an
	 * incomplete profile. That used to exist — it set a `OmniWP_gate` flag
	 * nothing ever read, so the UI said "bắt buộc" while nothing enforced it.
	 * Onboarding asks, and takes no for an answer.
	 */
	public function redirect( AuthResult $result, string $requested = '' ): string {
		$profiles = new ProfileCompletionService();

		/*
		 * A flow that draws its own screens gets no destination at all.
		 *
		 * Returning '' is the whole of 19.5 on this side of the boundary: the
		 * caller reads it as "there is nowhere to send them, render the welcome
		 * where you are". Every other caller passes `in_place = false` and takes
		 * the branch below unchanged, so the page-hosted flow behaves exactly as
		 * it did.
		 *
		 * `mark_seen()` is deliberately *not* called here. It moves to whoever
		 * actually renders the screen — marking a welcome delivered before it is
		 * on screen is how a welcome gets lost, which is the argument
		 * `Shortcodes::onboarding_args()` already makes for the page path.
		 */
		if ( $result->in_place && $result->is_new_user && ! $profiles->has_seen( $result->user_id ) ) {
			$result->redirect_url = '';

			return '';
		}

		if ( $result->is_new_user && ! $profiles->has_seen( $result->user_id ) ) {
			$profiles->mark_seen( $result->user_id, $result->auth_method );
			$url = add_query_arg(
				array(
					'OmniWP_welcome' => '1',
					'new'            => '1',
				),
				self::profile_url()
			);
			if ( '' !== $requested ) {
				$url = add_query_arg( 'redirect_to', rawurlencode( $requested ), $url );
			}
			$filtered             = (string) apply_filters( 'OMNIWP_post_register_redirect', $url, $result->user_id );
			$result->redirect_url = $this->safe( $filtered, $url );
			return $result->redirect_url;
		}

		$requested = wp_validate_redirect( $requested, '' );
		$is_checkout_request = false;
		if ( '' !== $requested ) {
			$is_checkout_request = ( false !== strpos( $requested, 'checkout' ) )
				|| ( function_exists( 'wc_get_checkout_url' ) && 0 === strpos( $requested, wc_get_checkout_url() ) );
		}

		// Scenario 2: Returning user login with incomplete basic profile.
		// If profile is incomplete AND the user is NOT in the middle of checkout,
		// prompt them politely to complete profile with a skip option.
		if ( ! $result->is_new_user && ! $is_checkout_request ) {
			$missing_fields = $profiles->onboarding_fields( $result->user_id );
			if ( ! empty( $missing_fields ) ) {
				if ( $result->in_place ) {
					$result->redirect_url = '';
					return '';
				}

				$dest = '' !== $requested ? $requested : ( \OmniWP\Frontend\AccountForm::shortcode_page_url() ?: home_url( '/' ) );
				$url  = add_query_arg(
					array(
						'OmniWP_welcome' => '1',
						'incomplete'     => '1',
					),
					$dest
				);
				$result->redirect_url = $this->safe( $url, $dest );
				return $result->redirect_url;
			}
		}

		if ( '' !== $requested ) {
			$url = $requested;
		} else {
			// A freshly registered account prefers the registration destination
			// when one is configured. This setting had a control on the settings
			// screen from the start and no reader anywhere, so an admin who filled
			// it in got a page that was never visited — the same defect as
			// `require_verification`, and the reason the schema is now declared in
			// one place that both draws a control and is read.
			$configured = $result->is_new_user
				? trim( (string) Settings::get( 'signup.redirect_register', '' ) )
				: '';

			if ( '' !== $configured ) {
				$url = $configured;
			} else {
				$url = \OmniWP\Frontend\AccountForm::edit_url();
			}
		}

		$filtered             = (string) apply_filters( 'OMNIWP_post_login_redirect', $url );
		$result->redirect_url = $this->safe( $filtered, home_url( '/' ) );
		return $result->redirect_url;
	}

	private function safe( string $url, string $fallback ): string {
		$safe_fallback = wp_validate_redirect( $fallback, home_url( '/' ) );
		return wp_validate_redirect( $url, $safe_fallback );
	}

	public static function profile_url(): string {
		return \OmniWP\Frontend\AccountForm::edit_url( 'profile' );
	}
}
