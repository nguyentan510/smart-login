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

use SmartLogin\Security\SecretBox;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'smart_login_settings';

	/** Hidden field naming the tab a save came from. */
	const TAB_FIELD = '_sl_tab';

	/**
	 * Where every `type => 'secret'` field is sealed, keyed by its registry path.
	 *
	 * ProviderCredentials had the right shape already — one option, many keys, no
	 * branch per key. This is that, applied to the registry.
	 */
	const SECRET_OPTION = 'smart_login_field_secrets';

	/**
	 * Secrets that were sealed somewhere else before 10.2 keyed them by path.
	 *
	 * A lookup rather than a branch, and a shrinking one: an entry earns its keep
	 * only until every install has re-saved that field. Without it the change of
	 * key would strand the stored value in place — readable by nothing, deletable
	 * by nothing, and silently replaced by an empty string.
	 */
	const LEGACY_SECRETS = array(
		'security.captcha_secret' => array(
			'option' => \SmartLogin\Security\Captcha::SECRET_OPTION,
			'key'    => 'captcha',
		),
	);

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
		self::absorb_secret_fields( $input );

		$fields = self::posted_fields( $input );

		foreach ( $fields as $path => $field ) {
			self::plant( $clean, $path, self::sanitize_field( $field, self::dig( $input, $path ), $path ) );
		}

		// Presets are applied after the plain fields, because they overwrite some
		// of them. Only when the tab that owns the preset was the one saved.
		if ( isset( $fields['sms.preset'] ) ) {
			self::apply_gateway_preset( $clean );
		}

		if ( isset( $fields['otp.preset'] ) ) {
			foreach ( OtpPresets::resolve( (string) self::dig( $clean, 'otp.preset' ) ) as $path => $value ) {
				self::plant( $clean, $path, $value );
			}
		}

		self::$cache = null;

		return $clean;
	}

	/**
	 * Take any `secret` field out of the settings array and into its own store.
	 *
	 * The generic form of what absorb_provider_secrets() does for Google and Zalo:
	 * a secret is encrypted at rest and never round-trips through the option
	 * array, so it can never be echoed back into a page by a field renderer that
	 * forgot it was special.
	 *
	 * Blank means "keep the stored one", because the form never renders a saved
	 * secret and blank is therefore indistinguishable from unchanged. Removing one
	 * takes the explicit clear checkbox.
	 */
	private static function absorb_secret_fields( array &$input ): void {
		foreach ( FieldRegistry::all() as $path => $field ) {
			if ( 'secret' !== ( $field['type'] ?? '' ) ) {
				continue;
			}

			$submitted = (string) ( self::dig( $input, $path ) ?? '' );

			// phpcs:ignore WordPress.Security.NonceVerification -- register_setting() has already verified.
			$clear = ! empty( $_POST[ 'sl_clear_' . str_replace( '.', '_', $path ) ] );

			if ( $clear ) {
				self::store_secret( $path, '' );
			} elseif ( '' !== trim( $submitted ) ) {
				self::store_secret( $path, trim( $submitted ) );
			}

			// Never let the plaintext reach the stored option, whatever happened.
			self::prune( $input, $path );
		}
	}

	/**
	 * Seal a secret under its registry path, or erase it when blank.
	 *
	 * This used to match one path literal and do nothing for any other, while
	 * absorb_secret_fields() pruned the plaintext from the option array either
	 * way — so a `secret` field whose path nobody remembered to add here was a
	 * control that accepted input and discarded it without a word.
	 */
	public static function store_secret( string $path, string $secret ): void {
		if ( '' === $secret ) {
			SecretBox::forget( self::SECRET_OPTION, $path );
		} else {
			SecretBox::put( self::SECRET_OPTION, $path, $secret );
		}

		// Either way the pre-10.2 copy goes. Leaving it behind on a clear would
		// resurrect the secret the administrator just deleted, because the read
		// below falls back to exactly that location.
		self::forget_legacy_secret( $path );
	}

	/**
	 * Read a sealed secret by registry path, wherever it currently lives.
	 */
	public static function read_secret( string $path ): string {
		$secret = SecretBox::get( self::SECRET_OPTION, $path );

		if ( '' !== $secret ) {
			return $secret;
		}

		$legacy = self::LEGACY_SECRETS[ $path ] ?? null;

		return $legacy ? SecretBox::get( $legacy['option'], $legacy['key'] ) : '';
	}

	private static function forget_legacy_secret( string $path ): void {
		$legacy = self::LEGACY_SECRETS[ $path ] ?? null;

		if ( $legacy ) {
			SecretBox::forget( $legacy['option'], $legacy['key'] );
		}
	}

	/**
	 * Remove a dot path from a nested array.
	 */
	private static function prune( array &$target, string $path ): void {
		$parts  = explode( '.', $path );
		$last   = array_pop( $parts );
		$cursor = &$target;

		foreach ( $parts as $part ) {
			if ( ! isset( $cursor[ $part ] ) || ! is_array( $cursor[ $part ] ) ) {
				return;
			}

			$cursor = &$cursor[ $part ];
		}

		unset( $cursor[ $last ] );
	}

	/**
	 * Derive the webhook transport settings from the chosen gateway.
	 *
	 * The administrator fills in credentials; URL, body, headers and the success
	 * condition come from GatewayPresets. Choosing "Tuỳ chỉnh" derives nothing,
	 * so a hand-written configuration is never overwritten — which is the rule
	 * that makes the whole arrangement predictable.
	 *
	 * @param array $clean Settings array being assembled, by reference.
	 */
	private static function apply_gateway_preset( array &$clean ): void {
		$slug        = (string) self::dig( $clean, 'sms.preset' );
		$credentials = (array) self::dig( $clean, 'sms.credentials' );
		$stored      = (array) self::dig( self::all(), 'sms.credentials' );

		// "Tuỳ chỉnh" draws no credential inputs, so the save carries none. That
		// is not a reason to erase the ones already stored — switching to custom
		// and back would otherwise cost the administrator their gateway keys.
		if ( GatewayPresets::is_custom( $slug ) ) {
			self::plant( $clean, 'sms.credentials', $stored );
			return;
		}

		// A blank secret means "keep the stored one" — the form never echoes a
		// saved secret back, so blank cannot be distinguished from unchanged any
		// other way. Non-secret credentials are visible on screen, so a blank
		// there really is a deletion.
		foreach ( GatewayPresets::credentials( $slug ) as $name => $spec ) {
			if ( empty( $spec['secret'] ) ) {
				continue;
			}

			if ( '' === (string) ( $credentials[ $name ] ?? '' ) ) {
				$credentials[ $name ] = (string) ( $stored[ $name ] ?? '' );
			}
		}

		self::plant( $clean, 'sms.credentials', $credentials );

		foreach ( GatewayPresets::resolve( $slug, $credentials ) as $path => $value ) {
			self::plant( $clean, $path, $value );
		}
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
	private static function sanitize_field( array $field, $raw, string $path = '' ) {
		if ( isset( $field['sanitize'] ) ) {
			return self::sanitize_special( (string) $field['sanitize'], $raw, $field, $path );
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
	private static function sanitize_special( string $rule, $raw, array $field, string $path = '' ) {
		switch ( $rule ) {
			case 'https_url':
				return self::sanitize_https_url( $raw, $path );

			case 'audit_events':
				// Intersected with the constants, so a stale stored name cannot
				// keep being looked for after the event it named is gone. The
				// empty strings come from the hidden input that makes "none
				// ticked" expressible at all.
				return array_values(
					array_intersect(
						array_filter( array_map( 'strval', (array) $raw ) ),
						\SmartLogin\Security\AuditLog::events()
					)
				);

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

			case 'credentials':
				return self::sanitize_credentials( $raw );

			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * An endpoint that will carry a live OTP, so plaintext HTTP is refused.
	 *
	 * Refused at save rather than at send: saving is the only moment the
	 * administrator is present to be told why. A send-time check would surface as
	 * users not receiving codes, days later, with the cause three screens away.
	 *
	 * The rejected value does **not** blank the field. Clearing an endpoint
	 * because someone mistyped the scheme would leave a channel routed at nothing
	 * — a worse outcome than the typo, and a silent one.
	 *
	 * @param mixed  $raw
	 * @param string $path Registry path, used to recover the stored value.
	 */
	private static function sanitize_https_url( $raw, string $path ): string {
		$url = esc_url_raw( trim( (string) $raw ) );

		if ( '' === $url || 0 === stripos( $url, 'https://' ) ) {
			return $url;
		}

		// A local n8n has no certificate, and refusing http there makes the
		// feature untestable before it is deployed. Written down here and in the
		// help text rather than left as a surprise.
		if ( self::is_local_environment() && 0 === stripos( $url, 'http://' ) ) {
			return $url;
		}

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'smart_login_https_required',
				__( 'Endpoint phải dùng https://. Mã xác thực đi qua địa chỉ này nên không chấp nhận HTTP thường. Giá trị cũ được giữ nguyên.', 'smart-login' ),
				'error'
			);
		}

		return '' !== $path ? (string) self::get( $path, '' ) : '';
	}

	private static function is_local_environment(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false;
		}

		return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Gateway credentials: a flat name => value map, names constrained to the
	 * shape `{{cred:name}}` accepts so a stored key can never fail to substitute.
	 *
	 * @param mixed $raw
	 */
	private static function sanitize_credentials( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $name => $value ) {
			$name = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) );

			if ( '' === $name || is_array( $value ) ) {
				continue;
			}

			$out[ $name ] = sanitize_text_field( (string) $value );
		}

		return $out;
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
