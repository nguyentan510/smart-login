<?php
/**
 * Plugin Name:       Smart Login
 * Plugin URI:        https://example.com/smart-login
 * Description:       Đăng nhập / đăng ký / xác thực OTP bằng số điện thoại hoặc email cho WordPress & WooCommerce. Hỗ trợ đẩy OTP ra gateway ngoài qua webhook.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Smart Login
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-login
 * Domain Path:       /languages
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

define( 'SMART_LOGIN_VERSION', '1.1.0' );
// Bumped to 2 so maybe_upgrade() runs once more: a login provider was dropped and
// its stored settings block and sealed secret have no screen left to clear them.
define( 'SMART_LOGIN_DB_VERSION', '3' );
define( 'SMART_LOGIN_FILE', __FILE__ );
define( 'SMART_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMART_LOGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMART_LOGIN_BASENAME', plugin_basename( __FILE__ ) );

// Deployment constants/environment override credentials entered in Settings.
foreach ( array(
	'SMART_LOGIN_GOOGLE_CLIENT_ID',
	'SMART_LOGIN_GOOGLE_CLIENT_SECRET',
	'SMART_LOGIN_GOOGLE_REDIRECT_URI',
) as $smart_login_provider_constant ) {
	if ( ! defined( $smart_login_provider_constant ) && function_exists( 'getenv' ) ) {
		$smart_login_provider_value = getenv( $smart_login_provider_constant );
		if ( false !== $smart_login_provider_value && '' !== (string) $smart_login_provider_value ) {
			define( $smart_login_provider_constant, (string) $smart_login_provider_value );
		}
	}
}

/**
 * Bail out early on unsupported PHP versions rather than fataling mid-request.
 */
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Smart Login yêu cầu PHP 8.0 trở lên. Plugin đang tạm dừng hoạt động.', 'smart-login' )
			);
		}
	);
	return;
}

/**
 * Autoloader: SmartLogin\OTP\OtpService -> includes/OTP/class-otp-service.php
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'SmartLogin\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'SmartLogin\\' ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		// CamelCase -> kebab-case (OtpService -> otp-service).
		$kebab = strtolower( preg_replace( '/(?<!^)([A-Z])/', '-$1', $short ) );

		$dir  = SMART_LOGIN_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' );
		$file = $dir . 'class-' . $kebab . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\\SmartLogin\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\SmartLogin\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\SmartLogin\Plugin::instance()->boot();
	},
	5
);
