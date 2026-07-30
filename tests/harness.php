<?php
/**
 * Shared assertion helpers for the newer test suites.
 *
 * Functions are `sl_` prefixed so this file can coexist with the original
 * runner (`run-tests.php`), which declares its own global check()/section().
 * That runner is deliberately left untouched — its 163 assertions are a working
 * asset and must not be destabilised by the refactor.
 *
 * A suite ends by calling sl_summary(), which sets the process exit code.
 *
 * @package SmartLogin
 */

$GLOBALS['sl_harness'] = array(
	'passed'  => 0,
	'failed'  => 0,
	'pending' => 0,
);

function sl_section( string $title ): void {
	echo "\n" . $title . "\n";
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function sl_check( string $label, $expected, $actual ): void {
	if ( $expected === $actual ) {
		++$GLOBALS['sl_harness']['passed'];
		return;
	}

	++$GLOBALS['sl_harness']['failed'];
	printf(
		"  FAIL     %s\n           expected: %s\n           actual:   %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/**
 * Assert a condition that carries its own explanation when it fails.
 */
function sl_assert( string $label, bool $condition, string $hint = '' ): void {
	if ( $condition ) {
		++$GLOBALS['sl_harness']['passed'];
		return;
	}

	++$GLOBALS['sl_harness']['failed'];
	printf( "  FAIL     %s\n", $label );

	if ( '' !== $hint ) {
		printf( "           %s\n", $hint );
	}
}

/**
 * A check that cannot run yet because the thing it tests does not exist.
 *
 * Pending is not a pass. It is counted and reported separately so a red suite
 * reads as a to-do list rather than a wall of duplicate failures: one FAIL for
 * the missing building block, then PENDING for the behaviour that depends on it.
 */
function sl_pending( string $label, string $blocked_on ): void {
	++$GLOBALS['sl_harness']['pending'];
	printf( "  PENDING  %s\n           blocked on: %s\n", $label, $blocked_on );
}

function sl_note( string $text ): void {
	printf( "           %s\n", $text );
}

/**
 * Print the tally and exit. Pending items do not fail the suite on their own;
 * the missing building block they are blocked on already did.
 */
function sl_summary( string $suite ): void {
	$tally = $GLOBALS['sl_harness'];

	printf(
		"\n%s: %d passed, %d failed, %d pending\n",
		$suite,
		$tally['passed'],
		$tally['failed'],
		$tally['pending']
	);

	exit( $tally['failed'] > 0 ? 1 : 0 );
}

/**
 * Read a plugin source file relative to the plugin root.
 *
 * Returns '' for a missing file so fitness checks can report "absent" as a
 * finding instead of crashing.
 */
function sl_source( string $relative ): string {
	$file = dirname( __DIR__ ) . '/' . ltrim( $relative, '/' );

	return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
}

/**
 * Every tracked PHP source file that is part of the shipped plugin.
 *
 * Excludes tests, tooling and the generated address dataset — fitness rules
 * describe production code, and data/ is machine-written.
 *
 * @return array<string,string> Relative path => contents.
 */
function sl_plugin_sources(): array {
	static $sources = null;

	if ( null !== $sources ) {
		return $sources;
	}

	$root     = dirname( __DIR__ );
	$sources  = array();
	$skip_dir = array( '.git', 'tests', 'scripts', 'docs', 'data', 'vendor', 'node_modules', '.github' );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( $current ) use ( $skip_dir ): bool {
				return ! ( $current->isDir() && in_array( $current->getBasename(), $skip_dir, true ) );
			}
		)
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$relative             = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		$sources[ $relative ] = (string) file_get_contents( $file->getPathname() );
	}

	ksort( $sources );

	return $sources;
}

/**
 * Fail when a pattern appears in any plugin source file outside an allowlist.
 *
 * The allowlist is passed in at the call site on purpose: adding an exception
 * means editing the test and writing down why, which is the point.
 *
 * @param string[] $allowed_files Relative paths permitted to match.
 */
function sl_forbid_pattern( string $label, string $pattern, array $allowed_files = array(), string $hint = '' ): void {
	$offenders = array();

	foreach ( sl_plugin_sources() as $relative => $contents ) {
		if ( in_array( $relative, $allowed_files, true ) ) {
			continue;
		}

		if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $matches[0] as $match ) {
			$line        = substr_count( substr( $contents, 0, (int) $match[1] ), "\n" ) + 1;
			$offenders[] = $relative . ':' . $line;
		}
	}

	if ( ! $offenders ) {
		++$GLOBALS['sl_harness']['passed'];
		return;
	}

	++$GLOBALS['sl_harness']['failed'];
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
 * Fail when files matching $must_contain_pattern do not also reference $required.
 *
 * Used to tie a dangerous call site to its mandatory helper, e.g. every
 * wp_insert_user() call must go through OpaqueLogin.
 */
function sl_require_companion( string $label, string $trigger_pattern, string $required_pattern, string $hint = '' ): void {
	$offenders = array();

	foreach ( sl_plugin_sources() as $relative => $contents ) {
		if ( ! preg_match( $trigger_pattern, $contents ) ) {
			continue;
		}

		if ( ! preg_match( $required_pattern, $contents ) ) {
			$offenders[] = $relative;
		}
	}

	if ( ! $offenders ) {
		++$GLOBALS['sl_harness']['passed'];
		return;
	}

	++$GLOBALS['sl_harness']['failed'];
	printf( "  FAIL     %s\n", $label );

	if ( '' !== $hint ) {
		printf( "           %s\n", $hint );
	}

	foreach ( $offenders as $offender ) {
		printf( "           → %s\n", $offender );
	}
}
