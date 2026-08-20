<?php
/**
 * Navigation and mobile chrome — Phase 25 guard rails.
 *
 * Normative spec: docs/navigation.md. Progress: docs/refactor-plan.md Phase 25.
 *
 * Landed at 25.0, before a single production file moved, and landed **red**:
 * four of these six rules have offenders in the tree they were written against,
 * and those offenders are the evidence the detectors work. A rule written after
 * the fix cannot fail, and a rule that has never failed is a comment.
 *
 * Rule 6 landed as five PENDINGs — its subject did not exist, and a rule that
 * passes for want of a subject states the opposite of the truth (the 10.0
 * precedent). 25.1 built the model and turned all five into assertions.
 *
 * Rules 1, 3, 4 and 5 are still red on purpose. They describe defects 25.3 and
 * 25.4 are scheduled to fix, and each names the file and line it is waiting on.
 *
 * Run with:  php tests/navigation/run-navigation-tests.php
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\FieldRegistry;
use OmniWP\Settings;
use OmniWP\Navigation\Catalog;
use OmniWP\Navigation\Dock;
use OmniWP\Navigation\Node;
use OmniWP\Navigation\TaxonomyProvider;
use OmniWP\Navigation\Tree;

$ow_root = dirname( __DIR__, 2 ) . '/';

/**
 * Every shipped asset of one extension, keyed by path relative to the plugin.
 *
 * Assets need their own reader: ow_plugin_sources() is PHP only, and two of the
 * rules here describe CSS and JS. build/ and dist/ are skipped for the reason
 * that helper skips them — they hold copies, and a rule that fails against a
 * copy names nothing a reader can fix.
 *
 * @return array<string,string>
 */
function ow_nav_assets( string $extension ): array {
	$root  = dirname( __DIR__, 2 ) . '/assets/';
	$found = array();

	foreach ( array( 'css', 'js' ) as $dir ) {
		foreach ( (array) glob( $root . $dir . '/*.' . $extension ) as $file ) {
			$found[ 'assets/' . $dir . '/' . basename( $file ) ] = (string) file_get_contents( $file );
		}
	}

	ksort( $found );

	return $found;
}

/**
 * Report a list of offenders under one label, or count a pass when it is empty.
 *
 * ow_forbid_pattern() does this for PHP sources; these rules collect offenders
 * from CSS, JS and a schema walk, so they need the same reporting without the
 * same scanner.
 *
 * @param string[] $offenders
 */
function ow_nav_offenders( string $label, array $offenders, string $hint = '' ): void {
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

	if ( count( $offenders ) > 12 ) {
		printf( "           → …and %d more\n", count( $offenders ) - 12 );
	}
}

/**
 * Selector => declaration body, for every rule block in a stylesheet.
 *
 * Crude on purpose. It does not understand nesting or at-rules, and it does not
 * need to: both questions asked of it are about one declaration block, and an
 * at-rule wrapper leaves the inner blocks intact for this regex.
 *
 * @return array<int,array{selector:string,body:string,line:int}>
 */
function ow_nav_css_blocks( string $css ): array {
	$blocks = array();

	if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_OFFSET_CAPTURE ) ) {
		return $blocks;
	}

	foreach ( $matches[1] as $index => $selector ) {
		$blocks[] = array(
			'selector' => trim( preg_replace( '/\s+/', ' ', $selector[0] ) ),
			'body'     => $matches[2][ $index ][0],
			'line'     => substr_count( substr( $css, 0, (int) $selector[1] ), "\n" ) + 1,
		);
	}

	return $blocks;
}

// =====================================================================
ow_section( 'Rule 1 — every setting the registry declares is read somewhere outside Admin/' );
// =====================================================================

/*
 * The ShopKit "setting chết" class, which bit that plugin six times: a row is
 * declared, a tab draws it, the store owner toggles it, and no code path ever
 * asks for the value. Nothing in this plugin has ever checked for it, and
 * docs/navigation.md §1.1 and §1.2 record what that cost — including a switch
 * named for the very feature Phase 25 is about to build.
 *
 * "Read" means the path appears as a literal in a file that is not *the settings
 * form*. The form is three files, not the whole of Admin/: `Readiness` and
 * `WebhookTester` live under Admin/ and are consumers like any other, and
 * excluding them wholesale was this rule's first false positive —
 * `otp.sms_unit_cost` is read by the readiness estimate and is not dead.
 */
$ow_form_files = array(
	'includes/Admin/Screens/class-settings-screen.php',
	'includes/Admin/class-field-renderer.php',
	'includes/Admin/class-settings-page.php',
	'includes/class-field-registry.php',
);

/*
 * Paths whose reader composes them at run time, so the literal is absent by
 * design. Each entry names the composer; an entry without one is a row nobody
 * has to justify again, which is the failure mode of every allowlist.
 */
$ow_dynamic_paths = array(
	/*
	 * ProviderAuthController and the provider gate build "providers.{$id}.…".
	 * `client_secret` is deliberately absent: it is not a registry row at all,
	 * it goes through Settings::read_secret(). The both-directions check below
	 * is what said so, on the first run after this list was written from memory.
	 */
	'providers.google.enabled',
	'providers.google.client_id',
	'providers.google.email_identity',
	// Mail\MailRegistry::PATH_PREFIX ('email.templates.') plus the template key.
	'email.templates.register.subject',
	'email.templates.register.body',
	'email.templates.login.subject',
	'email.templates.login.body',
	'email.templates.recover.subject',
	'email.templates.recover.body',
	'email.templates.add_identity.subject',
	'email.templates.add_identity.body',
	'email.templates.budget_halted.enabled',
	'email.templates.budget_halted.subject',
	'email.templates.budget_halted.body',
	'email.templates.breaker_open.enabled',
	'email.templates.breaker_open.subject',
	'email.templates.breaker_open.body',
	/*
	 * uninstall.php:32 reads it out of the raw nested option. It runs without the
	 * plugin booted, so it cannot ask Settings for a dotted path.
	 */
	'advanced.delete_data_on_uninstall',
);

$ow_schema     = FieldRegistry::all();
$ow_readers    = array();
$ow_dead_rows  = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( in_array( $ow_relative, $ow_form_files, true ) ) {
		continue;
	}

	$ow_readers[ $ow_relative ] = $ow_contents;
}

foreach ( array_keys( $ow_schema ) as $ow_path ) {
	if ( in_array( $ow_path, $ow_dynamic_paths, true ) ) {
		continue;
	}

	$ow_seen = false;

	foreach ( $ow_readers as $ow_contents ) {
		if ( false !== strpos( $ow_contents, "'" . $ow_path . "'" ) || false !== strpos( $ow_contents, '"' . $ow_path . '"' ) ) {
			$ow_seen = true;
			break;
		}
	}

	if ( ! $ow_seen ) {
		$ow_dead_rows[] = $ow_path;
	}
}

/*
 * Dead rows that belong to another module, deferred to 25.7 with a tracker row.
 *
 * They are PENDING rather than allowlisted, and the difference matters: an
 * allowlist entry says "this is fine", and these are not fine. A PENDING says
 * the rule found something nobody has fixed yet, keeps saying it on every run,
 * and does not count as a pass.
 *
 * 25.4 fixed the two that were navigation's own: ecommerce.mobile_dock_enabled
 * and branding.show_floating_cart are gone from the registry. Wiring the rest
 * means touching checkout field rendering and the OTP automation bus, which is
 * neither this phase's subject nor its risk to take.
 */
$ow_deferred_dead = array(
	// The address book runs unconditionally in CheckoutService; the switch was
	// never consulted. Wiring it changes checkout rendering.
	'ecommerce.address_book_checkout',
	/*
	 * AutomationEndpoint judges success by HTTP status alone, and on the path
	 * that matters it cannot do otherwise: EventBus posts with blocking = false
	 * (class-event-bus.php:132), and a fire-and-forget request has no body to
	 * test. The sms.* twins of these two are read, which is what made the
	 * omission look like an oversight rather than a limit.
	 */
	'automation.success_path',
	'automation.success_value',
);

$ow_unexpected_dead = array_values( array_diff( $ow_dead_rows, $ow_deferred_dead ) );

ow_nav_offenders(
	'No setting is declared, drawn and then read by nothing',
	$ow_unexpected_dead,
	'A control that changes nothing is worse than a missing one: the store owner sets it and believes it took.'
);

foreach ( $ow_deferred_dead as $ow_deferred ) {
	if ( in_array( $ow_deferred, $ow_dead_rows, true ) ) {
		ow_pending( 'A store owner can still set ' . $ow_deferred . ', and nothing reads it', '25.7, dead settings outside navigation' );
		continue;
	}

	/*
	 * Checked in the other direction too. A deferral that has quietly been fixed
	 * is a second lie in the place the first one was recorded.
	 */
	ow_nav_offenders(
		'No deferral outlives the row it excused',
		array( $ow_deferred . ' (deferred to 25.7, but it now has a reader)' ),
		'Remove it from $ow_deferred_dead.'
	);
}

$ow_stale_exemptions = array();

foreach ( $ow_dynamic_paths as $ow_exempt ) {
	if ( ! isset( $ow_schema[ $ow_exempt ] ) ) {
		$ow_stale_exemptions[] = $ow_exempt . ' (exempted, but the registry no longer declares it)';
	}
}

ow_nav_offenders(
	'No exemption outlives the row it excused',
	$ow_stale_exemptions,
	'An allowlist checked in one direction only becomes a list of things nobody has to justify again.'
);

// =====================================================================
ow_section( 'Rule 2 — no device detection on the server' );
// =====================================================================

/*
 * wp_is_mobile() reads the user agent, and the page it produces is then cached
 * and served to the other kind of device. docs/navigation.md §3.4 settles the
 * alternative: the device axis is data on the node, and it resolves in CSS.
 *
 * This rule lands green — the function appears nowhere today — so it was proved
 * to fail before being trusted, by adding one call and watching it name the
 * line. That is the 24.0 precedent for a preventive rule.
 */
ow_forbid_pattern(
	'wp_is_mobile() is never called',
	'/\bwp_is_mobile\s*\(/',
	array(),
	'Device detection on the server plus a page cache serves the phone layout to the desktop.'
);

// =====================================================================
ow_section( 'Rule 3 — the breakpoint lives in CSS, not in JS' );
// =====================================================================

/*
 * ShopKit closed this class in P17.1: JS asks getComputedStyle() what the layout
 * currently *is*, rather than re-deciding what 768 means. Two copies of a
 * breakpoint are two copies that will disagree, and nothing notices when they
 * do.
 */
$ow_js_breakpoints = array();

foreach ( ow_nav_assets( 'js' ) as $ow_relative => $ow_contents ) {
	if ( ! preg_match_all( '/(innerWidth|outerWidth|clientWidth)\s*[<>]=?\s*\d{3,}|matchMedia\s*\(\s*[\'"][^\'"]*\d{3,}px/', $ow_contents, $ow_matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_matches[0] as $ow_match ) {
		$ow_line             = substr_count( substr( $ow_contents, 0, (int) $ow_match[1] ), "\n" ) + 1;
		$ow_js_breakpoints[] = $ow_relative . ':' . $ow_line . '  ' . trim( $ow_match[0] );
	}
}

ow_nav_offenders(
	'No script decides the layout from a pixel width',
	$ow_js_breakpoints,
	'Ask getComputedStyle() what the layout is. The number belongs to the stylesheet that draws it.'
);

// =====================================================================
ow_section( 'Rule 4 — the bottom edge of the viewport has one owner' );
// =====================================================================

/*
 * Measured before it was designed (docs/navigation.md §1.4): .sl-floating-cart
 * sits on top of ShopKit's .sk-sticky-cart on a phone, over the end of the bar
 * where the buy button is, and neither element knows the other exists. A dock is
 * a third fixed element on the same edge.
 *
 * The rule reads only elements anchored to the bottom and *not* to the top: a
 * full-height drawer legitimately spans the viewport and is not stacking on
 * anything.
 */
/*
 * The element that *defines* --ow-dock-height cannot stack on it. One entry, and
 * it is the only kind of entry this list may ever hold: the edge has one owner,
 * which is the whole point of the rule.
 */
$ow_edge_owner = '.ow-dock';

$ow_fixed_selectors = array();
$ow_bottom_anchored = array();

foreach ( ow_nav_assets( 'css' ) as $ow_relative => $ow_contents ) {
	foreach ( ow_nav_css_blocks( $ow_contents ) as $ow_block ) {
		if ( preg_match( '/position\s*:\s*fixed/i', $ow_block['body'] ) ) {
			$ow_fixed_selectors[ $ow_block['selector'] ] = true;
		}
	}
}

foreach ( ow_nav_assets( 'css' ) as $ow_relative => $ow_contents ) {
	foreach ( ow_nav_css_blocks( $ow_contents ) as $ow_block ) {
		if ( ! isset( $ow_fixed_selectors[ $ow_block['selector'] ] ) ) {
			continue;
		}

		if ( $ow_edge_owner === $ow_block['selector'] ) {
			continue;
		}

		// Anchored to both edges: it spans, it does not stack.
		if ( preg_match( '/(^|[;{\s])top\s*:/i', $ow_block['body'] ) ) {
			continue;
		}

		if ( ! preg_match( '/(^|[;{\s])bottom\s*:\s*([^;]+)/i', $ow_block['body'], $ow_bottom ) ) {
			continue;
		}

		$ow_value = trim( $ow_bottom[2] );

		if ( 'auto' === strtolower( $ow_value ) || false !== strpos( $ow_value, '--ow-dock-height' ) ) {
			continue;
		}

		$ow_bottom_anchored[] = $ow_relative . ':' . $ow_block['line'] . '  ' . $ow_block['selector'] . ' { bottom: ' . $ow_value . ' }';
	}
}

ow_nav_offenders(
	'Everything anchored to the bottom stacks on --ow-dock-height',
	$ow_bottom_anchored,
	'Two plugins already overlap on this edge. A third fixed element without a shared token makes it three.'
);

// =====================================================================
ow_section( 'Rule 5 — cart state never lands in cacheable HTML' );
// =====================================================================

/*
 * SlideCart::render_drawer() runs on wp_footer and writes the whole cart into
 * every page; Shortcodes::render_cart_button() writes the count inline. Under
 * full-page caching that is one visitor's cart served to the next.
 *
 * The allowlist is the 24.1 shape: an exemption carries its reason, and it is
 * checked in both directions below so it cannot outlive what it excused.
 */
$ow_cart_reads_allowed = array(
	// The service *is* the reader. Its callers are what this rule is about.
	'includes/Ecommerce/class-cart-service.php',
	// Cart and checkout are never cached — every cache plugin sets DONOTCACHEPAGE.
	'templates/ecommerce/cart-page.php',
	'templates/ecommerce/checkout-page.php',
	// Rendered by CheckoutService only, so it reaches no other page.
	'templates/ecommerce/voucher-picker-modal.php',
);

/**
 * The function a byte offset falls inside, or '' at file scope.
 *
 * An AJAX or REST callback reading the cart is the *correct* shape — that
 * response is never cached, and it is what the fragment marker will call. The
 * defect is a page-render path baking the same values into the document, so the
 * rule has to tell the two apart rather than banning the call outright.
 *
 * A `woocommerce_add_to_cart_fragments` callback is the third such shape, and
 * the one 25.4's dock badge uses: WooCommerce refreshes fragments from its own
 * nonce-free endpoint, so that response is never cached either.
 */
function ow_nav_enclosing_function( string $contents, int $offset ): string {
	if ( ! preg_match_all( '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$name = '';

	foreach ( $matches[1] as $match ) {
		if ( (int) $match[1] > $offset ) {
			break;
		}

		$name = $match[0];
	}

	return $name;
}

$ow_cart_in_page = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( in_array( $ow_relative, $ow_cart_reads_allowed, true ) ) {
		continue;
	}

	if ( ! preg_match_all( '/get_cart_contents_count\s*\(|CartService::get_cart_data\s*\(/', $ow_contents, $ow_matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_matches[0] as $ow_match ) {
		$ow_scope = ow_nav_enclosing_function( $ow_contents, (int) $ow_match[1] );

		if ( 0 === strpos( $ow_scope, 'ajax_' ) || 0 === strpos( $ow_scope, 'rest_' ) || false !== strpos( $ow_scope, 'fragment' ) ) {
			continue;
		}

		$ow_line           = substr_count( substr( $ow_contents, 0, (int) $ow_match[1] ), "\n" ) + 1;
		$ow_cart_in_page[] = $ow_relative . ':' . $ow_line . '  ' . ( '' === $ow_scope ? 'file scope' : $ow_scope . '()' );
	}
}

/*
 * The three call sites that predate this phase, deferred to 25.8.
 *
 * 25.4 proved the shape works: the dock's badge renders empty and is filled by
 * WooCommerce's fragment refresh, and rule 5 does not name it. Doing the same to
 * these three means splitting the slide-cart drawer into a shell and a
 * replaceable body — a restructure of the one module Phase 24 recorded as
 * "structurally sound and behaviourally unproven", with no integration gate to
 * catch what it breaks. Building that gate is 25.8's first task, not a detour
 * inside a navigation phase.
 *
 * PENDING rather than allowlisted: these are not fine, and a run that stops
 * saying so is a run that has forgotten.
 */
$ow_deferred_cart_reads = array(
	'includes/Ecommerce/class-slide-cart.php:122  render_drawer()',
	'includes/Frontend/class-shortcodes.php:637  render_cart_button()',
	'templates/ecommerce/voucher-module.php:22  file scope',
);

$ow_new_cart_reads = array_values( array_diff( $ow_cart_in_page, $ow_deferred_cart_reads ) );

ow_nav_offenders(
	'No new call site renders cart state into a page that can be cached',
	$ow_new_cart_reads,
	'Render an empty element carrying a fragment marker and fill it from an uncached read.'
);

foreach ( $ow_deferred_cart_reads as $ow_deferred_read ) {
	if ( in_array( $ow_deferred_read, $ow_cart_in_page, true ) ) {
		ow_pending( 'A cached page still ships the previous visitor cart state: ' . $ow_deferred_read, '25.8, cart state out of cacheable HTML' );
		continue;
	}

	ow_nav_offenders(
		'No cart deferral outlives the call site it excused',
		array( $ow_deferred_read . ' (deferred to 25.8, but it is gone or moved)' ),
		'A line number in a deferral list is a claim about the tree; update it or drop it.'
	);
}

$ow_stale_allowlist = array();

foreach ( $ow_cart_reads_allowed as $ow_allowed ) {
	if ( '' === ow_source( $ow_allowed ) ) {
		$ow_stale_allowlist[] = $ow_allowed . ' (allowlisted, but the file is gone)';
		continue;
	}

	if ( ! preg_match( '/get_cart_contents_count\s*\(|get_cart_data\s*\(/', ow_source( $ow_allowed ) ) ) {
		$ow_stale_allowlist[] = $ow_allowed . ' (allowlisted, but reads no cart state)';
	}
}

ow_nav_offenders(
	'No cart exemption outlives the call site it excused',
	$ow_stale_allowlist,
	'An allowlist checked in one direction only becomes a list of things nobody has to justify again.'
);

// =====================================================================
ow_section( 'Rule 6 — one tree, N providers' );
// =====================================================================

/*
 * WordPress functions the model calls that no shared stub declares. They live
 * here rather than in tests/stubs.php because only this suite needs them, and a
 * shared stub is a shared behaviour every other suite silently inherits.
 */
$GLOBALS['ow_terms']          = array();
$GLOBALS['ow_get_terms_calls'] = 0;
$GLOBALS['ow_menu_items']     = array();

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		++$GLOBALS['ow_get_terms_calls'];

		return $GLOBALS['ow_terms'][ $args['taxonomy'] ?? '' ] ?? array();
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return isset( $GLOBALS['ow_terms'][ $taxonomy ] );
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, $taxonomy = '' ) {
		return 'https://example.test/' . $taxonomy . '/' . ( $term->slug ?? '' ) . '/';
	}
}

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	function wp_get_nav_menu_items( $menu ) {
		return $GLOBALS['ow_menu_items'][ $menu ] ?? array();
	}
}

/** One stubbed term object, shaped the way get_terms() returns them. */
function ow_nav_term( int $id, string $name, int $parent, string $slug, int $count = 3 ): object {
	return (object) array(
		'term_id' => $id,
		'name'    => $name,
		'parent'  => $parent,
		'slug'    => $slug,
		'count'   => $count,
	);
}

// --- Every provider id is claimed exactly once -------------------------------

ow_check( 'Four providers ship with the plugin', 4, count( Catalog::providers() ) );
ow_assert( 'The WP menu provider needs nothing else installed', Catalog::has( 'wp_menu' ) );

add_filter(
	'omniwp_navigation_providers',
	static function ( array $providers ): array {
		// A third party trying to take a name that is already ours.
		$providers['product_cat'] = array(
			'label'    => 'Hijacked',
			'callback' => static function (): Tree {
				return Tree::empty();
			},
		);

		// And one adding a name of its own, which is the supported case.
		$providers['shopkit_brands'] = array(
			'label'    => 'ShopKit brands',
			'callback' => static function (): Tree {
				return Tree::of( array( array( 'id' => 'b1', 'type' => 'block', 'label' => 'Brands' ) ) );
			},
		);

		return $providers;
	}
);

Catalog::flush();

ow_check( 'A filtered row cannot displace a built-in id', 'Danh mục sản phẩm', Catalog::label( 'product_cat' ) );
ow_assert( 'A filtered row with a new id is registered', Catalog::has( 'shopkit_brands' ) );
ow_check( 'The sibling plugin joins by hook, not by class name', 1, Catalog::tree( 'shopkit_brands' )->count() );

// --- An unknown provider is a state, not an exception ------------------------

$ow_unknown = Catalog::tree( 'nothing_registered_this' );
ow_assert( 'An unknown provider yields an empty tree rather than throwing', $ow_unknown->is_empty() );

// --- Depth is capped by the model -------------------------------------------

$ow_deep = array(
	array(
		'id'       => 'l1',
		'type'     => 'link',
		'label'    => 'F1',
		'children' => array(
			array(
				'id'       => 'l2',
				'type'     => 'link',
				'label'    => 'F2',
				'children' => array(
					array(
						'id'       => 'l3',
						'type'     => 'link',
						'label'    => 'F3',
						'children' => array(
							array(
								'id'       => 'l4',
								'type'     => 'link',
								'label'    => 'F4',
								'children' => array( array( 'id' => 'l5', 'type' => 'link', 'label' => 'F5' ) ),
							),
						),
					),
				),
			),
		),
	),
);

$ow_capped = Tree::of( $ow_deep );

ow_check( 'A five-level tree comes back three deep', 3, $ow_capped->depth() );
ow_check( 'Three nodes survive, not five', 3, $ow_capped->count() );
ow_assert( 'The level that was cut is gone, not hidden', null === $ow_capped->find( 'l4' ) );
ow_assert( 'The level that fits is intact', null !== $ow_capped->find( 'l3' ) );

// --- The vocabulary is closed ------------------------------------------------

$ow_rejected = false;

try {
	Node::make( array( 'id' => 'x', 'type' => 'carousel' ) );
} catch ( \InvalidArgumentException $e ) {
	$ow_rejected = true;
}

ow_assert( 'A node type outside the catalog is refused', $ow_rejected, 'A provider setting a bad type is a programming error, not stored data.' );
ow_check( 'The vocabulary is four types', 4, count( Node::TYPES ) );

$ow_stale_device = Node::make( array( 'id' => 'x', 'type' => 'link', 'devices' => 'tablet-landscape' ) );
ow_check( 'An unrecognised device value falls back to showing the node', 'all', $ow_stale_device->devices() );
ow_check( 'A node with no device restriction carries no class', '', $ow_stale_device->device_class() );
ow_check(
	'The device axis leaves the model as a class',
	'ow-nav--only-mobile',
	Node::make( array( 'id' => 'y', 'type' => 'link', 'devices' => 'mobile' ) )->device_class()
);

// --- The taxonomy provider asks once ----------------------------------------

$GLOBALS['ow_terms']['product_cat'] = array(
	ow_nav_term( 10, 'Sữa bột cao cấp', 0, 'sua-bot' ),
	ow_nav_term( 11, 'Bỉm tã khuyến mãi', 0, 'bim-ta' ),
	ow_nav_term( 20, 'Sữa Mỹ', 10, 'sua-my' ),
	ow_nav_term( 21, 'Sữa Nhật', 10, 'sua-nhat' ),
	ow_nav_term( 30, '0-1 tuổi', 20, '0-1-tuoi' ),
);

$GLOBALS['ow_get_terms_calls'] = 0;

$ow_cats = TaxonomyProvider::tree( array( 'taxonomy' => 'product_cat' ) );

ow_check( 'One get_terms() builds the whole tree', 1, $GLOBALS['ow_get_terms_calls'] );
ow_check( 'Every term reaches the tree', 5, $ow_cats->count() );
ow_check( 'Two roots', 2, count( $ow_cats->roots() ) );
ow_check( 'Hierarchy comes from parent, not from a second query', 3, $ow_cats->depth() );
ow_check( 'A term node carries its count as a badge', '3', $ow_cats->find( 'product_cat-20' )->badge() );

// --- Term visuals arrive by hook, and only by hook ---------------------------

ow_check( 'With nobody attached, a term has no visual', array(), $ow_cats->find( 'product_cat-10' )->visual() );

add_filter(
	'omniwp_navigation_term_visual',
	static function ( array $visuals, array $ids ): array {
		foreach ( $ids as $id ) {
			$visuals[ $id ] = array( 'image' => 'https://example.test/' . $id . '.png' );
		}

		return $visuals;
	},
	10,
	2
);

$GLOBALS['ow_get_terms_calls'] = 0;
$ow_with_visuals               = TaxonomyProvider::tree( array( 'taxonomy' => 'product_cat' ) );

ow_check(
	'A visual provider fills every node in one call',
	'https://example.test/10.png',
	$ow_with_visuals->find( 'product_cat-10' )->visual()['image'] ?? ''
);
ow_check( 'Attaching visuals costs no extra term query', 1, $GLOBALS['ow_get_terms_calls'] );

// --- Nothing in the model knows what a device is -----------------------------

$ow_model_device_reads = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 !== strpos( $ow_relative, 'includes/Navigation/' ) ) {
		continue;
	}

	if ( ! preg_match_all( '/wp_is_mobile|HTTP_USER_AGENT|\bis_mobile\b/', $ow_contents, $ow_matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_matches[0] as $ow_match ) {
		$ow_model_device_reads[] = $ow_relative . ':' . ( substr_count( substr( $ow_contents, 0, (int) $ow_match[1] ), "\n" ) + 1 );
	}
}

ow_nav_offenders(
	'Nothing under includes/Navigation/ reads device state',
	$ow_model_device_reads,
	'The model carries the axis as data. Resolving it is CSS.'
);

$ow_model_sibling_coupling = array();

foreach ( ow_plugin_sources() as $ow_relative => $ow_contents ) {
	if ( 0 !== strpos( $ow_relative, 'includes/Navigation/' ) ) {
		continue;
	}

	if ( preg_match( '/ShopKit\\\\|class_exists\s*\(/', $ow_contents ) ) {
		$ow_model_sibling_coupling[] = $ow_relative;
	}
}

ow_nav_offenders(
	'The model never names the sibling plugin',
	$ow_model_sibling_coupling,
	'A hook keeps the menu rendering when ShopKit is deactivated; a class name does not.'
);

// =====================================================================
ow_section( 'Rule 7 — the dock' );
// =====================================================================

$GLOBALS['ow_options']['OMNIWP_settings'] = array();

ow_assert( 'A fresh install does not grow a bar at the bottom of every page', ! Dock::is_enabled(), 'Every other e-commerce default here is 1; this one may not be.' );

ow_check(
	'The default slot list is the five the reference stores settled on',
	'home,categories,search,cart,account',
	(string) FieldRegistry::all()['navigation.dock_slots']['default']
);

Settings::update(
	array(
		'navigation.dock_enabled' => 1,
		'navigation.dock_slots'   => 'cart,home,not_a_slot,cart,account',
	)
);

$ow_slots = Dock::resolved_slots();
$ow_names = array_column( $ow_slots, 'name' );

ow_check( 'Order is the order the store asked for', array( 'cart', 'home', 'account' ), $ow_names );
ow_assert( 'A name the catalog does not know costs one icon, not the bar', ! in_array( 'not_a_slot', $ow_names, true ) );

Settings::update( array( 'navigation.dock_slots' => 'home,categories,search,cart,account,home,categories' ) );
ow_check( 'Never more than five', 5, count( Dock::resolved_slots() ) );

Settings::update( array( 'navigation.dock_slots' => 'nothing,valid,here' ) );
ow_check( 'A slot list that resolves to nothing yields nothing', 0, count( Dock::resolved_slots() ) );

// --- The badge is empty in the page, and filled by the fragment --------------

ow_check(
	'An empty badge carries no number and is hidden',
	'<span class="ow-dock__badge" data-ow-cart-badge="1" hidden></span>',
	Dock::badge_markup( 0 )
);

ow_assert( 'A filled badge is the same element with a number in it', false !== strpos( Dock::badge_markup( 3 ), '>3<' ) );
ow_assert( 'A filled badge is not hidden', false === strpos( Dock::badge_markup( 3 ), 'hidden' ) );

/*
 * The fragment key and the class have to be the same string. WooCommerce
 * replaces the element its key selects, so a rename on one side and not the
 * other leaves a badge that silently never updates and no error anywhere.
 */
ow_assert(
	'The fragment key selects the element the template prints',
	false !== strpos( Dock::badge_markup( 0 ), 'class="' . ltrim( Dock::BADGE_SELECTOR, '.' ) . '"' )
);

// --- The token, and who reads it --------------------------------------------

$ow_nav_css = ow_nav_assets( 'css' )['assets/css/omniwp-navigation.css'] ?? '';

ow_assert( 'The dock publishes --ow-dock-height', false !== strpos( $ow_nav_css, '--ow-dock-height:' ) );
ow_assert( 'It clears the phone safe area too', false !== strpos( $ow_nav_css, 'safe-area-inset-bottom' ) );
ow_assert( 'The document gets the strip back', false !== strpos( $ow_nav_css, 'padding-bottom: var(--ow-dock-height)' ) );

/*
 * Every consumer outside this stylesheet reads the token with a fallback.
 * Without one, a page where the dock is off — and so the stylesheet that defines
 * it never loaded — resolves to `bottom:` with nothing after it, and the element
 * falls back to static positioning. That is a worse bug than the overlap the
 * token exists to fix.
 */
$ow_tokenless = array();

foreach ( ow_nav_assets( 'css' ) as $ow_relative => $ow_contents ) {
	if ( 'assets/css/omniwp-navigation.css' === $ow_relative ) {
		continue;
	}

	if ( ! preg_match_all( '/var\(\s*--ow-dock-height\s*(,[^)]*)?\)/', $ow_contents, $ow_matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $ow_matches[0] as $ow_index => $ow_match ) {
		if ( '' === trim( (string) ( $ow_matches[1][ $ow_index ][0] ?? '' ) ) ) {
			$ow_tokenless[] = $ow_relative . ':' . ( substr_count( substr( $ow_contents, 0, (int) $ow_match[1] ), "\n" ) + 1 ) . '  ' . $ow_match[0];
		}
	}
}

ow_nav_offenders(
	'Every consumer of the token reads it with a fallback',
	$ow_tokenless,
	'The stylesheet that defines it only loads when the dock renders.'
);

/*
 * And somebody actually reads it. A token nothing consumes is the same shape of
 * lie as a setting nothing reads, which is the defect this phase opened on.
 */
$ow_token_readers = 0;

foreach ( ow_nav_assets( 'css' ) as $ow_relative => $ow_contents ) {
	if ( 'assets/css/omniwp-navigation.css' === $ow_relative ) {
		continue;
	}

	$ow_token_readers += preg_match_all( '/var\(\s*--ow-dock-height/', $ow_contents );
}

ow_assert( 'The elements that used to fight over this edge now stack on it', $ow_token_readers >= 4, 'docs/navigation.md §1.4 counted five; the dock itself is the sixth.' );

// --- The dock that was built twice and never met is gone ---------------------

$ow_ghost_dock = array();

foreach ( array_merge( ow_plugin_sources(), ow_nav_assets( 'css' ), ow_nav_assets( 'js' ) ) as $ow_relative => $ow_contents ) {
	/*
	 * A live reference, not a mention. Both files below now carry a comment
	 * explaining what used to be there and why it is gone, and the first version
	 * of this rule reported those comments as offenders — a rule that cannot tell
	 * a tombstone from a body will get the tombstone deleted.
	 */
	if ( preg_match( '/sl-mobile-bottom-dock\s*\{|[\'"][a-z._]*mobile_dock_enabled[\'"]/', $ow_contents ) ) {
		$ow_ghost_dock[] = $ow_relative;
	}
}

ow_nav_offenders(
	'Neither half of the dock that was built twice survives',
	$ow_ghost_dock,
	'A switch nothing read and a stylesheet nothing emitted: docs/navigation.md §1.1.'
);

ow_summary( 'Navigation' );
