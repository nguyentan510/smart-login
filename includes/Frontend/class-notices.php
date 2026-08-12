<?php
/**
 * In-request notice bag, plus a one-shot flash that survives a redirect.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Notices {

	const FLASH_COOKIE = 'OMNIWP_flash';

	/** @var array<int,array{type:string,message:string}> */
	private static $items = array();

	public static function add( string $message, string $type = 'error' ): void {
		if ( '' === trim( $message ) ) {
			return;
		}

		self::$items[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Add every message carried by a WP_Error.
	 */
	public static function add_wp_error( WP_Error $error, string $type = 'error' ): void {
		foreach ( $error->get_error_messages() as $message ) {
			self::add( $message, $type );
		}
	}

	public static function all(): array {
		return self::$items;
	}

	public static function has_errors(): bool {
		foreach ( self::$items as $item ) {
			if ( 'error' === $item['type'] ) {
				return true;
			}
		}

		return false;
	}

	public static function clear(): void {
		self::$items = array();
	}

	/**
	 * Queue a message to be shown after a redirect.
	 */
	public static function flash( string $message, string $type = 'success' ): void {
		if ( headers_sent() ) {
			self::add( $message, $type );
			return;
		}

		$payload = wp_json_encode(
			array(
				'type'    => $type,
				'message' => $message,
			)
		);

		$encoded = base64_encode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$value   = $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );

		setcookie(
			self::FLASH_COOKIE,
			$value,
			array(
				'expires'  => time() + 120,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Move any flashed message into the in-request bag and drop the cookie.
	 */
	public static function absorb_flash(): void {
		if ( empty( $_COOKIE[ self::FLASH_COOKIE ] ) ) {
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::FLASH_COOKIE ] ) );
		unset( $_COOKIE[ self::FLASH_COOKIE ] );

		if ( ! headers_sent() ) {
			setcookie(
				self::FLASH_COOKIE,
				'',
				array(
					'expires' => time() - DAY_IN_SECONDS,
					'path'    => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'  => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				)
			);
		}

		if ( false === strpos( $raw, '.' ) ) {
			return;
		}

		list( $encoded, $sig ) = explode( '.', $raw, 2 );

		if ( ! hash_equals( hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ), $sig ) ) {
			return;
		}

		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$data    = $decoded ? json_decode( $decoded, true ) : null;

		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			self::add( (string) $data['message'], (string) ( $data['type'] ?? 'success' ) );
		}
	}
}
