<?php
/**
 * The external accounts attached to this one, with a way to detach one.
 *
 * Override at yourtheme/smart-login/partials/linked-identities.php
 *
 * Before this existed the UI offered "link" unconditionally and never showed what
 * was already linked, so nobody could tell an account with two providers from one
 * with none.
 *
 * **Federated identities only.** `IdentityLinkService::linked()` returns every
 * identity record, phone and email included, and this partial rendered all of
 * them — so an account's own address printed once whole in the contact row above
 * and once masked here, and a member read two addresses. The flag that separates
 * the two kinds has been in the payload since Phase 6 and nothing read it.
 *
 * The filter is presentational, which is why it lives here and not in the
 * service: `can_unlink()` counts every identity, and the REST route serves
 * callers that are not this card. See docs/sign-in-card.md, decision 2.
 *
 * @var array<int,array<string,mixed>> $sl_identities Output of IdentityLinkService::linked()
 * @var bool                           $sl_can_unlink
 * @var string                         $sl_redirect
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\FormController;

defined( 'ABSPATH' ) || exit;

$sl_identities = array_values(
	array_filter(
		isset( $sl_identities ) && is_array( $sl_identities ) ? $sl_identities : array(),
		static function ( $sl_candidate ): bool {
			return is_array( $sl_candidate ) && ! empty( $sl_candidate['federated'] );
		}
	)
);

// After the filter, not before: "nothing federated" and "nothing at all" are
// different states, and an account whose only identity is its phone must render
// no heading rather than an empty one.
if ( empty( $sl_identities ) ) {
	return;
}
?>
<div class="sl-identities">
	<?php
	/*
	 * Not "Cách đăng nhập của bạn" any more. With the contact rows above owning
	 * phone and email, a heading claiming to list every way in would sit over a
	 * list that holds none of them.
	 */
	?>
	<h3 class="sl-subtitle"><?php esc_html_e( 'Tài khoản đã liên kết', 'smart-login' ); ?></h3>

	<ul class="sl-identity-list">
		<?php foreach ( $sl_identities as $sl_identity ) : ?>
			<li class="sl-identity-item sl-identity-item--<?php echo esc_attr( $sl_identity['channel'] ); ?>">
				<span class="sl-identity-label"><?php echo esc_html( $sl_identity['label'] ); ?></span>
				<?php
				/*
				 * `display` names the account; `masked` is the provider's `sub`
				 * claim, which identifies nobody to the person reading it. The
				 * masked value stays in the payload for the REST route and for
				 * integrators — it is only this row that stops rendering it.
				 */
				?>
				<span class="sl-identity-value">
					<?php echo esc_html( '' !== (string) ( $sl_identity['display'] ?? '' ) ? $sl_identity['display'] : $sl_identity['masked'] ); ?>
				</span>

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
