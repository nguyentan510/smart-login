<?php
/**
 * Renders any settings tab by walking the registry.
 *
 * There is no per-tab method here and no list of which keys belong where. The
 * screen asks FieldRegistry which fields carry this tab, groups them by section,
 * and draws them. Tab membership and rendering are therefore the same fact,
 * which is what makes a setting that is claimed by a tab but drawn by nothing
 * impossible to express.
 *
 * Sections that need more than a table of controls — the provider cards, the
 * send-a-test panels, the administrative-unit dataset warning — get that chrome
 * from before_section()/after_section() rather than from the registry, which
 * stays pure data.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Admin\Screens;

use SmartLogin\Address\AddressRepository;
use SmartLogin\Admin\FieldRenderer;
use SmartLogin\Admin\MailMessages;
use SmartLogin\Admin\ProviderCards;
use SmartLogin\Admin\SettingsPage;
use SmartLogin\FieldRegistry;
use SmartLogin\GatewayPresets;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Installer;
use SmartLogin\OTP\Placeholders;
use SmartLogin\OTP\Transports\EventBus;
use SmartLogin\OTP\Transports\TransportRouter;
use SmartLogin\OtpPresets;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsScreen {

	public function render( string $tab ): void {
		$tabs = FieldRegistry::tabs();
		$tab  = isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
		?>
		<div class="wrap smart-login-admin">
			<h1><?php esc_html_e( 'Smart Login', 'smart-login' ); ?></h1>

			<?php SettingsPage::nav( $tab ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( SettingsPage::GROUP );

				/*
				 * Names the tab this save came from. Settings::sanitize() writes
				 * only the fields belonging to it and leaves every other tab
				 * untouched, which is what replaced the old approach of echoing
				 * the entire option back through hidden inputs — gateway
				 * credentials included, on screens that had no reason to hold them.
				 */
				printf(
					'<input type="hidden" name="%s[%s]" value="%s" />',
					esc_attr( Settings::OPTION ),
					esc_attr( Settings::TAB_FIELD ),
					esc_attr( $tab )
				);

				foreach ( FieldRegistry::by_section( $tab ) as $section => $fields ) {
					$this->section( $tab, $section, $fields );
				}

				submit_button( __( 'Lưu thay đổi', 'smart-login' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string              $tab     Tab slug being rendered.
	 * @param string              $section Section slug within it.
	 * @param array<string,array> $fields  The registry rows in this section.
	 */
	private function section( string $tab, string $section, array $fields ): void {
		$labels = FieldRegistry::sections();

		// Drawn by the provider cards, above the grid it governs, since 12.1 —
		// so this screen must not also draw it below. The registry row keeps its
		// section, because the section is still what groups it; only who renders
		// it changed.
		if ( 'linking' === $section && 'providers' === $tab ) {
			return;
		}

		// The message list holds every message, both groups, so the alerts are
		// drawn with the rest rather than under a second heading of their own.
		if ( 'mail_admin' === $section && 'delivery-mail' === $tab ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html( $labels[ $section ] ?? $section ) );

		$this->before_section( $section );

		// The provider cards carry their own credential inputs and callback URLs,
		// so they replace the table rather than sitting beside it.
		if ( 'provider' === $section ) {
			( new ProviderCards() )->render( FieldRegistry::for_tab( $tab ) );
			$this->after_section( $section );
			return;
		}

		// Same shape: a list with one editor open replaces the table, and it
		// needs the whole tab because it draws the alerts from mail_admin too.
		if ( 'templates' === $section ) {
			( new MailMessages() )->render( FieldRegistry::for_tab( $tab ) );
			$this->after_section( $section );
			return;
		}

		$derived = $this->derived_paths( $section );

		echo '<table class="form-table" role="presentation">';

		foreach ( $fields as $path => $field ) {
			if ( isset( $derived[ $path ] ) || ! self::field_applies( $field ) ) {
				continue;
			}

			FieldRenderer::render( $path, $field );
		}

		echo '</table>';

		if ( $derived ) {
			$this->derived_details( $section, array_intersect_key( $fields, $derived ) );
		}

		$this->after_section( $section );
	}

	/**
	 * Whether a field's `show_if` condition holds against the stored settings.
	 *
	 * Declared in the registry beside the field rather than as a branch here, for
	 * the reason the registry exists at all: one array decides the default, the
	 * type, the tab, the control — and now whether the control is relevant.
	 * A field with no condition always applies.
	 *
	 * @param array $field Field spec.
	 */
	private static function field_applies( array $field ): bool {
		foreach ( (array) ( $field['show_if'] ?? array() ) as $path => $expected ) {
			if ( (string) Settings::get( $path, '' ) !== (string) $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Fields a preset is currently producing, which must not be edited directly.
	 *
	 * Empty when the section has no preset or the preset is "Tuỳ chỉnh", in which
	 * case every field renders as a normal control and nothing is derived.
	 *
	 * @return array<string,true>
	 */
	private function derived_paths( string $section ): array {
		if ( 'sms' === $section && ! GatewayPresets::is_custom( (string) Settings::get( 'sms.preset', GatewayPresets::CUSTOM ) ) ) {
			return array_fill_keys(
				array( 'sms.url', 'sms.method', 'sms.content_type', 'sms.headers', 'sms.body', 'sms.success_path', 'sms.success_value' ),
				true
			);
		}

		if ( 'otp' === $section && ! OtpPresets::is_custom( (string) Settings::get( 'otp.preset', 'balanced' ) ) ) {
			return array_fill_keys(
				array( 'otp.length', 'otp.ttl', 'otp.max_attempts', 'otp.resend_cooldown', 'otp.max_per_destination_hour', 'otp.max_per_ip_hour' ),
				true
			);
		}

		return array();
	}

	/**
	 * The derived values, collapsed. Present so a configuration can be checked,
	 * folded away because reading it is not part of setting the plugin up.
	 *
	 * @param string              $section Section slug the fields belong to.
	 * @param array<string,array> $fields  The derived rows, in registry order.
	 */
	private function derived_details( string $section, array $fields ): void {
		$summary = 'otp' === $section
			? __( 'Xem giá trị đang áp dụng', 'smart-login' )
			: __( 'Xem request sẽ được gửi', 'smart-login' );
		?>
		<details class="sl-derived">
			<summary><?php echo esc_html( $summary ); ?></summary>
			<p class="description">
				<?php esc_html_e( 'Các giá trị này do lựa chọn ở trên sinh ra. Chọn “Tuỳ chỉnh” nếu bạn cần tự đặt.', 'smart-login' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<?php
				foreach ( $fields as $path => $field ) {
					$field['readonly'] = true;
					FieldRenderer::render( $path, $field );
				}
				?>
			</table>
		</details>
		<?php
	}

	private function before_section( string $section ): void {
		if ( 'address' === $section ) {
			$this->address_dataset_status();
		}

		if ( 'automation' === $section ) {
			$this->automation_role();
		}

		if ( 'sms' === $section ) {
			$this->channel_status( PhoneChannel::ID, 'sms.enabled', Settings::phone_enabled() );
		}

		if ( 'email' === $section ) {
			$this->channel_status( MailChannel::ID, 'email.enabled', Settings::email_enabled() );
		}
	}

	/**
	 * Whether this channel is carrying anything, said rather than implied.
	 *
	 * `delivery-routing.md` D2 asked for this two phases before anybody hit it:
	 * "configured but not delivering" and "broken" look identical otherwise. An
	 * administrator did hit it, and the screen they were reading said nothing at
	 * all while their phone numbers went nowhere.
	 *
	 * Cheap only because 20.1 removed the routing table. While the answer was a
	 * lookup through two settings this would have been a fourth place that could
	 * disagree with the other three; now it is two booleans, and both of them are
	 * on screens the reader can get to from here.
	 *
	 * @param string $channel          Identity channel id, for the wording.
	 * @param string $enabled_path     The channel's own on/off setting.
	 * @param bool   $identity_enabled Whether that identity is accepted at all.
	 */
	private function channel_status( string $channel, string $enabled_path, bool $identity_enabled ): void {
		$is_phone = PhoneChannel::ID === $channel;
		$label    = $is_phone ? __( 'số điện thoại', 'smart-login' ) : __( 'email', 'smart-login' );

		if ( ! Settings::is_on( $enabled_path ) ) {
			$state   = 'off';
			$class   = 'notice-info';
			$message = sprintf(
				/* translators: %s: identity channel, e.g. "số điện thoại". */
				__( 'Kênh này đang tắt. Không mã xác thực nào được gửi tới %s.', 'smart-login' ),
				$label
			);
		} elseif ( ! $identity_enabled ) {
			// The state this whole sub-phase exists for: switched on, configured,
			// and unreachable — because the identity it carries is not accepted.
			$state   = 'idle';
			$class   = 'notice-warning';
			$message = sprintf(
				/* translators: %s: identity channel, e.g. "số điện thoại". */
				__( 'Kênh này đang bật nhưng chưa có gì đi qua: website hiện không nhận %s làm cách định danh. Đổi ở tab Đăng nhập & Đăng ký, mục Định danh.', 'smart-login' ),
				$label
			);
		} else {
			$state   = 'serving';
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %s: identity channel, e.g. "số điện thoại". */
				__( 'Đang gửi mã xác thực tới %s.', 'smart-login' ),
				$label
			);
		}

		printf(
			'<div class="notice %1$s inline" data-sl-channel-status="%2$s"><p>%3$s</p></div>',
			esc_attr( $class ),
			esc_attr( $state ),
			esc_html( $message )
		);
	}

	/**
	 * Which of the endpoint's two roles is actually live.
	 *
	 * Configured-but-not-routed is a valid, common configuration — the bus on,
	 * no channel pointed here — and it must not read as broken. Equally, an
	 * administrator who fills this screen in and never changes the routing has
	 * not enabled OTP delivery, and nothing else on the page would say so.
	 */
	private function automation_role(): void {
		$events = count( EventBus::subscribed() );

		if ( $events > 0 ) {
			$message = sprintf(
				/* translators: %d: number of subscribed events. */
				_n( 'Đang gửi %d loại sự kiện ra ngoài.', 'Đang gửi %d loại sự kiện ra ngoài.', $events, 'smart-login' ),
				$events
			);
			$class = 'notice-success';
		} else {
			$message = __( 'Chưa được dùng. Chọn sự kiện bên dưới để website gửi thông báo ra ngoài. Endpoint này không gửi mã xác thực — mã xác thực đi qua tab Kênh SMS và Kênh Email.', 'smart-login' );
			$class   = 'notice-info';
		}

		if ( $events > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of subscribed events. */
				_n( 'Đang theo dõi %d sự kiện.', 'Đang theo dõi %d sự kiện.', $events, 'smart-login' ),
				$events
			);
		}

		printf( '<div class="notice %s inline"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	private function after_section( string $section ): void {
		switch ( $section ) {
			case 'sms':
				$this->placeholders();
				$this->tester( 'sms' );
				break;

			case 'email':
				$this->tester( 'email' );
				break;

			case 'automation':
				$this->tester( 'automation' );
				break;

			case 'dev':
				printf( '<h2>%s</h2>', esc_html__( 'Tình trạng hệ thống', 'smart-login' ) );
				$this->system_status();
				break;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * Every address feature silently does nothing without the dataset, so say so
	 * where the switches are rather than leaving the admin to wonder.
	 */
	private function address_dataset_status(): void {
		if ( AddressRepository::is_dataset_installed() ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: province count, 2: ward count. */
						__( 'Đã cài dữ liệu hành chính: %1$d tỉnh/thành, %2$d phường/xã.', 'smart-login' ),
						count( AddressRepository::provinces() ),
						AddressRepository::count_wards()
					)
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-error inline"><p><strong>%s</strong> %s</p><p><code>php bin/build-address-data.php path/to/source.json</code></p></div>',
			esc_html__( 'Chưa có dữ liệu hành chính.', 'smart-login' ),
			esc_html__( 'Bộ chọn địa chỉ sẽ không hiển thị gì cho tới khi bạn sinh dữ liệu bằng lệnh sau:', 'smart-login' )
		);
	}

	private function placeholders(): void {
		?>
		<table class="widefat striped sl-tokens">
			<tbody>
			<?php foreach ( Placeholders::available_tokens() as $token => $description ) : ?>
				<tr>
					<td style="width:180px"><code><?php echo esc_html( $token ); ?></code></td>
					<td><?php echo esc_html( $description ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function tester( string $transport ): void {
		?>
		<h3><?php esc_html_e( 'Gửi thử', 'smart-login' ); ?></h3>
		<div class="sl-tester" data-transport="<?php echo esc_attr( $transport ); ?>">
			<p class="description">
				<?php
				switch ( $transport ) {
					case 'sms':
						esc_html_e( 'Lưu cấu hình trước, sau đó gửi một mã thật tới số điện thoại của bạn để kiểm tra.', 'smart-login' );
						break;

					case 'automation':
						esc_html_e( 'Lưu cấu hình trước. Nút này gửi một gói tin đã ký tới endpoint bất kể Định tuyến đang trỏ đâu, và không bị ngắt mạch chặn — nên nó là cách kiểm tra xem endpoint đã sống lại chưa.', 'smart-login' );
						break;

					default:
						esc_html_e( 'Lưu cấu hình trước, sau đó gửi một mã thật tới email của bạn để kiểm tra.', 'smart-login' );
				}
				?>
			</p>
			<p>
				<input
					type="text"
					class="regular-text sl-test-destination"
					placeholder="<?php echo esc_attr( $this->tester_placeholder( $transport ) ); ?>"
				/>
				<button type="button" class="button sl-test-button"><?php esc_html_e( 'Gửi thử', 'smart-login' ); ?></button>
			</p>
			<div class="sl-test-result" hidden></div>
		</div>
		<?php
	}

	private function tester_placeholder( string $transport ): string {
		switch ( $transport ) {
			case 'sms':
				return __( '0969789475', 'smart-login' );

			case 'automation':
				// Either shape is legitimate here: the envelope carries the
				// channel, so the endpoint is told which one it is being handed.
				return __( '0969789475 hoặc ban@example.com', 'smart-login' );

			default:
				return __( 'ban@example.com', 'smart-login' );
		}
	}

	private function system_status(): void {
		global $wpdb;

		$otp_table   = Installer::otp_table();
		$audit_table = Installer::audit_table();

		$rows = array(
			__( 'Phiên bản plugin', 'smart-login' )  => SMART_LOGIN_VERSION,
			__( 'WooCommerce', 'smart-login' )       => class_exists( 'WooCommerce' ) ? __( 'Đang hoạt động', 'smart-login' ) : __( 'Không có', 'smart-login' ),
			__( 'Môi trường', 'smart-login' )        => wp_get_environment_type(),
			__( 'WP_DEBUG', 'smart-login' )          => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'Bật', 'smart-login' ) : __( 'Tắt', 'smart-login' ),
			__( 'Bảng OTP', 'smart-login' )          => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $otp_table ) ) ? $otp_table : __( 'CHƯA TẠO', 'smart-login' ), // phpcs:ignore WordPress.DB
			__( 'Bảng nhật ký', 'smart-login' )      => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) ? $audit_table : __( 'CHƯA TẠO', 'smart-login' ), // phpcs:ignore WordPress.DB
			__( 'Dọn dẹp tiếp theo', 'smart-login' ) => wp_next_scheduled( Installer::CLEANUP_HOOK )
				? wp_date( 'H:i d/m/Y', wp_next_scheduled( Installer::CLEANUP_HOOK ) )
				: __( 'Chưa lên lịch', 'smart-login' ),
		);
		?>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<td style="width:220px"><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><code><?php echo esc_html( (string) $value ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
