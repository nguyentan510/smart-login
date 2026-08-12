<?php
/**
 * Smart Menu fitness — quick deployment system for WP Nav Menus.
 *
 * Normative spec: docs/smart-menu.md. Progress: docs/refactor-plan.md Phase 23.
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Admin\SmartMenuFields;
use OmniWP\Admin\SmartMenuMetaBox;
use OmniWP\Frontend\SmartMenuRenderer;

ow_section( 'Rule 1 — Preset URLs use #smart- anchors' );
$presets = SmartMenuMetaBox::presets();
ow_check( 'Presets list is non-empty (9 presets)', 9, count( $presets ) );

$valid_anchors = true;
foreach ( $presets as $preset ) {
	if ( 0 !== strpos( $preset['url'], '#smart-' ) && 0 !== strpos( $preset['url'], '#omniwp' ) ) {
		$valid_anchors = false;
	}
}
ow_check( 'All presets use #smart- or #omniwp anchor URLs', true, $valid_anchors );

ow_section( 'Rule 2 — Metabox ID is add-omniwp-menu' );
ow_check( 'Metabox ID constant', 'add-omniwp-menu', SmartMenuMetaBox::METABOX_ID );

ow_section( 'Rule 3 & 4 — Nav menu item custom meta keys' );
ow_check( 'Visibility meta key', '_ow_smart_menu_visibility', SmartMenuFields::META_VISIBILITY );
ow_check( 'Mode meta key', '_ow_smart_menu_mode', SmartMenuFields::META_MODE );

ow_section( 'Rule 5 — Logout URL is dynamic (wp_logout_url)' );
$renderer = new SmartMenuRenderer();
$dummy    = (object) array(
	'url'     => '#smart-logout',
	'classes' => array(),
	'title'   => 'Logout',
);
$res      = $renderer->setup_item( $dummy );
ow_assert( 'Logout item URL is nonced wp_logout_url()', false !== strpos( $res->url, 'action=logout' ) );
ow_assert( 'Logout item receives sl-logout-item class', in_array( 'sl-logout-item', $res->classes, true ) );

ow_section( 'Rule 6 & 9 — Popup trigger receives sl-login-trigger' );
$login_dummy = (object) array(
	'url'     => '#omniwp',
	'classes' => array(),
	'title'   => 'Login',
);
$login_res   = $renderer->setup_item( $login_dummy );
ow_check( 'Login popup item URL is #', '#', $login_res->url );
ow_assert( 'Login popup item receives sl-login-trigger class', in_array( 'sl-login-trigger', $login_res->classes, true ) );

ow_section( 'Rule 7 & 8 — Visibility filter (guest vs logged-in)' );
$item_guest     = (object) array(
	'ID'      => 101,
	'url'     => '#omniwp',
	'classes' => array( 'sl-visibility-guest' ),
);
$item_logged_in = (object) array(
	'ID'      => 102,
	'url'     => '#smart-account',
	'classes' => array( 'sl-visibility-logged_in' ),
);

// Anonymous visitor (logged out)
$GLOBALS['ow_logged_in']       = false;
$GLOBALS['ow_current_user_id'] = 0;
$filtered_anon                 = $renderer->filter_objects( array( $item_guest, $item_logged_in ), (object) array() );
ow_check( 'Anonymous visitor sees guest item and hides logged-in item', 1, count( $filtered_anon ) );
ow_check( 'Remaining item is guest item', 101, $filtered_anon[0]->ID );

// Authenticated user (logged in)
$GLOBALS['ow_logged_in']       = true;
$GLOBALS['ow_current_user_id'] = 42;
$filtered_auth                 = $renderer->filter_objects( array( $item_guest, $item_logged_in ), (object) array() );
ow_check( 'Logged-in user sees logged-in item and hides guest item', 1, count( $filtered_auth ) );
ow_check( 'Remaining item is logged-in item', 102, $filtered_auth[0]->ID );

ow_summary( 'Smart Menu' );

