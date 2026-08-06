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
use SmartLogin\Frontend\ProviderMark;

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
	 * No subtitle. Since 16.1 these rows are the only ones left here, and since
	 * 16.3 they use the same grid as the contact rows above — so the card holds
	 * one list of ways in, and a heading over its last two entries would divide
	 * a list that has stopped being two lists.
	 */
	?>
	<ul class="sl-identity-list">
		<?php foreach ( $sl_identities as $sl_identity ) : ?>
			<?php $sl_field_id = 'sl-unlink-pass-' . md5( $sl_identity['channel'] . $sl_identity['subject'] ); ?>
			<li class="sl-identity sl-identity--<?php echo esc_attr( $sl_identity['channel'] ); ?>">
				<div class="sl-row">
					<?php
					/*
					 * A flex item of the row rather than something inside the label: the
					 * label column is the 108px one the contact rows above share, and a
					 * mark inside it would move where "Google" starts relative to
					 * "Email" — the misalignment 16.3 exists to have removed.
					 */
					ProviderMark::output( (string) $sl_identity['channel'] );
					?>
					<span class="sl-row__label"><?php echo esc_html( $sl_identity['label'] ); ?></span>
					<?php
					/*
					 * `display` names the account; `masked` is the provider's `sub`
					 * claim, which identifies nobody to the person reading it. The
					 * masked value stays in the payload for the REST route and for
					 * integrators — it is only this row that stops rendering it.
					 */
					?>
					<span class="sl-row__value">
						<?php echo esc_html( '' !== (string) ( $sl_identity['display'] ?? '' ) ? $sl_identity['display'] : $sl_identity['masked'] ); ?>
					</span>

					<?php if ( ! empty( $sl_identity['is_primary'] ) ) : ?>
						<span class="sl-badge sl-badge--primary"><?php esc_html_e( 'Chính', 'smart-login' ); ?></span>
					<?php endif; ?>

					<?php if ( empty( $sl_identity['removable'] ) ) : ?>
						<span class="sl-muted sl-identity-note">
							<?php esc_html_e( 'Không thể bỏ — đây là cách đăng nhập duy nhất', 'smart-login' ); ?>
						</span>
					<?php else : ?>
						<details class="sl-identity-unlink">
							<?php
							/*
							 * `<details>` stays as the mechanism — it holds a password
							 * form and has to work with JavaScript off. 17.3 changes
							 * what the summary looks like and nothing else, so the
							 * rarest control in the card stops being a third visual
							 * weight beside "Đổi" and "Liên kết".
							 */
							?>
							<summary class="sl-action sl-action--summary sl-action--danger"><?php esc_html_e( 'Bỏ liên kết', 'smart-login' ); ?></summary>

							<form method="post" class="sl-identity-unlink-form">
								<?php wp_nonce_field( 'smart_login_unlink_identity' ); ?>
								<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="unlink_identity" />
								<input type="hidden" name="channel" value="<?php echo esc_attr( $sl_identity['channel'] ); ?>" />
								<input type="hidden" name="subject" value="<?php echo esc_attr( $sl_identity['subject'] ); ?>" />
								<input type="hidden" name="_redirect" value="<?php echo esc_url( $sl_redirect ); ?>" />

								<div class="sl-field">
									<label class="sl-label" for="<?php echo esc_attr( $sl_field_id ); ?>">
										<?php esc_html_e( 'Nhập mật khẩu để xác nhận', 'smart-login' ); ?>
									</label>
									<input
										type="password"
										class="sl-input"
										id="<?php echo esc_attr( $sl_field_id ); ?>"
										name="password"
										autocomplete="current-password"
										required
									/>
								</div>

								<button type="submit" class="sl-btn sl-btn--outline sl-btn--danger sl-btn--inline">
									<?php esc_html_e( 'Xác nhận bỏ liên kết', 'smart-login' ); ?>
								</button>
							</form>
						</details>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
