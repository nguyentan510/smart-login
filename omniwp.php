<?php
/**
 * Plugin Name:       OmniWP
 * Plugin URI:        https://example.com/omniwp
 * Description:       Nền tảng Quản lý Định danh & Trung tâm Khách hàng Toàn năng (Customer Identity & Account Portal) cho WordPress & WooCommerce.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            OmniWP Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omniwp
 * Domain Path:       /languages
 *
 * @package OmniWP
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'OMNIWP_LOADED' ) ) {
	return;
}
define( 'OMNIWP_LOADED', true );

defined( 'OMNIWP_VERSION' ) || define( 'OMNIWP_VERSION', '1.1.0' );
defined( 'OMNIWP_DB_VERSION' ) || define( 'OMNIWP_DB_VERSION', '3' );
defined( 'OMNIWP_FILE' ) || define( 'OMNIWP_FILE', __FILE__ );
defined( 'OMNIWP_DIR' ) || define( 'OMNIWP_DIR', plugin_dir_path( __FILE__ ) );
defined( 'OMNIWP_URL' ) || define( 'OMNIWP_URL', plugin_dir_url( __FILE__ ) );
defined( 'OMNIWP_BASENAME' ) || define( 'OMNIWP_BASENAME', plugin_basename( __FILE__ ) );

// Deployment constants/environment override credentials entered in Settings.
foreach ( array(
	'OMNIWP_GOOGLE_CLIENT_ID',
	'OMNIWP_GOOGLE_CLIENT_SECRET',
	'OMNIWP_GOOGLE_REDIRECT_URI',
	'OMNIWP_GOOGLE_CLIENT_ID',
	'OMNIWP_GOOGLE_CLIENT_SECRET',
	'OMNIWP_GOOGLE_REDIRECT_URI',
) as $ow_provider_constant ) {
	if ( ! defined( $ow_provider_constant ) && function_exists( 'getenv' ) ) {
		$ow_provider_value = getenv( $ow_provider_constant );
		if ( false !== $ow_provider_value && '' !== (string) $ow_provider_value ) {
			define( $ow_provider_constant, (string) $ow_provider_value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound
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
				esc_html__( 'OmniWP yêu cầu PHP 8.0 trở lên. Plugin đang tạm dừng hoạt động.', 'omniwp' )
			);
		}
	);
	return;
}

/**
 * Autoloader: OmniWP\OTP\OtpService -> includes/OTP/class-otp-service.php
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 === strpos( $class_name, 'OmniWP\\' ) ) {
			$class_name = 'OmniWP\\' . substr( $class_name, strlen( 'OmniWP\\' ) );
		}

		if ( 0 !== strpos( $class_name, 'OmniWP\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'OmniWP\\' ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		// CamelCase -> kebab-case (OtpService -> otp-service).
		$kebab = strtolower( preg_replace( '/(?<!^)([A-Z])/', '-$1', $short ) );

		$dir  = OMNIWP_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' );
		$file = $dir . 'class-' . $kebab . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\\OmniWP\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\OmniWP\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\OmniWP\Plugin::instance()->boot();
	},
	5
);
