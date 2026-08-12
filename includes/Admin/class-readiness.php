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
 * @package OmniWP
 */

namespace OmniWP\Admin;

use OmniWP\Address\AddressRepository;
use OmniWP\Auth\Providers\ProviderCredentials;
use OmniWP\Auth\Providers\ProviderRegistry;
use OmniWP\Identity\Channels\MailChannel;
use OmniWP\Identity\Channels\PhoneChannel;
use OmniWP\Installer;
use OmniWP\OTP\Transports\TransportRouter;
use OmniWP\Settings;

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

	/** @var \OmniWP\OTP\OtpRepository */
	private $repo;

	/**
	 * The repository is injectable for the reason RateLimiter's is: the stub
	 * `$wpdb` returns one global whatever the query, so counting by channel and
	 * counting by transport are indistinguishable through it, and a test driven
	 * that way would assert nothing.
	 */
	public function __construct( ?\OmniWP\OTP\OtpRepository $repo = null ) {
		$this->repo = $repo ?? new \OmniWP\OTP\OtpRepository();
	}

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
		return (array) apply_filters( 'OMNIWP_readiness_checks', $checks );
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

	/**
	 * The identity channel the site is configured to accept.
	 */
	private function identity(): array {
		$modes = array(
			'phone_only' => __( 'Chỉ số điện thoại', 'omniwp' ),
			'email_only' => __( 'Chỉ email', 'omniwp' ),
			'both'       => __( 'Số điện thoại hoặc email', 'omniwp' ),
		);
		$mode  = (string) Settings::get( 'identity.mode', 'phone_only' );
		$codes = \OmniWP\Identity\Phone::allowed_codes();

		// Not wrong, but it is the configuration that carries the SMS-pumping
		// exposure, so the operator should have arrived at it on purpose.
		if ( Settings::phone_enabled() && count( $codes ) > 5 ) {
			return $this->check(
				'identity',
				__( 'Định danh', 'omniwp' ),
				self::WARN,
				sprintf(
					/* translators: 1: identity mode, 2: number of country codes. */
					__( '%1$s. Đang chấp nhận %2$d mã quốc gia — mỗi mã mở thêm là một đường để đốt tin nhắn.', 'omniwp' ),
					$modes[ $mode ] ?? $mode,
					count( $codes )
				),
				'auth',
				__( 'Xem danh sách', 'omniwp' )
			);
		}

		return $this->check(
			'identity',
			__( 'Định danh', 'omniwp' ),
			self::OK,
			Settings::phone_enabled()
				? sprintf(
					/* translators: 1: identity mode, 2: comma-separated country codes. */
					__( '%1$s. Mã quốc gia: %2$s.', 'omniwp' ),
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

		// Asks the router which transport actually serves each channel, rather
		// than constructing the two it used to assume. Before 10.1 that
		// assumption was true; after it, a channel pointed at the automation
		// endpoint was checked against a webhook nobody was using.
		$router   = new TransportRouter();
		$channels = array();

		if ( Settings::phone_enabled() ) {
			$channels[ PhoneChannel::ID ] = __( 'Số điện thoại', 'omniwp' );
		}

		if ( Settings::email_enabled() ) {
			$channels[ MailChannel::ID ] = __( 'Email', 'omniwp' );
		}

		foreach ( $channels as $channel => $label ) {
			// Still read through the router's own table rather than by assembling a
			// key from a literal prefix — the reason has not changed, only the
			// table has. Since 20.1 it is a constant, so there is no stored value
			// to fall back from and no cell for a channel to be pointed into.
			$transport = TransportRouter::CHANNEL_TRANSPORT[ $channel ];

			if ( ! $router->is_available( $transport ) ) {
				$broken[] = sprintf(
					/* translators: 1: identity channel, 2: transport id. */
					__( '%1$s (kênh gửi "%2$s")', 'omniwp' ),
					$label,
					$transport
				);
			}
		}

		if ( ! $broken ) {
			$unproven = $this->mail_path_unproven( $channels );

			if ( '' !== $unproven ) {
				return $this->check(
					'delivery',
					__( 'Kênh gửi mã', 'omniwp' ),
					self::WARN,
					$unproven,
					'delivery',
					__( 'Gửi thử', 'omniwp' )
				);
			}

			return $this->check(
				'delivery',
				__( 'Kênh gửi mã', 'omniwp' ),
				self::OK,
				__( 'Đã sẵn sàng cho mọi hình thức định danh đang bật.', 'omniwp' ),
				'delivery'
			);
		}

		return $this->check(
			'delivery',
			__( 'Kênh gửi mã', 'omniwp' ),
			self::FAIL,
			sprintf(
				/* translators: %s: comma-separated transport names. */
				__( 'Chưa cấu hình: %s. Người dùng sẽ không nhận được mã xác thực.', 'omniwp' ),
				implode( ', ', $broken )
			),
			'delivery',
			__( 'Cấu hình ngay', 'omniwp' )
		);
	}

	/**
	 * Why "Đã sẵn sàng" was a claim the email channel could not support.
	 *
	 * `MailTransport::is_available()` reads one checkbox and nothing else, so it
	 * can never report itself unready — and this check used to treat that as
	 * proof. The SMS channel cannot lie the same way because its own check also
	 * requires a URL.
	 *
	 * There is no honest synchronous probe for "can this site send mail":
	 * `wp_mail()` returning true means PHP handed the message to an MTA, which is
	 * not delivery, and finding out costs a real send. So this reports what can
	 * be stood behind — nothing has ever gone out through it, and the host shows
	 * no configured mail path — and says so as a WARN, because the site may well
	 * be fine and a FAIL would cry wolf on every fresh install.
	 *
	 * @param array<string,string> $channels Enabled identity channels.
	 */
	private function mail_path_unproven( array $channels ): string {
		if ( ! isset( $channels[ MailChannel::ID ] ) ) {
			return '';
		}

		// The guard that used to sit here asked whether the email channel was
		// routed somewhere other than wp_mail(), which 20.1 made unaskable: since
		// the routing table went away, email has exactly one transport. The check
		// below is now unconditionally the right one for this channel.

		// An SMTP plugin registers one of these long before this screen renders.
		// MailTransport's own phpmailer_init clamp is added around its send and
		// removed again, so it cannot produce a false negative here.
		if ( has_filter( 'phpmailer_init' ) || has_filter( 'pre_wp_mail' ) || has_filter( 'wp_mail_from' ) ) {
			return '';
		}

		if ( '' !== trim( (string) ini_get( 'sendmail_path' ) ) ) {
			return '';
		}

		if ( $this->repo->count_recent_by_channel( MailChannel::ID, 30 * DAY_IN_SECONDS ) > 0 ) {
			return '';
		}

		return __( 'Kênh email đang bật nhưng chưa từng gửi được mã nào, máy chủ không khai báo đường gửi thư và không thấy plugin SMTP nào. Bật một plugin SMTP hoặc dùng nút Gửi thử để xác nhận trước khi mở đăng ký.', 'omniwp' );
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
		$limiter   = new \OmniWP\Security\RateLimiter();
		$remaining = $limiter->halted_for();

		if ( $remaining > 0 ) {
			return $this->check(
				'budget',
				__( 'Trần gửi toàn site', 'omniwp' ),
				self::WARN,
				sprintf(
					/* translators: %d: minutes remaining. */
					__( 'Đã chạm trần, đang tạm dừng gửi mã. Tự mở lại sau %d phút.', 'omniwp' ),
					max( 1, (int) ceil( $remaining / MINUTE_IN_SECONDS ) )
				),
				'security',
				__( 'Xem trần', 'omniwp' )
			);
		}

		$hour = Settings::get_int( 'security.max_per_site_hour', 100 );
		$day  = Settings::get_int( 'security.max_per_site_day', 500 );

		if ( $hour <= 0 && $day <= 0 ) {
			return $this->check(
				'budget',
				__( 'Trần gửi toàn site', 'omniwp' ),
				self::WARN,
				__( 'Không có trần nào. Mọi giới hạn còn lại đều tính theo một số điện thoại hoặc một IP, nên không có gì chặn được kẻ tấn công đổi cả hai.', 'omniwp' ),
				'security',
				__( 'Đặt trần', 'omniwp' )
			);
		}

		// Consumption, not just configuration. The ceilings shipped with defaults
		// that were chosen for plausibility rather than measured from any real
		// site's traffic, so an operator needs to see how close they run before
		// the kill switch introduces them to the number.
		$used = $this->repo->count_recent_all( HOUR_IN_SECONDS );
		// By channel, not by transport. Counting `transport = 'sms'` read zero
		// the moment a site routed phone at the automation endpoint — while the
		// automation kept sending real messages and the bill kept arriving.
		$sms  = $this->repo->count_recent_by_channel( PhoneChannel::ID, DAY_IN_SECONDS );
		$cost = Settings::get_int( 'otp.sms_unit_cost', 0 );

		$detail = sprintf(
			/* translators: 1: codes sent this hour, 2: hourly ceiling, 3: daily ceiling. */
			__( 'Giờ này đã gửi %1$d. Trần %2$s mã/giờ, %3$s mã/ngày.', 'omniwp' ),
			$used,
			$hour > 0 ? (string) $hour : __( 'không giới hạn', 'omniwp' ),
			$day > 0 ? (string) $day : __( 'không giới hạn', 'omniwp' )
		);

		if ( $cost > 0 && $sms > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: 1: SMS sent today, 2: estimated cost. */
				__( 'Hôm nay %1$d tin SMS, ước tính %2$s đ.', 'omniwp' ),
				$sms,
				number_format_i18n( $sms * $cost )
			);
		}

		// Approaching the ceiling is worth saying before it trips, not after.
		if ( $hour > 0 && $used >= (int) ceil( $hour * 0.8 ) ) {
			return $this->check(
				'budget',
				__( 'Trần gửi toàn site', 'omniwp' ),
				self::WARN,
				$detail . ' ' . __( 'Đang sát trần — hoặc site đang bị lạm dụng, hoặc trần đặt thấp hơn nhu cầu thật.', 'omniwp' ),
				'security',
				__( 'Xem trần', 'omniwp' )
			);
		}

		return $this->check(
			'budget',
			__( 'Trần gửi toàn site', 'omniwp' ),
			self::OK,
			$detail,
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
		$cidrs    = \OmniWP\Security\Client::trusted_cidrs();

		if ( $trusting && ! $cidrs ) {
			return $this->check(
				'proxy',
				__( 'Proxy và địa chỉ IP', 'omniwp' ),
				self::FAIL,
				__( 'Đang bật tin proxy nhưng chưa khai dải IP nào, nên plugin không tin header của ai cả. Khai dải IP của proxy để giới hạn theo IP hoạt động đúng.', 'omniwp' ),
				'security',
				__( 'Khai dải IP', 'omniwp' )
			);
		}

		// A /0 accepts the entire address space, which is the spoofable
		// configuration this control exists to prevent, arriving via the box
		// meant to prevent it.
		foreach ( $cidrs as $cidr ) {
			if ( 1 === preg_match( '#/0\s*$#', $cidr ) ) {
				return $this->check(
					'proxy',
					__( 'Proxy và địa chỉ IP', 'omniwp' ),
					self::FAIL,
					sprintf(
						/* translators: %s: the offending CIDR. */
						__( 'Dải %s nhận mọi địa chỉ, nên bất kỳ ai cũng tự khai được IP của mình. Hãy khai đúng dải của proxy.', 'omniwp' ),
						$cidr
					),
					'security',
					__( 'Sửa dải IP', 'omniwp' )
				);
			}
		}

		if ( ! $trusting && \OmniWP\Security\Client::looks_proxied() ) {
			// Worse than merely inaccurate when a per-IP ceiling is switched on:
			// every visitor then shares one counter, so the control that exists to
			// stop an attacker locks out the customers instead. Named on screen
			// rather than left to the sub-phase ordering.
			$ip_ceilings = Settings::get_int( 'security.max_login_failures_per_ip_hour', 30 ) > 0
				|| Settings::get_int( 'security.max_identify_per_ip_hour', 30 ) > 0
				|| Settings::get_int( 'otp.max_per_ip_hour', 10 ) > 0;

			return $this->check(
				'proxy',
				__( 'Proxy và địa chỉ IP', 'omniwp' ),
				self::WARN,
				$ip_ceilings
					? __( 'Request này đến qua Cloudflare nhưng plugin đang bỏ qua header proxy, nên mọi khách bị tính chung một địa chỉ. Đang có giới hạn theo IP đang bật, nghĩa là chúng sẽ khoá nhầm khách thật trước khi chạm được kẻ tấn công.', 'omniwp' )
					: __( 'Request này đến qua Cloudflare nhưng plugin đang bỏ qua header proxy, nên nhật ký ghi địa chỉ của CDN thay vì của khách.', 'omniwp' ),
				'security',
				__( 'Cấu hình proxy', 'omniwp' )
			);
		}

		if ( $trusting ) {
			return $this->check(
				'proxy',
				__( 'Proxy và địa chỉ IP', 'omniwp' ),
				self::OK,
				sprintf(
					/* translators: %d: number of trusted ranges. */
					_n( 'Tin header proxy từ %d dải đã khai.', 'Tin header proxy từ %d dải đã khai.', count( $cidrs ), 'omniwp' ),
					count( $cidrs )
				),
				'security'
			);
		}

		return $this->check(
			'proxy',
			__( 'Proxy và địa chỉ IP', 'omniwp' ),
			self::OK,
			__( 'Dùng địa chỉ kết nối trực tiếp. Đúng khi site không đứng sau proxy nào.', 'omniwp' ),
			'security'
		);
	}

	private function form_placement(): array {
		if ( \OmniWP\Plugin::woocommerce_active() && Settings::is_on( 'woo.replace_login_form' ) ) {
			return $this->check(
				'form',
				__( 'Form đăng nhập', 'omniwp' ),
				self::OK,
				__( 'Đang thay form My Account của WooCommerce.', 'omniwp' ),
				'profile'
			);
		}

		if ( $this->shortcode_page_id() > 0 ) {
			return $this->check(
				'form',
				__( 'Form đăng nhập', 'omniwp' ),
				self::OK,
				__( 'Đã đặt shortcode trên một trang.', 'omniwp' ),
				'profile'
			);
		}

		return $this->check(
			'form',
			__( 'Form đăng nhập', 'omniwp' ),
			self::FAIL,
			__( 'Chưa có trang nào chứa [smart_auth], và tích hợp My Account đang tắt.', 'omniwp' ),
			'profile',
			__( 'Bật tích hợp WooCommerce', 'omniwp' )
		);
	}

	/**
	 * One LIKE against post_content, cached: this screen is rare but the query is
	 * unindexed, so it should not run on every load.
	 */
	private function shortcode_page_id(): int {
		$cached = get_transient( 'OMNIWP_form_page' );

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
				'%[OMNIWP%',
				'%[smart_register%'
			)
		);

		set_transient( 'OMNIWP_form_page', $id, 5 * MINUTE_IN_SECONDS );

		return $id;
	}

	private function address_data(): array {
		if ( ! Settings::is_on( 'address.enabled' ) ) {
			return $this->check(
				'address',
				__( 'Dữ liệu hành chính', 'omniwp' ),
				self::OFF,
				__( 'Bộ chọn địa chỉ đang tắt.', 'omniwp' ),
				'profile'
			);
		}

		if ( ! AddressRepository::is_dataset_installed() ) {
			return $this->check(
				'address',
				__( 'Dữ liệu hành chính', 'omniwp' ),
				self::FAIL,
				__( 'Bộ chọn địa chỉ đang bật nhưng chưa có dữ liệu, nên sẽ không hiển thị gì.', 'omniwp' ),
				'profile',
				__( 'Xem hướng dẫn', 'omniwp' )
			);
		}

		return $this->check(
			'address',
			__( 'Dữ liệu hành chính', 'omniwp' ),
			self::OK,
			sprintf(
				/* translators: %d: province count. */
				__( 'Đã cài %d tỉnh/thành.', 'omniwp' ),
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
				__( 'Đăng nhập nhanh', 'omniwp' ),
				self::OFF,
				__( 'Chưa bật đăng nhập qua Google. Không bắt buộc.', 'omniwp' ),
				'providers'
			);
		}

		/*
		 * A secret that is really the id.
		 *
		 * The save-time rule in Settings::sanitize() refuses this pair, but it
		 * cannot reach a site that already holds one — and one did, silently,
		 * for as long as that provider had been switched on. Every sign-in came
		 * back "invalid secret key" while this screen, whose whole job is to say
		 * what is wrong before a visitor finds out, reported OK.
		 */
		$confused = array();

		foreach ( $available as $provider ) {
			$id     = ProviderCredentials::client_id( $provider->id() );
			$secret = ProviderCredentials::secret( $provider->id() );

			if ( '' !== $id && hash_equals( $id, $secret ) ) {
				$confused[] = $provider->name();
			}
		}

		if ( $confused ) {
			return $this->check(
				'providers',
				__( 'Đăng nhập nhanh', 'omniwp' ),
				self::WARN,
				sprintf(
					/* translators: %s: comma-separated provider names. */
					__( '%s: secret đang trùng với ID ứng dụng, nên mọi lần đăng nhập đều bị từ chối. Hãy dán đúng App Secret Key.', 'omniwp' ),
					implode( ', ', $confused )
				),
				'providers'
			);
		}

		return $this->check(
			'providers',
			__( 'Đăng nhập nhanh', 'omniwp' ),
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
			return $this->check( 'tables', __( 'Bảng dữ liệu', 'omniwp' ), self::OK, __( 'Đầy đủ.', 'omniwp' ), 'advanced' );
		}

		return $this->check(
			'tables',
			__( 'Bảng dữ liệu', 'omniwp' ),
			self::FAIL,
			sprintf(
				/* translators: %s: comma-separated table names. */
				__( 'Thiếu: %s. Hãy tắt rồi bật lại plugin.', 'omniwp' ),
				implode( ', ', $missing )
			),
			'advanced'
		);
	}

	private function dev_mode(): array {
		if ( ! ( new \OmniWP\OTP\OtpService() )->dev_mode_active() ) {
			return $this->check( 'dev', __( 'Chế độ DEV', 'omniwp' ), self::OFF, __( 'Đang tắt.', 'omniwp' ), 'advanced' );
		}

		return $this->check(
			'dev',
			__( 'Chế độ DEV', 'omniwp' ),
			self::WARN,
			__( 'Mã OTP đang hiển thị thẳng trên màn hình. Tắt trước khi chạy thật.', 'omniwp' ),
			'advanced',
			__( 'Tắt ngay', 'omniwp' )
		);
	}

	private function check( string $key, string $label, string $status, string $detail, string $tab, string $action_label = '' ): array {
		return array(
			'key'          => $key,
			'label'        => $label,
			'status'       => $status,
			'detail'       => $detail,
			'action'       => admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . $tab ),
			'action_label' => '' !== $action_label ? $action_label : __( 'Mở cài đặt', 'omniwp' ),
		);
	}
}
