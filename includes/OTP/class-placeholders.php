<?php
/**
 * Template placeholder expansion shared by every OTP channel.
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP;

use SmartLogin\Identity\Phone;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class Placeholders {

	/**
	 * Build the replacement map for one OTP delivery.
	 *
	 * @param string $destination Canonical phone digits or email address.
	 * @param string $code        The plaintext code (in memory only).
	 * @param array  $ctx         intent, transport, expires_at, user_name…
	 */
	public static function build( string $destination, string $code, array $ctx ): array {
		$ttl        = (int) ( $ctx['ttl_seconds'] ?? Settings::get_int( 'otp.ttl', 300 ) );
		$is_email   = ( false !== strpos( $destination, '@' ) );
		$expires_ts = (int) ( $ctx['expires_ts'] ?? ( time() + $ttl ) );

		$map = array(
			'destination' => $destination,
			'phone'       => $is_email ? '' : $destination,
			'phone_local' => $is_email ? '' : Phone::to_local( $destination ),
			'phone_plus'  => $is_email ? '' : '+' . $destination,
			'email'       => $is_email ? $destination : '',
			'code'        => $code,
			'intent'      => (string) ( $ctx['intent'] ?? '' ),
			'transport'   => (string) ( $ctx['transport'] ?? '' ),
			'ttl_seconds' => (string) $ttl,
			'ttl_minutes' => (string) max( 1, (int) round( $ttl / 60 ) ),
			'expires_at'  => wp_date( 'H:i d/m/Y', $expires_ts ),
			'site_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'    => home_url( '/' ),
			'user_name'   => (string) ( $ctx['user_name'] ?? '' ),
			'delivery_id' => (string) ( $ctx['delivery_id'] ?? '' ),
		);

		/**
		 * Add or override placeholders available to OTP templates.
		 *
		 * @param array  $map
		 * @param string $destination
		 * @param array  $ctx
		 */
		return (array) apply_filters( 'smart_login_otp_placeholders', $map, $destination, $ctx );
	}

	/**
	 * Replace {{tokens}} in a template.
	 *
	 * @param string   $template Template text.
	 * @param array    $map      From build().
	 * @param callable $escape   Per-value escaper (JSON string escaping, urlencode…).
	 */
	public static function render( string $template, array $map, ?callable $escape = null ): string {
		$search  = array();
		$replace = array();

		foreach ( $map as $key => $value ) {
			$value     = (string) $value;
			$search[]  = '{{' . $key . '}}';
			$replace[] = $escape ? (string) $escape( $value ) : $value;
		}

		return str_replace( $search, $replace, $template );
	}

	/**
	 * Escape a value for safe interpolation inside a JSON string literal.
	 */
	public static function json_escape( string $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );

		// Strip the surrounding quotes json_encode adds.
		return is_string( $encoded ) ? substr( $encoded, 1, -1 ) : '';
	}

	/**
	 * The token list shown as help text on the settings screen.
	 *
	 * With a message id, only the tokens that message declares. Showing the full
	 * list beside every template is how an administrator pastes `{{ip}}` into an
	 * OTP mail and receives a silent empty string — the failure Phase 11 exists
	 * to prevent, and the one this project has met five times through renames.
	 *
	 * Without an id it still returns everything, because the SMS section
	 * legitimately shows the whole set.
	 *
	 * @param string $message_id Optional MailRegistry row id.
	 */
	public static function available_tokens( string $message_id = '' ): array {
		$all = self::token_table();

		if ( '' === $message_id ) {
			// The SMS section asks with no id and must keep getting exactly the
			// list it always had. The operational tokens below are reachable only
			// through the message that declares them — offering {{ceiling}} beside
			// an SMS body would be the same defect this scoping exists to remove,
			// pointed the other way.
			return array_intersect_key(
				$all,
				array_flip( self::braced( \SmartLogin\Mail\MailRegistry::OTP_TOKENS ) )
			);
		}

		$allowed = \SmartLogin\Mail\MailRegistry::tokens( $message_id );
		$scoped  = array();

		foreach ( $allowed as $token ) {
			$key = '{{' . $token . '}}';

			if ( isset( $all[ $key ] ) ) {
				$scoped[ $key ] = $all[ $key ];
			}
		}

		return $scoped;
	}

	/** @param string[] $tokens @return string[] */
	private static function braced( array $tokens ): array {
		return array_map(
			static fn( string $token ): string => '{{' . $token . '}}',
			$tokens
		);
	}

	/**
	 * Every token the plugin knows how to expand, with its description.
	 *
	 * One table for every message, filtered per message on the way out. Two
	 * tables would be two places to describe `{{site_name}}`.
	 */
	private static function token_table(): array {
		return array(
			'{{destination}}'  => __( 'Số điện thoại hoặc email nhận mã', 'smart-login' ),
			'{{phone}}'        => __( 'SĐT dạng E.164 không dấu cộng — 84969789475', 'smart-login' ),
			'{{phone_local}}'  => __( 'SĐT dạng nội địa — 0969789475', 'smart-login' ),
			'{{phone_plus}}'   => __( 'SĐT dạng quốc tế — +84969789475', 'smart-login' ),
			'{{email}}'        => __( 'Email nhận mã (rỗng nếu gửi SMS)', 'smart-login' ),
			'{{code}}'         => __( 'Mã OTP', 'smart-login' ),
			'{{intent}}'       => __( 'Mục đích: register / login / recover / add_identity', 'smart-login' ),
			'{{transport}}'    => __( 'Kênh gửi: sms / email', 'smart-login' ),
			'{{ttl_seconds}}'  => __( 'Thời gian hiệu lực tính bằng giây', 'smart-login' ),
			'{{ttl_minutes}}'  => __( 'Thời gian hiệu lực tính bằng phút', 'smart-login' ),
			'{{expires_at}}'   => __( 'Thời điểm hết hạn', 'smart-login' ),
			'{{site_name}}'    => __( 'Tên website', 'smart-login' ),
			'{{site_url}}'     => __( 'Địa chỉ website', 'smart-login' ),
			'{{user_name}}'    => __( 'Họ tên người dùng (nếu có)', 'smart-login' ),
			'{{delivery_id}}'  => __( 'Mã giao nhận ổn định giữa các lần retry', 'smart-login' ),

			// Operational alerts only. Never offered beside an OTP template,
			// because there they would always render empty.
			'{{ceiling}}'      => __( 'Trần vừa bị chạm', 'smart-login' ),
			'{{window}}'       => __( 'Khoảng thời gian của trần: giờ hoặc ngày', 'smart-login' ),
			'{{halt_minutes}}' => __( 'Số phút tạm dừng gửi', 'smart-login' ),
			'{{cooldown}}'     => __( 'Số giây trước khi thử lại kênh gửi', 'smart-login' ),
		);
	}
}
