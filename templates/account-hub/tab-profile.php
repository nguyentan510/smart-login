<?php
/**
 * Personal Info Tab (Thông tin cá nhân).
 *
 * Uses OmniWP's native AccountForm section: 'profile'.
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
			<span class="sl-hub-title__icon"><?php echo IconSet::get( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span><?php esc_html_e( 'Thông tin cá nhân', 'omniwp' ); ?></span>
		</h2>
		<p class="sl-hub-subtitle"><?php esc_html_e( 'Quản lý thông tin hồ sơ và ngày sinh của bạn.', 'omniwp' ); ?></p>
	</div>
</div>

<form class="sl-form sl-hub-form" method="post" action="">
	<div class="sl-hub-tab-content">
		<?php $ow_form->output_section( 'profile' ); ?>

		<div class="sl-hub-profile-actions">
			<?php wp_nonce_field( 'OMNIWP_save_profile' ); ?>
			<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="save_profile" />
			<input type="hidden" name="_redirect" value="<?php echo esc_url( \OmniWP\Frontend\AccountForm::edit_url( 'profile' ) ); ?>" />
			<button type="reset" class="sl-btn sl-btn--ghost sl-btn--inline"><?php esc_html_e( 'Huỷ', 'omniwp' ); ?></button>
			<button type="submit" class="sl-btn sl-btn--primary sl-btn--inline"><?php esc_html_e( 'Lưu thay đổi', 'omniwp' ); ?></button>
		</div>
	</div>
</form>
