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
use SmartLogin\Admin\ProviderCards;
use SmartLogin\Admin\SettingsPage;
use SmartLogin\FieldRegistry;
use SmartLogin\Installer;
use SmartLogin\OTP\Placeholders;
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

		printf( '<h2>%s</h2>', esc_html( $labels[ $section ] ?? $section ) );

		$this->before_section( $section );

		// The provider cards carry their own credential inputs and callback URLs,
		// so they replace the table rather than sitting beside it.
		if ( 'provider' === $section ) {
			( new ProviderCards() )->render( $fields );
			$this->after_section( $section );
			return;
		}

		echo '<table class="form-table" role="presentation">';

		foreach ( $fields as $path => $field ) {
			FieldRenderer::render( $path, $field );
		}

		echo '</table>';

		$this->after_section( $section );
	}

	private function before_section( string $section ): void {
		if ( 'address' === $section ) {
			$this->address_dataset_status();
		}
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
		<div class="sl-tester" data-channel="<?php echo esc_attr( $transport ); ?>">
			<p class="description">
				<?php
				echo 'sms' === $transport
					? esc_html__( 'Lưu cấu hình trước, sau đó gửi một mã thật tới số điện thoại của bạn để kiểm tra.', 'smart-login' )
					: esc_html__( 'Lưu cấu hình trước, sau đó gửi một mã thật tới email của bạn để kiểm tra.', 'smart-login' );
				?>
			</p>
			<p>
				<input
					type="text"
					class="regular-text sl-test-destination"
					placeholder="<?php echo 'sms' === $transport ? esc_attr__( '0969789475', 'smart-login' ) : esc_attr__( 'ban@example.com', 'smart-login' ); ?>"
				/>
				<button type="button" class="button sl-test-button"><?php esc_html_e( 'Gửi thử', 'smart-login' ); ?></button>
			</p>
			<div class="sl-test-result" hidden></div>
		</div>
		<?php
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
