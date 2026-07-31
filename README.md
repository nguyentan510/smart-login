# Smart Login

Đăng nhập / đăng ký / xác thực OTP bằng **số điện thoại** hoặc **email** cho WordPress & WooCommerce, với khả năng đẩy OTP ra bất kỳ gateway SMS nào qua webhook — không cần viết code.

---

## Cài đặt

1. Copy thư mục `smart-login` vào `wp-content/plugins/`.
2. Kích hoạt plugin trong **Plugins → Installed Plugins**. Lúc kích hoạt plugin tạo 3 bảng và lên lịch dọn dẹp hằng ngày.
3. Vào **Smart Login → Tổng quan** để xem còn thiếu gì.

Yêu cầu: WordPress 6.0+, PHP 8.0+. WooCommerce là tuỳ chọn.

---

## Cấu hình tối thiểu để chạy

Sau khi kích hoạt, mở **Smart Login → Tổng quan**. Màn hình này liệt kê mọi điều kiện để plugin chạy được, mục nào **đỏ** là đang chặn, và mỗi mục có nút đi thẳng tới chỗ sửa. Bản cài mặc định sẽ đỏ ở **Kênh gửi mã** — đó là chủ ý: `Chỉ số điện thoại` cộng với webhook chưa bật thì không có đường nào gửi mã tới một số điện thoại.

### 1. Tab **Đăng nhập & Đăng ký**
- **Đăng nhập bằng**: `Chỉ số điện thoại` (mặc định).
- **Mã quốc gia mặc định**: chọn từ danh sách, mặc định `Việt Nam (+84)`.
- **Domain email ảo**: giữ nguyên `phone.invalid`. Phần trước `@` là mã nội bộ, không phải số điện thoại — xem mục Bảo mật.

### 2. Tab **Gửi mã**
Chọn **Nhà cung cấp**, điền các ô thông tin xác thực mà nhà cung cấp đó yêu cầu, bật **Kích hoạt**, rồi bấm **Gửi thử**. URL, Body và điều kiện thành công được sinh tự động — mở `Xem request sẽ được gửi` nếu muốn kiểm chứng.

Chọn `Tuỳ chỉnh` nếu gateway của bạn chưa có preset; khi đó mọi trường mở khoá và không bao giờ bị ghi đè.

### 3. Đặt form lên trang
Có hai cách, dùng được đồng thời:

- **WooCommerce**: bật *Thay form My Account* ở tab Hồ sơ & Địa chỉ. Trang `/my-account/` sẽ tự dùng form của plugin.
- **Shortcode**: đặt `[smart_auth]` hoặc `[smart_login]` vào bất kỳ trang nào. Box hỏi **một ô định danh duy nhất** rồi tự phân nhánh, kèm OAuth Google/Zalo phía dưới, và bao trọn cả luồng cho tới màn hình chào mừng.

Không còn cặp tab Đăng nhập / Đăng ký: người dùng hiếm khi biết mình thuộc nhánh nào, nên server tra định danh và tự quyết định. `[smart_register]` vẫn dùng được và mở cùng màn hình đó, chỉ đổi tiêu đề. Các shortcode khác: `[smart_verify_otp]`, `[smart_forgot_password]`, `[smart_profile]`.

Form đăng ký thu thập Họ tên và Mật khẩu **sau** bước OTP, mỗi màn một việc. Không có ô "Nhập lại mật khẩu" — nút hiện/ẩn mật khẩu đã làm đúng việc mà ô đó sinh ra để làm. Ngày sinh, Giới tính, Mã giới thiệu nằm ở màn hình chào mừng và ở hồ sơ, đều không bắt buộc.

---

## Gateway

> Sau khi lưu, luôn dùng nút **Gửi thử** — nó hiển thị đúng request đã gửi và response nhận về, đã che secret.

Preset có sẵn ở tab **Gửi mã**:

| Nhà cung cấp | Cần điền |
|---|---|
| **eSMS.vn** | ApiKey, SecretKey, Brandname |
| **Webhook JSON** (n8n / Make / Zapier) | URL nhận webhook |
| **Tuỳ chỉnh** | tự khai báo URL, Method, Body, điều kiện thành công |

Preset chỉ gồm những gateway mà tham số đã được kiểm chứng trong dự án này. Một preset sai tên tham số còn tệ hơn không có preset, vì nó trông đáng tin trong khi vẫn hỏng — nên gateway khác dùng `Tuỳ chỉnh`, hoặc thêm một preset bằng filter:

```php
add_filter( 'smart_login_gateway_presets', function ( array $presets ): array {
    $presets['my_gateway'] = array(
        'label'         => 'Gateway của tôi',
        'url'           => 'https://api.example.vn/send',
        'method'        => 'POST',
        'content_type'  => 'application/json',
        'body'          => '{"key":"{{cred:api_key}}","to":"{{phone_local}}","text":"{{code}}"}',
        'success_path'  => 'status',
        'success_value' => 'ok',
        'credentials'   => array(
            'api_key' => array( 'label' => 'API key', 'secret' => true ),
        ),
    );

    return $presets;
} );
```

`{{cred:tên}}` được thay bằng giá trị admin nhập, ngay lúc lưu. Ô nào đánh dấu `'secret' => true` sẽ không bao giờ được hiển thị lại và được che trong phần xem trước request.

### Danh sách placeholder

`{{destination}}` `{{phone}}` `{{phone_local}}` `{{phone_plus}}` `{{email}}` `{{code}}` `{{intent}}` `{{transport}}` `{{ttl_seconds}}` `{{ttl_minutes}}` `{{expires_at}}` `{{site_name}}` `{{site_url}}` `{{user_name}}` `{{delivery_id}}`

`{{intent}}` là mục đích (`register` / `login` / `recover` / `add_identity`), `{{transport}}` là kênh gửi (`sms` / `email`). Hai thứ này độc lập nhau: thêm một kênh gửi mới không sinh thêm mục đích nào.

Với `application/json`, mọi giá trị được escape tự động nên payload luôn là JSON hợp lệ kể cả khi tên website chứa dấu nháy kép.

Retry webhook mặc định bị tắt để tránh gửi trùng khi gateway đã nhận request nhưng response bị mất. Chỉ bật **Thử lại** khi gateway có cơ chế idempotency; điền tên header (ví dụ `Idempotency-Key`) ở trường **Header idempotency**. Plugin sẽ gửi cùng một `{{delivery_id}}` cho cả hai lần thử.

---

## Cách hệ thống hoạt động

### Bước 1 dùng chung: một ô định danh

Người dùng nhập SĐT (hoặc email). Plugin chuẩn hoá về E.164 (`0969789475` → `84969789475`), tra `IdentityDirectory` và rẽ nhánh:

- **Đã có chủ sở hữu** → màn hình nhập mật khẩu.
- **Chưa có, hoặc chủ cũ đã từ bỏ số này** → bắt đầu đăng ký.

Việc rẽ nhánh có tiết lộ một định danh đã được đăng ký hay chưa. Đây là đánh đổi có chủ ý: form đăng ký cũ vốn đã tiết lộ điều đó qua thông báo lỗi, và `RateLimiter` chặn dò quét theo IP lẫn theo đích đến.

### Đăng ký
1. Gửi mã 6 số, hiệu lực 5 phút. **Chưa tạo tài khoản** — chỉ có một bản ghi OTP tạm giữ đúng định danh. Nếu gửi thất bại, bản ghi bị xoá và người dùng nhận thông báo rõ ràng, không bao giờ mắc kẹt ở màn hình chờ mã không tồn tại.
2. Nhập đúng mã → đổi lấy một *signup grant* dùng một lần (transient 15 phút). Định danh đã chứng minh chỉ nằm ở phía server; trình duyệt chỉ cầm một chuỗi ngẫu nhiên.
3. Màn hình cuối hỏi Họ tên, Mật khẩu và Điều khoản. Hỏi ở đây chứ không phải ở bước 1 là chủ đích: tại thời điểm này người dùng đã bỏ công vào luồng, nên tỉ lệ hoàn tất cao hơn hẳn so với việc hỏi một người lạ. Sai mật khẩu thì được cấp grant mới — không phải làm lại OTP, không tốn thêm một tin SMS.
4. Tạo `wp_users` với `user_login` = SĐT, `user_email` = email ảo → tự động đăng nhập → màn hình chào mừng.

### Màn hình chào mừng
Hiện ngay tại chỗ, không chuyển trang. Chỉ hỏi những gì hồ sơ còn thiếu, tối đa 3 mục, mỗi mục kèm lý do vì sao đáng điền, và luôn có nút **Để sau**.

Không có rào chắn nào ở đây. Bản trước đặt cờ `smartlogin_gate` mà không nơi nào đọc, nên giao diện nói "bắt buộc" trong khi chẳng có gì cưỡng chế; cờ đó đã bị gỡ bỏ thay vì được cưỡng chế.

Đăng nhập qua Google/Zalo quay về bằng redirect nên không hiện tại chỗ được — luồng đó tới `/my-account/edit-account/?smartlogin_welcome=1`, và trang đó hiển thị đúng màn hình chào mừng này thay vì form sửa hồ sơ đầy đủ.

### Đăng nhập
Số điện thoại (hoặc email) + mật khẩu. Cắm vào filter `authenticate` của WordPress nên hoạt động ở cả `wp-login.php`, WooCommerce và form của plugin. Sai 5 lần → khoá 15 phút theo cặp IP + tài khoản.

### Quên mật khẩu
SĐT/email → OTP → đặt mật khẩu mới → **mọi phiên đăng nhập cũ bị huỷ**.

Mỗi OTP được bind với đúng mục đích (`register`, `reset` hoặc `login`) và chỉ một request thắng được bước consume; reset grant cũng là token dùng một lần.

---

## Hook cho lập trình viên

```php
// Tự viết cách gửi OTP; trả về non-null để plugin bỏ qua kênh mặc định.
add_filter( 'smart_login_dispatch_otp', function ( $handled, $destination, $code, $ctx ) {
    my_gateway_send( $destination, $code );
    return true; // hoặc new WP_Error(...) nếu thất bại
}, 10, 4 );

// Xử lý mã giới thiệu / cộng điểm sau khi tài khoản được tạo.
add_action( 'smart_login_user_registered', function ( $user_id, $payload ) {
    if ( ! empty( $payload['referral_code'] ) ) {
        my_loyalty_apply_referral( $user_id, $payload['referral_code'] );
    }
}, 10, 2 );

// Đổi nơi chuyển hướng sau khi đăng ký.
add_filter( 'smart_login_post_register_redirect', fn( $url, $uid ) => home_url( '/uu-dai/' ), 10, 2 );

// Bổ sung trường tuỳ ý vào form đăng ký.
add_filter( 'smart_login_registration_payload', function ( $payload, $input ) {
    $payload['company'] = sanitize_text_field( $input['company'] ?? '' );
    return $payload;
}, 10, 2 );

// Thêm ràng buộc mật khẩu.
add_filter( 'smart_login_validate_password', function ( $ok, $password ) {
    return preg_match( '/\d/', $password ) ? $ok : new WP_Error( 'weak', 'Mật khẩu phải chứa ít nhất một chữ số.' );
}, 10, 2 );
```

Hook khác: `smart_login_otp_code`, `smart_login_otp_sent`, `smart_login_otp_placeholders`, `smart_login_webhook_args`, `smart_login_otp_email`, `smart_login_synthetic_email`, `smart_login_phone_is_valid`, `smart_login_default_role`, `smart_login_post_login_redirect`, `smart_login_check_otp_send`, `smart_login_reset_reveal_unknown`, `smart_login_trust_proxy_headers`, `smart_login_missing_profile_fields`, `smart_login_step_url`, `smart_login_locate_template`.

---

## Module địa chỉ 2 cấp

Bộ chọn **Tỉnh/Thành phố → Phường/Xã** theo mô hình hành chính có hiệu lực từ 01/7/2025 (34 tỉnh/thành, ~3.320 phường/xã, đã bỏ cấp huyện).

### Dữ liệu hành chính

Plugin **kèm sẵn** bộ dữ liệu trong `data/` — 34 tỉnh/thành và 3.321 phường/xã — nên cài xong là dùng được ngay, không cần bước nào thêm.

Chỉ cần sinh lại khi ranh giới hành chính thay đổi:

1. Tải một bộ JSON 2 cấp, ví dụ [vietmap-company/vietnam_administrative_address](https://github.com/vietmap-company/vietnam_administrative_address) hoặc [qtv100291/Vietnam-administrative-division-json-server](https://github.com/qtv100291/Vietnam-administrative-division-json-server).
2. Chạy:

```bash
php bin/build-address-data.php duong/dan/toi/source.json
```

Script tự nhận diện các cách đặt tên trường phổ biến (`code`/`Code`/`id`, `name`/`full_name`/`name_with_type`, `wards`/`communes`/`children`…) và sẽ **từ chối ghi** nếu số lượng không khớp mô hình 2 cấp — đó là lưới an toàn chống việc vô tình dùng bộ dữ liệu 3 cấp cũ.

Kết quả: `data/provinces.php`, `data/wards/{mã tỉnh}.php`, `data/search-index.php`.

Trang **Smart Login → Cài đặt → Chung** hiển thị trạng thái dữ liệu đã cài kèm số lượng thực tế.

Khi nhà nước thay đổi đơn vị hành chính: tải JSON mới **từ cùng nguồn**, chạy lại lệnh trên. Không cần đụng vào cơ sở dữ liệu.

> **Quan trọng — mã tỉnh phụ thuộc vào nguồn dữ liệu.** Bộ đang cài dùng mã `11`–`44` (Hà Nội = `11`), không phải mã Tổng cục Thống kê (`01`, `79`…). Địa chỉ khách hàng được lưu bằng chính những mã này trong `billing_state`. Nếu sau này bạn đổi sang nguồn dữ liệu khác có hệ mã khác, **toàn bộ địa chỉ đã lưu sẽ trỏ sai tỉnh**. Hãy bám một nguồn duy nhất, hoặc viết script chuyển mã trước khi đổi.

### Nơi bộ chọn xuất hiện

| Màn hình | Ghi chú |
|---|---|
| Hồ sơ (`/my-account/edit-account/`) | Combobox cho cả hai cấp |
| Sổ địa chỉ (`/my-account/edit-address/`) | Woo giữ ô Tỉnh, plugin thay ô Phường/Xã |
| Thanh toán | Như trên |
| `[smart_address]` | Nhúng vào form bất kỳ; chỉ render trường, form và nút bấm là của bạn |

### Dữ liệu được lưu ở đâu

| Thông tin | Trường |
|---|---|
| Mã tỉnh | `billing_state` — trường gốc của Woo, nên shipping zone theo tỉnh vẫn chạy |
| Tên phường/xã | `billing_city` — hiện đúng trong email và hoá đơn |
| Mã phường/xã | `smartlogin_ward_code` (user), `_smartlogin_ward_code` (đơn hàng) |
| Số nhà, tên đường | `billing_address_1` |

Server **luôn tra tên từ mã**, không bao giờ tin tên do trình duyệt gửi lên. Ghép một phường/xã vào tỉnh không phải của nó sẽ bị từ chối ở cả trang hồ sơ lẫn checkout.

### Ô tìm nhanh

Gõ tên phường/xã bất kỳ (có dấu hoặc không — `cau giay` cũng ra `Cầu Giấy`) để tự điền cả hai ô, bỏ qua bước chọn tỉnh. Tắt được ở Cài đặt.

### Hook

```php
// Sau khi địa chỉ được lưu cho một user.
add_action( 'smart_login_address_saved', function ( $user_id, $address ) {
    error_log( SmartLogin\Address\AddressFields::format( $address ) );
}, 10, 2 );
```

---

## Tuỳ biến giao diện

Copy file từ `templates/` sang `yourtheme/smart-login/` và sửa. Màn hình đăng nhập/đăng ký là **`form-auth.php`** — một template gộp cả hai:

```
wp-content/themes/your-theme/smart-login/form-auth.php
```

`form-login.php` và `form-register.php` vẫn còn trong `templates/` nhưng **không được nạp** — override chúng sẽ không có tác dụng gì.

Với hai template WooCommerce, đường dẫn theo chuẩn của Woo:

```
wp-content/themes/your-theme/woocommerce/myaccount/form-login.php
wp-content/themes/your-theme/woocommerce/myaccount/form-edit-account.php
```

---

## REST API

Namespace `smart-login/v1`. Mọi endpoint dùng `POST` và cần header `X-WP-Nonce` (nonce `wp_rest`).

| Endpoint | Tham số |
|---|---|
| `/register` | `identity`, `password`, `full_name`, `dob`, `gender`, `referral_code`, `terms` |
| `/verify` | `code` (và `token` + `purpose` nếu client không dùng cookie) |
| `/resend` | — |
| `/login` | `identity`, `password`, `redirect_to` |
| `/forgot` | `identity` |
| `/reset` | `grant`, `password`, `password_confirm` |
| `/contact/start` | `type` (`phone` hoặc `email`), `value`; yêu cầu user đã đăng nhập |
| `/contact/verify` | `type`, `token`, `code`; chỉ cập nhật contact sau OTP đúng |
| `/contact/resend` | `token`; yêu cầu user đã đăng nhập |

---

## Google Login và Zalo Login

Hai provider mặc định tắt. Cách cấu hình thông thường:

1. Vào **Smart Login → Đăng nhập nhanh**.
2. Mở thẻ **Google Login** hoặc **Zalo Login**.
3. Tab **Thiết lập** có chỗ nhập Client/App ID, Secret và Callback URL cần sao chép.
4. Tab **Hướng dẫn** trình bày các bước khai báo ứng dụng ngay trong WordPress.
5. Bật provider và bấm **Lưu thay đổi**.

Secret nhập qua Settings được mã hóa trước khi lưu và không bao giờ được hiển thị lại trong HTML. Để trống ô Secret khi lưu sẽ giữ nguyên giá trị cũ; muốn xóa phải chọn rõ tùy chọn xóa.

Với deployment được quản lý tập trung, có thể dùng các constant hoặc biến môi trường dưới đây. Các giá trị này luôn được ưu tiên hơn Settings:

```php
define( 'SMART_LOGIN_GOOGLE_CLIENT_ID', '...' );
define( 'SMART_LOGIN_GOOGLE_CLIENT_SECRET', '...' );
define( 'SMART_LOGIN_GOOGLE_REDIRECT_URI', 'https://example.com/wp-admin/admin-post.php?action=smart_login_provider_callback&provider=google' );

define( 'SMART_LOGIN_ZALO_APP_ID', '...' );
define( 'SMART_LOGIN_ZALO_APP_SECRET', '...' );
define( 'SMART_LOGIN_ZALO_REDIRECT_URI', 'https://example.com/wp-admin/admin-post.php?action=smart_login_provider_callback&provider=zalo' );
```

Google ID token được kiểm tra chữ ký bằng public certificate, sau đó kiểm tra issuer, audience, expiry và nonce. Zalo dùng `id` làm định danh chính; nếu Zalo không cung cấp email verified, plugin tạo tài khoản provider-only và đưa user tới hồ sơ để bổ sung contact.

Provider đã xác thực không cần OTP bổ sung. Mọi phone/email do user tự nhập hoặc thay đổi trong trang hồ sơ vẫn phải hoàn tất OTP trước khi dữ liệu chính được cập nhật.

Zalo OA/ZNS không thuộc Login Provider và chưa được triển khai trong phase này; khi cần, nó phải được thêm như một OTP Channel riêng.

---

## Bảo mật

- Mã OTP sinh bằng `random_int()`, chỉ lưu dạng HMAC-SHA256, so sánh bằng `hash_equals()`.
- Bước xác thực dùng token ngẫu nhiên 64 ký tự trong cookie HttpOnly — số điện thoại không bao giờ đi qua form, nên không thể đổi số đích giữa chừng.
- Mật khẩu không bao giờ tồn tại ở dạng plaintext trong CSDL, kể cả trong bản ghi OTP tạm.
- Rate limit 3 tầng: cooldown giữa 2 lần gửi, giới hạn theo số điện thoại/giờ, giới hạn theo IP/giờ.
- Nonce + honeypot + kiểm tra thời gian điền form tối thiểu.
- Thông báo lỗi đăng nhập đồng nhất, không phân biệt sai tài khoản hay sai mật khẩu.
- Tài khoản mới luôn bị ép role `customer`; mọi role gửi kèm form đều bị bỏ qua.
- Nhật ký không bao giờ ghi mã OTP, mật khẩu hay khoá API.

---

## Kiểm thử

```bash
php tests/run-tests.php
```

Bộ test chạy độc lập, không cần cài WordPress — nó stub các hàm WP cần thiết và kiểm tra chuẩn hoá số điện thoại, đầu số nhà mạng, tách họ tên tiếng Việt, phân tích ngày sinh và độ an toàn JSON của template webhook.

Xem thêm ma trận kiểm thử thủ công 12 kịch bản trong kế hoạch triển khai.

---

## Lưu ý vận hành

**Email ảo.** Tài khoản đăng ký bằng SĐT nhận địa chỉ dạng `sl_9f2c…@phone.invalid`. Đuôi `.invalid` được RFC 2606 dành riêng nên không bao giờ phân giải được — đó là chủ ý.

Phần trước dấu `@` là mã nội bộ của tài khoản, **không phải số điện thoại**. Hai lý do: WordPress phân giải `user_email` ở `authenticate` priority 20 nên một địa chỉ suy diễn được từ số điện thoại sẽ là một identifier gõ được, đi vòng qua tầng định danh; và nó sẽ không bao giờ được cập nhật khi người dùng đổi số, khiến số cũ vẫn với tới được tài khoản qua đường email. Tuỳ chọn *Chặn email ảo* (bật mặc định) sẽ loại các địa chỉ này khỏi mọi email gửi đi, tránh bounce làm hỏng uy tín domain. **Đừng tắt nó** trừ khi bạn có cách xử lý khác.

**Chi phí SMS.** Với cấu hình mặc định, mỗi người dùng chỉ tốn 1 tin nhắn trọn đời (lúc đăng ký). Bật *OTP cho thiết bị lạ* sẽ làm chi phí tăng đáng kể.

**Chế độ DEV** hiển thị mã OTP ngay trên màn hình. Nó chỉ hoạt động khi bật đủ cả ba: tuỳ chọn trong Settings, hằng số `WP_DEBUG`, và `wp_get_environment_type()` khác `production`. Bật nhầm trên site thật sẽ không có tác dụng.
