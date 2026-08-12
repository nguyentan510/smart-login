<?php
/**
 * Read access to the bundled administrative-unit dataset.
 *
 * Data lives in plain PHP arrays under data/ so OPcache keeps them in memory:
 * no JSON parsing, no database round-trip, no network call at runtime.
 *
 * @package OmniWP
 */

namespace OmniWP\Address;

defined( 'ABSPATH' ) || exit;

class AddressRepository {

	/** @var array<string,array>|null Province list, loaded once per request. */
	private static $provinces = null;

	/** @var array<string,array<string,array>> Ward lists keyed by province code. */
	private static $wards = array();

	private static function data_dir(): string {
		return OMNIWP_DIR . 'data/';
	}

	/**
	 * Load a data file, tolerating a missing dataset rather than fataling.
	 */
	private static function load( string $relative ): array {
		$file = self::data_dir() . $relative;

		if ( ! is_readable( $file ) ) {
			return array();
		}

		$data = include $file;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return array<string,array{name:string,short:string,type:string}>
	 */
	public static function provinces(): array {
		if ( null === self::$provinces ) {
			self::$provinces = self::load( 'provinces.php' );
		}

		return self::$provinces;
	}

	/**
	 * @return array{name:string,short:string,type:string}|null
	 */
	public static function find_province( string $code ): ?array {
		$code      = self::province_code( $code );
		$provinces = self::provinces();

		return $provinces[ $code ] ?? null;
	}

	public static function province_name( string $code ): string {
		$province = self::find_province( $code );

		return $province ? $province['name'] : '';
	}

	/**
	 * Find province code by exact name or normalized name.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function find_province_code_by_name( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}

		$provinces  = self::provinces();
		$normalized = AddressNormalizer::normalize( $name );

		foreach ( $provinces as $code => $prov ) {
			if ( $name === $prov['name'] || $name === ( $prov['short'] ?? '' ) ) {
				return (string) $code;
			}
			if ( $normalized === AddressNormalizer::normalize( $prov['name'] ) || $normalized === AddressNormalizer::normalize( $prov['short'] ?? '' ) ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * Wards of one province.
	 *
	 * @return array<string,array{name:string,type:string}>
	 */
	public static function wards( string $province_code ): array {
		$province_code = self::province_code( $province_code );

		if ( '' === $province_code ) {
			return array();
		}

		if ( ! isset( self::$wards[ $province_code ] ) ) {
			self::$wards[ $province_code ] = self::load( 'wards/' . $province_code . '.php' );
		}

		return self::$wards[ $province_code ];
	}

	/**
	 * Look a ward up inside a specific province.
	 *
	 * The province is required on purpose: this is the check that stops a
	 * tampered form pairing a ward with a province it does not belong to.
	 *
	 * @return array{name:string,type:string}|null
	 */
	public static function find_ward( string $ward_code, string $province_code ): ?array {
		$ward_code = self::ward_code( $ward_code );

		if ( '' === $ward_code ) {
			return null;
		}

		$wards = self::wards( $province_code );

		return $wards[ $ward_code ] ?? null;
	}

	public static function ward_name( string $ward_code, string $province_code ): string {
		$ward = self::find_ward( $ward_code, $province_code );

		return $ward ? $ward['name'] : '';
	}

	/**
	 * Find ward code by exact name or normalized name.
	 *
	 * @param string $ward_name
	 * @param string $province_code
	 * @return string
	 */
	public static function find_ward_code_by_name( string $ward_name, string $province_code ): string {
		$ward_name     = trim( $ward_name );
		$province_code = self::province_code( $province_code );

		if ( '' === $ward_name || '' === $province_code ) {
			return '';
		}

		$wards      = self::wards( $province_code );
		$normalized = AddressNormalizer::normalize( $ward_name );

		foreach ( $wards as $code => $ward ) {
			if ( $ward_name === $ward['name'] ) {
				return (string) $code;
			}
			if ( $normalized === AddressNormalizer::normalize( $ward['name'] ) ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * Does this ward really belong to this province?
	 */
	public static function is_valid_pair( string $ward_code, string $province_code ): bool {
		return null !== self::find_ward( $ward_code, $province_code );
	}


	/**
	 * Is a usable dataset installed?
	 *
	 * Used by the admin status panel and by the tests, which must not assert
	 * against placeholder data.
	 */
	public static function is_dataset_installed(): bool {
		return count( self::provinces() ) >= 30;
	}

	/**
	 * Total ward count across every province. Only for diagnostics — it reads
	 * all 34 files, so never call it during a normal page load.
	 */
	public static function count_wards(): int {
		$total = 0;

		foreach ( array_keys( self::provinces() ) as $code ) {
			$total += count( self::wards( (string) $code ) );
		}

		return $total;
	}

	/**
	 * Province codes are 2 digits, ward codes 5 — both with leading zeros.
	 *
	 * Zero-padding on the way in matters: anything that round-trips a code
	 * through a JSON number or an integer cast turns "00076" into "76", and a
	 * silent lookup miss there would drop the customer's ward.
	 */
	private static function clean_code( string $code, int $width = 0 ): string {
		$digits = preg_replace( '/[^0-9]/', '', trim( $code ) );

		if ( null === $digits || '' === $digits ) {
			return '';
		}

		return $width > 0 ? str_pad( $digits, $width, '0', STR_PAD_LEFT ) : $digits;
	}

	public static function province_code( string $code ): string {
		return self::clean_code( $code, 2 );
	}

	public static function ward_code( string $code ): string {
		return self::clean_code( $code, 5 );
	}

	/**
	 * Drop the request-level caches. Tests use this after swapping datasets.
	 */
	public static function flush(): void {
		self::$provinces = null;
		self::$wards     = array();
	}
}
