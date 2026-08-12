<?php
/**
 * "CHÚC MỪNG" screen shown right after a successful registration.
 * Override at yourtheme/omniwp/registered-success.php
 *
 * @var array  $notices
 * @var string $redirect
 * @var int    $user_id
 *
 * @package OmniWP
 */

use OmniWP\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$ow_site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
?>
<div class="omniwp omniwp--done">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<p class="sl-congrats-label"><?php esc_html_e( 'CHÚC MỪNG', 'omniwp' ); ?></p>

	<h2 class="sl-congrats-title">
		<?php
		printf(
			/* translators: %s: site name. */
			esc_html__( 'Bạn đã trở thành hội viên của %s!', 'omniwp' ),
			esc_html( $ow_site )
		);
		?>
	</h2>

	<a class="sl-btn sl-btn--primary sl-btn--block" href="<?php echo esc_url( $redirect ); ?>">
		<?php esc_html_e( 'Tiếp tục', 'omniwp' ); ?>
	</a>
</div>
<?php
/*
 * Two things used to happen here and neither survived review.
 *
 * The button said "Khám phá ngay" and led to the account editing form, which is
 * not exploring anything. And a six-second timer moved the visitor on whether
 * or not they had finished reading — a redirect nobody asked for, on a screen
 * whose only job was to be read.
 *
 * The flow this template belongs to now ends on onboarding.php instead, which
 * says what it wants and waits to be told. This file stays because the REST
 * registration path still resolves to STEP_DONE.
 */

