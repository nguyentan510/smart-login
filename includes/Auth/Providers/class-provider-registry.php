<?php
/**
 * Registry for enabled external login providers.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {

	private array $providers = array();

	public function __construct( ?array $providers = null ) {
		foreach ( $providers ?? array( new GoogleProvider(), new ZaloProvider() ) as $provider ) {
			if ( $provider instanceof LoginProviderInterface ) {
				$this->register( $provider );
			}
		}
	}

	public function register( LoginProviderInterface $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	public function get( string $id ): ?LoginProviderInterface {
		return $this->providers[ sanitize_key( $id ) ] ?? null;
	}

	public function all(): array {
		return $this->providers;
	}

	public function available(): array {
		return array_filter( $this->providers, static fn( LoginProviderInterface $provider ): bool => $provider->is_available() );
	}
}
