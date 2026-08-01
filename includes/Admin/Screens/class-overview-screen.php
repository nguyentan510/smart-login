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

	/** admin-post action behind the "resume sending" button. */
	const RESUME_ACTION = 'smart_login_resume_sending';

	public function register(): void {
		add_action( 'admin_post_' . self::RESUME_ACTION, array( $this, 'handle_resume' ) );
	}

	/**
	 * Clear the kill switch by hand.
	 *
	 * The switch shipped in 9.1 without an off switch: RateLimiter::resume()
	 * existed and was tested, and nothing called it. A halt clears itself after
	 * security.halt_minutes, but the thresholds that trip it were chosen without
	 * any site's traffic to measure, so the first person to meet one may well be
	 * a legitimate campaign rather than an attacker.
	 */
	public function handle_resume(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện việc này.', 'smart-login' ) );
		}

		check_admin_referer( self::RESUME_ACTION );

		\SmartLogin\Security\RateLimiter::resume();

		wp_safe_redirect(
			add_query_arg(
				'sl_resumed',
				'1',
				admin_url( 'admin.php?page=' . SettingsPage::SLUG )
			)
		);
		exit;
	}

	public function render(): void {
		$readiness = new Readiness();
		$checks    = $readiness->checks();
		$ready     = $readiness->is_ready();
		$halted    = ( new \SmartLogin\Security\RateLimiter() )->halted_for();
		?>
		<div class="wrap smart-login-admin">
			<h1><?php esc_html_e( 'Smart Login', 'smart-login' ); ?></h1>

			<?php SettingsPage::nav( SettingsPage::OVERVIEW ); ?>

			<?php if ( isset( $_GET['sl_resumed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Đã mở lại việc gửi mã.', 'smart-login' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $halted > 0 ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %d: minutes remaining. */
							esc_html__( 'Việc gửi mã đang tạm dừng vì chạm trần toàn site. Tự mở lại sau %d phút.', 'smart-login' ),
							(int) max( 1, ceil( $halted / MINUTE_IN_SECONDS ) )
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::RESUME_ACTION ); ?>" />
						<?php wp_nonce_field( self::RESUME_ACTION ); ?>
						<p>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Mở lại việc gửi mã', 'smart-login' ); ?>
							</button>
							<span class="description">
								<?php esc_html_e( 'Chỉ làm việc này khi bạn đã xem nhật ký và tin rằng lưu lượng vừa rồi là thật.', 'smart-login' ); ?>
							</span>
						</p>
					</form>
				</div>
			<?php endif; ?>

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
