<?php
/**
 * The extra WordPress surface the admin screens touch.
 *
 * Kept apart from stubs.php and template-stubs.php so the pure-logic and
 * front-end suites do not pay for the settings API, the submit button helpers
 * or the environment probes.
 *
 * @package SmartLogin
 */

if ( ! defined( 'SMART_LOGIN_VERSION' ) ) {
	define( 'SMART_LOGIN_VERSION', '1.0.1-test' );
}

if ( ! defined( 'SMART_LOGIN_BASENAME' ) ) {
	define( 'SMART_LOGIN_BASENAME', 'smart-login/smart-login.php' );
}

function current_user_can( $capability, ...$args ) {
	return true;
}

function wp_get_environment_type() {
	return 'local';
}

function settings_fields( $group ) {
	printf(
		'<input type="hidden" name="option_page" value="%s" /><input type="hidden" name="action" value="update" />',
		esc_attr( $group )
	);
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	printf( '<p class="submit"><button type="submit" class="button button-%s">%s</button></p>', esc_attr( $type ), esc_html( (string) $text ) );
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['sl_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}

function wp_next_scheduled( $hook, $args = array() ) {
	return $GLOBALS['sl_next_scheduled'] ?? false;
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
}

// add_filter() and add_action() are no longer declared here: stubs.php now
// carries a real registry, and this file is only ever loaded after it. A second
// no-op declaration would be a redeclaration fatal, and the no-op would win
// nothing. add_action() joined the registry in 10.7, for the same reason
// add_filter() did in 9.0 — a stub that cannot express "registered, then
// removed" cannot assert that MailTransport takes its SMTP clamp back off.

function add_menu_page( ...$args ) {
	return 'toplevel_page_smart-login';
}

function add_submenu_page( ...$args ) {
	return 'smart-login_page_stub';
}

function register_setting( $group, $option, $args = array() ) {
	return true;
}

function wp_localize_script( $handle, $name, $data ) {
	return true;
}

/**
 * Two published pages, so the page picker has something to draw and the
 * "keep an unrecognised stored URL as its own option" branch is reachable.
 */
function get_pages( $args = array() ) {
	return $GLOBALS['sl_pages'] ?? array(
		(object) array(
			'ID'         => 11,
			'post_title' => 'Điều khoản sử dụng',
		),
		(object) array(
			'ID'         => 12,
			'post_title' => 'Tài khoản',
		),
	);
}
