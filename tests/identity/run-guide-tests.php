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
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../admin-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Admin\SettingsPage;
use OmniWP\FieldRegistry;
use OmniWP\Frontend\LoginDialog;
use OmniWP\Frontend\Shortcodes;

/** The screen this phase adds. Absent until 22.2. */
const ow_GUIDE_CLASS = 'OmniWP\Admin\Screens\GuideScreen';

/** The catalog 22.1 adds. Absent until then. */
const ow_CATALOG_CONST = 'OmniWP\Frontend\Shortcodes::CATALOG';

/**
 * The screen's markup, rendered once and remembered.
 *
 * Returns null when the screen does not exist, so every rule phrased over the
 * markup reports PENDING instead of asserting against an empty string.
 */
function ow_guide_markup( bool $fresh = false ): ?array {
	static $captured = null;

	if ( ! $fresh && null !== $captured ) {
		return $captured;
	}

	if ( ! class_exists( ow_GUIDE_CLASS ) ) {
		return null;
	}

	$class    = ow_GUIDE_CLASS;
	$captured = ow_capture(
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
function ow_guide_data( string $method ): ?array {
	if ( ! class_exists( ow_GUIDE_CLASS ) || ! method_exists( ow_GUIDE_CLASS, $method ) ) {
		return null;
	}

	return (array) call_user_func( array( ow_GUIDE_CLASS, $method ) );
}

/** The shortcode catalog, or null before 22.1. */
function ow_catalog(): ?array {
	return defined( ow_CATALOG_CONST ) ? (array) constant( ow_CATALOG_CONST ) : null;
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
function ow_sources_outside_guide(): array {
	$sources = ow_plugin_sources();

	unset( $sources['includes/Admin/Screens/class-guide-screen.php'] );

	return $sources;
}

// ---------------------------------------------------------------------
ow_section( 'Rule 1 — the guide is a screen, not a settings tab' );

$ow_guide_slug = class_exists( ow_GUIDE_CLASS ) && defined( ow_GUIDE_CLASS . '::SLUG' )
	? (string) constant( ow_GUIDE_CLASS . '::SLUG' )
	: '';

if ( '' === $ow_guide_slug ) {
	ow_pending( 'the guide is registered as a standalone page', 'GuideScreen does not exist' );
	ow_pending( 'and is not a settings tab', 'GuideScreen does not exist' );
} else {
	ow_assert(
		'the guide is registered as a standalone page',
		SettingsPage::GUIDE_SLUG === $ow_guide_slug,
		'A screen reachable by its own submenu slug.'
	);

	/*
	 * The other half, and the one that matters. Membership of FieldRegistry::tabs()
	 * means "this tab draws these settings and a save writes them"; the settings
	 * screen renders a form and a Save button for every tab in it. A guide added
	 * there for tidiness would ship a button that saves nothing.
	 */
	ow_assert(
		'and is not a settings tab',
		! isset( FieldRegistry::tabs()[ $ow_guide_slug ] ) && ! isset( SettingsPage::tabs()[ $ow_guide_slug ] ),
		'In FieldRegistry::tabs() it would be rendered by SettingsScreen, with a Save button that writes nothing.'
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 2 — it renders' );

$ow_render = ow_guide_markup();

if ( null === $ow_render ) {
	ow_pending( 'the guide renders', 'GuideScreen does not exist' );
	ow_pending( 'and emits no PHP notice', 'GuideScreen does not exist' );
	ow_pending( 'and produces markup', 'GuideScreen does not exist' );
} else {
	ow_assert( 'the guide renders', null === $ow_render['error'], (string) $ow_render['error'] );
	ow_assert(
		'and emits no PHP notice',
		array() === $ow_render['warnings'],
		implode( ' | ', array_slice( $ow_render['warnings'], 0, 3 ) )
	);
	ow_assert( 'and produces markup', '' !== trim( $ow_render['html'] ) );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 3 — every registered shortcode is documented, and nothing else is' );

$ow_catalog   = ow_catalog();
$ow_documented = ow_guide_data( 'shortcodes' );

if ( null === $ow_catalog || null === $ow_documented ) {
	ow_pending(
		'the documented tags are exactly the registered ones',
		null === $ow_catalog ? 'Shortcodes::CATALOG does not exist' : 'GuideScreen::shortcodes() does not exist'
	);
} else {
	$ow_registered_tags = array_keys( $ow_catalog );
	$ow_documented_tags = array_keys( $ow_documented );
	sort( $ow_registered_tags );
	sort( $ow_documented_tags );

	// Both directions, because finding 2 is both directions: README names six of
	// the nine registered tags, and a list can just as easily name a tag that was
	// removed.
	ow_check( 'the documented tags are exactly the registered ones', $ow_registered_tags, $ow_documented_tags );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 4 — a tag is registered from the catalog and nowhere else' );

$ow_register_body = ow_method_body( ow_source( 'includes/Frontend/class-shortcodes.php' ), 'register' );

if ( '' === $ow_register_body ) {
	ow_pending( 'register() adds no shortcode by literal name', 'Shortcodes::register() was not found' );
} else {
	ow_assert(
		'register() adds no shortcode by literal name',
		0 === preg_match( '/add_shortcode\s*\(\s*[\'"]/', $ow_register_body ),
		'A literal here is a second list: the catalog can then omit a tag that is still registered, which is exactly finding 2.'
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 5 — every attribute is documented, and nothing else is' );

if ( null === $ow_catalog || null === $ow_documented ) {
	ow_pending( 'the documented attributes are exactly the declared ones', 'the catalog or the screen does not exist' );
} else {
	$ow_att_drift = array();

	foreach ( $ow_catalog as $ow_tag => $ow_entry ) {
		$ow_declared = array_keys( (array) ( $ow_entry['atts'] ?? array() ) );
		$ow_shown    = array_keys( (array) ( $ow_documented[ $ow_tag ]['atts'] ?? array() ) );
		sort( $ow_declared );
		sort( $ow_shown );

		if ( $ow_declared !== $ow_shown ) {
			$ow_att_drift[] = sprintf(
				'%s — declared [%s], documented [%s]',
				$ow_tag,
				implode( ',', $ow_declared ),
				implode( ',', $ow_shown )
			);
		}
	}

	ow_check( 'the documented attributes are exactly the declared ones', array(), $ow_att_drift );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 6 — and all of it reaches the screen' );

/*
 * Rules 3 and 5 compare two arrays. This one checks the arrays are what the
 * administrator actually reads: a screen could hold a perfect catalog and render
 * half of it.
 */
if ( null === $ow_render || null === $ow_catalog ) {
	ow_pending( 'every tag appears in the rendered guide', 'the screen or the catalog does not exist' );
	ow_pending( 'and so does every attribute', 'the screen or the catalog does not exist' );
} else {
	$ow_missing_tags = array();
	$ow_missing_atts = array();

	foreach ( $ow_catalog as $ow_tag => $ow_entry ) {
		if ( false === strpos( $ow_render['html'], '[' . $ow_tag . ']' ) ) {
			$ow_missing_tags[] = $ow_tag;
		}

		foreach ( array_keys( (array) ( $ow_entry['atts'] ?? array() ) ) as $ow_att ) {
			if ( false === strpos( $ow_render['html'], $ow_att ) ) {
				$ow_missing_atts[] = $ow_tag . '.' . $ow_att;
			}
		}
	}

	ow_check( 'every tag appears in the rendered guide', array(), $ow_missing_tags );
	ow_check( 'and so does every attribute', array(), $ow_missing_atts );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 7 — every quoted message is a message the plugin prints' );

$ow_problems = ow_guide_data( 'problems' );

if ( null === $ow_problems ) {
	ow_pending( 'every quoted message exists verbatim in includes/', 'GuideScreen::problems() does not exist' );
} else {
	$ow_sources  = ow_sources_outside_guide();
	$ow_unquoted = array();
	$ow_quotes   = 0;

	foreach ( $ow_problems as $ow_row ) {
		$ow_quote = (string) ( $ow_row['quote'] ?? '' );

		// A row may describe a symptom rather than quote a string — "the address
		// picker is empty" is not a message anybody prints.
		if ( '' === $ow_quote ) {
			continue;
		}

		++$ow_quotes;
		$ow_found = false;

		foreach ( $ow_sources as $ow_contents ) {
			if ( false !== strpos( $ow_contents, $ow_quote ) ) {
				$ow_found = true;
				break;
			}
		}

		if ( ! $ow_found ) {
			$ow_unquoted[] = $ow_quote;
		}
	}

	ow_assert(
		'the troubleshooting table quotes something',
		$ow_quotes > 0,
		'With no quoted rows the rule below passes vacuously, which is the failure mode it exists to avoid.'
	);

	ow_check( 'every quoted message exists verbatim in includes/', array(), $ow_unquoted );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 8 — every filter named is a filter that runs' );

// Not `$ow_filters`: at global scope that *is* `$GLOBALS['ow_filters']`, the
// stub filter registry, and assigning this list over it turned every registered
// hook into a description string. The suite fataled inside add_filter().
$ow_guide_filters = ow_guide_data( 'filters' );

if ( null === $ow_guide_filters ) {
	ow_pending( 'every filter named is applied in includes/', 'GuideScreen::filters() does not exist' );
} else {
	$ow_sources = ow_sources_outside_guide();
	$ow_absent  = array();

	foreach ( array_keys( $ow_guide_filters ) as $ow_hook ) {
		$ow_found = false;

		foreach ( $ow_sources as $ow_contents ) {
			if ( 1 === preg_match( '/apply_filters\s*\(\s*[\'"]' . preg_quote( (string) $ow_hook, '/' ) . '[\'"]/', $ow_contents ) ) {
				$ow_found = true;
				break;
			}
		}

		if ( ! $ow_found ) {
			$ow_absent[] = (string) $ow_hook;
		}
	}

	ow_assert( 'the guide names at least one filter', array() !== $ow_guide_filters );
	ow_check( 'every filter named is applied in includes/', array(), $ow_absent );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 9 — every "fix it here" link goes somewhere' );

if ( null === $ow_problems ) {
	ow_pending( 'every fix links to a real screen', 'GuideScreen::problems() does not exist' );
} else {
	$ow_tabs    = SettingsPage::tabs();
	$ow_unknown = array();

	foreach ( $ow_problems as $ow_row ) {
		$ow_tab = (string) ( $ow_row['tab'] ?? '' );

		if ( '' !== $ow_tab && ! isset( $ow_tabs[ $ow_tab ] ) ) {
			$ow_unknown[] = $ow_tab;
		}
	}

	ow_check( 'every fix links to a real screen', array(), $ow_unknown );
}

// ---------------------------------------------------------------------
ow_section( 'Rule 10 — the alias list is rendered from the map, not typed' );

if ( null === $ow_render ) {
	ow_pending( 'every shipped alias appears in the guide', 'GuideScreen does not exist' );
	ow_pending( 'and an alias added by filter appears too', 'GuideScreen does not exist' );
} else {
	$ow_missing_aliases = array();

	foreach ( array_keys( LoginDialog::aliases() ) as $ow_alias ) {
		if ( false === strpos( $ow_render['html'], '#' . $ow_alias ) ) {
			$ow_missing_aliases[] = (string) $ow_alias;
		}
	}

	ow_check( 'every shipped alias appears in the guide', array(), $ow_missing_aliases );

	/*
	 * The half that a hard-coded list would pass without. `aliases()` is
	 * filterable (class-login-dialog.php), so a site can add a spelling — and a
	 * guide that lists three strings would go on naming three.
	 */
	add_filter( 'omniwp_dialog_aliases',
		static function ( array $map ): array {
			$map['dang-nhap-ngay'] = \OmniWP\Frontend\Flow::STEP_IDENTIFY;

			return $map;
		}
	);

	$ow_filtered = ow_guide_markup( true );

	ow_assert(
		'and an alias added by filter appears too',
		false !== strpos( (string) $ow_filtered['html'], '#dang-nhap-ngay' ),
		'The list is typed into the screen rather than read from LoginDialog::aliases().'
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 11 — the guide offers nothing to save' );

if ( null === $ow_render ) {
	ow_pending( 'the guide draws no settings input', 'GuideScreen does not exist' );
	ow_pending( 'and no submit button', 'GuideScreen does not exist' );
} else {
	ow_assert(
		'the guide draws no settings input',
		false === strpos( $ow_render['html'], 'name="' . \OmniWP\Settings::OPTION ),
		'A field on this screen is a field the settings save path knows nothing about.'
	);

	ow_assert(
		'and no submit button',
		false === strpos( $ow_render['html'], 'type="submit"' ),
		'A Save button on a screen with nothing to save is the reason rule 1 keeps this out of FieldRegistry.'
	);
}

// ---------------------------------------------------------------------
ow_section( 'Rule 12 — the guide reads no setting, so it can restate none' );

$ow_guide_source = ow_source( 'includes/Admin/Screens/class-guide-screen.php' );

if ( '' === $ow_guide_source ) {
	ow_pending( 'the guide screen reads no setting', 'class-guide-screen.php does not exist' );
} else {
	ow_assert(
		'the guide screen reads no setting',
		false === strpos( $ow_guide_source, 'Settings::' ),
		'A guide that reads a stored value is one refactor away from printing it, and then it is the place that value goes stale.'
	);
}

// ---------------------------------------------------------------------
ow_summary( 'Guide tab' );
