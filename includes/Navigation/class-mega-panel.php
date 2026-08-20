<?php
/**
 * The panel a top-level menu item opens.
 *
 * One render, two shapes. On a wide screen it is a dropdown with a rail of F1
 * down the left and that entry's F2 columns beside it; on a narrow one the same
 * markup is a full-screen sheet with the rail down the left and a grid of F2 to
 * its right. That is not a compromise between the two — it is what the reference
 * stores all converged on, and it is why the desktop panel and the mobile
 * category browser are one feature rather than two (docs/navigation.md §0).
 *
 * The markup renders on every request and CSS decides its shape. Nothing here
 * asks what device this is, because the page it produces may be cached and
 * served to the other kind (§3.4).
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

use OmniWP\Admin\SmartMenuFields;
use OmniWP\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

final class MegaPanel {

	/**
	 * Runs after SmartMenuRenderer, which sits on the same filter at 10.
	 *
	 * Order matters and is not incidental: that renderer may replace the whole
	 * link with the account button, and a panel appended before it would be
	 * thrown away with the markup it was attached to.
	 */
	const PRIORITY = 20;

	public function register(): void {
		add_filter( 'walker_nav_menu_start_el', array( $this, 'append_panel' ), self::PRIORITY, 3 );
		add_filter( 'nav_menu_css_class', array( $this, 'item_classes' ), 10, 2 );
	}

	/**
	 * The provider and branch a menu item opens, or null for the vast majority.
	 *
	 * @return array{provider:string,root:string}|null
	 */
	public static function panel_for( int $item_id ): ?array {
		if ( $item_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return null;
		}

		$provider = (string) get_post_meta( $item_id, SmartMenuFields::META_PANEL, true );

		if ( '' === $provider || ! Catalog::has( $provider ) ) {
			return null;
		}

		return array(
			'provider' => $provider,
			'root'     => (string) get_post_meta( $item_id, SmartMenuFields::META_PANEL_ROOT, true ),
		);
	}

	/**
	 * The tree an item shows, already narrowed to its branch.
	 *
	 * A root id that no longer resolves yields the whole tree rather than an
	 * empty one. Term ids move when a store reorganises its catalog, and a menu
	 * item that silently shows nothing is harder to notice than one showing too
	 * much.
	 */
	public static function tree_for( int $item_id ): Tree {
		$panel = self::panel_for( $item_id );

		if ( null === $panel ) {
			return Tree::empty();
		}

		$tree = Catalog::tree( $panel['provider'] );

		if ( '' === $panel['root'] ) {
			return $tree;
		}

		$node = $tree->find( $panel['root'] );

		return null === $node ? $tree : Tree::of( $node->children() );
	}

	/**
	 * Mark the `<li>` so CSS can position the panel against it.
	 *
	 * @param string[] $classes
	 * @param object   $item
	 *
	 * @return string[]
	 */
	public function item_classes( $classes, $item ) {
		if ( ! is_array( $classes ) || ! is_object( $item ) ) {
			return $classes;
		}

		if ( null !== self::panel_for( (int) ( $item->ID ?? 0 ) ) ) {
			$classes[] = 'ow-has-mega';
		}

		return $classes;
	}

	/**
	 * Put the panel next to the link, never inside it.
	 *
	 * The link keeps its href and keeps working: a store owner points the F1 item
	 * at the category archive, and without JavaScript — or before it loads — the
	 * item is still a link that goes somewhere. The panel is an enhancement on
	 * top, which is also why the toggle is a separate button rather than the link
	 * itself.
	 *
	 * @param string $item_output
	 * @param object $item
	 * @param int    $depth
	 *
	 * @return string
	 */
	public function append_panel( $item_output, $item, $depth ) {
		if ( ! is_string( $item_output ) || ! is_object( $item ) || (int) $depth > 0 ) {
			return $item_output;
		}

		$item_id = (int) ( $item->ID ?? 0 );

		if ( null === self::panel_for( $item_id ) ) {
			return $item_output;
		}

		$tree = self::tree_for( $item_id );

		if ( $tree->is_empty() ) {
			return $item_output;
		}

		Assets::enqueue();

		$panel = TemplateLoader::render(
			'navigation/mega-panel',
			array(
				'tree'     => $tree,
				'panel_id' => 'ow-mega-' . $item_id,
				'label'    => (string) ( $item->title ?? '' ),
			)
		);

		if ( '' === trim( $panel ) ) {
			return $item_output;
		}

		return $item_output . self::toggle( 'ow-mega-' . $item_id, (string) ( $item->title ?? '' ) ) . $panel;
	}

	/**
	 * One node as a link, at any level.
	 *
	 * One function for all three levels on purpose: an F3 term is the same thing
	 * as an F1 term with a different class, and the moment they are two functions
	 * they start to drift. It lives on the class rather than in the template
	 * because a template that declares a global function declares it again on
	 * every render path that includes it.
	 *
	 * @param Node   $node     The node to draw.
	 * @param string $modifier BEM modifier naming the level.
	 */
	public static function link( Node $node, string $modifier ): string {
		$classes = 'ow-mega__link ow-mega__link--' . $modifier;
		$device  = $node->device_class();

		if ( '' !== $device ) {
			$classes .= ' ' . $device;
		}

		$image = (string) ( $node->visual()['image'] ?? '' );
		$media = '' === $image
			? ''
			: '<span class="ow-mega__thumb"><img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async" /></span>';

		return sprintf(
			'<a class="%1$s" href="%2$s">%3$s<span class="ow-mega__text">%4$s</span></a>',
			esc_attr( $classes ),
			esc_url( $node->url() ),
			$media,
			esc_html( $node->label() )
		);
	}

	/**
	 * The control that opens the panel.
	 *
	 * Separate from the link because the link has somewhere to go. Merging the
	 * two would mean a top-level menu item that no longer navigates, which is a
	 * regression a theme's own menu never had.
	 */
	private static function toggle( string $panel_id, string $label ): string {
		return sprintf(
			'<button type="button" class="ow-mega__toggle" aria-expanded="false" aria-controls="%1$s" aria-label="%2$s" data-ow-mega-toggle="%1$s"><span class="ow-mega__chevron" aria-hidden="true"></span></button>',
			esc_attr( $panel_id ),
			esc_attr(
				sprintf(
					/* translators: %s: menu item label. */
					__( 'Mở bảng danh mục %s', 'omniwp' ),
					$label
				)
			)
		);
	}
}
