<?php
/**
 * The external accounts attached to this one, with a way to detach one.
 *
 * Override at yourtheme/omniwp/partials/linked-identities.php
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
 * @var array<int,array<string,mixed>> $ow_identities Output of IdentityLinkService::linked()
 * @var bool                           $ow_can_unlink
 * @var string                         $ow_redirect
 *
 * @package OmniWP
 */

use OmniWP\Frontend\DeferredForms;
use OmniWP\Frontend\FormController;
use OmniWP\Frontend\ProviderMark;

defined( 'ABSPATH' ) || exit;

$ow_identities = array_values(
	array_filter(
		isset( $ow_identities ) && is_array( $ow_identities ) ? $ow_identities : array(),
		static function ( $ow_candidate ): bool {
			return is_array( $ow_candidate ) && ! empty( $ow_candidate['federated'] );
		}
	)
);

// After the filter, not before: "nothing federated" and "nothing at all" are
// different states, and an account whose only identity is its phone must render
// no heading rather than an empty one.
if ( empty( $ow_identities ) ) {
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
		<?php foreach ( $ow_identities as $ow_identity ) : ?>
			<?php $ow_field_id = 'sl-unlink-pass-' . md5( $ow_identity['channel'] . $ow_identity['subject'] ); ?>
			<li class="sl-identity sl-identity--<?php echo esc_attr( $ow_identity['channel'] ); ?>">
				<div class="sl-row">
					<?php
					/*
					 * A flex item of the row rather than something inside the label: the
					 * label column is the 108px one the contact rows above share, and a
					 * mark inside it would move where "Google" starts relative to
					 * "Email" — the misalignment 16.3 exists to have removed.
					 */
					ProviderMark::output( (string) $ow_identity['channel'] );
					?>
					<span class="sl-row__label"><?php echo esc_html( $ow_identity['label'] ); ?></span>
					<?php
					/*
					 * `display` names the account; `masked` is the provider's `sub`
					 * claim, which identifies nobody to the person reading it. The
					 * masked value stays in the payload for the REST route and for
					 * integrators — it is only this row that stops rendering it.
					 */
					?>
					<span class="sl-row__value">
						<?php echo esc_html( '' !== (string) ( $ow_identity['display'] ?? '' ) ? $ow_identity['display'] : $ow_identity['masked'] ); ?>
					</span>

					<?php if ( ! empty( $ow_identity['is_primary'] ) ) : ?>
						<span class="sl-badge sl-badge--primary"><?php esc_html_e( 'Chính', 'omniwp' ); ?></span>
					<?php endif; ?>

					<?php if ( empty( $ow_identity['removable'] ) ) : ?>
						<span class="sl-muted sl-identity-note">
							<?php esc_html_e( 'Không thể bỏ — đây là cách đăng nhập duy nhất', 'omniwp' ); ?>
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
							<summary class="sl-action sl-action--summary sl-action--danger"><?php esc_html_e( 'Bỏ liên kết', 'omniwp' ); ?></summary>

							<?php
							/*
							 * The controls are here; the <form> they belong to is not.
							 *
							 * This card renders *inside* the account form, and HTML forbids
							 * a form inside a form — the parser drops the inner start tag,
							 * so the inner close tag ends the OUTER form and everything
							 * after it stops being part of any form. Measured on a real
							 * account holding a removable Google identity: the account form
							 * opened at offset 401, this one at 8171 and closed at 9273, and
							 * the save bar landed at 28359 — outside. "Lưu thay đổi" had
							 * nothing to submit, and pressing it did nothing at all: no
							 * error, no notice, no request.
							 *
							 * The `form` attribute is HTML's own answer: a control may sit
							 * anywhere in the document and belong to a form by id. So the
							 * password box and the confirm button stay exactly where they
							 * make sense, and the form element is registered with
							 * DeferredForms and emitted once the surrounding form has
							 * closed. Nothing about this control's position or its
							 * no-JavaScript behaviour changes.
							 */
							$ow_unlink_form = 'sl-unlink-form-' . md5( $ow_identity['channel'] . $ow_identity['subject'] );

							ob_start();
							?>
							<form method="post" class="sl-identity-unlink-form" id="<?php echo esc_attr( $ow_unlink_form ); ?>">
								<?php wp_nonce_field( 'OMNIWP_unlink_identity' ); ?>
								<input type="hidden" name="<?php echo esc_attr( FormController::ACTION_FIELD ); ?>" value="unlink_identity" />
								<input type="hidden" name="channel" value="<?php echo esc_attr( $ow_identity['channel'] ); ?>" />
								<input type="hidden" name="subject" value="<?php echo esc_attr( $ow_identity['subject'] ); ?>" />
								<input type="hidden" name="_redirect" value="<?php echo esc_url( $ow_redirect ); ?>" />
							</form>
							<?php
							DeferredForms::add( $ow_unlink_form, (string) ob_get_clean() );
							?>

							<div class="sl-field">
								<label class="sl-label" for="<?php echo esc_attr( $ow_field_id ); ?>">
									<?php esc_html_e( 'Nhập mật khẩu để xác nhận', 'omniwp' ); ?>
								</label>
								<input
									type="password"
									class="sl-input"
									id="<?php echo esc_attr( $ow_field_id ); ?>"
									name="password"
									autocomplete="current-password"
									form="<?php echo esc_attr( $ow_unlink_form ); ?>"
									required
								/>
							</div>

							<button type="submit" class="sl-btn sl-btn--outline sl-btn--danger sl-btn--inline" form="<?php echo esc_attr( $ow_unlink_form ); ?>">
								<?php esc_html_e( 'Xác nhận bỏ liên kết', 'omniwp' ); ?>
							</button>

						</details>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
