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
 * @package OmniWP
 */

namespace OmniWP;

use OmniWP\Security\SecretBox;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'OMNIWP_settings';

	/** Hidden field naming the tab a save came from. */
	const TAB_FIELD = '_ow_tab';

	/**
	 * Where every `type => 'secret'` field is sealed, keyed by its registry path.
	 *
	 * ProviderCredentials had the right shape already — one option, many keys, no
	 * branch per key. This is that, applied to the registry.
	 */
	const SECRET_OPTION = 'OMNIWP_field_secrets';

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
		return apply_filters( 'OMNIWP_setting', $value, $path );
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

		// Read before the posted values are planted over it: apply_otp_preset() needs
		// to know whether the administrator changed the profile in this save or was
		// editing the values underneath it.
		$stored_otp_preset = (string) self::dig( $clean, 'otp.preset' );

		self::absorb_provider_secrets( $input );
		self::absorb_secret_fields( $input );

		$fields = self::posted_fields( $input );

		/*
		 * No tab named means this is not the first time WordPress has applied us.
		 *
		 * `update_option()` sanitises, and then — when the stored value equals the value
		 * registered as the setting's default — routes the write through `add_option()`,
		 * which sanitises **again** (wp-includes/option.php:884 and :1111). The second
		 * pass receives the first pass's output, and that output is registry-shaped, so it
		 * carries no `_ow_tab`.
		 *
		 * This used to fall through with an empty field list, leave `$clean` at the stored
		 * values, and hand back exactly what was already in the database — discarding
		 * everything the first pass had just done. Captured from a real save: pass one
		 * turned `identity.mode` into `both`, pass two turned it back into `phone_only`,
		 * and WordPress still said "Settings saved."
		 *
		 * The trigger is "stored == registered default", which is the state a freshly
		 * activated site is in. So this was invisible on any site that had ever saved
		 * anything, and certain on one that had not.
		 *
		 * Falling back to every declared field makes the filter idempotent: the second
		 * pass re-sanitises the first pass's own values and arrives at the same answer.
		 * A field absent from the input still keeps what is stored, which is the rule the
		 * loop below already applies.
		 */
		$tab_named = (bool) $fields;

		if ( ! $tab_named ) {
			$fields = FieldRegistry::all();
		}

		foreach ( $fields as $path => $field ) {
			/*
			 * A field the post does not carry keeps what is stored.
			 *
			 * It used to be sanitised anyway, and `null` sanitises to zero — which a
			 * number field then clamps up to its minimum. A save that omitted one input
			 * wrote `otp.ttl` 300 → 60 and `otp.length` 6 → 4 without a word. It hid
			 * because a browser posts every input it draws, and because re-applying the
			 * OTP profile happened to overwrite the six fields where it would have shown.
			 * Removing that overwrite is what exposed it.
			 *
			 * A checkbox is the exception and the reason this cannot simply skip absent
			 * fields: browsers post nothing for an unchecked box, so absence *is* the
			 * value there.
			 *
			 * That exception holds only when a tab was named, because only then is every
			 * field known to have been rendered. In the tabless fallback above nothing was
			 * rendered, so an absent checkbox means "not carried" rather than "unchecked" —
			 * and treating it as unchecked zeroed every checkbox on the site. Caught by the
			 * regression suite's "disturbs nothing it does not carry", which is the
			 * assertion that replaced the letter this defect was hiding behind.
			 */
			$raw = self::dig( $input, $path );

			$can_be_empty_on_tab = in_array( $field['type'] ?? '', array( 'checkbox', 'menu_items', 'headers' ), true );

			if ( null === $raw && ( ! $tab_named || ! $can_be_empty_on_tab ) ) {
				continue;
			}

			self::plant( $clean, $path, self::sanitize_field( $field, $raw, $path ) );
		}

		/*
		 * Presets are applied after the plain fields, because they overwrite some of
		 * them — and only when the tab that owns the preset was genuinely the one saved.
		 *
		 * `$tab_named` is load-bearing here. Without it the tabless fallback above put
		 * every field in `$fields`, which made both presets look posted on WordPress's
		 * second sanitise pass and rederived `sms.url`, `sms.body` and `otp.preset` from
		 * values nobody had submitted. Caught by the regression suite's "disturbs nothing
		 * it does not carry" — the second thing that assertion found in one sitting.
		 */
		if ( $tab_named && isset( $fields['sms.preset'] ) ) {
			self::apply_gateway_preset( $clean );
		}

		if ( $tab_named && isset( $fields['otp.preset'] ) ) {
			self::apply_otp_preset( $clean, $stored_otp_preset );
		}

		self::$cache = null;

		return $clean;
	}

	/**
	 * Take any `secret` field out of the settings array and into its own store.
	 *
	 * The generic form of what absorb_provider_secrets() does for each provider:
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
			$clear = ! empty( $_POST[ 'ow_clear_' . str_replace( '.', '_', $path ) ] );

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
	}

	/**
	 * Read a sealed secret by registry path.
	 *
	 * One location. Until 15.3 this fell back to where the captcha secret lived
	 * before 10.2 moved it, and clearing had to reach that copy too or the secret
	 * came back on the next read — a defect 10.2's own Outcome records. A wiped
	 * database has no earlier location to fall back to, so the fallback and the
	 * clear-the-old-copy dance go together.
	 */
	public static function read_secret( string $path ): string {
		return SecretBox::get( self::SECRET_OPTION, $path );
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
	 * Apply the OTP profile, or step aside when the administrator is editing values.
	 *
	 * `otp.preset` shares the `delivery` tab with the six fields it owns, and both are
	 * editable there. Re-applying the profile on every save of that tab meant every
	 * value typed into those inputs was discarded between the click and the reload —
	 * reported from a real screen as "fill it in, press save, everything comes back".
	 * `otp.ttl` 300 → 301 came back 300.
	 *
	 * So the profile is applied when it **changed** in this save, which is the moment
	 * choosing one is meant to mean something. Otherwise the posted values stand, and
	 * the profile becomes `custom` if any of them no longer matches what it prescribes
	 * — because a screen still reading "Cân bằng" over values that are not balanced is
	 * the same lie in a smaller font.
	 *
	 * `sms.preset` does not have this problem and is left alone: its profile owns the
	 * credential inputs, and those are redrawn per profile rather than edited beneath
	 * it.
	 *
	 * @param array  $clean  Settings array being assembled, by reference.
	 * @param string $stored The profile before this save.
	 */
	private static function apply_otp_preset( array &$clean, string $stored ): void {
		$chosen = (string) self::dig( $clean, 'otp.preset' );

		if ( $chosen !== $stored ) {
			foreach ( OtpPresets::resolve( $chosen ) as $path => $value ) {
				self::plant( $clean, $path, $value );
			}

			return;
		}

		foreach ( OtpPresets::resolve( $chosen ) as $path => $value ) {
			// Loose comparison on purpose: the form posts strings and the profile
			// declares integers, so 300 and '300' are the same answer here.
			if ( self::dig( $clean, $path ) != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons, Universal.Operators.StrictComparisons.LooseNotEqual
				self::plant( $clean, 'otp.preset', OtpPresets::CUSTOM );

				return;
			}
		}
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
				'id'     => 'providers.google.client_id',
			),
		);

		foreach ( $providers as $provider => $fields ) {
			$submitted = trim( (string) ( $input[ $fields['secret'] ] ?? '' ) );

			if ( ! empty( $input[ $fields['clear'] ] ) ) {
				\OmniWP\Auth\Providers\ProviderCredentials::clear_secret( $provider );
			} elseif ( '' !== $submitted && self::secret_is_the_id( $provider, $fields['id'], $submitted, $input ) ) {
				/*
				 * Refused rather than stored, and the stored one is left alone.
				 *
				 * A provider's public id and its secret sit next to each other on
				 * the provider's own dashboard, are the same shape, and one in the
				 * other's box is accepted by everything until a visitor presses the
				 * button — at which point the provider answers "invalid secret key"
				 * and the site owner has a failing sign-in and no idea why. This is
				 * the only place both values are in one hand.
				 */
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error(
						self::OPTION,
						'OMNIWP_provider_secret',
						__( 'Secret trùng với ID ứng dụng nên không được lưu. Hãy dán đúng App Secret Key từ trang quản lý ứng dụng — nó là một giá trị khác với App ID.', 'omniwp' ),
						'error'
					);
				}
			} elseif ( '' !== $submitted ) {
				$stored = \OmniWP\Auth\Providers\ProviderCredentials::store_secret(
					$provider,
					(string) $input[ $fields['secret'] ]
				);

				if ( ! $stored && function_exists( 'add_settings_error' ) ) {
					add_settings_error(
						self::OPTION,
						'OMNIWP_provider_secret',
						__( 'Không thể mã hóa secret của provider. Cấu hình cũ được giữ nguyên.', 'omniwp' ),
						'error'
					);
				}
			}

			unset( $input[ $fields['secret'] ], $input[ $fields['clear'] ] );
		}
	}

	/**
	 * Is this "secret" the provider's public id wearing the wrong label?
	 *
	 * The id submitted in the same save wins over the stored one, because an
	 * administrator filling both boxes at once has no stored id to be compared
	 * against yet — and that is the save where the mistake is easiest to make.
	 *
	 * @param string $provider Provider slug.
	 * @param string $id_path  Registry path holding that provider's public id.
	 * @param string $secret   The secret just submitted.
	 * @param array  $input    Raw submitted values.
	 */
	private static function secret_is_the_id( string $provider, string $id_path, string $secret, array $input ): bool {
		$submitted_id = trim( (string) ( self::dig( $input, $id_path ) ?? '' ) );
		$id           = '' !== $submitted_id
			? $submitted_id
			: trim( \OmniWP\Auth\Providers\ProviderCredentials::client_id( $provider ) );

		return '' !== $id && hash_equals( $id, $secret );
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
						\OmniWP\Security\AuditLog::events()
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

			case 'menu_items':
				return self::sanitize_menu_items( $raw );

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
				'OMNIWP_https_required',
				__( 'Endpoint phải dùng https://. Mã xác thực đi qua địa chỉ này nên không chấp nhận HTTP thường. Giá trị cũ được giữ nguyên.', 'omniwp' ),
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

	/**
	 * The repeatable account-menu rows from the settings form.
	 *
	 * Shaped like sanitize_headers() above, which is the point: the repeater is
	 * a proven control with three columns instead of two, and this phase does
	 * not invent settings machinery.
	 *
	 * A row is dropped when it has no label or no URL, silently, the way a blank
	 * header row is — an administrator leaving a spare row empty is the normal
	 * case, not an error.
	 *
	 * **The key is derived, never typed.** Nobody should have to invent a slug to
	 * add a menu item, but every entry still needs a stable identifier: later
	 * surfaces compare keys to decide which item is active, and a filter has to
	 * be able to name an item whose label an administrator has since rewritten.
	 * So it comes from the label, and then three things have to be true of it:
	 *
	 *   - it matches [a-z0-9_-]+, and when nothing derivable is left it falls
	 *     back to the row's position. **A row is never dropped for an
	 *     underivable key.** `sanitize_title()` folds Vietnamese accents to
	 *     ASCII, so "Đơn hàng" gives `don-hang` — but a label written in a script
	 *     it cannot transliterate would reduce to nothing, and losing a menu item
	 *     because of the alphabet its label is in is not a defensible refusal
	 *   - it is not `account` or `logout` — those belong to the pinned ends, and
	 *     a row labelled "Đăng xuất" taking the tail's key would make two entries
	 *     answer to the same name
	 *   - it is unique within the table, by numeric suffix
	 *
	 * @param mixed $raw
	 * @return array<int,array{key:string,label:string,icon:string,url:string}>
	 */
	private static function sanitize_menu_items( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$reserved = array(
			\OmniWP\Frontend\AccountMenu::KEY_ACCOUNT,
			\OmniWP\Frontend\AccountMenu::KEY_LOGOUT,
		);

		$out  = array();
		$seen = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$url   = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) );

			if ( '' === $label || '' === $url ) {
				continue;
			}

			$key = sanitize_title( $label );
			$key = (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );

			if ( '' === $key ) {
				$key = 'item-' . ( count( $out ) + 1 );
			}

			if ( in_array( $key, $reserved, true ) ) {
				$key .= '-2';
			}

			if ( isset( $seen[ $key ] ) ) {
				++$seen[ $key ];
				$key .= '-' . $seen[ $key ];
			} else {
				$seen[ $key ] = 1;
			}

			$out[] = array(
				'key'   => $key,
				'label' => $label,
				// Through IconSet, so a name outside the set becomes the
				// fallback here rather than being stored and folded on every
				// render. An icon that is not in the set cannot be written down.
				'icon'  => \OmniWP\Frontend\IconSet::sanitize( (string) ( $row['icon'] ?? '' ) ),
				'url'   => $url,
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
