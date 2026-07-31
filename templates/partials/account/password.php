<?php
/**
 * Bảo mật — the password, collapsed until asked for.
 *
 * Three always-visible password boxes on a profile page are three fields most
 * visitors will never touch, sitting between them and the save button. A
 * <details> keeps the section reachable without JavaScript and closed by default.
 *
 * Optional is load-bearing: leaving the fields blank must save the rest of the
 * form untouched, which is why none of them carry `required`.
 *
 * Override at yourtheme/smart-login/partials/account/password.php
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\AccountForm;
use SmartLogin\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$sl_headings = AccountForm::headings();
?>
<section class="sl-card" id="sl-section-password">
	<h3 class="sl-card__title">
		<span class="sl-card__icon" aria-hidden="true">&#9679;</span>
		<?php echo esc_html( $sl_headings['password'] ); ?>
	</h3>

	<details class="sl-disclosure">
		<summary class="sl-disclosure__summary">
			<span><?php esc_html_e( 'Đổi mật khẩu', 'smart-login' ); ?></span>
			<span class="sl-hint"><?php esc_html_e( 'Để trống nếu không muốn thay đổi', 'smart-login' ); ?></span>
		</summary>

		<div class="sl-disclosure__body">
			<?php
			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'         => 'password_current',
					'label'        => __( 'Mật khẩu hiện tại', 'smart-login' ),
					'id'           => 'password_current',
					'required'     => false,
					// Named explicitly: password-field derives this from
					// `'password' === $name`, so `password_current` would otherwise be
					// advertised to password managers as a field to generate a NEW
					// password into.
					'autocomplete' => 'current-password',
				)
			);

			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'         => 'password_1',
					'label'        => __( 'Mật khẩu mới', 'smart-login' ),
					'id'           => 'password_1',
					'required'     => false,
					'autocomplete' => 'new-password',
				)
			);

			TemplateLoader::output(
				'partials/password-field',
				array(
					'name'         => 'password_2',
					'label'        => __( 'Nhập lại mật khẩu mới', 'smart-login' ),
					'id'           => 'password_2',
					'required'     => false,
					'autocomplete' => 'new-password',
				)
			);
			?>
		</div>
	</details>
</section>
