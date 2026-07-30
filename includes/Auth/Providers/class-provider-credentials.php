<?php
/**
 * Resolves provider credentials from deployment constants or encrypted options.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderCredentials {

	const SECRET_OPTION = 'smart_login_provider_secrets';
	const CIPHER        = 'aes-256-gcm';

	public static function client_id( string $provider ): string {
		$provider = sanitize_key( $provider );
		$constant = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_CLIENT_ID' : 'SMART_LOGIN_ZALO_APP_ID';
		$setting  = 'google' === $provider ? 'google_client_id' : 'zalo_app_id';

		return self::constant_value( $constant ) ?: trim( (string) Settings::get( $setting, '' ) );
	}

	public static function secret( string $provider ): string {
		$provider = sanitize_key( $provider );
		$constant = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_CLIENT_SECRET' : 'SMART_LOGIN_ZALO_APP_SECRET';
		$external = self::constant_value( $constant );
		if ( '' !== $external ) {
			return $external;
		}

		$stored = get_option( self::SECRET_OPTION, array() );
		$record = is_array( $stored ) ? ( $stored[ $provider ] ?? null ) : null;
		return is_array( $record ) ? self::decrypt( $record ) : '';
	}

	public static function redirect_uri( string $provider ): string {
		$provider = sanitize_key( $provider );
		$constant = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_REDIRECT_URI' : 'SMART_LOGIN_ZALO_REDIRECT_URI';
		$external = self::constant_value( $constant );
		if ( '' !== $external ) {
			return $external;
		}

		return admin_url( 'admin-post.php?action=smart_login_provider_callback&provider=' . $provider );
	}

	public static function is_configured( string $provider ): bool {
		return '' !== self::client_id( $provider ) && '' !== self::secret( $provider );
	}

	public static function source( string $provider ): string {
		$provider        = sanitize_key( $provider );
		$id_constant     = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_CLIENT_ID' : 'SMART_LOGIN_ZALO_APP_ID';
		$secret_constant = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_CLIENT_SECRET' : 'SMART_LOGIN_ZALO_APP_SECRET';
		if ( '' !== self::constant_value( $id_constant ) || '' !== self::constant_value( $secret_constant ) ) {
			return 'environment';
		}
		return self::is_configured( $provider ) ? 'settings' : 'missing';
	}

	public static function store_secret( string $provider, string $secret ): bool {
		$provider = sanitize_key( $provider );
		$secret   = trim( $secret );
		if ( ! in_array( $provider, array( 'google', 'zalo' ), true ) || '' === $secret ) {
			return false;
		}
		$record = self::encrypt( $secret );
		if ( ! is_array( $record ) ) {
			return false;
		}
		$stored              = get_option( self::SECRET_OPTION, array() );
		$stored              = is_array( $stored ) ? $stored : array();
		$stored[ $provider ] = $record;
		return update_option( self::SECRET_OPTION, $stored );
	}

	public static function clear_secret( string $provider ): bool {
		$provider = sanitize_key( $provider );
		$stored   = get_option( self::SECRET_OPTION, array() );
		if ( ! is_array( $stored ) || ! array_key_exists( $provider, $stored ) ) {
			return true;
		}
		unset( $stored[ $provider ] );
		return update_option( self::SECRET_OPTION, $stored );
	}

	private static function constant_value( string $name ): string {
		return defined( $name ) ? trim( (string) constant( $name ) ) : '';
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . SMART_LOGIN_BASENAME, true );
	}

	private static function encrypt( string $plaintext ): ?array {
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
			'iv'         => base64_encode( $iv ),
			'tag'        => base64_encode( $tag ),
			'ciphertext' => base64_encode( $ciphertext ),
		);
	}

	private static function decrypt( array $record ): string {
		if (
			1 !== (int) ( $record['version'] ?? 0 )
			|| self::CIPHER !== (string) ( $record['cipher'] ?? '' )
			|| ! function_exists( 'openssl_decrypt' )
		) {
			return '';
		}
		$iv         = base64_decode( (string) ( $record['iv'] ?? '' ), true );
		$tag        = base64_decode( (string) ( $record['tag'] ?? '' ), true );
		$ciphertext = base64_decode( (string) ( $record['ciphertext'] ?? '' ), true );
		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return '';
		}
		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plaintext ? '' : $plaintext;
	}
}
