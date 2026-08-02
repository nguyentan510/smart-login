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
 * @package SmartLogin
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
		// checkout. Marked `spec` because the documentation sniffs are a known,
		// documented deferral — see the comment block in phpcs.xml.
		'name' => 'Coding standards',
		'file' => 'run-phpcs.php',
		'kind' => 'spec',
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
