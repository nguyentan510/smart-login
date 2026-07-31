<?php
/**
 * Menu, asset loading and routing for the admin screens.
 *
 * That is the whole job now. This class used to be 1100 lines and also held the
 * schema, four field-rendering helpers, six hand-written tab bodies, the
 * provider cards, the inline provider documentation, the send-a-test panels, the
 * system status table and the audit log. Each of those has moved to something
 * that does one of them.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin;

use SmartLogin\Admin\Screens\AuditScreen;
use SmartLogin\Admin\Screens\OverviewScreen;
use SmartLogin\Admin\Screens\SettingsScreen;
use SmartLogin\FieldRegistry;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG       = 'smart-login';
	const AUDIT_SLUG = 'smart-login-audit';
	const GROUP      = 'smart_login_group';

	/** The readiness screen. It holds no fields, so it is not a registry tab. */
	const OVERVIEW = 'overview';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'dev_mode_notice' ) );
		add_filter( 'plugin_action_links_' . SMART_LOGIN_BASENAME, array( $this, 'action_links' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Smart Login', 'smart-login' ),
			__( 'Smart Login', 'smart-login' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-lock',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Cài đặt', 'smart-login' ),
			__( 'Cài đặt', 'smart-login' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Nhật ký', 'smart-login' ),
			__( 'Nhật ký', 'smart-login' ),
			'manage_options',
			self::AUDIT_SLUG,
			array( $this, 'render_audit' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'smart-login-admin', SMART_LOGIN_URL . 'assets/css/admin.css', array(), SMART_LOGIN_VERSION );
		wp_enqueue_script( 'smart-login-admin', SMART_LOGIN_URL . 'assets/js/admin.js', array(), SMART_LOGIN_VERSION, true );

		wp_localize_script(
			'smart-login-admin',
			'SmartLoginAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( WebhookTester::NONCE ),
				'i18n'    => array(
					'sending' => __( 'Đang gửi…', 'smart-login' ),
					'test'    => __( 'Gửi thử', 'smart-login' ),
					'failed'  => __( 'Không kết nối được tới máy chủ.', 'smart-login' ),
					'prompt'  => __( 'Nhập số điện thoại hoặc email để gửi thử.', 'smart-login' ),
				),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Cài đặt', 'smart-login' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Dev mode leaks codes on screen; make that impossible to forget.
	 */
	public function dev_mode_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! ( new \SmartLogin\OTP\OtpService() )->dev_mode_active() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Smart Login:', 'smart-login' ),
			esc_html__( 'Chế độ DEV đang bật — mã OTP được hiển thị trực tiếp trên màn hình. Hãy tắt trước khi đưa lên môi trường thật.', 'smart-login' )
		);
	}

	// -----------------------------------------------------------------
	// Routing
	// -----------------------------------------------------------------

	/**
	 * Overview is the default and the fallback. An unrecognised tab lands on the
	 * screen that says what is wrong rather than on an arbitrary settings page.
	 */
	public function render(): void {
		self::require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only tab switch.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( isset( FieldRegistry::tabs()[ $requested ] ) ) {
			( new SettingsScreen() )->render( $requested );
			return;
		}

		( new OverviewScreen() )->render();
	}

	/**
	 * Every screen in the strip, readiness first.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array( self::OVERVIEW => __( 'Tổng quan', 'smart-login' ) ) + FieldRegistry::tabs();
	}

	public function render_audit(): void {
		self::require_capability();

		( new AuditScreen() )->render();
	}

	/**
	 * The tab strip, shared by every settings screen.
	 */
	public static function nav( string $active ): void {
		?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( self::tabs() as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"
					class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private static function require_capability(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'smart-login' ) );
		}
	}
}
