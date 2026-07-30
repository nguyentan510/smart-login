<?php
/**
 * Shown when an already-authenticated visitor hits a login/register form.
 * Override at yourtheme/smart-login/logged-in.php
 *
 * @var WP_User $user
 * @var array   $notices
 * @var string  $my_account
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;
?>
<div class="smart-login smart-login--logged-in">

	<?php TemplateLoader::output( 'partials/notices', array( 'notices' => $notices ) ); ?>

	<p class="sl-lead">
		<?php
		printf(
			/* translators: %s: display name. */
			esc_html__( 'Bạn đang đăng nhập với tên %s.', 'smart-login' ),
			'<strong>' . esc_html( $user->display_name ) . '</strong>'
		);
		?>
	</p>

	<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( $my_account ); ?>">
		<?php esc_html_e( 'Vào tài khoản', 'smart-login' ); ?>
	</a>

	<a class="sl-btn sl-btn--outline" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
		<?php esc_html_e( 'Đăng xuất', 'smart-login' ); ?>
	</a>
</div>
