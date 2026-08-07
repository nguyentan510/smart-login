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
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['sl_options'] = array();
$GLOBALS['sl_transients'] = array();
$GLOBALS['sl_transient_delete_fail'] = false;
$GLOBALS['sl_http_requests'] = array();

function get_option( $name, $default = false ) {
	return $GLOBALS['sl_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['sl_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['sl_options'][ $name ] );
	return true;
}

/** Every mail the plugin tried to send, so a test can count them. */
$GLOBALS['sl_mails'] = array();

function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$GLOBALS['sl_mails'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	);

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

/**
 * Registered filters, hook => list of callbacks.
 *
 * Before this existed, apply_filters() returned its input untouched, so a test
 * could not exercise any branch a filter controls — Client::ip() reading proxy
 * headers being the one that forced the change. An empty registry behaves
 * exactly as the old stub did, which is what keeps the four suites that load
 * this file unaffected.
 */
$GLOBALS['sl_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sl_filters'][ $hook ][] = array(
		'cb'   => $callback,
		'args' => max( 1, (int) $accepted_args ),
	);

	return true;
}

function remove_all_filters( $hook = '' ) {
	if ( '' === $hook ) {
		$GLOBALS['sl_filters'] = array();
		return true;
	}

	unset( $GLOBALS['sl_filters'][ $hook ] );

	return true;
}

/**
 * Actions are filters in WordPress, and they are filters here for the same
 * reason: MailTransport registers `phpmailer_init` around its own send and
 * removes it again, and a stub that cannot express "registered, then not" cannot
 * assert the half of 10.7 that keeps the clamp off the site's other mail.
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['sl_filters'][ $hook ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['sl_filters'][ $hook ] as $index => $filter ) {
		if ( $filter['cb'] == $callback ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- array callables compare by value.
			unset( $GLOBALS['sl_filters'][ $hook ][ $index ] );

			return true;
		}
	}

	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	return remove_filter( $hook, $callback, $priority );
}

function has_action( $hook, $callback = false ) {
	return has_filter( $hook, $callback );
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}

function has_filter( $hook, $callback = false ) {
	return ! empty( $GLOBALS['sl_filters'][ $hook ] );
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['sl_filters'][ $hook ] ?? array() as $filter ) {
		$params = array_slice( array_merge( array( $value ), $args ), 0, $filter['args'] );
		$value  = call_user_func_array( $filter['cb'], $params );
	}

	return $value;
}

$GLOBALS['sl_user_meta'] = array();

function get_user_meta( $user_id, $key = '', $single = false ) {
	$value = $GLOBALS['sl_user_meta'][ (int) $user_id ][ $key ] ?? '';

	return $single ? $value : ( '' === $value ? array() : array( $value ) );
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['sl_user_meta'][ (int) $user_id ][ $key ] = $value;

	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['sl_user_meta'][ (int) $user_id ][ $key ] );

	return true;
}

/**
 * Addresses `wp_users` already holds, as email => user id.
 *
 * Deliberately not a WP_User factory: `email_exists()` answers with the owner's
 * id, which is everything the identity code needs from this direction, and a
 * WP_User class here would collide with the richer one in template-stubs.php.
 */
$GLOBALS['sl_users_by_email'] = array();

function email_exists( $email ) {
	return $GLOBALS['sl_users_by_email'][ strtolower( (string) $email ) ] ?? false;
}

/**
 * Every wp_update_user() call this run received.
 *
 * Recorded rather than executed: the assertions that need it are about whether a
 * write happened and in what order, not about what WordPress would store.
 */
$GLOBALS['sl_user_updates'] = array();

function wp_update_user( $data ) {
	$GLOBALS['sl_user_updates'][] = (array) $data;

	return (int) ( ( (array) $data )['ID'] ?? 0 );
}

function do_action( $hook ) {}

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = strip_tags( (string) $text );

	return $remove_breaks ? trim( preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : $text;
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

/*
 * Added in 19.8. The dialog's captured-link list names WordPress's own sign-in
 * URL, and without this the list came out empty in-process — which reads as
 * "capture is off" rather than "the stub is missing". A rule that cannot tell
 * those apart is the kind that gets believed.
 */
function wp_login_url( $redirect = '' ) {
	$url = 'https://example.test/wp-login.php';

	return '' === $redirect ? $url : $url . '?redirect_to=' . rawurlencode( $redirect );
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

/**
 * The canned reply every outbound call receives.
 *
 * A 500 by default, which is what makes the captcha guard rail meaningful and
 * what every suite written before 10.3 assumes. A test that needs a different
 * answer assigns to $GLOBALS['sl_http_response'] and puts it back, so the
 * unset default behaves exactly as the fixed 500 always did.
 */
function sl_stub_http_response() {
	return $GLOBALS['sl_http_response'] ?? array(
		'response' => array( 'code' => 500 ),
		'body'     => 'gateway failure',
	);
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['sl_http_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	return sl_stub_http_response();
}

/**
 * Delegates, so anything asserting on $GLOBALS['sl_http_requests'] sees POSTs too.
 *
 * The default response is a 500, which is what makes the captcha guard rail
 * meaningful: verify_token() has to read that as "no" rather than as "carry on".
 */
function wp_remote_post( $url, $args = array() ) {
	$args['method'] = 'POST';

	return wp_remote_request( $url, $args );
}

/**
 * Delegates for the same reason wp_remote_post() does.
 *
 * Added when a provider's profile call turned out to be the only outbound GET in the
 * plugin that no pure suite could see: the provider read its token back out of a
 * query string, and there was no stub to record that it had.
 */
function wp_remote_get( $url, $args = array() ) {
	$args['method'] = 'GET';

	return wp_remote_request( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}

/**
 * Minimal $wpdb so repository code can be exercised without MySQL.
 *
 * Deliberately dumb: it does not parse SQL. Each getter returns whatever the
 * test put in the matching global, which is enough to drive code paths whose
 * logic lives in PHP rather than in the query. Anything that depends on real SQL
 * semantics belongs in the integration gate, not here.
 */
class SmartLoginStubWpdb {

	public $prefix = 'wp_';
	public $users = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public $posts = 'wp_posts';
	public $last_error = '';
	public $insert_id = 1;

	/** @var array<int,array<string,mixed>> Every write this stub received. */
	public $writes = array();

	public function prepare( $query, ...$args ) {
		// Close enough for tests: swap placeholders for the literal values.
		$query = str_replace( array( '%d', '%s' ), '%s', $query );

		return $args ? vsprintf( $query, array_map( 'strval', $args ) ) : $query;
	}

	public function get_var( $query = null ) {
		return $GLOBALS['sl_wpdb_var'] ?? null;
	}

	public function get_row( $query = null, $output = null ) {
		return $GLOBALS['sl_wpdb_row'] ?? null;
	}

	public function get_results( $query = null, $output = null ) {
		return $GLOBALS['sl_wpdb_results'] ?? array();
	}

	public function get_col( $query = null, $index = 0 ) {
		return $GLOBALS['sl_wpdb_col'] ?? array();
	}

	public function insert( $table, $data, $format = null ) {
		$this->writes[] = array( 'op' => 'insert', 'table' => $table, 'data' => $data );

		return $GLOBALS['sl_wpdb_insert_result'] ?? 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->writes[] = array( 'op' => 'update', 'table' => $table, 'data' => $data );

		return $GLOBALS['sl_wpdb_update_result'] ?? 1;
	}

	public function delete( $table, $where, $where_format = null ) {
		$this->writes[] = array( 'op' => 'delete', 'table' => $table, 'where' => $where );

		return $GLOBALS['sl_wpdb_delete_result'] ?? 1;
	}

	public function query( $query ) {
		$this->writes[] = array( 'op' => 'query', 'sql' => $query );

		return 1;
	}

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
}

$GLOBALS['wpdb'] = new SmartLoginStubWpdb();

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
