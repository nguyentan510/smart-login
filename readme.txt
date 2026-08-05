=== Smart Login ===
Contributors: ngoctan
Tags: otp, login, phone, woocommerce, vietnam
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.4
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

= 1.0.4 =
* **Sửa lỗi nghiêm trọng: màn hình cài đặt không lưu được trên một site vừa cài.** WordPress áp bộ lọc làm sạch nhiều hơn một lần cho một lần lưu: `update_option()` làm sạch, rồi khi giá trị đang lưu trùng với giá trị mặc định đã đăng ký, nó đi qua `add_option()` — và hàm này làm sạch **lần thứ hai**. Lần thứ hai không còn biết tab nào đang được lưu, nên nó trả về đúng giá trị cũ và vứt bỏ kết quả của lần đầu — trong khi WordPress vẫn hiển thị "Đã lưu". Điều kiện kích hoạt là "giá trị đang lưu bằng mặc định", tức đúng trạng thái của site vừa kích hoạt plugin. Bản làm sạch nay idempotent.

= 1.0.3 =
* Hồ sơ OTP chỉ áp dụng khi bạn đổi nó, và tự chuyển sang "Tuỳ chỉnh" khi một giá trị khác đi. Trước đó mọi lần lưu tab Gửi mã đều áp lại hồ sơ lên sáu giá trị nó chi phối.
* Một trường không có trong lần gửi form nay giữ nguyên giá trị đã lưu, thay vì bị ghi thành giá trị nhỏ nhất — `otp.ttl` 300 thành 60, `otp.length` 6 thành 4.
* **Đính chính:** hai mục trên **không** phải nguyên nhân của báo cáo "lưu không được" trên tab Gửi mã. Màn hình không vể sáu trường đó thành ô nhập khi đang chọn một hồ sơ, nên từ trình duyệt không ai gõ vào được. Cả hai chỉ tới được qua REST hoặc lời gọi lập trình. Nguyên nhân thực của báo cáo đó là một gate kiểm thứ chạy trên site đang dùng và xoá toàn bộ cấu hình — không phải mã được phát hành, và gate đó nay tự từ chối một site đã có cấu hình.

= 1.0.2 =
* Email đã xác thực bởi Google trở thành một cách đăng nhập và khôi phục tài khoản, bật/tắt theo từng nhà cung cấp.
* Sửa: nhập email của tài khoản Google vào màn hình đăng nhập không còn gửi mã đăng ký rồi báo "tài khoản đã tồn tại" ở bước cuối.
* Sửa: xoá một người dùng nay trả lại số điện thoại và email họ giữ; trước đó những thông tin đó không ai đăng ký lại được.
* Màn hình đặt mật khẩu có lối nhận mã xác thực cho người chưa từng đặt mật khẩu.
* Mục Bảo mật không còn hiện ô "mật khẩu hiện tại" cho tài khoản không thể điền được ô đó.
* Bản này không nâng cấp từ bản cũ: xem Upgrade Notice.

= 1.0.1 =
* Mô hình định danh mới: mỗi kênh một identity, một bảng duy nhất quyết định quyền sở hữu.
* Sửa lỗi số điện thoại đã thu hồi vẫn lấy lại được mật khẩu của chủ cũ.
* `user_login` chuyển sang giá trị mờ, kèm cột định danh và tìm kiếm trong màn hình Users.
* `billing_phone` không còn bị ghi đè bởi số đăng nhập; bổ sung `shipping_phone`.
* Đơn hàng WooCommerce lưu tên phường/xã thay vì mã.
* Thêm chức năng xem và bỏ liên kết nhà cung cấp, có xác nhận mật khẩu và không cho bỏ liên kết cuối cùng.
* Chính sách mật khẩu áp dụng cho cả đặt lại mật khẩu.

== Upgrade Notice ==

= 1.0.4 =
Nên cập nhật ngay nếu bạn đang dùng 1.0.2 hoặc 1.0.3: trên một site vừa cài, màn hình cài đặt báo đã lưu nhưng không ghi gì.

= 1.0.3 =
Bản vá cho 1.0.2, cập nhật bình thường. Không cần làm gì thêm: hai lỗi được vá không tới được từ màn hình cài đặt, nên giá trị bạn đã lưu không bị ảnh hưởng.

= 1.0.2 =
Bản này **không có đường nâng cấp** từ 1.0.1. Plugin chưa từng phát hành, nên mọi đoạn mã di trú đã được gỡ bỏ và phiên bản cơ sở dữ liệu đặt lại về 1. Nếu bạn đang chạy 1.0.1, hãy gỡ và cài lại thay vì cập nhật.

= 1.0.1 =
Bản này thay đổi cấu trúc cơ sở dữ liệu định danh. Hãy sao lưu trước khi cập nhật.
