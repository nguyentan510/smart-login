# Phase 23 — Smart Menu (Specification)

Normative spec cho Phase 23. Trạng thái theo dõi tại [`refactor-plan.md`](refactor-plan.md); các bài viết hướng dẫn từng sub-phase tại [`smart-menu/`](smart-menu/).

Yêu cầu triển khai: **"Smart Menu" — Hệ thống triển khai nhanh trên Menu với danh sách mang tính chất đặc trưng của dự án Smart Login.**

---

## 1. Tổng quan & Mục tiêu Kiến trúc

Metabox **Smart Menu** hiển thị tại trang Quản trị WordPress Menu (`wp-admin/nav-menus.php`), cho phép người quản trị web nhanh chóng chọn và thêm các mục điều hướng đặc trưng của Smart Login vào bất kỳ WordPress Navigation Menu nào.

### Nguyên tắc thiết kế (Design Principles)
1. **100% WordPress Core Native**: Sử dụng cơ chế Custom Links chuẩn (`URL` định dạng `#smart-*`) kết hợp với Nav Menu Item Meta (`_ow_smart_menu_*`). Đảm bảo an toàn 100% khi deactivate plugin và tương thích hoàn toàn với tất cả WordPress Themes.
2. **Dynamic Nonce & Auth State**: Các liên kết đặc thù như **Đăng xuất (Logout)** tự động chèn `wp_logout_url()` kèm nonce bảo mật tại thời điểm render frontend; các liên kết **Auth Switcher** tự động đổi nhãn và đường dẫn theo trạng thái đăng nhập của người dùng.
3. **Phân quyền ẩn/hiện linh hoạt (Visibility Filter)**: Mỗi item có thể cấu hình ẩn/hiện dựa trên trạng thái của khách ghé thăm (*Tất cả / Chỉ khách chưa đăng nhập / Chỉ thành viên đã đăng nhập*).
4. **Tích hợp liền mạch Phase 21**: Hỗ trợ toggle giữa link đơn giản (*Simple Link*) và menu thả xuống phong phú (*Rich Account Dropdown*) kế thừa `:root` design tokens và `IconSet` từ Phase 21.

---

## 2. Danh sách Preset Items đặc trưng (Project-Specific Presets)

Metabox **Smart Menu** cung cấp 5 nhóm Preset items đặc trưng:

| STT | Tên Preset Item | Target URL Anchor | Mặc định Visibility | Hành vi Frontend |
| --- | --- | --- | --- | --- |
| 1 | **Nút Tài khoản [OMNIWP_button]** | `#smart-button` | Tất cả (`everyone`) | **Chưa đăng nhập**: Nút "Đăng nhập" (mở Popup Modal).<br>**Đã đăng nhập**: Tên thành viên kèm menu tài khoản đổ xuống. |
| 2 | **Smart Login Popup** | `#omniwp` | Chỉ khách (`guest`) | Gán class `sl-login-trigger` / mở Dialog Modal Đăng nhập/Đăng ký khi click. |
| 3 | **Auth Switcher linh hoạt** | `#smart-auth-switcher` | Tất cả (`everyone`) | **Chưa đăng nhập**: Hiển thị "Đăng nhập / Đăng ký" (mở Popup).<br>**Đã đăng nhập**: Hiển thị "Tài khoản [Tên/SĐT]" kèm dropdown menu. |
| 4 | **Đăng xuất (Secure Logout)** | `#smart-logout` | Chỉ thành viên (`logged_in`) | Tự động tạo `wp_logout_url()` kèm nonce bảo mật và redirect. |
| 5 | **Trang Tài khoản (Account Hub)** | `#smart-account` | Chỉ thành viên (`logged_in`) | Dẫn tới trang tài khoản Smart Login hoặc WooCommerce My Account. |
| 6 | **Sub-pages (Hồ sơ/Đơn hàng/Bảo mật)** | `#smart-account-profile`<br>`#smart-account-orders`<br>`#smart-account-security`<br>`#smart-account-providers` | Chỉ thành viên (`logged_in`) | Dẫn trực tiếp tới các tab/mục tương ứng trong Account Card / Woo Account. |


---

## 3. Sub-phases Breakdown

### 23.0 — Guard Rails
Xây dựng bộ kiểm thử tính đúng đắn (`tests/smart-menu/run-smart-menu-tests.php`) bao gồm 10 quy tắc kiến trúc (Guard rules).

### 23.1 — Admin Metabox Registration
Tạo class `SmartMenuMetaBox` đăng ký metabox `"Smart Menu"` trên hook `admin_head-nav-menus.php`, hiển thị giao diện danh sách checkbox các Preset items đặc trưng kèm button **Add to Menu**.

### 23.2 — Menu Item Editor Custom Fields
Tạo class `SmartMenuFields` mở rộng form chỉnh sửa Nav Menu Item trong WP Admin:
- Thêm dropdown **Hiển thị (Visibility)**: Tất cả / Chỉ khách chưa đăng nhập / Chỉ thành viên đã đăng nhập.
- Thêm checkbox **Chế độ Render**: Simple Link / Rich Account Dropdown (Phase 21).

### 23.3 — Frontend Nav Walker & Dynamic Renderer
Tạo class `SmartMenuRenderer` can thiệp vào `wp_nav_menu_objects` và `wp_setup_nav_menu_item`:
- Render nhãn, URL động (`wp_logout_url()`, account page URL).
- Gán data attributes và CSS classes cho Popup Trigger.

### 23.4 — Visibility Filter & Dynamic Auth Switcher
- Lọc danh sách menu items theo trạng thái `is_user_logged_in()`.
- Chuyển đổi linh hoạt giữa giao diện Đăng nhập và Dropdown Tài khoản đối với item `#smart-auth-switcher`.

### 23.5 — Verification, Documentation & Promotion
- Viết walkthrough, cập nhật `README.md` và kiểm tra toàn bộ test suite.
- Promote test suite từ `spec` thành `required` trong `tests/run-all.php`.
