<?php
/**
 * Backward-compatible login template entrypoint.
 *
 * Login now lives in the all-in-one authentication box. Theme authors should
 * override form-auth.php for the combined experience.
 *
 * @var array $notices
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\Flow;
use SmartLogin\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

TemplateLoader::output(
	'form-auth',
	array(
		'notices'    => $notices ?? array(),
		'active_tab' => Flow::STEP_LOGIN,
		'terms_url'  => '',
	)
);
