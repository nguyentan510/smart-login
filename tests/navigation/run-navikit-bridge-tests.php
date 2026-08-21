<?php
/**
 * What is left of navigation in this plugin, and what must not be.
 *
 * Phase 25 built a navigation module here — a tree model, a mega panel, a mobile
 * sheet and a bottom dock, around 1,700 lines. Phase 26 moved all of it into
 * NaviKit, which ships to sites that run neither this plugin nor its sibling.
 *
 * Two things are checked, and the first matters more than the second: that the
 * module is **gone**, not merely unused, and that what replaced it names no
 * class of NaviKit's outside a single guarded line. A plugin that reaches past a
 * public API is a plugin that will break when that API's insides change, and
 * this repository is the first consumer of that API — which is the whole reason
 * for moving it here rather than learning it from a customer.
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Frontend\NaviKitBridge;

/**
 * Report a list of offenders, or count a pass when it is empty.
 *
 * `ow_forbid_pattern()` does this for a regex over PHP sources; these rules
 * gather their offenders by walking a registry and a node list, so they need the
 * reporting without the scanner.
 *
 * @param string[] $offenders
 */
function ow_nav_bridge_offenders( string $label, array $offenders, string $hint = '' ): void {
	if ( ! $offenders ) {
		++$GLOBALS['ow_harness']['passed'];
		return;
	}

	++$GLOBALS['ow_harness']['failed'];
	printf( "  FAIL     %s\n", $label );

	if ( '' !== $hint ) {
		printf( "           %s\n", $hint );
	}

	foreach ( array_slice( $offenders, 0, 12 ) as $offender ) {
		printf( "           → %s\n", $offender );
	}
}

ow_section( 'Rule 1 — the navigation module is gone, not just unused' );

/*
 * Deleted rather than left dormant. Code that is present and unreachable is the
 * defect class this project has recorded repeatedly under other names: a
 * stylesheet nothing emits, a setting nothing reads, five scripts nothing loads.
 * The next person cannot tell dormant from load-bearing.
 */
$ow_ghosts = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 === strpos( $ow_relative, 'includes/Navigation/' ) || 0 === strpos( $ow_relative, 'templates/navigation/' ) ) {
		$ow_ghosts[] = $ow_relative;
	}
}

ow_nav_bridge_offenders( 'No file of the old navigation module survives', $ow_ghosts );

foreach ( array( 'assets/css/omniwp-navigation.css', 'assets/js/omniwp-navigation.js' ) as $ow_asset ) {
	ow_assert( 'Its assets are gone too: ' . $ow_asset, '' === ow_source( $ow_asset ) );
}

ow_assert(
	'And nothing still tries to construct it',
	! class_exists( '\OmniWP\Navigation\Dock' ) && ! class_exists( '\OmniWP\Navigation\MegaPanel' ),
	'A class that autoloads from a directory that no longer exists is a fatal waiting for a page view.'
);

ow_section( 'Rule 2 — the settings it owned went with it' );

$ow_dead_nav_rows = array();

foreach ( array_keys( \OmniWP\FieldRegistry::all() ) as $ow_path ) {
	if ( 0 === strpos( $ow_path, 'navigation.' ) ) {
		$ow_dead_nav_rows[] = $ow_path;
	}
}

ow_nav_bridge_offenders(
	'No navigation setting is still declared',
	$ow_dead_nav_rows,
	'A control for a feature that left is a control that lies to whoever sets it.'
);

ow_assert( 'And the tab that drew them is gone', ! isset( \OmniWP\FieldRegistry::tabs()['navigation'] ) );

ow_section( 'Rule 3 — the bridge joins by hook, never by class' );

/*
 * The single unavoidable coupling is building the Tree that NaviKit's contract
 * asks a provider to return. It is confined to one guarded line, and everything
 * else this file offers is plain arrays — so a version of NaviKit that renamed
 * its insides fails here loudly instead of fataling a page.
 */
$ow_bridge_source = ow_source( 'includes/Frontend/class-navi-kit-bridge.php' );

ow_assert( 'The bridge exists', '' !== $ow_bridge_source );
ow_assert(
	'And its file name matches what the autoloader will look for',
	class_exists( '\OmniWP\Frontend\NaviKitBridge' ),
	'Every internal capital becomes a dash: NaviKitBridge resolves to navi-kit-bridge.'
);
/*
 * Twice, and the two are one piece of reasoning: the guard, and the call it
 * guards. Anything past that pair would be this plugin reaching into another
 * one's insides.
 */
ow_check( 'It names a NaviKit class exactly twice', 2, substr_count( $ow_bridge_source, 'NaviKit\\Navigation\\Tree' ) );
ow_assert( 'And the call is guarded', false !== strpos( $ow_bridge_source, "class_exists( '\\NaviKit\\Navigation\\Tree' )" ) );
ow_assert( 'It registers through the documented filter', false !== strpos( $ow_bridge_source, "'navikit_navigation_providers'" ) );

$ow_bridge = new NaviKitBridge();

ow_check( 'A non-array filter value is passed through untouched', 'not an array', $ow_bridge->add_provider( 'not an array' ) );

$ow_providers = $ow_bridge->add_provider( array() );

ow_assert( 'The provider registers under a prefixed id', isset( $ow_providers['omniwp_account'] ) );
ow_check( 'And names a callable', true, is_callable( $ow_providers['omniwp_account']['callback'] ) );

ow_section( 'Rule 4 — with NaviKit absent, nothing here does anything' );

/*
 * `add_filter()` on a hook nobody fires is inert, so the bridge deliberately
 * does not ask whether NaviKit is installed. What it must do is survive being
 * called anyway.
 */
ow_assert(
	'The tree callback answers null rather than fataling',
	null === $ow_bridge->tree(),
	'NaviKit is not loaded in this suite, which is exactly the case being tested.'
);

ow_section( 'Rule 5 — only cache-safe destinations cross the bridge' );

/*
 * A navigation tree is structure, and structure is what a page cache is allowed
 * to keep. Two things were deliberately left out, and the reasons are the
 * finding of this phase:
 *
 *   The logout link carries a nonce from wp_logout_url(). In a cached page it
 *   outlives the nonce and logs nobody out.
 *
 *   The account button changes label and target with the visitor. In a cached
 *   page it shows one person's name to the next.
 *
 * Both stay on the Smart Menu path, which renders through the theme's own menu
 * where they already live.
 */
$ow_offered = NaviKitBridge::nodes();

$ow_unsafe = array();

foreach ( $ow_offered as $ow_node ) {
	if ( false !== strpos( (string) ( $ow_node['url'] ?? '' ), '_wpnonce' ) || false !== strpos( (string) ( $ow_node['url'] ?? '' ), 'action=logout' ) ) {
		$ow_unsafe[] = (string) $ow_node['id'];
	}
}

ow_nav_bridge_offenders(
	'No node carries a nonce into a page that can be cached',
	$ow_unsafe,
	'A nonce baked into cached HTML outlives the cache entry. The logout link stays on the Smart Menu path.'
);

$ow_types = array_unique( array_column( $ow_offered, 'type' ) );

ow_check( 'Every node offered is a plain link', array( 'link' ), array_values( $ow_types ) );
ow_assert( 'And each is a plain array, not an object of NaviKit\'s', array() === array_filter( $ow_offered, 'is_object' ) );

ow_summary( 'NaviKit bridge' );
