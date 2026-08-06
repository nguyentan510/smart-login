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
 * $sl_link_providers arrives already filtered through
 * IdentityLinkService::unlinked_providers() — see AccountForm::link_providers().
 * A partial that filtered for itself would be a second place for that rule to
 * live.
 *
 * Override at yourtheme/smart-login/partials/account/providers.php
 *
 * @var array<int,array<string,mixed>> $sl_identities
 * @var bool                           $sl_can_unlink
 * @var string                         $sl_redirect
 * @var array<int,object>              $sl_link_providers
 *
 * @package SmartLogin
 */

use SmartLogin\Auth\ProviderAuthController;
use SmartLogin\Frontend\ProviderMark;
use SmartLogin\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$sl_identities     = isset( $sl_identities ) && is_array( $sl_identities ) ? $sl_identities : array();
$sl_link_providers = isset( $sl_link_providers ) && is_array( $sl_link_providers ) ? $sl_link_providers : array();

TemplateLoader::output(
	'partials/linked-identities',
	array(
		'sl_identities' => $sl_identities,
		'sl_can_unlink' => ! empty( $sl_can_unlink ),
		'sl_redirect'   => isset( $sl_redirect ) ? (string) $sl_redirect : home_url( '/' ),
	)
);
?>

<?php if ( ! empty( $sl_link_providers ) ) : ?>
	<?php
	/*
	 * A row, not a block of buttons.
	 *
	 * 16.3 folded the linked providers into the contact card's own list so the
	 * card holds one list of ways in. A full-width outline button underneath it
	 * was the last piece of the geometry that preceded that — and the loudest
	 * control in a card whose other actions are small text.
	 *
	 * "Zalo · chưa liên kết · Liên kết" is the same shape as every row above it,
	 * which is also what makes the two halves readable as one list: what you
	 * have, and what you could have.
	 *
	 * `data-sl-provider-mode="link"` and the start URL are unchanged. The
	 * account surface suite asserts that this invitation lives in exactly one
	 * template, and it still does.
	 */
	?>
	<ul class="sl-identity-list sl-link-providers">
		<?php foreach ( $sl_link_providers as $sl_link_provider ) : ?>
			<li class="sl-identity sl-identity--<?php echo esc_attr( $sl_link_provider->id() ); ?>">
				<div class="sl-row">
					<?php ProviderMark::output_for_provider( $sl_link_provider ); ?>
					<span class="sl-row__label"><?php echo esc_html( $sl_link_provider->name() ); ?></span>
					<span class="sl-row__value sl-muted"><?php esc_html_e( 'chưa liên kết', 'smart-login' ); ?></span>

					<a
						class="sl-action"
						href="<?php echo esc_url( ProviderAuthController::start_url( $sl_link_provider->id(), '', true ) ); ?>"
						data-sl-provider="<?php echo esc_attr( $sl_link_provider->id() ); ?>"
						data-sl-provider-mode="link"
					>
						<?php esc_html_e( 'Liên kết', 'smart-login' ); ?>
						<?php
						/*
						 * The visible word is the same in every row, so on its own it
						 * names nothing when a screen reader lists the links on the
						 * page. The brand goes with it, out of sight.
						 */
						?>
						<span class="screen-reader-text">
							<?php echo esc_html( $sl_link_provider->name() ); ?>
						</span>
					</a>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
