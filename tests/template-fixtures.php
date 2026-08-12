<?php
/**
 * One set of arguments per template, shared by everything that renders one.
 *
 * Extracted from run-template-tests.php in 18.1, unchanged. Two callers now:
 * the smoke test, which renders every template and fails on a throw, and
 * tests/visual/render.php, which renders one into a page somebody can look at.
 *
 * They have to be the same shapes. A picture built from a second set of
 * fixtures is a picture of a screen the suite has never executed, which is the
 * one thing a visual tool must not be.
 *
 * Where a template reads something not listed here it will emit a PHP warning,
 * which the smoke runner turns into a failure — an undeclared variable in a
 * template is a bug whether or not it happens to render.
 *
 * Requires tests/stubs.php and tests/template-stubs.php to have been loaded:
 * several entries construct WP_User.
 *
 * @package OmniWP
 */

defined( 'ABSPATH' ) || exit;

return array(
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
		'ow_form' => new \OmniWP\Frontend\AccountForm( 7, \OmniWP\Frontend\AccountForm::CONTEXT_STANDALONE ),
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
	/*
	 * Filled, because this suite asserts every template produces markup and the
	 * row renders nothing until a site says what its reasons are. The *empty*
	 * path — the one every install runs by default — is asserted separately in
	 * run-sign-in-anywhere-tests.php, where "renders nothing" is the property
	 * being checked rather than a failure.
	 */
	'partials/dialog-benefits'       => array(
		'benefits' => array(
			array(
				'icon'  => '🚚',
				'label' => 'Miễn phí vận chuyển',
			),
			array(
				'icon'  => '⭐',
				'label' => 'Ưu đãi riêng cho thành viên',
			),
		),
	),

	/*
	 * The one owner of a step's heading. It draws nothing when something else
	 * is the outer surface, which is why 19.10 exists: six templates each drew
	 * their own, and inside the dialog that was the second heading on screen.
	 */
	'partials/screen-title'          => array(
		'text' => 'Đăng nhập hoặc đăng ký',
	),

	/*
	 * The dialog shell. One argument, because the shell holds no state — that is
	 * the property rule 4 asserts and the reason it is safe on a cached page.
	 * A fixture with more in it would be describing a template this is not.
	 */
	'login-dialog'                   => array(
		'title' => 'Đăng nhập hoặc đăng ký',
	),
	'logged-in'                      => array(
		'user'       => new WP_User( 7, 'Nguyễn Như' ),
		'notices'    => array(),
		'my_account' => 'https://example.test/my-account/',
	),
	// The header button, both states. Two fixtures rather than one because the
	// shortcode renders two different templates and a fixture per template is
	// what this file is — the signed-out one is an anchor, the signed-in one is
	// a <details> that has to survive with no script at all.
	'login-button'                   => array(
		'label'    => 'Đăng nhập',
		'step'     => 'identify',
		'href'     => 'https://example.test/dang-nhap?OMNIWP_step=identify',
		'class'    => '',
		'collapse' => true,
	),
	'account-button'                 => array(
		'label'    => '0969789475',
		'items'    => array(
			array(
				'key'   => 'account',
				'label' => 'Thông tin cá nhân',
				'icon'  => 'user',
				'url'   => 'https://example.test/my-account/',
			),
			array(
				'key'   => 'don-hang',
				'label' => 'Đơn hàng của tôi',
				'icon'  => 'box',
				'url'   => 'https://example.test/don-hang/',
			),
			array(
				'key'   => 'logout',
				'label' => 'Đăng xuất',
				'icon'  => 'log-out',
				'url'   => 'https://example.test/wp-login.php?action=logout',
			),
		),
		'class'    => '',
		'collapse' => true,
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
		'ow_status'   => array(
			'complete'            => false,
			'required_missing'    => array( array( 'key' => 'full_name', 'label' => 'Họ tên' ) ),
			'recommended_missing' => array( array( 'key' => 'dob', 'label' => 'Ngày sinh' ) ),
		),
		'ow_pending'  => array( 'type' => 'email', 'masked' => 'ng•••@example.test' ),
		'ow_welcome'  => false,
		'ow_edit_url' => 'https://example.test/my-account/edit-account/',
	),
	'partials/account/contact'       => array(
		'ow_user'       => new WP_User( 7, 'Nguyễn Như' ),
		'ow_phone'      => '84969789475',
		'ow_synthetic'  => false,
		'ow_pending'    => array( 'type' => 'email', 'masked' => 'ng•••@example.test' ),
		'ow_otp_length' => 6,
		'ow_providers'  => array(
			// Held an empty list from 8.2 until 16.0, so the smoke test never
			// executed the branch where a contact channel also owns an identity
			// row — the shape 14.4 and 14.5 gave nearly every account, and the one
			// that printed the address twice for four phases.
			'ow_identities'     => array(
				array(
					'channel'     => 'email',
					'subject'     => 'user@example.test',
					'masked'      => 'us•••@example.test',
					'label'       => 'Email',
					'federated'   => false,
					'is_primary'  => true,
					'linked_by'   => 'otp',
					'verified_at' => '2026-07-30 08:00:00',
					'removable'   => true,
				),
			),
			'ow_can_unlink'     => false,
			'ow_redirect'       => 'https://example.test/my-account/',
			'ow_link_providers' => array(),
		),
	),
	'partials/account/providers'     => array(
		'ow_identities'     => array(
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
		'ow_can_unlink'     => true,
		'ow_redirect'       => 'https://example.test/my-account/',
		// Deliberately empty: the "everything is already linked" case is the one
		// the WooCommerce copy got wrong for six phases.
		'ow_link_providers' => array(),
	),
	'partials/account/profile'       => array(
		'ow_user'     => new WP_User( 7, 'Nguyễn Như' ),
		'ow_gender'   => 'female',
		'ow_dob'      => '05/10/1994',
		// Empty on purpose: the input renders only when there is no code yet,
		// and the input is what the regression suite greps for.
		'ow_referral' => '',
	),
	'partials/account/address'       => array(
		'ow_values'   => array(
			'province_code' => '01',
			'province_name' => 'Thành phố Hà Nội',
			'ward_code'     => '00076',
			'ward_name'     => 'Phường Cầu Giấy',
			'street'        => '12 Trần Duy Hưng',
		),
		'ow_required' => false,
	),
	'partials/account/password'      => array(),
	// 17.8. The section id is the whole input: the label and the mark both come
	// from AccountForm::sections_meta(), which is the point of the partial.
	'partials/account/card-head'     => array( 'ow_section' => 'profile' ),
	'partials/linked-identities'     => array(
		'ow_identities' => array(
			array(
				'channel'     => 'google',
				'subject'     => 'sub-1',
				'masked'      => 'sub-••••••',
				'display'     => 'Cai Hoa',
				'label'       => 'Google',
				'federated'   => true,
				'is_primary'  => false,
				'linked_by'   => 'oauth',
				'verified_at' => '2026-07-30 08:00:00',
				'removable'   => true,
			),
			// The row this partial must now drop. Rendering only federated
			// fixtures is how it went unnoticed that it rendered everything.
			array(
				'channel'     => 'phone',
				'subject'     => '84969789475',
				'masked'      => '0969•••475',
				'label'       => 'Số điện thoại',
				'federated'   => false,
				'is_primary'  => true,
				'linked_by'   => 'otp',
				'verified_at' => '2026-07-30 08:00:00',
				'removable'   => true,
			),
		),
		'ow_can_unlink' => true,
		'ow_redirect'   => 'https://example.test/my-account/',
	),
	'ecommerce/slide-cart-drawer'    => array(
		'cart' => \OmniWP\Ecommerce\CartService::get_cart_data(),
	),
	'ecommerce/floating-cart'        => array(
		'cart' => \OmniWP\Ecommerce\CartService::get_cart_data(),
	),
	'ecommerce/cart-page'            => array(),
	'ecommerce/checkout-page'        => array(),
	'ecommerce/review-order'         => array(),
	'ecommerce/address-modal'        => array(
		'addresses' => array(
			array(
				'first_name' => 'Nguyễn Văn An',
				'phone'      => '0901234567',
				'address_1'  => '123 Lê Lợi',
				'state'      => '01',
				'city'       => 'Phường Cầu Giấy',
				'tag'        => 'Nhà riêng',
				'is_default' => true,
			),
		),
		'provinces' => \OmniWP\Address\AddressRepository::provinces(),
	),
	'ecommerce/address-cards'        => array(
		'addresses' => array(
			array(
				'first_name' => 'Nguyễn Văn An',
				'phone'      => '0901234567',
				'address_1'  => '123 Lê Lợi',
				'state'      => '01',
				'city'       => 'Phường Cầu Giấy',
				'tag'        => 'Nhà riêng',
				'is_default' => true,
			),
		),
	),
	'ecommerce/address-modal-dialog' => array(
		'provinces' => \OmniWP\Address\AddressRepository::provinces(),
	),
	'ecommerce/thankyou-page'        => array(
		'order'          => new class() {
			public function get_order_number(): string {
				return '1001';
			}
			public function get_formatted_billing_full_name(): string {
				return 'Nguyễn Văn An';
			}
			public function get_billing_phone(): string {
				return '0901234567';
			}
			public function get_formatted_billing_address(): string {
				return '123 Lê Lợi, Hà Nội';
			}
			public function get_payment_method_title(): string {
				return 'Chuyển khoản ngân hàng';
			}
			public function get_shipping_method(): string {
				return 'Giao hàng tiêu chuẩn';
			}
			public function get_formatted_order_total(): string {
				return '500.000₫';
			}
			public function get_payment_method(): string {
				return 'bacs';
			}
			public function get_total(): float {
				return 500000;
			}
			public function get_items(): array {
				return array();
			}
			public function get_date_created(): object {
				return new class() {
					public function date( string $format ): string {
						return '12/08/2026';
					}
				};
			}
			public function get_total_discount(): float {
				return 0.0;
			}
			public function get_shipping_total(): float {
				return 0.0;
			}
			public function get_billing_email(): string {
				return 'user@example.test';
			}
			public function get_subtotal_to_display(): string {
				return '500.000₫';
			}
		},
		'order_id'       => 1001,
		'status'         => 'processing',
		'status_name'    => 'Đang xử lý',
		'payment_method' => 'bacs',
		'vietqr_url'     => 'https://img.vietqr.io/image/test.png',
	),
	'ecommerce/voucher-picker-modal' => array(
		'vouchers' => array(),
	),
);
