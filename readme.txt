=== Smart Login ===
Contributors: ngoctan
Tags: otp, login, phone, woocommerce, vietnam
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Đăng nhập và đăng ký bằng số điện thoại hoặc email với mã OTP, kèm địa chỉ hành chính Việt Nam 2 cấp cho WooCommerce.

== Description ==

Smart Login thay thế màn hình đăng nhập mặc định của WordPress bằng một luồng xác thực dùng mã OTP, và tích hợp sẵn với WooCommerce.

**Định danh**

Mỗi cách đăng nhập của người dùng — số điện thoại, email, Google, Zalo — là một *identity* riêng trong bảng `smartlogin_identities`, với ràng buộc `UNIQUE (channel, subject)`. Đó là nơi duy nhất trả lời câu hỏi "số này thuộc về ai".

Hệ quả thực tế: khi người dùng đổi số điện thoại, số cũ được **thu hồi** chứ không phải ghi đè. Người nhận SIM tái sử dụng sau đó không thể dùng số ấy để lấy lại mật khẩu của chủ cũ.

**Số điện thoại đăng nhập và số nhận hàng là hai thứ khác nhau**

`billing_phone` của WooCommerce là thông tin giao hàng, có thể là số của người thân. Plugin chỉ điền vào khi ô đó đang trống, và không bao giờ ghi đè lựa chọn của khách. Số người nhận có ô riêng `shipping_phone`.

**Địa chỉ hành chính 2 cấp**

Bộ dữ liệu 34 tỉnh/thành và 3.321 phường/xã theo mô hình có hiệu lực từ 01/07/2025, dùng cho cả trang thanh toán và sổ địa chỉ của WooCommerce. Đơn hàng lưu **tên** phường/xã, đồng thời giữ mã chính thức trong metadata.

**Gửi mã OTP**

Qua webhook tới bất kỳ nhà cung cấp SMS nào (cấu hình bằng placeholder, không cần code), hoặc qua email bằng `wp_mail()` nên mọi plugin SMTP sẵn có đều hoạt động.

== Installation ==

1. Tải thư mục plugin vào `/wp-content/plugins/`.
2. Kích hoạt trong màn hình **Plugins**.
3. Vào **Smart Login → Cài đặt** để chọn kênh định danh và cấu hình webhook gửi SMS.
4. Đặt shortcode `[smart_login]` lên một trang, hoặc bật tuỳ chọn thay thế biểu mẫu của WooCommerce.

== Frequently Asked Questions ==

= Người dùng đổi số điện thoại thì số cũ còn đăng nhập được không? =

Không. Số cũ được thu hồi và ghi vào bảng lịch sử. Nó không còn chủ sở hữu, nên không đăng nhập được và cũng không lấy lại mật khẩu được — kể cả khi SIM đó đã được nhà mạng cấp cho người khác.

= Tại sao tên đăng nhập trong danh sách Users trông như `sl_9f2c...`? =

Vì WordPress phân giải `user_login` ở `authenticate` priority 20, trước mọi mã của plugin, và không cho phép đổi giá trị này về sau. Nếu nó chứa số điện thoại thì số cũ sẽ vĩnh viễn đăng nhập được. Giá trị mờ này loại bỏ hẳn khả năng đó. Cột "Định danh chính" và ô tìm kiếm trong màn hình Users vẫn tra được theo số điện thoại.

= Có bắt buộc dùng WooCommerce không? =

Không. WooCommerce chỉ cần khi bạn muốn dùng phần địa chỉ và các màn hình tài khoản.

= Làm sao tự tạo lại bộ dữ liệu địa chỉ? =

`php bin/build-address-data.php`. Bộ dữ liệu có sẵn trong plugin; chỉ cần chạy lại khi ranh giới hành chính thay đổi.

== Changelog ==

= 1.0.1 =
* Mô hình định danh mới: mỗi kênh một identity, một bảng duy nhất quyết định quyền sở hữu.
* Sửa lỗi số điện thoại đã thu hồi vẫn lấy lại được mật khẩu của chủ cũ.
* `user_login` chuyển sang giá trị mờ, kèm cột định danh và tìm kiếm trong màn hình Users.
* `billing_phone` không còn bị ghi đè bởi số đăng nhập; bổ sung `shipping_phone`.
* Đơn hàng WooCommerce lưu tên phường/xã thay vì mã.
* Thêm chức năng xem và bỏ liên kết nhà cung cấp, có xác nhận mật khẩu và không cho bỏ liên kết cuối cùng.
* Chính sách mật khẩu áp dụng cho cả đặt lại mật khẩu.

== Upgrade Notice ==

= 1.0.1 =
Bản này thay đổi cấu trúc cơ sở dữ liệu định danh. Hãy sao lưu trước khi cập nhật.
