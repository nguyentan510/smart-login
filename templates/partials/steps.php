<?php
/**
 * Progress indicator for the multi-step registration.
 *
 * Shown only where the visitor is genuinely mid-flow. The entry screen has no
 * indicator on purpose: it serves signing in as well as signing up, and a
 * "1/3" there would promise three steps to someone who only has two.
 *
 * Override at yourtheme/omniwp/partials/steps.php
 *
 * @var int      $current 1-based index of the active step.
 * @var string[] $labels
 *
 * @package OmniWP
 */

defined( 'ABSPATH' ) || exit;

$ow_labels  = array_values( (array) $labels );
$ow_total   = count( $ow_labels );
$ow_current = max( 1, min( $ow_total, (int) $current ) );

if ( $ow_total < 2 ) {
	return;
}
?>
<ol class="sl-steps" aria-label="<?php esc_attr_e( 'Tiến độ đăng ký', 'omniwp' ); ?>">
	<?php foreach ( $ow_labels as $ow_index => $ow_label ) : ?>
		<?php
		$ow_position = $ow_index + 1;
		$ow_state    = $ow_position < $ow_current ? 'is-done' : ( $ow_position === $ow_current ? 'is-current' : '' );
		?>
		<li
			class="sl-step <?php echo esc_attr( $ow_state ); ?>"
			<?php echo $ow_position === $ow_current ? 'aria-current="step"' : ''; ?>
		>
			<span class="sl-step__dot" aria-hidden="true"><?php echo esc_html( (string) $ow_position ); ?></span>
			<span class="sl-step__label"><?php echo esc_html( $ow_label ); ?></span>
		</li>
	<?php endforeach; ?>
</ol>
<p class="screen-reader-text">
	<?php
	printf(
		/* translators: 1: current step number, 2: total steps. */
		esc_html__( 'Bước %1$d trên %2$d', 'omniwp' ),
		(int) $ow_current,
		(int) $ow_total
	);
	?>
</p>
