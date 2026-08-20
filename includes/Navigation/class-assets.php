<?php
/**
 * The one stylesheet and the one script the navigation surfaces share.
 *
 * Both the dock and the mega panel need them, and both discover they need them
 * at render time rather than at `wp_enqueue_scripts` — a panel is attached while
 * the theme walks its menu, which on most themes is after that hook has run.
 * WordPress prints late styles in the footer, so this works; registering here
 * and enqueueing on demand is what keeps a page with neither surface from
 * carrying either file.
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

use OmniWP\Frontend\Assets as FrontendAssets;

defined( 'ABSPATH' ) || exit;

final class Assets {

	const HANDLE = 'omniwp-navigation';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets(): void {
		/*
		 * The stylesheet depends on the tokens file, because that is where
		 * --ow-layout lives and the script reads it. A navigation surface without
		 * that token would report the wide layout on every screen.
		 */
		wp_register_style(
			self::HANDLE,
			OMNIWP_URL . 'assets/css/omniwp-navigation.css',
			array( FrontendAssets::TOKENS_HANDLE ),
			OMNIWP_VERSION
		);

		wp_register_script(
			self::HANDLE,
			OMNIWP_URL . 'assets/js/omniwp-navigation.js',
			array(),
			OMNIWP_VERSION,
			true
		);
	}

	/**
	 * Ask for both files.
	 *
	 * Safe to call more than once and safe to call late; both are what a render
	 * time enqueue needs to be.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}
}
