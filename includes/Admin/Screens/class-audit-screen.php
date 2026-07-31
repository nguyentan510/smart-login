<?php
/**
 * The authentication log.
 *
 * Moved out of SettingsPage unchanged. It shares nothing with the settings
 * screens beyond the menu it hangs off, and leaving it inside a class that was
 * already doing seven other jobs was most of why that class was 1100 lines.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin\Screens;

use SmartLogin\Security\AuditLog;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class AuditScreen {

	const PER_PAGE = 50;

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only pagination.
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$rows   = AuditLog::recent( self::PER_PAGE, $offset );
		?>
		<div class="wrap smart-login-admin">
			<h1><?php esc_html_e( 'Nhật ký Smart Login', 'smart-login' ); ?></h1>

			<?php if ( ! Settings::is_on( 'advanced.audit_enabled' ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Ghi nhật ký đang tắt. Bật ở tab Nâng cao để bắt đầu ghi.', 'smart-login' ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Thời gian (UTC)', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Sự kiện', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Định danh', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'IP', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Chi tiết', 'smart-login' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Chưa có bản ghi nào.', 'smart-login' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><code><?php echo esc_html( $row['event'] ); ?></code></td>
						<td><?php echo esc_html( $row['identity_masked'] ); ?></td>
						<td><?php echo esc_html( $row['ip'] ? (string) @inet_ntop( $row['ip'] ) : '—' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors ?></td>
						<td><code><?php echo esc_html( (string) $row['meta'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="tablenav">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Trước', 'smart-login' ); ?></a>
				<?php endif; ?>
				<?php if ( count( $rows ) === self::PER_PAGE ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>"><?php esc_html_e( 'Sau', 'smart-login' ); ?> &raquo;</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
