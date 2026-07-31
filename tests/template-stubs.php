<?php
/**
 * The extra WordPress surface templates need, on top of tests/stubs.php.
 *
 * Kept separate so the pure-logic suites stay lean: only the template smoke test
 * pays for this.
 *
 * @package SmartLogin
 */

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Just enough of WP_User for a template to read from.
	 */
	class WP_User {

		public $ID;
		public $user_login;
		public $user_email;
		public $display_name;
		public $first_name;
		public $last_name;
		public $roles = array( 'customer' );

		public function __construct( int $id = 1, string $display_name = 'Người dùng' ) {
			$this->ID           = $id;
			$this->user_login   = 'sl_' . str_repeat( 'a1', 12 );
			$this->user_email   = 'user@example.test';
			$this->display_name = $display_name;
			$this->first_name   = 'Như';
			$this->last_name    = 'Nguyễn';
		}

		public function exists(): bool {
			return $this->ID > 0;
		}
	}
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return esc_html( $text );
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}

function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}

function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}

function _e( $text, $domain = null ) {
	echo esc_html( $text );
}

function _x( $text, $context, $domain = null ) {
	return $text;
}

function esc_html_x( $text, $context, $domain = null ) {
	return esc_html( $text );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';

	if ( $display ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $field;
}

function wp_create_nonce( $action = -1 ) {
	return 'test-nonce';
}

function checked( $checked, $current = true, $display = true ) {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $result;
}

function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $result;
}

function disabled( $disabled, $current = true, $display = true ) {
	$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $result;
}

function is_user_logged_in() {
	return ! empty( $GLOBALS['sl_logged_in'] );
}

function get_current_user_id() {
	return (int) ( $GLOBALS['sl_current_user_id'] ?? 0 );
}

function wp_get_current_user() {
	return new WP_User( get_current_user_id() ?: 1 );
}

function get_userdata( $user_id ) {
	return $user_id > 0 ? new WP_User( (int) $user_id ) : false;
}

/**
 * Frontend\AccountForm resolves the account it renders, so the smoke test needs
 * this as well as get_userdata(). Only the 'id' field is used.
 */
function get_user_by( $field, $value ) {
	return 'id' === $field && (int) $value > 0 ? new WP_User( (int) $value ) : false;
}

function get_permalink( $post = null ) {
	return 'https://example.test/my-account/';
}

function wp_logout_url( $redirect = '' ) {
	return 'https://example.test/wp-login.php?action=logout';
}

function wp_lostpassword_url( $redirect = '' ) {
	return 'https://example.test/wp-login.php?action=lostpassword';
}

function wc_get_account_endpoint_url( $endpoint ) {
	return 'https://example.test/my-account/' . $endpoint . '/';
}

function do_shortcode( $content ) {
	return $content;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

/**
 * Reached now that the status fixture carries non-empty missing-field lists —
 * the old fixture had both empty, so the branch that plucks labels was never
 * executed by any test.
 */
function wp_list_pluck( $list, $field, $index_key = null ) {
	$out = array();

	foreach ( (array) $list as $key => $item ) {
		$value = is_array( $item ) ? ( $item[ $field ] ?? null ) : ( is_object( $item ) ? ( $item->$field ?? null ) : null );

		if ( null !== $index_key && is_array( $item ) && isset( $item[ $index_key ] ) ) {
			$out[ $item[ $index_key ] ] = $value;
			continue;
		}

		$out[ $key ] = $value;
	}

	return $out;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_kses( $text, $allowed = array() ) {
	return (string) $text;
}

/**
 * No theme override, so TemplateLoader falls back to the plugin's own copy —
 * which is the path this suite is here to exercise.
 */
function locate_template( $template_names, $load = false, $load_once = true, $args = array() ) {
	return '';
}

function remove_query_arg( $key, $query = false ) {
	$url = false === $query ? 'https://example.test/my-account/' : (string) $query;

	return current( explode( '?', $url ) );
}

function wp_print_inline_script_tag( $javascript, $attributes = array() ) {
	echo '<script>' . $javascript . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

function wp_get_inline_script_tag( $javascript, $attributes = array() ) {
	return '<script>' . $javascript . '</script>';
}

function wp_unique_id( $prefix = '' ) {
	static $id = 0;

	return $prefix . ++$id;
}

/*
 * A template that renders the address picker reaches Assets::enqueue_address()
 * on the way — onboarding.php is the first one to do so. Enqueueing is a no-op
 * here; what matters is that the call resolves rather than fatalling, which is
 * precisely the class of bug this suite exists to catch.
 */
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {}

function sanitize_html_class( $class_name, $fallback = '' ) {
	$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class_name );

	return '' !== $sanitized ? $sanitized : $fallback;
}
