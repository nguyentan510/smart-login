<?php
/**
 * A navigation tree built from a WordPress nav menu.
 *
 * This is the provider that must never need anything else installed. With
 * WooCommerce gone and the sibling plugin deactivated, a store's menu still has
 * to render, and this is what renders it.
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

use OmniWP\Admin\SmartMenuFields;

defined( 'ABSPATH' ) || exit;

final class MenuProvider {

	/**
	 * Build the tree for one menu.
	 *
	 * @param array<string,mixed> $args `menu` (id, slug or name), `source`.
	 */
	public static function tree( array $args = array() ): Tree {
		$menu = $args['menu'] ?? 0;

		if ( ! function_exists( 'wp_get_nav_menu_items' ) || ( ! $menu && 0 !== $menu ) ) {
			return Tree::empty();
		}

		$items = wp_get_nav_menu_items( $menu );

		if ( ! is_array( $items ) || array() === $items ) {
			return Tree::empty();
		}

		return Tree::of( self::branch( self::group_by_parent( $items ), 0, (string) ( $args['source'] ?? 'wp_menu' ) ) );
	}

	/**
	 * Menu items bucketed by the id of their parent.
	 *
	 * One pass, not a query per level: `wp_get_nav_menu_items()` already returns
	 * the whole menu flat, and walking it per parent would turn a menu of two
	 * hundred items into two hundred scans.
	 *
	 * @param array<int,object> $items
	 *
	 * @return array<int,array<int,object>>
	 */
	private static function group_by_parent( array $items ): array {
		$buckets = array();

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$parent_id = (int) ( $item->menu_item_parent ?? 0 );

			$buckets[ $parent_id ][] = $item;
		}

		return $buckets;
	}

	/**
	 * @param array<int,array<int,object>> $buckets   Items bucketed by parent id.
	 * @param int                          $parent_id Bucket to walk.
	 * @param string                       $source    Provider id.
	 *
	 * @return Node[]
	 */
	private static function branch( array $buckets, int $parent_id, string $source ): array {
		$nodes = array();

		foreach ( $buckets[ $parent_id ] ?? array() as $item ) {
			$id = (int) ( $item->ID ?? 0 );

			$nodes[] = Node::make(
				array(
					'id'       => 'menu-' . $id,
					'type'     => 'link',
					'label'    => (string) ( $item->title ?? '' ),
					'url'      => (string) ( $item->url ?? '' ),
					'devices'  => self::devices_for( $id ),
					'source'   => $source,
					'meta'     => array(
						'menu_item_id' => $id,
						'classes'      => (array) ( $item->classes ?? array() ),
					),
					'children' => self::branch( $buckets, $id, $source ),
				)
			);
		}

		return $nodes;
	}

	/**
	 * The device axis stored on a nav menu item.
	 *
	 * Absent until 25.5 ships the control, and absent means `all` — a menu saved
	 * before that field existed behaves as it always did. Node::make() normalises
	 * anything it does not recognise, so a stale value cannot fatal a live menu.
	 */
	private static function devices_for( int $item_id ): string {
		if ( $item_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return 'all';
		}

		return (string) get_post_meta( $item_id, SmartMenuFields::META_DEVICES, true );
	}
}
