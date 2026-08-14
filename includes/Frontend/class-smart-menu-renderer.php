<?php
/**
 * Frontend Renderer and Visibility Filter for Smart Menu items.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Admin\SmartMenuFields;

defined( 'ABSPATH' ) || exit;

final class SmartMenuRenderer {

	public function register(): void {
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'setup_item' ) );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_objects' ), 10, 2 );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'filter_menu_el' ), 10, 4 );
	}

	/**
	 * Setup menu item properties based on Smart Menu preset URL anchors.
	 *
	 * @param object $item Nav menu item object.
	 * @return object
	 */
	public function setup_item( $item ) {
		if ( ! is_object( $item ) || empty( $item->url ) ) {
			return $item;
		}

		$url = (string) $item->url;

		if ( false === strpos( $url, '#smart-' ) && false === strpos( $url, '#omniwp' ) ) {
			return $item;
		}

		if ( ! is_array( $item->classes ) ) {
			$item->classes = array();
		}

		$item->classes[] = 'sl-smart-menu-item';

		// In Admin UI, preserve the anchor identifier so the admin can see and save #smart-*
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return $item;
		}

		$account_url = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url() : '';

		if ( '#smart-button' === $url || strpos( $url, '#smart-button' ) !== false ) {
			$item->classes[] = 'sl-button-item';
		} elseif ( '#smart-logout' === $url || strpos( $url, '#smart-logout' ) !== false ) {
			$item->url       = function_exists( 'wp_logout_url' ) ? wp_logout_url( home_url( '/' ) ) : '#';
			$item->classes[] = 'sl-logout-item';
		} elseif ( '#omniwp' === $url || strpos( $url, '#omniwp' ) !== false ) {
			$item->url       = '#';
			$item->classes[] = 'sl-login-trigger';
			$item->classes[] = 'sl-login-item';
		} elseif ( '#smart-auth-switcher' === $url || strpos( $url, '#smart-auth-switcher' ) !== false ) {
			if ( is_user_logged_in() ) {
				$user = wp_get_current_user();
				if ( $user && $user->ID ) {
					$item->title = $user->display_name ? $user->display_name : $user->user_login;
				}
				$item->url       = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url( 'profile' ) : home_url( '/' );
				$item->classes[] = 'sl-auth-switcher-logged-in';
			} else {
				$item->title     = __( 'Đăng nhập / Đăng ký', 'omniwp' );
				$item->url       = '#';
				$item->classes[] = 'sl-login-trigger';
				$item->classes[] = 'sl-auth-switcher-logged-out';
			}
		} elseif ( '#smart-account-profile' === $url ) {
			$item->url       = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url( 'profile' ) : ( '' !== $account_url ? $account_url . '#profile' : '#' );
			$item->classes[] = 'sl-account-profile-item';
		} elseif ( '#smart-account-orders' === $url ) {
			$item->url       = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url( 'orders' ) : ( '' !== $account_url ? $account_url . '#orders' : '#' );
			$item->classes[] = 'sl-account-orders-item';
		} elseif ( '#smart-account-address' === $url ) {
			$item->url       = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url( 'address' ) : ( '' !== $account_url ? $account_url . '#address' : '#' );
			$item->classes[] = 'sl-account-address-item';
		} elseif ( '#smart-account-security' === $url || '#smart-account-contact' === $url ) {
			$item->url       = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url( 'security' ) : ( '' !== $account_url ? $account_url . '#security' : '#' );
			$item->classes[] = 'sl-account-security-item';
		} elseif ( '#smart-account' === $url || strpos( $url, '#smart-account' ) !== false ) {
			$item->url       = class_exists( '\OmniWP\Frontend\AccountForm' ) ? AccountForm::edit_url() : '#';
			$item->classes[] = 'sl-account-item';
		}

		return $item;
	}

	/**
	 * Filter menu objects for visibility right before WP prints the menu.
	 *
	 * @param array<int, object> $items List of nav menu items.
	 * @param object             $args  Menu arguments.
	 * @return array<int, object>
	 */
	public function filter_objects( array $items, $args ): array {
		$is_logged_in = is_user_logged_in();
		$filtered     = array();
		$has_ow_item  = false;

		foreach ( $items as $item ) {
			$item_id    = isset( $item->ID ) ? (int) $item->ID : 0;
			$visibility = function_exists( 'get_post_meta' ) ? \get_post_meta( $item_id, SmartMenuFields::META_VISIBILITY, true ) : '';

			if ( empty( $visibility ) && isset( $item->classes ) && is_array( $item->classes ) ) {
				if ( in_array( 'sl-visibility-guest', $item->classes, true ) ) {
					$visibility = 'guest';
				} elseif ( in_array( 'sl-visibility-logged_in', $item->classes, true ) ) {
					$visibility = 'logged_in';
				}
			}

			if ( 'guest' === $visibility && $is_logged_in ) {
				continue;
			}
			if ( 'logged_in' === $visibility && ! $is_logged_in ) {
				continue;
			}

			if ( isset( $item->url ) && strpos( (string) $item->url, '#smart-' ) !== false ) {
				$has_ow_item = true;
			}

			$filtered[] = $item;
		}

		if ( $has_ow_item ) {
			Assets::enqueue_button();
		}

		return $filtered;
	}

	/**
	 * Replace the theme link output for #smart-button or dropdown mode items to avoid nested <a> tags.
	 *
	 * @param string $item_output The menu item HTML output.
	 * @param object $item        Menu item data object.
	 * @param int    $depth       Depth of menu item.
	 * @param object $args        Nav menu arguments.
	 * @return string
	 */
	public function filter_menu_el( string $item_output, $item, int $depth, $args ): string {
		if ( ! is_object( $item ) ) {
			return $item_output;
		}

		$url     = isset( $item->url ) ? (string) $item->url : '';
		$item_id = isset( $item->ID ) ? (int) $item->ID : 0;
		$mode    = function_exists( 'get_post_meta' ) ? \get_post_meta( $item_id, SmartMenuFields::META_MODE, true ) : '';

		$is_button   = ( strpos( $url, '#smart-button' ) !== false );
		$is_dropdown = ( 'dropdown' === $mode && is_user_logged_in() );

		if ( $is_button || $is_dropdown ) {
			Assets::enqueue_button();

			if ( class_exists( '\OmniWP\Frontend\Shortcodes' ) ) {
				$button_markup = ( new Shortcodes() )->render_button( array() );
				if ( '' !== trim( $button_markup ) ) {
					return $button_markup;
				}
			}
		}

		return $item_output;
	}
}
