# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/).

## [1.0.1] — chưa phát hành

Bản này viết lại tầng định danh. Chi tiết thiết kế ở
[`docs/identity-model.md`](docs/identity-model.md), quá trình ở
[`docs/refactor-plan.md`](docs/refactor-plan.md).

### Bảo mật

- **Số điện thoại đã thu hồi không còn lấy lại được mật khẩu của chủ cũ.**
  Trước đây quyền sở hữu được suy ra từ `wp_users.user_login`, mà WordPress
  không cho phép thay đổi — nên sau khi người dùng đổi số, số cũ vẫn phân giải
  về tài khoản của họ. Người nhận SIM tái sử dụng có thể yêu cầu mã đặt lại mật
  khẩu và chiếm tài khoản. Nay quyền sở hữu chỉ đến từ bảng `smartlogin_identities`,
  và một chủ thể đã thu hồi không có chủ sở hữu.
- **`user_login` chuyển sang giá trị mờ** (`sl_` + 24 ký tự hex). WordPress phân
  giải nó ở `authenticate` priority 20 — trước mọi mã của plugin, và ở cả ba
  handler `username_password`, `email_password` và `application_password`, nên
  phạm vi gồm cả REST API. Một giá trị không đoán được loại bỏ hẳn đường này.
- **Địa chỉ email ảo không còn suy diễn được từ số điện thoại**, vì lý do tương
  tự và vì nó không bao giờ được cập nhật khi người dùng đổi số.
- **Chính sách mật khẩu áp dụng cho cả đặt lại mật khẩu.** Filter
  `smart_login_validate_password` trước đây chỉ chạy khi đăng ký, nên một site
  bắt buộc mật khẩu phải có chữ số có thể bị bỏ qua hoàn toàn bằng cách đi qua
  luồng Quên mật khẩu.
- **Phát hành phiên đăng nhập bắt buộc có `AuthProof`.** Constructor là private
  và chỉ ba factory ở tầng chứng minh tạo được, nên "không có chứng minh thì
  không có phiên" là lỗi kiểu chứ không còn là quy ước review.

### Đã thêm

- Bảng `smartlogin_identities` (chỉ số quyền, `UNIQUE (channel, subject)`) và
  `smartlogin_identity_history` (append-only, không dùng để xác thực).
- `IdentityDirectory` — nơi duy nhất trả lời "chủ thể này thuộc về ai".
- Bảng quyết định 4 intent × 4 trạng thái, dưới dạng dữ liệu.
- Xem và **bỏ liên kết** nhà cung cấp, có xác nhận mật khẩu và cổng chặn không
  cho bỏ liên kết cuối cùng.
- Cột "Định danh chính" và tìm kiếm theo định danh trong màn hình Users.
- Trường `shipping_phone` cho số điện thoại người nhận.
- `bin/build-pot.php` để sinh file dịch mà không cần wp-cli.

### Đã sửa

- **Đơn hàng WooCommerce lưu tên phường/xã thay vì mã** (`Phường Cầu Giấy`, không
  phải `00076`). Việc thay thế trước đây làm trong
  `woocommerce_after_checkout_validation`, mà `do_action()` truyền mảng theo giá
  trị nên phép gán bị bỏ đi.
- **`billing_phone` không còn bị số đăng nhập ghi đè.** Khách hàng muốn hàng giao
  tới số của người thân trước đây không giữ được: lưu sổ địa chỉ là bị đặt lại.
- **`smart_login_phone_is_valid` nay áp dụng cho số Việt Nam.** Nhánh xử lý số VN
  trả về trước khi tới filter, nên hook đã tài liệu hoá này chết trên đúng cấu
  hình mặc định mà gần như mọi site đều dùng.
- **ETag của REST địa chỉ theo dữ liệu, không theo phiên bản plugin.** Sinh lại
  bộ dữ liệu không đổi phiên bản, nên client vẫn phục vụ tên phường/xã cũ tới 24
  giờ — đúng tình huống mà header này sinh ra để xử lý.
- `uninstall.php` xoá thêm hai meta key mã phường/xã bị bỏ sót.

### Đã thay đổi

- Sáu hằng số `PURPOSE_*` gộp thành bốn `INTENT_*`. `change_phone`,
  `change_email` và `verify_email` vốn là cùng một mục đích áp cho kênh khác
  nhau, nên tập hằng số phình theo mỗi tính năng.
- Namespace `OTP\Channels` đổi thành `OTP\Transports`. Từ "channel" nay chỉ có
  một nghĩa duy nhất trong toàn dự án: một không gian định danh.
- Filter `smart_login_otp_channels` → `smart_login_otp_transports`.
- Placeholder `{{purpose}}` / `{{channel}}` → `{{intent}}` / `{{transport}}`.
- `LoginHandler::attempt()` bỏ tham số `$remember` — nó chưa bao giờ được dùng.
- Bảng `smart_login_external_identities` bị xoá; nhà cung cấp liên kết không còn
  là trường hợp đặc biệt.
- Cấu trúc CSDL `2` → `4`.

### Ghi chú nâng cấp

Bảng OTP được tạo lại khi nâng cấp, vì `dbDelta()` không đổi tên cột được. Bảng
này chỉ chứa mã dùng-một-lần còn hiệu lực, nên tác động xấu nhất là một người
đang dở luồng phải bấm gửi lại mã.
