<?php
/**
 * Legacy Contact & Login Tab (Deprecated fallback).
 *
 * Contact section is now merged into Security tab (tab-security.php).
 *
 * @var \WP_User                      $user
 * @var \OmniWP\Frontend\AccountForm $ow_form
 * @var array                         $tab
 *
 * @package OmniWP
 */

use OmniWP\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

TemplateLoader::output(
	'account-hub/tab-security',
	array(
		'user'    => $user ?? wp_get_current_user(),
		'ow_form' => $ow_form ?? null,
		'tab'     => $tab ?? array(),
	)
);

