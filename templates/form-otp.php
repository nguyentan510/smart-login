<?php
/**
 * OTP verification screen. Override at yourtheme/omniwp/form-otp.php
 *
 * @var array  $notices
 * @var string $intent
 * @var string $masked
 * @var int    $expires_in
 * @var int    $resend_after
 * @var string $transport
 * @var int    $otp_length
 * @var string $dev_code
 * @var bool   $has_session
 *
 * @package OmniWP
 */

use OmniWP\Frontend\Flow;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\OTP\OtpService;
use OmniWP\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$ow_registering = OtpService::INTENT_REGISTER === $intent;
// Identifier-first collapses login and register onto one entry screen, so
// "back" is the same place either way.
$ow_back = Flow::STEP_IDENTIFY;
?>
<div class="omniwp omniwp--otp">

	<?php
	if ( $ow_registering ) {
		TemplateLoader::output(
			'partials/steps',
			array(
				'current' => 2,
				'labels'  => array(
					__( 'Số điện thoại', 'omniwp' ),
					__( 'Xác thực', 'omniwp' ),
					__( 'Thông tin', 'omniwp' ),
				),
			)
		);
	}
	?>

	<?php TemplateLoader::output( 'partials/screen-title', array( 'text' => __( 'Xác thực OTP', 'omniwp' ) ) ); ?>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php if ( ! $has_session ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Phiên xác thực đã kết thúc. Vui lòng thực hiện lại.', 'omniwp' ); ?></p>
		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( $ow_back ) ); ?>">
			<?php esc_html_e( 'Quay lại', 'omniwp' ); ?>
		</a>

	<?php else : ?>

		<p class="sl-lead">
			<?php
			if ( 'email' === $transport ) {
				printf(
					/* translators: %s: masked email address. */
					esc_html__( 'Vui lòng nhập vào mã OTP đã được gửi đến email %s', 'omniwp' ),
					'<strong class="sl-dest">' . esc_html( $masked ) . '</strong>'
				);
			} else {
				printf(
					/* translators: %s: masked phone number. */
					esc_html__( 'Vui lòng nhập vào mã OTP đã được gửi đến số điện thoại %s', 'omniwp' ),
					'<strong class="sl-dest">' . esc_html( $masked ) . '</strong>'
				);
			}
			?>
		</p>

		<?php if ( '' !== $dev_code ) : ?>
			<div class="sl-notice sl-notice--warning">
				<?php
				printf(
					/* translators: %s: the OTP code. */
					esc_html__( 'CHẾ ĐỘ DEV — mã xác thực: %s', 'omniwp' ),
					'<code>' . esc_html( $dev_code ) . '</code>'
				);
				?>
			</div>
		<?php endif; ?>

		<form method="post" class="sl-form sl-form--otp" id="sl-otp-form" novalidate>
			<?php RequestGuard::fields( 'otp' ); ?>
			<input type="hidden" name="OMNIWP_action" value="verify_otp" />
			<input type="hidden" name="otp_code" id="sl-otp-code" value="" />

			<div class="sl-otp-boxes" data-otp-length="<?php echo esc_attr( $otp_length ); ?>">
				<?php for ( $ow_i = 0; $ow_i < $otp_length; $ow_i++ ) : ?>
					<input
						type="text"
						class="sl-otp-digit"
						name="otp_digit[]"
						inputmode="numeric"
						pattern="[0-9]*"
						maxlength="1"
						autocomplete="<?php echo 0 === $ow_i ? 'one-time-code' : 'off'; ?>"
						aria-label="
						<?php
							printf(
								/* translators: %d: digit position. */
								esc_attr__( 'Ký tự thứ %d của mã OTP', 'omniwp' ),
								(int) $ow_i + 1
							);
						?>
						"
						<?php echo 0 === $ow_i ? 'autofocus' : ''; ?>
					/>
				<?php endfor; ?>
			</div>

			<p class="sl-countdown">
				<?php esc_html_e( 'Thời gian còn lại:', 'omniwp' ); ?>
				<span class="sl-countdown__value" data-expires-in="<?php echo esc_attr( $expires_in ); ?>">
					<?php echo esc_html( sprintf( '%02d:%02d', intdiv( $expires_in, 60 ), $expires_in % 60 ) ); ?>
				</span>
			</p>

			<button type="submit" class="sl-btn sl-btn--primary" id="sl-otp-submit">
				<?php esc_html_e( 'Tiếp tục', 'omniwp' ); ?>
			</button>
		</form>

		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( $ow_back ) ); ?>">
			<?php esc_html_e( 'Quay lại', 'omniwp' ); ?>
		</a>

		<form method="post" class="sl-resend">
			<?php RequestGuard::fields( 'otp' ); ?>
			<input type="hidden" name="OMNIWP_action" value="resend_otp" />
			<span><?php esc_html_e( 'Chưa nhận được mã.', 'omniwp' ); ?></span>
			<button
				type="submit"
				class="sl-link sl-link--button"
				id="sl-resend-button"
				data-resend-after="<?php echo esc_attr( $resend_after ); ?>"
			><?php esc_html_e( 'Gửi lại', 'omniwp' ); ?></button>
		</form>

	<?php endif; ?>
</div>
