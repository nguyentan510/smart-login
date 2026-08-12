<?php
/**
 * Generate languages/omniwp.pot without wp-cli.
 *
 * Scans the shipped source for the WordPress translation functions, collects the
 * strings with their references and any translators comments, and writes a POT
 * file. `wp i18n make-pot` does this better, but it is not always installed and
 * a plugin should not be unable to ship translations because of that.
 *
 * Run with:  php bin/build-pot.php
 *
 * @package OmniWP
 */

// phpcs:disable WordPress.Security.EscapeOutput -- CLI output.

// `$text_domain`, not `$domain`: this file's variables live at global scope and
// `$domain` is one WordPress owns, so assigning it here overwrites a global for
// anything loaded afterwards.
$root        = dirname( __DIR__ );
$text_domain = 'omniwp';
$out         = $root . '/languages/' . $text_domain . '.pot';

$scan_dirs = array( 'includes', 'templates' );
$scan_root = array( 'omniwp.php', 'uninstall.php' );

/**
 * function name => [ index of singular arg, index of plural arg or null, index of domain arg ]
 */
$functions = array(
	'__'         => array( 0, null, 1 ),
	'_e'         => array( 0, null, 1 ),
	'esc_html__' => array( 0, null, 1 ),
	'esc_html_e' => array( 0, null, 1 ),
	'esc_attr__' => array( 0, null, 1 ),
	'esc_attr_e' => array( 0, null, 1 ),
	'_x'         => array( 0, null, 2 ),
	'esc_html_x' => array( 0, null, 2 ),
	'_n'         => array( 0, 1, 3 ),
	'_nx'        => array( 0, 1, 4 ),
);

$files = array();

foreach ( $scan_root as $relative ) {
	if ( is_readable( $root . '/' . $relative ) ) {
		$files[] = $relative;
	}
}

foreach ( $scan_dirs as $dir ) {
	if ( ! is_dir( $root . '/' . $dir ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		}
	}
}

sort( $files );

/** @var array<string,array{refs:string[],plural:?string,context:?string,comment:?string}> */
$entries = array();

foreach ( $files as $relative ) {
	$source = file_get_contents( $root . '/' . $relative );
	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
			continue;
		}

		// Skip method calls and definitions: ->__( … ) is not a translation call.
		$previous = $i > 0 ? $tokens[ $i - 1 ] : null;
		if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		list( $singular_at, $plural_at, $domain_at ) = $functions[ $token[1] ];

		$args = ow_pot_read_args( $tokens, $i, $count );

		if ( null === $args || ! isset( $args[ $domain_at ] ) || $text_domain !== $args[ $domain_at ] ) {
			continue;
		}

		$singular = $args[ $singular_at ] ?? null;

		if ( null === $singular || '' === $singular ) {
			continue;
		}

		$context = null;
		if ( '_x' === $token[1] || 'esc_html_x' === $token[1] ) {
			$context = $args[1] ?? null;
		} elseif ( '_nx' === $token[1] ) {
			$context = $args[3] ?? null;
		}

		$key = ( null !== $context ? $context . "\4" : '' ) . $singular;

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'singular' => $singular,
				'plural'   => null !== $plural_at ? ( $args[ $plural_at ] ?? null ) : null,
				'context'  => $context,
				'refs'     => array(),
				'comment'  => ow_pot_translator_comment( $tokens, $i ),
			);
		}

		$entries[ $key ]['refs'][] = $relative . ':' . $token[2];
	}
}

ksort( $entries );

$now  = gmdate( 'Y-m-d H:iO' );
$pot  = "# Copyright (C) Smart Login contributors\n";
$pot .= "# This file is distributed under the same licence as the Smart Login plugin.\n";
$pot .= "msgid \"\"\nmsgstr \"\"\n";
$pot .= "\"Project-Id-Version: Smart Login\\n\"\n";
$pot .= "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/omniwp\\n\"\n";
$pot .= '"POT-Creation-Date: ' . $now . "\\n\"\n";
$pot .= "\"MIME-Version: 1.0\\n\"\n";
$pot .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$pot .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$pot .= "\"Plural-Forms: nplurals=1; plural=0;\\n\"\n";
$pot .= "\"X-Generator: bin/build-pot.php\\n\"\n";
$pot .= "\"X-Domain: {$text_domain}\\n\"\n";

foreach ( $entries as $entry ) {
	$pot .= "\n";

	if ( ! empty( $entry['comment'] ) ) {
		$pot .= '#. ' . $entry['comment'] . "\n";
	}

	foreach ( $entry['refs'] as $ref ) {
		$pot .= '#: ' . $ref . "\n";
	}

	if ( null !== $entry['context'] ) {
		$pot .= 'msgctxt ' . ow_pot_quote( $entry['context'] ) . "\n";
	}

	$pot .= 'msgid ' . ow_pot_quote( $entry['singular'] ) . "\n";

	if ( null !== $entry['plural'] ) {
		$pot .= 'msgid_plural ' . ow_pot_quote( $entry['plural'] ) . "\n";
		$pot .= "msgstr[0] \"\"\n";
	} else {
		$pot .= "msgstr \"\"\n";
	}
}

/*
 * `--check` writes nothing and reports whether the committed catalogue matches what
 * the tree would produce now.
 *
 * The catalogue went stale by 76 strings across five phases, and nobody noticed until
 * 14.3 regenerated it for an unrelated reason. Nothing was watching; the fitness suite
 * watches now, and it needs a way to ask without writing to the working tree.
 */
if ( in_array( '--check', $argv, true ) ) {
	$current = is_readable( $out ) ? (string) file_get_contents( $out ) : '';

	if ( ow_pot_strings( $current ) === ow_pot_strings( $pot ) ) {
		printf( "POT is current\n  %d strings from %d files\n", count( $entries ), count( $files ) );
		exit( 0 );
	}

	$missing = array_diff( ow_pot_strings( $pot ), ow_pot_strings( $current ) );
	$extra   = array_diff( ow_pot_strings( $current ), ow_pot_strings( $pot ) );

	printf(
		"POT is STALE\n  %d string(s) in the tree are missing from the catalogue, %d in the catalogue are gone from the tree\n  first missing: %s\n  run: php bin/build-pot.php\n",
		count( $missing ),
		count( $extra ),
		(string) ( reset( $missing ) ?: '—' )
	);
	exit( 1 );
}

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0755, true );
}

file_put_contents( $out, $pot );

printf( "Wrote %s\n  %d strings from %d files\n", str_replace( $root . '/', '', $out ), count( $entries ), count( $files ) );

/**
 * The set of translatable strings a catalogue declares, sorted.
 *
 * Not the file, and not even the file below its header. Two earlier versions of this
 * were wrong in ways worth recording, because each would have produced a rule nobody
 * could live with:
 *
 *   - Comparing whole files compared `POT-Creation-Date`, so a catalogue was stale one
 *     second after it was written.
 *   - Comparing everything below the header compared the `#:` source references, so
 *     any edit that shifted a line above a `__()` call demanded a regeneration. Caught
 *     by --check reporting 689 committed against 689 produced — identical counts, and
 *     still "stale".
 *
 * What a translator can actually be missing is a string. That is what this compares.
 *
 * @return string[] Sorted msgid/msgid_plural literals, header entry excluded.
 */
function ow_pot_strings( string $pot ): array {
	preg_match_all( '/^msgid(?:_plural)? (".*")$/m', $pot, $matches );

	$strings = array_values(
		array_filter(
			$matches[1] ?? array(),
			static function ( string $literal ): bool {
				return '""' !== $literal;
			}
		)
	);

	sort( $strings );

	return $strings;
}

/**
 * Read the literal string arguments of a call, or null when any are dynamic.
 *
 * A concatenated or variable argument cannot be extracted, and guessing would
 * put a wrong string in the catalogue.
 *
 * @return array<int,string>|null
 */
function ow_pot_read_args( array $tokens, int $start, int $count ): ?array {
	$i = $start + 1;

	while ( $i < $count && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
		++$i;
	}

	if ( $i >= $count || '(' !== $tokens[ $i ] ) {
		return null;
	}

	$depth   = 0;
	$args    = array();
	$current = null;
	$dynamic = false;

	for ( ; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( '(' === $token ) {
			++$depth;
			continue;
		}

		if ( ')' === $token ) {
			--$depth;
			if ( 0 === $depth ) {
				$args[] = $dynamic ? null : $current;
				break;
			}
			continue;
		}

		if ( 1 === $depth && ',' === $token ) {
			$args[]  = $dynamic ? null : $current;
			$current = null;
			$dynamic = false;
			continue;
		}

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$current = ow_pot_unquote( $token[1] );
			continue;
		}

		$dynamic = true;
	}

	return $args;
}

/**
 * The `translators:` comment immediately above a call, if there is one.
 */
function ow_pot_translator_comment( array $tokens, int $index ): ?string {
	for ( $i = $index; $i >= 0 && $i > $index - 40; $i-- ) {
		if ( ! is_array( $tokens[ $i ] ) || T_COMMENT !== $tokens[ $i ][0] ) {
			continue;
		}

		if ( false === stripos( $tokens[ $i ][1], 'translators:' ) ) {
			continue;
		}

		$text = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $tokens[ $i ][1] ) );

		return trim( $text );
	}

	return null;
}

function ow_pot_unquote( string $literal ): string {
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}

	return stripcslashes( $body );
}

function ow_pot_quote( string $value ): string {
	$value = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	$value = str_replace( "\n", '\\n', $value );
	$value = str_replace( "\t", '\\t', $value );

	return '"' . $value . '"';
}
