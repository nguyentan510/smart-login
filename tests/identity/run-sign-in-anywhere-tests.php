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
 * PENDING rows were written to avoid, and the reason `ow_pending()` exists.
 *
 * Rule 9 is here because the defect it forbids was in the first draft of this
 * plan: `?OMNIWP_step=` already existed and the plan was adding `#login` as
 * a second, weaker vocabulary for it.
 *
 * Run with:  php tests/identity/run-sign-in-anywhere-tests.php
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

// Rule 7 renders a real fragment, so the suite needs the template stubs and a
// visitor who is not signed in — the dialog's whole subject is somebody who is
// not.
$GLOBALS['ow_logged_in'] = false;

use OmniWP\Frontend\Flow;

$ow_sources = ow_plugin_sources();

/**
 * The steps a visitor may ask for by URL.
 *
 * Read off `Flow` rather than restated here. A rule carrying its own copy of the
 * allowlist stops testing the allowlist and starts testing itself.
 *
 * @return string[]
 */
$ow_public_steps = static function (): array {
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
$ow_file = static function ( string $relative ) use ( $ow_sources ): string {
	return $ow_sources[ $relative ] ?? ow_source( $relative );
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
 * `ow_method_body()` in the harness already strips comments for exactly this
 * reason — "a rule that reads prose is a rule that goes green when somebody
 * rewords a comment", and this is the same defect pointing the other way. There
 * was no file-level equivalent; this is it.
 */
$ow_code = static function ( string $source ): string {
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
ow_section( 'Rule 1 — every public step is reachable over REST (19.1, 19.2)' );
// ---------------------------------------------------------------------------

/*
 * Finding 3: the REST surface still models the two-screen login/register world
 * the form flow left behind. `identify` — the entire first step of the current
 * UX — exists only on the HTML path, so a client that is not an HTML form
 * cannot start the flow at all.
 */
$ow_rest  = $ow_file( 'includes/Frontend/class-rest-controller.php' );
$ow_steps = $ow_public_steps();

ow_assert(
	'Flow::PUBLIC_STEPS is readable, so the rule has a subject',
	array() !== $ow_steps,
	'Reflection found no PUBLIC_STEPS on Flow. The rule below would pass for want of anything to check.'
);

ow_assert(
	'the REST controller registers an identify route',
	(bool) preg_match( "/'identify'\s*=>/", $ow_rest ),
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
if ( array() !== $ow_steps ) {
	ow_assert(
		'the REST controller can render every public step',
		(bool) preg_match( '/function\s+handle_step\s*\(/', $ow_rest ),
		'19.2 adds the fragment route. Steps needing cover: ' . implode( ', ', $ow_steps )
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 2 — one decision, not two (19.1)' );
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
$ow_form_controller = $ow_file( 'includes/Frontend/class-form-controller.php' );

ow_assert(
	'FormController::handle_identify() was found, so the rule has a subject',
	'' !== ow_method_body( $ow_form_controller, 'handle_identify' ),
	'Without a body to read, the delegation checks below would pass vacuously.'
);

foreach ( array( 'handle_identify', 'handle_login', 'handle_verify_otp', 'handle_forgot' ) as $ow_handler ) {
	$ow_body = ow_method_body( $ow_form_controller, $ow_handler );

	if ( '' === $ow_body ) {
		ow_pending( 'FormController::' . $ow_handler . '() delegates to the flow engine', 'method not found' );
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
	ow_assert(
		'FormController::' . $ow_handler . '() asks the flow engine',
		false !== strpos( $ow_body, 'engine()->' ),
		'19.1 moves the decision into FlowEngine so both controllers ask one implementation.'
	);

	ow_assert(
		'FormController::' . $ow_handler . '() decides nothing itself',
		false === strpos( $ow_body, 'Flow::set(' ) && false === strpos( $ow_body, 'Notices::' ),
		'A handler that picks its own step is a second copy of the state machine, whatever it delegates alongside.'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 3 — in place means in place (19.5)' );
// ---------------------------------------------------------------------------

/*
 * Finding 1, and the reason this phase exists. A new user is sent to
 * profile_url() regardless of the redirect_to they arrived with, and
 * profile_url() falls back to admin_url( 'profile.php' ) without WooCommerce.
 * Register from a blog post today and you land in wp-admin.
 */
$ow_context   = $ow_file( 'includes/Auth/class-auth-context.php' );
$ow_redirector = $ow_file( 'includes/Auth/class-post-auth-redirector.php' );

$ow_has_in_place = (bool) preg_match( '/in_place/', $ow_context );

ow_assert(
	'AuthContext carries whether the flow owns its own surface',
	$ow_has_in_place,
	'19.5 adds in_place. It is a fact about the request, which is what AuthContext is for.'
);

if ( ! $ow_has_in_place ) {
	ow_pending(
		'a new user authenticated in place is not handed a redirect to profile_url()',
		'AuthContext::$in_place'
	);
} else {
	$ow_redirect_body = ow_method_body( $ow_redirector, 'redirect' );

	ow_assert(
		'PostAuthRedirector::redirect() reads it',
		'' !== $ow_redirect_body && false !== strpos( $ow_redirect_body, 'in_place' ),
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
	$ow_new_user = static function ( bool $in_place ): \OmniWP\Auth\AuthResult {
		$GLOBALS['ow_user_meta'] = array();

		return new \OmniWP\Auth\AuthResult(
			7,
			new \OmniWP\Auth\AuthContext(
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

	$ow_page_url  = ( new \OmniWP\Auth\PostAuthRedirector() )->redirect( $ow_new_user( false ), 'https://example.test/san-pham/ao-thun/' );
	$ow_place_url = ( new \OmniWP\Auth\PostAuthRedirector() )->redirect( $ow_new_user( true ), 'https://example.test/san-pham/ao-thun/' );

	ow_assert(
		'a page-hosted registration still goes where it always did',
		false !== strpos( $ow_page_url, 'OmniWP_welcome=1' ),
		'19.5 must not change the shortcode path. Got: ' . $ow_page_url
	);

	ow_check( 'an in-place registration is given no destination at all', '', $ow_place_url );

	ow_assert(
		'and never one inside wp-admin',
		false === strpos( $ow_place_url, 'wp-admin' ),
		'Finding 1: profile_url() falls back to admin_url( profile.php ) without WooCommerce, and the page path proves it by returning ' . $ow_page_url
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 4 — no nonce in cacheable markup (19.3)' );
// ---------------------------------------------------------------------------

/*
 * Finding 5: RequestGuard::fields() writes a nonce and a one-hour stamp into
 * markup. Emitted on wp_footer, that is markup a full-page cache serves to every
 * anonymous visitor — a dead nonce for everyone, on production only.
 *
 * The subject is the footer hook. Until something registers one there is nothing
 * to check, and saying so is the point of PENDING.
 */
$ow_footer_files = array();

foreach ( $ow_sources as $ow_relative => $ow_contents ) {
	if ( preg_match( "/add_action\(\s*'wp_footer'/", $ow_contents ) ) {
		$ow_footer_files[] = $ow_relative;
	}
}

if ( array() === $ow_footer_files ) {
	ow_pending( 'nothing hooked to wp_footer emits RequestGuard::fields()', 'no wp_footer hook exists yet' );
} else {
	foreach ( $ow_footer_files as $ow_relative ) {
		ow_assert(
			$ow_relative . ' emits no nonce into the footer',
			! preg_match( '/RequestGuard::fields|wp_nonce_field/', $ow_sources[ $ow_relative ] ),
			'Decision 1: the dialog fetches its markup so the nonce is minted per request, never cached.'
		);
	}
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 5 — no duplicate ids when a page holds two copies (19.3)' );
// ---------------------------------------------------------------------------

/*
 * Finding 9: templates/form-auth.php hard-codes id="sl-identity" and autofocus.
 * A login page that also carries the dialog has two elements with one id, a
 * <label for> that resolves to whichever came first, and two controls claiming
 * focus. Shipped since the template was written; the dialog only makes it
 * reachable.
 */
$ow_form_auth = $ow_code( $ow_file( 'templates/form-auth.php' ) );

ow_assert(
	'templates/form-auth.php was found, so the rule has a subject',
	'' !== $ow_form_auth,
	'Without the template there is nothing to scope.'
);

if ( '' !== $ow_form_auth ) {
	ow_assert(
		'the identify template scopes its ids',
		! preg_match( '/id="sl-identity"/', $ow_form_auth ),
		'A literal id cannot appear twice on one page. 19.3 gives every id in this template a render-scoped prefix.'
	);

	ow_assert(
		'the identify template does not claim focus in markup',
		! preg_match( '/\bautofocus\b/', $ow_form_auth ),
		'Two autofocus controls is one too many. The dialog applies focus on open instead.'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 6 — the shell is outside every form (19.3)' );
// ---------------------------------------------------------------------------

/*
 * The second half of finding 9. DeferredForms exists because a nested </form>
 * closes the *outer* form and silently disables everything after it — the defect
 * where "Lưu thay đổi" had no form to submit and pressing it did nothing.
 */
$ow_dialog_template = $ow_code( $ow_file( 'templates/login-dialog.php' ) );

if ( '' === $ow_dialog_template ) {
	ow_pending( 'the dialog shell contains no nested form', 'templates/login-dialog.php' );
} else {
	ow_assert(
		'the dialog shell contains no <form>',
		! preg_match( '/<form\b/', $ow_dialog_template ),
		'The shell is a container. Forms arrive inside the fetched fragment, after the page has closed its own.'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 7 — the fragment does not link into the API (19.2)' );
// ---------------------------------------------------------------------------

/*
 * Finding 8: Flow::url() computes step links with remove_query_arg() against the
 * *current request*, which inside a REST request is /wp-json/. A fragment
 * rendered over REST emits links into the API unless the renderer is given the
 * host page explicitly.
 */
$ow_flow = $ow_file( 'includes/Frontend/class-flow.php' );

ow_assert(
	'Flow::url() was found, so the rule has a subject',
	'' !== ow_method_body( $ow_flow, 'url' ),
	'Without the method there is nothing to give a base to.'
);

ow_assert(
	'Flow can be told which page it is rendering for',
	(bool) preg_match( '/function\s+(set_base|base)\s*\(/', $ow_flow ),
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
if ( class_exists( \OmniWP\Frontend\FragmentRenderer::class ) ) {
	$ow_host     = 'https://example.test/san-pham/ao-thun/';
	$ow_fragment = ow_capture(
		static function () use ( $ow_host ) {
			$GLOBALS['ow_rendered'] = ( new \OmniWP\Frontend\FragmentRenderer() )->render(
				Flow::STEP_IDENTIFY,
				$ow_host,
				$ow_host
			);
		}
	);

	$ow_html = (string) ( $GLOBALS['ow_rendered']['html'] ?? '' );

	ow_assert(
		'a fragment renders at all',
		null === $ow_fragment['error'] && '' !== $ow_html,
		'render failed: ' . (string) $ow_fragment['error']
	);

	ow_assert(
		'a fragment carries the host page, not the API',
		'' !== $ow_html && false === strpos( $ow_html, '/wp-json/' ),
		'Finding 8: Flow::url() computes links against the current request, which inside REST is the API URL.'
	);

	ow_assert(
		'a fragment returns the visitor to the page they were on',
		false !== strpos( $ow_html, 'value="' . $ow_host . '"' ),
		'redirect_to must be the host page. form-auth.php read $_GET directly until 19.2.'
	);

	ow_assert(
		'a fragment does not offer the current request as a destination',
		false === strpos( $ow_html, '/my-account/' ),
		'The stub answers /my-account/ for "the current request". Seeing it means the base was ignored.'
	);
} else {
	ow_pending( 'a fragment carries the host page, not the API', 'includes/Frontend/class-fragment-renderer.php' );
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 8 — the trigger degrades (19.4)' );
// ---------------------------------------------------------------------------

$ow_launcher = $ow_file( 'assets/js/omniwp-launcher.js' );

if ( '' === $ow_launcher ) {
	ow_pending( 'the launcher resolves #login to the canonical query form', 'assets/js/omniwp-launcher.js' );
	ow_pending( 'a blocked script leaves a working link to the sign-in page', 'assets/js/omniwp-launcher.js' );
} else {
	ow_assert(
		'the launcher resolves the hash to the canonical step parameter',
		false !== strpos( $ow_launcher, 'OMNIWP_step' ),
		'Decision 4: the query parameter is canonical because it is the only form the server can see.'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 9 — one vocabulary (19.4)' );
// ---------------------------------------------------------------------------

/*
 * Finding 11, and the defect this rule exists to prevent was in the first draft
 * of this plan. `?OMNIWP_step=` already existed, was already allowlisted
 * against PUBLIC_STEPS and was already generated by Flow::url(). Adding a second
 * list of step names in JavaScript is how the two drift.
 */
if ( '' === $ow_launcher ) {
	ow_pending( 'no step allowlist is restated in JavaScript', 'assets/js/omniwp-launcher.js' );
} else {
	$ow_restated = 0;

	foreach ( $ow_steps as $ow_step ) {
		if ( preg_match( "/['\"]" . preg_quote( $ow_step, '/' ) . "['\"]/", $ow_launcher ) ) {
			++$ow_restated;
		}
	}

	// One is the alias map's target and is expected; a full copy of the list is
	// the second source of truth this rule forbids.
	ow_assert(
		'the launcher does not restate PUBLIC_STEPS',
		$ow_restated < count( $ow_steps ),
		'Localize the allowlist from PHP. Found ' . $ow_restated . ' of ' . count( $ow_steps ) . ' step names hard-coded.'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 10 — no link is rewritten (19.8)' );
// ---------------------------------------------------------------------------

/*
 * Decision 8. Capture is allowed to default on for exactly one reason: with the
 * script blocked, removed, or simply not loaded yet, every captured link is the
 * ordinary link the theme wrote. A plugin that rewrote href would own a failure
 * mode where its own script is the only thing keeping the site's login working.
 */
if ( '' === $ow_launcher ) {
	ow_pending( 'the launcher never assigns to href', 'assets/js/omniwp-launcher.js' );
} else {
	ow_assert(
		'the launcher never assigns to href',
		! preg_match( '/\.href\s*=|setAttribute\(\s*[\'"]href/', $ow_launcher ),
		'19.8 intercepts clicks. Rewriting href is what makes a captured link unable to survive a script failure.'
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 11 — one session writer, and it fires wp_login (19.9)' );
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
$ow_writers = array();

foreach ( $ow_sources as $ow_relative => $ow_contents ) {
	if ( 0 !== strpos( $ow_relative, 'includes/' ) ) {
		continue;
	}

	if ( preg_match( '/wp_set_auth_cookie\s*\(/', $ow_contents ) ) {
		$ow_writers[] = $ow_relative;
	}
}

ow_check( 'exactly one file mints a session', 1, count( $ow_writers ) );

foreach ( $ow_writers as $ow_relative ) {
	ow_assert(
		$ow_relative . ' fires wp_login',
		(bool) preg_match( "/do_action\(\s*'wp_login'/", $ow_sources[ $ow_relative ] ),
		"WooCommerce's guest-cart merge hangs off this action. Without it the cart silently stops merging."
	);
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 12 — the provider round trip comes home (19.6)' );
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
$ow_host_page = 'https://example.test/san-pham/ao-thun/';

$ow_plain  = \OmniWP\Auth\ProviderAuthController::start_url( 'google', $ow_host_page );
$ow_placed = \OmniWP\Auth\ProviderAuthController::start_url( 'google', $ow_host_page, false, true );

ow_assert(
	'a start url carries the page the visitor was on',
	false !== strpos( rawurldecode( $ow_plain ), $ow_host_page ),
	'form-auth.php passes Flow::redirect_to(), which for a fragment is the host page. Got: ' . $ow_plain
);

ow_assert(
	'a dialog stamps the return url so the round trip can reopen it',
	false !== strpos( rawurldecode( $ow_placed ), \OmniWP\Auth\ProviderAuthController::IN_PLACE_ARG ),
	'Without the marker a new member returns to the account page instead of the page they left.'
);

ow_assert(
	'a page-hosted button stamps nothing',
	false === strpos( rawurldecode( $ow_plain ), \OmniWP\Auth\ProviderAuthController::IN_PLACE_ARG ),
	'The marker must be conditional. Applied always, it would reopen a dialog on the sign-in page too.'
);

ow_check(
	'an off-site return url is still refused',
	false,
	false !== strpos( \OmniWP\Auth\ProviderAuthController::start_url( 'google', 'https://evil.test/', false, true ), 'evil.test' )
);

/*
 * And the marker is the flag the plugin already had, not a second spelling of
 * it. Rule 9's argument, applied to a URL instead of to a script: this project
 * has watched a rename cross an untested boundary six times, and two names for
 * "this member has just registered" is how a seventh starts.
 */
ow_assert(
	'the reopen flag is the one a finished registration already writes',
	false !== strpos( $ow_file( 'includes/Auth/class-provider-auth-controller.php' ), 'OmniWP_welcome' )
		&& false !== strpos( $ow_file( 'includes/Frontend/class-login-dialog.php' ), 'OmniWP_welcome' ),
	'PostAuthRedirector and Shortcodes::is_welcome_request() already use it.'
);

// ---------------------------------------------------------------------------
ow_section( 'Rule 13 — capture is bounded, and reversible (19.8)' );
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
$ow_dialog_php = $ow_file( 'includes/Frontend/class-login-dialog.php' );
$ow_launcher_js = $ow_file( 'assets/js/omniwp-launcher.js' );

ow_assert(
	'the captured list is resolved in PHP, not guessed in JavaScript',
	false !== strpos( $ow_dialog_php, 'captured_urls' )
		&& false !== strpos( $ow_launcher_js, 'data.captured' ),
	'A URL the plugin cannot name is a URL it must not claim.'
);

ow_assert(
	'nothing matches on link text',
	! preg_match( '/textContent|innerText/', $ow_launcher_js ),
	'A heuristic over link text fires on an article about signing in. Named URLs only — that is the sub-phase\'s Not-in-scope line.'
);

ow_assert(
	'a wp-login.php action is refused',
	false !== strpos( $ow_launcher_js, "searchParams.get( 'action' )" ),
	'wp-login.php?action=logout is not a sign-in, and capturing it traps somebody trying to leave.'
);

ow_assert(
	'an opt-out attribute is honoured',
	false !== strpos( $ow_launcher_js, 'data-no-omniwp' ),
	'One attribute has to be enough for a site that wants one link left alone.'
);

/*
 * And the off switch, driven rather than read. `OMNIWP_capture_links`
 * returning an empty array must leave the launcher with nothing to match, which
 * is what "restores today's behaviour exactly" means.
 */
add_filter( 'omniwp_capture_links',
	static function (): array {
		return array();
	}
);

ow_check(
	'the filter switches capture off entirely',
	array(),
	\OmniWP\Frontend\LoginDialog::captured_urls()
);

remove_all_filters( 'omniwp_capture_links' );

ow_assert(
	'and the list is non-empty by default',
	array() !== \OmniWP\Frontend\LoginDialog::captured_urls(),
	'A capture that is off by default is a feature nobody receives, which was the alternative this declined.'
);

// ---------------------------------------------------------------------------
ow_section( 'Rule 14 — one heading per screen (19.10)' );
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
if ( class_exists( \OmniWP\Frontend\FragmentRenderer::class ) ) {
	$ow_host  = 'https://example.test/san-pham/ao-thun/';
	$ow_titles = array();

	foreach ( array( Flow::STEP_IDENTIFY, Flow::STEP_FORGOT ) as $ow_step ) {
		$ow_capture = ow_capture(
			static function () use ( $ow_step, $ow_host ) {
				$GLOBALS['ow_fragment'] = ( new \OmniWP\Frontend\FragmentRenderer() )->render( $ow_step, $ow_host, $ow_host );
			}
		);

		$ow_markup = (string) ( $GLOBALS['ow_fragment']['html'] ?? '' );
		$ow_name   = (string) ( $GLOBALS['ow_fragment']['title'] ?? '' );

		if ( '' === $ow_markup ) {
			ow_pending( 'the ' . $ow_step . ' fragment draws no heading of its own', 'render returned nothing' );
			continue;
		}

		ow_assert(
			'the ' . $ow_step . ' fragment draws no <h2> of its own',
			! preg_match( '/<h2\b/', $ow_markup ),
			'The shell already drew one, and it is the dialog\'s accessible name. Two is one too many for a screen reader before it is one too many for a designer.'
		);

		$ow_titles[] = $ow_name;
	}

	ow_assert(
		'the dialog title is short enough to be a title',
		'' !== ( $ow_titles[0] ?? '' ) && mb_strlen( $ow_titles[0] ) <= 20,
		'Got: "' . ( $ow_titles[0] ?? '' ) . '"'
	);

	ow_check( 'and it is the one the screen was asked for', 'Đăng nhập', $ow_titles[0] ?? '' );
} else {
	ow_pending( 'a fragment draws no heading of its own', 'includes/Frontend/class-fragment-renderer.php' );
}

// ---------------------------------------------------------------------------
ow_section( 'Rule 15 — the plugin makes no promises of its own (19.11)' );
// ---------------------------------------------------------------------------

/*
 * The benefits row's layout comes from a reference where the three badges are
 * one pharmacy's claims. Shipping those would put somebody else's marketing on
 * every site that installed this; inventing our own would be making promises
 * the plugin has no way to keep.
 *
 * So the default is nothing, and that is asserted rather than trusted — an
 * empty row is the state every install starts in, and the template suite cannot
 * check it there because "renders nothing" fails its produces-markup rule.
 */
$ow_benefits_default = ow_capture(
	static function (): void {
		\OmniWP\Frontend\TemplateLoader::output( 'partials/dialog-benefits' );
	}
);

ow_check( 'the benefits row is empty until a site fills it', '', trim( $ow_benefits_default['html'] ) );

ow_assert(
	'and it renders without a notice when empty',
	null === $ow_benefits_default['error'] && array() === $ow_benefits_default['warnings'],
	(string) $ow_benefits_default['error'] . ' ' . implode( ' | ', $ow_benefits_default['warnings'] )
);

$ow_benefits_filled = ow_capture(
	static function (): void {
		\OmniWP\Frontend\TemplateLoader::output(
			'partials/dialog-benefits',
			array(
				'benefits' => array(
					array(
						'icon'  => '🚚',
						'label' => 'Miễn phí vận chuyển',
					),
				),
			)
		);
	}
);

ow_assert(
	'and it renders when a site does',
	false !== strpos( $ow_benefits_filled['html'], 'sl-benefit__label' ),
	'A slot nobody can fill is a slot that should not exist.'
);

// ---------------------------------------------------------------------------
ow_section( 'Rule 16 — a fragment never needs a script the dialog cannot load (19.12)' );
// ---------------------------------------------------------------------------

/*
 * Reported from the running site: on the welcome screen inside the dialog,
 * choosing a province leaves Phường/Xã empty and disabled. `address.js` never
 * runs there — the template reaches `Assets::enqueue_address()` inside a REST
 * request, which is the no-op `Shortcodes::render_step()` already warns about
 * for `Assets::enqueue()`, and the dialog's own asset list never named the file.
 *
 * Two properties, because fixing either one alone leaves the picker dead:
 * the dialog has to be able to *fetch* the script, and it has to *call* it —
 * `address.js` binds on DOMContentLoaded, and a fragment arrives long after.
 *
 * The second half is written over every enhancement hook the plugin exposes
 * rather than over this one, so a hook added later and left unwired fails here
 * instead of being found on a screenshot, which is how all three of 19.10, 19.11
 * and this one were found.
 */
$ow_welcome_page = 'https://example.test/san-pham/ao-thun/';

// The welcome screen is the one authenticated step the dialog draws, so the
// visitor rule 7 needs (signed out) is the wrong one here. Restored below.
$ow_signed_out                 = $GLOBALS['ow_logged_in'];
$GLOBALS['ow_logged_in']       = true;
$GLOBALS['ow_current_user_id'] = 7;

$ow_welcome = ow_capture(
	static function () use ( $ow_welcome_page ): void {
		$GLOBALS['ow_welcome_fragment'] = ( new \OmniWP\Frontend\FragmentRenderer() )->render(
			Flow::STEP_ONBOARD,
			$ow_welcome_page,
			$ow_welcome_page,
			array(
				'user_id'  => 7,
				'redirect' => $ow_welcome_page,
				// Supplied rather than derived, so the rule describes the picker
				// and not ProfileCompletionService's idea of what is missing.
				'fields'   => array(
					array(
						'key'    => 'address',
						'label'  => 'Địa chỉ nhận hàng',
						'reason' => 'Để đơn hàng được giao đúng nơi',
					),
				),
			)
		);
	}
);

$GLOBALS['ow_logged_in'] = $ow_signed_out;

$ow_welcome_html = (string) ( $GLOBALS['ow_welcome_fragment']['html'] ?? '' );

ow_assert(
	'the welcome fragment renders the address picker',
	null === $ow_welcome['error'] && false !== strpos( $ow_welcome_html, 'data-sl-address' ),
	'render failed: ' . (string) $ow_welcome['error']
);

/*
 * And it arrives inert. The ward select ships `disabled` with one empty option,
 * because on the server there is nothing to choose from until a province is
 * picked — which is precisely why the script is not optional here.
 */
ow_assert(
	'and the picker arrives inert, so its script is not optional',
	(bool) preg_match( '/data-sl-ward[^>]*\bdisabled\b/s', $ow_welcome_html ),
	'If the ward select were usable without JavaScript this rule would be about nothing.'
);

$ow_contract  = \OmniWP\Frontend\LoginDialog::contract();
$ow_dialog_js = $ow_file( 'assets/js/omniwp-dialog.js' );
$ow_address_js = $ow_file( 'assets/js/address.js' );

ow_assert(
	'the dialog can fetch the address picker script',
	false !== strpos( (string) wp_json_encode( $ow_contract ), 'address.js' ),
	'The contract names four assets and none of them is address.js, so nothing on the dialog path ever loads it.'
);

ow_assert(
	'one array owns the picker configuration',
	method_exists( \OmniWP\Frontend\Assets::class, 'address_config' ),
	'`OmniWPAddress` is localized onto a handle the dialog never enqueues. Two builders for one config is how the two drift.'
);

if ( method_exists( \OmniWP\Frontend\Assets::class, 'address_config' ) ) {
	ow_check(
		'and the dialog carries exactly that array',
		\OmniWP\Frontend\Assets::address_config(),
		(array) ( $ow_contract['address'] ?? array() )
	);
}

ow_assert(
	'the address picker can enhance markup that arrives late',
	(bool) preg_match( '/window\.OmniWP\w*Enhance\s*=/', $ow_address_js ),
	'address.js initialises on DOMContentLoaded and exports nothing. The dialog paints long after it.'
);

/*
 * Every hook, not this one. `OmniWPEnhance` was created in 19.3 for markup
 * that arrives late and `address.js` simply never joined it; a rule that names
 * only the file we just fixed would not have caught that, and will not catch the
 * next one.
 */
$ow_enhancers = array();

foreach ( (array) glob( OMNIWP_DIR . 'assets/js/*.js' ) as $ow_script ) {
	if ( preg_match_all( '/window\.(OmniWP\w*Enhance)\s*=/', (string) file_get_contents( $ow_script ), $ow_found ) ) {
		$ow_enhancers = array_merge( $ow_enhancers, $ow_found[1] );
	}
}

$ow_enhancers = array_unique( $ow_enhancers );

ow_assert(
	'the plugin exposes at least one enhancement hook, so the loop has a subject',
	array() !== $ow_enhancers,
	'Without one, the check below would pass for want of anything to walk.'
);

foreach ( $ow_enhancers as $ow_hook ) {
	ow_assert(
		'the dialog calls ' . $ow_hook . '() when it paints',
		false !== strpos( $ow_dialog_js, $ow_hook ),
		'A hook nothing calls is a fragment rendered with the enhancement silently missing — the defect 19.12 is.'
	);
}

ow_summary( 'Sign-in anywhere' );
