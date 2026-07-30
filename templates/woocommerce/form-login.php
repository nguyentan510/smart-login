<?php
/**
 * Replaces WooCommerce's myaccount/form-login.php with the Smart Login flow.
 *
 * Override at yourtheme/woocommerce/myaccount/form-login.php — a theme copy of
 * the WooCommerce template always wins over this one.
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );

$sl_shortcodes = new Shortcodes();

// Templates render trusted plugin markup that escapes its own output.
echo $sl_shortcodes->render_flow( Flow::STEP_LOGIN ); // phpcs:ignore WordPress.Security.EscapeOutput

do_action( 'woocommerce_after_customer_login_form' );
