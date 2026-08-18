<?php
/**
 * Resolves provider credentials from deployment constants or encrypted options.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth\Providers;

use OmniWP\Security\SecretBox;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderCredentials {

	const SECRET_OPTION = 'OMNIWP_provider_secrets';

	/**
	 * Kept for anything still reading it. The cipher itself moved to SecretBox in
	 * 9.8, together with the stored record shape, which did **not** change — a
	 * secret sealed by the old code opens with the new.
	 */
	const CIPHER = SecretBox::CIPHER;

	/**
	 * Deployment constants and the settings path, per provider.
	 *
	 * A map rather than the pair of `'google' === $provider ? … : …` ternaries
	 * this used to be. With two providers those ternaries read as a choice; with
	 * one they were an accident waiting for the next provider, because *every*
	 * id that was not `google` collected the other provider's constants. A
	 * provider absent from this map now resolves to nothing, which is the answer
	 * an unknown provider should get.
	 */
	const PROVIDERS = array(
		'google' => array(
			'id'       => 'OMNIWP_GOOGLE_CLIENT_ID',
			'secret'   => 'OMNIWP_GOOGLE_CLIENT_SECRET',
			'redirect' => 'OMNIWP_GOOGLE_REDIRECT_URI',
			'setting'  => 'providers.google.client_id',
		),
	);

	public static function client_id( string $provider ): string {
		$provider = sanitize_key( $provider );
		$map      = self::PROVIDERS[ $provider ] ?? null;

		if ( ! $map ) {
			return '';
		}

		return self::constant_value( $map['id'] ) ?: trim( (string) Settings::get( $map['setting'], '' ) );
	}

	public static function secret( string $provider ): string {
		$provider = sanitize_key( $provider );
		$map      = self::PROVIDERS[ $provider ] ?? null;

		if ( ! $map ) {
			return '';
		}

		$external = self::constant_value( $map['secret'] );
		if ( '' !== $external ) {
			return $external;
		}

		return SecretBox::get( self::SECRET_OPTION, $provider );
	}

	public static function redirect_uri( string $provider ): string {
		$provider = sanitize_key( $provider );
		$external = isset( self::PROVIDERS[ $provider ] )
			? self::constant_value( self::PROVIDERS[ $provider ]['redirect'] )
			: '';
		if ( '' !== $external ) {
			return $external;
		}

		return admin_url( 'admin-post.php?action=omniwp_provider_callback&provider=' . $provider );
	}

	public static function is_configured( string $provider ): bool {
		return '' !== self::client_id( $provider ) && '' !== self::secret( $provider );
	}

	public static function source( string $provider ): string {
		$provider = sanitize_key( $provider );
		$map      = self::PROVIDERS[ $provider ] ?? null;
		if ( $map && ( '' !== self::constant_value( $map['id'] ) || '' !== self::constant_value( $map['secret'] ) ) ) {
			return 'environment';
		}
		return self::is_configured( $provider ) ? 'settings' : 'missing';
	}

	public static function store_secret( string $provider, string $secret ): bool {
		$provider = sanitize_key( $provider );

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
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
