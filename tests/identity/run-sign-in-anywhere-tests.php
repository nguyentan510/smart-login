<?php
/**
 * Sign-in-anywhere fitness — the dialog, the trigger and the place it leaves you.
 *
 * Normative spec: docs/sign-in-anywhere.md. Progress: docs/refactor-plan.md
 * Phase 19.
 *
 * Landed `spec` in 19.0, which is what that kind is for. Most of the rules below
 * were red or pending the day they landed, deliberately: a rule written after
 * the fix cannot fail, and a rule that has never failed is a comment.
 *
 * Five of them describe code that does not exist yet. Each one asserts its
 * subject was **found** before counting anything, so narrowing a rule to nothing
 * reports PENDING rather than passing vacuously — the failure mode 10.0's
 * PENDING rows were written to avoid, and the reason `sl_pending()` exists.
 *
 * Rule 9 is here because the defect it forbids was in the first draft of this
 * plan: `?smart_login_step=` already existed and the plan was adding `#login` as
 * a second, weaker vocabulary for it.
 *
 * Run with:  php tests/identity/run-sign-in-anywhere-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

// Rule 7 renders a real fragment, so the suite needs the template stubs and a
// visitor who is not signed in — the dialog's whole subject is somebody who is
// not.
$GLOBALS['sl_logged_in'] = false;

use SmartLogin\Frontend\Flow;

$sl_sources = sl_plugin_sources();

/**
 * The steps a visitor may ask for by URL.
 *
 * Read off `Flow` rather than restated here. A rule carrying its own copy of the
 * allowlist stops testing the allowlist and starts testing itself.
 *
 * @return string[]
 */
$sl_public_steps = static function (): array {
	$reflection = new ReflectionClass( Flow::class );

	foreach ( $reflection->getReflectionConstants() as $constant ) {
		if ( 'PUBLIC_STEPS' === $constant->getName() ) {
			return array_values( (array) $constant->getValue() );
		}
	}

	return array();
};

/**
 * Source of a file that may not exist yet, by path.
 */
$sl_file = static function ( string $relative ) use ( $sl_sources ): string {
	return $sl_sources[ $relative ] ?? sl_source( $relative );
};

/**
 * The same source with its comments removed.
 *
 * Rules 5, 6 and 10 all went red against code that satisfied them, because each
 * one matched the sentence *explaining* the property rather than a violation of
 * it: the template that stopped hard-coding `id="sl-identity"` says so in a
 * comment, and the dialog shell's header explains what a nested `<form>` does to
 * the form around it.
 *
 * `sl_method_body()` in the harness already strips comments for exactly this
 * reason — "a rule that reads prose is a rule that goes green when somebody
 * rewords a comment", and this is the same defect pointing the other way. There
 * was no file-level equivalent; this is it.
 */
$sl_code = static function ( string $source ): string {
	if ( '' === $source ) {
		return '';
	}

	$out = '';

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$out .= is_array( $token ) ? $token[1] : $token;
	}

	// HTML comments are not PHP tokens; the templates use them too.
	return (string) preg_replace( '/<!--.*?-->/s', '', $out );
};

// ---------------------------------------------------------------------------
sl_section( 'Rule 1 — every public step is reachable over REST (19.1, 19.2)' );
// ---------------------------------------------------------------------------

/*
 * Finding 3: the REST surface still models the two-screen login/register world
 * the form flow left behind. `identify` — the entire first step of the current
 * UX — exists only on the HTML path, so a client that is not an HTML form
 * cannot start the flow at all.
 */
$sl_rest  = $sl_file( 'includes/Frontend/class-rest-controller.php' );
$sl_steps = $sl_public_steps();

sl_assert(
	'Flow::PUBLIC_STEPS is readable, so the rule has a subject',
	array() !== $sl_steps,
	'Reflection found no PUBLIC_STEPS on Flow. The rule below would pass for want of anything to check.'
);

sl_assert(
	'the REST controller registers an identify route',
	(bool) preg_match( "/'identify'\s*=>/", $sl_rest ),
	'Finding 3: routes are register/verify/resend/login/forgot/reset. Identifier-first has no JSON path.'
);

/*
 * The fragment route, checked by the *callback it registers* rather than by the
 * string 'step'.
 *
 * The first draft matched /'step'\s*=>/ and went green the moment 19.1 added
 * `array( 'step' => $decision->step )` to a response body — a rule reporting
 * that a route existed because an array key was spelled the same way. Caught in
 * the run that landed 19.1, which is what these rules are for.
 */
if ( array() !== $sl_steps ) {
	sl_assert(
		'the REST controller can render every public step',
		(bool) preg_match( '/function\s+handle_step\s*\(/', $sl_rest ),
		'19.2 adds the fragment route. Steps needing cover: ' . implode( ', ', $sl_steps )
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 2 — one decision, not two (19.1)' );
// ---------------------------------------------------------------------------

/*
 * Decision 2: `FormController` and the popup path drive the same state machine.
 * Two implementations of handle_identify() would drift inside a phase — this
 * project has watched a rename cross an untested boundary five times.
 *
 * Asserted per method rather than per file. A file-level regex reports green as
 * soon as one method somewhere mentions the engine, which is precisely the
 * failure being guarded against.
 */
$sl_form_controller = $sl_file( 'includes/Frontend/class-form-controller.php' );

sl_assert(
	'FormController::handle_identify() was found, so the rule has a subject',
	'' !== sl_method_body( $sl_form_controller, 'handle_identify' ),
	'Without a body to read, the delegation checks below would pass vacuously.'
);

foreach ( array( 'handle_identify', 'handle_login', 'handle_verify_otp', 'handle_forgot' ) as $sl_handler ) {
	$sl_body = sl_method_body( $sl_form_controller, $sl_handler );

	if ( '' === $sl_body ) {
		sl_pending( 'FormController::' . $sl_handler . '() delegates to the flow engine', 'method not found' );
		continue;
	}

	/*
	 * Delegation is two properties, not one: the handler asks the engine, *and*
	 * it decides nothing itself. The first draft looked for the class name
	 * `FlowEngine` in the body, which a delegating handler has no reason to
	 * name — it calls `$this->engine()`. Worse, a handler that named the class
	 * and then set its own step would have passed.
	 *
	 * `Flow::set(` is the tell. It is how a controller says "render this step",
	 * and after 19.1 exactly one method in the class is allowed to say it:
	 * apply(), which is applying somebody else's decision.
	 */
	sl_assert(
		'FormController::' . $sl_handler . '() asks the flow engine',
		false !== strpos( $sl_body, 'engine()->' ),
		'19.1 moves the decision into FlowEngine so both controllers ask one implementation.'
	);

	sl_assert(
		'FormController::' . $sl_handler . '() decides nothing itself',
		false === strpos( $sl_body, 'Flow::set(' ) && false === strpos( $sl_body, 'Notices::' ),
		'A handler that picks its own step is a second copy of the state machine, whatever it delegates alongside.'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 3 — in place means in place (19.5)' );
// ---------------------------------------------------------------------------

/*
 * Finding 1, and the reason this phase exists. A new user is sent to
 * profile_url() regardless of the redirect_to they arrived with, and
 * profile_url() falls back to admin_url( 'profile.php' ) without WooCommerce.
 * Register from a blog post today and you land in wp-admin.
 */
$sl_context   = $sl_file( 'includes/Auth/class-auth-context.php' );
$sl_redirector = $sl_file( 'includes/Auth/class-post-auth-redirector.php' );

$sl_has_in_place = (bool) preg_match( '/in_place/', $sl_context );

sl_assert(
	'AuthContext carries whether the flow owns its own surface',
	$sl_has_in_place,
	'19.5 adds in_place. It is a fact about the request, which is what AuthContext is for.'
);

if ( ! $sl_has_in_place ) {
	sl_pending(
		'a new user authenticated in place is not handed a redirect to profile_url()',
		'AuthContext::$in_place'
	);
} else {
	$sl_redirect_body = sl_method_body( $sl_redirector, 'redirect' );

	sl_assert(
		'PostAuthRedirector::redirect() reads it',
		'' !== $sl_redirect_body && false !== strpos( $sl_redirect_body, 'in_place' ),
		'The new-user branch must not reach profile_url() when the flow renders its own surface.'
	);

	/*
	 * And then the behaviour, driven rather than read.
	 *
	 * This is the defect the whole phase is named after, so it is asserted
	 * against the object rather than against its source: a new member of a
	 * page-hosted flow still gets today's destination, and a new member of an
	 * in-place flow gets none at all.
	 *
	 * `wc_get_page_permalink()` is undefined in this process, which is exactly
	 * the site the finding is about — WooCommerce deactivated, `profile_url()`
	 * falling back to wp-admin. So the second assertion below is the literal
	 * finding: registering from a blog post landed people in wp-admin.
	 */
	$sl_new_user = static function ( bool $in_place ): \SmartLogin\Auth\AuthResult {
		$GLOBALS['sl_user_meta'] = array();

		return new \SmartLogin\Auth\AuthResult(
			7,
			new \SmartLogin\Auth\AuthContext(
				array(
					'auth_method' => 'otp',
					'user_id'     => 7,
					'is_new_user' => true,
					'in_place'    => $in_place,
				)
			),
			array( 'required_missing' => array() )
		);
	};

	$sl_page_url  = ( new \SmartLogin\Auth\PostAuthRedirector() )->redirect( $sl_new_user( false ), 'https://example.test/san-pham/ao-thun/' );
	$sl_place_url = ( new \SmartLogin\Auth\PostAuthRedirector() )->redirect( $sl_new_user( true ), 'https://example.test/san-pham/ao-thun/' );

	sl_assert(
		'a page-hosted registration still goes where it always did',
		false !== strpos( $sl_page_url, 'smartlogin_welcome=1' ),
		'19.5 must not change the shortcode path. Got: ' . $sl_page_url
	);

	sl_check( 'an in-place registration is given no destination at all', '', $sl_place_url );

	sl_assert(
		'and never one inside wp-admin',
		false === strpos( $sl_place_url, 'wp-admin' ),
		'Finding 1: profile_url() falls back to admin_url( profile.php ) without WooCommerce, and the page path proves it by returning ' . $sl_page_url
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 4 — no nonce in cacheable markup (19.3)' );
// ---------------------------------------------------------------------------

/*
 * Finding 5: RequestGuard::fields() writes a nonce and a one-hour stamp into
 * markup. Emitted on wp_footer, that is markup a full-page cache serves to every
 * anonymous visitor — a dead nonce for everyone, on production only.
 *
 * The subject is the footer hook. Until something registers one there is nothing
 * to check, and saying so is the point of PENDING.
 */
$sl_footer_files = array();

foreach ( $sl_sources as $sl_relative => $sl_contents ) {
	if ( preg_match( "/add_action\(\s*'wp_footer'/", $sl_contents ) ) {
		$sl_footer_files[] = $sl_relative;
	}
}

if ( array() === $sl_footer_files ) {
	sl_pending( 'nothing hooked to wp_footer emits RequestGuard::fields()', 'no wp_footer hook exists yet' );
} else {
	foreach ( $sl_footer_files as $sl_relative ) {
		sl_assert(
			$sl_relative . ' emits no nonce into the footer',
			! preg_match( '/RequestGuard::fields|wp_nonce_field/', $sl_sources[ $sl_relative ] ),
			'Decision 1: the dialog fetches its markup so the nonce is minted per request, never cached.'
		);
	}
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 5 — no duplicate ids when a page holds two copies (19.3)' );
// ---------------------------------------------------------------------------

/*
 * Finding 9: templates/form-auth.php hard-codes id="sl-identity" and autofocus.
 * A login page that also carries the dialog has two elements with one id, a
 * <label for> that resolves to whichever came first, and two controls claiming
 * focus. Shipped since the template was written; the dialog only makes it
 * reachable.
 */
$sl_form_auth = $sl_code( $sl_file( 'templates/form-auth.php' ) );

sl_assert(
	'templates/form-auth.php was found, so the rule has a subject',
	'' !== $sl_form_auth,
	'Without the template there is nothing to scope.'
);

if ( '' !== $sl_form_auth ) {
	sl_assert(
		'the identify template scopes its ids',
		! preg_match( '/id="sl-identity"/', $sl_form_auth ),
		'A literal id cannot appear twice on one page. 19.3 gives every id in this template a render-scoped prefix.'
	);

	sl_assert(
		'the identify template does not claim focus in markup',
		! preg_match( '/\bautofocus\b/', $sl_form_auth ),
		'Two autofocus controls is one too many. The dialog applies focus on open instead.'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 6 — the shell is outside every form (19.3)' );
// ---------------------------------------------------------------------------

/*
 * The second half of finding 9. DeferredForms exists because a nested </form>
 * closes the *outer* form and silently disables everything after it — the defect
 * where "Lưu thay đổi" had no form to submit and pressing it did nothing.
 */
$sl_dialog_template = $sl_code( $sl_file( 'templates/login-dialog.php' ) );

if ( '' === $sl_dialog_template ) {
	sl_pending( 'the dialog shell contains no nested form', 'templates/login-dialog.php' );
} else {
	sl_assert(
		'the dialog shell contains no <form>',
		! preg_match( '/<form\b/', $sl_dialog_template ),
		'The shell is a container. Forms arrive inside the fetched fragment, after the page has closed its own.'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 7 — the fragment does not link into the API (19.2)' );
// ---------------------------------------------------------------------------

/*
 * Finding 8: Flow::url() computes step links with remove_query_arg() against the
 * *current request*, which inside a REST request is /wp-json/. A fragment
 * rendered over REST emits links into the API unless the renderer is given the
 * host page explicitly.
 */
$sl_flow = $sl_file( 'includes/Frontend/class-flow.php' );

sl_assert(
	'Flow::url() was found, so the rule has a subject',
	'' !== sl_method_body( $sl_flow, 'url' ),
	'Without the method there is nothing to give a base to.'
);

sl_assert(
	'Flow can be told which page it is rendering for',
	(bool) preg_match( '/function\s+(set_base|base)\s*\(/', $sl_flow ),
	'19.2 adds a render context. Without it every step link in a fetched fragment points at /wp-json/.'
);

/*
 * And then the property itself, by rendering one.
 *
 * A structural check that `set_base()` exists says nothing about whether the
 * templates read it. This renders the identify step for a product page and
 * looks at what came out — the stubbed `remove_query_arg()` answers
 * `/my-account/` for "the current request", so a fragment that ignored its base
 * would say so out loud.
 */
if ( class_exists( \SmartLogin\Frontend\FragmentRenderer::class ) ) {
	$sl_host     = 'https://example.test/san-pham/ao-thun/';
	$sl_fragment = sl_capture(
		static function () use ( $sl_host ) {
			$GLOBALS['sl_rendered'] = ( new \SmartLogin\Frontend\FragmentRenderer() )->render(
				Flow::STEP_IDENTIFY,
				$sl_host,
				$sl_host
			);
		}
	);

	$sl_html = (string) ( $GLOBALS['sl_rendered']['html'] ?? '' );

	sl_assert(
		'a fragment renders at all',
		null === $sl_fragment['error'] && '' !== $sl_html,
		'render failed: ' . (string) $sl_fragment['error']
	);

	sl_assert(
		'a fragment carries the host page, not the API',
		'' !== $sl_html && false === strpos( $sl_html, '/wp-json/' ),
		'Finding 8: Flow::url() computes links against the current request, which inside REST is the API URL.'
	);

	sl_assert(
		'a fragment returns the visitor to the page they were on',
		false !== strpos( $sl_html, 'value="' . $sl_host . '"' ),
		'redirect_to must be the host page. form-auth.php read $_GET directly until 19.2.'
	);

	sl_assert(
		'a fragment does not offer the current request as a destination',
		false === strpos( $sl_html, '/my-account/' ),
		'The stub answers /my-account/ for "the current request". Seeing it means the base was ignored.'
	);
} else {
	sl_pending( 'a fragment carries the host page, not the API', 'includes/Frontend/class-fragment-renderer.php' );
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 8 — the trigger degrades (19.4)' );
// ---------------------------------------------------------------------------

$sl_launcher = $sl_file( 'assets/js/smart-login-launcher.js' );

if ( '' === $sl_launcher ) {
	sl_pending( 'the launcher resolves #login to the canonical query form', 'assets/js/smart-login-launcher.js' );
	sl_pending( 'a blocked script leaves a working link to the sign-in page', 'assets/js/smart-login-launcher.js' );
} else {
	sl_assert(
		'the launcher resolves the hash to the canonical step parameter',
		false !== strpos( $sl_launcher, 'smart_login_step' ),
		'Decision 4: the query parameter is canonical because it is the only form the server can see.'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 9 — one vocabulary (19.4)' );
// ---------------------------------------------------------------------------

/*
 * Finding 11, and the defect this rule exists to prevent was in the first draft
 * of this plan. `?smart_login_step=` already existed, was already allowlisted
 * against PUBLIC_STEPS and was already generated by Flow::url(). Adding a second
 * list of step names in JavaScript is how the two drift.
 */
if ( '' === $sl_launcher ) {
	sl_pending( 'no step allowlist is restated in JavaScript', 'assets/js/smart-login-launcher.js' );
} else {
	$sl_restated = 0;

	foreach ( $sl_steps as $sl_step ) {
		if ( preg_match( "/['\"]" . preg_quote( $sl_step, '/' ) . "['\"]/", $sl_launcher ) ) {
			++$sl_restated;
		}
	}

	// One is the alias map's target and is expected; a full copy of the list is
	// the second source of truth this rule forbids.
	sl_assert(
		'the launcher does not restate PUBLIC_STEPS',
		$sl_restated < count( $sl_steps ),
		'Localize the allowlist from PHP. Found ' . $sl_restated . ' of ' . count( $sl_steps ) . ' step names hard-coded.'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 10 — no link is rewritten (19.8)' );
// ---------------------------------------------------------------------------

/*
 * Decision 8. Capture is allowed to default on for exactly one reason: with the
 * script blocked, removed, or simply not loaded yet, every captured link is the
 * ordinary link the theme wrote. A plugin that rewrote href would own a failure
 * mode where its own script is the only thing keeping the site's login working.
 */
if ( '' === $sl_launcher ) {
	sl_pending( 'the launcher never assigns to href', 'assets/js/smart-login-launcher.js' );
} else {
	sl_assert(
		'the launcher never assigns to href',
		! preg_match( '/\.href\s*=|setAttribute\(\s*[\'"]href/', $sl_launcher ),
		'19.8 intercepts clicks. Rewriting href is what makes a captured link unable to survive a script failure.'
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 11 — one session writer, and it fires wp_login (19.9)' );
// ---------------------------------------------------------------------------

/*
 * Finding 13. WooCommerce merges a guest cart with the member's saved cart only
 * when _woocommerce_load_saved_cart_after_login is set, and that meta is written
 * by wc_user_logged_in() on the wp_login action. So the cart survives a sign-in
 * because SessionIssuer fires wp_login — and nothing asserted that.
 *
 * Green on arrival, which is the 18.0 precedent: a property nothing has ever
 * checked is worth a rule the day somebody notices it holds. It is also what
 * stops 19.9 being satisfied by deleting the thing it depends on.
 */
$sl_writers = array();

foreach ( $sl_sources as $sl_relative => $sl_contents ) {
	if ( 0 !== strpos( $sl_relative, 'includes/' ) ) {
		continue;
	}

	if ( preg_match( '/wp_set_auth_cookie\s*\(/', $sl_contents ) ) {
		$sl_writers[] = $sl_relative;
	}
}

sl_check( 'exactly one file mints a session', 1, count( $sl_writers ) );

foreach ( $sl_writers as $sl_relative ) {
	sl_assert(
		$sl_relative . ' fires wp_login',
		(bool) preg_match( "/do_action\(\s*'wp_login'/", $sl_sources[ $sl_relative ] ),
		"WooCommerce's guest-cart merge hangs off this action. Without it the cart silently stops merging."
	);
}

// ---------------------------------------------------------------------------
sl_section( 'Rule 12 — the provider round trip comes home (19.6)' );
// ---------------------------------------------------------------------------

/*
 * A provider hand-off is a full-page navigation and cannot be otherwise, so the
 * dialog closes when the visitor leaves. Coming back to the page they started
 * on is the whole of the round trip, and it hangs on two values: the return url
 * the start link carries, and the marker that says a dialog asked.
 *
 * The stored transaction needs a database and is asserted in tests/integration/.
 * What is checkable here is the link, and whether the marker is conditional —
 * a marker applied unconditionally would reopen the dialog for every visitor
 * who ever used Google, including on the page path.
 */
$sl_host_page = 'https://example.test/san-pham/ao-thun/';

$sl_plain  = \SmartLogin\Auth\ProviderAuthController::start_url( 'google', $sl_host_page );
$sl_placed = \SmartLogin\Auth\ProviderAuthController::start_url( 'google', $sl_host_page, false, true );

sl_assert(
	'a start url carries the page the visitor was on',
	false !== strpos( rawurldecode( $sl_plain ), $sl_host_page ),
	'form-auth.php passes Flow::redirect_to(), which for a fragment is the host page. Got: ' . $sl_plain
);

sl_assert(
	'a dialog stamps the return url so the round trip can reopen it',
	false !== strpos( rawurldecode( $sl_placed ), \SmartLogin\Auth\ProviderAuthController::IN_PLACE_ARG ),
	'Without the marker a new member returns to the account page instead of the page they left.'
);

sl_assert(
	'a page-hosted button stamps nothing',
	false === strpos( rawurldecode( $sl_plain ), \SmartLogin\Auth\ProviderAuthController::IN_PLACE_ARG ),
	'The marker must be conditional. Applied always, it would reopen a dialog on the sign-in page too.'
);

sl_check(
	'an off-site return url is still refused',
	false,
	false !== strpos( \SmartLogin\Auth\ProviderAuthController::start_url( 'google', 'https://evil.test/', false, true ), 'evil.test' )
);

/*
 * And the marker is the flag the plugin already had, not a second spelling of
 * it. Rule 9's argument, applied to a URL instead of to a script: this project
 * has watched a rename cross an untested boundary six times, and two names for
 * "this member has just registered" is how a seventh starts.
 */
sl_assert(
	'the reopen flag is the one a finished registration already writes',
	false !== strpos( $sl_file( 'includes/Auth/class-provider-auth-controller.php' ), 'smartlogin_welcome' )
		&& false !== strpos( $sl_file( 'includes/Frontend/class-login-dialog.php' ), 'smartlogin_welcome' ),
	'PostAuthRedirector and Shortcodes::is_welcome_request() already use it.'
);

// ---------------------------------------------------------------------------
sl_section( 'Rule 13 — capture is bounded, and reversible (19.8)' );
// ---------------------------------------------------------------------------

/*
 * Capture defaults on, which is only defensible because it cannot strand
 * anybody: clicks are intercepted and `href` is never touched, so a blocked
 * script leaves every captured link exactly as its author wrote it. Rule 10
 * asserts that half.
 *
 * This rule asserts the other half — that the list is *named*, not guessed, and
 * that switching it off restores today's behaviour exactly.
 */
$sl_dialog_php = $sl_file( 'includes/Frontend/class-login-dialog.php' );
$sl_launcher_js = $sl_file( 'assets/js/smart-login-launcher.js' );

sl_assert(
	'the captured list is resolved in PHP, not guessed in JavaScript',
	false !== strpos( $sl_dialog_php, 'captured_urls' )
		&& false !== strpos( $sl_launcher_js, 'data.captured' ),
	'A URL the plugin cannot name is a URL it must not claim.'
);

sl_assert(
	'nothing matches on link text',
	! preg_match( '/textContent|innerText/', $sl_launcher_js ),
	'A heuristic over link text fires on an article about signing in. Named URLs only — that is the sub-phase\'s Not-in-scope line.'
);

sl_assert(
	'a wp-login.php action is refused',
	false !== strpos( $sl_launcher_js, "searchParams.get( 'action' )" ),
	'wp-login.php?action=logout is not a sign-in, and capturing it traps somebody trying to leave.'
);

sl_assert(
	'an opt-out attribute is honoured',
	false !== strpos( $sl_launcher_js, 'data-no-smart-login' ),
	'One attribute has to be enough for a site that wants one link left alone.'
);

/*
 * And the off switch, driven rather than read. `smart_login_capture_links`
 * returning an empty array must leave the launcher with nothing to match, which
 * is what "restores today's behaviour exactly" means.
 */
add_filter(
	'smart_login_capture_links',
	static function (): array {
		return array();
	}
);

sl_check(
	'the filter switches capture off entirely',
	array(),
	\SmartLogin\Frontend\LoginDialog::captured_urls()
);

remove_all_filters( 'smart_login_capture_links' );

sl_assert(
	'and the list is non-empty by default',
	array() !== \SmartLogin\Frontend\LoginDialog::captured_urls(),
	'A capture that is off by default is a feature nobody receives, which was the alternative this declined.'
);

// ---------------------------------------------------------------------------
sl_section( 'Rule 14 — one heading per screen (19.10)' );
// ---------------------------------------------------------------------------

/*
 * The dialog shell draws a title, because that element is the dialog's
 * accessible name through `aria-labelledby`. A fragment that draws its own puts
 * the same sentence on screen twice, forty pixels apart — which is what shipped,
 * and what a screenshot showed in a second.
 *
 * Asserted over the *rendered* fragment rather than the template source: the
 * template's heading is conditional now, and a rule reading the source would
 * pass while the condition was wrong. Every step gets the same check, because
 * the one that regresses will be a step somebody adds later.
 */
if ( class_exists( \SmartLogin\Frontend\FragmentRenderer::class ) ) {
	$sl_host  = 'https://example.test/san-pham/ao-thun/';
	$sl_titles = array();

	foreach ( array( Flow::STEP_IDENTIFY, Flow::STEP_FORGOT ) as $sl_step ) {
		$sl_capture = sl_capture(
			static function () use ( $sl_step, $sl_host ) {
				$GLOBALS['sl_fragment'] = ( new \SmartLogin\Frontend\FragmentRenderer() )->render( $sl_step, $sl_host, $sl_host );
			}
		);

		$sl_markup = (string) ( $GLOBALS['sl_fragment']['html'] ?? '' );
		$sl_name   = (string) ( $GLOBALS['sl_fragment']['title'] ?? '' );

		if ( '' === $sl_markup ) {
			sl_pending( 'the ' . $sl_step . ' fragment draws no heading of its own', 'render returned nothing' );
			continue;
		}

		sl_assert(
			'the ' . $sl_step . ' fragment draws no <h2> of its own',
			! preg_match( '/<h2\b/', $sl_markup ),
			'The shell already drew one, and it is the dialog\'s accessible name. Two is one too many for a screen reader before it is one too many for a designer.'
		);

		$sl_titles[] = $sl_name;
	}

	sl_assert(
		'the dialog title is short enough to be a title',
		'' !== ( $sl_titles[0] ?? '' ) && mb_strlen( $sl_titles[0] ) <= 20,
		'Got: "' . ( $sl_titles[0] ?? '' ) . '"'
	);

	sl_check( 'and it is the one the screen was asked for', 'Đăng nhập', $sl_titles[0] ?? '' );
} else {
	sl_pending( 'a fragment draws no heading of its own', 'includes/Frontend/class-fragment-renderer.php' );
}

sl_summary( 'Sign-in anywhere' );
