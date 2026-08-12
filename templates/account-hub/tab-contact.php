<?php
/**
 * Contact & Login Tab (Đăng nhập & liên hệ).
 *
 * Uses OmniWP's native AccountForm section: 'contact'
 * (includes Phone, Email, OTP verification, and linked social providers).
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
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Đăng nhập & liên hệ', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Quản lý số điện thoại, email và các tài khoản liên kết mạng xã hội.', 'omniwp' ); ?></p>
	</div>
</div>

<div class="sl-hub-tab-content">
	<?php $ow_form->output_section( 'contact' ); ?>
</div>
