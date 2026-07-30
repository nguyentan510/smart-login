<?php
/**
 * Replaces WooCommerce's myaccount/form-edit-account.php.
 *
 * Same field set as the reference design: Họ tên, Số điện thoại (read-only),
 * Email, Giới tính, Ngày sinh — plus WooCommerce's own password section and
 * every hook third-party plugins rely on.
 *
 * Override at yourtheme/woocommerce/myaccount/form-edit-account.php
 *
 * @package SmartLogin
 */

use SmartLogin\Address\AddressFields;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\UserManager;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

$sl_user      = get_user_by( 'id', get_current_user_id() );
$sl_phone     = (string) get_user_meta( $sl_user->ID, UserManager::META_PHONE, true );
$sl_synthetic = UserManager::is_synthetic_email( $sl_user->user_email );
$sl_dob       = (string) get_user_meta( $sl_user->ID, UserManager::META_DOB, true );
$sl_gender    = (string) get_user_meta( $sl_user->ID, UserManager::META_GENDER, true );
$sl_referral  = (string) get_user_meta( $sl_user->ID, UserManager::META_REFERRAL, true );
$sl_missing   = UserManager::missing_profile_fields( $sl_user->ID );
$sl_status    = ( new \SmartLogin\Auth\ProfileCompletionService() )->status( $sl_user->ID );
$sl_pending   = ( new \SmartLogin\Auth\ContactVerificationService() )->pending( $sl_user->ID );
$sl_welcome   = ! empty( $_GET['smartlogin_welcome'] ); // phpcs:ignore WordPress.Security.NonceVerification

if ( '' !== $sl_dob ) {
	$sl_dob = gmdate( 'd/m/Y', strtotime( $sl_dob ) );
}

do_action( 'woocommerce_before_edit_account_form' );
?>

<div class="smart-login smart-login--account">

	<?php if ( $sl_welcome && ! empty( $sl_status['complete'] ) && empty( $sl_status['recommended_missing'] ) ) : ?>
		<div class="sl-notice sl-notice--success">
			<?php esc_html_e( 'Hồ sơ của bạn đã đầy đủ. Bạn có thể tiếp tục sử dụng hệ thống.', 'smart-login' ); ?>
		</div>
	<?php elseif ( $sl_welcome ) : ?>
		<div class="sl-notice sl-notice--success">
			<?php esc_html_e( 'Chào mừng bạn! Hãy bổ sung thông tin để nhận đầy đủ ưu đãi hội viên.', 'smart-login' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $sl_status['required_missing'] ) ) : ?>
		<div class="sl-notice sl-notice--warning">
			<?php
			printf(
				/* translators: %s: comma-separated list of field labels. */
				esc_html__( 'Thông tin bắt buộc còn thiếu: %s', 'smart-login' ),
				esc_html( implode( ', ', wp_list_pluck( $sl_status['required_missing'], 'label' ) ) )
			);
			?>
		</div>
	<?php elseif ( ! empty( $sl_status['recommended_missing'] ) ) : ?>
		<div class="sl-notice sl-notice--info">
			<?php echo esc_html( implode( ', ', wp_list_pluck( $sl_status['recommended_missing'], 'label' ) ) ); ?>
		</div>
	<?php endif; ?>

	<form class="woocommerce-EditAccountForm edit-account sl-form" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?> >

		<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

		<div class="sl-field">
			<label class="sl-label" for="smartlogin_full_name">
				<?php esc_html_e( 'Họ tên', 'smart-login' ); ?><span class="sl-required">*</span>
			</label>
			<input
				type="text"
				class="sl-input"
				name="smartlogin_full_name"
				id="smartlogin_full_name"
				value="<?php echo esc_attr( $sl_user->display_name ); ?>"
				autocomplete="name"
				required
			/>
		</div>

		<?php if ( '' !== $sl_phone ) : ?>
			<div class="sl-field">
				<label class="sl-label" for="smartlogin_phone_display"><?php esc_html_e( 'Số điện thoại', 'smart-login' ); ?></label>
				<input
					type="text"
					class="sl-input"
					id="smartlogin_phone_display"
					value="<?php echo esc_attr( Phone::to_local( $sl_phone ) ); ?>"
					readonly
					disabled
				/>
				<p class="sl-hint"><?php esc_html_e( 'Số điện thoại đã xác thực, không thể tự thay đổi.', 'smart-login' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="sl-field">
			<label class="sl-label" for="account_email">
				<?php esc_html_e( 'Email', 'smart-login' ); ?>
				<?php if ( ! Settings::is_on( 'field_email_optional' ) ) : ?>
					<span class="sl-required">*</span>
				<?php endif; ?>
			</label>
			<input
				type="email"
				class="sl-input"
				name="account_email"
				id="account_email"
				value="<?php echo $sl_synthetic ? '' : esc_attr( $sl_user->user_email ); ?>"
				placeholder="<?php esc_attr_e( 'Nhập email', 'smart-login' ); ?>"
				autocomplete="email"
				readonly
			/>
			<p class="sl-hint"><?php esc_html_e( 'Để thêm hoặc đổi email, hãy dùng bước xác thực OTP bên dưới.', 'smart-login' ); ?></p>
		</div>

		<hr class="sl-separator" />
		<p class="sl-lead"><?php esc_html_e( 'Thay đổi thông tin liên hệ', 'smart-login' ); ?></p>
		<p class="sl-hint"><?php esc_html_e( 'Số điện thoại hoặc email mới chỉ được lưu sau khi mã OTP hợp lệ.', 'smart-login' ); ?></p>

		<?php if ( ! empty( $sl_pending ) ) : ?>
			<div class="sl-notice sl-notice--info">
				<?php
				printf(
					/* translators: 1: contact type, 2: masked destination. */
					esc_html__( 'Đang chờ xác thực %1$s: %2$s. Bạn có thể gửi lại một mã mới bên dưới.', 'smart-login' ),
					'phone' === $sl_pending['type'] ? esc_html__( 'số điện thoại', 'smart-login' ) : esc_html__( 'email', 'smart-login' ),
					esc_html( $sl_pending['masked'] )
				);
				?>
			</div>
		<?php endif; ?>

		<div class="sl-contact-grid">
			<div class="sl-contact-verification" data-sl-contact="phone">
				<label class="sl-label" for="sl-contact-phone"><?php esc_html_e( 'Số điện thoại mới', 'smart-login' ); ?></label>
				<div class="sl-contact-row">
					<input type="tel" class="sl-input" id="sl-contact-phone" data-sl-contact-value autocomplete="tel" />
					<button type="button" class="sl-btn sl-btn--outline" data-sl-contact-start><?php esc_html_e( 'Gửi OTP', 'smart-login' ); ?></button>
				</div>
				<div class="sl-contact-confirm" data-sl-contact-confirm hidden>
					<p class="sl-hint" data-sl-contact-masked></p>
					<div class="sl-contact-row">
						<input type="text" class="sl-input" data-sl-contact-code inputmode="numeric" autocomplete="one-time-code" maxlength="<?php echo esc_attr( Settings::get_int( 'otp_length', 6 ) ); ?>" placeholder="<?php esc_attr_e( 'Mã OTP', 'smart-login' ); ?>" />
						<button type="button" class="sl-btn sl-btn--primary" data-sl-contact-verify><?php esc_html_e( 'Xác thực', 'smart-login' ); ?></button>
					</div>
					<button type="button" class="sl-link sl-link--button" data-sl-contact-resend><?php esc_html_e( 'Gửi lại mã', 'smart-login' ); ?></button>
				</div>
				<p class="sl-contact-status" data-sl-contact-status aria-live="polite"></p>
			</div>

			<div class="sl-contact-verification" data-sl-contact="email">
				<label class="sl-label" for="sl-contact-email"><?php esc_html_e( 'Email mới', 'smart-login' ); ?></label>
				<div class="sl-contact-row">
					<input type="email" class="sl-input" id="sl-contact-email" data-sl-contact-value autocomplete="email" />
					<button type="button" class="sl-btn sl-btn--outline" data-sl-contact-start><?php esc_html_e( 'Gửi OTP', 'smart-login' ); ?></button>
				</div>
				<div class="sl-contact-confirm" data-sl-contact-confirm hidden>
					<p class="sl-hint" data-sl-contact-masked></p>
					<div class="sl-contact-row">
						<input type="text" class="sl-input" data-sl-contact-code inputmode="numeric" autocomplete="one-time-code" maxlength="<?php echo esc_attr( Settings::get_int( 'otp_length', 6 ) ); ?>" placeholder="<?php esc_attr_e( 'Mã OTP', 'smart-login' ); ?>" />
						<button type="button" class="sl-btn sl-btn--primary" data-sl-contact-verify><?php esc_html_e( 'Xác thực', 'smart-login' ); ?></button>
					</div>
					<button type="button" class="sl-link sl-link--button" data-sl-contact-resend><?php esc_html_e( 'Gửi lại mã', 'smart-login' ); ?></button>
				</div>
				<p class="sl-contact-status" data-sl-contact-status aria-live="polite"></p>
			</div>
		</div>

		<?php $sl_link_providers = ( new \SmartLogin\Auth\Providers\ProviderRegistry() )->available(); ?>
		<?php if ( ! empty( $sl_link_providers ) ) : ?>
			<div class="sl-notice sl-notice--info">
				<strong><?php esc_html_e( 'Liên kết đăng nhập nhanh', 'smart-login' ); ?></strong>
				<div class="sl-provider-buttons">
					<?php foreach ( $sl_link_providers as $sl_link_provider ) : ?>
						<a
							class="sl-btn sl-btn--outline"
							href="<?php echo esc_url( \SmartLogin\Auth\ProviderAuthController::start_url( $sl_link_provider->id(), '', true ) ); ?>"
							data-sl-provider="<?php echo esc_attr( $sl_link_provider->id() ); ?>"
							data-sl-provider-mode="link"
						>
							<?php echo esc_html( $sl_link_provider->label() ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( Settings::is_on( 'field_gender' ) || Settings::is_on( 'field_dob' ) || Settings::is_on( 'field_referral' ) ) : ?>
			<hr class="sl-separator" />
			<p class="sl-lead"><?php esc_html_e( 'Thông tin bổ sung', 'smart-login' ); ?></p>
			<p class="sl-hint"><?php esc_html_e( 'Các thông tin dưới đây không bắt buộc và có thể bổ sung sau.', 'smart-login' ); ?></p>

			<?php if ( Settings::is_on( 'field_gender' ) ) : ?>
				<fieldset class="sl-field sl-field--radio">
					<legend class="sl-label"><?php esc_html_e( 'Giới tính', 'smart-login' ); ?></legend>
					<?php
					foreach ( array(
						'female' => __( 'Nữ', 'smart-login' ),
						'male'   => __( 'Nam', 'smart-login' ),
						'other'  => __( 'Khác', 'smart-login' ),
					) as $sl_value => $sl_label ) :
						?>
						<label class="sl-radio">
							<input type="radio" name="smartlogin_gender" value="<?php echo esc_attr( $sl_value ); ?>" <?php checked( $sl_gender, $sl_value ); ?> />
							<span><?php echo esc_html( $sl_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( Settings::is_on( 'field_dob' ) ) : ?>
				<div class="sl-field">
					<label class="sl-label" for="sl-dob"><?php esc_html_e( 'Ngày sinh', 'smart-login' ); ?></label>
					<input
						type="text"
						class="sl-input"
						name="smartlogin_dob"
						id="sl-dob"
						value="<?php echo esc_attr( $sl_dob ); ?>"
						placeholder="<?php esc_attr_e( 'dd/mm/yyyy', 'smart-login' ); ?>"
						inputmode="numeric"
						autocomplete="bday"
					/>
				</div>
			<?php endif; ?>

			<?php if ( Settings::is_on( 'field_referral' ) ) : ?>
				<div class="sl-field">
					<label class="sl-label" for="smartlogin_referral_code"><?php esc_html_e( 'Mã giới thiệu', 'smart-login' ); ?></label>
					<input
						type="text"
						class="sl-input"
						name="smartlogin_referral_code"
						id="smartlogin_referral_code"
						value="<?php echo esc_attr( $sl_referral ); ?>"
						autocomplete="off"
					/>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( Settings::is_on( 'address_enabled' ) ) : ?>
			<hr class="sl-separator" />

			<div class="sl-field">
				<label class="sl-label" for="smartlogin_country"><?php esc_html_e( 'Quốc gia', 'smart-login' ); ?></label>
				<input type="text" class="sl-input" id="smartlogin_country" value="<?php esc_attr_e( 'Việt Nam', 'smart-login' ); ?>" readonly disabled />
			</div>

			<?php
			AddressFields::output(
				array(
					'values'   => AddressFields::get_for_user( $sl_user->ID ),
					'required' => Settings::is_on( 'address_required_in_profile' ),
				)
			);
			?>
		<?php endif; ?>

		<hr class="sl-separator" />

		<p class="sl-lead"><?php esc_html_e( 'Đổi mật khẩu (để trống nếu không muốn thay đổi)', 'smart-login' ); ?></p>

		<?php
		TemplateLoader::output(
			'partials/password-field',
			array(
				'name'     => 'password_current',
				'label'    => __( 'Mật khẩu hiện tại', 'smart-login' ),
				'id'       => 'password_current',
				'required' => false,
			)
		);

		TemplateLoader::output(
			'partials/password-field',
			array(
				'name'     => 'password_1',
				'label'    => __( 'Mật khẩu mới', 'smart-login' ),
				'id'       => 'password_1',
				'required' => false,
			)
		);

		TemplateLoader::output(
			'partials/password-field',
			array(
				'name'     => 'password_2',
				'label'    => __( 'Nhập lại mật khẩu mới', 'smart-login' ),
				'id'       => 'password_2',
				'required' => false,
			)
		);
		?>

		<?php do_action( 'woocommerce_edit_account_form' ); ?>

		<p>
			<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
			<button type="submit" class="sl-btn sl-btn--primary woocommerce-Button button" name="save_account_details" value="<?php esc_attr_e( 'Cập nhật', 'smart-login' ); ?>">
				<?php esc_html_e( 'Cập nhật', 'smart-login' ); ?>
			</button>
			<input type="hidden" name="action" value="save_account_details" />
		</p>

		<?php do_action( 'woocommerce_edit_account_form_end' ); ?>
	</form>
</div>

<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
