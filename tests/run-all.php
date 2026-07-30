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
 *   spec     — encodes docs/identity-model.md, which is deliberately ahead of
 *              the implementation during the refactor. Reported in full, but
 *              does not fail the build until Phase 7 promotes it.
 *
 * Pass --strict to require everything. That is the Phase 7 acceptance gate.
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
		'name' => 'Identity core',
		'file' => 'identity/run-core-tests.php',
		'kind' => 'required',
	),
	array(
		'name' => 'Identity contract',
		'file' => 'identity/run-contract-tests.php',
		'kind' => 'spec',
	),
	array(
		'name' => 'Identity fitness',
		'file' => 'identity/run-fitness-tests.php',
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

if ( ! $strict ) {
	printf( "\n  Spec suites track docs/identity-model.md and are red by design.\n" );
	printf( "  Progress: docs/refactor-plan.md    Final gate: php tests/run-all.php --strict\n" );
}

printf( "\n" );

exit( $blocking > 0 ? 1 : 0 );
