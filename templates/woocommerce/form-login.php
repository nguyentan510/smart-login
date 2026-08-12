<?php
/**
 * Replaces WooCommerce's myaccount/form-login.php with the Smart Login flow.
 *
 * Override at yourtheme/woocommerce/myaccount/form-login.php — a theme copy of
 * the WooCommerce template always wins over this one.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\Flow;
use OmniWP\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );

$ow_shortcodes = new Shortcodes();

// Templates render trusted plugin markup that escapes its own output.
echo $ow_shortcodes->render_flow( Flow::STEP_LOGIN ); // phpcs:ignore WordPress.Security.EscapeOutput

do_action( 'woocommerce_after_customer_login_form' );
