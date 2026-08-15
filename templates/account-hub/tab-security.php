<?php
/**
 * Security & Login Tab (Đăng nhập & Bảo mật).
 *
 * Uses OmniWP's native AccountForm sections: 'contact' and 'password'.
 *
 * @var \WP_User                      $user
 * @var \OmniWP\Frontend\AccountForm $ow_form
 * @var array                         $tab
 *
 * @package OmniWP
 */

use OmniWP\Frontend\FormController;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>

<div class="sl-hub-header">
	<div class="sl-hub-header__meta">
		<h2 class="sl-hub-title">
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Đăng nhập & Bảo mật', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Quản lý số điện thoại, email, tài khoản liên kết và mật khẩu bảo mật.', 'omniwp' ); ?></p>
	</div>
</div>

<form class="sl-form sl-hub-form" method="post" action="">
	<div class="sl-hub-tab-content">
		<?php $ow_form->output_section( 'contact' ); ?>
		<?php $ow_form->output_section( 'password' ); ?>

		<div class="sl-hub-profile-actions">
			<?php wp_nonce_field( 'OMNIWP_save_profile' ); ?>
			<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="save_profile" />
			<input type="hidden" name="_redirect" value="<?php echo esc_url( \OmniWP\Frontend\AccountForm::edit_url( 'security' ) ); ?>" />
			<button type="reset" class="sl-btn sl-btn--ghost sl-btn--inline"><?php esc_html_e( 'Huỷ', 'omniwp' ); ?></button>
			<button type="submit" class="sl-btn sl-btn--primary sl-btn--inline"><?php esc_html_e( 'Cập nhật bảo mật', 'omniwp' ); ?></button>
		</div>
	</div>
</form>

