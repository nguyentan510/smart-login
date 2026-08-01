# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/).

## [1.0.1] — chưa phát hành

Bản này viết lại tầng định danh và dựng ranh giới chống lạm dụng. Chi tiết thiết
kế ở [`docs/identity-model.md`](docs/identity-model.md) và
[`docs/abuse-boundary.md`](docs/abuse-boundary.md), quá trình ở
[`docs/refactor-plan.md`](docs/refactor-plan.md).

### Chống lạm dụng

Mọi giới hạn trước đây đều tính theo **một** số điện thoại hoặc **một** IP — đúng
hai trục kẻ tấn công xoay vòng được. Không có gì đếm trên phạm vi cả website, nên
một botnet đổi cả hai trục không gặp trần nào.

- **Trần gửi mã toàn site theo giờ và theo ngày**, kèm kill switch tự động và
  email cảnh báo. Đây là trần duy nhất không thể đi vòng bằng cách đổi IP hay đổi
  số. Chạm trần thì việc gửi tạm dừng; màn hình Tổng quan có nút mở lại thủ công.
- **Chỉ gửi tới mã quốc gia đã cho phép** (mặc định: chỉ mã mặc định). Trước đây
  mọi mã ngoài `84` chỉ bị kiểm độ dài 8–15 chữ số, nghĩa là mã xác thực có thể bị
  nhắm tới đầu số premium ở nước mà kẻ tấn công ăn chia doanh thu với nhà mạng.
- **Bước tra định danh nay có hạn mức theo IP.** Trước đây `RateLimiter` chỉ được
  gọi từ trong `OtpService::issue()`, tức nhánh *"chưa có tài khoản"*; một số đã
  đăng ký đi thẳng tới màn hình mật khẩu mà không qua giới hạn nào, nên danh sách
  khách hàng có thể bị dò sạch miễn phí. Màn hình Quên mật khẩu có cùng lỗ hổng và
  nay dùng chung hạn mức đó.
- **Trần đăng nhập sai theo IP.** Khoá cũ tính theo cặp `(tài khoản, IP)` nên
  không thấy được kiểu rải mật khẩu: một mật khẩu phổ biến thử trên hàng nghìn tài
  khoản chỉ ghi một lần sai cho mỗi tài khoản.
- **Timeout gửi bị chặn cứng ở 15 giây và có ngắt mạch.** Mỗi lần gửi giữ một
  tiến trình PHP; ở 10 request/giây, timeout 10 giây chiếm 100 tiến trình trong
  khi pool PHP-FPM điển hình chỉ có 20–50 — thứ sập là cả website.
- **Cấu hình proxy tin cậy theo dải CIDR.** Sau Cloudflare, `REMOTE_ADDR` là IP
  máy chủ biên, nên mọi khách bị tính chung một địa chỉ và mọi giới hạn theo IP
  vừa chặn oan vừa vô dụng. Chỉ một cái cờ là không đủ: header chỉ đáng tin khi
  máy gửi nó nằm trong dải đã khai.
- **Xác minh chống robot tuỳ chọn** (Turnstile / hCaptcha), mặc định chỉ hiện khi
  site đang bị ép. Ngày thường trình duyệt khách không tải script bên thứ ba nào.
- **Nhật ký không còn khuếch đại cuộc tấn công nó ghi lại.** Vượt trần mỗi loại sự
  kiện chỉ ghi một dòng tổng hợp cho cả giờ; các sự kiện quan trọng không bao giờ
  bị bỏ.

### Sửa lỗi

- **Thời gian giữ nhật ký và bản ghi OTP mà quản trị viên đặt nay mới thực sự có
  tác dụng.** `Installer::cleanup()` đọc key phẳng `otp_retention_days` và
  `audit_retention_days` trong khi bản viết lại phần cài đặt đã đổi chúng thành
  `advanced.*`. Cả hai lần đọc đều trượt và âm thầm rơi về hằng số 7/90.
- **`/address/*` trả về 304 khi client đã có bản mới nhất**, thay vì tải lại toàn
  bộ dữ liệu để rồi báo không có gì thay đổi.

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

### Giao diện quản trị

- **Màn hình Tổng quan.** Trả lời câu hỏi duy nhất mà admin có sau khi cài: đã
  chạy được chưa. Bảy mục, đỏ là đang chặn, mỗi mục có nút đi thẳng tới chỗ sửa.
  Đặt làm màn mặc định.
- **Bản cài mặc định nay được cảnh báo là chưa chạy được.** `Chỉ số điện thoại`
  cộng `webhook tắt` nghĩa là không có đường nào gửi mã tới một số điện thoại,
  và `email bật` khiến nó trông như đã có kênh. Trước đây người đầu tiên phát
  hiện ra là người dùng đầu tiên bấm Đăng ký.
- **Preset gateway.** Chọn nhà cung cấp rồi chỉ điền ApiKey/Secret; URL, Body,
  Headers và điều kiện thành công được sinh lúc lưu và hiển thị read-only để
  kiểm chứng, với secret được che. Tab Gửi mã từ 11 trường xuống còn 3 ô. Chọn
  `Tuỳ chỉnh` thì mở khoá toàn bộ và không bao giờ bị sinh đè. Thêm gateway mới
  là một entry qua filter `smart_login_gateway_presets`.
- **Preset bảo mật OTP** — Chặt / Cân bằng / Thoáng thay cho sáu ô số, số chi
  tiết nằm trong khối gấp lại được.
- **Bỏ ô text ở những chỗ vốn là danh sách hữu hạn**: mã quốc gia thành select;
  link điều khoản và hai trang điều hướng thành bộ chọn trang.
- **Mỗi tab lưu riêng phần của mình.** Kèm theo đó, hidden input mang toàn bộ
  option biến mất — nghĩa là `Authorization: Bearer …` của gateway không còn nằm
  trong page source của những tab không liên quan.
- Trang settings tách thành màn hình + renderer + schema; `SettingsPage` còn lại
  menu và định tuyến.

### Trải nghiệm người dùng

- **Luồng vào theo định danh trước (identifier-first).** Cặp tab Đăng nhập /
  Đăng ký bị gỡ. Màn hình đầu hỏi đúng một ô, `IdentityDirectory` tra và tự rẽ
  nhánh. Người dùng hiếm khi biết mình đã có tài khoản hay chưa; bắt họ đoán
  trước rồi báo lỗi là bắt họ gõ lại từ đầu.
- **Họ tên và mật khẩu được hỏi sau bước OTP, không phải trước.** Bản cũ bắt điền
  5 trường rồi mới gửi mã, nên ai bỏ cuộc ở màn OTP thì site vừa mất tiền SMS
  vừa không có tài khoản nào được tạo. Định danh đã chứng minh được giữ ở server
  sau một *signup grant* dùng một lần; mật khẩu bị từ chối thì được cấp grant
  mới, không phải làm lại OTP.
- **Bỏ ô "Nhập lại mật khẩu".** Ô mật khẩu vốn đã có nút hiện/ẩn, tức là đã có
  cách kiểm tra mình gõ đúng chưa. Ô thứ hai chỉ còn là một chỗ nữa để gõ sai.
- **Màn hình chào mừng riêng, hiện ngay tại chỗ.** Trước đây màn "CHÚC MỪNG" tự
  chuyển trang sau 6 giây (nút ghi "Khám phá ngay" nhưng dẫn tới form sửa hồ sơ)
  rồi thả người vừa đăng ký vào form tài khoản đầy đủ: khoảng 15 control, trong
  đó có 2 widget xác thực OTP và 3 ô đổi mật khẩu. Nay là một màn hỏi tối đa 3
  mục, mỗi mục kèm lý do vì sao đáng điền, và luôn có nút **Để sau**.
- **Bỏ hẳn cổng chặn hồ sơ.** `smartlogin_gate` được đặt vào URL và
  `_smartlogin_profile_gate` được ghi vào meta, nhưng không đoạn mã nào đọc
  chúng. Giao diện nói "bắt buộc" trong khi không có gì cưỡng chế — vị trí tệ
  nhất trong ba lựa chọn. Nay chỉ còn lời mời.
- **Chỉ báo tiến độ** ở hai bước giữa của luồng đăng ký.
- Nhãn trường chuyển từ xám 13px sang màu chữ đầy đủ 14px; nhãn cũ dưới ngưỡng
  tương phản và trông như placeholder.
- Ô định danh dùng `type="tel"` khi site chỉ nhận số điện thoại và `type="email"`
  khi chỉ nhận email, thay vì luôn là `text`.
- Submit lặp không còn bắn được tin SMS thứ hai.

### Đã sửa (giao diện quản trị)

- **`field_email_optional` không còn tự tắt mỗi khi lưu tab Chung.** Khoá này
  được khai báo thuộc tab Chung nhưng không được vẽ ở đâu cả, nên nó vắng mặt cả
  ở hidden input lẫn `$_POST`, và `Settings::sanitize()` hiểu sự vắng mặt đó là
  một checkbox chưa tick rồi lưu `0`. Hậu quả dây chuyền: mọi tài khoản đăng ký
  bằng số điện thoại bị coi là thiếu thông tin bắt buộc (Email) và bị đẩy về
  trang hồ sơ — nơi ô Email là `readonly`. Khoá này nay có checkbox thật, và bộ
  test chặn mọi khoá được khai báo thuộc một tab mà tab đó không vẽ.
- **`require_verification` bị xoá.** Không nơi nào đọc nó; đó là một công tắc
  không nối vào đâu, lại cũng tự về `0` theo đúng cơ chế trên.
- Ô Email ở trang sửa hồ sơ không còn vừa gắn dấu `*` vừa `readonly` — một ô
  không gõ được thì không thể "bắt buộc" người dùng điền. Nay có liên kết trỏ
  thẳng tới bước xác thực OTP tương ứng.
- Nút **Gửi lại** trên màn OTP hiển thị đúng thời gian chờ còn lại sau một lần
  nhập sai. Trước đây nó được đặt về 0 nên trông như bấm được ngay, trong khi
  `RateLimiter` vẫn giữ cooldown và trả về lỗi.

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
