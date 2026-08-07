<?php
/**
 * Thông tin cá nhân — the fields people actually come here to change.
 *
 * First on the page for that reason. The screen this replaces opened with two
 * OTP panels and a pair of social buttons, and buried the name field under them.
 *
 * Override at yourtheme/smart-login/partials/account/profile.php
 *
 * @var WP_User $sl_user
 * @var string  $sl_gender
 * @var string  $sl_dob    Already formatted d/m/Y.
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

?>
<section class="sl-card" id="sl-section-profile">
	<?php TemplateLoader::output( 'partials/account/card-head', array( 'sl_section' => 'profile' ) ); ?>

	<div class="sl-field">
		<label class="sl-label" for="smartlogin_full_name">
			<?php esc_html_e( 'Họ và tên', 'smart-login' ); ?>
			<span class="sl-required" aria-hidden="true">*</span>
			<span class="screen-reader-text"><?php esc_html_e( '(bắt buộc)', 'smart-login' ); ?></span>
		</label>
		<input
			type="text"
			class="sl-input"
			name="smartlogin_full_name"
			id="smartlogin_full_name"
			value="<?php echo esc_attr( $sl_user->display_name ); ?>"
			autocomplete="name"
			required
		/>
	</div>

	<div class="sl-grid-2">
		<?php if ( Settings::is_on( 'profile.dob' ) ) : ?>
			<div class="sl-field">
				<label class="sl-label" for="sl-dob"><?php esc_html_e( 'Ngày sinh', 'smart-login' ); ?></label>
				<input
					type="text"
					class="sl-input"
					name="smartlogin_dob"
					id="sl-dob"
					value="<?php echo esc_attr( $sl_dob ); ?>"
					placeholder="<?php esc_attr_e( 'dd/mm/yyyy', 'smart-login' ); ?>"
					inputmode="numeric"
					autocomplete="bday"
				/>
			</div>
		<?php endif; ?>

		<?php if ( Settings::is_on( 'profile.gender' ) ) : ?>
			<fieldset class="sl-field sl-field--radio">
				<legend class="sl-label"><?php esc_html_e( 'Giới tính', 'smart-login' ); ?></legend>
				<?php
				foreach ( array(
					'female' => __( 'Nữ', 'smart-login' ),
					'male'   => __( 'Nam', 'smart-login' ),
					'other'  => __( 'Khác', 'smart-login' ),
				) as $sl_value => $sl_label ) :
					?>
					<label class="sl-radio">
						<input type="radio" name="smartlogin_gender" value="<?php echo esc_attr( $sl_value ); ?>" <?php checked( $sl_gender, $sl_value ); ?> />
						<span><?php echo esc_html( $sl_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
	</div>

</section>
