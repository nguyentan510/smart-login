<?php
/**
 * Backward-compatible registration template entrypoint.
 *
 * Registration now lives in the all-in-one authentication box. Theme authors
 * should override form-auth.php for the combined experience.
 *
 * @var array  $notices
 * @var string $terms_url
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
		'active_tab' => Flow::STEP_REGISTER,
		'terms_url'  => $terms_url ?? '',
	)
);
