<?php
/**
 * The identities attached to the current account, with a way to remove one.
 *
 * Override at yourtheme/smart-login/partials/linked-identities.php
 *
 * Before this existed the UI offered "link" unconditionally and never showed what
 * was already linked, so nobody could tell an account with two providers from one
 * with none.
 *
 * @var array<int,array<string,mixed>> $sl_identities Output of IdentityLinkService::linked()
 * @var bool                           $sl_can_unlink
 * @var string                         $sl_redirect
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\FormController;

defined( 'ABSPATH' ) || exit;

if ( empty( $sl_identities ) ) {
	return;
}
?>
<div class="sl-identities">
	<h3 class="sl-subtitle"><?php esc_html_e( 'Cách đăng nhập của bạn', 'smart-login' ); ?></h3>

	<ul class="sl-identity-list">
		<?php foreach ( $sl_identities as $sl_identity ) : ?>
			<li class="sl-identity-item sl-identity-item--<?php echo esc_attr( $sl_identity['channel'] ); ?>">
				<span class="sl-identity-label"><?php echo esc_html( $sl_identity['label'] ); ?></span>
				<span class="sl-identity-value"><?php echo esc_html( $sl_identity['masked'] ); ?></span>

				<?php if ( ! empty( $sl_identity['is_primary'] ) ) : ?>
					<span class="sl-identity-badge"><?php esc_html_e( 'Chính', 'smart-login' ); ?></span>
				<?php endif; ?>

				<?php if ( empty( $sl_identity['removable'] ) ) : ?>
					<span class="sl-muted sl-identity-note">
						<?php esc_html_e( 'Không thể bỏ — đây là cách đăng nhập duy nhất', 'smart-login' ); ?>
					</span>
				<?php else : ?>
					<details class="sl-identity-unlink">
						<summary class="sl-link"><?php esc_html_e( 'Bỏ liên kết', 'smart-login' ); ?></summary>

						<form method="post" class="sl-identity-unlink-form">
							<?php wp_nonce_field( 'smart_login_unlink_identity' ); ?>
							<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="unlink_identity" />
							<input type="hidden" name="channel" value="<?php echo esc_attr( $sl_identity['channel'] ); ?>" />
							<input type="hidden" name="subject" value="<?php echo esc_attr( $sl_identity['subject'] ); ?>" />
							<input type="hidden" name="_redirect" value="<?php echo esc_url( $sl_redirect ); ?>" />

							<p class="sl-field">
								<label for="sl-unlink-pass-<?php echo esc_attr( md5( $sl_identity['channel'] . $sl_identity['subject'] ) ); ?>">
									<?php esc_html_e( 'Nhập mật khẩu để xác nhận', 'smart-login' ); ?>
								</label>
								<input
									type="password"
									id="sl-unlink-pass-<?php echo esc_attr( md5( $sl_identity['channel'] . $sl_identity['subject'] ) ); ?>"
									name="password"
									autocomplete="current-password"
									required
								/>
							</p>

							<button type="submit" class="sl-btn sl-btn--outline sl-btn--danger">
								<?php esc_html_e( 'Xác nhận bỏ liên kết', 'smart-login' ); ?>
							</button>
						</form>
					</details>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
