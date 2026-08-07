<?php
/**
 * Đăng nhập & liên hệ — phone, email, and the providers attached to the account.
 *
 * One card, because these answer one question: how you get in, and how we reach
 * you. The screen this replaces spread them across three places in three visual
 * styles, and showed the email address twice.
 *
 * Each row shows the value in force with a "Đổi" that opens the OTP exchange in
 * place, instead of two always-visible panels asking for values nobody was
 * trying to change.
 *
 * Nothing in this card waits for the form's submit button — see
 * AccountForm::SECTIONS. The badge says so out loud, because a control that
 * takes effect immediately inside a form you must still submit is the single
 * most confusing thing about the old screen.
 *
 * Override at yourtheme/smart-login/partials/account/contact.php
 *
 * @var string $sl_phone      Canonical, or '' when the account has none.
 * @var bool   $sl_synthetic  Placeholder address, so there is no real email yet.
 * @var array  $sl_pending
 * @var int    $sl_otp_length
 * @var array  $sl_providers  Args for partials/account/providers.
 * @var WP_User $sl_user
 *
 * @package SmartLogin
 */

use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Identity\Phone;

defined( 'ABSPATH' ) || exit;

$sl_pending    = isset( $sl_pending ) && is_array( $sl_pending ) ? $sl_pending : array();
$sl_otp_length = isset( $sl_otp_length ) ? (int) $sl_otp_length : 6;

/*
 * The identity record behind each contact channel, keyed by channel id.
 *
 * This is what the second list used to say and says no longer — that the value
 * is a way in, and which one is primary. It belongs beside the value rather than
 * in a list underneath it. Already in scope: `sl_providers` is handed to this
 * partial for the block at the foot of the card, so no new query is added.
 */
$sl_records = array();

foreach ( ( is_array( $sl_providers ?? null ) ? ( $sl_providers['sl_identities'] ?? array() ) : array() ) as $sl_record ) {
	if ( is_array( $sl_record ) && ! empty( $sl_record['channel'] ) ) {
		$sl_records[ (string) $sl_record['channel'] ] = $sl_record;
	}
}

$sl_rows = array(
	'phone' => array(
		'label'    => __( 'Số điện thoại', 'smart-login' ),
		'id'       => 'sl-contact-phone',
		'type'     => 'tel',
		'complete' => 'tel',
		'value'    => '' !== $sl_phone ? Phone::to_local( $sl_phone ) : '',
		'empty'    => __( 'Chưa có số điện thoại', 'smart-login' ),
		'action'   => '' !== $sl_phone ? __( 'Đổi', 'smart-login' ) : __( 'Thêm', 'smart-login' ),
		'identity' => $sl_records[ PhoneChannel::ID ] ?? null,
	),
	'email' => array(
		'label'    => __( 'Email', 'smart-login' ),
		'id'       => 'sl-contact-email',
		'type'     => 'email',
		'complete' => 'email',
		'value'    => $sl_synthetic ? '' : (string) $sl_user->user_email,
		'empty'    => __( 'Chưa có email', 'smart-login' ),
		'action'   => $sl_synthetic ? __( 'Thêm', 'smart-login' ) : __( 'Đổi', 'smart-login' ),
		'identity' => $sl_records[ MailChannel::ID ] ?? null,
	),
);
?>
<section class="sl-card" id="sl-section-contact">
	<div class="sl-card__head">
		<?php TemplateLoader::output( 'partials/account/card-head', array( 'sl_section' => 'contact' ) ); ?>
		<?php
		/*
		 * States the effect, not the mechanism. "lưu riêng, không qua nút Cập
		 * nhật" described the implementation and asked the reader to hold a rule
		 * about which button applies to which card. `saves_own` and its three
		 * readers are untouched — this is the badge's copy, not its source.
		 */
		?>
		<span class="sl-badge sl-badge--instant"><?php esc_html_e( 'có hiệu lực ngay', 'smart-login' ); ?></span>
	</div>

	<?php foreach ( $sl_rows as $sl_type => $sl_row ) : ?>
		<div
			class="sl-contact-verification"
			data-sl-contact="<?php echo esc_attr( $sl_type ); ?>"
			data-sl-contact-target="#sl-value-<?php echo esc_attr( $sl_type ); ?>"
			<?php if ( ! empty( $sl_pending ) && ( $sl_pending['type'] ?? '' ) === $sl_type ) : ?>
				data-sl-contact-pending="<?php echo esc_attr( $sl_pending['masked'] ?? '' ); ?>"
			<?php endif; ?>
		>
			<div class="sl-row">
				<span class="sl-row__label"><?php echo esc_html( $sl_row['label'] ); ?></span>

				<span class="sl-row__value" id="sl-value-<?php echo esc_attr( $sl_type ); ?>">
					<?php if ( '' !== $sl_row['value'] ) : ?>
						<?php echo esc_html( $sl_row['value'] ); ?>
					<?php else : ?>
						<em class="sl-muted"><?php echo esc_html( $sl_row['empty'] ); ?></em>
					<?php endif; ?>
				</span>

				<?php
				/*
				 * The badge is rendered whether or not it applies, and hidden when
				 * it does not. smart-login.js unhides it after a successful
				 * verification, and that line was unreachable for the one case it
				 * was written for: an account adding its first phone had no badge
				 * in the DOM to unhide.
				 *
				 * The condition is the directory's answer, not the value's. It used
				 * to read `'' !== $sl_row['value']`, which claims a phone is
				 * verified on the strength of a user-meta key — the over-claim
				 * AccountForm::has_contact_identity() is documented at length for
				 * avoiding.
				 */
				?>
				<span
					class="sl-badge sl-badge--verified"
					data-sl-contact-verified
					<?php echo null === $sl_row['identity'] ? 'hidden' : ''; ?>
				><?php esc_html_e( 'dùng để đăng nhập', 'smart-login' ); ?></span>

				<?php if ( ! empty( $sl_row['identity']['is_primary'] ) ) : ?>
					<span class="sl-badge sl-badge--primary"><?php esc_html_e( 'Chính', 'smart-login' ); ?></span>
				<?php endif; ?>

				<button
					type="button"
					class="sl-action"
					data-sl-contact-toggle
					aria-expanded="false"
					aria-controls="sl-edit-<?php echo esc_attr( $sl_type ); ?>"
				><?php echo esc_html( $sl_row['action'] ); ?></button>
			</div>

			<div class="sl-contact-edit" id="sl-edit-<?php echo esc_attr( $sl_type ); ?>" data-sl-contact-edit hidden>
				<label class="sl-label" for="<?php echo esc_attr( $sl_row['id'] ); ?>">
					<?php
					/* translators: %s: Số điện thoại or Email. */
					printf( esc_html__( '%s mới', 'smart-login' ), esc_html( $sl_row['label'] ) );
					?>
				</label>
				<div class="sl-contact-row">
					<input
						type="<?php echo esc_attr( $sl_row['type'] ); ?>"
						class="sl-input"
						id="<?php echo esc_attr( $sl_row['id'] ); ?>"
						data-sl-contact-value
						autocomplete="<?php echo esc_attr( $sl_row['complete'] ); ?>"
					/>
					<?php
					/*
					 * A button, not a `.sl-action`: this is the primary action of the
					 * editor it sits in, and the row above already carries the small
					 * control that opened it. `--inline` is 17.3's replacement for the
					 * `.sl-contact-row .sl-btn { width: auto }` that used to decide
					 * this from an ancestor.
					 */
					?>
					<button type="button" class="sl-btn sl-btn--outline sl-btn--inline" data-sl-contact-start><?php esc_html_e( 'Gửi mã', 'smart-login' ); ?></button>
				</div>

				<div class="sl-contact-confirm" data-sl-contact-confirm hidden>
					<p class="sl-hint" data-sl-contact-masked></p>
					<?php
					/*
					 * A real label, added in 18.2. This box carried
					 * `placeholder="Mã OTP"` and nothing else — no id, no label, no
					 * aria-label — so it was the only control on the account surface
					 * with no accessible name, twice, once per channel.
					 *
					 * A placeholder is the last resort in the name computation and it
					 * disappears the moment somebody types into the box it was
					 * explaining, which on a six-digit code is immediately. The
					 * placeholder is gone rather than kept beside the label: it said
					 * the same thing, and `maxlength` already carries the length.
					 */
					?>
					<label class="sl-label" for="sl-contact-code-<?php echo esc_attr( $sl_type ); ?>">
						<?php esc_html_e( 'Mã xác thực', 'smart-login' ); ?>
					</label>
					<div class="sl-contact-row">
						<input
							type="text"
							class="sl-input"
							id="sl-contact-code-<?php echo esc_attr( $sl_type ); ?>"
							data-sl-contact-code
							inputmode="numeric"
							autocomplete="one-time-code"
							maxlength="<?php echo esc_attr( (string) $sl_otp_length ); ?>"
						/>
						<button type="button" class="sl-btn sl-btn--primary sl-btn--inline" data-sl-contact-verify><?php esc_html_e( 'Xác thực', 'smart-login' ); ?></button>
					</div>
					<button type="button" class="sl-action" data-sl-contact-resend><?php esc_html_e( 'Gửi lại mã', 'smart-login' ); ?></button>
				</div>

				<p class="sl-contact-status" data-sl-contact-status aria-live="polite"></p>
			</div>
		</div>
	<?php endforeach; ?>

	<?php
	/*
	 * The rows above offer "Đổi", and "Đổi" is a listener. With JavaScript off it
	 * is a control that cannot work and, until this was added, said nothing about
	 * why. `partials/address-fields.php` has carried the same courtesy for the
	 * ward select since 8.5; this card never got one.
	 *
	 * Found by 18.4, on the first reading of a page that carries no JavaScript at
	 * all — which is the state the visual renderer is permanently in.
	 */
	?>
	<noscript>
		<p class="sl-hint">
			<?php esc_html_e( 'Trình duyệt đang tắt JavaScript: chưa đổi được số điện thoại hoặc email ở đây, vì bước nhập mã xác thực cần JavaScript. Hãy bật JavaScript rồi tải lại trang.', 'smart-login' ); ?>
		</p>
	</noscript>

	<?php TemplateLoader::output( 'partials/account/providers', is_array( $sl_providers ?? null ) ? $sl_providers : array() ); ?>
</section>
