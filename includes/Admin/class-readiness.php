<?php
/**
 * Whether this site can actually run the plugin, as data.
 *
 * A default install cannot. `identity.mode` is phone-only and `sms.enabled` is
 * off, so the very first visitor to press Đăng ký gets "Kênh SMS chưa được cấu
 * hình. Liên hệ quản trị viên." Nothing in the admin said so: `email.enabled`
 * defaults to on, which reads like a working channel right up until you notice
 * it can never reach a phone number.
 *
 * The facts needed to say this were all present and all scattered — the address
 * dataset warning sat mid-way down one tab, the provider state on another, the
 * table status at the bottom of a third. None of them answered the only question
 * an administrator has after installing something: is it working yet.
 *
 * Returns structures, not markup, so the screen decides how to show them and the
 * tests can assert on them.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin;

use SmartLogin\Address\AddressRepository;
use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Installer;
use SmartLogin\OTP\Transports\MailTransport;
use SmartLogin\OTP\Transports\WebhookTransport;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class Readiness {

	/** Blocking: the plugin cannot do its job. */
	const FAIL = 'fail';

	/** Works, but something will bite later. */
	const WARN = 'warn';

	/** Working. */
	const OK = 'ok';

	/** Deliberately not in use; not a problem. */
	const OFF = 'off';

	/**
	 * @return array<int,array{key:string,label:string,status:string,detail:string,action:string,action_label:string}>
	 */
	public function checks(): array {
		$checks = array(
			$this->identity(),
			$this->delivery(),
			$this->budget(),
			$this->proxy(),
			$this->form_placement(),
			$this->address_data(),
			$this->providers(),
			$this->tables(),
			$this->dev_mode(),
		);

		/**
		 * Add or adjust readiness checks.
		 *
		 * @param array $checks
		 */
		return (array) apply_filters( 'smart_login_readiness_checks', $checks );
	}

	/**
	 * True when nothing is blocking. What the screen leads with.
	 */
	public function is_ready(): bool {
		foreach ( $this->checks() as $check ) {
			if ( self::FAIL === $check['status'] ) {
				return false;
			}
		}

		return true;
	}

	// -----------------------------------------------------------------

	private function identity(): array {
		$modes = array(
			'phone_only' => __( 'Chỉ số điện thoại', 'smart-login' ),
			'email_only' => __( 'Chỉ email', 'smart-login' ),
			'both'       => __( 'Số điện thoại hoặc email', 'smart-login' ),
		);
		$mode  = (string) Settings::get( 'identity.mode', 'phone_only' );
		$codes = \SmartLogin\Identity\Phone::allowed_codes();

		// Not wrong, but it is the configuration that carries the SMS-pumping
		// exposure, so the operator should have arrived at it on purpose.
		if ( Settings::phone_enabled() && count( $codes ) > 5 ) {
			return $this->check(
				'identity',
				__( 'Định danh', 'smart-login' ),
				self::WARN,
				sprintf(
					/* translators: 1: identity mode, 2: number of country codes. */
					__( '%1$s. Đang chấp nhận %2$d mã quốc gia — mỗi mã mở thêm là một đường để đốt tin nhắn.', 'smart-login' ),
					$modes[ $mode ] ?? $mode,
					count( $codes )
				),
				'auth',
				__( 'Xem danh sách', 'smart-login' )
			);
		}

		return $this->check(
			'identity',
			__( 'Định danh', 'smart-login' ),
			self::OK,
			Settings::phone_enabled()
				? sprintf(
					/* translators: 1: identity mode, 2: comma-separated country codes. */
					__( '%1$s. Mã quốc gia: %2$s.', 'smart-login' ),
					$modes[ $mode ] ?? $mode,
					'+' . implode( ', +', $codes )
				)
				: ( $modes[ $mode ] ?? $mode ),
			'auth'
		);
	}

	/**
	 * The check this class was written for: can a code actually reach the kind of
	 * identifier this site accepts?
	 */
	private function delivery(): array {
		$broken = array();

		if ( Settings::phone_enabled() && ! ( new WebhookTransport() )->is_available() ) {
			$broken[] = __( 'SMS', 'smart-login' );
		}

		if ( Settings::email_enabled() && ! ( new MailTransport() )->is_available() ) {
			$broken[] = __( 'Email', 'smart-login' );
		}

		if ( ! $broken ) {
			return $this->check(
				'delivery',
				__( 'Kênh gửi mã', 'smart-login' ),
				self::OK,
				__( 'Đã sẵn sàng cho mọi hình thức định danh đang bật.', 'smart-login' ),
				'delivery'
			);
		}

		return $this->check(
			'delivery',
			__( 'Kênh gửi mã', 'smart-login' ),
			self::FAIL,
			sprintf(
				/* translators: %s: comma-separated transport names. */
				__( 'Chưa cấu hình: %s. Người dùng sẽ không nhận được mã xác thực.', 'smart-login' ),
				implode( ', ', $broken )
			),
			'delivery',
			__( 'Cấu hình ngay', 'smart-login' )
		);
	}

	/**
	 * A configured plugin with no form on the site is still a plugin nobody can
	 * use, and that was never reported anywhere.
	 */
	/**
	 * The site-wide send ceiling, and whether it has tripped.
	 *
	 * A halt is reported as WARN rather than FAIL on purpose: the plugin is
	 * working exactly as configured, and calling that "broken" would train the
	 * reader to ignore the row. What it must never do is stay silent — a site
	 * that has quietly stopped sending codes looks identical to a site whose
	 * gateway died.
	 */
	private function budget(): array {
		$limiter   = new \SmartLogin\Security\RateLimiter();
		$remaining = $limiter->halted_for();

		if ( $remaining > 0 ) {
			return $this->check(
				'budget',
				__( 'Trần gửi toàn site', 'smart-login' ),
				self::WARN,
				sprintf(
					/* translators: %d: minutes remaining. */
					__( 'Đã chạm trần, đang tạm dừng gửi mã. Tự mở lại sau %d phút.', 'smart-login' ),
					max( 1, (int) ceil( $remaining / MINUTE_IN_SECONDS ) )
				),
				'security',
				__( 'Xem trần', 'smart-login' )
			);
		}

		$hour = Settings::get_int( 'security.max_per_site_hour', 100 );
		$day  = Settings::get_int( 'security.max_per_site_day', 500 );

		if ( $hour <= 0 && $day <= 0 ) {
			return $this->check(
				'budget',
				__( 'Trần gửi toàn site', 'smart-login' ),
				self::WARN,
				__( 'Không có trần nào. Mọi giới hạn còn lại đều tính theo một số điện thoại hoặc một IP, nên không có gì chặn được kẻ tấn công đổi cả hai.', 'smart-login' ),
				'security',
				__( 'Đặt trần', 'smart-login' )
			);
		}

		return $this->check(
			'budget',
			__( 'Trần gửi toàn site', 'smart-login' ),
			self::OK,
			sprintf(
				/* translators: 1: hourly ceiling, 2: daily ceiling. */
				__( '%1$s mã/giờ, %2$s mã/ngày.', 'smart-login' ),
				$hour > 0 ? (string) $hour : __( 'không giới hạn', 'smart-login' ),
				$day > 0 ? (string) $day : __( 'không giới hạn', 'smart-login' )
			),
			'security'
		);
	}

	/**
	 * Whether the plugin is seeing the visitor's address or the CDN's.
	 *
	 * This row exists because the control it describes had no interface at all:
	 * proxy handling was a filter mentioned once at the bottom of the README, so
	 * a site behind Cloudflare counted every visitor against one edge address and
	 * nothing said so. Every per-IP limit in the plugin was quietly measuring the
	 * wrong thing.
	 */
	private function proxy(): array {
		$trusting = Settings::is_on( 'security.trust_proxy' );
		$cidrs    = \SmartLogin\Security\Client::trusted_cidrs();

		if ( $trusting && ! $cidrs ) {
			return $this->check(
				'proxy',
				__( 'Proxy và địa chỉ IP', 'smart-login' ),
				self::FAIL,
				__( 'Đang bật tin proxy nhưng chưa khai dải IP nào, nên plugin không tin header của ai cả. Khai dải IP của proxy để giới hạn theo IP hoạt động đúng.', 'smart-login' ),
				'security',
				__( 'Khai dải IP', 'smart-login' )
			);
		}

		// A /0 accepts the entire address space, which is the spoofable
		// configuration this control exists to prevent, arriving via the box
		// meant to prevent it.
		foreach ( $cidrs as $cidr ) {
			if ( 1 === preg_match( '#/0\s*$#', $cidr ) ) {
				return $this->check(
					'proxy',
					__( 'Proxy và địa chỉ IP', 'smart-login' ),
					self::FAIL,
					sprintf(
						/* translators: %s: the offending CIDR. */
						__( 'Dải %s nhận mọi địa chỉ, nên bất kỳ ai cũng tự khai được IP của mình. Hãy khai đúng dải của proxy.', 'smart-login' ),
						$cidr
					),
					'security',
					__( 'Sửa dải IP', 'smart-login' )
				);
			}
		}

		if ( ! $trusting && \SmartLogin\Security\Client::looks_proxied() ) {
			return $this->check(
				'proxy',
				__( 'Proxy và địa chỉ IP', 'smart-login' ),
				self::WARN,
				__( 'Request này đến qua Cloudflare nhưng plugin đang bỏ qua header proxy, nên mọi khách bị tính chung một địa chỉ. Giới hạn theo IP vì thế vừa chặn oan người thật vừa không chặn được kẻ tấn công.', 'smart-login' ),
				'security',
				__( 'Cấu hình proxy', 'smart-login' )
			);
		}

		if ( $trusting ) {
			return $this->check(
				'proxy',
				__( 'Proxy và địa chỉ IP', 'smart-login' ),
				self::OK,
				sprintf(
					/* translators: %d: number of trusted ranges. */
					_n( 'Tin header proxy từ %d dải đã khai.', 'Tin header proxy từ %d dải đã khai.', count( $cidrs ), 'smart-login' ),
					count( $cidrs )
				),
				'security'
			);
		}

		return $this->check(
			'proxy',
			__( 'Proxy và địa chỉ IP', 'smart-login' ),
			self::OK,
			__( 'Dùng địa chỉ kết nối trực tiếp. Đúng khi site không đứng sau proxy nào.', 'smart-login' ),
			'security'
		);
	}

	private function form_placement(): array {
		if ( \SmartLogin\Plugin::woocommerce_active() && Settings::is_on( 'woo.replace_login_form' ) ) {
			return $this->check(
				'form',
				__( 'Form đăng nhập', 'smart-login' ),
				self::OK,
				__( 'Đang thay form My Account của WooCommerce.', 'smart-login' ),
				'profile'
			);
		}

		if ( $this->shortcode_page_id() > 0 ) {
			return $this->check(
				'form',
				__( 'Form đăng nhập', 'smart-login' ),
				self::OK,
				__( 'Đã đặt shortcode trên một trang.', 'smart-login' ),
				'profile'
			);
		}

		return $this->check(
			'form',
			__( 'Form đăng nhập', 'smart-login' ),
			self::FAIL,
			__( 'Chưa có trang nào chứa [smart_auth], và tích hợp My Account đang tắt.', 'smart-login' ),
			'profile',
			__( 'Bật tích hợp WooCommerce', 'smart-login' )
		);
	}

	/**
	 * One LIKE against post_content, cached: this screen is rare but the query is
	 * unindexed, so it should not run on every load.
	 */
	private function shortcode_page_id(): int {
		$cached = get_transient( 'smart_login_form_page' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
				 LIMIT 1",
				'%[smart_auth%',
				'%[smart_login%',
				'%[smart_register%'
			)
		);

		set_transient( 'smart_login_form_page', $id, 5 * MINUTE_IN_SECONDS );

		return $id;
	}

	private function address_data(): array {
		if ( ! Settings::is_on( 'address.enabled' ) ) {
			return $this->check(
				'address',
				__( 'Dữ liệu hành chính', 'smart-login' ),
				self::OFF,
				__( 'Bộ chọn địa chỉ đang tắt.', 'smart-login' ),
				'profile'
			);
		}

		if ( ! AddressRepository::is_dataset_installed() ) {
			return $this->check(
				'address',
				__( 'Dữ liệu hành chính', 'smart-login' ),
				self::FAIL,
				__( 'Bộ chọn địa chỉ đang bật nhưng chưa có dữ liệu, nên sẽ không hiển thị gì.', 'smart-login' ),
				'profile',
				__( 'Xem hướng dẫn', 'smart-login' )
			);
		}

		return $this->check(
			'address',
			__( 'Dữ liệu hành chính', 'smart-login' ),
			self::OK,
			sprintf(
				/* translators: %d: province count. */
				__( 'Đã cài %d tỉnh/thành.', 'smart-login' ),
				count( AddressRepository::provinces() )
			),
			'profile'
		);
	}

	private function providers(): array {
		$available = ( new ProviderRegistry() )->available();

		if ( ! $available ) {
			return $this->check(
				'providers',
				__( 'Đăng nhập nhanh', 'smart-login' ),
				self::OFF,
				__( 'Chưa bật Google hoặc Zalo. Không bắt buộc.', 'smart-login' ),
				'providers'
			);
		}

		return $this->check(
			'providers',
			__( 'Đăng nhập nhanh', 'smart-login' ),
			self::OK,
			implode(
				', ',
				array_map(
					static fn( $provider ): string => $provider->label(),
					$available
				)
			),
			'providers'
		);
	}

	private function tables(): array {
		global $wpdb;

		$missing = array();

		foreach ( array( Installer::otp_table(), Installer::audit_table(), Installer::identities_table() ) as $table ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
				$missing[] = $table;
			}
		}

		if ( ! $missing ) {
			return $this->check( 'tables', __( 'Bảng dữ liệu', 'smart-login' ), self::OK, __( 'Đầy đủ.', 'smart-login' ), 'advanced' );
		}

		return $this->check(
			'tables',
			__( 'Bảng dữ liệu', 'smart-login' ),
			self::FAIL,
			sprintf(
				/* translators: %s: comma-separated table names. */
				__( 'Thiếu: %s. Hãy tắt rồi bật lại plugin.', 'smart-login' ),
				implode( ', ', $missing )
			),
			'advanced'
		);
	}

	private function dev_mode(): array {
		if ( ! ( new \SmartLogin\OTP\OtpService() )->dev_mode_active() ) {
			return $this->check( 'dev', __( 'Chế độ DEV', 'smart-login' ), self::OFF, __( 'Đang tắt.', 'smart-login' ), 'advanced' );
		}

		return $this->check(
			'dev',
			__( 'Chế độ DEV', 'smart-login' ),
			self::WARN,
			__( 'Mã OTP đang hiển thị thẳng trên màn hình. Tắt trước khi chạy thật.', 'smart-login' ),
			'advanced',
			__( 'Tắt ngay', 'smart-login' )
		);
	}

	private function check( string $key, string $label, string $status, string $detail, string $tab, string $action_label = '' ): array {
		return array(
			'key'          => $key,
			'label'        => $label,
			'status'       => $status,
			'detail'       => $detail,
			'action'       => admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . $tab ),
			'action_label' => '' !== $action_label ? $action_label : __( 'Mở cài đặt', 'smart-login' ),
		);
	}
}
