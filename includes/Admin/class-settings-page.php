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
use SmartLogin\Admin\Screens\GuideScreen;
use SmartLogin\Admin\Screens\OverviewScreen;
use SmartLogin\Admin\Screens\SettingsScreen;
use SmartLogin\FieldRegistry;
use SmartLogin\Installer;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG       = 'smart-login';
	const AUDIT_SLUG = 'smart-login-audit';
	const GROUP      = 'smart_login_group';

	/** The readiness screen. It holds no fields, so it is not a registry tab. */
	const OVERVIEW = 'overview';

	/** admin-post action that clears what an upgrade left behind. */
	const DISMISS_NOTICE = 'smart_login_dismiss_migration_notice';

	public function register(): void {
		// The overview screen owns one admin-post handler (resume sending), which
		// has to be registered whether or not that screen is the one rendering.
		( new OverviewScreen() )->register();

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'dev_mode_notice' ) );
		add_action( 'admin_notices', array( $this, 'migration_notices' ) );
		add_action( 'admin_post_' . self::DISMISS_NOTICE, array( $this, 'dismiss_migration_notices' ) );
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

	/**
	 * What an upgrade could not decide for the site.
	 *
	 * Shown on every admin screen rather than only on Smart Login's own, because
	 * the administrator who needs to read it has no reason to be visiting this
	 * plugin — the configuration worked yesterday.
	 *
	 * Dismissal deletes the record. Nothing else clears it: it does not expire, it
	 * does not go away on the next upgrade, and it survives a settings save, which
	 * is the difference between a notice and a flash message.
	 */
	public function migration_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notices = (array) get_option( Installer::MIGRATION_NOTICE_OPTION, array() );

		if ( ! $notices ) {
			return;
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses( (string) $notice, array( 'code' => array() ) )
			);
		}

		printf(
			'<div class="notice notice-warning"><p><a href="%s">%s</a></p></div>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_NOTICE ), self::DISMISS_NOTICE ) ),
			esc_html__( 'Tôi đã đọc — ẩn thông báo này', 'smart-login' )
		);
	}

	public function dismiss_migration_notices(): void {
		self::require_capability();
		check_admin_referer( self::DISMISS_NOTICE );

		delete_option( Installer::MIGRATION_NOTICE_OPTION );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	// -----------------------------------------------------------------
	// Routing
	// -----------------------------------------------------------------

	/**
	 * Slugs that used to name a screen, and where they now point.
	 *
	 * `delivery-automation` asserted an association 20.1 removed, and the prefix
	 * was visible — it is the `?tab=` value an administrator copies into a support
	 * message. Renamed in 20.4, aliased here so a bookmark lands on the screen it
	 * meant rather than on Overview, which would be a soft landing and a support
	 * question.
	 *
	 * @var array<string,string>
	 */
	const RENAMED_TABS = array( 'delivery-automation' => 'integrations' );

	/**
	 * Overview is the default and the fallback. An unrecognised tab lands on the
	 * screen that says what is wrong rather than on an arbitrary settings page.
	 */
	public function render(): void {
		self::require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only tab switch.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$requested = self::RENAMED_TABS[ $requested ] ?? $requested;

		if ( isset( FieldRegistry::tabs()[ $requested ] ) ) {
			( new SettingsScreen() )->render( $requested );
			return;
		}

		// Routed here rather than through the registry: it holds no fields, so a
		// save would have nothing to write. Same reason the overview screen below
		// is not a registry tab either.
		if ( GuideScreen::SLUG === $requested ) {
			( new GuideScreen() )->render();
			return;
		}

		( new OverviewScreen() )->render();
	}

	/**
	 * Every screen in the strip: readiness first, instructions last.
	 *
	 * The order is the order the two get opened in. Overview is what you read
	 * after installing; the guide is what you read when you are stuck, and that
	 * is not the same moment.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array( self::OVERVIEW => __( 'Tổng quan', 'smart-login' ) )
			+ FieldRegistry::tabs()
			+ array( GuideScreen::SLUG => __( 'Hướng dẫn', 'smart-login' ) );
	}

	public function render_audit(): void {
		self::require_capability();

		( new AuditScreen() )->render();
	}

	/**
	 * The tab strip, shared by every settings screen.
	 */
	public static function nav( string $active ): void {
		$parents = FieldRegistry::tab_parents();
		$family  = FieldRegistry::parent_tab( $active );
		?>
		<nav class="nav-tab-wrapper">
			<?php
			foreach ( self::tabs() as $slug => $label ) :
				// Children are not in the top strip; six entries became nine when
				// the delivery screen split, and a nine-item strip is worse than
				// the page it was meant to fix.
				if ( isset( $parents[ $slug ] ) ) {
					continue;
				}
				?>
				<a
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"
					class="nav-tab <?php echo $slug === $family ? 'nav-tab-active' : ''; ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		self::sub_nav( $family, $active );
	}

	/**
	 * The second level, when the active tab belongs to a family.
	 *
	 * The parent is the first entry in its own sub-nav rather than a heading
	 * above it: it is a screen with fields and a Save button like the others, and
	 * presenting it as a container would leave no way to reach it.
	 */
	private static function sub_nav( string $family, string $active ): void {
		$children = array_keys( FieldRegistry::tab_parents(), $family, true );

		if ( ! $children ) {
			return;
		}

		$siblings = array_merge( array( $family ), $children );
		$last     = array_key_last( $siblings );
		?>
		<ul class="subsubsub sl-subnav">
			<?php foreach ( $siblings as $index => $slug ) : ?>
				<li>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"
						class="<?php echo $slug === $active ? 'current' : ''; ?>"
					><?php echo esc_html( FieldRegistry::self_label( $slug ) ); ?></a>
					<?php echo $index === $last ? '' : ' |'; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<div style="clear:both"></div>
		<?php
	}

	private static function require_capability(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'smart-login' ) );
		}
	}
}
