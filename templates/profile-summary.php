<?php
/**
 * Profile summary with the "complete your profile" nudge.
 * Override at yourtheme/smart-login/profile-summary.php
 *
 * @var WP_User  $user
 * @var array    $notices
 * @var string[] $missing
 * @var string   $phone
 * @var bool     $synthetic
 * @var bool     $welcome
 * @var array    $status
 * @var array    $pending
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Identity\Phone;
use SmartLogin\Auth\ProviderAuthController;
use SmartLogin\Auth\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

$sl_edit = function_exists( 'wc_get_account_endpoint_url' )
	? wc_get_account_endpoint_url( 'edit-account' )
	: admin_url( 'profile.php' );
?>
<div class="smart-login smart-login--profile">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php if ( $welcome && ! empty( $status['complete'] ) && empty( $status['recommended_missing'] ) ) : ?>
		<div class="sl-notice sl-notice--success">
			<?php esc_html_e( 'Hồ sơ của bạn đã đầy đủ. Bạn có thể tiếp tục sử dụng hệ thống.', 'smart-login' ); ?>
		</div>
	<?php elseif ( $welcome ) : ?>
		<div class="sl-notice sl-notice--success">
			<?php esc_html_e( 'Chào mừng bạn! Hãy bổ sung thông tin để nhận đầy đủ ưu đãi hội viên.', 'smart-login' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $status['required_missing'] ) ) : ?>
		<div class="sl-notice sl-notice--warning">
			<strong><?php esc_html_e( 'Thông tin bắt buộc còn thiếu', 'smart-login' ); ?></strong>
			<p>
					<?php echo esc_html( implode( ', ', wp_list_pluck( $status['required_missing'], 'label' ) ) ); ?>
			</p>
			<a class="sl-link" href="<?php echo esc_url( $sl_edit ); ?>"><?php esc_html_e( 'Cập nhật ngay', 'smart-login' ); ?></a>
		</div>
	<?php elseif ( ! empty( $status['recommended_missing'] ) ) : ?>
		<div class="sl-notice sl-notice--info">
			<strong><?php esc_html_e( 'Bạn có thể bổ sung thêm', 'smart-login' ); ?></strong>
			<p><?php echo esc_html( implode( ', ', wp_list_pluck( $status['recommended_missing'], 'label' ) ) ); ?></p>
			<a class="sl-link" href="<?php echo esc_url( $sl_edit ); ?>"><?php esc_html_e( 'Bổ sung thông tin', 'smart-login' ); ?></a>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $pending ) ) : ?>
		<div class="sl-notice sl-notice--info">
			<?php
			printf(
				/* translators: 1: contact type, 2: masked destination. */
				esc_html__( 'Đang chờ xác thực %1$s: %2$s. Mở trang chỉnh sửa hồ sơ để nhập mã OTP.', 'smart-login' ),
				'phone' === ( $pending['type'] ?? '' ) ? esc_html__( 'số điện thoại', 'smart-login' ) : esc_html__( 'email', 'smart-login' ),
				esc_html( $pending['masked'] ?? '' )
			);
			?>
		</div>
	<?php endif; ?>

	<?php
	// What is already linked, and how to remove one.
	$sl_link_service = new \SmartLogin\Auth\IdentityLinkService();
	$sl_user_id      = get_current_user_id();

	TemplateLoader::output(
		'partials/linked-identities',
		array(
			'sl_identities' => $sl_link_service->linked( $sl_user_id ),
			'sl_can_unlink' => $sl_link_service->can_unlink( $sl_user_id ),
			// Back to the page hosting the shortcode, so the notice lands in view.
			'sl_redirect'   => get_permalink() ?: home_url( '/' ),
		)
	);

	// Only offer providers that are not linked yet — offering "link Google" to
	// somebody whose Google is already linked was the old behaviour and it told
	// them nothing.
	$sl_available_ids  = array_map(
		static fn( $sl_provider ): string => $sl_provider->id(),
		( new ProviderRegistry() )->available()
	);
	$sl_offerable      = $sl_link_service->unlinked_providers( $sl_user_id, $sl_available_ids );
	$sl_link_providers = array_filter(
		( new ProviderRegistry() )->available(),
		static fn( $sl_provider ): bool => in_array( $sl_provider->id(), $sl_offerable, true )
	);
	?>
	<?php if ( ! empty( $sl_link_providers ) ) : ?>
		<div class="sl-notice sl-notice--info">
			<strong><?php esc_html_e( 'Liên kết đăng nhập nhanh', 'smart-login' ); ?></strong>
			<div class="sl-provider-buttons">
				<?php foreach ( $sl_link_providers as $sl_link_provider ) : ?>
					<a
						class="sl-btn sl-btn--outline"
						href="<?php echo esc_url( ProviderAuthController::start_url( $sl_link_provider->id(), '', true ) ); ?>"
						data-sl-provider="<?php echo esc_attr( $sl_link_provider->id() ); ?>"
						data-sl-provider-mode="link"
					>
						<?php echo esc_html( $sl_link_provider->label() ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<dl class="sl-profile-list">
		<dt><?php esc_html_e( 'Họ tên', 'smart-login' ); ?></dt>
		<dd><?php echo esc_html( $user->display_name ); ?></dd>

		<?php if ( '' !== $phone ) : ?>
			<dt><?php esc_html_e( 'Số điện thoại', 'smart-login' ); ?></dt>
			<dd><?php echo esc_html( Phone::to_local( $phone ) ); ?></dd>
		<?php endif; ?>

		<dt><?php esc_html_e( 'Email', 'smart-login' ); ?></dt>
		<dd>
			<?php if ( $synthetic ) : ?>
				<em class="sl-muted"><?php esc_html_e( 'Chưa cung cấp', 'smart-login' ); ?></em>
			<?php else : ?>
				<?php echo esc_html( $user->user_email ); ?>
			<?php endif; ?>
		</dd>
	</dl>

	<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( $sl_edit ); ?>">
		<?php esc_html_e( 'Chỉnh sửa thông tin', 'smart-login' ); ?>
	</a>
</div>
