<?php
/**
 * The welcome screen, shown once, right where the visitor already is.
 *
 * This replaces two things that used to happen in sequence: a congratulations
 * screen that yanked the visitor away after six seconds, and the full account
 * editing form used as an onboarding step — fifteen controls including two OTP
 * widgets and three password boxes, none of which a thirty-second-old account
 * needs.
 *
 * Every field here is optional and every field says what it is for. "Để sau"
 * is a real button, not a link hidden in small print: the profile gate that
 * used to justify pressure has been removed, so this screen asks and accepts
 * no for an answer.
 *
 * Override at yourtheme/omniwp/onboarding.php
 *
 * @var array    $notices
 * @var WP_User  $user
 * @var array    $fields        Output of ProfileCompletionService::onboarding_fields().
 * @var string   $redirect      Where "Hoàn tất" and "Để sau" both lead.
 * @var array    $address       Current address values, or empty.
 * @var bool     $email_missing Whether the account still has no real email.
 *
 * @package OmniWP
 */

use OmniWP\Address\AddressFields;
use OmniWP\Frontend\IconSet;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$ow_site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
$ow_fields = (array) ( $fields ?? array() );
$ow_first  = trim( (string) ( $user->first_name ?? '' ) );
$ow_name   = '' !== $ow_first ? $ow_first : (string) ( $user->display_name ?? '' );
$ow_is_new = ! isset( $is_new_user ) || ! empty( $is_new_user );
?>
<?php
/*
 * `data-sl-onboarding` is how the launcher knows this screen is already on the
 * page. A member returning from a provider carries `OmniWP_welcome=1`, and
 * both this template and the dialog answer to it — without a marker they would
 * both draw, and the visitor would be asked the same questions twice.
 */
?>
<div class="omniwp omniwp--onboard" data-sl-onboarding>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<div class="sl-congrats">
		<span class="sl-congrats__mark" aria-hidden="true">
			<?php echo IconSet::get( $ow_is_new ? 'check' : 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
		<div class="sl-congrats__text">
			<p class="sl-congrats-label"><?php echo esc_html( $ow_is_new ? __( 'ĐĂNG KÝ THÀNH CÔNG', 'omniwp' ) : __( 'HOÀN THIỆN HỒ SƠ', 'omniwp' ) ); ?></p>
			<h2 class="sl-congrats-title">
				<?php
				if ( $ow_is_new ) {
					printf(
						/* translators: %s: site name. */
						esc_html__( 'Bạn đã trở thành hội viên của %s!', 'omniwp' ),
						esc_html( $ow_site )
					);
				} else {
					esc_html_e( 'Bổ sung thông tin tài khoản của bạn', 'omniwp' );
				}
				?>
			</h2>
		</div>
	</div>

	<?php if ( empty( $ow_fields ) ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Hồ sơ của bạn đã đầy đủ. Không còn gì phải điền cả.', 'omniwp' ); ?></p>
		<a class="sl-btn sl-btn--primary sl-btn--block" href="<?php echo esc_url( $redirect ); ?>">
			<?php esc_html_e( 'Bắt đầu khám phá', 'omniwp' ); ?>
		</a>

	<?php else : ?>

		<p class="sl-lead">
			<?php
			if ( $ow_is_new ) {
				printf(
					/* translators: %s: the member's first name. */
					esc_html__( 'Chào %s! Thêm địa chỉ nhận hàng để nhận quà hội viên & đặt hàng siêu tốc.', 'omniwp' ),
					esc_html( $ow_name )
				);
			} else {
				printf(
					/* translators: %s: the member's first name. */
					esc_html__( 'Chào %s! Bổ sung địa chỉ nhận hàng để nhận quà hội viên & đặt hàng siêu tốc.', 'omniwp' ),
					esc_html( $ow_name )
				);
			}
			?>
		</p>

		<form method="post" class="sl-form sl-form--onboard">
			<?php RequestGuard::fields( 'onboard' ); ?>
			<input type="hidden" name="OMNIWP_action" value="onboard" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>" />

			<?php foreach ( $ow_fields as $ow_field ) : ?>
				<?php if ( 'full_name' === $ow_field['key'] ) : ?>
					<div class="sl-field">
						<label class="sl-label" for="sl-onboard-name"><?php echo esc_html( $ow_field['label'] ); ?></label>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $ow_field['reason'] ); ?></p>
						<input
							type="text"
							class="sl-input"
							id="sl-onboard-name"
							name="full_name"
							value=""
							autocomplete="name"
						/>
					</div>
				<?php endif; ?>

				<?php if ( 'phone' === $ow_field['key'] ) : ?>
					<div class="sl-field">
						<label class="sl-label" for="sl-onboard-phone"><?php echo esc_html( $ow_field['label'] ); ?></label>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $ow_field['reason'] ); ?></p>
						<input
							type="tel"
							class="sl-input"
							id="sl-onboard-phone"
							name="phone"
							value=""
							placeholder="<?php esc_attr_e( 'Ví dụ: 0912345678', 'omniwp' ); ?>"
							inputmode="tel"
							autocomplete="tel"
						/>
					</div>
				<?php endif; ?>

				<?php if ( 'address' === $ow_field['key'] ) : ?>
					<div class="sl-onboard-group">
						<p class="sl-label"><?php echo esc_html( $ow_field['label'] ); ?></p>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $ow_field['reason'] ); ?></p>
						<?php
						AddressFields::output(
							array(
								'values'   => ! empty( $address ) ? $address : null,
								'required' => false,
							)
						);
						?>
					</div>
				<?php endif; ?>

				<?php if ( 'dob' === $ow_field['key'] ) : ?>
					<div class="sl-field">
						<label class="sl-label" for="sl-dob"><?php echo esc_html( $ow_field['label'] ); ?></label>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $ow_field['reason'] ); ?></p>
						<input
							type="text"
							class="sl-input"
							id="sl-dob"
							name="dob"
							value=""
							placeholder="<?php esc_attr_e( 'dd/mm/yyyy', 'omniwp' ); ?>"
							inputmode="numeric"
							autocomplete="bday"
						/>
					</div>
				<?php endif; ?>

				<?php if ( 'gender' === $ow_field['key'] ) : ?>
					<fieldset class="sl-field sl-field--radio">
						<legend class="sl-label"><?php echo esc_html( $ow_field['label'] ); ?></legend>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $ow_field['reason'] ); ?></p>
						<?php
						foreach ( array(
							'female' => __( 'Nữ', 'omniwp' ),
							'male'   => __( 'Nam', 'omniwp' ),
							'other'  => __( 'Khác', 'omniwp' ),
						) as $ow_value => $ow_label ) :
							?>
							<label class="sl-radio">
								<input type="radio" name="gender" value="<?php echo esc_attr( $ow_value ); ?>" />
								<span><?php echo esc_html( $ow_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="sl-onboard-actions">
				<button type="submit" name="ow_skip" value="1" class="sl-btn sl-btn--ghost sl-onboard-btn-skip">
					<?php esc_html_e( 'Để sau', 'omniwp' ); ?>
				</button>
				<button type="submit" class="sl-btn sl-btn--primary sl-onboard-btn-submit">
					<?php echo esc_html( $ow_is_new ? __( 'Hoàn tất', 'omniwp' ) : __( 'Lưu thông tin', 'omniwp' ) ); ?>
				</button>
			</div>
		</form>

	<?php endif; ?>

	<?php if ( ! empty( $email_missing ) ) : ?>
		<p class="sl-hint sl-onboard-email">
			<?php esc_html_e( 'Bạn có thể thêm email trong trang hồ sơ bất cứ lúc nào — việc này cần một mã xác thực riêng nên không nằm ở đây.', 'omniwp' ); ?>
		</p>
	<?php endif; ?>
</div>
