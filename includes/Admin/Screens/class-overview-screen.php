<?php
/**
 * The first screen after installing: is this working yet?
 *
 * Everything here already existed somewhere. The point is that it now exists in
 * one place, before the settings rather than buried inside them, and that a red
 * row links straight to the control that fixes it.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin\Screens;

use SmartLogin\Admin\Readiness;
use SmartLogin\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

final class OverviewScreen {

	public function render(): void {
		$readiness = new Readiness();
		$checks    = $readiness->checks();
		$ready     = $readiness->is_ready();
		?>
		<div class="wrap smart-login-admin">
			<h1><?php esc_html_e( 'Smart Login', 'smart-login' ); ?></h1>

			<?php SettingsPage::nav( SettingsPage::OVERVIEW ); ?>

			<div class="sl-readiness-banner <?php echo $ready ? 'is-ready' : 'is-blocked'; ?>">
				<?php if ( $ready ) : ?>
					<h2><?php esc_html_e( 'Sẵn sàng hoạt động', 'smart-login' ); ?></h2>
					<p><?php esc_html_e( 'Người dùng có thể đăng ký và đăng nhập ngay bây giờ.', 'smart-login' ); ?></p>
				<?php else : ?>
					<h2><?php esc_html_e( 'Chưa chạy được', 'smart-login' ); ?></h2>
					<p><?php esc_html_e( 'Những mục màu đỏ bên dưới đang chặn luồng đăng nhập. Sửa xong là dùng được.', 'smart-login' ); ?></p>
				<?php endif; ?>
			</div>

			<table class="widefat striped sl-readiness">
				<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr class="sl-readiness__row is-<?php echo esc_attr( $check['status'] ); ?>">
						<td class="sl-readiness__mark" aria-hidden="true"><?php echo esc_html( self::mark( $check['status'] ) ); ?></td>
						<td class="sl-readiness__label">
							<strong><?php echo esc_html( $check['label'] ); ?></strong>
							<span class="screen-reader-text"><?php echo esc_html( self::status_label( $check['status'] ) ); ?></span>
						</td>
						<td class="sl-readiness__detail"><?php echo esc_html( $check['detail'] ); ?></td>
						<td class="sl-readiness__action">
							<?php if ( Readiness::OK !== $check['status'] && Readiness::OFF !== $check['status'] ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $check['action'] ); ?>">
									<?php echo esc_html( $check['action_label'] ); ?>
								</a>
							<?php else : ?>
								<a class="button-link" href="<?php echo esc_url( $check['action'] ); ?>">
									<?php esc_html_e( 'Xem', 'smart-login' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function mark( string $status ): string {
		switch ( $status ) {
			case Readiness::OK:
				return '✔';

			case Readiness::FAIL:
				return '✕';

			case Readiness::WARN:
				return '!';

			default:
				return '–';
		}
	}

	/**
	 * The glyph above is decorative, so the state has to be readable some other
	 * way for anyone not looking at the colour.
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case Readiness::OK:
				return __( 'Đạt', 'smart-login' );

			case Readiness::FAIL:
				return __( 'Đang chặn', 'smart-login' );

			case Readiness::WARN:
				return __( 'Cảnh báo', 'smart-login' );

			default:
				return __( 'Không dùng', 'smart-login' );
		}
	}
}
