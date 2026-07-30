<?php
/**
 * OTP verification screen. Override at yourtheme/smart-login/form-otp.php
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
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\OTP\OtpService;
use SmartLogin\Security\RequestGuard;

defined( 'ABSPATH' ) || exit;

$sl_back = OtpService::INTENT_REGISTER === $intent ? Flow::STEP_REGISTER : Flow::STEP_LOGIN;
?>
<div class="smart-login smart-login--otp">

	<h2 class="sl-title"><?php esc_html_e( 'Xác thực OTP', 'smart-login' ); ?></h2>

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php if ( ! $has_session ) : ?>

		<p class="sl-lead"><?php esc_html_e( 'Phiên xác thực đã kết thúc. Vui lòng thực hiện lại.', 'smart-login' ); ?></p>
		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( $sl_back ) ); ?>">
			<?php esc_html_e( 'Quay lại', 'smart-login' ); ?>
		</a>

	<?php else : ?>

		<p class="sl-lead">
			<?php
			if ( 'email' === $transport ) {
				printf(
					/* translators: %s: masked email address. */
					esc_html__( 'Vui lòng nhập vào mã OTP đã được gửi đến email %s', 'smart-login' ),
					'<strong class="sl-dest">' . esc_html( $masked ) . '</strong>'
				);
			} else {
				printf(
					/* translators: %s: masked phone number. */
					esc_html__( 'Vui lòng nhập vào mã OTP đã được gửi đến số điện thoại %s', 'smart-login' ),
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
					esc_html__( 'CHẾ ĐỘ DEV — mã xác thực: %s', 'smart-login' ),
					'<code>' . esc_html( $dev_code ) . '</code>'
				);
				?>
			</div>
		<?php endif; ?>

		<form method="post" class="sl-form sl-form--otp" id="sl-otp-form" novalidate>
			<?php RequestGuard::fields( 'otp' ); ?>
			<input type="hidden" name="smart_login_action" value="verify_otp" />
			<input type="hidden" name="otp_code" id="sl-otp-code" value="" />

			<div class="sl-otp-boxes" data-otp-length="<?php echo esc_attr( $otp_length ); ?>">
				<?php for ( $sl_i = 0; $sl_i < $otp_length; $sl_i++ ) : ?>
					<input
						type="text"
						class="sl-otp-digit"
						name="otp_digit[]"
						inputmode="numeric"
						pattern="[0-9]*"
						maxlength="1"
						autocomplete="<?php echo 0 === $sl_i ? 'one-time-code' : 'off'; ?>"
						aria-label="<?php
							printf(
								/* translators: %d: digit position. */
								esc_attr__( 'Ký tự thứ %d của mã OTP', 'smart-login' ),
								$sl_i + 1
							);
						?>"
						<?php echo 0 === $sl_i ? 'autofocus' : ''; ?>
					/>
				<?php endfor; ?>
			</div>

			<p class="sl-countdown">
				<?php esc_html_e( 'Thời gian còn lại:', 'smart-login' ); ?>
				<span class="sl-countdown__value" data-expires-in="<?php echo esc_attr( $expires_in ); ?>">
					<?php echo esc_html( sprintf( '%02d:%02d', intdiv( $expires_in, 60 ), $expires_in % 60 ) ); ?>
				</span>
			</p>

			<button type="submit" class="sl-btn sl-btn--primary" id="sl-otp-submit">
				<?php esc_html_e( 'Tiếp tục', 'smart-login' ); ?>
			</button>
		</form>

		<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( Flow::url( $sl_back ) ); ?>">
			<?php esc_html_e( 'Quay lại', 'smart-login' ); ?>
		</a>

		<form method="post" class="sl-resend">
			<?php RequestGuard::fields( 'otp' ); ?>
			<input type="hidden" name="smart_login_action" value="resend_otp" />
			<span><?php esc_html_e( 'Chưa nhận được mã.', 'smart-login' ); ?></span>
			<button
				type="submit"
				class="sl-link sl-link--button"
				id="sl-resend-button"
				data-resend-after="<?php echo esc_attr( $resend_after ); ?>"
			><?php esc_html_e( 'Gửi lại', 'smart-login' ); ?></button>
		</form>

	<?php endif; ?>
</div>
