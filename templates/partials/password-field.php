<?php
/**
 * Password input with a show/hide toggle.
 *
 * @var string $name
 * @var string $label
 * @var string $id
 * @var bool   $required
 * @var string $autocomplete
 * @var int    $minlength
 * @var string $describedby
 * @var bool   $disabled
 * @var bool   $autofocus
 *
 * @package OmniWP
 */

use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$ow_id           = $id ?? 'sl-' . $name;
$ow_required     = $required ?? true;
$ow_autocomplete = $autocomplete ?? ( 'password' === $name ? 'current-password' : 'new-password' );
$ow_minlength    = isset( $minlength ) ? max( 0, (int) $minlength ) : 0;
$ow_describedby  = isset( $describedby ) ? trim( (string) $describedby ) : '';
$ow_disabled     = ! empty( $disabled );
$ow_autofocus    = ! empty( $autofocus );
?>
<div class="sl-field sl-field--password">
	<label class="sl-label" for="<?php echo esc_attr( $ow_id ); ?>">
		<?php echo esc_html( $label ); ?>
		<?php
		if ( $ow_required ) :
			?>
			<span class="sl-required">*</span><?php endif; ?>
	</label>
	<div class="sl-input-wrap">
		<input
			type="password"
			class="sl-input"
			id="<?php echo esc_attr( $ow_id ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			autocomplete="<?php echo esc_attr( $ow_autocomplete ); ?>"
			<?php
			if ( $ow_minlength > 0 ) :
				?>
				minlength="<?php echo esc_attr( $ow_minlength ); ?>"<?php endif; ?>
			<?php
			if ( '' !== $ow_describedby ) :
				?>
				aria-describedby="<?php echo esc_attr( $ow_describedby ); ?>"<?php endif; ?>
			<?php echo $ow_disabled ? 'disabled' : ''; ?>
			<?php echo $ow_required ? 'required' : ''; ?>
			<?php echo $ow_autofocus ? 'autofocus' : ''; ?>
		/>
		<button type="button" class="sl-toggle-password" aria-label="<?php esc_attr_e( 'Hiện mật khẩu', 'omniwp' ); ?>" data-target="<?php echo esc_attr( $ow_id ); ?>">
			<?php echo IconSet::get( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconSet returns fixed markup from a closed set; nothing here comes from input. ?>
		</button>
	</div>
</div>
