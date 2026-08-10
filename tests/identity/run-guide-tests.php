<?php
/**
 * The guide tab — twelve rules, landed before the screen exists.
 *
 * Normative spec: docs/in-plugin-guide.md. Progress: docs/refactor-plan.md
 * Phase 22.
 *
 * Landed `spec` in 22.0, which is what that kind is for. Every rule below is
 * phrased over a screen or a catalog that does not exist on the tree it landed
 * on, so each one resolves its subject **first** and reports PENDING when it is
 * absent. A rule that passes for want of a subject states the opposite of the
 * truth — the 10.0 precedent, and 21.0 hit it in practice: three rules phrased
 * as absences passed against an empty string because nothing had rendered.
 *
 * The point of the phase is that nothing on the screen is typed twice. So most
 * of these rules are equalities between two things that already exist —
 * the registered shortcodes and the documented ones, the quoted error strings
 * and the strings in `includes/`, the tab slugs and `SettingsPage::tabs()`.
 *
 * Run with:  php tests/identity/run-guide-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../admin-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Admin\SettingsPage;
use SmartLogin\FieldRegistry;
use SmartLogin\Frontend\LoginDialog;
use SmartLogin\Frontend\Shortcodes;

/** The screen this phase adds. Absent until 22.2. */
const SL_GUIDE_CLASS = 'SmartLogin\Admin\Screens\GuideScreen';

/** The catalog 22.1 adds. Absent until then. */
const SL_CATALOG_CONST = 'SmartLogin\Frontend\Shortcodes::CATALOG';

/**
 * The screen's markup, rendered once and remembered.
 *
 * Returns null when the screen does not exist, so every rule phrased over the
 * markup reports PENDING instead of asserting against an empty string.
 */
function sl_guide_markup( bool $fresh = false ): ?array {
	static $captured = null;

	if ( ! $fresh && null !== $captured ) {
		return $captured;
	}

	if ( ! class_exists( SL_GUIDE_CLASS ) ) {
		return null;
	}

	$class    = SL_GUIDE_CLASS;
	$captured = sl_capture(
		static function () use ( $class ): void {
			( new $class() )->render();
		}
	);

	return $captured;
}

/**
 * A static method on the screen, or null when the screen has not landed.
 *
 * @return array|null
 */
function sl_guide_data( string $method ): ?array {
	if ( ! class_exists( SL_GUIDE_CLASS ) || ! method_exists( SL_GUIDE_CLASS, $method ) ) {
		return null;
	}

	return (array) call_user_func( array( SL_GUIDE_CLASS, $method ) );
}

/** The shortcode catalog, or null before 22.1. */
function sl_catalog(): ?array {
	return defined( SL_CATALOG_CONST ) ? (array) constant( SL_CATALOG_CONST ) : null;
}

/**
 * Every shipped source file except the guide screen itself.
 *
 * The exclusion is the whole point of rule 7: searching the sources for a string
 * the guide holds would find the guide holding it, and the rule would pass by
 * reading its own subject back.
 *
 * @return array<string,string>
 */
function sl_sources_outside_guide(): array {
	$sources = sl_plugin_sources();

	unset( $sources['includes/Admin/Screens/class-guide-screen.php'] );

	return $sources;
}

// ---------------------------------------------------------------------
sl_section( 'Rule 1 — the guide is a screen, not a settings tab' );

$sl_guide_slug = class_exists( SL_GUIDE_CLASS ) && defined( SL_GUIDE_CLASS . '::SLUG' )
	? (string) constant( SL_GUIDE_CLASS . '::SLUG' )
	: '';

if ( '' === $sl_guide_slug ) {
	sl_pending( 'the guide is in the tab strip', 'GuideScreen does not exist' );
	sl_pending( 'and is not a settings tab', 'GuideScreen does not exist' );
	sl_pending( 'and the navigation links to it', 'GuideScreen does not exist' );
} else {
	sl_assert(
		'the guide is in the tab strip',
		isset( SettingsPage::tabs()[ $sl_guide_slug ] ),
		'A screen nobody can reach from the navigation is a screen reachable by typing a URL.'
	);

	/*
	 * The other half, and the one that matters. Membership of FieldRegistry::tabs()
	 * means "this tab draws these settings and a save writes them"; the settings
	 * screen renders a form and a Save button for every tab in it. A guide added
	 * there for tidiness would ship a button that saves nothing.
	 */
	sl_assert(
		'and is not a settings tab',
		! isset( FieldRegistry::tabs()[ $sl_guide_slug ] ),
		'In FieldRegistry::tabs() it would be rendered by SettingsScreen, with a Save button that writes nothing.'
	);

	$sl_nav = sl_capture(
		static function () use ( $sl_guide_slug ): void {
			SettingsPage::nav( $sl_guide_slug );
		}
	);

	sl_assert(
		'and the navigation links to it',
		null === $sl_nav['error'] && false !== strpos( $sl_nav['html'], 'tab=' . $sl_guide_slug . '"' ),
		(string) $sl_nav['error']
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 2 — it renders' );

$sl_render = sl_guide_markup();

if ( null === $sl_render ) {
	sl_pending( 'the guide renders', 'GuideScreen does not exist' );
	sl_pending( 'and emits no PHP notice', 'GuideScreen does not exist' );
	sl_pending( 'and produces markup', 'GuideScreen does not exist' );
} else {
	sl_assert( 'the guide renders', null === $sl_render['error'], (string) $sl_render['error'] );
	sl_assert(
		'and emits no PHP notice',
		array() === $sl_render['warnings'],
		implode( ' | ', array_slice( $sl_render['warnings'], 0, 3 ) )
	);
	sl_assert( 'and produces markup', '' !== trim( $sl_render['html'] ) );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 3 — every registered shortcode is documented, and nothing else is' );

$sl_catalog   = sl_catalog();
$sl_documented = sl_guide_data( 'shortcodes' );

if ( null === $sl_catalog || null === $sl_documented ) {
	sl_pending(
		'the documented tags are exactly the registered ones',
		null === $sl_catalog ? 'Shortcodes::CATALOG does not exist' : 'GuideScreen::shortcodes() does not exist'
	);
} else {
	$sl_registered_tags = array_keys( $sl_catalog );
	$sl_documented_tags = array_keys( $sl_documented );
	sort( $sl_registered_tags );
	sort( $sl_documented_tags );

	// Both directions, because finding 2 is both directions: README names six of
	// the nine registered tags, and a list can just as easily name a tag that was
	// removed.
	sl_check( 'the documented tags are exactly the registered ones', $sl_registered_tags, $sl_documented_tags );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 4 — a tag is registered from the catalog and nowhere else' );

$sl_register_body = sl_method_body( sl_source( 'includes/Frontend/class-shortcodes.php' ), 'register' );

if ( '' === $sl_register_body ) {
	sl_pending( 'register() adds no shortcode by literal name', 'Shortcodes::register() was not found' );
} else {
	sl_assert(
		'register() adds no shortcode by literal name',
		0 === preg_match( '/add_shortcode\s*\(\s*[\'"]/', $sl_register_body ),
		'A literal here is a second list: the catalog can then omit a tag that is still registered, which is exactly finding 2.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 5 — every attribute is documented, and nothing else is' );

if ( null === $sl_catalog || null === $sl_documented ) {
	sl_pending( 'the documented attributes are exactly the declared ones', 'the catalog or the screen does not exist' );
} else {
	$sl_att_drift = array();

	foreach ( $sl_catalog as $sl_tag => $sl_entry ) {
		$sl_declared = array_keys( (array) ( $sl_entry['atts'] ?? array() ) );
		$sl_shown    = array_keys( (array) ( $sl_documented[ $sl_tag ]['atts'] ?? array() ) );
		sort( $sl_declared );
		sort( $sl_shown );

		if ( $sl_declared !== $sl_shown ) {
			$sl_att_drift[] = sprintf(
				'%s — declared [%s], documented [%s]',
				$sl_tag,
				implode( ',', $sl_declared ),
				implode( ',', $sl_shown )
			);
		}
	}

	sl_check( 'the documented attributes are exactly the declared ones', array(), $sl_att_drift );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 6 — and all of it reaches the screen' );

/*
 * Rules 3 and 5 compare two arrays. This one checks the arrays are what the
 * administrator actually reads: a screen could hold a perfect catalog and render
 * half of it.
 */
if ( null === $sl_render || null === $sl_catalog ) {
	sl_pending( 'every tag appears in the rendered guide', 'the screen or the catalog does not exist' );
	sl_pending( 'and so does every attribute', 'the screen or the catalog does not exist' );
} else {
	$sl_missing_tags = array();
	$sl_missing_atts = array();

	foreach ( $sl_catalog as $sl_tag => $sl_entry ) {
		if ( false === strpos( $sl_render['html'], '[' . $sl_tag . ']' ) ) {
			$sl_missing_tags[] = $sl_tag;
		}

		foreach ( array_keys( (array) ( $sl_entry['atts'] ?? array() ) ) as $sl_att ) {
			if ( false === strpos( $sl_render['html'], $sl_att ) ) {
				$sl_missing_atts[] = $sl_tag . '.' . $sl_att;
			}
		}
	}

	sl_check( 'every tag appears in the rendered guide', array(), $sl_missing_tags );
	sl_check( 'and so does every attribute', array(), $sl_missing_atts );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 7 — every quoted message is a message the plugin prints' );

$sl_problems = sl_guide_data( 'problems' );

if ( null === $sl_problems ) {
	sl_pending( 'every quoted message exists verbatim in includes/', 'GuideScreen::problems() does not exist' );
} else {
	$sl_sources  = sl_sources_outside_guide();
	$sl_unquoted = array();
	$sl_quotes   = 0;

	foreach ( $sl_problems as $sl_row ) {
		$sl_quote = (string) ( $sl_row['quote'] ?? '' );

		// A row may describe a symptom rather than quote a string — "the address
		// picker is empty" is not a message anybody prints.
		if ( '' === $sl_quote ) {
			continue;
		}

		++$sl_quotes;
		$sl_found = false;

		foreach ( $sl_sources as $sl_contents ) {
			if ( false !== strpos( $sl_contents, $sl_quote ) ) {
				$sl_found = true;
				break;
			}
		}

		if ( ! $sl_found ) {
			$sl_unquoted[] = $sl_quote;
		}
	}

	sl_assert(
		'the troubleshooting table quotes something',
		$sl_quotes > 0,
		'With no quoted rows the rule below passes vacuously, which is the failure mode it exists to avoid.'
	);

	sl_check( 'every quoted message exists verbatim in includes/', array(), $sl_unquoted );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 8 — every filter named is a filter that runs' );

// Not `$sl_filters`: at global scope that *is* `$GLOBALS['sl_filters']`, the
// stub filter registry, and assigning this list over it turned every registered
// hook into a description string. The suite fataled inside add_filter().
$sl_guide_filters = sl_guide_data( 'filters' );

if ( null === $sl_guide_filters ) {
	sl_pending( 'every filter named is applied in includes/', 'GuideScreen::filters() does not exist' );
} else {
	$sl_sources = sl_sources_outside_guide();
	$sl_absent  = array();

	foreach ( array_keys( $sl_guide_filters ) as $sl_hook ) {
		$sl_found = false;

		foreach ( $sl_sources as $sl_contents ) {
			if ( 1 === preg_match( '/apply_filters\s*\(\s*[\'"]' . preg_quote( (string) $sl_hook, '/' ) . '[\'"]/', $sl_contents ) ) {
				$sl_found = true;
				break;
			}
		}

		if ( ! $sl_found ) {
			$sl_absent[] = (string) $sl_hook;
		}
	}

	sl_assert( 'the guide names at least one filter', array() !== $sl_guide_filters );
	sl_check( 'every filter named is applied in includes/', array(), $sl_absent );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 9 — every "fix it here" link goes somewhere' );

if ( null === $sl_problems ) {
	sl_pending( 'every fix links to a real screen', 'GuideScreen::problems() does not exist' );
} else {
	$sl_tabs    = SettingsPage::tabs();
	$sl_unknown = array();

	foreach ( $sl_problems as $sl_row ) {
		$sl_tab = (string) ( $sl_row['tab'] ?? '' );

		if ( '' !== $sl_tab && ! isset( $sl_tabs[ $sl_tab ] ) ) {
			$sl_unknown[] = $sl_tab;
		}
	}

	sl_check( 'every fix links to a real screen', array(), $sl_unknown );
}

// ---------------------------------------------------------------------
sl_section( 'Rule 10 — the alias list is rendered from the map, not typed' );

if ( null === $sl_render ) {
	sl_pending( 'every shipped alias appears in the guide', 'GuideScreen does not exist' );
	sl_pending( 'and an alias added by filter appears too', 'GuideScreen does not exist' );
} else {
	$sl_missing_aliases = array();

	foreach ( array_keys( LoginDialog::aliases() ) as $sl_alias ) {
		if ( false === strpos( $sl_render['html'], '#' . $sl_alias ) ) {
			$sl_missing_aliases[] = (string) $sl_alias;
		}
	}

	sl_check( 'every shipped alias appears in the guide', array(), $sl_missing_aliases );

	/*
	 * The half that a hard-coded list would pass without. `aliases()` is
	 * filterable (class-login-dialog.php), so a site can add a spelling — and a
	 * guide that lists three strings would go on naming three.
	 */
	add_filter(
		'smart_login_dialog_aliases',
		static function ( array $map ): array {
			$map['dang-nhap-ngay'] = \SmartLogin\Frontend\Flow::STEP_IDENTIFY;

			return $map;
		}
	);

	$sl_filtered = sl_guide_markup( true );

	sl_assert(
		'and an alias added by filter appears too',
		false !== strpos( (string) $sl_filtered['html'], '#dang-nhap-ngay' ),
		'The list is typed into the screen rather than read from LoginDialog::aliases().'
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 11 — the guide offers nothing to save' );

if ( null === $sl_render ) {
	sl_pending( 'the guide draws no settings input', 'GuideScreen does not exist' );
	sl_pending( 'and no submit button', 'GuideScreen does not exist' );
} else {
	sl_assert(
		'the guide draws no settings input',
		false === strpos( $sl_render['html'], 'name="' . \SmartLogin\Settings::OPTION ),
		'A field on this screen is a field the settings save path knows nothing about.'
	);

	sl_assert(
		'and no submit button',
		false === strpos( $sl_render['html'], 'type="submit"' ),
		'A Save button on a screen with nothing to save is the reason rule 1 keeps this out of FieldRegistry.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'Rule 12 — the guide reads no setting, so it can restate none' );

$sl_guide_source = sl_source( 'includes/Admin/Screens/class-guide-screen.php' );

if ( '' === $sl_guide_source ) {
	sl_pending( 'the guide screen reads no setting', 'class-guide-screen.php does not exist' );
} else {
	sl_assert(
		'the guide screen reads no setting',
		false === strpos( $sl_guide_source, 'Settings::' ),
		'A guide that reads a stored value is one refactor away from printing it, and then it is the place that value goes stale.'
	);
}

// ---------------------------------------------------------------------
sl_summary( 'Guide tab' );
