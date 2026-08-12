<?php
/**
 * Admin Metabox for Smart Menu in WP Nav Menus (wp-admin/nav-menus.php).
 *
 * @package OmniWP
 */

namespace OmniWP\Admin;

defined( 'ABSPATH' ) || exit;

final class SmartMenuMetaBox {

	const METABOX_ID = 'add-omniwp-menu';

	public function register(): void {
		add_action( 'admin_head-nav-menus.php', array( $this, 'add_metabox' ) );
	}

	/**
	 * Available preset menu items tailored for Smart Login.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function presets(): array {
		return array(
			// Nhóm 1: Nút điều khiển & Auth Triggers
			array(
				'id'         => 'smart-button',
				'title'      => __( 'Nút Tài khoản Thông minh (Header)', 'omniwp' ),
				'url'        => '#smart-button',
				'visibility' => 'everyone',
			),
			array(
				'id'         => 'omniwp',
				'title'      => __( 'Đăng nhập / Đăng ký (Mở Popup)', 'omniwp' ),
				'url'        => '#omniwp',
				'visibility' => 'guest',
			),
			array(
				'id'         => 'smart-auth-switcher',
				'title'      => __( 'Đăng nhập / Tên người dùng (Tự động đổi)', 'omniwp' ),
				'url'        => '#smart-auth-switcher',
				'visibility' => 'everyone',
			),
			array(
				'id'         => 'smart-logout',
				'title'      => __( 'Đăng xuất', 'omniwp' ),
				'url'        => '#smart-logout',
				'visibility' => 'logged_in',
			),

			// Nhóm 2: Các Tab Smart Account Hub
			array(
				'id'         => 'smart-account',
				'title'      => __( 'Tài khoản của tôi (Tổng quan)', 'omniwp' ),
				'url'        => '#smart-account',
				'visibility' => 'logged_in',
			),
			array(
				'id'         => 'smart-account-profile',
				'title'      => __( 'Thông tin cá nhân', 'omniwp' ),
				'url'        => '#smart-account-profile',
				'visibility' => 'logged_in',
			),
			array(
				'id'         => 'smart-account-orders',
				'title'      => __( 'Lịch sử đơn hàng', 'omniwp' ),
				'url'        => '#smart-account-orders',
				'visibility' => 'logged_in',
			),
			array(
				'id'         => 'smart-account-address',
				'title'      => __( 'Địa chỉ nhận hàng', 'omniwp' ),
				'url'        => '#smart-account-address',
				'visibility' => 'logged_in',
			),
			array(
				'id'         => 'smart-account-security',
				'title'      => __( 'Đăng nhập & Bảo mật', 'omniwp' ),
				'url'        => '#smart-account-security',
				'visibility' => 'logged_in',
			),
		);
	}

	public function add_metabox(): void {
		add_meta_box(
			self::METABOX_ID,
			__( 'Smart Menu', 'omniwp' ),
			array( $this, 'render_metabox' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	public function render_metabox(): void {
		global $_nav_menu_placeholder, $nav_menu_selected_id;

		$_nav_menu_placeholder = ( 0 > $_nav_menu_placeholder ) ? (int) $_nav_menu_placeholder - 1 : -1;
		$presets               = self::presets();
		?>
		<div id="posttype-omniwp-endpoints" class="posttypediv">
			<div id="tabs-panel-omniwp-endpoints" class="tabs-panel tabs-panel-active">
				<ul id="omniwp-endpoints-checklist" class="categorychecklist form-no-clear">
					<?php
					$i = -1;
					foreach ( $presets as $preset ) :
						?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox"
									class="menu-item-checkbox"
									name="menu-item[<?php echo esc_attr( (string) $i ); ?>][menu-item-object-id]"
									value="<?php echo esc_attr( (string) $i ); ?>" />
								<?php echo esc_html( $preset['title'] ); ?>
							</label>
							<input type="hidden" class="menu-item-type" name="menu-item[<?php echo esc_attr( (string) $i ); ?>][menu-item-type]" value="custom" />
							<input type="hidden" class="menu-item-title" name="menu-item[<?php echo esc_attr( (string) $i ); ?>][menu-item-title]" value="<?php echo esc_attr( $preset['title'] ); ?>" />
							<input type="hidden" class="menu-item-url" name="menu-item[<?php echo esc_attr( (string) $i ); ?>][menu-item-url]" value="<?php echo esc_attr( $preset['url'] ); ?>" />
							<input type="hidden" class="menu-item-classes" name="menu-item[<?php echo esc_attr( (string) $i ); ?>][menu-item-classes]" value="sl-smart-menu-item sl-visibility-<?php echo esc_attr( $preset['visibility'] ); ?>" />
						</li>
						<?php
						--$i;
					endforeach;
					?>
				</ul>
			</div>

			<p class="button-controls" data-items-type="posttype-omniwp-endpoints">
				<span class="list-controls">
					<label>
						<input type="checkbox" class="select-all" />
						<?php esc_html_e( 'Select All', 'omniwp' ); ?>
					</label>
				</span>

				<span class="add-to-menu">
					<button type="submit"
						class="button-secondary submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to Menu', 'omniwp' ); ?>"
						name="add-post-type-menu-item"
						id="submit-posttype-omniwp-endpoints"><?php esc_html_e( 'Add to Menu', 'omniwp' ); ?></button>
					<span class="spinner"></span>
				</span>
			</p>
		</div>

		<?php
	}
}
