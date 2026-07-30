<?php
/**
 * Loads templates, letting the active theme override any of them by putting a
 * file of the same name in `yourtheme/smart-login/`.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

defined( 'ABSPATH' ) || exit;

class TemplateLoader {

	const THEME_DIR = 'smart-login';

	/**
	 * Absolute path to the template that should be used for $name.
	 */
	public static function locate( string $name ): string {
		$name = ltrim( str_replace( array( '..', "\0" ), '', $name ), '/' );
		$file = $name . '.php';

		$found = locate_template( array( self::THEME_DIR . '/' . $file ) );

		if ( ! $found ) {
			$found = SMART_LOGIN_DIR . 'templates/' . $file;
		}

		/**
		 * @param string $found
		 * @param string $name
		 */
		return (string) apply_filters( 'smart_login_locate_template', $found, $name );
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string $template_name Template name without extension, e.g. 'form-login'.
	 * @param array  $args Extracted into the template scope.
	 */
	public static function render( string $template_name, array $args = array() ): string {
		$file = self::locate( $template_name );

		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();

		/**
		 * @param array  $args
		 * @param string $template_name
		 */
		$args = (array) apply_filters( 'smart_login_template_args', $args, $template_name );

		// phpcs:ignore WordPress.PHP.DontExtract -- templates expect named variables.
		extract( $args, EXTR_SKIP );

		include $file;

		return (string) ob_get_clean();
	}

	public static function output( string $name, array $args = array() ): void {
		// Templates are trusted plugin/theme code and escape their own output.
		echo self::render( $name, $args ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
