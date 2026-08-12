<?php
/**
 * Security Tab (Mật khẩu & Bảo mật).
 *
 * Uses OmniWP's native AccountForm section: 'password'.
 *
 * @var \WP_User                      $user
 * @var \OmniWP\Frontend\AccountForm $ow_form
 * @var array                         $tab
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>

<div class="sl-hub-header">
	<div class="sl-hub-header__meta">
		<h2 class="sl-hub-title">
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Mật khẩu & Bảo mật', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Cập nhật mật khẩu đăng nhập và quản lý phiên bảo mật tài khoản.', 'omniwp' ); ?></p>
	</div>
</div>

<div class="sl-hub-tab-content">
	<?php $ow_form->output_section( 'password' ); ?>
</div>
