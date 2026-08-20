<?php
/**
 * The mobile bottom dock.
 *
 * Five slots at most, anchored to the bottom edge of a phone screen. It is the
 * projection a store owner sees first, and the one that had to answer for the
 * edge it lands on: docs/navigation.md §1.4 counted six elements already
 * competing for it, two of which overlap on a live product page today.
 *
 * Two properties are load bearing and both are enforced by the Phase 25 suite:
 *
 *   The dock publishes `--ow-dock-height`, and everything else anchored to that
 *   edge stacks on it (§3.6). When the dock is off the token is absent and every
 *   consumer falls back to what it did before.
 *
 *   The cart badge renders **empty** and is filled by WooCommerce's own fragment
 *   refresh (§3.5). A number rendered here would be baked into the cached page
 *   and served to the next visitor, which is the defect §1.3 measured.
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

use OmniWP\Frontend\AccountForm;
use OmniWP\Frontend\IconSet;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class Dock {

	/** Five is the cap every reference store settled on, and the cap a thumb settles on. */
	const MAX_SLOTS = 5;

	/**
	 * The class the badge carries, and the key its fragment is registered under.
	 *
	 * One constant because the two must be the same string: WooCommerce replaces
	 * the element its fragment key selects, so a rename in one place and not the
	 * other leaves a badge that never updates and no error anywhere.
	 */
	const BADGE_SELECTOR = '.ow-dock__badge';

	public function register(): void {
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragment' ) );
	}

	/**
	 * What a slot can be.
	 *
	 * `url` is a callable rather than a string because three of the five answers
	 * depend on WooCommerce pages or the visitor's session, and resolving those
	 * at class-load time answers them before WordPress knows.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function slots(): array {
		return array(
			'home'       => array(
				'label' => __( 'Trang chủ', 'omniwp' ),
				'icon'  => 'home',
				'url'   => static function (): string {
					return home_url( '/' );
				},
			),
			'categories' => array(
				'label' => __( 'Danh mục', 'omniwp' ),
				'icon'  => 'grid',

				/*
				 * A link to the shop archive until 25.3 ships the two-pane sheet,
				 * at which point this slot opens that sheet instead. The link is
				 * what it degrades to when the sheet cannot render, so it stays
				 * either way rather than becoming a button that needs JS to mean
				 * anything.
				 */
				'url'   => static function (): string {
					return self::shop_url();
				},
			),
			'search'     => array(
				'label' => __( 'Tìm kiếm', 'omniwp' ),
				'icon'  => 'search',
				'url'   => static function (): string {
					return home_url( '/?s=' );
				},
			),
			'cart'       => array(
				'label' => __( 'Giỏ hàng', 'omniwp' ),
				'icon'  => 'cart',
				'badge' => true,
				'url'   => static function (): string {
					return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
				},
			),
			'account'    => array(
				'label' => __( 'Tài khoản', 'omniwp' ),
				'icon'  => 'user',
				'url'   => static function (): string {
					return AccountForm::edit_url();
				},
			),
		);
	}

	private static function shop_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );

			if ( is_string( $shop ) && '' !== $shop ) {
				return $shop;
			}
		}

		return home_url( '/' );
	}

	public static function is_enabled(): bool {
		return Settings::is_on( 'navigation.dock_enabled' );
	}

	/**
	 * The slots this store asked for, in the order it asked for them.
	 *
	 * Unknown names are dropped rather than rejected, and duplicates collapse.
	 * The stored value is free text, so it is treated as a request rather than
	 * as an instruction — a typo should cost one icon, not the whole bar.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function resolved_slots(): array {
		$catalog = self::slots();
		$asked   = explode( ',', (string) Settings::get( 'navigation.dock_slots', '' ) );
		$chosen  = array();

		foreach ( $asked as $name ) {
			$name = trim( $name );

			if ( '' === $name || ! isset( $catalog[ $name ] ) || isset( $chosen[ $name ] ) ) {
				continue;
			}

			$chosen[ $name ] = $catalog[ $name ] + array( 'name' => $name );

			if ( count( $chosen ) >= self::MAX_SLOTS ) {
				break;
			}
		}

		return array_values( $chosen );
	}

	/**
	 * Print the dock.
	 *
	 * Not on the checkout, for the reason the slide cart already gives at
	 * `SlideCart::render_drawer():117`: a bar full of ways to leave is friction
	 * on the one page where leaving is the failure.
	 */
	public function render(): void {
		if ( is_admin() || ! self::is_enabled() ) {
			return;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}

		$slots = self::resolved_slots();

		if ( array() === $slots ) {
			return;
		}

		Assets::enqueue();

		/*
		 * WooCommerce's own fragment refresh, which is what fills the badge. It
		 * carries its own nonce-free endpoint and its own cache handling, so the
		 * dock needs neither — and a badge whose script is missing stays empty
		 * rather than wrong.
		 */
		if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}

		TemplateLoader::output(
			'navigation/dock',
			array(
				'slots' => $slots,
			)
		);
	}

	/**
	 * The badge, as WooCommerce refreshes it.
	 *
	 * @param array<string,string> $fragments
	 *
	 * @return array<string,string>
	 */
	public function cart_fragment( $fragments ) {
		if ( ! is_array( $fragments ) || ! self::is_enabled() ) {
			return $fragments;
		}

		/*
		 * The cart read lives inside this callback and nowhere else. A fragment
		 * response is never cached, which is the whole reason the badge goes
		 * through one; the Phase 25 rule reads the enclosing function to tell
		 * that shape from a page-render path, so moving this line up into a
		 * helper would make the rule fire and be right to.
		 */
		$count = function_exists( 'WC' ) && WC()->cart
			? (int) WC()->cart->get_cart_contents_count()
			: 0;

		$fragments[ self::BADGE_SELECTOR ] = self::badge_markup( $count );

		return $fragments;
	}

	/**
	 * The badge element.
	 *
	 * `$count` of zero renders an empty, hidden badge rather than a `0`, so the
	 * markup printed into a cacheable page and the markup a fragment replaces it
	 * with are the same shape.
	 */
	public static function badge_markup( int $count ): string {
		return sprintf(
			'<span class="ow-dock__badge" data-ow-cart-badge="1"%s>%s</span>',
			$count > 0 ? '' : ' hidden',
			$count > 0 ? esc_html( (string) $count ) : ''
		);
	}

	public static function icon( string $name ): string {
		return IconSet::get( $name );
	}
}
