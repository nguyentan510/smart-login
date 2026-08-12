# Smart Login

Đăng nhập / đăng ký / xác thực OTP bằng **số điện thoại** hoặc **email** cho WordPress & WooCommerce, với khả năng đẩy OTP ra bất kỳ gateway SMS nào qua webhook — không cần viết code.

---

## Cài đặt

1. Copy thư mục `omniwp` vào `wp-content/plugins/`.
2. Kích hoạt plugin trong **Plugins → Installed Plugins**. Lúc kích hoạt plugin tạo 3 bảng và lên lịch dọn dẹp hằng ngày.
3. Vào **Smart Login → Tổng quan** để xem còn thiếu gì.

Yêu cầu: WordPress 6.0+, PHP 8.0+. WooCommerce là tuỳ chọn.

---

## Cấu hình tối thiểu để chạy

Sau khi kích hoạt, mở **Smart Login → Tổng quan**. Màn hình này liệt kê mọi điều kiện để plugin chạy được, mục nào **đỏ** là đang chặn, và mỗi mục có nút đi thẳng tới chỗ sửa. Bản cài mặc định sẽ đỏ ở **Kênh gửi mã** — đó là chủ ý: `Chỉ số điện thoại` cộng với webhook chưa bật thì không có đường nào gửi mã tới một số điện thoại.

### 1. Tab **Đăng nhập & Đăng ký**
- **Đăng nhập bằng**: `Chỉ số điện thoại` (mặc định).
- **Mã quốc gia mặc định**: chọn từ danh sách, mặc định `Việt Nam (+84)`.
- **Mã quốc gia được phép**: để trống nghĩa là **chỉ chấp nhận mã mặc định ở trên**, không phải chấp nhận mọi quốc gia. Điền `84,65,1` nếu bạn thực sự phục vụ khách ở nhiều nước — xem mục Bảo mật để biết vì sao mỗi mã mở thêm là một khoản chi phí.
- **Domain email ảo**: giữ nguyên `phone.invalid`. Phần trước `@` là mã nội bộ, không phải số điện thoại — xem mục Bảo mật.

### 2. Tab **Gửi mã**
Chọn **Nhà cung cấp**, điền các ô thông tin xác thực mà nhà cung cấp đó yêu cầu, bật **Kích hoạt**, rồi bấm **Gửi thử**. URL, Body và điều kiện thành công được sinh tự động — mở `Xem request sẽ được gửi` nếu muốn kiểm chứng.

Chọn `Tuỳ chỉnh` nếu gateway của bạn chưa có preset; khi đó mọi trường mở khoá và không bao giờ bị ghi đè.

### 3. Đặt form lên trang
Có hai cách, dùng được đồng thời:

- **WooCommerce**: bật *Thay form My Account* ở tab Hồ sơ & Địa chỉ. Trang `/my-account/` sẽ tự dùng form của plugin.
- **Shortcode**: đặt `[smart_auth]` hoặc `[OMNIWP]` vào bất kỳ trang nào. Box hỏi **một ô định danh duy nhất** rồi tự phân nhánh, kèm OAuth Google phía dưới, và bao trọn cả luồng cho tới màn hình chào mừng.

Không còn cặp tab Đăng nhập / Đăng ký: người dùng hiếm khi biết mình thuộc nhánh nào, nên server tra định danh và tự quyết định. `[smart_register]` vẫn dùng được và mở cùng màn hình đó, chỉ đổi tiêu đề. Các shortcode khác: `[smart_verify_otp]`, `[smart_forgot_password]`, `[smart_profile]`.

### 4. Hộp đăng nhập ở mọi trang

Không cần đặt gì thêm: plugin nạp một script rất nhỏ trên toàn site, và mở hộp
đăng nhập có nền mờ ngay tại trang khách đang đứng — trang sản phẩm, bài blog,
trang danh mục. Toàn bộ luồng chạy trong đó: định danh → OTP → đăng ký → màn
hình chào mừng. **Không ai bị đá ra khỏi trang đang xem.**

Bốn cách gọi, cùng một bộ từ vựng:

| Cách gọi | Dùng khi |
|---|---|
| `?OMNIWP_step=identify` | **dạng chuẩn.** Server đọc được, nên gửi qua email/SMS và dùng làm đích redirect đều được |
| `#login` (hoặc `#dang-nhap`, `#dang-ky`, `#quen-mat-khau`) | viết tay trong trình soạn thảo cho nhanh |
| `data-omniwp="identify"` | gắn lên phần tử bất kỳ, kể cả không phải link |
| `[OMNIWP_button]` | site dựng bằng Elementor/Gutenberg, không sửa được template |

Ngoài ra plugin **tự nhận ra link đăng nhập sẵn có** của theme, của
`wp-login.php` và của WooCommerce, rồi mở hộp thay vì chuyển trang. Nó chỉ chặn
cú click — **không bao giờ sửa `href`** — nên khi JavaScript bị chặn hay chưa
tải xong, mọi link vẫn là link bình thường như theme đã viết.

Tại sao query param là dạng chuẩn chứ không phải `#login`: fragment không bao
giờ được gửi lên server, nên nó không thể render sẵn trước lần vẽ đầu, không
thể làm đích của một redirect, và không hiển thị gì khi JavaScript hỏng.

---

## Nút tài khoản trên header

`[OMNIWP_button]` là **một shortcode, hai trạng thái**. Chưa đăng nhập nó
là nút `Đăng nhập`; đã đăng nhập nó hiện tên của thành viên kèm menu tài khoản
đổ xuống. Không phải hai shortcode, vì người đặt nút vào header không biết
trước khách là ai.

| Thuộc tính | Mặc định | Ý nghĩa |
|---|---|---|
| `label` | `Đăng nhập` | chữ khi chưa đăng nhập |
| `collapse` | `mobile` | dưới 782px chỉ còn biểu tượng; `none` để luôn hiện chữ |
| `class` | — | thêm class của bạn |
| `step` | `identify` | bước mở ra khi bấm |

Không sửa được template thì vào **Cài đặt → Hồ sơ & Địa chỉ → Menu tài khoản**
và chọn một vị trí menu của giao diện; nút sẽ được chèn vào cuối menu đó. Mặc
định là **không chèn** — plugin được phép mặc định vô hình, nhưng không được
phép mặc định sửa markup của theme.

Menu đổ xuống chạy bằng `<details>`, nên nó **mở và đóng được kể cả khi
JavaScript không chạy**. Script chỉ thêm: bấm ra ngoài để đóng, phím `Escape`,
và `aria-expanded`.

### Các mục trong menu

Hai đầu là của plugin và không cấu hình được: **Thông tin cá nhân** tự tìm
trang tài khoản, **Đăng xuất** cần nonce nên không thể là một dòng link gõ tay.
Phần giữa là của bạn — thêm ở cùng màn hình cài đặt, mỗi dòng gồm biểu tượng,
nhãn và liên kết. Để trống cũng không sao: menu vẫn có hai mục dùng được.

Biểu tượng chọn từ một bộ đóng, không nhập SVG: `user`, `lock`, `map-pin`,
`shield`, `box`, `file-text`, `calendar`, `pill`, `heart`, `ticket`, `log-out`.

Sửa cả danh sách bằng code — filter `omniwp_account_menu` hoặc `[omniwp_button]` chạy **sau cùng**, trên mảng đã ghép, nên
gỡ được cả hai đầu ghim:

```php
add_filter( 'omniwp_account_menu', function ( array $items, int $user_id ): array {
	$items[] = array(
		'key'   => 'wishlist',   // ổn định, dùng để so khớp; không phải nhãn
		'label' => 'Sản phẩm yêu thích',
		'icon'  => 'heart',      // tên trong bộ trên; tên lạ tự chuyển về mặc định
		'url'   => home_url( '/yeu-thich/' ),
	);

	return $items;
}, 10, 2 );
```

Mỗi mục đúng bốn khoá. Khoá thứ năm sẽ bị loại bỏ khi chuẩn hoá.

### Đổi màu, đổi khoảng cách

Không có bảng chọn màu trong cài đặt, và sẽ không có. Toàn bộ token nằm trên
`:root` trong `assets/css/omniwp-tokens.css`, ghi đè một dòng là xong:

```css
:root {
	--sl-accent: #0f62fe;
	--sl-accent-dark: #0043ce;
	--sl-radius: 4px;
}
```

Breakpoint thu gọn là `782px` và **không phải** một biến — CSS không cho phép
custom property trong điều kiện `@media`. Muốn đổi thì khai báo lại hai rule
`@media (max-width: …)` của `omniwp-button.css` ở độ rộng của bạn; CSS của
theme nạp sau nên nó thắng.

Tắt hoàn toàn, hoặc chừa một link ra:

```php
// tắt hộp đăng nhập trên toàn site
add_filter( 'OMNIWP_popup_enabled', '__return_false' );

// giữ hộp, nhưng đừng chiếm quyền link đăng nhập nào của theme
add_filter( 'OMNIWP_capture_links', '__return_empty_array' );
```

```html
<!-- hoặc chừa đúng một link -->
<a href="/my-account/" data-no-omniwp>Tài khoản</a>
```

Form đăng ký thu thập Họ tên và Mật khẩu **sau** bước OTP, mỗi màn một việc. Không có ô "Nhập lại mật khẩu" — nút hiện/ẩn mật khẩu đã làm đúng việc mà ô đó sinh ra để làm. Ngày sinh và Giới tính nằm ở màn hình chào mừng và ở hồ sơ, đều không bắt buộc.

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
add_filter( 'OMNIWP_gateway_presets', function ( array $presets ): array {
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

**Timeout bị chặn cứng ở 15 giây**, kể cả khi giá trị cũ trong CSDL lớn hơn. Mỗi lần gửi giữ một tiến trình PHP đúng bằng khoảng thời gian đó; ở 10 request/giây, timeout 10 giây chiếm 100 tiến trình trong khi một pool PHP-FPM điển hình chỉ có 20–50 — thứ sập là cả website chứ không riêng trang đăng nhập.

**Ngắt mạch:** gateway lỗi liên tiếp đủ số lần (mặc định 5) thì plugin ngừng gọi nó trong 5 phút và trả lỗi ngay, thay vì giữ tiến trình để chờ một dịch vụ đã chết. Hết thời gian, đúng **một** lần gửi được cho đi thử; thất bại thì ngắt lại ngay. Admin nhận email khi mạch ngắt. Nút **Gửi thử** không bị ngắt mạch chặn — đó chính là cách bạn kiểm tra gateway đã sống lại chưa.

Retry webhook mặc định bị tắt để tránh gửi trùng khi gateway đã nhận request nhưng response bị mất. Chỉ bật **Thử lại** khi gateway có cơ chế idempotency; điền tên header (ví dụ `Idempotency-Key`) ở trường **Header idempotency**. Plugin sẽ gửi cùng một `{{delivery_id}}` cho cả hai lần thử.

---

## Cách hệ thống hoạt động

### Bước 1 dùng chung: một ô định danh

Người dùng nhập SĐT (hoặc email). Plugin chuẩn hoá về E.164 (`0969789475` → `84969789475`), tra `IdentityDirectory` và rẽ nhánh:

- **Đã có chủ sở hữu** → màn hình nhập mật khẩu.
- **Chưa có, hoặc chủ cũ đã từ bỏ số này** → bắt đầu đăng ký.

Việc rẽ nhánh có tiết lộ một định danh đã được đăng ký hay chưa. Đây là đánh đổi có chủ ý: form đăng ký cũ vốn đã tiết lộ điều đó qua thông báo lỗi.

Bản thân bước tra cứu được tính vào một hạn mức riêng theo IP (mặc định 30 lần/giờ, đổi được ở tab **Chống lạm dụng**), áp dụng **trước** khi tra và **giống hệt nhau ở cả hai nhánh** — nếu không, chính thông báo từ chối lại trở thành cái oracle mà nó sinh ra để đóng. Màn hình Quên mật khẩu dùng chung hạn mức đó, vì nó cũng tra danh bạ và cũng không tốn tin nhắn nào khi định danh không tồn tại.

### Đăng ký
1. Gửi mã 6 số, hiệu lực 5 phút. **Chưa tạo tài khoản** — chỉ có một bản ghi OTP tạm giữ đúng định danh. Nếu gửi thất bại, bản ghi bị xoá và người dùng nhận thông báo rõ ràng, không bao giờ mắc kẹt ở màn hình chờ mã không tồn tại.
2. Nhập đúng mã → đổi lấy một *signup grant* dùng một lần (transient 15 phút). Định danh đã chứng minh chỉ nằm ở phía server; trình duyệt chỉ cầm một chuỗi ngẫu nhiên.
3. Màn hình cuối hỏi Họ tên, Mật khẩu và Điều khoản. Hỏi ở đây chứ không phải ở bước 1 là chủ đích: tại thời điểm này người dùng đã bỏ công vào luồng, nên tỉ lệ hoàn tất cao hơn hẳn so với việc hỏi một người lạ. Sai mật khẩu thì được cấp grant mới — không phải làm lại OTP, không tốn thêm một tin SMS.
4. Tạo `wp_users` với `user_login` = SĐT, `user_email` = email ảo → tự động đăng nhập → màn hình chào mừng.

### Màn hình chào mừng
Hiện ngay tại chỗ, không chuyển trang. Chỉ hỏi những gì hồ sơ còn thiếu, tối đa 3 mục, mỗi mục kèm lý do vì sao đáng điền, và luôn có nút **Để sau**.

Không có rào chắn nào ở đây. Bản trước đặt cờ `OmniWP_gate` mà không nơi nào đọc, nên giao diện nói "bắt buộc" trong khi chẳng có gì cưỡng chế; cờ đó đã bị gỡ bỏ thay vì được cưỡng chế.

Đăng nhập qua provider quay về bằng redirect nên không hiện tại chỗ được — luồng đó tới `/my-account/edit-account/?OmniWP_welcome=1`, và trang đó hiển thị đúng màn hình chào mừng này thay vì form sửa hồ sơ đầy đủ.

### Đăng nhập
Số điện thoại (hoặc email) + mật khẩu. Cắm vào filter `authenticate` của WordPress nên hoạt động ở cả `wp-login.php`, WooCommerce và form của plugin. Sai 5 lần → khoá 15 phút theo cặp IP + tài khoản.

### Quên mật khẩu
SĐT/email → OTP → đặt mật khẩu mới → **mọi phiên đăng nhập cũ bị huỷ**.

Mỗi OTP được bind với đúng mục đích (`register`, `reset` hoặc `login`) và chỉ một request thắng được bước consume; reset grant cũng là token dùng một lần.

---

## Hook cho lập trình viên

```php
// Tự viết cách gửi OTP; trả về non-null để plugin bỏ qua kênh mặc định.
add_filter( 'OMNIWP_dispatch_otp', function ( $handled, $destination, $code, $ctx ) {
    my_gateway_send( $destination, $code );
    return true; // hoặc new WP_Error(...) nếu thất bại
}, 10, 4 );

// Thêm một trường của riêng bạn vào hồ sơ đăng ký, rồi xử lý sau khi tài khoản
// được tạo. Plugin không tự thu thập trường nào ngoài những gì nó hiển thị.
add_filter( 'OMNIWP_registration_payload', function ( $payload, $input ) {
    $payload['my_campaign'] = sanitize_text_field( $input['my_campaign'] ?? '' );
    return $payload;
}, 10, 2 );

add_action( 'OMNIWP_user_registered', function ( $user_id, $payload ) {
    if ( ! empty( $payload['my_campaign'] ) ) {
        my_loyalty_apply_campaign( $user_id, $payload['my_campaign'] );
    }
}, 10, 2 );

// Đổi nơi chuyển hướng sau khi đăng ký.
add_filter( 'OMNIWP_post_register_redirect', fn( $url, $uid ) => home_url( '/uu-dai/' ), 10, 2 );

// Bổ sung trường tuỳ ý vào form đăng ký.
add_filter( 'OMNIWP_registration_payload', function ( $payload, $input ) {
    $payload['company'] = sanitize_text_field( $input['company'] ?? '' );
    return $payload;
}, 10, 2 );

// Thêm ràng buộc mật khẩu.
add_filter( 'OMNIWP_validate_password', function ( $ok, $password ) {
    return preg_match( '/\d/', $password ) ? $ok : new WP_Error( 'weak', 'Mật khẩu phải chứa ít nhất một chữ số.' );
}, 10, 2 );
```

Hook khác: `OMNIWP_otp_code`, `OMNIWP_otp_sent`, `OMNIWP_otp_placeholders`, `OMNIWP_webhook_args`, `OMNIWP_otp_email`, `OMNIWP_synthetic_email`, `OMNIWP_phone_is_valid`, `OMNIWP_default_role`, `OMNIWP_post_login_redirect`, `OMNIWP_check_otp_send`, `OMNIWP_reset_reveal_unknown`, `OMNIWP_trust_proxy_headers`, `OMNIWP_missing_profile_fields`, `OMNIWP_step_url`, `OMNIWP_locate_template`.

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

Kết quả: `data/provinces.php`, `data/wards/{mã tỉnh}.php`.

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
| Mã phường/xã | `OmniWP_ward_code` (user), `_OmniWP_ward_code` (đơn hàng) |
| Số nhà, tên đường | `billing_address_1` |

Thẻ **"Địa chỉ nhận hàng"** ghi cả bốn giá trị này sang bộ `shipping_*` tương
ứng (`shipping_state`, `shipping_city`, `shipping_address_1`,
`OmniWP_shipping_ward_code`), để cái tên trên thẻ đúng với thứ nó thực sự
ghi. Chỉ phía `billing_*` được **đọc** lại — bên `shipping_*` là bản sao, không
phải nguồn thứ hai. Đổi lại: khách nào đang cố tình để địa chỉ giao khác địa chỉ
thanh toán sẽ bị ghi đè ở lần lưu tiếp theo trên thẻ này; đó là cái giá của "một
địa chỉ", được ghi ra chứ không giấu đi.

Server **luôn tra tên từ mã**, không bao giờ tin tên do trình duyệt gửi lên. Ghép một phường/xã vào tỉnh không phải của nó sẽ bị từ chối ở cả trang hồ sơ lẫn checkout.

### Ô tìm nhanh

Gõ tên phường/xã bất kỳ (có dấu hoặc không — `cau giay` cũng ra `Cầu Giấy`) để tự điền cả hai ô, bỏ qua bước chọn tỉnh. Tắt được ở Cài đặt.

### Hook

```php
// Sau khi địa chỉ được lưu cho một user.
add_action( 'OMNIWP_address_saved', function ( $user_id, $address ) {
    error_log( OmniWP\Address\AddressFields::format( $address ) );
}, 10, 2 );
```

---

## Tuỳ biến giao diện

Copy file từ `templates/` sang `yourtheme/omniwp/` và sửa. Màn hình đăng nhập/đăng ký là **`form-auth.php`** — một template gộp cả hai:

```
wp-content/themes/your-theme/omniwp/form-auth.php
```

Với hai template WooCommerce, đường dẫn theo chuẩn của Woo:

```
wp-content/themes/your-theme/woocommerce/myaccount/form-login.php
wp-content/themes/your-theme/woocommerce/myaccount/form-edit-account.php
```

---

## REST API

Namespace `omniwp/v1`. Mọi endpoint dùng `POST` và cần header `X-WP-Nonce` (nonce `wp_rest`).

> **Nonce không phải biện pháp chống bot.** Với khách chưa đăng nhập, WordPress cấp cùng một `wp_rest` nonce cho **tất cả** trong 12–24 giờ, nên bot lấy một lần dùng cả ngày. Nó chống CSRF, chỉ vậy. Thứ thực sự chặn lạm dụng là các hạn mức ở tab **Chống lạm dụng** — chúng nằm trong `RateLimiter`, nên áp dụng cho cả REST lẫn form.
>
> Client trình duyệt còn gửi kèm `OMNIWP_ts` (timestamp có chữ ký) và ô honeypot `OMNIWP_website`. Client không dùng cookie (app native) có thể **bỏ qua cả hai** — server chỉ kiểm khi chúng có mặt.

| Endpoint | Tham số |
|---|---|
| `/register` | `identity`, `password`, `full_name`, `dob`, `gender`, `terms` |
| `/verify` | `code` (và `token` + `purpose` nếu client không dùng cookie) |
| `/resend` | — |
| `/login` | `identity`, `password`, `redirect_to` |
| `/forgot` | `identity` |
| `/reset` | `grant`, `password`, `password_confirm` |
| `/contact/start` | `type` (`phone` hoặc `email`), `value`; yêu cầu user đã đăng nhập |
| `/contact/verify` | `type`, `token`, `code`; chỉ cập nhật contact sau OTP đúng |
| `/contact/resend` | `token`; yêu cầu user đã đăng nhập |
| `/identify` | `identity` — bước 1 dùng chung; trả về bước kế tiếp (`password` hay `otp`) |
| `/step` | `step`, `page`, `redirect_to` — trả **HTML** của một bước, cho hộp đăng nhập |

`/step` là ngoại lệ duy nhất về nonce, và có lý do: `wp_localize_script()` ghi
nonce vào HTML của trang, nên trên site có cache toàn trang, script nằm ở mọi
trang sẽ cầm một nonce đã cũ. Thay vào đó `GET` chỉ render form công khai và
không đổi gì, còn `POST` mang theo nonce + timestamp + honeypot của chính form
vừa được render vài giây trước — tươi hơn cái nó từ chối.

---

## Google Login

Provider mặc định tắt. Cách cấu hình thông thường:

1. Vào **Smart Login → Đăng nhập nhanh**.
2. Mở thẻ **Google Login**.
3. Tab **Thiết lập** có chỗ nhập Client/App ID, Secret và Callback URL cần sao chép.
4. Tab **Hướng dẫn** trình bày các bước khai báo ứng dụng ngay trong WordPress.
5. Bật provider và bấm **Lưu thay đổi**.

Secret nhập qua Settings được mã hóa trước khi lưu và không bao giờ được hiển thị lại trong HTML. Để trống ô Secret khi lưu sẽ giữ nguyên giá trị cũ; muốn xóa phải chọn rõ tùy chọn xóa.

Với deployment được quản lý tập trung, có thể dùng các constant hoặc biến môi trường dưới đây. Các giá trị này luôn được ưu tiên hơn Settings:

```php
define( 'OMNIWP_GOOGLE_CLIENT_ID', '...' );
define( 'OMNIWP_GOOGLE_CLIENT_SECRET', '...' );
define( 'OMNIWP_GOOGLE_REDIRECT_URI', 'https://example.com/wp-admin/admin-post.php?action=OMNIWP_provider_callback&provider=google' );
```

Google ID token được kiểm tra chữ ký bằng public certificate, sau đó kiểm tra issuer, audience, expiry và nonce. Provider nào không cung cấp email verified thì plugin tạo tài khoản provider-only và đưa user tới hồ sơ để bổ sung contact.

Provider đã xác thực không cần OTP bổ sung. Mọi phone/email do user tự nhập hoặc thay đổi trong trang hồ sơ vẫn phải hoàn tất OTP trước khi dữ liệu chính được cập nhật.

Zalo Login đã được gỡ khỏi plugin — Zalo không cấp email cho user access token, nên mọi lần đăng nhập Zalo đều sinh một tài khoản riêng thay vì tìm thấy tài khoản sẵn có. Zalo OA/ZNS là chức năng khác và không liên quan: nếu cần gửi OTP qua đó, nó phải được thêm như một OTP Channel riêng.

---

## Xác minh chống robot (captcha)

Mặc định tắt. Bật ở tab **Chống lạm dụng → Xác minh chống robot**: chọn Cloudflare Turnstile hoặc hCaptcha, điền Site key và Secret key.

**Chế độ mặc định là `Chỉ khi site đang bị ép`**, và đó là điểm thiết kế chứ không phải sự dè dặt. Trần gửi toàn site đã chặn được thiệt hại tối đa rồi, nên một thử thách bắt mọi khách vượt qua mỗi ngày mua thêm rất ít mà tốn tỉ lệ chuyển đổi mỗi ngày. Thử thách chỉ hiện khi:

- ngân sách giờ đã tiêu quá **một nửa**, hoặc
- kill switch đang bật, hoặc
- kênh gửi đang bị ngắt mạch, hoặc
- chính IP đó đã dùng quá nửa hạn mức tra cứu định danh

Ngày thường khách **không thấy gì, và trình duyệt cũng không tải script của bên thứ ba** — một captcha vẫn tốn một request và một dấu vết riêng tư kể cả khi không ai nhìn tới nó.

Secret được mã hoá trước khi lưu (AES-256-GCM, khoá dẫn xuất từ salt của chính site) và **không bao giờ hiển thị lại**. Để trống ô khi lưu nghĩa là giữ nguyên; muốn xoá phải tick ô xoá.

---

## Site đứng sau Cloudflare hay proxy

**Bắt buộc đọc nếu site của bạn dùng CDN.** Mặc định plugin chỉ tin `REMOTE_ADDR` — địa chỉ duy nhất client không giả mạo được. Sau Cloudflare, `REMOTE_ADDR` là IP máy chủ biên của Cloudflare, nên **mọi khách bị tính chung một địa chỉ**: giới hạn theo IP vừa chặn oan người thật vừa không chặn được kẻ tấn công, và nhật ký ghi lại IP vô dụng cho việc điều tra.

Sửa ở tab **Chống lạm dụng → Proxy và địa chỉ IP**: bật *Site đứng sau proxy tin cậy* **và** dán dải IP của proxy (Cloudflare công bố tại `https://www.cloudflare.com/ips/`).

Cần cả hai. Bật cờ mà không khai dải thì plugin không tin header của ai cả — đó là chủ ý, không phải thiếu sót: nếu chỉ cần một cái cờ, kẻ tấn công tìm được IP gốc của server sẽ đi thẳng vào đó và tự khai IP mới cho từng request, làm bay sạch mọi giới hạn theo IP. **Header chỉ đáng tin khi máy gửi nó đáng tin.** Màn hình Tổng quan báo đỏ nếu bạn bật cờ mà quên khai dải.

Plugin **không kèm sẵn** danh sách IP Cloudflare: một danh sách cứng sẽ lạc hậu âm thầm, và lúc đó nó thành lỗ hổng chứ không còn là biện pháp bảo vệ.

Với deployment quản lý tập trung, dùng filter thay cho Settings:

```php
add_filter( 'OMNIWP_trust_proxy_headers', '__return_true' );
add_filter( 'OMNIWP_trusted_proxy_cidrs', fn() => array( '173.245.48.0/20', '2400:cb00::/32' ) );
```

Cả hai đều cần. Trước đây `OMNIWP_trust_proxy_headers` một mình là đủ; nay không còn, vì một lối thoát hiểm mở lại đúng lỗ hổng thì không phải lối thoát hiểm.

---

## Bảo mật

- Mã OTP sinh bằng `random_int()`, chỉ lưu dạng HMAC-SHA256, so sánh bằng `hash_equals()`.
- Bước xác thực dùng token ngẫu nhiên 64 ký tự trong cookie HttpOnly — số điện thoại không bao giờ đi qua form, nên không thể đổi số đích giữa chừng.
- Mật khẩu không bao giờ tồn tại ở dạng plaintext trong CSDL, kể cả trong bản ghi OTP tạm.
- Rate limit 4 tầng: cooldown giữa 2 lần gửi, giới hạn theo số điện thoại/giờ, giới hạn theo IP/giờ, và **trần toàn site theo giờ/ngày**. Ba tầng đầu đều tính theo *một* số hoặc *một* IP — tức hai thứ kẻ tấn công xoay vòng được; tầng thứ tư là tầng duy nhất một botnet không đi vòng qua được. Chạm trần thì việc gửi mã tự tạm dừng và admin nhận email.
- **Chỉ gửi tới mã quốc gia đã cho phép** (mặc định: chỉ mã mặc định). Trước đây mọi mã ngoài `84` chỉ bị kiểm tra độ dài 8–15 chữ số, nghĩa là mã xác thực có thể bị nhắm tới đầu số premium ở nước mà kẻ tấn công ăn chia doanh thu với nhà mạng — kiểu lạm dụng gọi là *SMS pumping*, và nó tiêu tiền thật của bạn.
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

**Email ảo.** Tài khoản đăng ký bằng SĐT nhận địa chỉ dạng `ow_9f2c…@phone.invalid`. Đuôi `.invalid` được RFC 2606 dành riêng nên không bao giờ phân giải được — đó là chủ ý.

Phần trước dấu `@` là mã nội bộ của tài khoản, **không phải số điện thoại**. Hai lý do: WordPress phân giải `user_email` ở `authenticate` priority 20 nên một địa chỉ suy diễn được từ số điện thoại sẽ là một identifier gõ được, đi vòng qua tầng định danh; và nó sẽ không bao giờ được cập nhật khi người dùng đổi số, khiến số cũ vẫn với tới được tài khoản qua đường email. Tuỳ chọn *Chặn email ảo* (bật mặc định) sẽ loại các địa chỉ này khỏi mọi email gửi đi, tránh bounce làm hỏng uy tín domain. **Đừng tắt nó** trừ khi bạn có cách xử lý khác.

**Chi phí SMS.** Với cấu hình mặc định, mỗi người dùng chỉ tốn 1 tin nhắn trọn đời (lúc đăng ký). Bật *OTP cho thiết bị lạ* sẽ làm chi phí tăng đáng kể.

**Chế độ DEV** hiển thị mã OTP ngay trên màn hình. Nó chỉ hoạt động khi bật đủ cả ba: tuỳ chọn trong Settings, hằng số `WP_DEBUG`, và `wp_get_environment_type()` khác `production`. Bật nhầm trên site thật sẽ không có tác dụng.
