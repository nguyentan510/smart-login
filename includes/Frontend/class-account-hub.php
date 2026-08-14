<?php
/**
 * Smart Login Account Hub Controller.
 *
 * Manages the 2-column Customer Portal layout, endpoint definitions,
 * and rendering hooks using existing OmniWP AccountForm partials.
 *
 * @package OmniWP
 */

namespace OmniWP\Frontend;

defined( 'ABSPATH' ) || exit;

final class AccountHub {

	/**
	 * Render the Account Hub UI.
	 *
	 * @param array $args Custom arguments passed to the shortcode or template.
	 */
	public static function render( array $args = array() ): void {
		if ( ! is_user_logged_in() ) {
			echo '<div class="omniwp omniwp--account-guest">';
			echo '<p>' . esc_html__( 'Bạn cần đăng nhập để xem thông tin tài khoản.', 'omniwp' ) . '</p>';
			echo '<p><a href="' . esc_url( wp_login_url() ) . '" class="sl-btn sl-btn--primary sl-login-trigger">' . esc_html__( 'Đăng nhập ngay', 'omniwp' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::enqueue_assets();

		$current_user = wp_get_current_user();
		$ow_form      = new AccountForm( $current_user->ID, AccountForm::CONTEXT_STANDALONE );
		$tabs         = self::get_tabs();
		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'profile'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'contact' === $active_tab ) {
			$active_tab = 'security';
		}

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'profile';
		}

		TemplateLoader::output(
			'account-hub',
			array(
				'user'       => $current_user,
				'ow_form'    => $ow_form,
				'notices'    => Notices::all(),
				'tabs'       => $tabs,
				'active_tab' => $active_tab,
			)
		);
	}

	/**
	 * Get all registered tabs for the Account Hub.
	 *
	 * @return array<string, array{key:string, label:string, icon:string, template:string}>
	 */
	public static function get_tabs(): array {
		$tabs = array(
			'profile'  => array(
				'key'      => 'profile',
				'label'    => __( 'Thông tin cá nhân', 'omniwp' ),
				'icon'     => 'user',
				'template' => 'account-hub/tab-profile',
			),
			'orders'   => array(
				'key'      => 'orders',
				'label'    => __( 'Lịch sử đơn hàng', 'omniwp' ),
				'icon'     => 'box',
				'template' => 'account-hub/tab-orders',
			),
			'vouchers' => array(
				'key'      => 'vouchers',
				'label'    => __( 'Mã giảm giá', 'omniwp' ),
				'icon'     => 'ticket',
				'template' => 'account-hub/tab-vouchers',
			),
			'address'  => array(
				'key'      => 'address',
				'label'    => __( 'Địa chỉ nhận hàng', 'omniwp' ),
				'icon'     => 'map-pin',
				'template' => 'account-hub/tab-address',
			),
			'security' => array(
				'key'      => 'security',
				'label'    => __( 'Đăng nhập & Bảo mật', 'omniwp' ),
				'icon'     => 'shield',
				'template' => 'account-hub/tab-security',
			),
			'logout'   => array(
				'key'       => 'logout',
				'label'     => __( 'Đăng xuất', 'omniwp' ),
				'icon'      => 'log-out',
				'template'  => '',
				'is_logout' => true,
			),
		);

		/**
		 * Filter registered Account Hub tabs.
		 *
		 * @param array $tabs Array of tabs.
		 */
		return (array) apply_filters( 'OMNIWP_account_hub_tabs', $tabs );
	}

	/**
	 * Enqueue assets required for Account Hub.
	 */
	public static function enqueue_assets(): void {
		Assets::enqueue();
		Assets::enqueue_address();

		wp_enqueue_style(
			Assets::HUB_HANDLE,
			OMNIWP_URL . 'assets/css/omniwp-account-hub.css',
			array( Assets::TOKENS_HANDLE, Assets::HANDLE ),
			OMNIWP_VERSION
		);

		wp_enqueue_script(
			Assets::HUB_HANDLE,
			OMNIWP_URL . 'assets/js/omniwp-account-hub.js',
			array( Assets::HANDLE ),
			OMNIWP_VERSION,
			true
		);

		wp_localize_script(
			Assets::HUB_HANDLE,
			'OmniWPHubData',
			array(
				'restUrl' => esc_url_raw( rest_url( 'omniwp/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
