<?php
/**
 * Progress indicator for the multi-step registration.
 *
 * Shown only where the visitor is genuinely mid-flow. The entry screen has no
 * indicator on purpose: it serves signing in as well as signing up, and a
 * "1/3" there would promise three steps to someone who only has two.
 *
 * Override at yourtheme/smart-login/partials/steps.php
 *
 * @var int      $current 1-based index of the active step.
 * @var string[] $labels
 *
 * @package SmartLogin
 */

defined( 'ABSPATH' ) || exit;

$sl_labels  = array_values( (array) $labels );
$sl_total   = count( $sl_labels );
$sl_current = max( 1, min( $sl_total, (int) $current ) );

if ( $sl_total < 2 ) {
	return;
}
?>
<ol class="sl-steps" aria-label="<?php esc_attr_e( 'Tiến độ đăng ký', 'smart-login' ); ?>">
	<?php foreach ( $sl_labels as $sl_index => $sl_label ) : ?>
		<?php
		$sl_position = $sl_index + 1;
		$sl_state    = $sl_position < $sl_current ? 'is-done' : ( $sl_position === $sl_current ? 'is-current' : '' );
		?>
		<li
			class="sl-step <?php echo esc_attr( $sl_state ); ?>"
			<?php echo $sl_position === $sl_current ? 'aria-current="step"' : ''; ?>
		>
			<span class="sl-step__dot" aria-hidden="true"><?php echo esc_html( (string) $sl_position ); ?></span>
			<span class="sl-step__label"><?php echo esc_html( $sl_label ); ?></span>
		</li>
	<?php endforeach; ?>
</ol>
<p class="screen-reader-text">
	<?php
	printf(
		/* translators: 1: current step number, 2: total steps. */
		esc_html__( 'Bước %1$d trên %2$d', 'smart-login' ),
		(int) $sl_current,
		(int) $sl_total
	);
	?>
</p>
