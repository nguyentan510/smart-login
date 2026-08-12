<?php
/**
 * The instructions, on the screen instead of in a file nobody opens.
 *
 * `README.md` is 33 KB and already answers most of what an administrator asks.
 * It is also on disk, in a plugin directory, reachable from `wp-admin` by
 * nobody — so this screen is not a second document. It is a short version of the
 * same one, put where the reader is.
 *
 * **Nothing here is typed twice.** The shortcodes come from
 * `Shortcodes::CATALOG`, the URL fragments from `LoginDialog::aliases()`, the
 * icon names from `IconSet::names()`, the links from `SettingsPage`. What cannot
 * be derived — the error strings a visitor sees — is quoted verbatim, and a rule
 * requires every quote to exist in `includes/`. A troubleshooting table whose
 * left-hand column has drifted is worse than no table: it sends the reader
 * hunting for a message the plugin does not print.
 *
 * This screen reads **no settings at all**, deliberately. A guide that reads a
 * stored value is one refactor away from printing it, and then it is the place
 * that value goes stale. It says what a value means and links to the screen that
 * owns it.
 *
 * @package OmniWP
 */

namespace OmniWP\Admin\Screens;

use OmniWP\Admin\SettingsPage;
use OmniWP\Frontend\IconSet;
use OmniWP\Frontend\LoginDialog;
use OmniWP\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class GuideScreen {

	/**
	 * Not a member of `FieldRegistry::tabs()`, and that is the point.
	 *
	 * Membership there means "this tab draws these settings and a save writes
	 * them" — `SettingsScreen` renders a form and a Save button for every tab in
	 * it. A guide added there for tidiness would ship a button that saves
	 * nothing. It is a `SettingsPage` route beside the overview screen instead.
	 */
	const SLUG = 'omniwp-guide';

	/**
	 * What each shortcode is for, keyed by the tag that registers it.
	 *
	 * The keys must match `Shortcodes::CATALOG` exactly, in both directions, and
	 * a rule enforces it: nine tags were registered while `README.md` named six,
	 * and `[smart_account]` and `[smart_address]` were named nowhere at all.
	 * That is the drift this pairing exists to make impossible.
	 *
	 * Defaults are **not** written here — they are read from the catalog at
	 * render time, so the table cannot disagree with the shortcode.
	 *
	 * @return array<string,array{label:string,summary:string,where:string,atts:array<string,string>}>
	 */
	public static function shortcodes(): array {
		return array(
			'smart_auth'             => array(
				'label'   => __( 'Hộp đăng nhập và đăng ký', 'omniwp' ),
				'summary' => __( 'Chỉ hỏi một ô định danh. Máy chủ tra xem đã có tài khoản chưa rồi tự chuyển sang mật khẩu, mã OTP hoặc đăng ký, cho tới màn hình chào mừng.', 'omniwp' ),
				'where'   => __( 'Trang đăng nhập của website. Đây là shortcode duy nhất bắt buộc phải có.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_login'            => array(
				'label'   => __( 'Tên gọi khác của [smart_auth]', 'omniwp' ),
				'summary' => __( 'Cùng một màn hình, không khác gì.', 'omniwp' ),
				'where'   => __( 'Chỉ dùng khi trang cũ của bạn đang gắn sẵn tên này.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_auth'            => array(
				'label'   => __( 'Hộp đăng nhập và đăng ký (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_auth].', 'omniwp' ),
				'where'   => __( 'Trang đăng nhập chuẩn OmniWP.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_login'           => array(
				'label'   => __( 'Tên gọi ngắn của [omniwp_auth]', 'omniwp' ),
				'summary' => __( 'Cùng một màn hình, không khác gì.', 'omniwp' ),
				'where'   => __( 'Chỉ dùng khi cần tên gọn.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_register'         => array(
				'label'   => __( 'Cũng là màn hình đó, giọng đăng ký', 'omniwp' ),
				'summary' => __( 'Không có màn hình đăng ký riêng: người dùng hiếm khi biết mình thuộc nhánh nào, nên bước đầu tự quyết định. Shortcode này chỉ đổi tiêu đề.', 'omniwp' ),
				'where'   => __( 'Trang “Đăng ký”, nếu menu của bạn đã có sẵn một trang riêng.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_register'        => array(
				'label'   => __( 'Cũng là màn hình đó, giọng đăng ký (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_register].', 'omniwp' ),
				'where'   => __( 'Trang đăng ký.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_verify_otp'       => array(
				'label'   => __( 'Mở thẳng bước nhập mã', 'omniwp' ),
				'summary' => __( 'Hiện ô nhập mã xác thực cho phiên đang chờ.', 'omniwp' ),
				'where'   => __( 'Hiếm khi cần: [smart_auth] đã tự chuyển sang bước này.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_verify_otp'      => array(
				'label'   => __( 'Mở thẳng bước nhập mã (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_verify_otp].', 'omniwp' ),
				'where'   => __( 'Hiếm khi cần.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_forgot_password'  => array(
				'label'   => __( 'Mở thẳng bước quên mật khẩu', 'omniwp' ),
				'summary' => __( 'Nhận định danh, gửi mã, rồi cho đặt lại mật khẩu.', 'omniwp' ),
				'where'   => __( 'Trang “Quên mật khẩu”, nếu bạn muốn có một địa chỉ riêng. Hộp đăng nhập vốn đã có sẵn liên kết này.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_forgot_password' => array(
				'label'   => __( 'Mở thẳng bước quên mật khẩu (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_forgot_password].', 'omniwp' ),
				'where'   => __( 'Trang Quên mật khẩu.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_profile'          => array(
				'label'   => __( 'Tóm tắt hồ sơ, chỉ để xem', 'omniwp' ),
				'summary' => __( 'Cho thành viên thấy hồ sơ còn thiếu gì và đã xác minh được những gì. Không sửa được ở đây.', 'omniwp' ),
				'where'   => __( 'Trang tài khoản. Khách chưa đăng nhập sẽ thấy hộp đăng nhập.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_profile'         => array(
				'label'   => __( 'Tóm tắt hồ sơ (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_profile].', 'omniwp' ),
				'where'   => __( 'Trang tài khoản.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_account'          => array(
				'label'   => __( 'Trang tài khoản đầy đủ, sửa được', 'omniwp' ),
				'summary' => __( 'Họ tên, số điện thoại, email, mật khẩu, địa chỉ và các tài khoản đã liên kết — tất cả trong một trang, không cần WooCommerce.', 'omniwp' ),
				'where'   => __( 'Trang tài khoản của website không dùng WooCommerce. Khách chưa đăng nhập sẽ thấy hộp đăng nhập.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_account'         => array(
				'label'   => __( 'Trang tài khoản đầy đủ (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_account].', 'omniwp' ),
				'where'   => __( 'Trang tài khoản.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_address'          => array(
				'label'   => __( 'Chỉ bộ chọn Tỉnh/Thành – Phường/Xã', 'omniwp' ),
				'summary' => __( 'In ra đúng phần ô chọn địa chỉ, không kèm thẻ form, nonce hay nút gửi — những thứ đó thuộc về form bạn đang nhúng nó vào. Module địa chỉ tắt thì không in gì cả.', 'omniwp' ),
				'where'   => __( 'Bên trong một form do bạn tự viết.', 'omniwp' ),
				'atts'    => array(
					'required' => __( '<code>yes</code> bắt buộc chọn, <code>no</code> cho phép bỏ trống.', 'omniwp' ),
				),
			),
			'omniwp_address'         => array(
				'label'   => __( 'Chỉ bộ chọn Tỉnh/Thành – Phường/Xã (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_address].', 'omniwp' ),
				'where'   => __( 'Bên trong một form do bạn tự viết.', 'omniwp' ),
				'atts'    => array(
					'required' => __( '<code>yes</code> bắt buộc chọn, <code>no</code> cho phép bỏ trống.', 'omniwp' ),
				),
			),
			'smart_vouchers'         => array(
				'label'   => __( 'Danh sách mã giảm giá', 'omniwp' ),
				'summary' => __( 'Hiển thị danh sách voucher dành riêng cho khách hàng.', 'omniwp' ),
				'where'   => __( 'Trang ưu đãi hoặc tài khoản.', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_vouchers'        => array(
				'label'   => __( 'Danh sách mã giảm giá (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_vouchers].', 'omniwp' ),
				'where'   => __( 'Trang ưu đãi hoặc tài khoản.', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_login_button'     => array(
				'label'   => __( 'Nút tài khoản trên header', 'omniwp' ),
				'summary' => __( 'Một shortcode, hai trạng thái: chưa đăng nhập là nút “Đăng nhập”, đã đăng nhập là tên thành viên kèm menu tài khoản đổ xuống.', 'omniwp' ),
				'where'   => __( 'Header hoặc menu dựng bằng Elementor, Gutenberg… Không sửa được template thì dùng ô “Chèn nút vào menu” bên dưới.', 'omniwp' ),
				'atts'    => array(
					'step'     => __( 'Bước mở ra khi bấm.', 'omniwp' ),
					'label'    => __( 'Chữ trên nút khi khách chưa đăng nhập. Bỏ trống là “Đăng nhập”.', 'omniwp' ),
					'class'    => __( 'Thêm class CSS của bạn vào nút.', 'omniwp' ),
					'collapse' => __( '<code>mobile</code>: dưới 782px chỉ còn biểu tượng. <code>none</code>: luôn hiện chữ.', 'omniwp' ),
				),
			),
			'omniwp_button'          => array(
				'label'   => __( 'Nút tài khoản trên header (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_login_button].', 'omniwp' ),
				'where'   => __( 'Header hoặc menu.', 'omniwp' ),
				'atts'    => array(
					'step'     => __( 'Bước mở ra khi bấm.', 'omniwp' ),
					'label'    => __( 'Chữ trên nút khi khách chưa đăng nhập. Bỏ trống là “Đăng nhập”.', 'omniwp' ),
					'class'    => __( 'Thêm class CSS của bạn vào nút.', 'omniwp' ),
					'collapse' => __( '<code>mobile</code>: dưới 782px chỉ còn biểu tượng. <code>none</code>: luôn hiện chữ.', 'omniwp' ),
				),
			),
			'smart_cart'             => array(
				'label'   => __( 'Trang giỏ hàng cố định', 'omniwp' ),
				'summary' => __( 'Hiển thị giao diện giỏ hàng 2 cột chuẩn OmniWP kèm tính năng áp mã và cập nhật số lượng.', 'omniwp' ),
				'where'   => __( 'Trang giỏ hàng (/cart/).', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_cart'            => array(
				'label'   => __( 'Trang giỏ hàng cố định (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_cart].', 'omniwp' ),
				'where'   => __( 'Trang giỏ hàng (/cart/).', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_checkout'         => array(
				'label'   => __( 'Trang thanh toán chuẩn Việt', 'omniwp' ),
				'summary' => __( 'Form thanh toán tối ưu chuẩn Việt Nam, tích hợp Sổ địa chỉ và Cổng thanh toán tiện lợi.', 'omniwp' ),
				'where'   => __( 'Trang thanh toán (/checkout/).', 'omniwp' ),
				'atts'    => array(),
			),
			'omniwp_checkout'        => array(
				'label'   => __( 'Trang thanh toán chuẩn Việt (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_checkout].', 'omniwp' ),
				'where'   => __( 'Trang thanh toán (/checkout/).', 'omniwp' ),
				'atts'    => array(),
			),
			'smart_cart_button'      => array(
				'label'   => __( 'Nút mở giỏ hàng trượt', 'omniwp' ),
				'summary' => __( 'Nút bấm mở Slide Cart Drawer kèm số lượng sản phẩm trong giỏ.', 'omniwp' ),
				'where'   => __( 'Header hoặc bất kỳ vị trí nào trên website.', 'omniwp' ),
				'atts'    => array(
					'label' => __( 'Nhãn văn bản trên nút. Mặc định là “Giỏ hàng”.', 'omniwp' ),
					'class' => __( 'Thêm class CSS của bạn.', 'omniwp' ),
				),
			),
			'omniwp_cart_button'     => array(
				'label'   => __( 'Nút mở giỏ hàng trượt (OmniWP)', 'omniwp' ),
				'summary' => __( 'Tương đương [smart_cart_button].', 'omniwp' ),
				'where'   => __( 'Header hoặc thanh điều hướng.', 'omniwp' ),
				'atts'    => array(
					'label' => __( 'Nhãn văn bản trên nút. Mặc định là “Giỏ hàng”.', 'omniwp' ),
					'class' => __( 'Thêm class CSS của bạn.', 'omniwp' ),
				),
			),
		);
	}

	/**
	 * What a visitor sees, why, and which screen fixes it.
	 *
	 * `quote` is the message **verbatim as the code prints it**, `%d` and all: a
	 * rule searches `includes/` for it, so a row whose wording has drifted fails
	 * the build rather than sending an administrator hunting. Rows describing a
	 * symptom rather than a message leave it empty.
	 *
	 * `tab` is a slug, not a URL. A slug that no longer resolves fails a rule; a
	 * typed URL would simply 404 for whoever followed it.
	 *
	 * @return array<int,array{quote:string,symptom:string,cause:string,fix:string,tab:string}>
	 */
	public static function problems(): array {
		return array(
			array(
				'quote'   => 'Kênh SMS chưa được cấu hình. Liên hệ quản trị viên.',
				'symptom' => '',
				'cause'   => __( 'Kênh SMS đang tắt, hoặc đã bật nhưng chưa chọn nhà cung cấp và điền thông tin xác thực.', 'omniwp' ),
				'fix'     => __( 'Chọn nhà cung cấp, điền thông tin, bật Kích hoạt rồi bấm Gửi thử.', 'omniwp' ),
				'tab'     => 'delivery-sms',
			),
			array(
				'quote'   => 'Kênh email chưa được cấu hình. Liên hệ quản trị viên.',
				'symptom' => '',
				'cause'   => __( 'Kênh email đang tắt.', 'omniwp' ),
				'fix'     => __( 'Bật kênh và gửi thử. Nếu máy chủ không gửi được thư, hãy cài một plugin SMTP.', 'omniwp' ),
				'tab'     => 'delivery-email',
			),
			array(
				'quote'   => 'Không gửi được mã xác thực. Vui lòng thử lại sau ít phút.',
				'symptom' => '',
				'cause'   => __( 'Nhà cung cấp đã nhận request nhưng trả về lỗi, hoặc hết thời gian chờ. Thường là sai thông tin xác thực, hết số dư, hoặc brandname chưa được duyệt.', 'omniwp' ),
				'fix'     => __( 'Mở Nhật ký để xem nhà cung cấp trả về đúng chữ gì, rồi bấm Gửi thử — nút đó hiển thị nguyên request và response.', 'omniwp' ),
				'tab'     => 'delivery-sms',
			),
			array(
				'quote'   => 'Vui lòng đợi %d giây trước khi yêu cầu mã mới.',
				'symptom' => '',
				'cause'   => __( 'Khách bấm “Gửi lại” sớm hơn thời gian chờ. Đây là hành vi đúng, không phải lỗi.', 'omniwp' ),
				'fix'     => __( 'Muốn ngắn hơn thì đổi hồ sơ mã, hoặc chọn “Tuỳ chỉnh” để tự đặt thời gian chờ.', 'omniwp' ),
				'tab'     => 'delivery',
			),
			array(
				'quote'   => 'Tài khoản tạm thời bị khoá do đăng nhập sai nhiều lần. Vui lòng thử lại sau %d phút.',
				'symptom' => '',
				'cause'   => __( 'Đã chạm ngưỡng đăng nhập sai. Khoá tự mở sau khoảng thời gian đã đặt.', 'omniwp' ),
				'fix'     => __( 'Không cần làm gì để mở khoá. Ngưỡng và thời gian khoá nằm ở đây.', 'omniwp' ),
				'tab'     => 'security',
			),
			array(
				'quote'   => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.',
				'symptom' => '',
				'cause'   => __( 'Nonce đã hết hạn. Gần như luôn là do trang chứa form bị cache, hoặc khách để form mở quá lâu.', 'omniwp' ),
				'fix'     => __( 'Loại trang chứa shortcode ra khỏi cache. Hộp đăng nhập nổi không gặp lỗi này vì nó tải form ngay lúc mở.', 'omniwp' ),
				'tab'     => '',
			),
			array(
				'quote'   => 'Tài khoản này chưa có email thật. Vui lòng dùng số điện thoại.',
				'symptom' => '',
				'cause'   => __( 'Tài khoản được tạo bằng OTP số điện thoại, nên email của nó là một địa chỉ nội bộ và không nhận được thư nào.', 'omniwp' ),
				'fix'     => __( 'Thành viên tự thêm email thật ở trang tài khoản; địa chỉ đó phải xác minh bằng mã trước khi dùng để đăng nhập.', 'omniwp' ),
				'tab'     => 'auth',
			),
			array(
				'quote'   => '',
				'symptom' => __( 'Trang không hiện form nào cả', 'omniwp' ),
				'cause'   => __( 'Chưa có trang nào chứa [smart_auth], và tích hợp trang My Account của WooCommerce đang tắt.', 'omniwp' ),
				'fix'     => __( 'Đặt shortcode lên một trang, hoặc bật thay form My Account.', 'omniwp' ),
				'tab'     => 'profile',
			),
			array(
				'quote'   => '',
				'symptom' => __( 'Bộ chọn Tỉnh/Thành không hiện gì', 'omniwp' ),
				'cause'   => __( 'Module địa chỉ đang bật nhưng dữ liệu hành chính chưa được sinh ra.', 'omniwp' ),
				'fix'     => __( 'Chạy <code>php bin/build-address-data.php path/to/source.json</code>. Trạng thái dữ liệu hiển thị ngay trên màn hình cài đặt.', 'omniwp' ),
				'tab'     => 'profile',
			),
			array(
				'quote'   => '',
				'symptom' => __( 'Nút đăng nhập bằng Google không xuất hiện', 'omniwp' ),
				'cause'   => __( 'Nhà cung cấp chưa bật, hoặc thiếu Client ID / Client Secret. Dán nhầm Secret trùng với ID cũng bị từ chối — đây là lỗi thường gặp nhất.', 'omniwp' ),
				'fix'     => __( 'Kiểm tra thẻ nhà cung cấp: khi nào hiện “Sẵn sàng” thì nút mới xuất hiện ngoài trang.', 'omniwp' ),
				'tab'     => 'providers',
			),
			array(
				'quote'   => '',
				'symptom' => __( 'Website đột nhiên ngừng gửi mã, không báo lỗi gì', 'omniwp' ),
				'cause'   => __( 'Đã chạm trần gửi toàn site nên plugin tự tạm dừng. Đây là cái chặn hoá đơn khi bị tấn công bơm tin nhắn.', 'omniwp' ),
				'fix'     => __( 'Màn hình Tổng quan báo còn bao nhiêu phút và có nút mở lại ngay. Xem nhật ký trước khi mở.', 'omniwp' ),
				'tab'     => 'security',
			),
			array(
				'quote'   => '',
				'symptom' => __( 'Mã OTP hiện thẳng trên màn hình', 'omniwp' ),
				'cause'   => __( 'Chế độ DEV đang bật.', 'omniwp' ),
				'fix'     => __( 'Tắt trước khi đưa website chạy thật.', 'omniwp' ),
				'tab'     => 'advanced',
			),
		);
	}

	/**
	 * The four or five hooks that actually get asked about.
	 *
	 * Not a hook reference — `README.md` is. Every name here is asserted to be a
	 * filter that really runs, so this list cannot become the third place naming
	 * a control that does not exist.
	 *
	 * @return array<string,string>
	 */
	public static function filters(): array {
		return array(
			'omniwp_popup_enabled'   => __( 'Tắt hẳn hộp đăng nhập nổi trên toàn site.', 'omniwp' ),
			'omniwp_capture_links'   => __( 'Những link đăng nhập sẵn có mà plugin tự nhận ra và mở hộp thay vì chuyển trang. Trả về mảng rỗng để không đụng vào link nào của giao diện.', 'omniwp' ),
			'omniwp_dialog_aliases'  => __( 'Thêm hoặc bớt một cách viết <code>#…</code> để mở hộp.', 'omniwp' ),
			'omniwp_account_menu'    => __( 'Sửa các mục trong menu tài khoản, kể cả hai mục do plugin ghim.', 'omniwp' ),
			'omniwp_gateway_presets' => __( 'Thêm một preset nhà cung cấp SMS.', 'omniwp' ),
		);
	}

	// -----------------------------------------------------------------

	/**
	 * The six-section guide in a 2-column layout (1:3 Table of Contents & Content).
	 */
	public function render(): void {
		?>
		<div class="wrap omniwp-admin sl-guide-page">
			<div class="sl-guide-header">
				<h1><?php esc_html_e( 'OmniWP — Hướng dẫn', 'omniwp' ); ?></h1>
				<p class="sl-guide-lead">
					<?php esc_html_e( 'Bản tóm tắt hướng dẫn sử dụng nhanh. Bản đầy đủ — hook, REST API, cách đổi màu — nằm trong tệp README.md của plugin.', 'omniwp' ); ?>
				</p>
			</div>

			<div class="sl-guide-layout">
				<aside class="sl-guide-toc">
					<div class="sl-guide-toc__inner">
						<h3><?php esc_html_e( 'Mục lục', 'omniwp' ); ?></h3>
						<nav class="sl-guide-toc__nav">
							<a href="#quick-start" class="sl-guide-toc__link active">
								<span class="dashicons dashicons-controls-play"></span>
								<?php esc_html_e( '1. Ba bước để chạy được', 'omniwp' ); ?>
							</a>
							<a href="#shortcodes" class="sl-guide-toc__link">
								<span class="dashicons dashicons-shortcode"></span>
								<?php esc_html_e( '2. Shortcode', 'omniwp' ); ?>
							</a>
							<a href="#triggers" class="sl-guide-toc__link">
								<span class="dashicons dashicons-external"></span>
								<?php esc_html_e( '3. Mở hộp đăng nhập', 'omniwp' ); ?>
							</a>
							<a href="#account-button" class="sl-guide-toc__link">
								<span class="dashicons dashicons-admin-users"></span>
								<?php esc_html_e( '4. Nút tài khoản header', 'omniwp' ); ?>
							</a>
							<a href="#troubleshooting" class="sl-guide-toc__link">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( '5. Khi có sự cố', 'omniwp' ); ?>
							</a>
							<a href="#developers" class="sl-guide-toc__link">
								<span class="dashicons dashicons-code-standards"></span>
								<?php esc_html_e( '6. Cho lập trình viên', 'omniwp' ); ?>
							</a>
						</nav>
					</div>
				</aside>

				<main class="sl-guide-content">
					<section id="quick-start" class="sl-guide-section">
						<?php $this->quick_start(); ?>
					</section>

					<section id="shortcodes" class="sl-guide-section">
						<?php $this->shortcode_table(); ?>
					</section>

					<section id="triggers" class="sl-guide-section">
						<?php $this->triggers(); ?>
					</section>

					<section id="account-button" class="sl-guide-section">
						<?php $this->account_button(); ?>
					</section>

					<section id="troubleshooting" class="sl-guide-section">
						<?php $this->troubleshooting(); ?>
					</section>

					<section id="developers" class="sl-guide-section">
						<?php $this->for_developers(); ?>
					</section>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * Three steps, then a pointer at the screen that answers "did it work".
	 *
	 * It does not restate the readiness rows. Overview already lists every
	 * condition with a button to the control that fixes it, and a second copy
	 * here would be a list that goes stale the first time a check is added.
	 */
	private function quick_start(): void {
		?>
		<div class="sl-guide-card">
			<h2><?php esc_html_e( 'Ba bước để chạy được', 'omniwp' ); ?></h2>
			<ol class="sl-guide-steps">
				<li>
					<strong><?php esc_html_e( 'Chọn cách định danh.', 'omniwp' ); ?></strong>
					<?php esc_html_e( 'Khách đăng nhập bằng số điện thoại, bằng email, hay cả hai.', 'omniwp' ); ?>
					<?php $this->tab_link( 'auth' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Bật kênh gửi mã tương ứng, rồi bấm Gửi thử.', 'omniwp' ); ?></strong>
					<?php esc_html_e( 'Nhận số điện thoại thì phải có kênh SMS: không có nhà cung cấp nào thì không mã nào tới được một số điện thoại.', 'omniwp' ); ?>
					<?php $this->tab_link( 'delivery-sms' ); ?>
					<?php $this->tab_link( 'delivery-email' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Đặt form lên một trang.', 'omniwp' ); ?></strong>
					<?php
					printf(
						/* translators: %s: the [smart_auth] shortcode. */
						esc_html__( 'Dán %s vào trang đăng nhập. Website dùng WooCommerce thì có thể bật thay form My Account thay cho việc này — hoặc làm cả hai.', 'omniwp' ),
						'<code>[smart_auth]</code>' // phpcs:ignore WordPress.Security.EscapeOutput -- literal markup.
					);
					?>
					<?php $this->tab_link( 'profile' ); ?>
				</li>
			</ol>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the overview screen. */
					esc_html__( 'Xong ba bước thì mở %s: màn hình đó liệt kê mọi điều kiện còn thiếu và nút đi thẳng tới chỗ sửa.', 'omniwp' ),
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( self::tab_url( SettingsPage::OVERVIEW ) ),
						esc_html__( 'Tổng quan', 'omniwp' )
					) // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped above.
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Every registered tag, with the defaults read out of the catalog.
	 */
	private function shortcode_table(): void {
		?>
		<div class="sl-guide-card">
			<h2><?php esc_html_e( 'Shortcode', 'omniwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Dán vào nội dung trang như một đoạn văn bản thường. Chỉ [smart_auth] là bắt buộc; những cái còn lại là tuỳ nhu cầu.', 'omniwp' ); ?>
			</p>

			<table class="widefat striped sl-guide-table">
				<thead>
					<tr>
						<th style="width:220px"><?php esc_html_e( 'Shortcode', 'omniwp' ); ?></th>
						<th><?php esc_html_e( 'Làm gì', 'omniwp' ); ?></th>
						<th style="width:32%"><?php esc_html_e( 'Đặt ở đâu', 'omniwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::shortcodes() as $tag => $entry ) : ?>
						<tr>
							<td>
								<code>[<?php echo esc_html( $tag ); ?>]</code>
								<p class="description"><?php echo esc_html( $entry['label'] ); ?></p>
							</td>
							<td>
								<?php echo esc_html( $entry['summary'] ); ?>
								<?php $this->attribute_list( $tag, $entry['atts'] ); ?>
							</td>
							<td><?php echo esc_html( $entry['where'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The attributes of one tag, with the live defaults beside them.
	 *
	 * @param string               $tag  Shortcode tag.
	 * @param array<string,string> $atts Documented attributes, name => help.
	 */
	private function attribute_list( string $tag, array $atts ): void {
		if ( ! $atts ) {
			return;
		}

		// From the catalog, never from a number typed here: the table would
		// otherwise be the second place a default is written down.
		$defaults = Shortcodes::CATALOG[ $tag ]['atts'] ?? array();
		?>
		<table class="sl-guide-atts">
			<tbody>
			<?php foreach ( $atts as $name => $help ) : ?>
				<tr>
					<td><code><?php echo esc_html( $name ); ?></code></td>
					<td>
						<?php if ( '' !== (string) ( $defaults[ $name ] ?? '' ) ) : ?>
							<code><?php echo esc_html( (string) $defaults[ $name ] ); ?></code>
						<?php else : ?>
							<span class="description">—</span>
						<?php endif; ?>
					</td>
					<td><?php echo wp_kses( $help, self::INLINE_HTML ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The four ways to open the dialog, and why one of them is canonical.
	 */
	private function triggers(): void {
		?>
		<div class="sl-guide-card">
			<h2><?php esc_html_e( 'Mở hộp đăng nhập từ bất kỳ trang nào', 'omniwp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Hộp đăng nhập nổi lên ngay tại trang khách đang đứng và chạy trọn luồng ở đó — không ai bị đá ra khỏi trang sản phẩm đang xem. Bốn cách gọi, cùng một bộ từ vựng:', 'omniwp' ); ?>
			</p>

			<table class="widefat striped sl-guide-table">
				<tbody>
					<tr>
						<td style="width:260px"><code>?OMNIWP_step=identify</code></td>
						<td>
							<strong><?php esc_html_e( 'Dạng chuẩn.', 'omniwp' ); ?></strong>
							<?php esc_html_e( 'Máy chủ đọc được, nên gửi trong email/SMS hay dùng làm đích chuyển hướng đều được, và trang vẫn hiện form khi JavaScript không chạy.', 'omniwp' ); ?>
						</td>
					</tr>
					<tr>
						<td><?php $this->alias_list(); ?></td>
						<td><?php esc_html_e( 'Cách viết ngắn, gõ tay trong trình soạn thảo. Fragment không bao giờ được gửi lên máy chủ, nên nó không làm được ba việc ở trên.', 'omniwp' ); ?></td>
					</tr>
					<tr>
						<td><code>data-omniwp="identify"</code></td>
						<td><?php esc_html_e( 'Gắn lên phần tử bất kỳ, kể cả khi đó không phải là một link.', 'omniwp' ); ?></td>
					</tr>
					<tr>
						<td><code>[OMNIWP_button]</code></td>
						<td><?php esc_html_e( 'Cho website dựng bằng Elementor hoặc Gutenberg, không sửa được template.', 'omniwp' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Ngoài ra plugin tự nhận ra link đăng nhập sẵn có của giao diện, của wp-login.php và của WooCommerce rồi mở hộp thay vì chuyển trang. Nó chỉ chặn cú click và không bao giờ sửa href, nên khi JavaScript hỏng thì mọi link vẫn là link bình thường.', 'omniwp' ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the data attribute that opts a link out. */
					esc_html__( 'Muốn chừa đúng một link ra thì thêm %s vào thẻ đó.', 'omniwp' ),
					'<code>data-no-omniwp</code>' // phpcs:ignore WordPress.Security.EscapeOutput -- literal markup.
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The fragment spellings, read from the map at render time.
	 *
	 * `LoginDialog::aliases()` is filterable, so a site that adds a spelling gets
	 * a guide that names it. A list typed here would go on naming three.
	 */
	private function alias_list(): void {
		$aliases = array_keys( LoginDialog::aliases() );

		if ( ! $aliases ) {
			echo '<span class="description">' . esc_html__( 'Không có cách viết ngắn nào đang bật.', 'omniwp' ) . '</span>';
			return;
		}

		foreach ( $aliases as $index => $alias ) {
			printf(
				'%s<code>#%s</code>',
				0 === $index ? '' : ' ',
				esc_html( (string) $alias )
			);
		}
	}

	private function account_button(): void {
		?>
		<div class="sl-guide-card">
			<h2><?php esc_html_e( 'Nút tài khoản trên header', 'omniwp' ); ?></h2>
			<p>
				<?php esc_html_e( 'Một shortcode, hai trạng thái: chưa đăng nhập là nút “Đăng nhập”, đã đăng nhập là tên thành viên kèm menu đổ xuống. Không phải hai shortcode, vì người đặt nút vào header không biết trước khách là ai.', 'omniwp' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: link to the settings screen holding the menu location. */
					esc_html__( 'Không sửa được template thì chọn một vị trí menu của giao diện ở mục “Menu tài khoản” trong %s; nút sẽ được chèn vào cuối menu đó. Mặc định là không chèn.', 'omniwp' ),
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( self::tab_url( 'profile' ) ),
						esc_html( self::tab_label( 'profile' ) )
					) // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped above.
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Các mục ở giữa menu là của bạn, thêm ở cùng màn hình đó. Biểu tượng chọn từ một bộ đóng, không nhập SVG:', 'omniwp' ); ?>
			</p>
			<p class="sl-guide-icons">
				<?php foreach ( array_keys( IconSet::names() ) as $icon ) : ?>
					<code><?php echo esc_html( (string) $icon ); ?></code>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The message the visitor sees, what it means, and the screen that fixes it.
	 */
	private function troubleshooting(): void {
		?>
		<div class="sl-guide-card">
			<h2><?php esc_html_e( 'Khi có sự cố', 'omniwp' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the audit log screen. */
					esc_html__( 'Cột đầu là đúng chữ mà khách nhìn thấy. Khi cần biết nhà cung cấp trả về gì, mở %s — mỗi lần gửi đều được ghi lại ở đó.', 'omniwp' ),
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( admin_url( 'admin.php?page=' . SettingsPage::AUDIT_SLUG ) ),
						esc_html__( 'Nhật ký', 'omniwp' )
					) // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped above.
				);
				?>
			</p>

			<table class="widefat striped sl-guide-table">
				<thead>
					<tr>
						<th style="width:28%"><?php esc_html_e( 'Hiện tượng', 'omniwp' ); ?></th>
						<th><?php esc_html_e( 'Vì sao', 'omniwp' ); ?></th>
						<th style="width:30%"><?php esc_html_e( 'Sửa ở đâu', 'omniwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::problems() as $row ) : ?>
						<tr>
							<td>
								<?php if ( '' !== $row['quote'] ) : ?>
									<em>“<?php echo esc_html( self::readable( $row['quote'] ) ); ?>”</em>
								<?php else : ?>
									<?php echo esc_html( $row['symptom'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['cause'] ); ?></td>
							<td>
								<?php echo wp_kses( $row['fix'], self::INLINE_HTML ); ?>
								<?php if ( '' !== $row['tab'] ) : ?>
									<p>
										<a class="button button-small" href="<?php echo esc_url( self::tab_url( $row['tab'] ) ); ?>">
											<?php echo esc_html( self::tab_label( $row['tab'] ) ); ?>
										</a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function for_developers(): void {
		?>
		<div class="sl-guide-card">
			<h2><?php esc_html_e( 'Cho lập trình viên', 'omniwp' ); ?></h2>
			<table class="widefat striped sl-guide-table">
				<tbody>
					<?php foreach ( self::filters() as $hook => $what ) : ?>
						<tr>
							<td style="width:280px"><code><?php echo esc_html( (string) $hook ); ?></code></td>
							<td><?php echo wp_kses( $what, self::INLINE_HTML ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: 1: the CSS token file, 2: the README file. */
					esc_html__( 'Màu sắc và bo góc là biến CSS trên :root trong %1$s — ghi đè một dòng là xong. Danh sách hook đầy đủ, REST API và cách thêm preset gateway nằm trong %2$s.', 'omniwp' ),
					'<code>assets/css/omniwp-tokens.css</code>', // phpcs:ignore WordPress.Security.EscapeOutput -- literal markup.
					'<code>README.md</code>' // phpcs:ignore WordPress.Security.EscapeOutput -- literal markup.
				);
				?>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------

	/** The inline markup a help string may carry. */
	private const INLINE_HTML = array(
		'code'   => array(),
		'strong' => array(),
		'em'     => array(),
	);

	/**
	 * A message with its sprintf placeholders made readable.
	 *
	 * The stored string is verbatim so rule 7 can find it in the source; "%d
	 * giây" on screen would read like a bug in the guide rather than a number
	 * the plugin fills in.
	 */
	private static function readable( string $message ): string {
		return str_replace( array( '%d', '%s' ), '…', $message );
	}

	private static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . $tab );
	}

	private static function tab_label( string $tab ): string {
		return (string) ( SettingsPage::tabs()[ $tab ] ?? $tab );
	}

	/** A "go to this screen" link, inline in a sentence. */
	private function tab_link( string $tab ): void {
		printf(
			' <a class="button button-small" href="%s">%s</a>',
			esc_url( self::tab_url( $tab ) ),
			esc_html( self::tab_label( $tab ) )
		);
	}
}
