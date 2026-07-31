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
 * Override at yourtheme/smart-login/onboarding.php
 *
 * @var array    $notices
 * @var WP_User  $user
 * @var array    $fields        Output of ProfileCompletionService::onboarding_fields().
 * @var string   $redirect      Where "Hoàn tất" and "Để sau" both lead.
 * @var array    $address       Current address values, or empty.
 * @var bool     $email_missing Whether the account still has no real email.
 *
 * @package SmartLogin
 */

use SmartLogin\Address\AddressFields;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$sl_site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
$sl_fields = (array) ( $fields ?? array() );
$sl_first  = trim( (string) ( $user->first_name ?? '' ) );
$sl_name   = '' !== $sl_first ? $sl_first : (string) $user->display_name;
?>
<div class="smart-login smart-login--onboard">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<div class="sl-congrats">
		<span class="sl-congrats__mark" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="28" height="28" focusable="false">
				<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" fill="currentColor"/>
			</svg>
		</span>
		<p class="sl-congrats-label"><?php esc_html_e( 'ĐĂNG KÝ THÀNH CÔNG', 'smart-login' ); ?></p>
		<h2 class="sl-congrats-title">
			<?php
			printf(
				/* translators: %s: site name. */
				esc_html__( 'Bạn đã trở thành hội viên của %s!', 'smart-login' ),
				esc_html( $sl_site )
			);
			?>
		</h2>
	</div>

	<?php if ( empty( $sl_fields ) ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Hồ sơ của bạn đã đầy đủ. Không còn gì phải điền cả.', 'smart-login' ); ?></p>
		<a class="sl-btn sl-btn--primary sl-btn--block" href="<?php echo esc_url( $redirect ); ?>">
			<?php esc_html_e( 'Bắt đầu khám phá', 'smart-login' ); ?>
		</a>

	<?php else : ?>

		<p class="sl-lead">
			<?php
			printf(
				/* translators: %s: the member's first name. */
				esc_html__( 'Chào %s! Thêm chút thông tin nữa để nhận đầy đủ ưu đãi hội viên — hoặc để sau cũng được.', 'smart-login' ),
				esc_html( $sl_name )
			);
			?>
		</p>

		<form method="post" class="sl-form sl-form--onboard">
			<?php RequestGuard::fields( 'onboard' ); ?>
			<input type="hidden" name="smart_login_action" value="onboard" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>" />

			<?php foreach ( $sl_fields as $sl_field ) : ?>
				<?php if ( 'full_name' === $sl_field['key'] ) : ?>
					<div class="sl-field">
						<label class="sl-label" for="sl-onboard-name"><?php echo esc_html( $sl_field['label'] ); ?></label>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $sl_field['reason'] ); ?></p>
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

				<?php if ( 'address' === $sl_field['key'] ) : ?>
					<div class="sl-onboard-group">
						<p class="sl-label"><?php echo esc_html( $sl_field['label'] ); ?></p>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $sl_field['reason'] ); ?></p>
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

				<?php if ( 'dob' === $sl_field['key'] ) : ?>
					<div class="sl-field">
						<label class="sl-label" for="sl-dob"><?php echo esc_html( $sl_field['label'] ); ?></label>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $sl_field['reason'] ); ?></p>
						<input
							type="text"
							class="sl-input"
							id="sl-dob"
							name="dob"
							value=""
							placeholder="<?php esc_attr_e( 'dd/mm/yyyy', 'smart-login' ); ?>"
							inputmode="numeric"
							autocomplete="bday"
						/>
					</div>
				<?php endif; ?>

				<?php if ( 'gender' === $sl_field['key'] ) : ?>
					<fieldset class="sl-field sl-field--radio">
						<legend class="sl-label"><?php echo esc_html( $sl_field['label'] ); ?></legend>
						<p class="sl-hint sl-hint--reason"><?php echo esc_html( $sl_field['reason'] ); ?></p>
						<?php
						foreach ( array(
							'female' => __( 'Nữ', 'smart-login' ),
							'male'   => __( 'Nam', 'smart-login' ),
							'other'  => __( 'Khác', 'smart-login' ),
						) as $sl_value => $sl_label ) :
							?>
							<label class="sl-radio">
								<input type="radio" name="gender" value="<?php echo esc_attr( $sl_value ); ?>" />
								<span><?php echo esc_html( $sl_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="sl-onboard-actions">
				<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">
					<?php esc_html_e( 'Hoàn tất', 'smart-login' ); ?>
				</button>
				<button type="submit" name="sl_skip" value="1" class="sl-btn sl-btn--ghost sl-btn--block">
					<?php esc_html_e( 'Để sau', 'smart-login' ); ?>
				</button>
			</div>
		</form>

	<?php endif; ?>

	<?php if ( ! empty( $email_missing ) ) : ?>
		<p class="sl-hint sl-onboard-email">
			<?php esc_html_e( 'Bạn có thể thêm email trong trang hồ sơ bất cứ lúc nào — việc này cần một mã xác thực riêng nên không nằm ở đây.', 'smart-login' ); ?>
		</p>
	<?php endif; ?>
</div>
