<?php
/**
 * Vietnamese text normalisation for address search.
 *
 * Everything here is pure string work with no dependency on the `intl`
 * extension, so it behaves identically on every host.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Address;

defined( 'ABSPATH' ) || exit;

class AddressNormalizer {

	/**
	 * Every accented Vietnamese character mapped to its ASCII base.
	 *
	 * `đ`/`Đ` are the ones most often forgotten: they are separate letters
	 * rather than accented `d`, so no generic accent-stripping catches them.
	 */
	const MAP = array(
		'à' => 'a',
		'á' => 'a',
		'ạ' => 'a',
		'ả' => 'a',
		'ã' => 'a',
		'â' => 'a',
		'ầ' => 'a',
		'ấ' => 'a',
		'ậ' => 'a',
		'ẩ' => 'a',
		'ẫ' => 'a',
		'ă' => 'a',
		'ằ' => 'a',
		'ắ' => 'a',
		'ặ' => 'a',
		'ẳ' => 'a',
		'ẵ' => 'a',
		'è' => 'e',
		'é' => 'e',
		'ẹ' => 'e',
		'ẻ' => 'e',
		'ẽ' => 'e',
		'ê' => 'e',
		'ề' => 'e',
		'ế' => 'e',
		'ệ' => 'e',
		'ể' => 'e',
		'ễ' => 'e',
		'ì' => 'i',
		'í' => 'i',
		'ị' => 'i',
		'ỉ' => 'i',
		'ĩ' => 'i',
		'ò' => 'o',
		'ó' => 'o',
		'ọ' => 'o',
		'ỏ' => 'o',
		'õ' => 'o',
		'ô' => 'o',
		'ồ' => 'o',
		'ố' => 'o',
		'ộ' => 'o',
		'ổ' => 'o',
		'ỗ' => 'o',
		'ơ' => 'o',
		'ờ' => 'o',
		'ớ' => 'o',
		'ợ' => 'o',
		'ở' => 'o',
		'ỡ' => 'o',
		'ù' => 'u',
		'ú' => 'u',
		'ụ' => 'u',
		'ủ' => 'u',
		'ũ' => 'u',
		'ư' => 'u',
		'ừ' => 'u',
		'ứ' => 'u',
		'ự' => 'u',
		'ử' => 'u',
		'ữ' => 'u',
		'ỳ' => 'y',
		'ý' => 'y',
		'ỵ' => 'y',
		'ỷ' => 'y',
		'ỹ' => 'y',
		'đ' => 'd',
		'À' => 'A',
		'Á' => 'A',
		'Ạ' => 'A',
		'Ả' => 'A',
		'Ã' => 'A',
		'Â' => 'A',
		'Ầ' => 'A',
		'Ấ' => 'A',
		'Ậ' => 'A',
		'Ẩ' => 'A',
		'Ẫ' => 'A',
		'Ă' => 'A',
		'Ằ' => 'A',
		'Ắ' => 'A',
		'Ặ' => 'A',
		'Ẳ' => 'A',
		'Ẵ' => 'A',
		'È' => 'E',
		'É' => 'E',
		'Ẹ' => 'E',
		'Ẻ' => 'E',
		'Ẽ' => 'E',
		'Ê' => 'E',
		'Ề' => 'E',
		'Ế' => 'E',
		'Ệ' => 'E',
		'Ể' => 'E',
		'Ễ' => 'E',
		'Ì' => 'I',
		'Í' => 'I',
		'Ị' => 'I',
		'Ỉ' => 'I',
		'Ĩ' => 'I',
		'Ò' => 'O',
		'Ó' => 'O',
		'Ọ' => 'O',
		'Ỏ' => 'O',
		'Õ' => 'O',
		'Ô' => 'O',
		'Ồ' => 'O',
		'Ố' => 'O',
		'Ộ' => 'O',
		'Ổ' => 'O',
		'Ỗ' => 'O',
		'Ơ' => 'O',
		'Ờ' => 'O',
		'Ớ' => 'O',
		'Ợ' => 'O',
		'Ở' => 'O',
		'Ỡ' => 'O',
		'Ù' => 'U',
		'Ú' => 'U',
		'Ụ' => 'U',
		'Ủ' => 'U',
		'Ũ' => 'U',
		'Ư' => 'U',
		'Ừ' => 'U',
		'Ứ' => 'U',
		'Ự' => 'U',
		'Ử' => 'U',
		'Ữ' => 'U',
		'Ỳ' => 'Y',
		'Ý' => 'Y',
		'Ỵ' => 'Y',
		'Ỷ' => 'Y',
		'Ỹ' => 'Y',
		'Đ' => 'D',
	);

	/**
	 * Strip Vietnamese accents, keeping case and punctuation.
	 */
	public static function unaccent( string $text ): string {
		return strtr( $text, self::MAP );
	}

	/**
	 * Search key: no accents, lowercase, single spaces, letters and digits only.
	 *
	 * "Phường Cầu Giấy" -> "phuong cau giay"
	 * "Thị xã Sơn Tây"  -> "thi xa son tay"
	 */
	public static function slug( string $text ): string {
		$text = self::unaccent( $text );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );

		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * Drop the administrative prefix so "Cầu Giấy" also matches
	 * "Phường Cầu Giấy" from the very first character typed.
	 *
	 * Returns '' when the name is nothing but a prefix.
	 */
	public static function strip_prefix( string $slug ): string {
		$prefixes = array(
			'thanh pho ',
			'thi xa ',
			'thi tran ',
			'quan ',
			'huyen ',
			'phuong ',
			'dac khu ',
			'tinh ',
			'xa ',
		);

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $slug, $prefix ) ) {
				return trim( substr( $slug, strlen( $prefix ) ) );
			}
		}

		return $slug;
	}

	/**
	 * The haystack stored in the search index for one ward.
	 *
	 * Holds the full name, the name without its prefix and the province name,
	 * so a single `strpos` covers every way a person might type it.
	 */
	public static function index_key( string $ward_name, string $province_name ): string {
		$ward          = self::slug( $ward_name );
		$bare          = self::strip_prefix( $ward );
		$province      = self::slug( $province_name );
		$province_bare = self::strip_prefix( $province );

		$parts = array_unique( array_filter( array( $ward, $bare, $province, $province_bare ) ) );

		return implode( '|', $parts );
	}
}
