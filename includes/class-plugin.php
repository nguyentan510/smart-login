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
use SmartLogin\Frontend\LoginDialog;
use SmartLogin\Frontend\RestController;
use SmartLogin\Frontend\Shortcodes;
use SmartLogin\Frontend\WooIntegration;
use SmartLogin\Identity\IdentityRepository;
use SmartLogin\Security\AuditLog;

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

		// `deleted_user`, not `delete_user`: after the row is gone, so a deletion that
		// fails leaves the identities where they were.
		add_action( 'deleted_user', array( $this, 'release_identities' ), 10, 1 );

		$this->services['login']     = new LoginHandler();
		$this->services['providers'] = new ProviderAuthController();
		$this->services['forms']     = new FormController();
		$this->services['rest']      = new RestController();
		$this->services['assets']    = new Assets();
		$this->services['codes']     = new Shortcodes();
		$this->services['dialog']    = new LoginDialog();

		if ( Settings::is_on( 'address.enabled' ) ) {
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

			if ( Settings::is_on( 'address.enabled' ) ) {
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

	/**
	 * Give back every subject a deleted account was holding.
	 *
	 * `IdentityRepository::retire_all_for_user()` has existed since Phase 2 with a
	 * default reason of `'user_deleted'` — it was written for this and never wired up,
	 * so until now only the integration gate called it. The consequence was a denial
	 * rather than a takeover, which is why it survived every gate: the row stayed live
	 * pointing at an account that no longer existed, `resolve()` answered KNOWN, and
	 * `create_verified_user()` refused that phone number or address as already
	 * registered for ever. Login itself failed closed, because `LoginHandler` asks
	 * `owner()` and `get_userdata()` returns false.
	 *
	 * Retire, not delete: the history row is what makes the subject resolve RETIRED,
	 * and RETIRED is what lets a *different* person register it while keeping the
	 * trail that it was reused. Phase 3 built that distinction and this is the case it
	 * was built for.
	 *
	 * Single site only. `wpmu_delete_user` fires this too on multisite, but
	 * `remove_user_from_blog` does not, and whether leaving one site of a network
	 * should release identities the network still owns is a decision, not an omission.
	 *
	 * @param int $user_id
	 */
	public function release_identities( $user_id ): void {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$released = ( new IdentityRepository() )->retire_all_for_user( $user_id );

		if ( $released > 0 ) {
			AuditLog::record(
				AuditLog::IDENTITY_RETIRED,
				'',
				array(
					'reason'   => 'user_deleted',
					'released' => $released,
				),
				$user_id
			);
		}
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
