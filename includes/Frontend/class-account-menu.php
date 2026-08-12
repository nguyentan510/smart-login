<?php
/**
 * The account destinations, assembled exactly once.
 *
 * Every navigational surface reads this and nothing else: the header dropdown
 * today, the account sidebar and the mobile panel when they are built. Two
 * arrays would be one refactor away from disagreeing, and the request that
 * opened Phase 21 came with a screenshot showing the dropdown and the sidebar
 * side by side with the same seven items — the screen where disagreeing shows
 * most.
 *
 * **A destination is not a section.** `AccountForm::sections_meta()` names cards
 * stacked on one page; this names pages. Feeding a menu from that method would
 * mean adding a fake section to a form that does not draw it, which is the
 * statement its own doc comment refuses to make. This class does not call it.
 *
 * Assembly, in order, and the order is the design:
 *
 *   1. the pinned head — `account`, resolved through AccountForm::edit_url()
 *   2. the configured middle — settings rows, which only the site owner can know
 *   3. the pinned tail — `logout`, which cannot be configured at all
 *   4. the filter, over the assembled array
 *
 * The ends are pinned because the plugin holds information the administrator
 * does not: `edit_url()` already resolves filter -> WooCommerce -> hosting page,
 * and `wp_logout_url()` is nonced per session, so a logout typed into a settings
 * field gets the "Bạn có chắc muốn đăng xuất?" interstitial instead of signing
 * anybody out. An empty middle therefore still yields a working two-item menu:
 * a fresh install has an account menu before anybody opens Settings.
 *
 * The filter runs **last, over the whole array**, which is what makes it one
 * escape hatch for developers rather than a second configuration surface
 * competing with the settings screen. It can remove a pinned end. It cannot
 * skip validation — see normalise().
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

defined( 'ABSPATH' ) || exit;

final class AccountMenu {

	/**
	 * The pinned head.
	 *
	 * `account`, not `profile`. `profile` is a *section* key
	 * (`AccountForm::SECTIONS`), and reusing it here would undo in the
	 * vocabulary the separation this class exists to make in the code.
	 */
	const KEY_ACCOUNT = 'account';

	/** The pinned tail. Always last, never configurable. */
	const KEY_LOGOUT = 'logout';

	/**
	 * Where the configured middle is stored.
	 *
	 * Named once, here, because this class is the only reader. A template
	 * reaching past it for the raw option would be the second source of truth
	 * the whole design is arranged to prevent.
	 */
	const OPTION = 'account_menu.items';

	/**
	 * Every destination, in menu order.
	 *
	 * @param int $user_id Whose menu this is; 0 for the current visitor. Passed
	 *                     to the filter as context, and to nothing else — the
	 *                     entries themselves are the same for every member, and
	 *                     a menu that varied per user without saying so would be
	 *                     a cache bug waiting to be found.
	 *
	 * @return array<int,array{key:string,label:string,icon:string,url:string}>
	 */
	public static function items( int $user_id = 0 ): array {
		$items = array();

		// 1. Preset: Profile
		if ( (bool) \OmniWP\Settings::get( 'account_menu.preset_profile', 1 ) ) {
			$items[] = array(
				'key'   => self::KEY_ACCOUNT,
				'label' => __( 'Thông tin cá nhân', 'omniwp' ),
				'icon'  => 'user',
				'url'   => AccountForm::edit_url( 'profile' ),
			);
		}

		// 2. Preset: Orders
		if ( (bool) \OmniWP\Settings::get( 'account_menu.preset_orders', 1 ) ) {
			$items[] = array(
				'key'   => 'orders',
				'label' => __( 'Lịch sử đơn hàng', 'omniwp' ),
				'icon'  => 'box',
				'url'   => AccountForm::edit_url( 'orders' ),
			);
		}

		// 3. Preset: Vouchers
		if ( (bool) \OmniWP\Settings::get( 'account_menu.preset_vouchers', 1 ) ) {
			$items[] = array(
				'key'   => 'vouchers',
				'label' => __( 'Mã giảm giá', 'omniwp' ),
				'icon'  => 'ticket',
				'url'   => AccountForm::edit_url( 'vouchers' ),
			);
		}

		// 3. Preset: Address
		if ( (bool) \OmniWP\Settings::get( 'account_menu.preset_address', 1 ) ) {
			$items[] = array(
				'key'   => 'address_book',
				'label' => __( 'Địa chỉ nhận hàng', 'omniwp' ),
				'icon'  => 'map-pin',
				'url'   => AccountForm::edit_url( 'address' ),
			);
		}

		// 4. Preset: Security
		if ( (bool) \OmniWP\Settings::get( 'account_menu.preset_security', 1 ) ) {
			$items[] = array(
				'key'   => 'security',
				'label' => __( 'Đăng nhập & Bảo mật', 'omniwp' ),
				'icon'  => 'shield',
				'url'   => AccountForm::edit_url( 'security' ),
			);
		}

		// 5. Configured custom items
		$custom = self::configured();
		if ( ! empty( $custom ) ) {
			$items = array_merge( $items, $custom );
		}

		// 6. Preset: Logout
		if ( (bool) \OmniWP\Settings::get( 'account_menu.preset_logout', 1 ) ) {
			$items[] = array(
				'key'   => self::KEY_LOGOUT,
				'label' => __( 'Đăng xuất', 'omniwp' ),
				'icon'  => 'log-out',
				'url'   => wp_logout_url( home_url( '/' ) ),
			);
		}

		/**
		 * The assembled account menu, immediately before it is normalised.
		 *
		 * Runs over the finished array rather than over the configured middle,
		 * so a site can remove a pinned entry — which is the only way to remove
		 * one, deliberately. Whatever this returns is validated again: a filter
		 * is a reason to change the list, not a reason to stop checking it.
		 *
		 * @param array $items   Each entry: key, label, icon, url.
		 * @param int   $user_id 0 for the current visitor.
		 */
		$items = (array) apply_filters( 'omniwp_account_menu', $items, $user_id );

		return self::normalise( $items );
	}

	/**
	 * Custom extra rows added by the site administrator.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function configured(): array {
		$rows = \OmniWP\Settings::get( self::OPTION, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Drop what cannot be rendered, and make what is left the declared shape.
	 *
	 * Four refusals, each with a reason a caller could act on:
	 *
	 *   - no key, label or url: there is nothing to draw or nowhere to go
	 *   - an empty url specifically: `AccountForm::edit_url()` returns '' on a
	 *     site with no account page, and a menu item that leads nowhere is worse
	 *     than one that is absent
	 *   - a key outside [a-z0-9_-]: keys are matched by later surfaces to decide
	 *     which item is active, and one carrying markup or a space is a key that
	 *     will be compared wrongly
	 *   - an icon outside the set: folded to the fallback by IconSet, so a name
	 *     that came from a form cannot reach the DOM
	 *
	 * The returned entries carry exactly four keys. A fifth would be a field
	 * nothing reads today and something will misread later; the spec fixes the
	 * shape and 21.0's rule 6 fails the day it grows.
	 *
	 * @param array $items
	 * @return array<int,array{key:string,label:string,icon:string,url:string}>
	 */
	private static function normalise( array $items ): array {
		$clean = array();
		$seen  = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = trim( (string) ( $item['label'] ?? '' ) );
			$url   = trim( (string) ( $item['url'] ?? '' ) );
			$key   = (string) ( $item['key'] ?? '' );

			if ( '' === $key && '' !== $label ) {
				$key = sanitize_title( $label );
				$key = (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
				if ( '' === $key ) {
					$key = 'item-' . ( (int) $index + 1 );
				}
			}

			if ( '' === $label || '' === $url || ! preg_match( '/^[a-z0-9_-]+$/', $key ) ) {
				continue;
			}

			// A duplicate key would make "which item is active" ambiguous for
			// every surface that later asks.
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$clean[] = array(
				'key'   => $key,
				'label' => $label,
				'icon'  => IconSet::sanitize( (string) ( $item['icon'] ?? '' ) ),
				'url'   => $url,
			);
		}

		return $clean;
	}
}
