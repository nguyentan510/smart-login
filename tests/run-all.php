<?php
/**
 * Aggregate test runner.
 *
 * Each suite runs in its own process. That is not incidental: the suites define
 * conflicting global helpers and mutate stubbed option/transient state, so
 * sharing one process would couple them.
 *
 * Suites are either `required` or `spec`:
 *
 *   required — must pass. Regressions here break the build.
 *   spec     — encodes a part of docs/identity-model.md that the implementation
 *              has not caught up with yet. Reported in full, but non-blocking.
 *
 * As of Phase 5 there are no `spec` suites left: the identity suites went green
 * and were promoted, because leaving a passing suite non-blocking can only serve
 * to hide the next regression. The `spec` kind stays supported for the next time
 * a specification lands ahead of its implementation.
 *
 * --strict makes no difference while nothing is marked `spec`. It remains as the
 * switch that refuses to tolerate one.
 *
 * Run with:  php tests/run-all.php [--strict]
 *
 * @package OmniWP
 */

$strict = in_array( '--strict', array_slice( $argv, 1 ), true );

$suites = array(
	array(
		'name' => 'Regression (pure logic)',
		'file' => 'run-tests.php',
		'kind' => 'required',
	),
	array(
		'name' => 'Syntax lint',
		'file' => 'run-lint.php',
		'kind' => 'required',
	),
	array(
		// Skips itself when phpcs is not installed, so it never blocks a plain
		// checkout.
		//
		// `required` since P7.3, and the suite stopped being permanently red in
		// the same commit. It was `spec` because the documentation sniffs are a
		// known deferral — but "known deferral" and "red for ever" are not the
		// same thing, and a suite that is always red teaches everyone reading
		// this summary to skip the line. It now passes at its baseline and fails
		// when a change adds a violation, which is the only state in which
		// marking it `required` means anything.
		'name' => 'Coding standards',
		'file' => 'run-phpcs.php',
		'kind' => 'required',
	),
	array(
		'name' => 'Identity core',
		'file' => 'identity/run-core-tests.php',
		'kind' => 'required',
	),
	array(
		'name' => 'Identity contract',
		'file' => 'identity/run-contract-tests.php',
		'kind' => 'required',
	),
	array(
		'name' => 'Identity fitness',
		'file' => 'identity/run-fitness-tests.php',
		'kind' => 'required',
	),
	array(
		// Actually renders every template. Added after a deleted class survived
		// in two templates for four phases and fatalled the My Account page,
		// because no other suite executes template code.
		'name' => 'Template rendering',
		'file' => 'identity/run-template-tests.php',
		'kind' => 'required',
	),
	array(
		// The same gate for the admin screens, which had the same exposure and
		// no cover at all. It also asserts the property the settings rebuild
		// exists to guarantee: every field a tab claims is a field that tab
		// draws, checked against the rendered HTML.
		'name' => 'Admin screens',
		'file' => 'identity/run-admin-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 8. Landed `spec` and red on purpose, so the duplication rules
		// could be seen failing on the tree that still contained the defect.
		// 8.2 and 8.3 turned all five green, so it is promoted here for the same
		// reason Phase 5 promoted the identity suites: leaving a passing suite
		// non-blocking can only hide the next regression.
		'name' => 'Account surface',
		'file' => 'identity/run-account-surface-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 9. Landed `spec` and red on purpose — nine of its assertions
		// described controls that did not exist and two described a defect
		// already in production. 9.7 turned the last one green, so it is promoted
		// here for the reason Phase 5 promoted the identity suites: a passing
		// suite left non-blocking can only serve to hide the next regression.
		'name' => 'Abuse boundary',
		'file' => 'security/run-abuse-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 10. Landed red at 10.0 before any production file moved, with
		// four rules reporting PENDING rather than passing vacuously — a rule
		// that passes for want of a subject states the opposite of the truth.
		// 10.1, 10.2 and 10.7 turned the last failing one green, so it is
		// promoted here for the reason Phase 5 promoted the identity suites: a
		// passing suite left non-blocking can only serve to hide the next
		// regression. The remaining PENDINGs are 10.3 and 10.4 and do not block.
		'name' => 'Delivery routing',
		'file' => 'delivery/run-routing-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 11, landed red at 11.0 before any production file moved. Two
		// Promoted in 15.4. It landed `spec` in 11.0 with rules that were red, and
		// the working agreement says a spec suite becomes required the moment it
		// goes green — it has been 56 passed / 0 failed / 0 pending since 13.3, so
		// the project was running four phases against its own rule. A green suite
		// that cannot block can only hide the next regression.
		'name' => 'Mail templates',
		'file' => 'mail/run-template-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 16, landed red at 16.0 before any production file moved. It needed
		// its own suite rather than rows in the account surface one, which has
		// been `required` since 8.3 — rules that are meant to fail cannot live in
		// a suite that blocks.
		//
		// Promoted in 16.3, the sub-phase that turned the last two green, rather
		// than left to a later one. 15.4 found this suite kind being abused for
		// four phases: a green suite that cannot block can only hide the next
		// regression.
		'name' => 'Sign-in card',
		'file' => 'identity/run-sign-in-card-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 17, landed red at 17.0 before any production file moved. Eight
		// rules, one per sub-phase, so "17.4 is done" and "rule 4 is green" are
		// the same sentence.
		//
		// Landed `spec` for the same reason 16.0 needed its own suite: the
		// account surface suite has been `required` since 8.3, and rules that
		// are meant to fail cannot live in a suite that blocks.
		//
		// `required` since 17.8, the sub-phase that turned the last two green,
		// rather than left for later — 15.4 found this kind being abused for
		// four phases, and a green suite that cannot block can only hide the
		// next regression.
		'name' => 'Account card',
		'file' => 'identity/run-account-card-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 18, landed red at 18.0 before the tool it specifies existed.
		//
		// The first suite here that reads a *rendered* surface as a DOM rather
		// than a template as a string. Both defects Phase 17 found by looking at
		// a page were invisible to every string-matching rule in this repo, and
		// two more turned up the moment a parser was pointed at the markup.
		//
		// `required` since 18.3, the sub-phase that turned the last one green.
		// A green suite left non-blocking can only hide the next regression —
		// 15.4 found this kind being abused for four phases.
		'name' => 'Rendered surface',
		'file' => 'identity/run-rendered-surface-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 19, landed red at 19.0 before any production file moved.
		//
		// `spec` for the reason 16.0 and 17.0 needed their own suites: rules that
		// are meant to fail cannot live in a suite that blocks, and every
		// neighbouring suite here has been `required` for phases.
		//
		// Seven of its assertions reported PENDING rather than passing, because
		// their subject did not exist yet. That is the 10.0 precedent — a rule
		// that passes for want of a subject states the opposite of the truth.
		//
		// `required` since 19.7, the sub-phase that took the readings. Three of
		// these rules had to be rewritten from a structural form to a
		// behavioural one during the phase — a rule that reads source proves a
		// thing was written, not that it works — and a suite that can no longer
		// block would hide the next regression in exactly those three.
		'name' => 'Sign-in anywhere',
		'file' => 'identity/run-sign-in-anywhere-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 21, landed red at 21.0 before any production file moved.
		//
		// `spec` for the reason every guard-rail suite before it was: rules that
		// are meant to fail cannot live in a suite that blocks. It is promoted in
		// 21.7, and the working agreement says that promotion happens the moment
		// the suite goes green — 15.4 found that rule being ignored for four
		// phases, and a green suite that cannot block only hides the next
		// regression.
		//
		// Ten of its assertions report PENDING rather than passing, because their
		// subject does not exist yet. That is the 10.0 precedent.
		//
		// One rule caught the suite lying about itself on the day it landed: the
		// button rules are phrased as absences, `shortcode_atts()` was not
		// stubbed, `render_button()` threw, and three of them passed against an
		// empty string. They now run only against markup that rendered.
		//
		// `required` since 21.5, not 21.7 as the plan had it. The suite went
		// green when the button landed, and the working agreement promotes a
		// spec suite the moment that happens — 15.4 found that rule being
		// ignored for four phases, and a green suite that cannot block only
		// hides the next regression. Waiting two sub-phases to honour the rule
		// would have been the same mistake with a shorter fuse.
		'name' => 'Account menu',
		'file' => 'identity/run-account-menu-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 22. Landed `spec` in 22.0 with twelve PENDING rules; promoted
		// here, in 22.2, the commit that turned all twelve green. The working
		// agreement promotes the moment a spec suite goes green, and holding it
		// longer would be 15.4's mistake with a shorter fuse.
		'name' => 'Guide tab',
		'file' => 'identity/run-guide-tests.php',
		'kind' => 'required',
	),
	array(
		'name' => 'Smart Menu',
		'file' => 'smart-menu/run-smart-menu-tests.php',
		'kind' => 'required',
	),
	array(
		'name' => 'E-Commerce Suite',
		'file' => 'ecommerce/run-ecommerce-tests.php',
		'kind' => 'required',
	),
	array(
		// Phase 25, landed red at 25.0 before any production file moved.
		//
		// `spec` for the reason every guard-rail suite before it was: rules that
		// are meant to fail cannot live in a suite that blocks, and every
		// neighbouring suite here has been `required` for phases.
		//
		// Four of its six rules name offenders that shipped — five dead settings,
		// a breakpoint written into JS four times, five elements competing for
		// the bottom edge of a phone screen, and cart state baked into every
		// cached page. Five assertions report PENDING rather than passing,
		// because the navigation model does not exist yet; that is the 10.0
		// precedent.
		//
		// Promoted the moment it goes green, which is 25.6 at the latest and
		// earlier if the rules turn sooner. 15.4 recorded what it costs to hold
		// a green suite non-blocking for four phases.
		'name' => 'Navigation',
		'file' => 'navigation/run-navigation-tests.php',
		'kind' => 'spec',
	),
);


$results  = array();
$blocking = 0;

foreach ( $suites as $suite ) {
	printf( "\n%s\n  %s  (%s)\n%s\n", str_repeat( '=', 68 ), $suite['name'], $suite['kind'], str_repeat( '=', 68 ) );

	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $suite['file'] );
	$status  = 0;

	passthru( $command, $status );

	$ok      = 0 === $status;
	$results[] = array(
		'name' => $suite['name'],
		'kind' => $suite['kind'],
		'ok'   => $ok,
	);

	if ( ! $ok && ( 'required' === $suite['kind'] || $strict ) ) {
		++$blocking;
	}
}

printf( "\n%s\nSummary%s\n%s\n", str_repeat( '=', 68 ), $strict ? ' (strict)' : '', str_repeat( '=', 68 ) );

foreach ( $results as $result ) {
	$blocks = ! $result['ok'] && ( 'required' === $result['kind'] || $strict );

	printf(
		"  %-9s %-26s %s\n",
		$result['ok'] ? 'PASS' : ( $blocks ? 'FAIL' : 'RED' ),
		$result['name'],
		$result['ok'] ? '' : ( $blocks ? '← blocking' : '← expected during refactor' )
	);
}

$spec_count = count( array_filter( $results, static fn( array $r ): bool => 'spec' === $r['kind'] ) );

if ( $spec_count > 0 && ! $strict ) {
	printf( "\n  %d spec suite(s) hold a standard the code has not fully met yet.\n", $spec_count );
	printf( "  Each deferral is written down where it is configured.\n" );
	printf( "  Strict gate: php tests/run-all.php --strict\n" );
}

printf( "\n" );

exit( $blocking > 0 ? 1 : 0 );
