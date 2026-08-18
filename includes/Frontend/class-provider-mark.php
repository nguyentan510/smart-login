<?php
/**
 * The one place a provider's mark is turned into markup.
 *
 * `icon_svg()` has been on LoginProviderInterface since Phase 12 and every
 * shipped provider has implemented it since. Exactly one caller existed —
 * templates/form-auth.php — so the sign-in screen wore each brand's own mark
 * while the account card, which names the same brands again, drew nothing at
 * all.
 *
 * Two entry points, because the two callers hold different things. The sign-in
 * screen is iterating provider objects; the account card is iterating identity
 * records, which carry a channel id and no object. Resolving the second into the
 * first is the whole job.
 *
 * **`get()` and not `available()`.** An account can hold a Google identity on a
 * site where Google has since been switched off, and that row still has to draw
 * its mark — the identity did not stop existing when the setting changed.
 *
 * **And not a key on `IdentityLinkService::linked()`.** That payload also serves
 * the REST route; markup does not belong in it.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Auth\Providers\LoginProviderInterface;
use OmniWP\Auth\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class ProviderMark {

	/**
	 * The mark for a channel id, or '' when no provider claims it.
	 *
	 * `linked()` returns `email` and `phone` rows alongside the federated ones,
	 * so this is called with channels that are not providers as a matter of
	 * course. '' is the answer, not an error.
	 */
	public static function svg( string $channel ): string {
		$provider = ( new ProviderRegistry() )->get( $channel );

		return null === $provider ? '' : self::for_provider( $provider );
	}

	/**
	 * The mark for a provider already in hand.
	 */
	public static function for_provider( LoginProviderInterface $provider ): string {
		/**
		 * Replace a provider's mark with an official brand asset.
		 *
		 * The supported way to drop one in, for a brand whose official artwork
		 * ships as a file this plugin cannot redistribute. Applied here rather
		 * than at each call site, so a site that filters it gets the same mark
		 * on the sign-in screen and in the account card.
		 *
		 * @param string $svg
		 * @param string $provider_id
		 */
		return (string) apply_filters( 'omniwp_provider_icon_svg', $provider->icon_svg(), $provider->id() );
	}

	/**
	 * Echo the mark in the box the stylesheet sizes, or nothing.
	 *
	 * Not escaped, because it is markup by definition. It comes from plugin code
	 * or from site code through the filter above, never from a request.
	 */
	public static function output( string $channel ): void {
		self::output_svg( self::svg( $channel ) );
	}

	public static function output_for_provider( LoginProviderInterface $provider ): void {
		self::output_svg( self::for_provider( $provider ) );
	}

	private static function output_svg( string $svg ): void {
		if ( '' === $svg ) {
			return;
		}

		echo '<span class="sl-provider-icon" aria-hidden="true">' . $svg . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
