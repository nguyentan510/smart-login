<?php
/**
 * Render every template and fail on anything that throws.
 *
 * This exists because of a real fatal that four other gates all missed. Phase 3
 * deleted Identity\IdentityResolver and removed its callers from includes/ — but
 * not from templates/, which kept calling IdentityResolver::identifier_label().
 * The WooCommerce My Account page fatalled on every load, for four phases.
 *
 * Why nothing caught it:
 *
 *   php -l          checks syntax; PHP resolves class names at RUN time
 *   contract tests  asserted the class was gone, never that nobody referenced it
 *   regression      inspects template source as a string, never executes it
 *   integration     exercises REST and provisioning, renders no template
 *
 * The fitness suite now resolves every SmartLogin class reference to a file,
 * which catches a deleted class. It cannot catch a deleted *method* on a class
 * that still exists — same failure mode, same fatal. Actually running the
 * template is the only thing that catches both.
 *
 * Run with:  php tests/identity/run-template-tests.php
 *
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
require __DIR__ . '/../template-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Settings;

Settings::update(
	array(
		'identity.mode'              => 'both',
		'providers.google.enabled'   => 1,
		'providers.zalo.enabled'     => 0,
		'address.enabled'            => 1,
		'otp.length'                 => 6,
		'signup.min_password_length' => 8,
	)
);

/**
 * Variables each template documents in its @var block, with plausible values.
 *
 * Where a template reads something not listed here it will emit a PHP warning,
 * which this runner turns into a failure — an undeclared variable in a template
 * is a bug whether or not it happens to render.
 */
$fixtures = array(
	'form-auth'                      => array(
		'notices'   => array(),
		'mode'      => 'login',
		'terms_url' => 'https://example.test/terms',
	),
	'form-password'                  => array(
		'notices'  => array(),
		'identity' => '0969789475',
	),
	'form-signup'                    => array(
		'notices'      => array(),
		'grant'        => 'signup-grant-token',
		'terms_url'    => 'https://example.test/terms',
		'min_password' => 8,
	),
	'onboarding'                     => array(
		'notices'       => array(),
		'user'          => new WP_User( 7, 'Nguyễn Như' ),
		'fields'        => array(
			array(
				'key'    => 'address',
				'label'  => 'Địa chỉ',
				'reason' => 'Để đơn hàng được giao đúng nơi',
			),
			array(
				'key'    => 'dob',
				'label'  => 'Ngày sinh',
				'reason' => 'Để nhận ưu đãi vào dịp sinh nhật',
			),
			array(
				'key'    => 'gender',
				'label'  => 'Giới tính',
				'reason' => 'Để gợi ý sản phẩm hợp với bạn hơn',
			),
		),
		'redirect'      => 'https://example.test/my-account/',
		'address'       => array(
			'province_code' => '',
			'province_name' => '',
			'ward_code'     => '',
			'ward_name'     => '',
			'street'        => '',
		),
		'email_missing' => true,
	),
	'form-forgot'                    => array( 'notices' => array() ),
	'partials/steps'                 => array(
		'current' => 2,
		'labels'  => array( 'Số điện thoại', 'Xác thực', 'Thông tin' ),
	),
	'form-otp'                       => array(
		'notices'      => array(),
		'intent'       => 'register',
		'masked'       => '096••••475',
		'expires_in'   => 300,
		'resend_after' => 60,
		'transport'    => 'sms',
		'otp_length'   => 6,
		'dev_code'     => '',
		'has_session'  => true,
	),
	'form-reset'                     => array(
		'notices' => array(),
		'grant'   => 'grant-token',
	),
	'logged-in'                      => array(
		'user'       => new WP_User( 7, 'Nguyễn Như' ),
		'notices'    => array(),
		'my_account' => 'https://example.test/my-account/',
	),
	'profile-summary'                => array(
		'user'      => new WP_User( 7, 'Nguyễn Như' ),
		'notices'   => array(),
		'missing'   => array(),
		'phone'     => '0969789475',
		'synthetic' => false,
		'welcome'   => false,
		'status'    => array(
			'complete'            => true,
			'required_missing'    => array(),
			'recommended_missing' => array(),
		),
		'pending'   => array(),
	),
	'registered-success'             => array(
		'notices'  => array(),
		'redirect' => 'https://example.test/my-account/',
		'user_id'  => 7,
	),
	'partials/notices'               => array( 'notices' => array( array( 'type' => 'error', 'message' => 'Sai mật khẩu.' ) ) ),
	'partials/password-field'        => array(
		'name'         => 'password',
		'label'        => 'Mật khẩu',
		'id'           => 'sl-pass',
		'required'     => true,
		'autocomplete' => 'current-password',
		'minlength'    => 8,
		'describedby'  => '',
		'disabled'     => false,
	),
	'partials/address-fields'        => array(
		'values'       => array(
			'province_code' => '01',
			'province_name' => 'Thành phố Hà Nội',
			'ward_code'     => '00076',
			'ward_name'     => 'Phường Cầu Giấy',
			'street'        => '12 Trần Duy Hưng',
		),
		'required'     => true,
		'quick_search' => true,
		'provinces'    => array( '01' => array( 'name' => 'Thành phố Hà Nội', 'short' => 'Hà Nội', 'type' => 'thanh-pho' ) ),
		'wards'        => array( '00076' => array( 'name' => 'Phường Cầu Giấy', 'type' => 'phuong' ) ),
	),
	'partials/linked-identities'     => array(
		'sl_identities' => array(
			array(
				'channel'     => 'google',
				'subject'     => 'sub-1',
				'masked'      => 'sub-••••••',
				'label'       => 'Google',
				'federated'   => true,
				'is_primary'  => false,
				'linked_by'   => 'oauth',
				'verified_at' => '2026-07-30 08:00:00',
				'removable'   => true,
			),
		),
		'sl_can_unlink' => true,
		'sl_redirect'   => 'https://example.test/my-account/',
	),
);

// ---------------------------------------------------------------------
sl_section( 'Every template renders without throwing' );

$root = dirname( __DIR__, 2 ) . '/templates/';

foreach ( $fixtures as $template => $args ) {
	$file = $root . $template . '.php';

	if ( ! is_readable( $file ) ) {
		sl_assert( sprintf( '%s exists', $template ), false, 'Fixture names a template that is not there.' );
		continue;
	}

	$GLOBALS['sl_template_warnings'] = array();

	set_error_handler(
		static function ( int $severity, string $message ) {
			$GLOBALS['sl_template_warnings'][] = $message;
			return true;
		}
	);

	ob_start();

	try {
		( static function ( string $sl_file, array $sl_args ): void {
			extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $sl_file;
		} )( $file, $args );

		$html  = (string) ob_get_clean();
		$error = null;
	} catch ( Throwable $exception ) {
		ob_end_clean();
		$html  = '';
		$error = get_class( $exception ) . ': ' . $exception->getMessage();
	} finally {
		restore_error_handler();
	}

	$warnings = $GLOBALS['sl_template_warnings'];

	sl_assert(
		sprintf( '%s renders', $template ),
		null === $error,
		(string) $error
	);

	if ( null !== $error ) {
		continue;
	}

	sl_assert(
		sprintf( '%s emits no PHP notice', $template ),
		array() === $warnings,
		implode( ' | ', array_slice( $warnings, 0, 3 ) )
	);

	sl_assert(
		sprintf( '%s produces markup', $template ),
		'' !== trim( $html ),
		'Rendered empty, which usually means an early return on a missing variable.'
	);
}

// ---------------------------------------------------------------------
sl_section( 'The shims that are documented as unused really are unused' );

// README tells theme authors to override form-auth.php. form-login.php and
// form-register.php are never loaded, so a reader who overrides them gets no
// effect and no explanation. Assert the claim so the README stays true.
$shortcodes = sl_source( 'includes/Frontend/class-shortcodes.php' );

sl_assert(
	'the login/register flow renders form-auth',
	false !== strpos( $shortcodes, "'form-auth'" )
);
sl_assert(
	'nothing loads form-login',
	false === strpos( $shortcodes, "'form-login'" ),
	'If this starts loading, the README note about the shims must change.'
);

// ---------------------------------------------------------------------
sl_summary( 'Templates' );
