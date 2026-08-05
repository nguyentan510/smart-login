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

/*
 * Credentials as deployment constants rather than stored secrets: the entry
 * screen only draws a provider button for a provider that is *configured*, and
 * ProviderCredentials::is_configured() reads the constants before it reaches
 * SecretBox. Going through SecretBox here would make this suite depend on
 * openssl, which is exactly the dependency the mail work has already been bitten
 * by. The values are never dialled — nothing in this suite completes an OAuth
 * round trip.
 */
define( 'SMART_LOGIN_GOOGLE_CLIENT_ID', 'google-client-for-render' );
define( 'SMART_LOGIN_GOOGLE_CLIENT_SECRET', 'google-secret-for-render' );
define( 'SMART_LOGIN_ZALO_APP_ID', 'zalo-app-for-render' );
define( 'SMART_LOGIN_ZALO_APP_SECRET', 'zalo-secret-for-render' );

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
// The WooCommerce account template resolves its own account rather than taking
// one as an argument, because Woo calls it with no arguments at all.
$GLOBALS['sl_logged_in']      = true;
$GLOBALS['sl_current_user_id'] = 7;

$fixtures = array(
	// Was excluded from this suite until 8.2, on the grounds that it needed a
	// WooCommerce runtime. It no longer does: it is an adapter over partials that
	// are rendered here anyway. That exclusion is why the most complex template in
	// the plugin went six phases without any test executing it, which is where the
	// account-surface drift accumulated.
	'woocommerce/form-edit-account'  => array(),
	// The same renderer and the same six partials as the Woo template; only the
	// wrapper differs, because FormController saves this one and WC_Form_Handler
	// saves the other.
	'account'                        => array(
		'sl_form' => new \SmartLogin\Frontend\AccountForm( 7, \SmartLogin\Frontend\AccountForm::CONTEXT_STANDALONE ),
		'notices' => array(),
	),
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
		'provinces'    => array( '01' => array( 'name' => 'Thành phố Hà Nội', 'short' => 'Hà Nội', 'type' => 'thanh-pho' ) ),
		'wards'        => array( '00076' => array( 'name' => 'Phường Cầu Giấy', 'type' => 'phuong' ) ),
	),
	'partials/account/status'        => array(
		'sl_status'   => array(
			'complete'            => false,
			'required_missing'    => array( array( 'key' => 'full_name', 'label' => 'Họ tên' ) ),
			'recommended_missing' => array( array( 'key' => 'dob', 'label' => 'Ngày sinh' ) ),
		),
		'sl_pending'  => array( 'type' => 'email', 'masked' => 'ng•••@example.test' ),
		'sl_welcome'  => false,
		'sl_edit_url' => 'https://example.test/my-account/edit-account/',
	),
	'partials/account/contact'       => array(
		'sl_user'       => new WP_User( 7, 'Nguyễn Như' ),
		'sl_phone'      => '84969789475',
		'sl_synthetic'  => false,
		'sl_pending'    => array( 'type' => 'email', 'masked' => 'ng•••@example.test' ),
		'sl_otp_length' => 6,
		'sl_providers'  => array(
			'sl_identities'     => array(),
			'sl_can_unlink'     => false,
			'sl_redirect'       => 'https://example.test/my-account/',
			'sl_link_providers' => array(),
		),
	),
	'partials/account/providers'     => array(
		'sl_identities'     => array(
			array(
				'channel'     => 'google',
				'subject'     => 'sub-1',
				'masked'      => 'sub-••••••',
				'label'       => 'Google',
				'federated'   => true,
				'is_primary'  => true,
				'linked_by'   => 'oauth',
				'verified_at' => '2026-07-30 08:00:00',
				'removable'   => true,
			),
		),
		'sl_can_unlink'     => true,
		'sl_redirect'       => 'https://example.test/my-account/',
		// Deliberately empty: the "everything is already linked" case is the one
		// the WooCommerce copy got wrong for six phases.
		'sl_link_providers' => array(),
	),
	'partials/account/profile'       => array(
		'sl_user'     => new WP_User( 7, 'Nguyễn Như' ),
		'sl_gender'   => 'female',
		'sl_dob'      => '05/10/1994',
		// Empty on purpose: the input renders only when there is no code yet,
		// and the input is what the regression suite greps for.
		'sl_referral' => '',
	),
	'partials/account/address'       => array(
		'sl_values'   => array(
			'province_code' => '01',
			'province_name' => 'Thành phố Hà Nội',
			'ward_code'     => '00076',
			'ward_name'     => 'Phường Cầu Giấy',
			'street'        => '12 Trần Duy Hưng',
		),
		'sl_required' => false,
	),
	'partials/account/password'      => array(),
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
sl_section( 'The ward select explains why it is inert (Phase 8.5)' );

/*
 * The fixture above renders it with a province already chosen, which is the
 * state where there is nothing to explain. This renders the other one — the
 * state a first-time visitor actually sees.
 */
$sl_render_address = static function ( array $wards ) use ( $fixtures, $root ): string {
	$args = $fixtures['partials/address-fields'];
	$args['wards'] = $wards;
	$args['values']['ward_code'] = $wards ? $args['values']['ward_code'] : '';

	ob_start();
	( static function ( string $sl_file, array $sl_args ): void {
		extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $sl_file;
	} )( $root . 'partials/address-fields.php', $args );

	return (string) ob_get_clean();
};

$sl_waiting = $sl_render_address( array() );
$sl_ready   = $sl_render_address( $fixtures['partials/address-fields']['wards'] );

sl_assert(
	'a ward select with nothing to offer says so',
	false !== strpos( $sl_waiting, 'Chọn Tỉnh/Thành phố trước' ),
	'A grey box that never says why reads as broken rather than as waiting.'
);

sl_assert(
	'the explanation is attached to the control, not just near it',
	(bool) preg_match( '/aria-describedby="[^"]*-ward-hint"/', $sl_waiting ),
	'Screen readers get the reason only if the select points at it.'
);

sl_assert(
	'the explanation is absent once wards are available',
	false === strpos( $sl_ready, 'Chọn Tỉnh/Thành phố trước' ),
	'An instruction that no longer applies is worse than none.'
);

sl_assert(
	'the select is only disabled while it is empty',
	false !== strpos( $sl_waiting, 'disabled' ) && false === strpos( $sl_ready, 'disabled' )
);

// ---------------------------------------------------------------------
sl_section( 'Every template on disk is either rendered here or excluded in writing' );

/*
 * Without this, adding a template adds no coverage and nobody notices. That is
 * how a deleted class survived in two templates for four phases.
 *
 * Phase 8.2 extracts six section partials under partials/account/. Each one
 * fails this check the moment it lands and passes once it has a fixture above,
 * which is the mechanism that makes "extend the smoke test" automatic rather
 * than remembered.
 */
$sl_uncovered_ok = array(
	// Documented shims. README points theme authors at form-auth.php; these two
	// are never loaded, which the assertions below this section keep true.
	'form-login'                 => 'shim, never loaded — asserted below',
	'form-register'              => 'shim, never loaded — asserted below',

	// form-edit-account was here until 8.2 and now has a fixture instead.
	'woocommerce/form-login'     => 'needs a WooCommerce runtime',

	// Not a page: it is the wrapper for an HTML email, and it is rendered — by
	// tests/mail/run-template-tests.php, which drives it through MailTransport
	// and asserts the marker, the accent, the footer and the code all arrive.
	// Excluded from this suite because a fixture here would render it into a
	// browser context it never sees, not because nothing renders it.
	'mail/layout'                => 'covered by the mail suite, not a page template',
);

$sl_on_disk = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $sl_file ) {
	if ( ! $sl_file->isFile() || 'php' !== strtolower( $sl_file->getExtension() ) ) {
		continue;
	}

	$sl_relative  = str_replace( '\\', '/', substr( $sl_file->getPathname(), strlen( $root ) ) );
	$sl_on_disk[] = substr( $sl_relative, 0, -4 );
}

sort( $sl_on_disk );

$sl_missing_fixture = array_diff( $sl_on_disk, array_keys( $fixtures ), array_keys( $sl_uncovered_ok ) );

sl_assert(
	'every template has a fixture or a written-down exclusion',
	array() === $sl_missing_fixture,
	'No fixture, so it is never executed: ' . implode( ', ', $sl_missing_fixture )
);

// The exclusion list must not outlive what it excludes.
$sl_stale = array_diff( array_keys( $sl_uncovered_ok ), $sl_on_disk );

sl_assert(
	'the exclusion list names no template that is gone',
	array() === $sl_stale,
	'Stale entries hide the next real gap: ' . implode( ', ', $sl_stale )
);

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
