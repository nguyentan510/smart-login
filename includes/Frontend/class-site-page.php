<?php
/**
 * Which page on this site hosts one of the plugin's shortcodes.
 *
 * `AccountForm::shortcode_page_url()` has done this for `[smart_account]` since
 * Phase 8. P3 needs the same answer for `[smart_login]` — the security card has
 * told people to "go to the login screen" since 14.3 without being able to link
 * there, and that deferral is written into
 * `templates/partials/account/password.php`.
 *
 * One resolver rather than two copies of a LIKE query and a cached option.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

defined( 'ABSPATH' ) || exit;

final class SitePage {

	/**
	 * The first published page containing any of these shortcodes, or ''.
	 *
	 * Cached in an option because a site's page structure changes about as often
	 * as its theme does, and the alternative is a `LIKE` on `post_content` for
	 * every notice that wants to link somewhere.
	 *
	 * `0` is cached as readily as a page id: "there is no such page" is an answer
	 * worth remembering, and it is the common one.
	 *
	 * @param string[] $shortcodes Tag names, without brackets.
	 * @param string   $option     Where the resolved id is remembered.
	 */
	public static function url( array $shortcodes, string $option ): string {
		$cached = get_option( $option, null );

		if ( null !== $cached && '' !== $cached ) {
			$url = (int) $cached > 0 ? get_permalink( (int) $cached ) : '';

			if ( $url || 0 === (int) $cached ) {
				return (string) $url;
			}
		}

		$page_id = 0;

		foreach ( $shortcodes as $shortcode ) {
			$pages = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'numberposts'      => 1,
					's'                => '[' . $shortcode,
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);

			if ( $pages ) {
				$page_id = (int) $pages[0];
				break;
			}
		}

		update_option( $option, $page_id, false );

		return $page_id ? (string) get_permalink( $page_id ) : '';
	}
}
