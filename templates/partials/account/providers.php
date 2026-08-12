<?php
/**
 * What is already linked, and what may still be linked.
 *
 * One section owns both halves. Splitting them is how the WooCommerce copy ended
 * up offering "link Google" to accounts that had linked Google long ago, and
 * offering no way to unlink at all.
 *
 * The button copy is "Liên kết Google", not "Tiếp tục với Google". The second is
 * sign-in copy, and on a profile page — where the visitor is already signed in —
 * it reads as an invitation to log in again.
 *
 * $ow_link_providers arrives already filtered through
 * IdentityLinkService::unlinked_providers() — see AccountForm::link_providers().
 * A partial that filtered for itself would be a second place for that rule to
 * live.
 *
 * Override at yourtheme/omniwp/partials/account/providers.php
 *
 * @var array<int,array<string,mixed>> $ow_identities
 * @var bool                           $ow_can_unlink
 * @var string                         $ow_redirect
 * @var array<int,object>              $ow_link_providers
 *
 * @package OmniWP
 */

use OmniWP\Auth\ProviderAuthController;
use OmniWP\Frontend\ProviderMark;
use OmniWP\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$ow_identities     = isset( $ow_identities ) && is_array( $ow_identities ) ? $ow_identities : array();
$ow_link_providers = isset( $ow_link_providers ) && is_array( $ow_link_providers ) ? $ow_link_providers : array();

/*
 * The page this control starts on, carried so a refusal can end on it.
 *
 * It used to be omitted, so the transaction held nowhere to return to and a
 * refused link — the common one being "this provider account already belongs to
 * somebody else" — landed on the sign-in step of My Account. A signed-in visitor
 * never sees that screen, and the sentence explaining the refusal was being
 * delivered to it.
 */
$ow_link_return = isset( $ow_redirect ) ? (string) $ow_redirect : '';

TemplateLoader::output(
	'partials/linked-identities',
	array(
		'ow_identities' => $ow_identities,
		'ow_can_unlink' => ! empty( $ow_can_unlink ),
		'ow_redirect'   => isset( $ow_redirect ) ? (string) $ow_redirect : home_url( '/' ),
	)
);
?>

<?php if ( ! empty( $ow_link_providers ) ) : ?>
	<?php
	/*
	 * A row, not a block of buttons.
	 *
	 * 16.3 folded the linked providers into the contact card's own list so the
	 * card holds one list of ways in. A full-width outline button underneath it
	 * was the last piece of the geometry that preceded that — and the loudest
	 * control in a card whose other actions are small text.
	 *
	 * "Google · chưa liên kết · Liên kết" is the same shape as every row above it,
	 * which is also what makes the two halves readable as one list: what you
	 * have, and what you could have.
	 *
	 * `data-sl-provider-mode="link"` and the start URL are unchanged. The
	 * account surface suite asserts that this invitation lives in exactly one
	 * template, and it still does.
	 */
	?>
	<ul class="sl-identity-list sl-link-providers">
		<?php foreach ( $ow_link_providers as $ow_link_provider ) : ?>
			<li class="sl-identity sl-identity--<?php echo esc_attr( $ow_link_provider->id() ); ?>">
				<div class="sl-row">
					<?php ProviderMark::output_for_provider( $ow_link_provider ); ?>
					<span class="sl-row__label"><?php echo esc_html( $ow_link_provider->name() ); ?></span>
					<span class="sl-row__value sl-muted"><?php esc_html_e( 'chưa liên kết', 'omniwp' ); ?></span>

					<a
						class="sl-action"
						href="<?php echo esc_url( ProviderAuthController::start_url( $ow_link_provider->id(), $ow_link_return, true ) ); ?>"
						data-sl-provider="<?php echo esc_attr( $ow_link_provider->id() ); ?>"
						data-sl-provider-mode="link"
					>
						<?php esc_html_e( 'Liên kết', 'omniwp' ); ?>
						<?php
						/*
						 * The visible word is the same in every row, so on its own it
						 * names nothing when a screen reader lists the links on the
						 * page. The brand goes with it, out of sight.
						 */
						?>
						<span class="screen-reader-text">
							<?php echo esc_html( $ow_link_provider->name() ); ?>
						</span>
					</a>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
