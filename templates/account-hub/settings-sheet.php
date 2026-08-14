<?php
/**
 * Settings Bottom Sheet for Mobile Account Hub.
 *
 * @var \WP_User $user
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;
?>

<div class="sl-hub-sheet-backdrop" data-sl-settings-sheet-backdrop style="display: none;" hidden>
	<div class="sl-hub-sheet" data-sl-settings-sheet role="dialog" aria-modal="true" aria-labelledby="sl-sheet-title">
		<div class="sl-hub-sheet__handle-bar">
			<span class="sl-hub-sheet__handle"></span>
		</div>
		<div class="sl-hub-sheet__header">
			<h3 id="sl-sheet-title" class="sl-hub-sheet__title"><?php esc_html_e( 'Cài đặt & Tài khoản', 'omniwp' ); ?></h3>
			<button type="button" class="sl-hub-sheet__close" data-sl-settings-sheet-close aria-label="<?php esc_attr_e( 'Đóng', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
		<div class="sl-hub-sheet__body">
			<ul class="sl-hub-sheet__list">
				<li>
					<a href="#security" class="sl-hub-sheet__item" data-sl-hub-tab="security" data-sl-settings-action="security">
						<div class="sl-hub-sheet__icon-wrap">
							<?php echo IconSet::get( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="sl-hub-sheet__item-content">
							<span class="sl-hub-sheet__item-title"><?php esc_html_e( 'Đăng nhập & Bảo mật', 'omniwp' ); ?></span>
							<span class="sl-hub-sheet__item-desc"><?php esc_html_e( 'Mật khẩu, số điện thoại, email và liên kết tài khoản', 'omniwp' ); ?></span>
						</div>
						<span class="sl-hub-sheet__arrow"><?php echo IconSet::get( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</a>
				</li>
			</ul>

			<div class="sl-hub-sheet__footer">
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="sl-hub-sheet__logout-btn" data-sl-logout-trigger>
					<span class="sl-hub-sheet__logout-icon"><?php echo IconSet::get( 'log-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php esc_html_e( 'Đăng xuất tài khoản', 'omniwp' ); ?></span>
				</a>
			</div>
		</div>
	</div>
</div>
