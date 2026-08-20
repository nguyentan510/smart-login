<?php
/**
 * Custom fields for Smart Menu items in WordPress Nav Menu Editor.
 *
 * @package OmniWP
 */

namespace OmniWP\Admin;

use OmniWP\Navigation\Catalog;
use OmniWP\Navigation\Node;

defined( 'ABSPATH' ) || exit;

final class SmartMenuFields {

	const META_VISIBILITY = '_ow_smart_menu_visibility';
	const META_MODE       = '_ow_smart_menu_mode';

	/**
	 * The device axis: 'all', 'desktop' or 'mobile'.
	 *
	 * Declared in 25.1 for Navigation\MenuProvider to read, and given its control
	 * in 25.3. An absent value means `all`, so an item saved before the control
	 * existed behaves as it always did.
	 *
	 * It is *not* resolved the way META_VISIBILITY is. That one drops the item on
	 * the server, because a page cache varies on the auth cookie; this one is
	 * rendered as a class and hidden by a media query, because no cache varies on
	 * viewport width. docs/navigation.md §3.4 has the argument in full — the two
	 * axes look alike and unifying them re-opens it.
	 */
	const META_DEVICES = '_ow_smart_menu_devices';

	/**
	 * Which navigation provider this item opens a panel from, and where in it.
	 *
	 * Empty means no panel, which is every menu item that existed before Phase
	 * 25 — so a menu saved before this field arrived renders exactly as it did.
	 */
	const META_PANEL      = '_ow_smart_menu_panel';
	const META_PANEL_ROOT = '_ow_smart_menu_panel_root';

	public function register(): void {
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_fields' ), 10, 2 );
	}

	/**
	 * Render custom visibility and mode fields inside the nav menu item accordion in Admin.
	 *
	 * @param int|string $item_id Nav menu item ID.
	 * @param object     $item    Nav menu item object.
	 */
	public function render_fields( $item_id, $item ): void {
		$item_id    = (int) $item_id;
		$visibility = get_post_meta( $item_id, self::META_VISIBILITY, true );
		$mode       = get_post_meta( $item_id, self::META_MODE, true );
		$devices    = (string) get_post_meta( $item_id, self::META_DEVICES, true );
		$panel      = (string) get_post_meta( $item_id, self::META_PANEL, true );
		$panel_root = (string) get_post_meta( $item_id, self::META_PANEL_ROOT, true );

		if ( '' === $visibility ) {
			$visibility = 'everyone';
		}
		if ( '' === $mode ) {
			$mode = 'simple';
		}

		$url = isset( $item->url ) ? (string) $item->url : '';
		if ( false === strpos( $url, '#smart-' ) && empty( $item->classes ) ) {
			// Still render fields for any menu item if desired, or skip if completely unrelated.
		}
		?>
		<p class="field-smart-menu-visibility description description-wide">
			<label for="edit-menu-item-sl-visibility-<?php echo esc_attr( (string) $item_id ); ?>">
				<?php esc_html_e( 'Quyền hiển thị OmniWP', 'omniwp' ); ?><br />
				<select id="edit-menu-item-sl-visibility-<?php echo esc_attr( (string) $item_id ); ?>"
					name="menu-item-sl-visibility[<?php echo esc_attr( (string) $item_id ); ?>]">
					<option value="everyone" <?php selected( $visibility, 'everyone' ); ?>><?php esc_html_e( 'Tất cả mọi người', 'omniwp' ); ?></option>
					<option value="guest" <?php selected( $visibility, 'guest' ); ?>><?php esc_html_e( 'Chỉ khách (Chưa đăng nhập)', 'omniwp' ); ?></option>
					<option value="logged_in" <?php selected( $visibility, 'logged_in' ); ?>><?php esc_html_e( 'Chỉ thành viên (Đã đăng nhập)', 'omniwp' ); ?></option>
				</select>
			</label>
		</p>

		<p class="field-smart-menu-devices description description-wide">
			<label for="edit-menu-item-sl-devices-<?php echo esc_attr( (string) $item_id ); ?>">
				<?php esc_html_e( 'Hiển thị trên thiết bị', 'omniwp' ); ?><br />
				<select id="edit-menu-item-sl-devices-<?php echo esc_attr( (string) $item_id ); ?>"
					name="menu-item-sl-devices[<?php echo esc_attr( (string) $item_id ); ?>]">
					<option value="all" <?php selected( '' === $devices ? 'all' : $devices, 'all' ); ?>><?php esc_html_e( 'Mọi màn hình', 'omniwp' ); ?></option>
					<option value="desktop" <?php selected( $devices, 'desktop' ); ?>><?php esc_html_e( 'Chỉ máy tính', 'omniwp' ); ?></option>
					<option value="mobile" <?php selected( $devices, 'mobile' ); ?>><?php esc_html_e( 'Chỉ điện thoại', 'omniwp' ); ?></option>
				</select>
			</label>
			<span class="description"><?php esc_html_e( 'Mục vẫn được tạo ra trong trang; màn hình nào không hợp thì CSS ẩn đi. Cách này an toàn với bộ nhớ đệm toàn trang.', 'omniwp' ); ?></span>
		</p>

		<p class="field-smart-menu-panel description description-wide">
			<label for="edit-menu-item-sl-panel-<?php echo esc_attr( (string) $item_id ); ?>">
				<?php esc_html_e( 'Bảng danh mục đổ xuống (Mega Menu)', 'omniwp' ); ?><br />
				<select id="edit-menu-item-sl-panel-<?php echo esc_attr( (string) $item_id ); ?>"
					name="menu-item-sl-panel[<?php echo esc_attr( (string) $item_id ); ?>]">
					<option value=""><?php esc_html_e( '— Không có —', 'omniwp' ); ?></option>
					<?php foreach ( Catalog::providers() as $ow_id => $ow_provider ) : ?>
						<option value="<?php echo esc_attr( (string) $ow_id ); ?>" <?php selected( $panel, (string) $ow_id ); ?>>
							<?php echo esc_html( (string) ( $ow_provider['label'] ?? $ow_id ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>

		<p class="field-smart-menu-panel-root description description-wide">
			<label for="edit-menu-item-sl-panel-root-<?php echo esc_attr( (string) $item_id ); ?>">
				<?php esc_html_e( 'Chỉ lấy nhánh (tuỳ chọn)', 'omniwp' ); ?><br />
				<input type="text" class="widefat code"
					id="edit-menu-item-sl-panel-root-<?php echo esc_attr( (string) $item_id ); ?>"
					name="menu-item-sl-panel-root[<?php echo esc_attr( (string) $item_id ); ?>]"
					value="<?php echo esc_attr( $panel_root ); ?>" />
			</label>
			<span class="description"><?php esc_html_e( 'Mã của một mục trong cây, ví dụ product_cat-42. Để trống thì lấy cả cây.', 'omniwp' ); ?></span>
		</p>

		<?php if ( strpos( $url, '#smart-button' ) !== false || strpos( $url, '#smart-auth-switcher' ) !== false || strpos( $url, '#smart-account' ) !== false ) : ?>

		<p class="field-smart-menu-mode description description-wide">
			<label for="edit-menu-item-sl-mode-<?php echo esc_attr( (string) $item_id ); ?>">
				<?php esc_html_e( 'Chế độ hiển thị (Render Mode)', 'omniwp' ); ?><br />
				<select id="edit-menu-item-sl-mode-<?php echo esc_attr( (string) $item_id ); ?>"
					name="menu-item-sl-mode[<?php echo esc_attr( (string) $item_id ); ?>]">
					<option value="simple" <?php selected( $mode, 'simple' ); ?>><?php esc_html_e( 'Simple Link (Liên kết đơn)', 'omniwp' ); ?></option>
					<option value="dropdown" <?php selected( $mode, 'dropdown' ); ?>><?php esc_html_e( 'Rich Account Dropdown (Phase 21)', 'omniwp' ); ?></option>
				</select>
			</label>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save custom menu item fields on menu update.
	 *
	 * @param int $menu_id         Nav menu ID.
	 * @param int $menu_item_db_id Nav menu item DB ID.
	 */
	public function save_fields( $menu_id, $menu_item_db_id ): void {
		$menu_item_db_id = (int) $menu_item_db_id;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['menu-item-sl-visibility'][ $menu_item_db_id ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$vis = sanitize_text_field( wp_unslash( $_POST['menu-item-sl-visibility'][ $menu_item_db_id ] ) );
			if ( in_array( $vis, array( 'everyone', 'guest', 'logged_in' ), true ) ) {
				update_post_meta( $menu_item_db_id, self::META_VISIBILITY, $vis );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['menu-item-sl-devices'][ $menu_item_db_id ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$devices = sanitize_text_field( wp_unslash( $_POST['menu-item-sl-devices'][ $menu_item_db_id ] ) );
			if ( in_array( $devices, Node::DEVICES, true ) ) {
				update_post_meta( $menu_item_db_id, self::META_DEVICES, $devices );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['menu-item-sl-panel'][ $menu_item_db_id ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$panel = sanitize_text_field( wp_unslash( $_POST['menu-item-sl-panel'][ $menu_item_db_id ] ) );

			/*
			 * Checked against the catalog, not against a list written here. A
			 * provider some plugin registered and later withdrew must not stay
			 * saveable, and a second list would have to be kept in step by hand.
			 */
			if ( '' === $panel || Catalog::has( $panel ) ) {
				update_post_meta( $menu_item_db_id, self::META_PANEL, $panel );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['menu-item-sl-panel-root'][ $menu_item_db_id ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$root = sanitize_text_field( wp_unslash( $_POST['menu-item-sl-panel-root'][ $menu_item_db_id ] ) );
			update_post_meta( $menu_item_db_id, self::META_PANEL_ROOT, $root );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['menu-item-sl-mode'][ $menu_item_db_id ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$mode = sanitize_text_field( wp_unslash( $_POST['menu-item-sl-mode'][ $menu_item_db_id ] ) );
			if ( in_array( $mode, array( 'simple', 'dropdown' ), true ) ) {
				update_post_meta( $menu_item_db_id, self::META_MODE, $mode );
			}
		}
	}
}
