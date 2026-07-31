<?php
/**
 * Typed access to the stored settings.
 *
 * The schema itself lives in FieldRegistry. Nothing is declared twice here:
 * defaults, types, sanitisers and clamps are all read off the registry, so the
 * only way to add a setting is to add a row there — and a row there is
 * simultaneously the thing that draws the control.
 *
 * Values are addressed by dot path (`otp.ttl`) and stored nested. The read API
 * — get(), get_int(), is_on(), phone_enabled(), email_enabled() — keeps the
 * signatures it always had, so the thirty-odd files that consume settings only
 * ever saw their key strings change.
 *
 * @package SmartLogin
 */

namespace SmartLogin;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'smart_login_settings';

	/** Hidden field naming the tab a save came from. */
	const TAB_FIELD = '_sl_tab';

	/** @var array|null Runtime cache, always in registry shape. */
	private static $cache = null;

	/**
	 * Every setting at its default, nested.
	 */
	public static function defaults(): array {
		$out = array();

		foreach ( FieldRegistry::all() as $path => $field ) {
			self::plant( $out, $path, $field['default'] );
		}

		return $out;
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::hydrate( is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	/**
	 * Force the stored array into the registry's shape: known paths keep their
	 * stored value, missing ones fall back to the default, and anything the
	 * registry does not declare is dropped.
	 *
	 * Walking the registry rather than array_replace_recursive() matters for the
	 * list-valued fields: a recursive merge of two lists pairs them up by index,
	 * so a stored header list shorter than the default would inherit stale rows
	 * off the end instead of replacing it.
	 */
	private static function hydrate( array $stored ): array {
		$out = array();

		foreach ( FieldRegistry::all() as $path => $field ) {
			$value = self::dig( $stored, $path );

			self::plant( $out, $path, null === $value ? $field['default'] : $value );
		}

		return $out;
	}

	/**
	 * @param string $path     Dot path, e.g. `otp.ttl`.
	 * @param mixed  $fallback Value to use when the path is unknown.
	 * @return mixed
	 */
	public static function get( string $path, $fallback = null ) {
		$value = self::dig( self::all(), $path );

		if ( null === $value ) {
			$value = $fallback;
		}

		/**
		 * Filter a single setting value at read time.
		 *
		 * @param mixed  $value
		 * @param string $path
		 */
		return apply_filters( 'smart_login_setting', $value, $path );
	}

	public static function get_int( string $path, int $fallback = 0 ): int {
		return (int) self::get( $path, $fallback );
	}

	public static function is_on( string $path ): bool {
		return (bool) self::get_int( $path );
	}

	/**
	 * @param array $values Dot path => value.
	 */
	public static function update( array $values ): void {
		$all = self::all();

		foreach ( $values as $path => $value ) {
			self::plant( $all, (string) $path, $value );
		}

		update_option( self::OPTION, $all );

		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Whether phone can be used as an identifier.
	 */
	public static function phone_enabled(): bool {
		return in_array( self::get( 'identity.mode' ), array( 'phone_only', 'both' ), true );
	}

	/**
	 * Whether email can be used as an identifier.
	 */
	public static function email_enabled(): bool {
		return in_array( self::get( 'identity.mode' ), array( 'email_only', 'both' ), true );
	}

	// -----------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------

	/**
	 * Clean one submitted tab and merge it onto what is already stored.
	 *
	 * The old version rebuilt the whole option from every default on every save,
	 * which forced each tab to carry the other tabs' values in hidden inputs —
	 * gateway credentials included, printed into the DOM of screens that had no
	 * business showing them. It also meant a key that no tab actually drew got
	 * silently reset, because an absent key and an unchecked checkbox look
	 * identical in $_POST.
	 *
	 * Both problems have the same fix. Only the fields belonging to the posted
	 * tab are considered, and every one of those was definitely rendered — so
	 * "absent" really does mean "unchecked", and every other tab is left exactly
	 * as it was.
	 *
	 * @param mixed $input Raw $_POST slice for this option.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::all();

		self::absorb_provider_secrets( $input );

		foreach ( self::posted_fields( $input ) as $path => $field ) {
			self::plant( $clean, $path, self::sanitize_field( $field, self::dig( $input, $path ) ) );
		}

		self::$cache = null;

		return $clean;
	}

	/**
	 * Which fields this save is allowed to write.
	 *
	 * An unrecognised or missing tab writes nothing rather than everything: a
	 * save that cannot say where it came from has no business rewriting the
	 * whole option.
	 *
	 * @return array<string,array>
	 */
	private static function posted_fields( array $input ): array {
		$tab = isset( $input[ self::TAB_FIELD ] ) ? sanitize_key( (string) $input[ self::TAB_FIELD ] ) : '';

		return isset( FieldRegistry::tabs()[ $tab ] ) ? FieldRegistry::for_tab( $tab ) : array();
	}

	/**
	 * Provider secrets are encrypted at rest and never round-trip through the
	 * form, so they are handled outside the registry.
	 */
	private static function absorb_provider_secrets( array &$input ): void {
		$providers = array(
			'google' => array(
				'secret' => 'google_client_secret',
				'clear'  => 'google_clear_secret',
			),
			'zalo'   => array(
				'secret' => 'zalo_app_secret',
				'clear'  => 'zalo_clear_secret',
			),
		);

		foreach ( $providers as $provider => $fields ) {
			if ( ! empty( $input[ $fields['clear'] ] ) ) {
				\SmartLogin\Auth\Providers\ProviderCredentials::clear_secret( $provider );
			} elseif ( '' !== trim( (string) ( $input[ $fields['secret'] ] ?? '' ) ) ) {
				$stored = \SmartLogin\Auth\Providers\ProviderCredentials::store_secret(
					$provider,
					(string) $input[ $fields['secret'] ]
				);

				if ( ! $stored && function_exists( 'add_settings_error' ) ) {
					add_settings_error(
						self::OPTION,
						'smart_login_provider_secret',
						__( 'Không thể mã hóa secret của provider. Cấu hình cũ được giữ nguyên.', 'smart-login' ),
						'error'
					);
				}
			}

			unset( $input[ $fields['secret'] ], $input[ $fields['clear'] ] );
		}
	}

	/**
	 * @param array $field Registry row.
	 * @param mixed $raw   Submitted value, or null when absent.
	 * @return mixed
	 */
	private static function sanitize_field( array $field, $raw ) {
		if ( isset( $field['sanitize'] ) ) {
			return self::sanitize_special( (string) $field['sanitize'], $raw, $field );
		}

		switch ( $field['type'] ?? 'text' ) {
			case 'checkbox':
				return empty( $raw ) ? 0 : 1;

			case 'number':
				$value = (int) $raw;

				if ( isset( $field['min'] ) ) {
					$value = max( (int) $field['min'], $value );
				}

				if ( isset( $field['max'] ) ) {
					$value = min( (int) $field['max'], $value );
				}

				return $value;

			case 'select':
				$choices = array_map( 'strval', array_keys( $field['choices'] ?? array() ) );

				return in_array( (string) $raw, $choices, true ) ? (string) $raw : $field['default'];

			case 'url':
				return esc_url_raw( trim( (string) $raw ) );

			case 'email':
				return is_email( $raw ) ? sanitize_email( $raw ) : '';

			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * @param string $rule  Named sanitiser from the registry row.
	 * @param mixed  $raw   Submitted value, or null when absent.
	 * @param array  $field Registry row, for its default.
	 * @return mixed
	 */
	private static function sanitize_special( string $rule, $raw, array $field ) {
		switch ( $rule ) {
			case 'country_code':
				return preg_replace( '/[^0-9]/', '', (string) $raw ) ?: $field['default'];

			case 'domain':
				return strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $raw ) ) ?: $field['default'];

			case 'header_name':
				$header = trim( (string) $raw );

				return preg_match( '/^[A-Za-z0-9-]+$/', $header ) ? $header : '';

			case 'raw_template':
				// A JSON/form template saved verbatim by an administrator. Only the
				// control characters go, because they are what would make the
				// payload invalid; everything else is deliberate.
				return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $raw );

			case 'rich_text':
				return wp_kses_post( (string) $raw );

			case 'headers':
				return self::sanitize_headers( $raw );

			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Turn the repeatable header rows from the settings form into a clean list.
	 *
	 * @param mixed $raw Either the repeater array or a legacy newline string.
	 */
	private static function sanitize_headers( $raw ): array {
		$out = array();

		if ( is_string( $raw ) ) {
			$rows = array();

			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				if ( false === strpos( $line, ':' ) ) {
					continue;
				}

				list( $key, $value ) = explode( ':', $line, 2 );

				$rows[] = array(
					'key'   => $key,
					'value' => $value,
				);
			}

			$raw = $rows;
		}

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key   = sanitize_text_field( $row['key'] ?? '' );
			$value = sanitize_text_field( $row['value'] ?? '' );

			// Header names must not contain separators or control characters.
			$key = preg_replace( '/[^A-Za-z0-9\-_]/', '', $key );

			if ( '' === $key ) {
				continue;
			}

			$out[] = array(
				'key'   => $key,
				'value' => str_replace( array( "\r", "\n" ), '', $value ),
			);
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Dot paths
	// -----------------------------------------------------------------

	/**
	 * @param array $source
	 * @return mixed Null when the path is not present.
	 */
	private static function dig( array $source, string $path ) {
		$node = $source;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return null;
			}

			$node = $node[ $segment ];
		}

		return $node;
	}

	/**
	 * @param array  $target Array to write into, by reference.
	 * @param string $path   Dot path.
	 * @param mixed  $value
	 */
	private static function plant( array &$target, string $path, $value ): void {
		$segments = explode( '.', $path );
		$leaf     = array_pop( $segments );
		$node     = &$target;

		foreach ( $segments as $segment ) {
			if ( ! isset( $node[ $segment ] ) || ! is_array( $node[ $segment ] ) ) {
				$node[ $segment ] = array();
			}

			$node = &$node[ $segment ];
		}

		$node[ $leaf ] = $value;

		unset( $node );
	}
}
