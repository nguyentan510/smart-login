<?php
/**
 * Profile completeness suggestion banner — lightweight inline chips strip.
 *
 * Designed as a subtle, non-intrusive suggestion strip that takes minimal space.
 *
 * @var array  $ow_status   Output of ProfileCompletionService::status()
 * @var array  $ow_pending  Output of ContactVerificationService::pending()
 * @var bool   $ow_welcome
 * @var string $ow_edit_url Empty on a surface that already edits.
 *
 * @package OmniWP
 */

use OmniWP\Auth\ProfileCompletionService;
use OmniWP\Frontend\IconSet;
use OmniWP\Identity\UserManager;

defined( 'ABSPATH' ) || exit;

$ow_status   = isset( $ow_status ) && is_array( $ow_status ) ? $ow_status : array();
$ow_pending  = isset( $ow_pending ) && is_array( $ow_pending ) ? $ow_pending : array();
$ow_welcome  = ! empty( $ow_welcome );
$ow_edit_url = isset( $ow_edit_url ) ? (string) $ow_edit_url : '';
$ow_required = $ow_status['required_missing'] ?? array();
$ow_optional = $ow_status['recommended_missing'] ?? array();

$ow_reasons = ProfileCompletionService::onboarding_reasons();

$ow_total = (int) ( $ow_status['total'] ?? 0 );
$ow_done  = (int) ( $ow_status['done'] ?? 0 );

if ( ! empty( $ow_required ) ) {
	$ow_missing = $ow_required;
	$ow_kind    = 'warning';
	$ow_heading = __( 'Cần bổ sung:', 'omniwp' );
} elseif ( ! empty( $ow_optional ) ) {
	$ow_missing = $ow_optional;
	$ow_kind    = 'info';
	$ow_heading = __( 'Gợi ý hoàn thiện:', 'omniwp' );
} else {
	$ow_missing = array();
	$ow_kind    = '';
	$ow_heading = '';
}
?>

<?php if ( $ow_welcome && ! empty( $ow_status['complete'] ) && empty( $ow_optional ) ) : ?>
	<div class="sl-notice sl-notice--success sl-notice--compact">
		<?php esc_html_e( 'Hồ sơ của bạn đã đầy đủ. Bạn có thể tiếp tục sử dụng hệ thống.', 'omniwp' ); ?>
	</div>
<?php elseif ( $ow_welcome ) : ?>
	<div class="sl-notice sl-notice--success sl-notice--compact">
		<?php esc_html_e( 'Chào mừng bạn! Hãy bổ sung thông tin để nhận đầy đủ ưu đãi hội viên.', 'omniwp' ); ?>
	</div>
<?php endif; ?>

<?php if ( is_user_logged_in() && UserManager::user_has_synthetic_email( get_current_user_id() ) ) : ?>
	<div class="sl-notice sl-notice--info sl-notice--promo-email" style="display: flex !important; flex-direction: row !important; flex-wrap: wrap !important; align-items: center !important; justify-content: space-between !important; gap: 14px !important; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important; border: 1px solid #e2e8f0 !important; border-radius: 10px !important; padding: 14px 18px !important; margin-bottom: 16px !important; width: 100% !important; box-sizing: border-box !important;">
		<div style="display: flex !important; flex-direction: row !important; align-items: center !important; gap: 12px !important; flex: 1 1 280px !important; min-width: 0 !important;">
			<span style="display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 38px !important; height: 38px !important; border-radius: 50% !important; background: #ffffff !important; color: #2563eb !important; border: 1px solid #e2e8f0 !important; flex-shrink: 0 !important; box-shadow: 0 1px 2px rgba(0,0,0,0.03) !important;">
				<?php echo IconSet::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<div style="flex: 1 !important; min-width: 0 !important;">
				<strong style="color: #1e293b !important; display: block !important; font-size: 0.95rem !important; margin-bottom: 3px !important; line-height: 1.3 !important;">
					<?php esc_html_e( 'Vui lòng bổ sung Email để nhận Hóa đơn & Ưu đãi', 'omniwp' ); ?>
				</strong>
				<span style="font-size: 0.85rem !important; color: #64748b !important; line-height: 1.4 !important; display: block !important;">
					<?php esc_html_e( 'Cập nhật email cá nhân để nhận Hóa đơn GTGT điện tử và các Chương trình khuyến mãi đặc quyền dành riêng cho bạn.', 'omniwp' ); ?>
				</span>
			</div>
		</div>
		<a href="#contact" data-sl-target="email" style="display: inline-flex !important; align-items: center !important; justify-content: center !important; width: auto !important; max-width: max-content !important; white-space: nowrap !important; flex-shrink: 0 !important; background: #2563eb !important; color: #ffffff !important; border-radius: 6px !important; padding: 9px 18px !important; text-decoration: none !important; font-weight: 600 !important; font-size: 0.85rem !important; line-height: 1.2 !important; box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important;">
			<?php esc_html_e( 'Thêm Email ngay', 'omniwp' ); ?>
		</a>
	</div>
<?php endif; ?>

<?php
$ow_tab_map = array(
	'dob'        => '#profile',
	'gender'     => '#profile',
	'name'       => '#profile',
	'first_name' => '#profile',
	'last_name'  => '#profile',
	'address'    => '#address',
	'email'      => '#contact',
	'phone'      => '#contact',
	'password'   => '#security',
);

$ow_icon_map = array(
	'dob'        => 'calendar',
	'gender'     => 'user',
	'name'       => 'user',
	'first_name' => 'user',
	'last_name'  => 'user',
	'address'    => 'map-pin',
	'email'      => 'mail',
	'phone'      => 'phone',
	'password'   => 'lock',
);
?>

<?php if ( ! empty( $ow_missing ) ) : ?>
	<div class="sl-suggest-strip sl-suggest-strip--<?php echo esc_attr( $ow_kind ); ?>">
		<div class="sl-suggest-strip__lead">
			<span class="sl-suggest-strip__icon">
				<?php echo 'warning' === $ow_kind ? '⚠️' : '💡'; ?>
			</span>
			<span class="sl-suggest-strip__label"><?php echo esc_html( $ow_heading ); ?></span>
		</div>

		<div class="sl-suggest-strip__chips">
			<?php foreach ( $ow_missing as $ow_item ) : ?>
				<?php
				$ow_item_key   = (string) ( $ow_item['key'] ?? '' );
				$ow_reason     = (string) ( $ow_reasons[ $ow_item_key ] ?? '' );
				$ow_tab_target = $ow_tab_map[ $ow_item_key ] ?? '#profile';
				$ow_icon_name  = $ow_icon_map[ $ow_item_key ] ?? 'user';
				$ow_label      = (string) ( $ow_item['label'] ?? '' );
				?>
				<a
					href="<?php echo esc_attr( $ow_tab_target ); ?>"
					class="sl-suggest-chip"
					data-sl-target="<?php echo esc_attr( $ow_item_key ); ?>"
					<?php if ( '' !== $ow_reason ) : ?>
						title="<?php echo esc_attr( $ow_reason ); ?>"
					<?php endif; ?>
				>
					<span class="sl-suggest-chip__icon">
						<?php echo IconSet::get( $ow_icon_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<span class="sl-suggest-chip__text"><?php echo esc_html( $ow_label ); ?></span>
					<span class="sl-suggest-chip__add" aria-hidden="true">+</span>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( $ow_total > 0 ) : ?>
			<?php
			/* translators: 1: Completed count, 2: Total count */
			$ratio_text = sprintf( __( '%1$d/%2$d', 'omniwp' ), (int) $ow_done, (int) $ow_total );
			?>
			<div class="sl-suggest-strip__meta">
				<span class="sl-suggest-strip__ratio"><?php echo esc_html( $ratio_text ); ?></span>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $ow_pending ) ) : ?>
	<div class="sl-notice sl-notice--info sl-notice--compact" data-sl-pending-banner>
		<?php
		if ( '' !== $ow_edit_url ) {
			printf(
				/* translators: 1: contact type, 2: masked destination. */
				esc_html__( 'Đang chờ xác thực %1$s: %2$s. Mở trang chỉnh sửa hồ sơ để nhập mã OTP.', 'omniwp' ),
				'phone' === ( $ow_pending['type'] ?? '' ) ? esc_html__( 'số điện thoại', 'omniwp' ) : esc_html__( 'email', 'omniwp' ),
				esc_html( $ow_pending['masked'] ?? '' )
			);
		} else {
			printf(
				/* translators: 1: contact type, 2: masked destination. */
				esc_html__( 'Đang chờ xác thực %1$s: %2$s. Bạn có thể gửi lại một mã mới bên dưới.', 'omniwp' ),
				'phone' === ( $ow_pending['type'] ?? '' ) ? esc_html__( 'số điện thoại', 'omniwp' ) : esc_html__( 'email', 'omniwp' ),
				esc_html( $ow_pending['masked'] ?? '' )
			);
		}
		?>
	</div>
<?php endif; ?>
