<?php
/**
 * Settings schema, defaults and typed accessors.
 *
 * @package SmartLogin
 */

namespace SmartLogin;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'smart_login_settings';

	/** @var array|null Runtime cache. */
	private static $cache = null;

	/**
	 * Every setting the plugin understands, with its default value.
	 * Anything not listed here is dropped on save.
	 */
	public static function defaults(): array {
		return array(
			// --- Tab: Chung ---
			'id_mode'                      => 'phone_only', // phone_only | email_only | both.
			'default_country_code'         => '84',
			'synthetic_email_domain'       => 'phone.invalid',
			// `require_verification` used to live here. Nothing ever read it —
			// every flow verifies unconditionally — so it was a switch wired to
			// nothing, and one that quietly zeroed itself on save.
			'min_password_length'          => 8,
			'field_dob'                    => 1,
			'field_gender'                 => 1,
			'field_referral'               => 1,
			'field_email_optional'         => 1,
			'redirect_after_register'      => '',
			'redirect_after_login'         => '',
			'terms_url'                    => '',
			'google_enabled'               => 0,
			'google_client_id'             => '',
			'zalo_enabled'                 => 0,
			'zalo_app_id'                  => '',
			'provider_auto_link_email'     => 1,

			// --- Tab: OTP ---
			'otp_length'                   => 6,
			'otp_ttl'                      => 300,
			'otp_max_attempts'             => 5,
			'otp_resend_cooldown'          => 60,
			'otp_max_per_destination_hour' => 5,
			'otp_max_per_ip_hour'          => 10,
			'login_otp_new_device'         => 0,
			'login_max_attempts'           => 5,
			'login_lockout_minutes'        => 15,

			// --- Tab: Push OTP (webhook) ---
			'webhook_enabled'              => 0,
			'webhook_url'                  => '',
			'webhook_method'               => 'POST',
			'webhook_content_type'         => 'application/json',
			'webhook_headers'              => array(),
			'webhook_body'                 => '{"phone":"{{phone_local}}","content":"{{code}} la ma xac thuc cua ban tai {{site_name}}. Ma co hieu luc {{ttl_minutes}} phut."}',
			'webhook_timeout'              => 10,
			'webhook_success_path'         => '',
			'webhook_success_value'        => '',
			'webhook_retry'                => 0,
			'webhook_idempotency_header'   => '',

			// --- Tab: Email ---
			'email_enabled'                => 1,
			'email_from_name'              => '',
			'email_from_address'           => '',
			'email_subject'                => 'Mã xác thực {{code}} - {{site_name}}',
			'email_body'                   => "Xin chào,\n\nMã xác thực của bạn là: {{code}}\nMã có hiệu lực trong {{ttl_minutes}} phút.\n\nNếu bạn không yêu cầu mã này, vui lòng bỏ qua email.\n\n{{site_name}}",
			'email_is_html'                => 0,

			// --- Tab: Nâng cao ---
			'audit_enabled'                => 1,
			'audit_retention_days'         => 90,
			'otp_retention_days'           => 7,
			'delete_data_on_uninstall'     => 0,
			'dev_mode'                     => 0,

			// --- Địa chỉ ---
			'address_enabled'              => 1,
			'address_quick_search'         => 1,
			'address_hide_postcode'        => 0,
			'address_required_in_profile'  => 0,

			// --- WooCommerce ---
			'woo_replace_login_form'       => 1,
			'woo_relax_billing_email'      => 0,
			'woo_block_synthetic_emails'   => 1,
			'woo_sync_billing_phone'       => 1,
		);
	}

	/**
	 * Fields that are integers/booleans, for sanitising on save.
	 */
	public static function int_fields(): array {
		return array(
			'min_password_length',
			'field_dob',
			'field_gender',
			'field_referral',
			'field_email_optional',
			'google_enabled',
			'zalo_enabled',
			'provider_auto_link_email',
			'otp_length',
			'otp_ttl',
			'otp_max_attempts',
			'otp_resend_cooldown',
			'otp_max_per_destination_hour',
			'otp_max_per_ip_hour',
			'login_otp_new_device',
			'login_max_attempts',
			'login_lockout_minutes',
			'webhook_enabled',
			'webhook_timeout',
			'webhook_retry',
			'email_enabled',
			'email_is_html',
			'audit_enabled',
			'audit_retention_days',
			'otp_retention_days',
			'delete_data_on_uninstall',
			'dev_mode',
			'address_enabled',
			'address_quick_search',
			'address_hide_postcode',
			'address_required_in_profile',
			'woo_replace_login_form',
			'woo_relax_billing_email',
			'woo_block_synthetic_emails',
			'woo_sync_billing_phone',
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value to use when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		$value = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;

		/**
		 * Filter a single setting value at read time.
		 *
		 * @param mixed  $value
		 * @param string $key
		 */
		return apply_filters( 'smart_login_setting', $value, $key );
	}

	/**
	 * @param string $key      Setting key.
	 * @param int    $fallback Value to use when the key is unknown.
	 */
	public static function get_int( string $key, int $fallback = 0 ): int {
		return (int) self::get( $key, $fallback );
	}

	public static function is_on( string $key ): bool {
		return (bool) self::get_int( $key );
	}

	public static function update( array $values ): void {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION, $merged );
		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Whether phone can be used as an identifier.
	 */
	public static function phone_enabled(): bool {
		return in_array( self::get( 'id_mode' ), array( 'phone_only', 'both' ), true );
	}

	/**
	 * Whether email can be used as an identifier.
	 */
	public static function email_enabled(): bool {
		return in_array( self::get( 'id_mode' ), array( 'email_only', 'both' ), true );
	}

	/**
	 * Strip unknown keys and coerce types. Used by the settings page.
	 */
	public static function sanitize( array $input ): array {
		foreach (
			array(
				'google' => array(
					'secret' => 'google_client_secret',
					'clear'  => 'google_clear_secret',
				),
				'zalo'   => array(
					'secret' => 'zalo_app_secret',
					'clear'  => 'zalo_clear_secret',
				),
			) as $provider => $fields
		) {
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

		$defaults = self::defaults();
		$ints     = self::int_fields();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				// Unchecked checkboxes never appear in $_POST.
				$clean[ $key ] = in_array( $key, $ints, true ) && is_int( $default ) ? 0 : $default;
				continue;
			}

			$value = $input[ $key ];

			if ( in_array( $key, $ints, true ) ) {
				$clean[ $key ] = max( 0, (int) $value );
				continue;
			}

			switch ( $key ) {
				case 'id_mode':
					$clean[ $key ] = in_array( $value, array( 'phone_only', 'email_only', 'both' ), true ) ? $value : 'phone_only';
					break;

				case 'webhook_method':
					$clean[ $key ] = in_array( strtoupper( (string) $value ), array( 'POST', 'GET' ), true ) ? strtoupper( (string) $value ) : 'POST';
					break;

				case 'webhook_content_type':
					$clean[ $key ] = in_array( $value, array( 'application/json', 'application/x-www-form-urlencoded' ), true ) ? $value : 'application/json';
					break;

				case 'webhook_url':
					$clean[ $key ] = esc_url_raw( trim( (string) $value ) );
					break;

				case 'webhook_idempotency_header':
					$header        = trim( (string) $value );
					$clean[ $key ] = preg_match( '/^[A-Za-z0-9-]+$/', $header ) ? $header : '';
					break;

				case 'redirect_after_register':
				case 'redirect_after_login':
				case 'terms_url':
					$clean[ $key ] = esc_url_raw( trim( (string) $value ) );
					break;

				case 'default_country_code':
					$clean[ $key ] = preg_replace( '/[^0-9]/', '', (string) $value ) ?: '84';
					break;

				case 'synthetic_email_domain':
					$clean[ $key ] = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $value ) ) ?: 'phone.invalid';
					break;

				case 'email_from_address':
					$clean[ $key ] = is_email( $value ) ? sanitize_email( $value ) : '';
					break;

				case 'webhook_headers':
					$clean[ $key ] = self::sanitize_headers( $value );
					break;

				case 'webhook_body':
					// Raw JSON/form template. Only admins can save it; keep it verbatim
					// minus control characters so the payload stays valid JSON.
					$clean[ $key ] = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value );
					break;

				case 'email_body':
					$clean[ $key ] = wp_kses_post( (string) $value );
					break;

				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		// Guard rails so a typo cannot brick the flow.
		$clean['otp_length']          = min( 8, max( 4, (int) $clean['otp_length'] ) );
		$clean['otp_ttl']             = min( 3600, max( 60, (int) $clean['otp_ttl'] ) );
		$clean['otp_max_attempts']    = min( 10, max( 1, (int) $clean['otp_max_attempts'] ) );
		$clean['otp_resend_cooldown'] = min( 600, max( 15, (int) $clean['otp_resend_cooldown'] ) );
		$clean['min_password_length'] = min( 64, max( 6, (int) $clean['min_password_length'] ) );
		$clean['webhook_timeout']     = min( 30, max( 3, (int) $clean['webhook_timeout'] ) );

		self::$cache = null;

		return $clean;
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
				list( $k, $v ) = explode( ':', $line, 2 );
				$rows[]        = array(
					'key'   => $k,
					'value' => $v,
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
			$key = sanitize_text_field( $row['key'] ?? '' );
			$val = sanitize_text_field( $row['value'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			// Header names must not contain separators/control chars.
			$key = preg_replace( '/[^A-Za-z0-9\-_]/', '', $key );
			if ( '' === $key ) {
				continue;
			}

			$out[] = array(
				'key'   => $key,
				'value' => str_replace( array( "\r", "\n" ), '', $val ),
			);
		}

		return $out;
	}
}
