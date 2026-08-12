<?php
/**
 * Generate the bundled administrative-unit dataset from a source JSON file.
 *
 * Usage:
 *   php bin/build-address-data.php path/to/source.json
 *   php bin/build-address-data.php path/to/provinces.json path/to/wards.json
 *
 * Writes:
 *   data/provinces.php
 *   data/wards/{province_code}.php   (one per province)
 *
 * The script prints the province and ward counts it wrote. Vietnam's two-level
 * structure since 2025-07-01 should give 34 provinces and roughly 3,320
 * wards — any other number means the source is stale or the wrong shape.
 *
 * Field names vary between public datasets, so the readers below accept the
 * common spellings rather than insisting on one schema.
 *
 * @package OmniWP
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/Address/class-address-normalizer.php';

use OmniWP\Address\AddressNormalizer;

const EXPECTED_PROVINCES = 34;
const EXPECTED_WARDS_MIN = 3200;
const EXPECTED_WARDS_MAX = 3400;

$root     = dirname( __DIR__ );
$data_dir = $root . '/data';

// ---------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------

$args  = array();
$force = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--force' === $arg ) {
		$force = true;
	} elseif ( 0 === strpos( $arg, '--out=' ) ) {
		$data_dir = rtrim( substr( $arg, 6 ), '/\\' );
	} else {
		$args[] = $arg;
	}
}

if ( ! $args ) {
	fwrite( STDERR, "Usage: php bin/build-address-data.php <source.json> [wards.json] [--out=DIR] [--force]\n" );
	fwrite( STDERR, "  --out=DIR  write somewhere other than data/\n" );
	fwrite( STDERR, "  --force    write even when the unit counts look wrong\n" );
	exit( 1 );
}

foreach ( $args as $target_path ) {
	if ( ! is_readable( $target_path ) ) {
		fwrite( STDERR, "Cannot read: {$target_path}\n" );
		exit( 1 );
	}
}

/**
 * @return mixed
 */
function read_json( string $target_path ) {
	$raw     = file_get_contents( $target_path );
	$decoded = json_decode( $raw, true );

	if ( null === $decoded ) {
		fwrite( STDERR, "Not valid JSON: {$target_path} (" . json_last_error_msg() . ")\n" );
		exit( 1 );
	}

	return $decoded;
}

/**
 * First present key out of several spellings.
 *
 * @param array $row
 * @param array $keys
 * @return string
 */
function pick( array $row, array $keys ): string {
	foreach ( $keys as $key ) {
		if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
			return trim( (string) $row[ $key ] );
		}
	}

	return '';
}

/**
 * @param array $row
 * @param array $keys
 * @return array
 */
function pick_list( array $row, array $keys ): array {
	foreach ( $keys as $key ) {
		if ( isset( $row[ $key ] ) && is_array( $row[ $key ] ) ) {
			return $row[ $key ];
		}
	}

	return array();
}

function province_code( string $raw ): string {
	$digits = preg_replace( '/[^0-9]/', '', $raw );

	return '' === $digits ? '' : str_pad( $digits, 2, '0', STR_PAD_LEFT );
}

function ward_code( string $raw ): string {
	$digits = preg_replace( '/[^0-9]/', '', $raw );

	return '' === $digits ? '' : str_pad( $digits, 5, '0', STR_PAD_LEFT );
}

/**
 * "Thành phố Hà Nội" -> "Hà Nội"; "Tỉnh Lào Cai" -> "Lào Cai".
 */
function short_name( string $name ): string {
	$prefixes = array( 'Thành phố ', 'Tỉnh ', 'Thành Phố ' );

	foreach ( $prefixes as $prefix ) {
		if ( 0 === mb_strpos( $name, $prefix ) ) {
			return trim( mb_substr( $name, mb_strlen( $prefix ) ) );
		}
	}

	return $name;
}

/**
 * Machine-readable unit type derived from the name, so the dataset keeps the
 * distinction between phường / xã / đặc khu even though the UI labels them
 * together.
 */
function unit_type( string $name, string $declared = '' ): string {
	$slug = AddressNormalizer::slug( '' !== $declared ? $declared : $name );

	foreach ( array(
		'dac khu'   => 'dac_khu',
		'thanh pho' => 'thanh_pho',
		'thi tran'  => 'thi_tran',
		'thi xa'    => 'thi_xa',
		'phuong'    => 'phuong',
		'quan'      => 'quan',
		'huyen'     => 'huyen',
		'tinh'      => 'tinh',
		'xa'        => 'xa',
	) as $needle => $type ) {
		if ( 0 === strpos( $slug, $needle ) || false !== strpos( $slug, ' ' . $needle . ' ' ) ) {
			return $type;
		}
	}

	return 'khac';
}

// ---------------------------------------------------------------------
// Parse into: provinces[code] = ['name'=>…], wards[province_code][ward_code] = ['name'=>…]
// ---------------------------------------------------------------------

$provinces = array();
$wards     = array();

$source = read_json( $args[0] );

// Some datasets wrap the list in an envelope.
if ( is_array( $source ) && ! isset( $source[0] ) ) {
	foreach ( array( 'data', 'provinces', 'result', 'items' ) as $key ) {
		if ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) ) {
			$source = $source[ $key ];
			break;
		}
	}
}

if ( ! is_array( $source ) ) {
	fwrite( STDERR, "Unrecognised source structure in {$args[0]}.\n" );
	exit( 1 );
}

// Normalise an associative province map into a list.
if ( ! isset( $source[0] ) ) {
	$source = array_values( $source );
}

foreach ( $source as $entry ) {
	if ( ! is_array( $entry ) ) {
		continue;
	}

	$p_code = province_code( pick( $entry, array( 'code', 'Code', 'province_code', 'ProvinceCode', 'id', 'ma', 'value' ) ) );
	// Prefer the qualified form ("Thành phố Hà Nội") over the bare one; short_name()
	// derives the short label from it.
	$p_name = pick( $entry, array( 'name_with_type', 'full_name', 'FullName', 'name', 'Name', 'ten', 'label', 'title' ) );

	if ( '' === $p_code || '' === $p_name ) {
		continue;
	}

	$provinces[ $p_code ] = array(
		'name'  => $p_name,
		'short' => short_name( $p_name ),
		'type'  => unit_type( $p_name, pick( $entry, array( 'type', 'division_type', 'Type' ) ) ),
	);

	$children = pick_list( $entry, array( 'wards', 'communes', 'xa', 'phuong_xa', 'children', 'items', 'districts' ) );

	foreach ( $children as $child ) {
		if ( ! is_array( $child ) ) {
			continue;
		}

		$w_code = ward_code( pick( $child, array( 'code', 'Code', 'ward_code', 'WardCode', 'id', 'ma', 'value' ) ) );
		$w_name = pick( $child, array( 'name_with_type', 'full_name', 'FullName', 'name', 'Name', 'ten', 'label', 'title' ) );

		if ( '' === $w_code || '' === $w_name ) {
			continue;
		}

		$wards[ $p_code ][ $w_code ] = array(
			'name' => $w_name,
			'type' => unit_type( $w_name, pick( $child, array( 'type', 'division_type', 'Type' ) ) ),
		);
	}
}

// Second file: a flat ward list carrying its province code.
if ( isset( $args[1] ) ) {
	$ward_source = read_json( $args[1] );

	if ( is_array( $ward_source ) && ! isset( $ward_source[0] ) ) {
		foreach ( array( 'data', 'wards', 'result', 'items' ) as $key ) {
			if ( isset( $ward_source[ $key ] ) && is_array( $ward_source[ $key ] ) ) {
				$ward_source = $ward_source[ $key ];
				break;
			}
		}
	}

	foreach ( (array) $ward_source as $child ) {
		if ( ! is_array( $child ) ) {
			continue;
		}

		$p_code = province_code( pick( $child, array( 'province_code', 'ProvinceCode', 'parent_code', 'tinh_code', 'parent' ) ) );
		$w_code = ward_code( pick( $child, array( 'code', 'Code', 'ward_code', 'id', 'ma' ) ) );
		$w_name = pick( $child, array( 'name_with_type', 'full_name', 'FullName', 'name', 'Name', 'ten' ) );

		if ( '' === $p_code || '' === $w_code || '' === $w_name || ! isset( $provinces[ $p_code ] ) ) {
			continue;
		}

		$wards[ $p_code ][ $w_code ] = array(
			'name' => $w_name,
			'type' => unit_type( $w_name, pick( $child, array( 'type', 'division_type', 'Type' ) ) ),
		);
	}
}

if ( ! $provinces ) {
	fwrite( STDERR, "No provinces parsed. Check the source file's field names.\n" );
	exit( 1 );
}

ksort( $provinces );

// ---------------------------------------------------------------------
// Validate BEFORE writing — a half-correct dataset on disk is worse than none.
// ---------------------------------------------------------------------

$province_count = count( $provinces );
$total_wards    = 0;

foreach ( $provinces as $p_code => $province ) {
	$total_wards += count( $wards[ $p_code ] ?? array() );
}

$problems = array();

if ( EXPECTED_PROVINCES !== $province_count ) {
	$problems[] = sprintf( 'Expected %d provinces, got %d.', EXPECTED_PROVINCES, $province_count );
}

if ( $total_wards < EXPECTED_WARDS_MIN || $total_wards > EXPECTED_WARDS_MAX ) {
	$problems[] = sprintf( 'Expected roughly %d-%d wards, got %d.', EXPECTED_WARDS_MIN, EXPECTED_WARDS_MAX, $total_wards );
}

foreach ( $provinces as $p_code => $province ) {
	if ( empty( $wards[ $p_code ] ) ) {
		$problems[] = sprintf( 'No wards for %s (%s).', $province['name'], $p_code );
	}
}

if ( $problems && ! $force ) {
	fwrite( STDERR, "\nREFUSING TO WRITE — the source does not match the post-2025 two-level structure:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, "  - {$problem}\n" );
	}

	fwrite( STDERR, "\nParsed {$province_count} provinces / {$total_wards} wards.\n" );
	fwrite( STDERR, "If the source still contains districts, it is the wrong dataset.\n" );
	fwrite( STDERR, "Pass --force only if you are certain the source is right.\n" );
	exit( 1 );
}

if ( $problems ) {
	fwrite( STDERR, "\nWARNING: writing despite --force:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, "  - {$problem}\n" );
	}
}

// ---------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------

/**
 * Render a PHP array file with a stable, diff-friendly layout.
 */
function write_php_file( string $target_path, string $header, array $rows ): void {
	$out = "<?php\n/**\n * {$header}\n *\n * GENERATED FILE — do not edit by hand.\n * Regenerate with: php bin/build-address-data.php <source.json>\n *\n * @package OmniWP\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn array(\n";

	foreach ( $rows as $key => $fields ) {
		$parts = array();

		foreach ( $fields as $name => $value ) {
			$parts[] = "'{$name}' => " . var_export( $value, true );
		}

		$out .= "\t'" . $key . "' => array( " . implode( ', ', $parts ) . " ),\n";
	}

	$out .= ");\n";

	if ( false === file_put_contents( $target_path, $out ) ) {
		fwrite( STDERR, "Failed to write {$target_path}\n" );
		exit( 1 );
	}
}

if ( ! is_dir( $data_dir . '/wards' ) && ! mkdir( $data_dir . '/wards', 0755, true ) && ! is_dir( $data_dir . '/wards' ) ) {
	fwrite( STDERR, "Cannot create {$data_dir}/wards\n" );
	exit( 1 );
}

// Clear stale per-province files so a shrinking dataset does not leave orphans.
foreach ( glob( $data_dir . '/wards/*.php' ) as $stale ) {
	unlink( $stale );
}

write_php_file( $data_dir . '/provinces.php', 'Vietnamese provinces and centrally-governed cities.', $provinces );

foreach ( $provinces as $p_code => $province ) {
	$list = $wards[ $p_code ] ?? array();
	ksort( $list );

	write_php_file(
		$data_dir . '/wards/' . $p_code . '.php',
		'Wards of ' . $province['name'] . '.',
		$list
	);
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------

echo "\nWrote to {$data_dir}:\n";
echo "  provinces.php        {$province_count} provinces\n";
echo "  wards/*.php          {$province_count} files, {$total_wards} wards\n\n";

echo "Sample of what was parsed:\n";

$sample = array_slice( $provinces, 0, 3, true );

foreach ( $sample as $p_code => $province ) {
	$list  = $wards[ $p_code ] ?? array();
	$first = reset( $list );

	// Pad by characters, not bytes, or Vietnamese names break the columns.
	$name = $province['name'];
	$pad  = str_repeat( ' ', max( 1, 28 - mb_strlen( $name ) ) );

	printf(
		"  %s  %s%s%4d wards   e.g. %s\n",
		$p_code,
		$name,
		$pad,
		count( $list ),
		$first ? $first['name'] : '—'
	);
}

if ( $problems ) {
	echo "\nWritten with --force despite the warnings above. Verify before shipping.\n";
	exit( 0 );
}

echo "\nCounts match the post-2025 two-level structure.\n";
echo "Spot-check the provinces you know before shipping.\n";
exit( 0 );
