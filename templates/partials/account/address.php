<?php
/**
 * Địa chỉ nhận hàng — the same picker WooCommerce's own Addresses tab uses.
 *
 * Since 17.4 the values land in **both** of Woo's address books through
 * ProfileSeeder, which is what makes the heading true. Before it they landed in
 * `billing_*` only, while the card was headed "Địa chỉ giao hàng" and told the
 * reader that editing here edited both — a sentence that was false for any
 * customer who had ever saved a separate shipping address.
 *
 * Saying the relationship out loud is still the point: the account menu also has
 * an "Địa chỉ" tab, and two forms with no stated relationship is how people end
 * up entering the same address twice.
 *
 * The hardcoded "Quốc gia: Việt Nam" row is gone. A locked field carrying one
 * value communicates nothing and costs a row.
 *
 * Override at yourtheme/smart-login/partials/account/address.php
 *
 * @var array $sl_values
 * @var bool  $sl_required
 *
 * @package SmartLogin
 */

use SmartLogin\Address\AddressFields;
use SmartLogin\Frontend\AccountForm;

defined( 'ABSPATH' ) || exit;

$sl_headings = AccountForm::headings();
?>
<section class="sl-card" id="sl-section-address">
	<h3 class="sl-card__title">
		<span class="sl-card__icon" aria-hidden="true">&#9679;</span>
		<?php echo esc_html( $sl_headings['address'] ); ?>
	</h3>
	<p class="sl-hint sl-card__note">
		<?php esc_html_e( 'Địa chỉ này dùng cho cả đơn hàng và hoá đơn. Sửa ở đây là sửa luôn địa chỉ trong tab Địa chỉ.', 'smart-login' ); ?>
	</p>

	<?php
	AddressFields::output(
		array(
			'values'   => $sl_values,
			'required' => ! empty( $sl_required ),
		)
	);
	?>
</section>
