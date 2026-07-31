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
	<div class="sl-link-providers">
		<p class="sl-hint"><?php esc_html_e( 'Thêm một cách đăng nhập nhanh:', 'smart-login' ); ?></p>
		<div class="sl-provider-buttons">
			<?php foreach ( $sl_link_providers as $sl_link_provider ) : ?>
				<a
					class="sl-btn sl-btn--outline"
					href="<?php echo esc_url( ProviderAuthController::start_url( $sl_link_provider->id(), '', true ) ); ?>"
					data-sl-provider="<?php echo esc_attr( $sl_link_provider->id() ); ?>"
					data-sl-provider-mode="link"
				>
					<?php
					/* translators: %s: provider name, e.g. Google. */
					printf( esc_html__( 'Liên kết %s', 'smart-login' ), esc_html( $sl_link_provider->name() ) );
					?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>
