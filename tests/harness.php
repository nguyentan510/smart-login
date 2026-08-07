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
 * build/ and dist/ are skipped for a different reason: they hold *copies* of
 * the files below. Staging a release archive puts a second smart-login.php on
 * disk, and a rule phrased "no file outside X does Y" then fails against the
 * copy rather than against anything a reader could fix. Six suites went red
 * this way before the two names were added here.
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
	$skip_dir = array( '.git', 'tests', 'scripts', 'docs', 'data', 'vendor', 'node_modules', '.github', 'build', 'dist' );

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
 * Fail when a section marker appears in more than one template.
 *
 * Scoped to templates/ deliberately: the service layer may name a concept as
 * often as it likes, but a block of markup that renders it belongs to exactly
 * one file. Two copies is how the profile-status notice ended up rendering a
 * sentence in one template and a bare implode() in the other.
 *
 * Zero owners passes. A marker for a partial that has not been extracted yet is
 * a to-do, not a violation, and 8.0 lands before the partials exist.
 */
function sl_require_single_template( string $label, string $pattern, string $hint = '' ): void {
	$owners = array();

	foreach ( sl_plugin_sources() as $relative => $contents ) {
		if ( 0 !== strpos( $relative, 'templates/' ) ) {
			continue;
		}

		if ( preg_match( $pattern, $contents ) ) {
			$owners[] = $relative;
		}
	}

	if ( count( $owners ) <= 1 ) {
		++$GLOBALS['sl_harness']['passed'];
		return;
	}

	++$GLOBALS['sl_harness']['failed'];
	printf( "  FAIL     %s\n", $label );

	if ( '' !== $hint ) {
		printf( "           %s\n", $hint );
	}

	foreach ( $owners as $owner ) {
		printf( "           → %s\n", $owner );
	}
}

/**
 * Fail when files matching $must_contain_pattern do not also reference $required.
 *
 * Used to tie a dangerous call site to its mandatory helper, e.g. every
 * wp_insert_user() call must go through OpaqueLogin.
 *
 * The allowlist is passed in at the call site, the same as sl_forbid_pattern's:
 * a file that legitimately triggers the pattern and must *not* carry the
 * companion is an exception somebody has to write down. 17.0 added it for the
 * first such case — a provisioned account's random password is not a password
 * its holder ever chose, so recording a change date for it would state
 * something false.
 *
 * @param string[] $allowed_files Relative paths exempt from the requirement.
 */
function sl_require_companion( string $label, string $trigger_pattern, string $required_pattern, string $hint = '', array $allowed_files = array() ): void {
	$offenders = array();

	foreach ( sl_plugin_sources() as $relative => $contents ) {
		if ( ! preg_match( $trigger_pattern, $contents ) || in_array( $relative, $allowed_files, true ) ) {
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

/**
 * The source text of one method body, located by tokenising rather than matching.
 *
 * Every rule above answers "does this file contain X". Two kinds of rule cannot
 * be expressed that way: an *ordering* property ("A is called before B") and a
 * *per-callback* property ("each of these ten methods calls the guard"). A
 * file-level regex reports green for both as soon as one method somewhere in the
 * file satisfies it, which is precisely the failure being guarded against.
 *
 * A regex cannot do this reliably either — method bodies nest braces, and braces
 * appear inside strings and comments. token_get_all() already knows the
 * difference, so the brace counter only ever sees real ones.
 *
 * Comments are stripped from the returned body. Without that, the ordering rule
 * for handle_identify() matches `resolve(` twice — once as the call and once in
 * the sentence explaining it — and a rule that reads prose is a rule that goes
 * green when somebody rewords a comment.
 *
 * @param string $source Full PHP source.
 * @param string $method Method name, without parentheses.
 * @return string The body between its outer braces, comments removed, or ''.
 */
function sl_method_body( string $source, string $method ): string {
	if ( '' === $source ) {
		return '';
	}

	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}

		// The name is the next T_STRING; whitespace and `&` may intervene.
		$name = '';

		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$name = $tokens[ $j ][1];
				break;
			}

			// A `(` before any name means a closure — not what is being looked for.
			if ( '(' === $tokens[ $j ] ) {
				break;
			}
		}

		if ( $name !== $method ) {
			continue;
		}

		// Walk to the opening brace of the body, stepping over the parameter
		// list and any return type. An abstract or interface method ends at `;`
		// and has no body to return.
		$depth = 0;
		$start = -1;

		for ( $j = $i + 1; $j < $count; $j++ ) {
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];

			if ( '(' === $text ) {
				++$depth;
				continue;
			}

			if ( ')' === $text ) {
				--$depth;
				continue;
			}

			if ( 0 === $depth && ';' === $text ) {
				break;
			}

			if ( 0 === $depth && '{' === $text ) {
				$start = $j;
				break;
			}
		}

		if ( -1 === $start ) {
			continue;
		}

		$braces = 0;
		$body   = '';

		for ( $j = $start; $j < $count; $j++ ) {
			$id   = is_array( $tokens[ $j ] ) ? $tokens[ $j ][0] : null;
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];

			// `${` opens a brace that closes with a plain `}`. There is none in
			// the tree today; counting it keeps the walker correct if one lands.
			if ( '{' === $text || '${' === $text ) {
				++$braces;

				if ( 1 === $braces ) {
					continue;
				}
			}

			if ( '}' === $text ) {
				--$braces;

				if ( 0 === $braces ) {
					return $body;
				}
			}

			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}

			$body .= $text;
		}
	}

	return '';
}

/*
 * Moved here from run-admin-tests.php in 13.0, when the mail suite needed to
 * render a screen too. A helper two suites use belongs in the shared harness;
 * a second copy would be a second place to forget that a PHP notice counts.
 */
/**
 * Run a renderer and report what happened, treating a PHP notice as a failure —
 * an undeclared variable on a settings screen is a bug whether or not the page
 * happens to come out looking right.
 *
 * @return array{html:string,error:?string,warnings:array}
 */
function sl_capture( callable $render ): array {
	$GLOBALS['sl_admin_warnings'] = array();

	set_error_handler(
		static function ( int $severity, string $message ) {
			$GLOBALS['sl_admin_warnings'][] = $message;
			return true;
		}
	);

	ob_start();

	try {
		$render();
		$html  = (string) ob_get_clean();
		$error = null;
	} catch ( Throwable $exception ) {
		ob_end_clean();
		$html  = '';
		$error = get_class( $exception ) . ': ' . $exception->getMessage();
	} finally {
		restore_error_handler();
	}

	return array(
		'html'     => $html,
		'error'    => $error,
		'warnings' => $GLOBALS['sl_admin_warnings'],
	);
}
