<?php
/**
 * Logout Confirmation Modal.
 *
 * @var \WP_User $user
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$logout_url = wp_logout_url( home_url( '/' ) );
?>

<div class="sl-logout-modal-backdrop" data-sl-logout-modal role="dialog" aria-modal="true" aria-labelledby="ow_logout_title">
	<div class="sl-logout-modal">
		<div class="sl-logout-modal__icon">
			<?php echo IconSet::get( 'log-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<h3 id="ow_logout_title" class="sl-logout-modal__title"><?php esc_html_e( 'Xác nhận đăng xuất', 'omniwp' ); ?></h3>

		<p class="sl-logout-modal__message">
			<?php esc_html_e( 'Bạn có chắc chắn muốn đăng xuất khỏi tài khoản không?', 'omniwp' ); ?>
		</p>

		<div class="sl-logout-modal__actions">
			<button type="button" class="sl-logout-modal__btn sl-logout-modal__btn--cancel sl-btn" data-sl-logout-cancel>
				<?php esc_html_e( 'Hủy bỏ', 'omniwp' ); ?>
			</button>
			<a href="<?php echo esc_url( $logout_url ); ?>" class="sl-logout-modal__btn sl-logout-modal__btn--confirm sl-btn">
				<?php esc_html_e( 'Đăng xuất ngay', 'omniwp' ); ?>
			</a>
		</div>
	</div>
</div>
