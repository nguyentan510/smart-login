<?php
/**
 * Authenticated encryption for secrets that must survive in the options table.
 *
 * Extracted from ProviderCredentials in 9.8, unchanged, because the captcha
 * secret needs exactly the same treatment and a second implementation of AEAD is
 * the last thing this plugin should grow. ProviderCredentials now delegates here
 * and keeps its own public API, so the provider gate covers both callers.
 *
 * AES-256-GCM: the tag is what makes a tampered ciphertext fail to decrypt at all
 * rather than decrypt into something attacker-chosen.
 *
 * @package OmniWP
 */

namespace OmniWP\Security;

defined( 'ABSPATH' ) || exit;

final class SecretBox {

	const CIPHER = 'aes-256-gcm';

	/**
	 * Derived from the site's own salts, so a database copied to another install
	 * cannot be decrypted there.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . OMNIWP_BASENAME, true );
	}

	/**
	 * The stored record shape is **exactly** what ProviderCredentials wrote before
	 * this class existed — `version`, `cipher`, `iv`, `tag`, `ciphertext`.
	 *
	 * Not a style choice. Renaming a key here would leave every already-stored
	 * provider secret undecryptable on upgrade, which is this project's most
	 * repeated failure: a rename crossing a boundary no test covers.
	 *
	 * @return array{version:int,cipher:string,iv:string,tag:string,ciphertext:string}|null
	 */
	public static function seal( string $plaintext ): ?array {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return null;
		}

		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext || '' === $tag ) {
			return null;
		}

		return array(
			'version'    => 1,
			'cipher'     => self::CIPHER,
			'iv'         => base64_encode( $iv ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			'tag'        => base64_encode( $tag ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			'ciphertext' => base64_encode( $ciphertext ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		);
	}

	/**
	 * @param array $record As produced by seal().
	 * @return string Empty when the record is unusable or has been tampered with.
	 */
	public static function open( array $record ): string {
		if (
			1 !== (int) ( $record['version'] ?? 0 )
			|| self::CIPHER !== (string) ( $record['cipher'] ?? '' )
			|| ! function_exists( 'openssl_decrypt' )
		) {
			return '';
		}

		$iv         = base64_decode( (string) ( $record['iv'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$tag        = base64_decode( (string) ( $record['tag'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$ciphertext = base64_decode( (string) ( $record['ciphertext'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return '';
		}

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Read one secret out of a named option holding a map of them.
	 */
	public static function get( string $option, string $key ): string {
		$stored = get_option( $option, array() );
		$record = is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null;

		return is_array( $record ) ? self::open( $record ) : '';
	}

	/**
	 * Write one secret into a named option holding a map of them.
	 */
	public static function put( string $option, string $key, string $secret ): bool {
		$secret = trim( $secret );

		if ( '' === $key || '' === $secret ) {
			return false;
		}

		$record = self::seal( $secret );

		if ( ! is_array( $record ) ) {
			return false;
		}

		$stored         = get_option( $option, array() );
		$stored         = is_array( $stored ) ? $stored : array();
		$stored[ $key ] = $record;

		return update_option( $option, $stored );
	}

	public static function forget( string $option, string $key ): bool {
		$stored = get_option( $option, array() );

		if ( ! is_array( $stored ) || ! array_key_exists( $key, $stored ) ) {
			return true;
		}

		unset( $stored[ $key ] );

		return update_option( $option, $stored );
	}
}
