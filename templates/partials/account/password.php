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
 * An account with no email or phone identity gets no boxes at all. Its password is
 * the 64-character random string the provider flow generated, so "Mật khẩu hiện tại"
 * is unsatisfiable and `FormController::save_password()` refuses without it — the
 * error reading as a typo to somebody who never had one. And a password would not
 * help yet: with no identifier the login screen accepts, there is nothing to type it
 * against. `$sl_has_contact` comes from the directory, never from `user_email`; see
 * AccountForm::has_contact_identity() for why that distinction is the whole fix.
 *
 * Override at yourtheme/smart-login/partials/account/password.php
 *
 * @var bool $sl_has_contact
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Security\SecurityMeta;

defined( 'ABSPATH' ) || exit;

$sl_has_contact = ! empty( $sl_has_contact );

/*
 * '' for every account that exists on the day 17.6 ships, and that is the
 * designed answer rather than a fallback — see SecurityMeta. The row renders the
 * control without an age instead of guessing one from user_registered, which
 * would be wrong for exactly the people most likely to read it.
 */
$sl_password_age = isset( $sl_user ) && $sl_user instanceof WP_User
	? SecurityMeta::describe_password_age( (int) $sl_user->ID )
	: '';
?>
<section class="sl-card" id="sl-section-password">
	<?php TemplateLoader::output( 'partials/account/card-head', array( 'sl_section' => 'password' ) ); ?>

	<?php if ( ! $sl_has_contact ) : ?>
		<p class="sl-hint">
			<?php esc_html_e( 'Tài khoản của bạn đang đăng nhập bằng Google hoặc Zalo và chưa có email hoặc số điện thoại nào được xác thực.', 'smart-login' ); ?>
		</p>
		<p class="sl-hint">
			<?php esc_html_e( 'Hãy xác thực email hoặc số điện thoại trước — đó là thông tin bạn sẽ dùng để đăng nhập. Sau đó bạn có thể đặt mật khẩu.', 'smart-login' ); ?>
		</p>
		<a class="sl-btn sl-btn--outline" href="#sl-section-contact">
			<?php esc_html_e( 'Tới mục Liên hệ', 'smart-login' ); ?>
		</a>
	<?php else : ?>
	<details class="sl-disclosure">
		<summary class="sl-disclosure__summary">
			<span><?php esc_html_e( 'Đổi mật khẩu', 'smart-login' ); ?></span>
			<?php if ( '' !== $sl_password_age ) : ?>
				<span class="sl-hint">
					<?php
					/* translators: %s: a relative age, e.g. "3 tháng trước". */
					printf( esc_html__( 'Đổi lần cuối %s', 'smart-login' ), esc_html( $sl_password_age ) );
					?>
				</span>
			<?php else : ?>
				<span class="sl-hint"><?php esc_html_e( 'Để trống nếu không muốn thay đổi', 'smart-login' ); ?></span>
			<?php endif; ?>
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

			<?php
			/*
			 * The route for somebody who cannot fill the first box.
			 *
			 * An account may hold a contact identity and still have a password nobody
			 * knows — a provider signup that later verified an email is the ordinary
			 * case. Named rather than detected: knowing which accounts have a chosen
			 * password would need a second source of truth that cannot be reconstructed
			 * for existing accounts, and somebody who does know their password loses
			 * nothing by reading one extra line.
			 *
			 * A sentence from 14.3 until P3, because the plugin had no addressable URL
			 * for its own sign-in screen from another page. `Flow::login_url()` is that
			 * URL now — a filter, then the page hosting the shortcode. It still returns
			 * '' on a site that has neither, and the sentence is what that site keeps.
			 */
			$sl_login_url = \SmartLogin\Frontend\Flow::login_url();
			?>
			<p class="sl-hint">
				<?php if ( '' !== $sl_login_url ) : ?>
					<?php
					printf(
						/* translators: %s: link to the sign-in screen. */
						esc_html__( 'Chưa có mật khẩu, hoặc không nhớ? %s và chọn "Chưa có mật khẩu, hoặc không nhớ?" để nhận mã xác thực và đặt mật khẩu mới.', 'smart-login' ),
						'<a class="sl-link" href="' . esc_url( $sl_login_url ) . '">'
							. esc_html__( 'Mở màn hình đăng nhập', 'smart-login' )
							. '</a>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Chưa có mật khẩu, hoặc không nhớ? Ở màn hình đăng nhập, chọn "Chưa có mật khẩu, hoặc không nhớ?" để nhận mã xác thực và đặt mật khẩu mới.', 'smart-login' ); ?>
				<?php endif; ?>
			</p>
		</div>
	</details>
	<?php endif; ?>
</section>
