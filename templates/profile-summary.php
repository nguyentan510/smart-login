<?php
/**
 * Profile summary with the "complete your profile" nudge.
 * Override at yourtheme/omniwp/profile-summary.php
 *
 * @var WP_User  $user
 * @var array    $notices
 * @var string[] $missing
 * @var string   $phone
 * @var bool     $synthetic
 * @var bool     $welcome
 * @var array    $status
 * @var array    $pending
 *
 * @package OmniWP
 */

use OmniWP\Frontend\AccountForm;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Identity\Phone;

defined( 'ABSPATH' ) || exit;

// Summary context: same status notice and same provider section as the editing
// surface, drawn from the same two partials. They used to be a second copy here,
// and this was the copy that stayed correct while the WooCommerce one drifted.
$ow_summary = new AccountForm( (int) $user->ID, AccountForm::CONTEXT_SUMMARY );
?>
<div class="omniwp omniwp--profile">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<?php $ow_summary->output_status(); ?>

	<?php $ow_summary->output_section( 'providers' ); ?>

	<dl class="sl-profile-list">
		<dt><?php esc_html_e( 'Họ và tên', 'omniwp' ); ?></dt>
		<dd><?php echo esc_html( $user->display_name ); ?></dd>

		<?php if ( '' !== $phone ) : ?>
			<dt><?php esc_html_e( 'Số điện thoại', 'omniwp' ); ?></dt>
			<dd><?php echo esc_html( Phone::to_local( $phone ) ); ?></dd>
		<?php endif; ?>

		<dt><?php esc_html_e( 'Email', 'omniwp' ); ?></dt>
		<dd>
			<?php if ( $synthetic ) : ?>
				<em class="sl-muted"><?php esc_html_e( 'Chưa cung cấp', 'omniwp' ); ?></em>
			<?php else : ?>
				<?php echo esc_html( $user->user_email ); ?>
			<?php endif; ?>
		</dd>
	</dl>

	<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( AccountForm::edit_url() ); ?>">
		<?php esc_html_e( 'Chỉnh sửa thông tin', 'omniwp' ); ?>
	</a>
</div>
