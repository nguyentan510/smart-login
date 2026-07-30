<?php
/**
 * Minimal WordPress stubs so the pure-logic classes can be exercised under
 * plain PHP CLI, without a WordPress install.
 *
 * Only functions the tested classes actually call are defined here. Anything
 * missing will fail loudly rather than silently returning null.
 *
 * @package SmartLogin
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SMART_LOGIN_VERSION', '1.0.1' );
define( 'SMART_LOGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'SMART_LOGIN_BASENAME', 'smart-login/smart-login.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['sl_options'] = array();
$GLOBALS['sl_transients'] = array();
$GLOBALS['sl_transient_delete_fail'] = false;
$GLOBALS['sl_http_requests'] = array();

function get_option( $name, $default = false ) {
	return $GLOBALS['sl_options'][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	$GLOBALS['sl_options'][ $name ] = $value;
	return true;
}

function set_transient( $name, $value, $expiration ) {
	$GLOBALS['sl_transients'][ $name ] = $value;
	return true;
}

function get_transient( $name ) {
	return $GLOBALS['sl_transients'][ $name ] ?? false;
}

function delete_transient( $name ) {
	if ( $GLOBALS['sl_transient_delete_fail'] ) {
		return false;
	}

	if ( ! array_key_exists( $name, $GLOBALS['sl_transients'] ) ) {
		return false;
	}

	unset( $GLOBALS['sl_transients'][ $name ] );
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function do_action( $hook ) {}

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_kses_post( $text ) {
	return (string) $text;
}

function esc_url_raw( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}

function wp_validate_redirect( $location, $fallback = '' ) {
	$location = (string) $location;
	if ( '' === $location ) {
		return $fallback;
	}
	$host = parse_url( $location, PHP_URL_HOST );
	return null === $host || 'example.test' === $host ? $location : $fallback;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_email( $value ) {
	return filter_var( (string) $value, FILTER_SANITIZE_EMAIL );
}

function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp ?? time() );
}

function get_bloginfo( $key ) {
	return 'Cửa hàng Demo';
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function add_query_arg( $key, $value = null, $url = false ) {
	$args = is_array( $key ) ? $key : array( $key => $value );
	$url  = false === $url ? 'https://example.test/' : (string) $url;
	$fragment = '';
	if ( false !== strpos( $url, '#' ) ) {
		list( $url, $fragment ) = explode( '#', $url, 2 );
		$fragment = '#' . $fragment;
	}
	$parts = parse_url( $url );
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	foreach ( $args as $name => $item ) {
		if ( false === $item ) {
			unset( $query[ $name ] );
		} else {
			$query[ $name ] = $item;
		}
	}
	$base = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'example.test' );
	if ( isset( $parts['port'] ) ) {
		$base .= ':' . $parts['port'];
	}
	$base .= $parts['path'] ?? '/';
	return $base . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' ) . $fragment;
}

function wp_specialchars_decode( $text, $quote_style = null ) {
	return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function current_time( $type, $gmt = false ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['sl_http_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	return array(
		'response' => array( 'code' => 500 ),
		'body'     => 'gateway failure',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}

class WP_Error {

	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// Autoload the plugin classes.
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'SmartLogin\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'SmartLogin\\' ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );
		$kebab    = strtolower( preg_replace( '/(?<!^)([A-Z])/', '-$1', $short ) );
		$file     = SMART_LOGIN_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' ) . 'class-' . $kebab . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
