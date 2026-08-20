<?php
/**
 * Where a navigation tree can come from, declared exactly once.
 *
 * Three projections land in 25.2–25.4 — a desktop mega panel, a mobile two-pane
 * sheet, and the `Danh mục` tab of the bottom bar. All three ask this class for a
 * Tree and render what comes back. None of them asks a taxonomy or a menu
 * directly, which is what makes "the same tree in three places" true by
 * construction rather than by discipline.
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/** @var array<string,array<string,mixed>>|null */
	private static $resolved = null;

	/**
	 * Providers this plugin ships.
	 *
	 * A method rather than a constant because the rows carry callables, and
	 * because the taxonomy rows are only meaningful with WooCommerce present —
	 * asking that question at class-load time answers it too early.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function builtin(): array {
		return array(
			'wp_menu'       => array(
				'label'    => __( 'Menu của WordPress', 'omniwp' ),
				'callback' => array( MenuProvider::class, 'tree' ),
				'supports' => array( 'devices' ),
			),
			'product_cat'   => array(
				'label'    => __( 'Danh mục sản phẩm', 'omniwp' ),
				'callback' => array( TaxonomyProvider::class, 'tree' ),
				'args'     => array( 'taxonomy' => 'product_cat' ),
				'supports' => array( 'visual', 'badge' ),
			),
			'product_brand' => array(
				'label'    => __( 'Thương hiệu', 'omniwp' ),
				'callback' => array( TaxonomyProvider::class, 'tree' ),
				'args'     => array( 'taxonomy' => 'product_brand' ),
				'supports' => array( 'visual' ),
			),
			'product_tag'   => array(
				'label'    => __( 'Thẻ sản phẩm', 'omniwp' ),
				'callback' => array( TaxonomyProvider::class, 'tree' ),
				'args'     => array( 'taxonomy' => 'product_tag' ),
				'supports' => array(),
			),
		);
	}

	/**
	 * Every provider, ours plus anybody else's.
	 *
	 * `omniwp_navigation_providers` is how the sibling plugin joins in. It is a
	 * hook and not a class name on purpose (docs/navigation.md §2): with ShopKit
	 * deactivated the menu still renders, as plain links, and nothing fatals.
	 *
	 * A filtered row may **not** displace a built-in id. Array-keyed filters make
	 * an overwrite silent, and a third party quietly replacing `product_cat`
	 * would be the "two lists that must agree" defect with an extra plugin in the
	 * middle. Ours wins, and the loser is reported rather than swallowed.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function providers(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$builtin = self::builtin();
		$added   = apply_filters( 'omniwp_navigation_providers', array() );
		$merged  = $builtin;

		foreach ( (array) $added as $id => $row ) {
			$id = (string) $id;

			if ( '' === $id || isset( $builtin[ $id ] ) || ! is_array( $row ) ) {
				continue;
			}

			if ( ! isset( $row['callback'] ) || ! is_callable( $row['callback'] ) ) {
				continue;
			}

			$merged[ $id ] = array(
				'label'    => (string) ( $row['label'] ?? $id ),
				'callback' => $row['callback'],
				'args'     => (array) ( $row['args'] ?? array() ),
				'supports' => (array) ( $row['supports'] ?? array() ),
			);
		}

		self::$resolved = $merged;

		return self::$resolved;
	}

	/** Forget the resolved list. For tests, and for a settings save that adds one. */
	public static function flush(): void {
		self::$resolved = null;
	}

	public static function has( string $id ): bool {
		return isset( self::providers()[ $id ] );
	}

	/** @return string[] */
	public static function ids(): array {
		return array_keys( self::providers() );
	}

	public static function label( string $id ): string {
		return (string) ( self::providers()[ $id ]['label'] ?? '' );
	}

	/**
	 * The tree a provider yields.
	 *
	 * An unknown id gives an empty tree rather than throwing. A projection must
	 * not break because a store deactivated the plugin that registered its
	 * provider, and "no nodes" is a state every projection already has to draw.
	 *
	 * @param string              $id   Provider id.
	 * @param array<string,mixed> $args Provider arguments.
	 */
	public static function tree( string $id, array $args = array() ): Tree {
		$provider = self::providers()[ $id ] ?? null;

		if ( null === $provider ) {
			return Tree::empty();
		}

		$result = call_user_func(
			$provider['callback'],
			array_merge( (array) ( $provider['args'] ?? array() ), $args, array( 'source' => $id ) )
		);

		return $result instanceof Tree ? $result : Tree::empty();
	}
}
