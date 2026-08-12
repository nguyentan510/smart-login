<?php
/**
 * Thông tin cá nhân — the fields people actually come here to change.
 *
 * First on the page for that reason. The screen this replaces opened with two
 * OTP panels and a pair of social buttons, and buried the name field under them.
 *
 * Override at yourtheme/omniwp/partials/account/profile.php
 *
 * @var WP_User $ow_user
 * @var string  $ow_gender
 * @var string  $ow_dob    Already formatted d/m/Y.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\TemplateLoader;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

?>
<section class="sl-card" id="sl-section-profile">
	<?php TemplateLoader::output( 'partials/account/card-head', array( 'ow_section' => 'profile' ) ); ?>

	<div class="sl-field">
		<label class="sl-label" for="OmniWP_full_name">
			<?php esc_html_e( 'Họ và tên', 'omniwp' ); ?>
			<span class="sl-required" aria-hidden="true">*</span>
			<span class="screen-reader-text"><?php esc_html_e( '(bắt buộc)', 'omniwp' ); ?></span>
		</label>
		<input
			type="text"
			class="sl-input"
			name="OmniWP_full_name"
			id="OmniWP_full_name"
			value="<?php echo esc_attr( $ow_user->display_name ); ?>"
			autocomplete="name"
			required
		/>
	</div>

	<div class="sl-grid-2">
		<?php if ( Settings::is_on( 'profile.dob' ) ) : ?>
			<div class="sl-field">
				<label class="sl-label" for="sl-dob"><?php esc_html_e( 'Ngày sinh', 'omniwp' ); ?></label>
				<input
					type="text"
					class="sl-input"
					name="OmniWP_dob"
					id="sl-dob"
					value="<?php echo esc_attr( $ow_dob ); ?>"
					placeholder="<?php esc_attr_e( 'dd/mm/yyyy', 'omniwp' ); ?>"
					inputmode="numeric"
					autocomplete="bday"
				/>
			</div>
		<?php endif; ?>

		<?php if ( Settings::is_on( 'profile.gender' ) ) : ?>
			<fieldset class="sl-field sl-field--radio">
				<legend class="sl-label"><?php esc_html_e( 'Giới tính', 'omniwp' ); ?></legend>
				<?php
				foreach ( array(
					'female' => __( 'Nữ', 'omniwp' ),
					'male'   => __( 'Nam', 'omniwp' ),
					'other'  => __( 'Khác', 'omniwp' ),
				) as $ow_value => $ow_label ) :
					?>
					<label class="sl-radio">
						<input type="radio" name="OmniWP_gender" value="<?php echo esc_attr( $ow_value ); ?>" <?php checked( $ow_gender, $ow_value ); ?> />
						<span><?php echo esc_html( $ow_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
	</div>

</section>
