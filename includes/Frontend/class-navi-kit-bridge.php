<?php
/**
 * What OmniWP contributes to somebody else's menu.
 *
 * Navigation left this plugin in Phase 26 and now lives in NaviKit. What stayed
 * behind is the half OmniWP actually owns — where a customer's account pages
 * are — and it is offered the same way any third party would offer it: through a
 * documented filter, with no class of NaviKit's named anywhere in this file.
 *
 * `add_filter()` on a hook nobody fires is inert, so nothing here checks whether
 * NaviKit is installed. That is the point of joining two plugins by a hook: with
 * the other one absent this costs one array entry in `$wp_filter` and changes
 * nothing.
 *
 * **Only cache-safe destinations are offered, and the exclusions are the
 * interesting part.** A navigation tree is structure, and structure is what a
 * page cache is allowed to keep. Anything that differs per visitor belongs to a
 * fragment or to the Smart Menu path, which renders through the theme's own menu
 * where it already lives:
 *
 *   The logout link carries a nonce from `wp_logout_url()`. Baked into a cached
 *   page it outlives the nonce and logs nobody out — the same class of defect as
 *   a `wp_rest` nonce in cached HTML.
 *
 *   The account button changes its label and its target with the visitor. In a
 *   cached page it would show one person's name to the next.
 *
 * The file is `class-navi-kit-bridge.php`, which looks like a typo and is not.
 * The autoloader turns every internal capital into a dash, so `NaviKitBridge`
 * resolves to `navi-kit-bridge` — and named `class-navikit-bridge.php` the class
 * simply never loads, which presents as "class not found" at the first page view
 * rather than at boot. This suite caught it; nothing else would have.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

defined( 'ABSPATH' ) || exit;

final class NaviKitBridge {

	/** The provider id this plugin claims. Prefixed, because the namespace is shared. */
	const PROVIDER = 'omniwp_account';

	public function register(): void {
		add_filter( 'navikit_navigation_providers', array( $this, 'add_provider' ) );
	}

	/**
	 * @param array<string,mixed> $providers
	 *
	 * @return array<string,mixed>
	 */
	public function add_provider( $providers ) {
		if ( ! is_array( $providers ) ) {
			return $providers;
		}

		$providers[ self::PROVIDER ] = array(
			'label'    => __( 'Trang tài khoản (OmniWP)', 'omniwp' ),
			'callback' => array( $this, 'tree' ),
			'args'     => array(),
			'supports' => array( 'devices' ),
		);

		return $providers;
	}

	/**
	 * The account destinations, as node specs.
	 *
	 * Returned as plain arrays rather than as objects of NaviKit's, so this file
	 * names no class of that plugin at all. The consumer builds its own nodes
	 * from the shape its own contract describes, and a rule in this repository
	 * checks that the shape stays a shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function nodes(): array {
		if ( ! class_exists( '\OmniWP\Frontend\AccountForm' ) ) {
			return array();
		}

		$sections = array(
			'profile'  => __( 'Hồ sơ', 'omniwp' ),
			'orders'   => __( 'Đơn hàng', 'omniwp' ),
			'address'  => __( 'Sổ địa chỉ', 'omniwp' ),
			'security' => __( 'Bảo mật', 'omniwp' ),
		);

		$nodes = array();

		foreach ( $sections as $key => $label ) {
			$url = AccountForm::edit_url( $key );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$nodes[] = array(
				'id'     => 'omniwp-account-' . $key,
				'type'   => 'link',
				'label'  => $label,
				'url'    => $url,
				'source' => self::PROVIDER,
			);
		}

		return $nodes;
	}

	/**
	 * The callback the provider row names.
	 *
	 * NaviKit hands every provider an argument array and expects a `Tree` back.
	 * Building one means naming its class, which is the single unavoidable
	 * coupling in this file — and it is confined to one line, guarded, so a
	 * version of NaviKit that renamed it fails here loudly rather than fataling
	 * a page.
	 *
	 * The argument array NaviKit passes is not read. There is nothing here to
	 * vary on — a site has one account area — and taking a parameter this method
	 * ignores would be a promise it does not keep. Adding it back the day there
	 * is something to vary is one line.
	 *
	 * @return mixed A NaviKit Tree, or null when that plugin is not present.
	 */
	public function tree() {
		if ( ! class_exists( '\NaviKit\Navigation\Tree' ) ) {
			return null;
		}

		return \NaviKit\Navigation\Tree::of( self::nodes() );
	}
}
