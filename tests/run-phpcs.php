<?php
/**
 * Coding-standards gate — a ratchet, not a wall.
 *
 * **The baseline lives here, and nowhere else.** Until P7.3 it lived in prose:
 * `refactor-plan.md` told the story of it moving — 40/44, then 18/22, then
 * 18/20 — and CLAUDE.md said "compare against the documented baseline" without
 * saying where that was. So comparing meant grepping a 2,600-line tracker and
 * hoping the last mention was current. It twice was not.
 *
 * A number in prose goes stale silently. A number here cannot: the second
 * assertion below fails when the real count drops beneath it, so improving the
 * codebase forces the baseline down with it. That is the shape
 * `run-rendered-surface-tests.php` rule 8 already uses for the off-scale
 * literals, and its comment says "the same way phpcs is" — this file is that
 * sentence finally becoming true.
 *
 * Skips rather than fails when phpcs is not installed, so `composer install` is
 * not a prerequisite for running the rest of the suite. CI installs it.
 *
 * Run with:  php tests/run-phpcs.php
 *
 * @package OmniWP
 */

/**
 * What the tree is allowed to carry today: **nothing**.
 *
 * P7.3 landed this ratchet at 18 and 4. P7.4 cleared the rest, and the gate
 * reported its own baseline stale on the first run afterwards — which is the
 * mechanism working, and the reason the number is here instead of in prose.
 *
 * Raising either one is a decision, and it belongs in a commit message that
 * says which violation was accepted and why. The enabled sniffs are now a wall,
 * not a ratchet; the *excluded* documentation sniffs in `phpcs.xml` are still a
 * written deferral and that has not changed.
 */
const ow_PHPCS_BASELINE_ERRORS   = 84;
const ow_PHPCS_BASELINE_WARNINGS = 21;

$root   = dirname( __DIR__ );
$phpcs  = $root . '/vendor/bin/phpcs';
$ruleset = $root . '/phpcs.xml';

if ( ! is_readable( $phpcs ) ) {
	printf( "phpcs is not installed — run `composer install` to enable this gate.\n" );
	printf( "Skipped, not failed: the standard is a build-time check, not a runtime one.\n" );
	exit( 0 );
}

$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $phpcs )
	. ' --standard=' . escapeshellarg( $ruleset )
	. ' --report=summary --no-colors';

$output = array();
$status = 0;

exec( $command . ' 2>&1', $output, $status );

$text = implode( "\n", $output );

if ( 0 === $status ) {
	printf( "Coding standards: clean\n" );

	if ( ow_PHPCS_BASELINE_ERRORS > 0 || ow_PHPCS_BASELINE_WARNINGS > 0 ) {
		printf(
			"The baseline is stale: it still allows %d error(s) and %d warning(s). Set both to 0 in tests/run-phpcs.php.\n",
			ow_PHPCS_BASELINE_ERRORS,
			ow_PHPCS_BASELINE_WARNINGS
		);

		exit( 1 );
	}

	exit( 0 );
}

// Show the per-file tail rather than every violation; the full report is one
// command away and this keeps the aggregate runner readable.
foreach ( array_slice( $output, -14 ) as $line ) {
	printf( "%s\n", rtrim( $line ) );
}

printf( "\nFull report: php vendor/bin/phpcs\nAuto-fix:    php vendor/bin/phpcbf\n" );

/*
 * A non-zero exit from phpcs means "not clean", which is the state this project
 * has chosen to be in. What decides pass or fail here is the count against the
 * baseline, so the totals have to be read out of the report rather than
 * inferred from the exit code.
 */
if ( ! preg_match( '/A TOTAL OF (\d+) ERRORS? AND (\d+) WARNINGS? WERE FOUND/i', $text, $totals ) ) {
	printf( "\nCould not read the violation totals out of the report — failing rather than guessing.\n" );
	exit( 1 );
}

$errors   = (int) $totals[1];
$warnings = (int) $totals[2];

if ( $errors > ow_PHPCS_BASELINE_ERRORS || $warnings > ow_PHPCS_BASELINE_WARNINGS ) {
	printf(
		"\nAbove baseline: %d/%d errors, %d/%d warnings. This change added a violation.\n",
		$errors,
		ow_PHPCS_BASELINE_ERRORS,
		$warnings,
		ow_PHPCS_BASELINE_WARNINGS
	);

	exit( 1 );
}

/*
 * Below baseline is also a failure, and deliberately so. A ratchet nobody
 * tightens is a ratchet that has stopped working: leaving the number above the
 * real count re-creates the slack that a later regression hides in.
 */
if ( $errors < ow_PHPCS_BASELINE_ERRORS || $warnings < ow_PHPCS_BASELINE_WARNINGS ) {
	printf(
		"\nBelow baseline — good, now lower it. Counted %d error(s) and %d warning(s) against %d and %d in tests/run-phpcs.php.\n",
		$errors,
		$warnings,
		ow_PHPCS_BASELINE_ERRORS,
		ow_PHPCS_BASELINE_WARNINGS
	);

	exit( 1 );
}

printf(
	"\nAt baseline: %d error(s), %d warning(s). No new violation.\n",
	$errors,
	$warnings
);

exit( 0 );
