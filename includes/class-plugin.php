<?php
/**
 * Plugin container: builds the services and wires every hook.
 *
 * @package SmartLogin
 */

namespace SmartLogin;

use SmartLogin\Address\AddressRest;
use SmartLogin\Address\WooAddress;
use SmartLogin\Admin\SettingsPage;
use SmartLogin\Admin\UsersColumn;
use SmartLogin\Admin\WebhookTester;
use SmartLogin\Auth\LoginHandler;
use SmartLogin\Auth\ProviderAuthController;
use SmartLogin\Frontend\Assets;
use SmartLogin\Frontend\FormController;
use SmartLogin\Frontend\RestController;
use SmartLogin\Frontend\Shortcodes;
use SmartLogin\Frontend\WooIntegration;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var array<string,object> */
	private $services = array();

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );
		add_action( Installer::CLEANUP_HOOK, array( Installer::class, 'cleanup' ) );

		$this->services['login']  = new LoginHandler();
		$this->services['providers'] = new ProviderAuthController();
		$this->services['forms']  = new FormController();
		$this->services['rest']   = new RestController();
		$this->services['assets'] = new Assets();
		$this->services['codes']  = new Shortcodes();

		if ( Settings::is_on( 'address_enabled' ) ) {
			$this->services['address_rest'] = new AddressRest();
		}

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'register' ) ) {
				$service->register();
			}
		}

		if ( self::woocommerce_active() ) {
			$this->services['woo'] = new WooIntegration();
			$this->services['woo']->register();

			if ( Settings::is_on( 'address_enabled' ) ) {
				$this->services['woo_address'] = new WooAddress();
				$this->services['woo_address']->register();
			}
		}

		if ( is_admin() ) {
			$this->services['settings_page'] = new SettingsPage();
			$this->services['settings_page']->register();

			$this->services['tester'] = new WebhookTester();
			$this->services['tester']->register();

			// user_login is opaque now, so the Users screen needs the identity
			// column and identity-aware search to stay usable.
			$this->services['users_column'] = new UsersColumn();
			$this->services['users_column']->register();
		}

		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite' ), 99 );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'smart-login', false, dirname( SMART_LOGIN_BASENAME ) . '/languages' );
	}

	public function maybe_flush_rewrite(): void {
		if ( get_transient( 'smart_login_flush_rewrite' ) ) {
			delete_transient( 'smart_login_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * @return object|null
	 */
	public function service( string $key ) {
		return $this->services[ $key ] ?? null;
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
