<?php
/**
 * Coding-standards gate.
 *
 * Skips rather than fails when phpcs is not installed, so `composer install` is
 * not a prerequisite for running the rest of the suite. CI installs it.
 *
 * Run with:  php tests/run-phpcs.php
 *
 * @package SmartLogin
 */

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
	exit( 0 );
}

// Show the per-file tail rather than every violation; the full report is one
// command away and this keeps the aggregate runner readable.
foreach ( array_slice( $output, -14 ) as $line ) {
	printf( "%s\n", rtrim( $line ) );
}

printf( "\nFull report: php vendor/bin/phpcs\nAuto-fix:    php vendor/bin/phpcbf\n" );

exit( 1 );
