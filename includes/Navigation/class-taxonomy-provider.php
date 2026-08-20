<?php
/**
 * A navigation tree built from a taxonomy.
 *
 * One `get_terms()` for the whole tree, then the hierarchy is assembled in
 * memory. A category browser that queried per level would issue a request per
 * open panel on a store with hundreds of terms, and the reference stores have
 * hundreds of terms.
 *
 * Term images and colours are **not** read here. This plugin does not own them;
 * the sibling plugin does, and it attaches them through
 * `omniwp_navigation_term_visual`. With that plugin gone the tree still renders,
 * as text links — which is the point of joining the two by a hook instead of a
 * class name (docs/navigation.md §2).
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

defined( 'ABSPATH' ) || exit;

final class TaxonomyProvider {

	/**
	 * Build the tree for one taxonomy.
	 *
	 * @param array<string,mixed> $args `taxonomy`, `root`, `hide_empty`, `orderby`, `source`.
	 */
	public static function tree( array $args = array() ): Tree {
		$taxonomy = (string) ( $args['taxonomy'] ?? 'product_cat' );

		if ( '' === $taxonomy || ! function_exists( 'get_terms' ) ) {
			return Tree::empty();
		}

		if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
			return Tree::empty();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => (bool) ( $args['hide_empty'] ?? true ),
				'orderby'    => (string) ( $args['orderby'] ?? 'menu_order' ),
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return Tree::empty();
		}

		$visuals = self::visuals( $terms, $taxonomy );

		return Tree::of(
			self::branch(
				self::group_by_parent( $terms ),
				(int) ( $args['root'] ?? 0 ),
				$taxonomy,
				$visuals,
				(string) ( $args['source'] ?? $taxonomy )
			)
		);
	}

	/**
	 * Ask whoever owns term visuals for all of them at once.
	 *
	 * One filter call carrying every id, not one per node. The sibling plugin
	 * already exposes a primer for exactly this shape, and a per-node hook would
	 * hand it no way to use it.
	 *
	 * @param array<int,object> $terms
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function visuals( array $terms, string $taxonomy ): array {
		$ids = array();

		foreach ( $terms as $term ) {
			$ids[] = (int) ( $term->term_id ?? 0 );
		}

		/**
		 * Filter: term id => visual payload (image, colour, icon).
		 *
		 * @param array<int,array<string,mixed>> $visuals
		 * @param int[]                          $ids
		 * @param string                         $taxonomy
		 */
		$visuals = apply_filters( 'omniwp_navigation_term_visual', array(), $ids, $taxonomy );

		return is_array( $visuals ) ? $visuals : array();
	}

	/**
	 * @param array<int,object> $terms
	 *
	 * @return array<int,array<int,object>>
	 */
	private static function group_by_parent( array $terms ): array {
		$buckets = array();

		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) ) {
				continue;
			}

			$buckets[ (int) ( $term->parent ?? 0 ) ][] = $term;
		}

		return $buckets;
	}

	/**
	 * @param array<int,array<int,object>>   $buckets   Terms bucketed by parent id.
	 * @param int                            $parent_id Bucket to walk.
	 * @param string                         $taxonomy  Taxonomy the terms belong to.
	 * @param array<int,array<string,mixed>> $visuals   Term id => visual payload.
	 * @param string                         $source    Provider id.
	 *
	 * @return Node[]
	 */
	private static function branch( array $buckets, int $parent_id, string $taxonomy, array $visuals, string $source ): array {
		$nodes = array();

		foreach ( $buckets[ $parent_id ] ?? array() as $term ) {
			$id    = (int) ( $term->term_id ?? 0 );
			$count = (int) ( $term->count ?? 0 );

			$nodes[] = Node::make(
				array(
					'id'       => $taxonomy . '-' . $id,
					'type'     => 'term',
					'label'    => (string) ( $term->name ?? '' ),
					'url'      => self::link( $term, $taxonomy ),
					'visual'   => (array) ( $visuals[ $id ] ?? array() ),
					'badge'    => $count > 0 ? (string) $count : '',
					'source'   => $source,
					'meta'     => array(
						'term_id'  => $id,
						'taxonomy' => $taxonomy,
						'slug'     => (string) ( $term->slug ?? '' ),
						'count'    => $count,
					),
					'children' => self::branch( $buckets, $id, $taxonomy, $visuals, $source ),
				)
			);
		}

		return $nodes;
	}

	/**
	 * @param object $term
	 */
	private static function link( $term, string $taxonomy ): string {
		if ( ! function_exists( 'get_term_link' ) ) {
			return '';
		}

		$link = get_term_link( $term, $taxonomy );

		return is_string( $link ) ? $link : '';
	}
}
