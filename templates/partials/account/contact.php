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

use SmartLogin\Frontend\AccountForm;
use SmartLogin\Frontend\TemplateLoader;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Identity\Phone;

defined( 'ABSPATH' ) || exit;

$sl_headings   = AccountForm::headings();
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
		<h3 class="sl-card__title">
			<span class="sl-card__icon" aria-hidden="true">&#9679;</span>
			<?php echo esc_html( $sl_headings['contact'] ); ?>
		</h3>
		<span class="sl-badge sl-badge--instant"><?php esc_html_e( 'lưu riêng, không qua nút Cập nhật', 'smart-login' ); ?></span>
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
					class="sl-link sl-link--button"
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
					<button type="button" class="sl-btn sl-btn--outline" data-sl-contact-start><?php esc_html_e( 'Gửi mã', 'smart-login' ); ?></button>
				</div>

				<div class="sl-contact-confirm" data-sl-contact-confirm hidden>
					<p class="sl-hint" data-sl-contact-masked></p>
					<div class="sl-contact-row">
						<input
							type="text"
							class="sl-input"
							data-sl-contact-code
							inputmode="numeric"
							autocomplete="one-time-code"
							maxlength="<?php echo esc_attr( (string) $sl_otp_length ); ?>"
							placeholder="<?php esc_attr_e( 'Mã OTP', 'smart-login' ); ?>"
						/>
						<button type="button" class="sl-btn sl-btn--primary" data-sl-contact-verify><?php esc_html_e( 'Xác thực', 'smart-login' ); ?></button>
					</div>
					<button type="button" class="sl-link sl-link--button" data-sl-contact-resend><?php esc_html_e( 'Gửi lại mã', 'smart-login' ); ?></button>
				</div>

				<p class="sl-contact-status" data-sl-contact-status aria-live="polite"></p>
			</div>
		</div>
	<?php endforeach; ?>

	<?php TemplateLoader::output( 'partials/account/providers', is_array( $sl_providers ?? null ) ? $sl_providers : array() ); ?>
</section>
