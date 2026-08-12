<?php
/**
 * The button, placed in a theme's own navigation menu.
 *
 * Every other placement needs somebody to edit markup: a shortcode in a page
 * builder, a `data-` attribute, a template call. A site on a classic theme with
 * no builder has none of those, and `Shortcodes::render_button()` exists
 * precisely for people who cannot edit templates — so leaving them with no way
 * to place it is the gap this closes.
 *
 * **Off by default**, which is the opposite of the dialog's link capture, and
 * the difference is not preference. Capture intercepts a click and rewrites
 * nothing: with its script gone the page is exactly what the theme wrote.
 * Injection *adds a node to somebody else's markup*. A plugin may default to
 * being invisible; it may not default to editing the theme.
 *
 * **One renderer.** The markup comes from the shortcode, wrapped in an `<li>`.
 * Two entry points producing two markups is the drift this phase is organised
 * against, applied to placement instead of to menu contents.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class NavMenuItem {

	const SETTING = 'account_menu.nav_location';

	public function register(): void {
		add_filter( 'wp_nav_menu_items', array( $this, 'inject' ), 10, 2 );
	}

	/**
	 * The theme's registered menu locations, for the settings control.
	 *
	 * A closed list the plugin can enumerate, which is what makes this a select
	 * rather than a free-text hook name — the site owner holds only the piece
	 * the plugin cannot derive, namely which of them is the header.
	 *
	 * @return array<string,string>
	 */
	public static function locations(): array {
		if ( ! function_exists( 'get_registered_nav_menus' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) get_registered_nav_menus() as $slug => $label ) {
			$out[ (string) $slug ] = (string) $label;
		}

		return $out;
	}

	/**
	 * Append the button to the chosen location, and only that one.
	 *
	 * A stored location the current theme does not register injects nothing and
	 * warns about nothing: themes get switched, and a stale option is not an
	 * error condition. The settings screen is where an administrator finds out,
	 * because that is where the list of real locations is.
	 *
	 * @param string $items The `<li>` string the theme is about to print.
	 * @param object $args  wp_nav_menu() arguments; `theme_location` is the one
	 *                      that matters and it is absent for an unlocated menu.
	 * @return string
	 */
	public function inject( $items, $args ) {
		$wanted = (string) Settings::get( self::SETTING, '' );

		if ( '' === $wanted ) {
			return $items;
		}

		$location = isset( $args->theme_location ) ? (string) $args->theme_location : '';

		if ( $location !== $wanted || ! array_key_exists( $wanted, self::locations() ) ) {
			return $items;
		}

		$button = ( new Shortcodes() )->render_button( array() );

		if ( '' === trim( $button ) ) {
			return $items;
		}

		// The filter's contract is a string of `<li>`. Returning anything else
		// puts a stray node inside somebody's `<ul>`, which is invalid and looks
		// like the theme's fault.
		return $items . '<li class="menu-item sl-account-item">' . $button . '</li>';
	}
}
