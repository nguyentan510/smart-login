<?php
/**
 * Thank You & Order Tracking Service: visual order progress tracker,
 * VietQR instant bank transfer generator, and OmniWP styled summary.
 *
 * @package OmniWP
 */

namespace OmniWP\Ecommerce;

use OmniWP\Frontend\TemplateLoader;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class ThankYouService {

	public function register(): void {
		if ( ! Settings::is_on( 'ecommerce.thankyou_custom_enabled', true ) ) {
			return;
		}

		add_action( 'woocommerce_thankyou', array( $this, 'render_custom_thankyou' ), 5, 1 );
	}

	/**
	 * Render OmniWP Order Tracker & VietQR box at the top of Thank You page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_custom_thankyou( $order_id ): void {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		TemplateLoader::output(
			'ecommerce/thankyou-page',
			array(
				'order'          => $order,
				'order_id'       => $order_id,
				'status'         => $order->get_status(),
				'status_name'    => wc_get_order_status_name( $order->get_status() ),
				'payment_method' => $order->get_payment_method(),
				'vietqr_url'     => self::generate_vietqr_url( $order ),
			)
		);
	}

	/**
	 * Generate VietQR quick payment QR code URL if applicable.
	 *
	 * @param object $order WooCommerce Order object.
	 * @return string|null
	 */
	public static function generate_vietqr_url( $order ): ?string {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
			return null;
		}

		if ( 'bacs' !== $order->get_payment_method() && 'vietqr' !== $order->get_payment_method() ) {
			return null;
		}

		$bacs_accounts = get_option( 'woocommerce_bacs_accounts', array() );
		if ( empty( $bacs_accounts ) || ! is_array( $bacs_accounts ) ) {
			return null;
		}

		$account = reset( $bacs_accounts );
		if ( empty( $account['bank_name'] ) || empty( $account['account_number'] ) ) {
			return null;
		}

		$bank_code = rawurlencode( preg_replace( '/[^A-Za-z0-9]/', '', (string) $account['bank_name'] ) );
		$acc_num   = rawurlencode( preg_replace( '/\s+/', '', (string) $account['account_number'] ) );
		$amount    = method_exists( $order, 'get_total' ) ? (int) $order->get_total() : 0;
		$order_num = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : '';
		$memo      = rawurlencode( 'DH' . $order_num );

		return sprintf(
			'https://img.vietqr.io/image/%s-%s-compact2.png?amount=%d&addInfo=%s&accountName=%s',
			$bank_code,
			$acc_num,
			$amount,
			$memo,
			rawurlencode( (string) ( $account['account_name'] ?? '' ) )
		);
	}
}
