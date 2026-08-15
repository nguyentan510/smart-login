<?php
/**
 * Smart Account Hub Router & WooCommerce My-Account Interception.
 *
 * Controls how customer portal traffic is routed:
 *  - 'woo_override': Directly renders OmniWP Account Hub on WooCommerce's /my-account/
 *  - 'custom_page': Redirects /my-account/ traffic to a dedicated custom page
 *  - 'disabled': Leaves default WooCommerce My Account behavior untouched
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class AccountRouter {

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 5 );
		add_filter( 'woocommerce_get_myaccount_page_permalink', array( $this, 'filter_myaccount_page_permalink' ), 10, 1 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'filter_endpoint_url' ), 10, 4 );
	}

	/**
	 * Get the currently configured portal mode.
	 *
	 * @return string 'woo_override'|'custom_page'|'disabled'
	 */
	public static function portal_mode(): string {
		return (string) Settings::get( 'account.portal_mode', 'woo_override' );
	}

	/**
	 * Get the resolved custom Account Hub page URL, or empty string.
	 */
	public static function custom_page_url(): string {
		$configured = trim( (string) Settings::get( 'account.custom_page_url', '' ) );
		if ( '' !== $configured ) {
			return $configured;
		}

		return AccountForm::shortcode_page_url();
	}

	/**
	 * Intercept /my-account/ requests and perform 301 redirection if custom page mode is active.
	 */
	public function handle_template_redirect(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$mode = self::portal_mode();

		if ( 'custom_page' !== $mode || ! Settings::is_on( 'account.redirect_woo' ) ) {
			return;
		}

		$target_base = self::custom_page_url();
		if ( '' === $target_base ) {
			return;
		}

		// Avoid redirect loops if the custom page happens to be the WC account page.
		$custom_page_id = url_to_postid( $target_base );
		if ( $custom_page_id > 0 && is_page( $custom_page_id ) ) {
			return;
		}

		// Don't intercept customer-logout: let WooCommerce handle its nonce and session termination.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'customer-logout' ) ) {
			return;
		}

		$target_url = $target_base;

		if ( Settings::is_on( 'account.map_deep_links' ) && function_exists( 'is_wc_endpoint_url' ) ) {
			if ( is_wc_endpoint_url( 'orders' ) ) {
				$target_url = add_query_arg( 'tab', 'orders', $target_base );
			} elseif ( is_wc_endpoint_url( 'view-order' ) ) {
				$order_id   = (int) get_query_var( 'view-order' );
				$target_url = add_query_arg(
					array(
						'tab'      => 'orders',
						'order_id' => $order_id,
					),
					$target_base
				);
			} elseif ( is_wc_endpoint_url( 'edit-address' ) ) {
				$target_url = add_query_arg( 'tab', 'address', $target_base );
			} elseif ( is_wc_endpoint_url( 'edit-account' ) ) {
				$target_url = add_query_arg( 'tab', 'profile', $target_base );
			}
		}

		// If user is guest and on a protected screen, preserve redirect_to if needed.
		if ( ! is_user_logged_in() && ! empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$target_url = add_query_arg( 'redirect_to', rawurlencode( wp_unslash( $_GET['redirect_to'] ) ), $target_url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		wp_safe_redirect( $target_url, 301 );
		exit;
	}

	/**
	 * Filter WooCommerce My Account page permalink to point to custom page when active.
	 *
	 * @param string $permalink Default permalink.
	 * @return string
	 */
	public function filter_myaccount_page_permalink( string $permalink ): string {
		if ( 'custom_page' === self::portal_mode() ) {
			$custom_url = self::custom_page_url();
			if ( '' !== $custom_url ) {
				return $custom_url;
			}
		}

		return $permalink;
	}

	/**
	 * Filter WooCommerce endpoint URLs to point to custom page with ?tab=... when active.
	 *
	 * @param string $url       Endpoint URL.
	 * @param string $endpoint  Endpoint key.
	 * @param string $value     Endpoint value.
	 * @param string $permalink Page permalink.
	 * @return string
	 */
	public function filter_endpoint_url( string $url, string $endpoint, string $value, string $permalink ): string {
		$mode = self::portal_mode();

		if ( 'disabled' === $mode || ! Settings::is_on( 'account.map_deep_links' ) ) {
			return $url;
		}

		if ( 'custom_page' === $mode ) {
			$base = self::custom_page_url();
			if ( '' === $base ) {
				return $url;
			}

			switch ( $endpoint ) {
				case 'orders':
					return add_query_arg( 'tab', 'orders', $base );

				case 'view-order':
					return add_query_arg(
						array(
							'tab'      => 'orders',
							'order_id' => $value,
						),
						$base
					);

				case 'edit-address':
					return add_query_arg( 'tab', 'address', $base );

				case 'edit-account':
					return add_query_arg( 'tab', 'profile', $base );
			}
		}

		return $url;
	}
}
