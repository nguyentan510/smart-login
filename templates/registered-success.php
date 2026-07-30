<?php
/**
 * "CHÚC MỪNG" screen shown right after a successful registration.
 * Override at yourtheme/smart-login/registered-success.php
 *
 * @var array  $notices
 * @var string $redirect
 * @var int    $user_id
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$sl_site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
?>
<div class="smart-login smart-login--done">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<p class="sl-congrats-label"><?php esc_html_e( 'CHÚC MỪNG', 'smart-login' ); ?></p>

	<h2 class="sl-congrats-title">
		<?php
		printf(
			/* translators: %s: site name. */
			esc_html__( 'Bạn đã trở thành hội viên của %s!', 'smart-login' ),
			esc_html( $sl_site )
		);
		?>
	</h2>

	<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( $redirect ); ?>">
		<?php esc_html_e( 'Khám phá ngay', 'smart-login' ); ?>
	</a>

	<p class="sl-hint">
		<?php esc_html_e( 'Bạn sẽ được chuyển tới trang hồ sơ để bổ sung thông tin.', 'smart-login' ); ?>
	</p>

	<?php
	// Belt and braces: move on automatically if the visitor does nothing.
	wp_print_inline_script_tag(
		sprintf(
			'setTimeout(function(){window.location.href=%s;},6000);',
			wp_json_encode( esc_url_raw( $redirect ) )
		)
	);
	?>
</div>
