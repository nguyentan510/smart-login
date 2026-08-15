<?php
/**
 * E-Commerce Checkout Service: streamlined Vietnamese checkout form,
 * Address Book integration, and custom template routing.
 *
 * @package OmniWP
 */

namespace OmniWP\Ecommerce;

use OmniWP\Address\AddressBook;
use OmniWP\Address\AddressRepository;
use OmniWP\Frontend\Assets;
use OmniWP\Frontend\TemplateLoader;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class CheckoutService {

	public function register(): void {
		if ( ! Settings::is_on( 'ecommerce.clean_checkout_enabled', true ) ) {
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ), 40, 1 );
		add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields' ), 40, 1 );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'filter_shipping_fields' ), 40, 1 );
		add_filter( 'woocommerce_order_button_text', array( $this, 'filter_order_button_text' ), 40 );
		add_filter( 'woocommerce_get_privacy_policy_text', array( $this, 'filter_privacy_policy_text' ), 30, 2 );
		add_filter( 'woocommerce_checkout_privacy_policy_text', array( $this, 'filter_checkout_privacy_policy_text' ), 30, 1 );

		// Remove default duplicate top coupon notice toggle from WooCommerce.
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );

		// Template override.
		add_filter( 'woocommerce_locate_template', array( $this, 'swap_checkout_template' ), 10, 3 );

		// Enqueue scripts & styles for checkout.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ), 25 );

		// Quick Address creation, set default, delete & Wards AJAX.
		add_action( 'wp_ajax_omniwp_save_checkout_address', array( $this, 'ajax_save_address' ) );
		add_action( 'wp_ajax_nopriv_omniwp_save_checkout_address', array( $this, 'ajax_save_address_nopriv' ) );
		add_action( 'wp_ajax_omniwp_set_default_address', array( $this, 'ajax_set_default_address' ) );
		add_action( 'wp_ajax_omniwp_delete_address', array( $this, 'ajax_delete_address' ) );
		add_action( 'wp_ajax_omniwp_get_wards', array( $this, 'ajax_get_wards' ) );
		add_action( 'wp_ajax_nopriv_omniwp_get_wards', array( $this, 'ajax_get_wards' ) );

		// Voucher Picker AJAX.
		add_action( 'wp_ajax_omniwp_get_checkout_vouchers', array( $this, 'ajax_get_checkout_vouchers' ) );
		add_action( 'wp_ajax_nopriv_omniwp_get_checkout_vouchers', array( $this, 'ajax_get_checkout_vouchers' ) );
		add_action( 'wp_ajax_omniwp_apply_selected_vouchers', array( $this, 'ajax_apply_selected_vouchers' ) );
		add_action( 'wp_ajax_nopriv_omniwp_apply_selected_vouchers', array( $this, 'ajax_apply_selected_vouchers' ) );

		// Render Address Book cards inside billing form, modal via wp_footer (outside <form>).
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_address_cards_picker' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_address_modal_footer' ), 50 );
		add_action( 'wp_footer', array( $this, 'render_voucher_picker_modal_footer' ), 55 );
		add_action( 'wp_footer', array( $this, 'render_order_confirmation_modal_footer' ), 60 );
	}

	/**
	 * Check if address modal assets should be loaded on this page.
	 */
	private function should_load_address_modal(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( isset( $_GET['tab'] ) && 'address' === sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		global $post;
		if ( $post && is_a( $post, 'WP_Post' ) ) {
			foreach ( array( 'woocommerce_checkout', 'omniwp_checkout', 'smart_checkout', 'omniwp_account', 'smart_account', 'woocommerce_my_account' ) as $tag ) {
				if ( has_shortcode( $post->post_content, $tag ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Vietnamese prominent Place Order button text.
	 */
	public function filter_order_button_text(): string {
		return __( 'ĐẶT HÀNG NGAY', 'omniwp' );
	}

	public function swap_checkout_template( $template, $template_name, $template_path ) {
		if ( 'checkout/form-checkout.php' === $template_name ) {
			$theme_override = locate_template(
				array(
					trailingslashit( $template_path ) . $template_name,
					'woocommerce/' . $template_name,
				)
			);

			if ( $theme_override ) {
				return $theme_override;
			}

			$ours = TemplateLoader::locate( 'ecommerce/checkout-page' );

			return is_readable( $ours ) ? $ours : $template;
		}

		if ( 'checkout/review-order.php' === $template_name ) {
			$theme_override = locate_template(
				array(
					trailingslashit( $template_path ) . $template_name,
					'woocommerce/' . $template_name,
				)
			);

			if ( $theme_override ) {
				return $theme_override;
			}

			$ours = TemplateLoader::locate( 'ecommerce/review-order' );

			return is_readable( $ours ) ? $ours : $template;
		}

		if ( 'checkout/thankyou.php' === $template_name && Settings::is_on( 'ecommerce.thankyou_custom_enabled', true ) ) {
			$theme_override = locate_template(
				array(
					trailingslashit( $template_path ) . $template_name,
					'woocommerce/' . $template_name,
				)
			);

			if ( $theme_override ) {
				return $theme_override;
			}

			$ours = TemplateLoader::locate( 'ecommerce/thankyou-page' );

			return is_readable( $ours ) ? $ours : $template;
		}

		return $template;
	}

	public function enqueue_checkout_assets(): void {
		if ( ! $this->should_load_address_modal() ) {
			return;
		}

		Assets::enqueue_address();

		$ver = defined( 'OMNIWP_VERSION' ) ? OMNIWP_VERSION : '1.0.0';

		wp_enqueue_style(
			'omniwp-ecommerce',
			plugins_url( 'assets/css/omniwp-ecommerce.css', OMNIWP_FILE ),
			array( 'omniwp-tokens', 'omniwp-base' ),
			$ver
		);

		wp_enqueue_script(
			'omniwp-checkout',
			plugins_url( 'assets/js/omniwp-checkout.js', OMNIWP_FILE ),
			array( 'jquery' ),
			$ver,
			true
		);

		$user_id   = get_current_user_id();
		$addresses = $user_id ? AddressBook::get_addresses( $user_id ) : array();

		$days_since_last_order = null;
		if ( $user_id && function_exists( 'wc_get_orders' ) ) {
			$last_orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => 1,
					'status'      => array( 'completed', 'processing', 'on-hold' ),
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
			if ( ! empty( $last_orders ) ) {
				$last_order   = reset( $last_orders );
				$date_created = $last_order ? $last_order->get_date_created() : null;
				if ( $date_created ) {
					$days_since_last_order = (int) floor( ( time() - $date_created->getTimestamp() ) / DAY_IN_SECONDS );
				}
			}
		}

		wp_localize_script(
			'omniwp-checkout',
			'omniwpCheckoutConfig',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'omniwp_checkout_nonce' ),
				'isLoggedIn'           => is_user_logged_in(),
				'userAddresses'        => $addresses,
				'provinces'            => AddressRepository::provinces(),
				'confirmModalEnabled'  => Settings::is_on( 'ecommerce.order_confirmation_modal_enabled', true ),
				'confirmDaysThreshold' => (int) Settings::get( 'ecommerce.order_confirmation_days_threshold', 0 ),
				'daysSinceLastOrder'   => $days_since_last_order,
				'i18n'                 => array(
					'saveSuccess'     => __( 'Đã lưu và chọn địa chỉ mới!', 'omniwp' ),
					'saveError'       => __( 'Lỗi lưu địa chỉ, vui lòng kiểm tra lại thông tin.', 'omniwp' ),
					'fillRequired'    => __( 'Vui lòng điền đầy đủ Tên, Số điện thoại và Địa chỉ.', 'omniwp' ),
					'confirmDelete'   => __( 'Bạn có chắc muốn xóa địa chỉ này?', 'omniwp' ),
					'confirmModal'    => array(
						'title'       => __( 'Xác nhận thông tin giao hàng', 'omniwp' ),
						'processing'  => __( 'Đang xử lý đơn hàng...', 'omniwp' ),
					),
				),
			)
		);
	}

	/**
	 * Streamline billing fields for Vietnamese buyers.
	 *
	 * @param array<string, array<string, mixed>> $fields WooCommerce billing fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function filter_billing_fields( array $fields ): array {
		if ( isset( $fields['billing_first_name'] ) ) {
			$fields['billing_first_name']['label']       = __( 'Họ và tên người nhận', 'omniwp' );
			$fields['billing_first_name']['placeholder'] = __( 'Ví dụ: Nguyễn Văn An', 'omniwp' );
			$fields['billing_first_name']['class']       = array( 'form-row-wide' );
			$fields['billing_first_name']['priority']    = 10;
		}

		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['label']       = __( 'Số điện thoại', 'omniwp' );
			$fields['billing_phone']['placeholder'] = __( 'Ví dụ: 0901234567', 'omniwp' );
			$fields['billing_phone']['class']       = array( 'form-row-wide' );
			$fields['billing_phone']['priority']    = 20;
			$fields['billing_phone']['required']    = true;
		}

		if ( isset( $fields['billing_email'] ) ) {
			$fields['billing_email']['label']       = __( 'Địa chỉ email (tùy chọn nhận hóa đơn)', 'omniwp' );
			$fields['billing_email']['placeholder'] = __( 'email@example.com', 'omniwp' );
			$fields['billing_email']['class']       = array( 'form-row-wide' );
			$fields['billing_email']['priority']    = 30;
			$fields['billing_email']['required']    = false;
		}

		if ( isset( $fields['billing_address_1'] ) ) {
			$fields['billing_address_1']['label']       = __( 'Số nhà, tên đường / Thôn xóm', 'omniwp' );
			$fields['billing_address_1']['placeholder'] = __( 'Ví dụ: 123 Đường Lê Lợi', 'omniwp' );
			$fields['billing_address_1']['priority']    = 60;
		}

		// Remove unwanted fields for Vietnamese market.
		unset(
			$fields['billing_last_name'],
			$fields['billing_company'],
			$fields['billing_country'],
			$fields['billing_postcode'],
			$fields['billing_address_2']
		);

		return $fields;
	}

	/**
	 * Streamline shipping fields similarly.
	 *
	 * @param array<string, array<string, mixed>> $fields WooCommerce shipping fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function filter_shipping_fields( array $fields ): array {
		if ( isset( $fields['shipping_first_name'] ) ) {
			$fields['shipping_first_name']['label']       = __( 'Họ và tên người nhận', 'omniwp' );
			$fields['shipping_first_name']['placeholder'] = __( 'Ví dụ: Nguyễn Văn An', 'omniwp' );
			$fields['shipping_first_name']['class']       = array( 'form-row-wide' );
		}

		if ( isset( $fields['shipping_phone'] ) ) {
			$fields['shipping_phone']['label']    = __( 'Số điện thoại', 'omniwp' );
			$fields['shipping_phone']['required'] = true;
		}

		unset(
			$fields['shipping_last_name'],
			$fields['shipping_company'],
			$fields['shipping_country'],
			$fields['shipping_postcode'],
			$fields['shipping_address_2']
		);

		return $fields;
	}

	/**
	 * Remove fields on top-level checkout array.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $fields Checkout fields.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function filter_checkout_fields( array $fields ): array {
		if ( isset( $fields['billing'] ) ) {
			$fields['billing'] = $this->filter_billing_fields( $fields['billing'] );
		}

		if ( isset( $fields['shipping'] ) ) {
			$fields['shipping'] = $this->filter_shipping_fields( $fields['shipping'] );
		}

		if ( isset( $fields['order']['order_comments'] ) ) {
			// We render order_comments manually in our checkout template (Section 5),
			// so remove WooCommerce's default to prevent duplicate rendering.
			unset( $fields['order']['order_comments'] );
		}

		return $fields;
	}

	/**
	 * Render Address Book Card Selector at Checkout.
	 */
	public function render_address_cards_picker(): void {
		if ( ! is_user_logged_in() ) {
			// Show guest quick login banner.
			?>
			<div class="sl-checkout-guest-auth-banner">
				<div class="sl-guest-auth-content">
					<span class="sl-guest-auth-icon">✨</span>
					<div>
						<strong><?php esc_html_e( 'Đã có tài khoản OmniWP?', 'omniwp' ); ?></strong>
						<p><?php esc_html_e( 'Đăng nhập nhanh để chọn địa chỉ đã lưu & dùng điểm ưu đãi.', 'omniwp' ); ?></p>
					</div>
				</div>
				<button type="button" class="sl-btn sl-btn--outline sl-btn--sm sl-login-trigger" data-omniwp="identify">
					<?php esc_html_e( 'Đăng nhập OTP', 'omniwp' ); ?>
				</button>
			</div>
			<?php
			return;
		}

		$user_id   = get_current_user_id();
		$addresses = AddressBook::get_addresses( $user_id );

		// Render only the cards section (NO modal form — that goes to wp_footer).
		TemplateLoader::output(
			'ecommerce/address-cards',
			array(
				'addresses' => $addresses,
			)
		);
	}

	/**
	 * Render Address Modal in wp_footer (outside any <form>) to avoid nested form bug.
	 */
	public function render_address_modal_footer(): void {
		if ( ! is_user_logged_in() || ! $this->should_load_address_modal() ) {
			return;
		}

		$addresses = is_user_logged_in() ? AddressBook::get_addresses( get_current_user_id() ) : array();

		TemplateLoader::output(
			'ecommerce/address-modal-dialog',
			array(
				'provinces' => AddressRepository::provinces(),
				'addresses' => $addresses,
			)
		);
	}

	public function ajax_set_default_address(): void {
		check_ajax_referer( 'omniwp_checkout_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng đăng nhập.', 'omniwp' ) ) );
		}

		$user_id = get_current_user_id();
		$id      = isset( $_POST['address_id'] ) ? sanitize_text_field( wp_unslash( $_POST['address_id'] ) ) : '';

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Mã địa chỉ không hợp lệ.', 'omniwp' ) ) );
		}

		AddressBook::set_default( $user_id, $id );
		$addresses = AddressBook::get_addresses( $user_id );

		wp_send_json_success(
			array(
				'message'   => __( 'Đã đặt làm địa chỉ mặc định!', 'omniwp' ),
				'addresses' => $addresses,
			)
		);
	}

	public function ajax_delete_address(): void {
		check_ajax_referer( 'omniwp_checkout_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng đăng nhập.', 'omniwp' ) ) );
		}

		$user_id = get_current_user_id();
		$id      = isset( $_POST['address_id'] ) ? sanitize_text_field( wp_unslash( $_POST['address_id'] ) ) : '';

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Mã địa chỉ không hợp lệ.', 'omniwp' ) ) );
		}

		AddressBook::delete_address( $user_id, $id );
		$addresses = AddressBook::get_addresses( $user_id );

		wp_send_json_success(
			array(
				'message'   => __( 'Đã xóa địa chỉ!', 'omniwp' ),
				'addresses' => $addresses,
			)
		);
	}

	/**
	 * AJAX endpoint to create and save a new address directly from checkout.
	 */
	public function ajax_save_address(): void {
		check_ajax_referer( 'omniwp_checkout_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng đăng nhập để lưu sổ địa chỉ.', 'omniwp' ) ) );
		}

		$user_id    = get_current_user_id();
		$address_id = isset( $_POST['address_id'] ) ? sanitize_text_field( wp_unslash( $_POST['address_id'] ) ) : ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' );
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$address_1  = isset( $_POST['address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1'] ) ) : '';

		// Modal sends: state = province code, city = ward display name, ward_code = ward numeric code.
		$province_code = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$ward_display  = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
		$ward_code     = isset( $_POST['ward_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ward_code'] ) ) : '';
		$tag           = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : __( 'Nhà riêng', 'omniwp' );
		$is_default    = ! empty( $_POST['is_default'] );

		if ( empty( $first_name ) || empty( $phone ) || empty( $address_1 ) || empty( $province_code ) ) {
			wp_send_json_error( array( 'message' => __( 'Vui lòng điền đầy đủ các thông tin bắt buộc.', 'omniwp' ) ) );
		}

		$state_name = AddressRepository::province_name( $province_code ) ?: $province_code;
		$ward_name  = AddressRepository::ward_name( $ward_code, $province_code ) ?: $ward_display;

		// Map to AddressBook keys: city = province code, state = province code, ward = ward code.
		$entry = array(
			'id'         => $address_id,
			'first_name' => $first_name,
			'phone'      => $phone,
			'address_1'  => $address_1,
			'city'       => $province_code,
			'state'      => $province_code,
			'ward'       => $ward_code,
			'ward_code'  => $ward_code,
			'state_name' => $state_name,
			'ward_name'  => $ward_name,
			'tag'        => $tag,
			'is_default' => $is_default,
			'country'    => 'VN',
		);

		$saved_address = AddressBook::save_address( $user_id, $entry );
		$addresses     = AddressBook::get_addresses( $user_id );

		wp_send_json_success(
			array(
				'message'       => __( 'Đã lưu địa chỉ thành công!', 'omniwp' ),
				'saved_address' => array_merge(
					(array) $saved_address,
					array(
						'state'      => $province_code,
						'state_name' => $state_name,
						'ward_name'  => $ward_name,
						'ward_code'  => $ward_code,
					)
				),
				'addresses'     => $addresses,
			)
		);
	}

	/**
	 * Guest user handler — returns a clear login-required message.
	 */
	public function ajax_save_address_nopriv(): void {
		wp_send_json_error(
			array( 'message' => __( 'Vui lòng đăng nhập để lưu sổ địa chỉ.', 'omniwp' ) ),
			401
		);
	}

	/**
	 * AJAX endpoint to load wards for a given province.
	 */
	public function ajax_get_wards(): void {
		$province = isset( $_GET['province'] ) ? sanitize_text_field( wp_unslash( $_GET['province'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$province = AddressRepository::province_code( $province );

		$out = array();
		if ( '' !== $province ) {
			foreach ( AddressRepository::wards( $province ) as $code => $ward ) {
				$out[] = array(
					'code' => (string) $code,
					'name' => $ward['name'],
				);
			}
		}

		wp_send_json_success( $out );
	}

	/**
	 * Render Shopee-Style Voucher Picker Modal in wp_footer (outside forms).
	 */
	public function render_voucher_picker_modal_footer(): void {
		if ( ! $this->should_load_address_modal() ) {
			return;
		}

		$user_id  = get_current_user_id();
		$vouchers = \OmniWP\Frontend\VoucherService::evaluate_cart_vouchers( $user_id );

		TemplateLoader::output(
			'ecommerce/voucher-picker-modal',
			array(
				'vouchers' => $vouchers,
			)
		);
	}

	/**
	 * AJAX endpoint to fetch evaluated system vouchers for current cart.
	 */
	public function ajax_get_checkout_vouchers(): void {
		$user_id  = get_current_user_id();
		$vouchers = \OmniWP\Frontend\VoucherService::evaluate_cart_vouchers( $user_id );

		wp_send_json_success( $vouchers );
	}

	/**
	 * AJAX endpoint to apply selected vouchers (freeship + discount codes).
	 */
	public function ajax_apply_selected_vouchers(): void {
		check_ajax_referer( 'omniwp_checkout_nonce', 'nonce' );

		$codes_raw = isset( $_POST['codes'] ) ? sanitize_text_field( wp_unslash( $_POST['codes'] ) ) : '';
		$codes     = array_filter( array_map( 'trim', explode( ',', $codes_raw ) ) );

		// Always clear existing coupons first so new selection replaces old ones
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->remove_coupons();
		}

		if ( empty( $codes ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Đã bỏ chọn tất cả mã giảm giá.', 'omniwp' ),
				)
			);
		}

		$applied_count = 0;
		$errors        = array();

		foreach ( $codes as $code ) {
			$res = \OmniWP\Frontend\VoucherService::apply_to_cart( $code );
			if ( $res['success'] ) {
				++$applied_count;
			} else {
				$errors[] = $res['message'];
			}
		}

		if ( $applied_count > 0 ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: applied count */
						__( 'Đã áp dụng thành công %d mã giảm giá!', 'omniwp' ),
						$applied_count
					),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => ! empty( $errors ) ? implode( ' ', $errors ) : __( 'Không thể áp dụng mã đã chọn.', 'omniwp' ),
			)
		);
	}

	/**
	 * Customize WooCommerce privacy policy template text to be concise & high-trust.
	 *
	 * @param string $text Existing text.
	 * @param string $type Policy type ('checkout' or 'registration').
	 * @return string
	 */
	/**
	 * Build custom Terms & Conditions text: Nhấn "Đặt hàng" đồng nghĩa với việc bạn đồng ý tuân theo [Điều khoản domain]
	 *
	 * @return string
	 */
	private function get_custom_terms_notice_html(): string {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $domain ) {
			$domain = preg_replace( '/^www\./i', '', (string) $domain );
		} else {
			$domain = get_bloginfo( 'name' );
		}

		$terms_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '';
		if ( empty( $terms_url ) ) {
			$terms_url = get_privacy_policy_url() ?: home_url( '#' );
		}

		$terms_label = sprintf( __( 'Điều khoản %s', 'omniwp' ), $domain );
		$terms_link  = '<a class="woocommerce-privacy-policy-link" href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html( $terms_label ) . '</a>';

		return sprintf(
			/* translators: %s: terms link */
			__( 'Nhấn "Đặt hàng" đồng nghĩa với việc bạn đồng ý tuân theo %s', 'omniwp' ),
			$terms_link
		);
	}

	/**
	 * Customize WooCommerce privacy policy template text to be concise & high-trust.
	 *
	 * @param string $text Existing text.
	 * @param string $type Policy type ('checkout' or 'registration').
	 * @return string
	 */
	public function filter_privacy_policy_text( string $text, string $type = '' ): string {
		if ( 'checkout' === $type || empty( $type ) ) {
			return $this->get_custom_terms_notice_html();
		}
		return $text;
	}

	/**
	 * Filter raw checkout privacy policy text.
	 *
	 * @param string $text Filtered HTML text.
	 * @return string
	 */
	public function filter_checkout_privacy_policy_text( string $text ): string {
		return $this->get_custom_terms_notice_html();
	}

	/**
	 * Render Order Confirmation Modal in wp_footer (outside forms).
	 */
	public function render_order_confirmation_modal_footer(): void {
		if ( ! $this->should_load_address_modal() || ! Settings::is_on( 'ecommerce.order_confirmation_modal_enabled', true ) ) {
			return;
		}

		TemplateLoader::output( 'ecommerce/order-confirmation-modal' );
	}
}
