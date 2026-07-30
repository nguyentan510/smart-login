<?php
/**
 * Admin settings screen.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin;

use SmartLogin\Auth\Providers\ProviderCredentials;
use SmartLogin\Installer;
use SmartLogin\OTP\Placeholders;
use SmartLogin\Security\AuditLog;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG       = 'smart-login';
	const AUDIT_SLUG = 'smart-login-audit';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'dev_mode_notice' ) );
		add_filter( 'plugin_action_links_' . SMART_LOGIN_BASENAME, array( $this, 'action_links' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Smart Login', 'smart-login' ),
			__( 'Smart Login', 'smart-login' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-lock',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Cài đặt', 'smart-login' ),
			__( 'Cài đặt', 'smart-login' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Nhật ký', 'smart-login' ),
			__( 'Nhật ký', 'smart-login' ),
			'manage_options',
			self::AUDIT_SLUG,
			array( $this, 'render_audit' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'smart_login_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'smart-login-admin', SMART_LOGIN_URL . 'assets/css/admin.css', array(), SMART_LOGIN_VERSION );
		wp_enqueue_script( 'smart-login-admin', SMART_LOGIN_URL . 'assets/js/admin.js', array(), SMART_LOGIN_VERSION, true );

		wp_localize_script(
			'smart-login-admin',
			'SmartLoginAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( WebhookTester::NONCE ),
				'i18n'    => array(
					'sending' => __( 'Đang gửi…', 'smart-login' ),
					'test'    => __( 'Gửi thử', 'smart-login' ),
					'failed'  => __( 'Không kết nối được tới máy chủ.', 'smart-login' ),
					'prompt'  => __( 'Nhập số điện thoại hoặc email để gửi thử.', 'smart-login' ),
				),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Cài đặt', 'smart-login' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Dev mode leaks codes on screen; make that impossible to forget.
	 */
	public function dev_mode_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$service = new \SmartLogin\OTP\OtpService();

		if ( ! $service->dev_mode_active() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Smart Login:', 'smart-login' ),
			esc_html__( 'Chế độ DEV đang bật — mã OTP được hiển thị trực tiếp trên màn hình. Hãy tắt trước khi đưa lên môi trường thật.', 'smart-login' )
		);
	}

	// -----------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------

	private function tabs(): array {
		return array(
			'general'  => __( 'Chung', 'smart-login' ),
			'otp'      => __( 'OTP', 'smart-login' ),
			'webhook'  => __( 'Push OTP', 'smart-login' ),
			'email'    => __( 'Email', 'smart-login' ),
			'providers' => __( 'Đăng nhập nhanh', 'smart-login' ),
			'advanced' => __( 'Nâng cao', 'smart-login' ),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'smart-login' ) );
		}

		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only tab switch.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$active = isset( $tabs[ $active ] ) ? $active : 'general';
		?>
		<div class="wrap smart-login-admin">
			<h1><?php esc_html_e( 'Smart Login', 'smart-login' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'smart_login_group' );

				// Every tab posts the whole option, so unrendered tabs must keep
				// their current values via hidden inputs.
				$this->render_hidden_for_other_tabs( $active );

				switch ( $active ) {
					case 'otp':
						$this->tab_otp();
						break;
					case 'webhook':
						$this->tab_webhook();
						break;
					case 'email':
						$this->tab_email();
						break;
					case 'providers':
						$this->tab_providers();
						break;
					case 'advanced':
						$this->tab_advanced();
						break;
					default:
						$this->tab_general();
				}

				submit_button( __( 'Lưu thay đổi', 'smart-login' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Which settings belong to which tab, so the others can be preserved.
	 */
	private function tab_fields(): array {
		return array(
			'general'  => array( 'id_mode', 'default_country_code', 'synthetic_email_domain', 'require_verification', 'min_password_length', 'field_dob', 'field_gender', 'field_referral', 'field_email_optional', 'redirect_after_register', 'redirect_after_login', 'terms_url', 'address_enabled', 'address_quick_search', 'address_hide_postcode', 'address_required_in_profile', 'woo_replace_login_form', 'woo_relax_billing_email', 'woo_block_synthetic_emails', 'woo_sync_billing_phone' ),
			'otp'      => array( 'otp_length', 'otp_ttl', 'otp_max_attempts', 'otp_resend_cooldown', 'otp_max_per_destination_hour', 'otp_max_per_ip_hour', 'login_otp_new_device', 'login_max_attempts', 'login_lockout_minutes' ),
			'webhook'  => array( 'webhook_enabled', 'webhook_url', 'webhook_method', 'webhook_content_type', 'webhook_headers', 'webhook_body', 'webhook_timeout', 'webhook_success_path', 'webhook_success_value', 'webhook_retry', 'webhook_idempotency_header' ),
			'email'    => array( 'email_enabled', 'email_from_name', 'email_from_address', 'email_subject', 'email_body', 'email_is_html' ),
			'providers' => array( 'google_enabled', 'google_client_id', 'zalo_enabled', 'zalo_app_id', 'provider_auto_link_email' ),
			'advanced' => array( 'audit_enabled', 'audit_retention_days', 'otp_retention_days', 'delete_data_on_uninstall', 'dev_mode' ),
		);
	}

	private function render_hidden_for_other_tabs( string $active ): void {
		$map     = $this->tab_fields();
		$visible = $map[ $active ] ?? array();

		foreach ( Settings::all() as $key => $value ) {
			if ( in_array( $key, $visible, true ) ) {
				continue;
			}

			if ( 'webhook_headers' === $key ) {
				foreach ( (array) $value as $i => $row ) {
					printf(
						'<input type="hidden" name="%1$s[webhook_headers][%2$d][key]" value="%3$s" /><input type="hidden" name="%1$s[webhook_headers][%2$d][value]" value="%4$s" />',
						esc_attr( Settings::OPTION ),
						(int) $i,
						esc_attr( $row['key'] ?? '' ),
						esc_attr( $row['value'] ?? '' )
					);
				}
				continue;
			}

			printf(
				'<input type="hidden" name="%s[%s]" value="%s" />',
				esc_attr( Settings::OPTION ),
				esc_attr( $key ),
				esc_attr( is_scalar( $value ) ? (string) $value : '' )
			);
		}
	}

	// -----------------------------------------------------------------
	// Field helpers
	// -----------------------------------------------------------------

	private function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	private function text( string $key, string $label, string $help = '', string $type = 'text', array $attrs = array() ): void {
		$value = (string) Settings::get( $key, '' );
		$extra = '';

		foreach ( $attrs as $attr => $attr_value ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $attr_value ) );
		}
		?>
		<tr>
			<th scope="row"><label for="sl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="sl-<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $this->name( $key ) ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				/>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo wp_kses_post( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function checkbox( string $key, string $label, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="hidden" name="<?php echo esc_attr( $this->name( $key ) ); ?>" value="0" />
					<input
						type="checkbox"
						name="<?php echo esc_attr( $this->name( $key ) ); ?>"
						value="1"
						<?php checked( Settings::is_on( $key ) ); ?>
					/>
					<?php echo wp_kses_post( $help ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	private function select( string $key, string $label, array $choices, string $help = '' ): void {
		$current = (string) Settings::get( $key, '' );
		?>
		<tr>
			<th scope="row"><label for="sl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="sl-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $this->name( $key ) ); ?>">
					<?php foreach ( $choices as $value => $text ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, (string) $value ); ?>>
							<?php echo esc_html( $text ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo wp_kses_post( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function textarea( string $key, string $label, string $help = '', int $rows = 6 ): void {
		?>
		<tr>
			<th scope="row"><label for="sl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea
					id="sl-<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $this->name( $key ) ); ?>"
					rows="<?php echo (int) $rows; ?>"
					class="large-text code"
				><?php echo esc_textarea( (string) Settings::get( $key, '' ) ); ?></textarea>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo wp_kses_post( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// -----------------------------------------------------------------
	// Tabs
	// -----------------------------------------------------------------

	private function tab_general(): void {
		?>
		<h2><?php esc_html_e( 'Định danh', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->select(
				'id_mode',
				__( 'Đăng nhập bằng', 'smart-login' ),
				array(
					'phone_only' => __( 'Chỉ số điện thoại', 'smart-login' ),
					'email_only' => __( 'Chỉ email', 'smart-login' ),
					'both'       => __( 'Cả hai', 'smart-login' ),
				),
				__( 'Quyết định trường định danh trên form đăng ký và đăng nhập.', 'smart-login' )
			);

			$this->text(
				'default_country_code',
				__( 'Mã quốc gia mặc định', 'smart-login' ),
				__( 'Chỉ nhập chữ số, ví dụ <code>84</code>. Số nhập dạng <code>0969789475</code> sẽ được chuẩn hoá thành <code>84969789475</code>.', 'smart-login' )
			);

			$this->text(
				'synthetic_email_domain',
				__( 'Domain email ảo', 'smart-login' ),
				__( 'Dùng cho tài khoản chỉ có số điện thoại. Nên giữ đuôi <code>.invalid</code> — theo RFC 2606 domain này không bao giờ phân giải được, nên không thể phát sinh email bounce.', 'smart-login' )
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Đăng ký và hồ sơ', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text( 'min_password_length', __( 'Độ dài mật khẩu tối thiểu', 'smart-login' ), '', 'number', array( 'min' => 6, 'max' => 64 ) );
			$this->checkbox( 'field_dob', __( 'Ngày sinh', 'smart-login' ), __( 'Hiển thị trong phần Thông tin bổ sung của hồ sơ; không hiển thị khi đăng ký.', 'smart-login' ) );
			$this->checkbox( 'field_gender', __( 'Giới tính', 'smart-login' ), __( 'Hiển thị trong phần Thông tin bổ sung của hồ sơ; không hiển thị khi đăng ký.', 'smart-login' ) );
			$this->checkbox( 'field_referral', __( 'Mã giới thiệu', 'smart-login' ), __( 'Hiển thị trong phần Thông tin bổ sung của hồ sơ; không hiển thị khi đăng ký.', 'smart-login' ) );
			$this->text( 'terms_url', __( 'Link điều kiện áp dụng', 'smart-login' ), __( 'Để trống nếu không có trang điều khoản riêng.', 'smart-login' ), 'url' );
			?>
		</table>

		<h2><?php esc_html_e( 'Điều hướng', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text(
				'redirect_after_register',
				__( 'Sau khi đăng ký', 'smart-login' ),
				__( 'Để trống để dùng trang Tài khoản của WooCommerce.', 'smart-login' ),
				'url'
			);
			$this->text(
				'redirect_after_login',
				__( 'Sau khi đăng nhập', 'smart-login' ),
				__( 'Để trống để dùng trang Tài khoản của WooCommerce.', 'smart-login' ),
				'url'
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Địa chỉ 2 cấp', 'smart-login' ); ?></h2>
		<?php $this->render_address_status(); ?>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'address_enabled', __( 'Kích hoạt', 'smart-login' ), __( 'Bật bộ chọn Tỉnh/Thành phố → Phường/Xã', 'smart-login' ) );
			$this->checkbox( 'address_quick_search', __( 'Ô tìm nhanh', 'smart-login' ), __( 'Cho phép gõ thẳng tên phường/xã để tự điền cả hai ô', 'smart-login' ) );
			$this->checkbox( 'address_required_in_profile', __( 'Bắt buộc ở hồ sơ', 'smart-login' ), __( 'Yêu cầu chọn địa chỉ khi cập nhật hồ sơ', 'smart-login' ) );
			$this->checkbox( 'address_hide_postcode', __( 'Ẩn Mã bưu điện', 'smart-login' ), __( 'Bỏ trường Mã bưu điện khỏi form địa chỉ và thanh toán', 'smart-login' ) );
			?>
		</table>

		<h2><?php esc_html_e( 'WooCommerce', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'woo_replace_login_form', __( 'Thay form My Account', 'smart-login' ), __( 'Thay thế form đăng nhập/đăng ký mặc định của WooCommerce', 'smart-login' ) );
			$this->checkbox( 'woo_sync_billing_phone', __( 'Đồng bộ SĐT', 'smart-login' ), __( 'Đồng bộ hai chiều giữa số điện thoại đã xác thực và <code>billing_phone</code>', 'smart-login' ) );
			$this->checkbox( 'woo_relax_billing_email', __( 'Email khi thanh toán', 'smart-login' ), __( 'Bỏ bắt buộc nhập email ở trang thanh toán', 'smart-login' ) );
			$this->checkbox( 'woo_block_synthetic_emails', __( 'Chặn email ảo', 'smart-login' ), __( 'Không gửi bất kỳ email nào tới địa chỉ ảo (khuyến nghị bật)', 'smart-login' ) );
			?>
		</table>
		<?php
	}

	private function tab_otp(): void {
		?>
		<h2><?php esc_html_e( 'Mã xác thực', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text( 'otp_length', __( 'Số ký tự', 'smart-login' ), __( 'Từ 4 đến 8.', 'smart-login' ), 'number', array( 'min' => 4, 'max' => 8 ) );
			$this->text( 'otp_ttl', __( 'Hiệu lực (giây)', 'smart-login' ), __( 'Mặc định 300 giây (5 phút).', 'smart-login' ), 'number', array( 'min' => 60, 'max' => 3600 ) );
			$this->text( 'otp_max_attempts', __( 'Số lần nhập sai tối đa', 'smart-login' ), __( 'Vượt quá thì mã bị huỷ và người dùng phải yêu cầu mã mới.', 'smart-login' ), 'number', array( 'min' => 1, 'max' => 10 ) );
			$this->text( 'otp_resend_cooldown', __( 'Chờ giữa 2 lần gửi (giây)', 'smart-login' ), '', 'number', array( 'min' => 15, 'max' => 600 ) );
			?>
		</table>

		<h2><?php esc_html_e( 'Giới hạn tần suất', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text( 'otp_max_per_destination_hour', __( 'Số mã tối đa / số ĐT / giờ', 'smart-login' ), __( 'Đặt 0 để bỏ giới hạn (không khuyến nghị).', 'smart-login' ), 'number', array( 'min' => 0 ) );
			$this->text( 'otp_max_per_ip_hour', __( 'Số mã tối đa / IP / giờ', 'smart-login' ), '', 'number', array( 'min' => 0 ) );
			?>
		</table>

		<h2><?php esc_html_e( 'Đăng nhập', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text( 'login_max_attempts', __( 'Số lần sai trước khi khoá', 'smart-login' ), '', 'number', array( 'min' => 0, 'max' => 20 ) );
			$this->text( 'login_lockout_minutes', __( 'Thời gian khoá (phút)', 'smart-login' ), '', 'number', array( 'min' => 1, 'max' => 1440 ) );
			$this->checkbox(
				'login_otp_new_device',
				__( 'OTP cho thiết bị lạ', 'smart-login' ),
				__( 'Yêu cầu nhập OTP khi đăng nhập từ thiết bị chưa từng thấy. <strong>Lưu ý chi phí SMS.</strong>', 'smart-login' )
			);
			?>
		</table>
		<?php
	}

	private function tab_webhook(): void {
		$headers = (array) Settings::get( 'webhook_headers', array() );
		$rows    = max( 3, count( $headers ) + 1 );
		?>
		<h2><?php esc_html_e( 'Đẩy OTP ra hệ thống ngoài', 'smart-login' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Plugin sẽ gọi HTTP tới địa chỉ bạn cấu hình mỗi khi cần gửi mã qua SMS/ZNS. Dùng được với mọi gateway, n8n, Make hoặc Zapier mà không cần viết code.', 'smart-login' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'webhook_enabled', __( 'Kích hoạt', 'smart-login' ), __( 'Bật kênh gửi SMS qua webhook', 'smart-login' ) );
			$this->text( 'webhook_url', __( 'URL', 'smart-login' ), __( 'Có thể chứa placeholder, ví dụ <code>https://api.gateway.vn/send?to={{phone_local}}</code>.', 'smart-login' ), 'url' );
			$this->select(
				'webhook_method',
				__( 'Phương thức', 'smart-login' ),
				array(
					'POST' => 'POST',
					'GET'  => 'GET',
				)
			);
			$this->select(
				'webhook_content_type',
				__( 'Kiểu dữ liệu', 'smart-login' ),
				array(
					'application/json'                  => 'application/json',
					'application/x-www-form-urlencoded' => 'application/x-www-form-urlencoded',
				),
				__( 'Với GET, phần Body bên dưới được dùng làm query string.', 'smart-login' )
			);
			$this->text( 'webhook_timeout', __( 'Timeout (giây)', 'smart-login' ), '', 'number', array( 'min' => 3, 'max' => 30 ) );
			$this->checkbox( 'webhook_retry', __( 'Thử lại', 'smart-login' ), __( 'Gọi lại 1 lần sau 2 giây. Chỉ hoạt động khi đã cấu hình header idempotency bên dưới.', 'smart-login' ) );
			$this->text(
				'webhook_idempotency_header',
				__( 'Header idempotency', 'smart-login' ),
				__( 'Chỉ điền khi gateway cam kết chống gửi trùng, ví dụ <code>Idempotency-Key</code>. Plugin gửi cùng một <code>{{delivery_id}}</code> cho cả hai lần thử.', 'smart-login' )
			);
			?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Headers', 'smart-login' ); ?></th>
				<td>
					<table class="widefat sl-headers-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tên', 'smart-login' ); ?></th>
								<th><?php esc_html_e( 'Giá trị', 'smart-login' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php for ( $i = 0; $i < $rows; $i++ ) : ?>
								<tr>
									<td>
										<input
											type="text"
											name="<?php echo esc_attr( $this->name( 'webhook_headers' ) . '[' . $i . '][key]' ); ?>"
											value="<?php echo esc_attr( $headers[ $i ]['key'] ?? '' ); ?>"
											placeholder="Authorization"
											class="regular-text"
										/>
									</td>
									<td>
										<input
											type="text"
											name="<?php echo esc_attr( $this->name( 'webhook_headers' ) . '[' . $i . '][value]' ); ?>"
											value="<?php echo esc_attr( $headers[ $i ]['value'] ?? '' ); ?>"
											placeholder="Bearer xxxxx"
											class="large-text"
										/>
									</td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Bỏ trống dòng không dùng. Giá trị chứa từ khoá bảo mật sẽ được che khi hiển thị kết quả gửi thử.', 'smart-login' ); ?></p>
				</td>
			</tr>

			<?php
			$this->textarea(
				'webhook_body',
				__( 'Body', 'smart-login' ),
				__( 'Với JSON, các giá trị thay thế được escape tự động nên nội dung luôn hợp lệ.', 'smart-login' ),
				7
			);
			?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Placeholder', 'smart-login' ); ?></th>
				<td>
					<table class="widefat striped sl-tokens">
						<tbody>
						<?php foreach ( Placeholders::available_tokens() as $token => $desc ) : ?>
							<tr>
								<td style="width:160px"><code><?php echo esc_html( $token ); ?></code></td>
								<td><?php echo esc_html( $desc ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Điều kiện thành công', 'smart-login' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Nhiều gateway trả về HTTP 200 kèm mã lỗi trong body. Khai báo ở đây để plugin phân biệt được gửi thành công thật sự.', 'smart-login' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php
			$this->text(
				'webhook_success_path',
				__( 'Đường dẫn JSON', 'smart-login' ),
				__( 'Ví dụ <code>CodeResult</code> hoặc <code>data.status</code>. Để trống thì chỉ cần HTTP 2xx là coi như thành công.', 'smart-login' )
			);
			$this->text( 'webhook_success_value', __( 'Giá trị mong đợi', 'smart-login' ), __( 'Ví dụ <code>100</code>.', 'smart-login' ) );
			?>
		</table>

		<?php $this->render_tester( 'sms' ); ?>
		<?php
	}

	private function tab_email(): void {
		?>
		<h2><?php esc_html_e( 'Gửi OTP qua email', 'smart-login' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Dùng hàm wp_mail() của WordPress, nên tự động chạy qua plugin SMTP nếu website đã cài.', 'smart-login' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'email_enabled', __( 'Kích hoạt', 'smart-login' ), __( 'Bật kênh gửi email', 'smart-login' ) );
			$this->text( 'email_from_name', __( 'Tên người gửi', 'smart-login' ), __( 'Để trống để dùng tên website.', 'smart-login' ) );
			$this->text( 'email_from_address', __( 'Email người gửi', 'smart-login' ), __( 'Để trống để dùng cấu hình mặc định của WordPress.', 'smart-login' ), 'email' );
			$this->text( 'email_subject', __( 'Tiêu đề', 'smart-login' ) );
			$this->checkbox( 'email_is_html', __( 'Định dạng', 'smart-login' ), __( 'Gửi dưới dạng HTML', 'smart-login' ) );
			$this->textarea( 'email_body', __( 'Nội dung', 'smart-login' ), __( 'Dùng chung bộ placeholder với tab Push OTP.', 'smart-login' ), 10 );
			?>
		</table>

		<?php $this->render_tester( 'email' ); ?>
		<?php
	}

	private function tab_providers(): void {
		?>
		<h2><?php esc_html_e( 'Đăng nhập nhanh', 'smart-login' ); ?></h2>
		<div class="notice notice-info inline sl-provider-intro">
			<p>
				<?php
				echo wp_kses_post(
					__(
						'Bạn có thể cấu hình trực tiếp tại đây. Client secret được <strong>mã hóa trước khi lưu</strong> và không bao giờ hiển thị lại. Nếu website đã khai báo credentials trong <code>wp-config.php</code> hoặc biến môi trường, cấu hình triển khai đó sẽ được ưu tiên.',
						'smart-login'
					)
				);
				?>
			</p>
		</div>

		<div class="sl-provider-grid">
			<?php
			$this->provider_setup_card(
				'google',
				__( 'Google Login', 'smart-login' ),
				'google_enabled',
				'google_client_id',
				'google_client_secret',
				'google_clear_secret'
			);
			$this->provider_setup_card(
				'zalo',
				__( 'Zalo Login', 'smart-login' ),
				'zalo_enabled',
				'zalo_app_id',
				'zalo_app_secret',
				'zalo_clear_secret'
			);
			?>
		</div>

		<h2><?php esc_html_e( 'Chính sách liên kết tài khoản', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'provider_auto_link_email',
				__( 'Tự liên kết bằng email', 'smart-login' ),
				__( 'Chỉ tự liên kết khi provider xác nhận email đã verified, email trùng chính xác với một tài khoản duy nhất và tài khoản đó không dùng email giả lập.', 'smart-login' )
			);
			?>
		</table>
		<?php
	}

	private function provider_setup_card(
		string $provider,
		string $label,
		string $enabled_key,
		string $client_id_key,
		string $secret_key,
		string $clear_secret_key
	): void {
		$configured  = ProviderCredentials::is_configured( $provider );
		$has_secret = '' !== ProviderCredentials::secret( $provider );
		$source     = ProviderCredentials::source( $provider );
		$source_labels = array(
			'environment' => __( 'wp-config.php / Environment', 'smart-login' ),
			'settings'    => __( 'Settings đã mã hóa', 'smart-login' ),
			'missing'     => __( 'Chưa cấu hình', 'smart-login' ),
		);
		$id_label = 'google' === $provider ? __( 'Google Client ID', 'smart-login' ) : __( 'Zalo App ID', 'smart-login' );
		$secret_label = 'google' === $provider ? __( 'Google Client Secret', 'smart-login' ) : __( 'Zalo App Secret', 'smart-login' );
		?>
		<section class="sl-provider-card" data-provider-card="<?php echo esc_attr( $provider ); ?>">
			<header class="sl-provider-card__header">
				<div>
					<h3><?php echo esc_html( $label ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: credential source. */
							esc_html__( 'Nguồn: %s', 'smart-login' ),
							esc_html( $source_labels[ $source ] ?? $source_labels['missing'] )
						);
						?>
					</p>
				</div>
				<span class="sl-provider-status <?php echo $configured ? 'is-ready' : 'is-missing'; ?>">
					<?php echo $configured ? esc_html__( 'Sẵn sàng', 'smart-login' ) : esc_html__( 'Thiếu credentials', 'smart-login' ); ?>
				</span>
			</header>

			<div class="sl-provider-tabs" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>">
				<button type="button" class="button-link is-active" role="tab" aria-selected="true" data-provider-tab="setup">
					<?php esc_html_e( 'Thiết lập', 'smart-login' ); ?>
				</button>
				<button type="button" class="button-link" role="tab" aria-selected="false" data-provider-tab="docs">
					<?php esc_html_e( 'Hướng dẫn', 'smart-login' ); ?>
				</button>
			</div>

			<div class="sl-provider-panel" data-provider-panel="setup">
				<table class="form-table" role="presentation">
					<tbody>
						<?php $this->checkbox( $enabled_key, __( 'Kích hoạt', 'smart-login' ), __( 'Hiển thị nút đăng nhập khi provider có đủ credentials.', 'smart-login' ) ); ?>
						<tr>
							<th scope="row">
								<label for="sl-<?php echo esc_attr( $client_id_key ); ?>"><?php echo esc_html( $id_label ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="sl-<?php echo esc_attr( $client_id_key ); ?>"
									name="<?php echo esc_attr( $this->name( $client_id_key ) ); ?>"
									value="<?php echo esc_attr( (string) Settings::get( $client_id_key, '' ) ); ?>"
									class="regular-text code"
									autocomplete="off"
								/>
								<?php if ( 'environment' === $source ) : ?>
									<p class="description"><?php esc_html_e( 'Giá trị triển khai đang được ưu tiên; ô này là cấu hình dự phòng.', 'smart-login' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="sl-<?php echo esc_attr( $secret_key ); ?>"><?php echo esc_html( $secret_label ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="sl-<?php echo esc_attr( $secret_key ); ?>"
									name="<?php echo esc_attr( $this->name( $secret_key ) ); ?>"
									value=""
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo $has_secret ? esc_attr__( 'Đã lưu — để trống để giữ nguyên', 'smart-login' ) : esc_attr__( 'Nhập secret', 'smart-login' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Secret chỉ dùng khi lưu; plugin không đưa secret hiện có trở lại HTML.', 'smart-login' ); ?>
								</p>
								<?php if ( $has_secret && 'environment' !== $source ) : ?>
									<label class="sl-provider-clear-secret">
										<input type="checkbox" name="<?php echo esc_attr( $this->name( $clear_secret_key ) ); ?>" value="1" />
										<?php esc_html_e( 'Xóa secret đã lưu khi bấm Lưu thay đổi', 'smart-login' ); ?>
									</label>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Callback URL', 'smart-login' ); ?></th>
							<td>
								<input
									type="text"
									class="large-text code"
									value="<?php echo esc_attr( ProviderCredentials::redirect_uri( $provider ) ); ?>"
									readonly
									data-provider-callback
								/>
								<p class="description"><?php esc_html_e( 'Sao chép chính xác URL này sang cấu hình ứng dụng của provider.', 'smart-login' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="sl-provider-panel sl-provider-docs" data-provider-panel="docs" hidden>
				<?php $this->provider_inline_docs( $provider ); ?>
			</div>
		</section>
		<?php
	}

	private function provider_inline_docs( string $provider ): void {
		if ( 'google' === $provider ) {
			?>
			<h4><?php esc_html_e( 'Tạo OAuth Client trên Google Cloud', 'smart-login' ); ?></h4>
			<ol>
				<li>
					<?php
					printf(
						wp_kses(
							/* translators: %s: Google Cloud Console URL. */
							__( 'Mở <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console → Clients</a>, chọn đúng project.', 'smart-login' ),
							array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
						),
						esc_url( 'https://console.cloud.google.com/auth/clients' )
					);
					?>
				</li>
				<li><?php esc_html_e( 'Cấu hình OAuth consent screen; nếu ứng dụng đang ở Testing, thêm các tài khoản thử nghiệm vào Test users.', 'smart-login' ); ?></li>
				<li><?php esc_html_e( 'Tạo OAuth Client ID loại Web application.', 'smart-login' ); ?></li>
				<li><?php esc_html_e( 'Trong Authorized redirect URIs, dán chính xác Callback URL ở tab Thiết lập.', 'smart-login' ); ?></li>
				<li><?php esc_html_e( 'Sao chép Client ID và Client Secret vào các ô tương ứng, bật Kích hoạt rồi Lưu thay đổi.', 'smart-login' ); ?></li>
			</ol>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Không dùng JavaScript origin thay cho redirect URI. Google yêu cầu URI callback phải khớp chính xác với URI đã khai báo.', 'smart-login' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<h4><?php esc_html_e( 'Tạo ứng dụng Zalo Login', 'smart-login' ); ?></h4>
		<ol>
			<li>
				<?php
				printf(
					wp_kses(
						/* translators: %s: Zalo Developers URL. */
						__( 'Mở <a href="%s" target="_blank" rel="noopener noreferrer">Zalo Developers</a>, tạo hoặc chọn ứng dụng.', 'smart-login' ),
						array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
					),
					esc_url( 'https://developers.zalo.me/' )
				);
				?>
			</li>
			<li><?php esc_html_e( 'Kích hoạt sản phẩm Zalo Login và khai báo domain website theo yêu cầu của ứng dụng.', 'smart-login' ); ?></li>
			<li><?php esc_html_e( 'Trong cấu hình callback/redirect URL, dán chính xác Callback URL ở tab Thiết lập.', 'smart-login' ); ?></li>
			<li><?php esc_html_e( 'Sao chép App ID và App Secret vào các ô tương ứng, bật Kích hoạt rồi Lưu thay đổi.', 'smart-login' ); ?></li>
			<li><?php esc_html_e( 'Nếu ứng dụng Zalo còn ở chế độ phát triển, chỉ các tài khoản được cấp quyền thử nghiệm có thể đăng nhập.', 'smart-login' ); ?></li>
		</ol>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Zalo Login và Zalo OA/ZNS là hai chức năng khác nhau. Phần này chỉ cấu hình đăng nhập; gửi OTP qua OA/ZNS thuộc kênh OTP riêng.', 'smart-login' ); ?></p>
		</div>
		<?php
	}

	private function tab_advanced(): void {
		?>
		<h2><?php esc_html_e( 'Nhật ký & dọn dẹp', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox( 'audit_enabled', __( 'Ghi nhật ký', 'smart-login' ), __( 'Lưu lại các sự kiện đăng nhập / xác thực', 'smart-login' ) );
			$this->text( 'audit_retention_days', __( 'Giữ nhật ký (ngày)', 'smart-login' ), '', 'number', array( 'min' => 1, 'max' => 3650 ) );
			$this->text( 'otp_retention_days', __( 'Giữ bản ghi OTP (ngày)', 'smart-login' ), __( 'Mã đã dùng hoặc hết hạn sẽ bị xoá sau khoảng thời gian này.', 'smart-login' ), 'number', array( 'min' => 1, 'max' => 365 ) );
			?>
		</table>

		<h2><?php esc_html_e( 'Phát triển', 'smart-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->checkbox(
				'dev_mode',
				__( 'Chế độ DEV', 'smart-login' ),
				__( 'Hiển thị mã OTP ngay trên màn hình. Chỉ có tác dụng khi <code>WP_DEBUG</code> bật <strong>và</strong> môi trường không phải <code>production</code>.', 'smart-login' )
			);
			$this->checkbox(
				'delete_data_on_uninstall',
				__( 'Xoá dữ liệu khi gỡ', 'smart-login' ),
				__( 'Xoá bảng, tuỳ chọn và user meta khi gỡ plugin. <strong>Không thể hoàn tác.</strong>', 'smart-login' )
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Tình trạng hệ thống', 'smart-login' ); ?></h2>
		<?php $this->render_status(); ?>
		<?php
	}

	// -----------------------------------------------------------------

	/**
	 * Tell the admin whether the administrative-unit dataset is actually
	 * installed — every address feature silently does nothing without it.
	 */
	private function render_address_status(): void {
		$provinces = count( \SmartLogin\Address\AddressRepository::provinces() );

		if ( \SmartLogin\Address\AddressRepository::is_dataset_installed() ) {
			$wards = \SmartLogin\Address\AddressRepository::count_wards();

			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: province count, 2: ward count. */
						__( 'Đã cài dữ liệu hành chính: %1$d tỉnh/thành, %2$d phường/xã.', 'smart-login' ),
						$provinces,
						$wards
					)
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-error inline"><p><strong>%s</strong> %s</p><p><code>php bin/build-address-data.php path/to/source.json</code></p></div>',
			esc_html__( 'Chưa có dữ liệu hành chính.', 'smart-login' ),
			esc_html__( 'Bộ chọn địa chỉ sẽ không hiển thị gì cho tới khi bạn sinh dữ liệu bằng lệnh sau:', 'smart-login' )
		);
	}

	private function render_tester( string $channel ): void {
		?>
		<h2><?php esc_html_e( 'Gửi thử', 'smart-login' ); ?></h2>
		<div class="sl-tester" data-channel="<?php echo esc_attr( $channel ); ?>">
			<p class="description">
				<?php
				echo 'sms' === $channel
					? esc_html__( 'Lưu cấu hình trước, sau đó gửi một mã thật tới số điện thoại của bạn để kiểm tra.', 'smart-login' )
					: esc_html__( 'Lưu cấu hình trước, sau đó gửi một mã thật tới email của bạn để kiểm tra.', 'smart-login' );
				?>
			</p>
			<p>
				<input
					type="text"
					class="regular-text sl-test-destination"
					placeholder="<?php echo 'sms' === $channel ? esc_attr__( '0969789475', 'smart-login' ) : esc_attr__( 'ban@example.com', 'smart-login' ); ?>"
				/>
				<button type="button" class="button sl-test-button"><?php esc_html_e( 'Gửi thử', 'smart-login' ); ?></button>
			</p>
			<div class="sl-test-result" hidden></div>
		</div>
		<?php
	}

	private function render_status(): void {
		global $wpdb;

		$otp_table   = Installer::otp_table();
		$audit_table = Installer::audit_table();

		$rows = array(
			__( 'Phiên bản plugin', 'smart-login' ) => SMART_LOGIN_VERSION,
			__( 'WooCommerce', 'smart-login' )      => class_exists( 'WooCommerce' ) ? __( 'Đang hoạt động', 'smart-login' ) : __( 'Không có', 'smart-login' ),
			__( 'Môi trường', 'smart-login' )       => wp_get_environment_type(),
			__( 'WP_DEBUG', 'smart-login' )         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'Bật', 'smart-login' ) : __( 'Tắt', 'smart-login' ),
			__( 'Bảng OTP', 'smart-login' )         => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $otp_table ) ) ? $otp_table : __( 'CHƯA TẠO', 'smart-login' ), // phpcs:ignore WordPress.DB
			__( 'Bảng nhật ký', 'smart-login' )     => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) ? $audit_table : __( 'CHƯA TẠO', 'smart-login' ), // phpcs:ignore WordPress.DB
			__( 'Dọn dẹp tiếp theo', 'smart-login' ) => wp_next_scheduled( Installer::CLEANUP_HOOK )
				? wp_date( 'H:i d/m/Y', wp_next_scheduled( Installer::CLEANUP_HOOK ) )
				: __( 'Chưa lên lịch', 'smart-login' ),
		);
		?>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<td style="width:220px"><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><code><?php echo esc_html( (string) $value ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_audit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'smart-login' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only pagination.
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$limit  = 50;
		$offset = ( $paged - 1 ) * $limit;
		$rows   = AuditLog::recent( $limit, $offset );
		?>
		<div class="wrap smart-login-admin">
			<h1><?php esc_html_e( 'Nhật ký Smart Login', 'smart-login' ); ?></h1>

			<?php if ( ! Settings::is_on( 'audit_enabled' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Ghi nhật ký đang tắt. Bật ở tab Nâng cao để bắt đầu ghi.', 'smart-login' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Thời gian (UTC)', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Sự kiện', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Định danh', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'IP', 'smart-login' ); ?></th>
						<th><?php esc_html_e( 'Chi tiết', 'smart-login' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Chưa có bản ghi nào.', 'smart-login' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><code><?php echo esc_html( $row['event'] ); ?></code></td>
						<td><?php echo esc_html( $row['identity_masked'] ); ?></td>
						<td><?php echo esc_html( $row['ip'] ? (string) @inet_ntop( $row['ip'] ) : '—' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors ?></td>
						<td><code><?php echo esc_html( (string) $row['meta'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="tablenav">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Trước', 'smart-login' ); ?></a>
				<?php endif; ?>
				<?php if ( count( $rows ) === $limit ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>"><?php esc_html_e( 'Sau', 'smart-login' ); ?> &raquo;</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
