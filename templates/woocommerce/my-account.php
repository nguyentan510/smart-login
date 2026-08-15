<?php
/**
 * My Account page override.
 *
 * Seamlessly replaces WooCommerce's default myaccount/my-account.php
 * with the full OmniWP Account Hub Customer Portal.
 *
 * @package OmniWP
 */

use OmniWP\Frontend\AccountHub;

defined( 'ABSPATH' ) || exit;

AccountHub::render();
