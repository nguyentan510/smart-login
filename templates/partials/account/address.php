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
 * Override at yourtheme/omniwp/partials/account/address.php
 *
 * @var array $ow_values
 * @var bool  $ow_required
 *
 * @package OmniWP
 */

use OmniWP\Address\AddressFields;
use OmniWP\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

?>
<section class="sl-card" id="sl-section-address">
	<?php TemplateLoader::output( 'partials/account/card-head', array( 'ow_section' => 'address' ) ); ?>
	<p class="sl-hint sl-card__note">
		<?php esc_html_e( 'Địa chỉ này dùng cho cả đơn hàng và hoá đơn. Sửa ở đây là sửa luôn địa chỉ trong tab Địa chỉ.', 'omniwp' ); ?>
	</p>

	<?php
	AddressFields::output(
		array(
			'values'   => $ow_values,
			'required' => ! empty( $ow_required ),
		)
	);
	?>
</section>
