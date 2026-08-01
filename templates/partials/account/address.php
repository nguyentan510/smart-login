<?php
/**
 * Địa chỉ giao hàng — the same picker WooCommerce's own Addresses tab uses.
 *
 * The values land in billing_state / billing_city / billing_address_1 through
 * ProfileSeeder, so this edits the address that prefills checkout rather than a
 * parallel copy of it. Saying so on screen is the point: the account menu also
 * has an "Địa chỉ" tab, and two forms with no stated relationship is how people
 * end up entering the same address twice.
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
		<?php esc_html_e( 'Đây là địa chỉ giao hàng mặc định, dùng chung với tab Địa chỉ — sửa ở đây là sửa cả hai.', 'smart-login' ); ?>
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
