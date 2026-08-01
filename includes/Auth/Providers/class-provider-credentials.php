<?php
/**
 * Resolves provider credentials from deployment constants or encrypted options.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

use SmartLogin\Security\SecretBox;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderCredentials {

	const SECRET_OPTION = 'smart_login_provider_secrets';

	/**
	 * Kept for anything still reading it. The cipher itself moved to SecretBox in
	 * 9.8, together with the stored record shape, which did **not** change — a
	 * secret sealed by the old code opens with the new.
	 */
	const CIPHER = SecretBox::CIPHER;

	public static function client_id( string $provider ): string {
		$provider = sanitize_key( $provider );
		$constant = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_CLIENT_ID' : 'SMART_LOGIN_ZALO_APP_ID';
		$setting  = 'google' === $provider ? 'providers.google.client_id' : 'providers.zalo.app_id';

		return self::constant_value( $constant ) ?: trim( (string) Settings::get( $setting, '' ) );
	}

	public static function secret( string $provider ): string {
		$provider = sanitize_key( $provider );
		$constant = 'google' === $provider ? 'SMART_LOGIN_GOOGLE_CLIENT_SECRET' : 'SMART_LOGIN_ZALO_APP_SECRET';
		$external = self::constant_value( $constant );
		if ( '' !== $external ) {
			return $external;
		}

		return SecretBox::get( self::SECRET_OPTION, $provider );
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

		if ( ! in_array( $provider, array( 'google', 'zalo' ), true ) ) {
			return false;
		}

		return SecretBox::put( self::SECRET_OPTION, $provider, $secret );
	}

	public static function clear_secret( string $provider ): bool {
		return SecretBox::forget( self::SECRET_OPTION, sanitize_key( $provider ) );
	}

	private static function constant_value( string $name ): string {
		return defined( $name ) ? trim( (string) constant( $name ) ) : '';
	}
}
