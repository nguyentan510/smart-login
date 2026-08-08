<?php
/**
 * Every setting this plugin understands, declared exactly once.
 *
 * The old schema lived in four places that had to be kept in step by hand:
 * Settings::defaults() held the values, Settings::int_fields() held the types,
 * SettingsPage::tab_fields() held the tab membership, and the tab_*() methods
 * held the actual controls. Nothing checked that the four agreed, and when they
 * disagreed the failure was silent — `field_email_optional` was claimed by the
 * Chung tab and drawn by nothing, so it vanished from both the hidden inputs and
 * $_POST, and sanitize() read that absence as an unchecked box and stored 0.
 *
 * Here the same array decides the default, the type, the sanitiser, the tab, and
 * the control that gets drawn. A key cannot belong to a tab it is not rendered
 * on, because membership and rendering read the same row. That class of bug is
 * not guarded against; it is unrepresentable.
 *
 * This lives in the core namespace rather than under Admin because it is the
 * schema, not the screen. Settings reads it on every request; Admin only borrows
 * it to draw things.
 *
 * @package SmartLogin
 */

namespace SmartLogin;

use SmartLogin\OTP\Transports\WebhookTransport;

defined( 'ABSPATH' ) || exit;

final class FieldRegistry {

	/**
	 * Tabs that hold fields. The overview screen has none and is not listed.
	 *
	 * Flat on purpose, even though four of these are one family. A save writes
	 * only the fields carried by the tab named in the POST
	 * (`Settings::sanitize()`), so every screen with its own Save button has to
	 * be a tab in its own right — a nested structure here would have meant
	 * teaching `posted_fields()` about depth for no gain. Hierarchy is
	 * presentation, and lives in tab_parents().
	 */
	public static function tabs(): array {
		return array(
			'auth'           => __( 'Đăng nhập & Đăng ký', 'smart-login' ),
			'providers'      => __( 'Đăng nhập nhanh', 'smart-login' ),
			'delivery'       => __( 'Gửi mã', 'smart-login' ),
			'delivery-sms'   => __( 'Kênh SMS', 'smart-login' ),
			'delivery-email' => __( 'Kênh Email', 'smart-login' ),
			'delivery-mail'  => __( 'Nội dung email', 'smart-login' ),
			'integrations'   => __( 'Thông báo & Tích hợp', 'smart-login' ),
			'profile'        => __( 'Hồ sơ & Địa chỉ', 'smart-login' ),
			'security'       => __( 'Chống lạm dụng', 'smart-login' ),
			'advanced'       => __( 'Nâng cao', 'smart-login' ),
		);
	}

	/**
	 * Which tabs are drawn as a second level under another.
	 *
	 * Six top-level tabs became nine when the delivery screen was split, and a
	 * nine-item `nav-tab-wrapper` is worse than the twenty-eight-control page it
	 * was meant to fix. The children hang off their parent instead.
	 *
	 * @return array<string,string> child slug => parent slug.
	 */
	public static function tab_parents(): array {
		return array(
			'delivery-sms'   => 'delivery',
			'delivery-email' => 'delivery',
			'delivery-mail'  => 'delivery',
		);
	}

	/** The top-level tab a slug belongs to; itself when it has no parent. */
	public static function parent_tab( string $tab ): string {
		return self::tab_parents()[ $tab ] ?? $tab;
	}

	/**
	 * The label a second-level tab shows for its own parent.
	 *
	 * The parent screen is the first entry in its own sub-nav, so it needs a name
	 * that describes what it holds rather than the family it heads — "Gửi mã" is
	 * the group, "Chính sách mã" is the page.
	 */
	public static function self_label( string $tab ): string {
		return 'delivery' === $tab
			? __( 'Chính sách mã', 'smart-login' )
			: ( self::tabs()[ $tab ] ?? $tab );
	}

	/** Section headings, in render order within their tab. */
	public static function sections(): array {
		return array(
			'identity'     => __( 'Định danh', 'smart-login' ),
			'signup'       => __( 'Đăng ký và điều hướng', 'smart-login' ),
			'login'        => __( 'Bảo mật đăng nhập', 'smart-login' ),
			'provider'     => __( 'Nhà cung cấp', 'smart-login' ),
			'linking'      => __( 'Chính sách liên kết tài khoản', 'smart-login' ),
			'routing'      => __( 'Định tuyến', 'smart-login' ),
			'otp'          => __( 'Mã xác thực', 'smart-login' ),
			'sms'          => __( 'Gửi qua SMS', 'smart-login' ),
			'email'        => __( 'Gửi qua email', 'smart-login' ),
			'automation'   => __( 'Automation / Webhook', 'smart-login' ),
			// The mail screen, in the order an administrator reads it: what every
			// message falls back to, the messages themselves, the operational
			// alerts, then how an HTML one is dressed.
			'mail_default' => __( 'Mẫu mặc định', 'smart-login' ),
			'templates'    => __( 'Mã xác thực', 'smart-login' ),
			'mail_admin'   => __( 'Cảnh báo quản trị', 'smart-login' ),
			'mail_design'  => __( 'Giao diện email HTML', 'smart-login' ),
			'fields'       => __( 'Trường hồ sơ', 'smart-login' ),
			'address'      => __( 'Địa chỉ 2 cấp', 'smart-login' ),
			'woo'          => __( 'WooCommerce', 'smart-login' ),
			'budget'       => __( 'Trần gửi toàn site', 'smart-login' ),
			'breaker'      => __( 'Ngắt mạch kênh gửi', 'smart-login' ),
			'captcha'      => __( 'Xác minh chống robot', 'smart-login' ),
			'network'      => __( 'Proxy và địa chỉ IP', 'smart-login' ),
			'audit'        => __( 'Nhật ký & dọn dẹp', 'smart-login' ),
			'dev'          => __( 'Phát triển', 'smart-login' ),
		);
	}

	/**
	 * The schema. Keys are dot paths into the stored option.
	 *
	 * Recognised per-field keys:
	 *   type     text|number|select|checkbox|textarea|url|email|headers
	 *   default  the value used when nothing is stored
	 *   tab      which tab draws it (must exist in tabs())
	 *   section  which heading it sits under (must exist in sections())
	 *   label    the control's label
	 *   help     description below the control, may contain inline HTML
	 *   choices  select only
	 *   min/max  number only — used for BOTH the input attributes and the clamp,
	 *            so the browser hint and the server rule cannot drift apart
	 *   sanitize names a special-case sanitiser; otherwise the type decides
	 *   rows     textarea only
	 */
	public static function all(): array {
		return array_merge(
			self::auth_fields(),
			self::provider_fields(),
			self::delivery_fields(),
			// Generated, not typed. One row in MailRegistry produces the subject
			// and body pair for a message, so a message cannot be editable
			// without being declared or declared without being editable.
			\SmartLogin\Mail\MailRegistry::fields(),
			self::profile_fields(),
			self::security_fields(),
			self::advanced_fields(),
			self::programmatic_fields()
		);
	}

	/**
	 * Settings no form draws, set by code or a filter instead.
	 *
	 * They still belong in the registry: Settings hydrates the stored option
	 * strictly against these paths, so a value absent from here would be dropped
	 * on the next read. What keeps them clear of the defect this class exists to
	 * prevent is the empty `tab` — a save only ever writes the fields carried by
	 * the tab it came from, and no tab carries these, so nothing can zero them.
	 */
	private static function programmatic_fields(): array {
		return array(
			'channels.enabled' => array(
				'type'    => 'passthrough',
				'default' => null,
				'tab'     => '',
				'section' => '',
				'label'   => __( 'Kênh định danh được bật', 'smart-login' ),
			),
		);
	}

	private static function auth_fields(): array {
		return array(
			'identity.mode'                  => array(
				'type'    => 'select',
				'default' => 'phone_only',
				'tab'     => 'auth',
				'section' => 'identity',
				'label'   => __( 'Đăng nhập bằng', 'smart-login' ),
				'choices' => array(
					'phone_only' => __( 'Chỉ số điện thoại', 'smart-login' ),
					'email_only' => __( 'Chỉ email', 'smart-login' ),
					'both'       => __( 'Cả hai', 'smart-login' ),
				),
				'help'    => __( 'Quyết định trường định danh trên màn hình đăng nhập/đăng ký.', 'smart-login' ),
			),
			'identity.country_code'          => array(
				'type'     => 'select',
				'default'  => '84',
				'tab'      => 'auth',
				'section'  => 'identity',
				'label'    => __( 'Mã quốc gia mặc định', 'smart-login' ),
				'choices'  => array(
					'84'  => __( 'Việt Nam (+84)', 'smart-login' ),
					'855' => __( 'Campuchia (+855)', 'smart-login' ),
					'856' => __( 'Lào (+856)', 'smart-login' ),
					'65'  => __( 'Singapore (+65)', 'smart-login' ),
					'66'  => __( 'Thái Lan (+66)', 'smart-login' ),
					'60'  => __( 'Malaysia (+60)', 'smart-login' ),
					'63'  => __( 'Philippines (+63)', 'smart-login' ),
					'62'  => __( 'Indonesia (+62)', 'smart-login' ),
					'1'   => __( 'Hoa Kỳ / Canada (+1)', 'smart-login' ),
				),
				// Kept as a fallback for a value arriving from anywhere but the
				// form: the select cannot produce a bad code, but a filter or a
				// direct update() still can.
				'sanitize' => 'country_code',
				'help'     => __( 'Số nhập dạng <code>0969789475</code> sẽ được chuẩn hoá thành <code>84969789475</code>.', 'smart-login' ),
			),
			'identity.allowed_country_codes' => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'auth',
				'section' => 'identity',
				'label'   => __( 'Mã quốc gia được phép', 'smart-login' ),
				'help'    => __( 'Danh sách cách nhau bằng dấu phẩy, ví dụ <code>84,65,1</code>. <strong>Để trống nghĩa là chỉ chấp nhận mã quốc gia mặc định ở trên</strong> — không phải chấp nhận mọi quốc gia. Mở rộng danh sách này làm tăng rủi ro bị đốt tin nhắn qua các đầu số quốc tế.', 'smart-login' ),
			),
			'identity.synthetic_domain'      => array(
				'type'     => 'text',
				'default'  => 'phone.invalid',
				'tab'      => 'auth',
				'section'  => 'identity',
				'label'    => __( 'Domain email ảo', 'smart-login' ),
				'sanitize' => 'domain',
				'help'     => __( 'Dùng cho tài khoản chỉ có số điện thoại. Nên giữ đuôi <code>.invalid</code> — theo RFC 2606 domain này không bao giờ phân giải được, nên không thể phát sinh email bounce.', 'smart-login' ),
			),
			'signup.min_password_length'     => array(
				'type'    => 'number',
				'default' => 8,
				'min'     => 6,
				'max'     => 64,
				'tab'     => 'auth',
				'section' => 'signup',
				'label'   => __( 'Độ dài mật khẩu tối thiểu', 'smart-login' ),
			),
			'signup.terms_url'               => array(
				'type'    => 'page',
				'default' => '',
				'tab'     => 'auth',
				'section' => 'signup',
				'label'   => __( 'Link điều kiện áp dụng', 'smart-login' ),
				'help'    => __( 'Để trống nếu không có trang điều khoản riêng.', 'smart-login' ),
			),
			'signup.redirect_register'       => array(
				'type'    => 'page',
				'default' => '',
				'tab'     => 'auth',
				'section' => 'signup',
				'label'   => __( 'Sau khi đăng ký', 'smart-login' ),
				'help'    => __( 'Để trống để dùng trang Tài khoản của WooCommerce.', 'smart-login' ),
			),
			'signup.redirect_login'          => array(
				'type'    => 'page',
				'default' => '',
				'tab'     => 'auth',
				'section' => 'signup',
				'label'   => __( 'Sau khi đăng nhập', 'smart-login' ),
				'help'    => __( 'Để trống để dùng trang Tài khoản của WooCommerce.', 'smart-login' ),
			),
			'login.max_attempts'             => array(
				'type'    => 'number',
				'default' => 5,
				'min'     => 0,
				'max'     => 20,
				'tab'     => 'auth',
				'section' => 'login',
				'label'   => __( 'Số lần sai trước khi khoá', 'smart-login' ),
			),
			'login.lockout_minutes'          => array(
				'type'    => 'number',
				'default' => 15,
				'min'     => 1,
				'max'     => 1440,
				'tab'     => 'auth',
				'section' => 'login',
				'label'   => __( 'Thời gian khoá (phút)', 'smart-login' ),
			),
			'login.otp_new_device'           => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'auth',
				'section' => 'login',
				'label'   => __( 'OTP cho thiết bị lạ', 'smart-login' ),
				'help'    => __( 'Yêu cầu nhập OTP khi đăng nhập từ thiết bị chưa từng thấy. <strong>Lưu ý chi phí SMS.</strong>', 'smart-login' ),
			),
		);
	}

	private static function provider_fields(): array {
		return array(
			'providers.google.enabled'        => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'providers',
				'section' => 'provider',
				'label'   => __( 'Kích hoạt Google', 'smart-login' ),
			),
			'providers.google.client_id'      => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'providers',
				'section' => 'provider',
				'label'   => __( 'Google Client ID', 'smart-login' ),
			),
			// On by default. Google asserts email_verified and that assertion is
			// already trusted enough to become the account's user_email, which core
			// resolves as a login identifier at authenticate priority 20. Withholding
			// the identity row does not withhold that trust, it splits it.
			'providers.google.email_identity' => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'providers',
				'section' => 'provider',
				'label'   => __( 'Email Google là một cách đăng nhập', 'smart-login' ),
				'help'    => __( 'Khi Google xác nhận email đã verified, địa chỉ đó trở thành một cách đăng nhập và khôi phục tài khoản. Tắt trước khi cập nhật nếu không muốn áp dụng cho tài khoản đã có.', 'smart-login' ),
			),
			'providers.auto_link_email'       => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'providers',
				'section' => 'linking',
				'label'   => __( 'Tự liên kết bằng email', 'smart-login' ),
				'help'    => __( 'Chỉ tự liên kết khi provider xác nhận email đã verified, email trùng chính xác với một tài khoản duy nhất và tài khoản đó không dùng email giả lập.', 'smart-login' ),
			),
		);
	}

	private static function delivery_fields(): array {
		return array(
			'otp.preset'                   => array(
				'type'    => 'select',
				'default' => 'balanced',
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Mức bảo mật', 'smart-login' ),
				'choices' => OtpPresets::choices(),
				'help'    => __( 'Chọn một mức và sáu giá trị bên dưới được đặt theo. Chọn <em>Tuỳ chỉnh</em> để tự điều chỉnh.', 'smart-login' ),
			),
			'otp.length'                   => array(
				'type'    => 'number',
				'default' => 6,
				'min'     => 4,
				'max'     => 8,
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Số ký tự', 'smart-login' ),
			),
			'otp.ttl'                      => array(
				'type'    => 'number',
				'default' => 300,
				'min'     => 60,
				'max'     => 3600,
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Hiệu lực (giây)', 'smart-login' ),
				'help'    => __( 'Mặc định 300 giây (5 phút).', 'smart-login' ),
			),
			'otp.max_attempts'             => array(
				'type'    => 'number',
				'default' => 5,
				'min'     => 1,
				'max'     => 10,
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Số lần nhập sai tối đa', 'smart-login' ),
				'help'    => __( 'Vượt quá thì mã bị huỷ và người dùng phải yêu cầu mã mới.', 'smart-login' ),
			),
			'otp.resend_cooldown'          => array(
				'type'    => 'number',
				'default' => 60,
				'min'     => 15,
				'max'     => 600,
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Chờ giữa 2 lần gửi (giây)', 'smart-login' ),
			),
			'otp.max_per_destination_hour' => array(
				'type'    => 'number',
				'default' => 5,
				'min'     => 0,
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Số mã tối đa / số ĐT / giờ', 'smart-login' ),
				'help'    => __( 'Đặt 0 để bỏ giới hạn (không khuyến nghị).', 'smart-login' ),
			),
			'otp.max_per_ip_hour'          => array(
				'type'    => 'number',
				'default' => 10,
				'min'     => 0,
				'tab'     => 'delivery',
				'section' => 'otp',
				'label'   => __( 'Số mã tối đa / IP / giờ', 'smart-login' ),
			),

			'sms.enabled'                  => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Kích hoạt', 'smart-login' ),
				'help'    => __( 'Bật kênh gửi SMS qua webhook', 'smart-login' ),
			),
			'sms.preset'                   => array(
				'type'    => 'select',
				// `generic` rather than `custom`. A fresh install used to open on
				// all thirteen raw fields including a free-text JSON body — the
				// hardest screen in the plugin, shown to the person who has
				// configured the least. Only new installs are affected: a site
				// that has ever saved this tab has its own value stored, and
				// sanitize() writes stored values.
				'default' => 'generic',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Nhà cung cấp', 'smart-login' ),
				'choices' => GatewayPresets::choices(),
				'help'    => __( 'Chọn nhà cung cấp và chỉ cần điền thông tin xác thực; URL, Body và điều kiện thành công được sinh tự động.', 'smart-login' ),
			),
			// The signed provider's two inputs. Ordinary fields rather than
			// entries in `sms.credentials`, because that array can carry neither
			// `https_url` nor `secret` — which are two of the four controls D2
			// found a preset could not hold.
			'sms.signed_url'               => array(
				'type'     => 'url',
				'default'  => '',
				'tab'      => 'delivery-sms',
				'section'  => 'sms',
				'label'    => __( 'Endpoint nhận envelope', 'smart-login' ),
				'sanitize' => 'https_url',
				'show_if'  => array( 'sms.preset' => GatewayPresets::ENVELOPE_SIGNED ),
				'help'     => __( 'Bắt buộc <code>https://</code>. Mã xác thực rời khỏi website tới địa chỉ này, nên chữ ký HMAC chỉ chứng minh <em>ai gửi</em> — nó không làm cho một endpoint đáng ngờ trở nên an toàn.', 'smart-login' ),
			),
			'sms.signed_secret'            => array(
				'type'    => 'secret',
				'default' => '',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Khoá ký (HMAC)', 'smart-login' ),
				'show_if' => array( 'sms.preset' => GatewayPresets::ENVELOPE_SIGNED ),
				'help'    => __( 'Dùng để ký từng gói tin bằng SHA-256. Chưa có khoá thì nhà cung cấp này không được dùng, vì endpoint sẽ nhận mã thật mà không xác thực được nguồn.', 'smart-login' ),
			),
			'sms.credentials'              => array(
				'type'        => 'credentials',
				'default'     => array(),
				'tab'         => 'delivery-sms',
				'section'     => 'sms',
				'label'       => __( 'Thông tin xác thực', 'smart-login' ),
				'sanitize'    => 'credentials',
				// Which inputs exist — and whether there are any at all — depends
				// on the gateway chosen above. "Tuỳ chỉnh" asks for none, so this
				// row legitimately draws nothing. Declared here so the admin gate
				// can tell that apart from a field nobody remembered to render,
				// which is the failure this schema exists to prevent.
				'conditional' => true,
			),
			'sms.url'                      => array(
				'type'    => 'url',
				'default' => '',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'URL', 'smart-login' ),
				'help'    => __( 'Có thể chứa placeholder, ví dụ <code>https://api.gateway.vn/send?to={{phone_local}}</code>.', 'smart-login' ),
			),
			'sms.method'                   => array(
				'type'    => 'select',
				'default' => 'POST',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Phương thức', 'smart-login' ),
				'choices' => array(
					'POST' => 'POST',
					'GET'  => 'GET',
				),
			),
			'sms.content_type'             => array(
				'type'    => 'select',
				'default' => 'application/json',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Kiểu dữ liệu', 'smart-login' ),
				'choices' => array(
					'application/json'                  => 'application/json',
					'application/x-www-form-urlencoded' => 'application/x-www-form-urlencoded',
				),
				'help'    => __( 'Với GET, phần Body bên dưới được dùng làm query string.', 'smart-login' ),
			),
			'sms.headers'                  => array(
				'type'     => 'headers',
				'default'  => array(),
				'tab'      => 'delivery-sms',
				'section'  => 'sms',
				'label'    => __( 'Headers', 'smart-login' ),
				'sanitize' => 'headers',
			),
			'sms.body'                     => array(
				'type'     => 'textarea',
				'rows'     => 7,
				'default'  => '{"phone":"{{phone_local}}","content":"{{code}} la ma xac thuc cua ban tai {{site_name}}. Ma co hieu luc {{ttl_minutes}} phut."}',
				'tab'      => 'delivery-sms',
				'section'  => 'sms',
				'label'    => __( 'Body', 'smart-login' ),
				'sanitize' => 'raw_template',
				'help'     => __( 'Với JSON, các giá trị thay thế được escape tự động nên nội dung luôn hợp lệ.', 'smart-login' ),
			),
			'sms.timeout'                  => array(
				'type'    => 'number',
				'default' => 5,
				'min'     => 2,
				'max'     => 15,
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Timeout (giây)', 'smart-login' ),
				'help'    => __( 'Mỗi lần gửi giữ một tiến trình PHP trong đúng khoảng này. Đặt cao là cách nhanh nhất để một gateway chậm làm sập cả website, nên trần cứng là 15 giây kể cả khi giá trị cũ lớn hơn.', 'smart-login' ),
			),
			'sms.success_path'             => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Đường dẫn JSON báo thành công', 'smart-login' ),
				'help'    => __( 'Ví dụ <code>CodeResult</code> hoặc <code>data.status</code>. Để trống thì chỉ cần HTTP 2xx là coi như thành công.', 'smart-login' ),
			),
			'sms.success_value'            => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Giá trị mong đợi', 'smart-login' ),
				'help'    => __( 'Ví dụ <code>100</code>.', 'smart-login' ),
			),
			'sms.retry'                    => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'delivery-sms',
				'section' => 'sms',
				'label'   => __( 'Thử lại', 'smart-login' ),
				'help'    => __( 'Gọi lại 1 lần sau 2 giây. Chỉ hoạt động khi đã cấu hình header idempotency bên dưới.', 'smart-login' ),
			),
			'sms.idempotency_header'       => array(
				'type'     => 'text',
				'default'  => '',
				'tab'      => 'delivery-sms',
				'section'  => 'sms',
				'label'    => __( 'Header idempotency', 'smart-login' ),
				'sanitize' => 'header_name',
				'help'     => __( 'Chỉ điền khi gateway cam kết chống gửi trùng, ví dụ <code>Idempotency-Key</code>. Plugin gửi cùng một <code>{{delivery_id}}</code> cho cả hai lần thử.', 'smart-login' ),
			),

			'email.enabled'                => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'delivery-email',
				'section' => 'email',
				'label'   => __( 'Kích hoạt', 'smart-login' ),
				'help'    => __( 'Bật kênh gửi email', 'smart-login' ),
			),
			'email.from_name'              => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'delivery-email',
				'section' => 'email',
				'label'   => __( 'Tên người gửi', 'smart-login' ),
				'help'    => __( 'Để trống để dùng tên website.', 'smart-login' ),
			),
			'email.from_address'           => array(
				'type'    => 'email',
				'default' => '',
				'tab'     => 'delivery-email',
				'section' => 'email',
				'label'   => __( 'Email người gửi', 'smart-login' ),
				'help'    => __( 'Để trống để dùng cấu hình mặc định của WordPress.', 'smart-login' ),
			),
			'email.subject'                => array(
				'type'    => 'text',
				'default' => 'Mã xác thực {{code}} - {{site_name}}',
				'tab'     => 'delivery-mail',
				'section' => 'mail_default',
				'label'   => __( 'Tiêu đề', 'smart-login' ),
			),
			'email.is_html'                => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'delivery-mail',
				'section' => 'mail_design',
				'label'   => __( 'Định dạng', 'smart-login' ),
				'help'    => __( 'Gửi dưới dạng HTML', 'smart-login' ),
			),
			'email.logo_url'               => array(
				'type'    => 'url',
				'default' => '',
				'tab'     => 'delivery-mail',
				'section' => 'mail_design',
				'label'   => __( 'Logo trong email', 'smart-login' ),
				'help'    => __( 'Chỉ dùng khi gửi dạng HTML. Để trống thì hiện tên website. Nhiều ứng dụng email chặn ảnh cho tới khi người nhận bấm hiển thị, nên đừng đặt thông tin quan trọng trong ảnh.', 'smart-login' ),
			),
			'email.accent_color'           => array(
				'type'    => 'text',
				'default' => '#2271b1',
				'tab'     => 'delivery-mail',
				'section' => 'mail_design',
				'label'   => __( 'Màu nhấn', 'smart-login' ),
				'help'    => __( 'Dạng <code>#2271b1</code>. Giá trị không hợp lệ sẽ dùng màu mặc định.', 'smart-login' ),
			),
			'email.footer_text'            => array(
				'type'     => 'textarea',
				'rows'     => 3,
				'default'  => '',
				'tab'      => 'delivery-mail',
				'section'  => 'mail_design',
				'label'    => __( 'Chân email', 'smart-login' ),
				'sanitize' => 'rich_text',
				'help'     => __( 'Ví dụ địa chỉ cửa hàng hoặc câu nhắc không trả lời email này. Để trống để bỏ hẳn phần chân.', 'smart-login' ),
			),

			'automation.url'               => array(
				'type'     => 'url',
				'default'  => '',
				'tab'      => 'integrations',
				'section'  => 'automation',
				'label'    => __( 'Endpoint', 'smart-login' ),
				'sanitize' => 'https_url',
				'help'     => __( 'Bắt buộc <code>https://</code>. Mã xác thực rời khỏi website tới địa chỉ này, nên chữ ký HMAC chỉ chứng minh <em>ai gửi</em> — nó không làm cho một endpoint đáng ngờ trở nên an toàn.', 'smart-login' ),
			),
			'automation.secret'            => array(
				'type'    => 'secret',
				'default' => '',
				'tab'     => 'integrations',
				'section' => 'automation',
				'label'   => __( 'Khoá ký (HMAC)', 'smart-login' ),
				'help'    => __( 'Dùng để ký từng gói tin bằng SHA-256. Chưa có khoá thì kênh này không được dùng, vì endpoint sẽ nhận mã thật mà không xác thực được nguồn.', 'smart-login' ),
			),
			'automation.headers'           => array(
				'type'     => 'headers',
				'default'  => array(),
				'tab'      => 'integrations',
				'section'  => 'automation',
				'label'    => __( 'Headers bổ sung', 'smart-login' ),
				'sanitize' => 'headers',
				'help'     => __( 'Các header do plugin sinh ra không thể bị ghi đè ở đây.', 'smart-login' ),
			),
			'automation.timeout'           => array(
				'type'    => 'number',
				'default' => 5,
				'min'     => 2,
				'max'     => WebhookTransport::MAX_TIMEOUT,
				'tab'     => 'integrations',
				'section' => 'automation',
				'label'   => __( 'Timeout (giây)', 'smart-login' ),
				'help'    => __( 'Cùng trần cứng với kênh SMS, và vì cùng một lý do: mỗi lần gửi giữ một tiến trình PHP trong đúng khoảng này.', 'smart-login' ),
			),
			'automation.events'            => array(
				'type'     => 'checkboxes',
				'default'  => array(),
				'tab'      => 'integrations',
				'section'  => 'automation',
				'label'    => __( 'Sự kiện gửi kèm', 'smart-login' ),
				'choices'  => 'audit_events',
				'sanitize' => 'audit_events',
				'help'     => __( 'Gửi không chờ phản hồi, <strong>không bao giờ kèm mã OTP</strong>, và dùng chung trần đếm mỗi giờ với nhật ký — nên tắt nhật ký là tắt luôn phần này.', 'smart-login' ),
			),
			'automation.success_path'      => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'integrations',
				'section' => 'automation',
				'label'   => __( 'Đường dẫn JSON báo thành công', 'smart-login' ),
				'help'    => __( 'Để trống thì chỉ cần HTTP 2xx là coi như thành công.', 'smart-login' ),
			),
			'automation.success_value'     => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'integrations',
				'section' => 'automation',
				'label'   => __( 'Giá trị mong đợi', 'smart-login' ),
			),

			'email.body'                   => array(
				'type'     => 'textarea',
				'rows'     => 10,
				'default'  => "Xin chào,\n\nMã xác thực của bạn là: {{code}}\nMã có hiệu lực trong {{ttl_minutes}} phút.\n\nNếu bạn không yêu cầu mã này, vui lòng bỏ qua email.\n\n{{site_name}}",
				'tab'      => 'delivery-mail',
				'section'  => 'mail_default',
				'label'    => __( 'Nội dung', 'smart-login' ),
				'sanitize' => 'rich_text',
				'help'     => __( 'Dùng chung bộ placeholder với phần Gửi qua SMS.', 'smart-login' ),
			),
		);
	}

	private static function profile_fields(): array {
		return array(
			'profile.email_optional'      => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'fields',
				'label'   => __( 'Email không bắt buộc', 'smart-login' ),
				'help'    => __( 'Tài khoản đăng ký bằng số điện thoại không bị coi là thiếu thông tin khi chưa có email. <strong>Tắt sẽ khiến mọi tài khoản chỉ có số điện thoại bị nhắc bổ sung email.</strong>', 'smart-login' ),
			),
			'profile.dob'                 => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'fields',
				'label'   => __( 'Ngày sinh', 'smart-login' ),
				'help'    => __( 'Hiển thị ở màn hình chào mừng và trong hồ sơ; không hiển thị khi đăng ký.', 'smart-login' ),
			),
			'profile.gender'              => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'fields',
				'label'   => __( 'Giới tính', 'smart-login' ),
				'help'    => __( 'Hiển thị ở màn hình chào mừng và trong hồ sơ; không hiển thị khi đăng ký.', 'smart-login' ),
			),
			'address.enabled'             => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'address',
				'label'   => __( 'Kích hoạt', 'smart-login' ),
				'help'    => __( 'Bật bộ chọn Tỉnh/Thành phố → Phường/Xã', 'smart-login' ),
			),
			'address.required_in_profile' => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'profile',
				'section' => 'address',
				'label'   => __( 'Bắt buộc ở hồ sơ', 'smart-login' ),
				'help'    => __( 'Yêu cầu chọn địa chỉ khi cập nhật hồ sơ', 'smart-login' ),
			),
			'address.hide_postcode'       => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'profile',
				'section' => 'address',
				'label'   => __( 'Ẩn Mã bưu điện', 'smart-login' ),
				'help'    => __( 'Bỏ trường Mã bưu điện khỏi form địa chỉ và thanh toán', 'smart-login' ),
			),

			'woo.replace_login_form'      => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'woo',
				'label'   => __( 'Thay form My Account', 'smart-login' ),
				'help'    => __( 'Thay thế form đăng nhập/đăng ký mặc định của WooCommerce', 'smart-login' ),
			),
			'woo.sync_billing_phone'      => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'woo',
				'label'   => __( 'Đồng bộ SĐT', 'smart-login' ),
				'help'    => __( 'Điền sẵn <code>billing_phone</code> từ số điện thoại đã xác thực khi ô đó còn trống', 'smart-login' ),
			),
			'woo.relax_billing_email'     => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'profile',
				'section' => 'woo',
				'label'   => __( 'Email khi thanh toán', 'smart-login' ),
				'help'    => __( 'Bỏ bắt buộc nhập email ở trang thanh toán', 'smart-login' ),
			),
			'woo.block_synthetic_emails'  => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'profile',
				'section' => 'woo',
				'label'   => __( 'Chặn email ảo', 'smart-login' ),
				'help'    => __( 'Không gửi bất kỳ email nào tới địa chỉ ảo (khuyến nghị bật)', 'smart-login' ),
			),
		);
	}

	/**
	 * Ceilings that are not scoped to one destination or one IP.
	 *
	 * Everything under `otp.max_per_*` counts a single attacker. These count the
	 * site, which is the only axis a botnet cannot rotate around. Defaults are
	 * deliberately generous: a ceiling low enough to break a launch gets switched
	 * off and never switched back on, which is worse than a high one.
	 */
	private static function security_fields(): array {
		return array(
			'security.max_per_site_hour'              => array(
				'type'    => 'number',
				'default' => 100,
				'min'     => 0,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Số mã tối đa / toàn site / giờ', 'smart-login' ),
				'help'    => __( 'Chạm trần thì việc gửi mã bị tạm dừng và admin nhận email. Đặt 0 để bỏ giới hạn — <strong>không khuyến nghị nếu bạn trả tiền cho mỗi tin nhắn</strong>.', 'smart-login' ),
			),
			'security.max_per_site_day'               => array(
				'type'    => 'number',
				'default' => 500,
				'min'     => 0,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Số mã tối đa / toàn site / ngày', 'smart-login' ),
				'help'    => __( 'Đặt 0 để bỏ giới hạn.', 'smart-login' ),
			),
			'security.halt_minutes'                   => array(
				'type'    => 'number',
				'default' => 60,
				'min'     => 5,
				'max'     => 1440,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Tạm dừng trong (phút)', 'smart-login' ),
				'help'    => __( 'Sau khoảng thời gian này việc gửi mã tự mở lại.', 'smart-login' ),
			),
			'security.max_identify_per_ip_hour'       => array(
				'type'    => 'number',
				'default' => 30,
				'min'     => 0,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Số lần tra định danh / IP / giờ', 'smart-login' ),
				'help'    => __( 'Màn hình đăng nhập tra xem một số điện thoại đã có tài khoản chưa. Không giới hạn thì danh sách khách hàng của bạn có thể bị dò sạch mà không tốn gì. Đặt 0 để bỏ giới hạn.', 'smart-login' ),
			),
			'security.max_login_failures_per_ip_hour' => array(
				'type'    => 'number',
				'default' => 30,
				'min'     => 0,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Số lần đăng nhập sai / IP / giờ', 'smart-login' ),
				'help'    => __( 'Bắt kiểu tấn công rải mật khẩu: thử một mật khẩu phổ biến trên hàng nghìn tài khoản. Khoá theo tài khoản không thấy được kiểu này vì mỗi tài khoản chỉ sai một lần. Để rộng rãi — văn phòng, trường học và mạng di động dồn nhiều người thật vào một IP. Đặt 0 để bỏ giới hạn.', 'smart-login' ),
			),
			'security.ip_lockout_minutes'             => array(
				'type'    => 'number',
				'default' => 15,
				'min'     => 1,
				'max'     => 1440,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Khoá IP trong (phút)', 'smart-login' ),
			),
			'security.audit_max_per_event_hour'       => array(
				'type'    => 'number',
				'default' => 500,
				'min'     => 0,
				'tab'     => 'security',
				'section' => 'budget',
				'label'   => __( 'Số dòng nhật ký tối đa / loại sự kiện / giờ', 'smart-login' ),
				'help'    => __( 'Vượt quá thì loại sự kiện đó chỉ ghi một dòng tổng hợp cho cả giờ. Không có trần này, một cuộc tấn công khiến chính nhật ký trở thành thứ khuếch đại nó. Các sự kiện quan trọng — khoá tài khoản, đăng ký, đặt lại mật khẩu, chạm trần, ngắt mạch, liên kết provider — không bao giờ bị bỏ. Đặt 0 để ghi tất cả.', 'smart-login' ),
			),
			'otp.sms_unit_cost'                       => array(
				'type'    => 'number',
				'default' => 0,
				'min'     => 0,
				// Sits with the OTP policy it prices rather than with the site
				// budget it used to sit beside: the ceiling is an abuse control,
				// this is an operating cost, and only one of them is a number the
				// operator sets from their invoice.
				'tab'     => 'delivery',
				'section' => 'otp',
				// The key still says `sms`; since 10.5 the number it multiplies is
				// every OTP sent to a phone number, whichever transport carried
				// it. Renaming the key would cross includes/, tests/ and docs/
				// for no behavioural gain, and this project has been bitten five
				// times by exactly that. The label carries the meaning instead.
				'label'   => __( 'Giá mỗi mã gửi tới số điện thoại (VNĐ)', 'smart-login' ),
				'help'    => __( 'Chỉ dùng để ước tính chi phí trên màn hình Tổng quan, và tính cho mọi mã gửi tới số điện thoại — kể cả khi kênh đó đang định tuyến qua automation. Đặt 0 để ẩn.', 'smart-login' ),
			),
			'security.captcha_provider'               => array(
				'type'    => 'select',
				'default' => 'off',
				'tab'     => 'security',
				'section' => 'captcha',
				'label'   => __( 'Nhà cung cấp', 'smart-login' ),
				'choices' => array(
					'off'       => __( 'Tắt', 'smart-login' ),
					'turnstile' => __( 'Cloudflare Turnstile', 'smart-login' ),
					'hcaptcha'  => __( 'hCaptcha', 'smart-login' ),
				),
			),
			'security.captcha_mode'                   => array(
				'type'    => 'select',
				'default' => 'adaptive',
				'tab'     => 'security',
				'section' => 'captcha',
				'label'   => __( 'Khi nào hiện', 'smart-login' ),
				'choices' => array(
					'adaptive' => __( 'Chỉ khi site đang bị ép', 'smart-login' ),
					'always'   => __( 'Luôn luôn', 'smart-login' ),
				),
				'help'    => __( 'Chế độ thích ứng chỉ hiện thử thách khi ngân sách đã tiêu quá nửa, kill switch vừa nổ, kênh gửi đang bị ngắt mạch, hoặc IP đó đã dùng quá nửa hạn mức tra cứu. Ngày thường khách không thấy gì — một captcha hiện lên vào thứ Ba yên ả là lỗi chuyển đổi, không phải biện pháp bảo mật.', 'smart-login' ),
			),
			'security.captcha_site_key'               => array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'security',
				'section' => 'captcha',
				'label'   => __( 'Site key', 'smart-login' ),
			),
			'security.captcha_secret'                 => array(
				'type'    => 'secret',
				'default' => '',
				'tab'     => 'security',
				'section' => 'captcha',
				'label'   => __( 'Secret key', 'smart-login' ),
				'help'    => __( 'Được mã hoá trước khi lưu và không bao giờ hiển thị lại. Để trống khi lưu nghĩa là giữ nguyên giá trị cũ.', 'smart-login' ),
			),
			'security.trust_proxy'                    => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'security',
				'section' => 'network',
				'label'   => __( 'Site đứng sau proxy tin cậy', 'smart-login' ),
				'help'    => __( 'Bật khi site thực sự nằm sau Cloudflare hoặc một load balancer của bạn. Nếu không điền dải IP bên dưới thì bật cái này <strong>không có tác dụng gì</strong> — và đó là chủ ý: tin header từ một máy chưa xác minh còn tệ hơn không tin gì cả.', 'smart-login' ),
			),
			'security.trusted_proxy_cidrs'            => array(
				'type'    => 'textarea',
				'default' => '',
				'rows'    => 4,
				'tab'     => 'security',
				'section' => 'network',
				'label'   => __( 'Dải IP của proxy', 'smart-login' ),
				'help'    => __( 'Dạng CIDR, mỗi dòng một dải — ví dụ <code>173.245.48.0/20</code>. Cloudflare công bố danh sách tại <code>https://www.cloudflare.com/ips/</code>. Plugin <strong>không kèm sẵn</strong> danh sách này: một danh sách cứng sẽ lạc hậu âm thầm, và lúc đó nó thành lỗ hổng chứ không còn là biện pháp bảo vệ.', 'smart-login' ),
			),
			'security.breaker_threshold'              => array(
				'type'    => 'number',
				'default' => 5,
				'min'     => 0,
				'max'     => 50,
				'tab'     => 'security',
				'section' => 'breaker',
				'label'   => __( 'Số lần lỗi liên tiếp trước khi ngắt', 'smart-login' ),
				'help'    => __( 'Khi kênh gửi lỗi liên tiếp đủ số lần này, plugin ngừng gọi nó và trả lỗi ngay — không giữ tiến trình PHP để chờ một gateway đã chết. Đặt 0 để tắt.', 'smart-login' ),
			),
			'security.breaker_cooldown'               => array(
				'type'    => 'number',
				'default' => 300,
				'min'     => 30,
				'max'     => 3600,
				'tab'     => 'security',
				'section' => 'breaker',
				'label'   => __( 'Ngắt trong (giây)', 'smart-login' ),
				'help'    => __( 'Hết khoảng này, một lần gửi được cho đi thử. Thất bại thì ngắt lại ngay, không cần lỗi đủ số lần lần nữa.', 'smart-login' ),
			),
		);
	}

	private static function advanced_fields(): array {
		return array(
			'advanced.audit_enabled'            => array(
				'type'    => 'checkbox',
				'default' => 1,
				'tab'     => 'advanced',
				'section' => 'audit',
				'label'   => __( 'Ghi nhật ký', 'smart-login' ),
				'help'    => __( 'Lưu lại các sự kiện đăng nhập / xác thực', 'smart-login' ),
			),
			'advanced.audit_retention_days'     => array(
				'type'    => 'number',
				'default' => 90,
				'min'     => 1,
				'max'     => 3650,
				'tab'     => 'advanced',
				'section' => 'audit',
				'label'   => __( 'Giữ nhật ký (ngày)', 'smart-login' ),
			),
			'advanced.otp_retention_days'       => array(
				'type'    => 'number',
				'default' => 7,
				'min'     => 1,
				'max'     => 365,
				'tab'     => 'advanced',
				'section' => 'audit',
				'label'   => __( 'Giữ bản ghi OTP (ngày)', 'smart-login' ),
				'help'    => __( 'Mã đã dùng hoặc hết hạn sẽ bị xoá sau khoảng thời gian này.', 'smart-login' ),
			),
			'advanced.dev_mode'                 => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'advanced',
				'section' => 'dev',
				'label'   => __( 'Chế độ DEV', 'smart-login' ),
				'help'    => __( 'Hiển thị mã OTP ngay trên màn hình. Chỉ có tác dụng khi <code>WP_DEBUG</code> bật <strong>và</strong> môi trường không phải <code>production</code>.', 'smart-login' ),
			),
			'advanced.delete_data_on_uninstall' => array(
				'type'    => 'checkbox',
				'default' => 0,
				'tab'     => 'advanced',
				'section' => 'dev',
				'label'   => __( 'Xoá dữ liệu khi gỡ', 'smart-login' ),
				'help'    => __( 'Xoá bảng, tuỳ chọn và user meta khi gỡ plugin. <strong>Không thể hoàn tác.</strong>', 'smart-login' ),
			),
		);
	}

	/**
	 * @return array<string,array> The fields drawn on one tab, in declared order.
	 */
	public static function for_tab( string $tab ): array {
		return array_filter(
			self::all(),
			static fn( array $field ): bool => ( $field['tab'] ?? '' ) === $tab
		);
	}

	/**
	 * @return array<string,array> One tab's fields grouped by section, sections in
	 *                             the order sections() declares.
	 */
	public static function by_section( string $tab ): array {
		$fields = self::for_tab( $tab );
		$out    = array();

		foreach ( array_keys( self::sections() ) as $section ) {
			$in_section = array_filter(
				$fields,
				static fn( array $field ): bool => ( $field['section'] ?? '' ) === $section
			);

			if ( $in_section ) {
				$out[ $section ] = $in_section;
			}
		}

		return $out;
	}

	public static function get( string $path ): ?array {
		return self::all()[ $path ] ?? null;
	}
}
