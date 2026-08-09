<?php
/**
 * WooCommerce integration: My Account screens, phone sync, and keeping
 * transactional mail away from placeholder addresses.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Address\AddressFields;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\ProfileSeeder;
use SmartLogin\Identity\UserManager;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class WooIntegration {

	public function register(): void {
		if ( Settings::is_on( 'woo.replace_login_form' ) ) {
			add_filter( 'woocommerce_locate_template', array( $this, 'swap_template' ), 10, 3 );
		}

		/*
		 * Priority 5, and the reason is worth writing down.
		 *
		 * This used to say "runs before WC_Form_Handler::save_account_details
		 * (template_redirect @20)". WooCommerce 10.9.4 registers it with
		 * `add_action( 'template_redirect', … )` and no priority at all, so it
		 * runs at 10 — the same priority this had. Equal priorities fall back to
		 * registration order, and WooCommerce registers during plugins_loaded
		 * while this registers later, so WooCommerce validated the request
		 * before the field names had been translated for it.
		 *
		 * Priority 5 makes the ordering a property of the code instead of an
		 * accident of load order. FormController::dispatch() already uses 5 for
		 * the same reason.
		 */
		add_action( 'template_redirect', array( $this, 'prepare_account_post' ), 5 );

		add_action( 'woocommerce_save_account_details', array( $this, 'save_account_details' ), 10, 1 );
		add_filter( 'woocommerce_save_account_details_errors', array( $this, 'block_unverified_email_change' ), 10, 2 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'sync_phone_from_address' ), 10, 2 );

		add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields' ), 20, 1 );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'ensure_shipping_phone' ), 20, 1 );

		// The account template is loaded by Woo, not by a shortcode, so it needs
		// the plugin's styles and scripts enqueued explicitly.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_account_page' ), 20 );

		add_action( 'woocommerce_account_content', array( $this, 'render_registration_success' ), 1 );

		if ( Settings::is_on( 'woo.block_synthetic_emails' ) ) {
			add_filter( 'wp_mail', array( $this, 'strip_synthetic_recipients' ), 5, 1 );
			add_filter( 'pre_wp_mail', array( $this, 'abort_empty_mail' ), 5, 2 );
		}
	}

	// -----------------------------------------------------------------
	// Templates
	// -----------------------------------------------------------------

	/**
	 * Point selected WooCommerce templates at the plugin's versions.
	 *
	 * A theme override still wins: wc_locate_template checks the theme first
	 * and only falls through to here when it finds nothing.
	 *
	 * @param string $template      Resolved path so far.
	 * @param string $template_name e.g. myaccount/form-login.php
	 */
	public function swap_template( $template, $template_name, $template_path ) {
		$ours = array(
			'myaccount/form-login.php'        => 'woocommerce/form-login.php',
			'myaccount/form-edit-account.php' => 'woocommerce/form-edit-account.php',
		);

		if ( ! isset( $ours[ $template_name ] ) ) {
			return $template;
		}

		// Respect a genuine theme override of the Woo template.
		$theme_override = locate_template(
			array(
				trailingslashit( $template_path ) . $template_name,
				'woocommerce/' . $template_name,
			)
		);

		if ( $theme_override ) {
			return $template;
		}

		$candidate = SMART_LOGIN_DIR . 'templates/' . $ours[ $template_name ];

		return is_readable( $candidate ) ? $candidate : $template;
	}

	public function enqueue_on_account_page(): void {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			Assets::enqueue();
		}
	}

	/**
	 * Registration signs the user in during template_redirect, so by render time
	 * WooCommerce considers them logged in and would show the dashboard. Take
	 * over the account content for this one request.
	 *
	 * Two cases land here. A registration that finished on this page renders its
	 * own step. And a visitor arriving with `smartlogin_welcome=1` — which is how
	 * a provider signup gets here, since OAuth returns through a redirect
	 * and cannot render anything in place — gets the same welcome screen the
	 * native flow shows inline. Before this, that second case landed on the full
	 * account editing form, which is not a welcome.
	 */
	public function render_registration_success(): void {
		$step = Flow::step( '' );

		if ( Flow::STEP_DONE === $step ) {
			$this->take_over_account_content();

			TemplateLoader::output(
				'registered-success',
				array(
					'notices'  => Notices::all(),
					'redirect' => (string) Flow::data( 'redirect', wc_get_account_endpoint_url( 'edit-account' ) ),
					'user_id'  => (int) Flow::data( 'user_id', 0 ),
				)
			);

			return;
		}

		if ( Flow::STEP_ONBOARD !== $step && ! $this->is_welcome_request() ) {
			return;
		}

		$this->take_over_account_content();

		TemplateLoader::output(
			'onboarding',
			array( 'notices' => Notices::all() ) + Shortcodes::onboarding_args()
		);
	}

	/**
	 * A freshly registered visitor, sent here by the redirect that follows
	 * registration or by PostAuthRedirector after an OAuth signup.
	 */
	private function is_welcome_request(): bool {
		return Shortcodes::is_welcome_request();
	}

	private function take_over_account_content(): void {
		remove_action( 'woocommerce_account_content', 'woocommerce_account_content' );

		Assets::enqueue();
	}

	// -----------------------------------------------------------------
	// Account details
	// -----------------------------------------------------------------

	/**
	 * Translate this plugin's account form into the fields WooCommerce expects,
	 * before WC_Form_Handler validates them.
	 *
	 * Two adjustments:
	 *  - A phone-registered user sees an empty Email box. Submitting it empty
	 *    must mean "still no email", not a validation error, so the placeholder
	 *    address goes back in.
	 *  - The form collects one "Họ và tên" field; Woo wants first/last/display.
	 */
	public function prepare_account_post(): void {
		if ( 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies its own nonce moments later.
		if ( empty( $_POST['save_account_details'] ) || ! is_user_logged_in() ) {
			return;
		}

		/*
		 * The account form cannot change an email — that only moves through the
		 * OTP flow, and block_unverified_email_change() rejects every other
		 * route. Since 8.4 the address is not even an input on the page, it is
		 * text with a "Đổi" beside it, so nothing posts account_email at all and
		 * WooCommerce reported "Email address is a required field" on every save.
		 *
		 * Supplying the address already on file is therefore the whole truth of
		 * what this form is submitting. It also covers the synthetic case this
		 * branch originally existed for: a placeholder address is still the
		 * current address.
		 */
		// Unslashed and sanitised even though the value is only tested for
		// emptiness and then overwritten. The line below already does it
		// properly; leaving one raw read here means the file contains an example
		// of both, and the wrong one is the one somebody copies.
		$posted_email = isset( $_POST['account_email'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['account_email'] ) ) )
			: '';

		if ( '' === $posted_email ) {
			$_POST['account_email'] = wp_get_current_user()->user_email;
		}

		if ( isset( $_POST['smartlogin_full_name'] ) ) {
			$full_name = sanitize_text_field( wp_unslash( $_POST['smartlogin_full_name'] ) );

			if ( '' !== trim( $full_name ) ) {
				$names = UserManager::split_name( $full_name );

				$_POST['account_first_name'] = $names['first'];
				// Woo rejects an empty last name; a mononym fills both slots.
				$_POST['account_last_name']    = '' !== $names['last'] ? $names['last'] : $names['first'];
				$_POST['account_display_name'] = $full_name;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * A Woo account form must not silently make a new email a verified contact.
	 * Clients should use the authenticated contact verification REST flow.
	 */
	public function block_unverified_email_change( $errors, $user ): void {
		if ( ! $user || ! isset( $_POST['account_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$requested = strtolower( sanitize_email( wp_unslash( $_POST['account_email'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $requested && $requested !== strtolower( (string) $user->user_email ) ) {
			$errors->add( 'smart_login_email_verification_required', __( 'Email mới phải được xác thực bằng mã OTP trước khi lưu.', 'smart-login' ) );
		}
	}

	/**
	 * Persist the plugin's own profile fields alongside Woo's.
	 */
	public function save_account_details( $user_id ): void {
		$user_id = (int) $user_id;

		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verified its own nonce.
		if ( isset( $_POST['smartlogin_dob'] ) ) {
			// parse_dob() is the sanitiser: it returns a Y-m-d string or nothing at
			// all, so an unparseable value cannot reach the database.
			$dob = \SmartLogin\Auth\RegisterHandler::parse_dob( (string) wp_unslash( $_POST['smartlogin_dob'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( '' !== $dob ) {
				update_user_meta( $user_id, UserManager::META_DOB, $dob );
			}
		}

		if ( isset( $_POST['smartlogin_gender'] ) ) {
			$gender = sanitize_key( wp_unslash( $_POST['smartlogin_gender'] ) );

			if ( in_array( $gender, array( 'male', 'female', 'other' ), true ) ) {
				update_user_meta( $user_id, UserManager::META_GENDER, $gender );
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification

		$this->save_account_address( $user_id );

		$user = get_userdata( $user_id );

		/*
		 * A real address has replaced the placeholder — drop the flag and mirror it
		 * into billing so checkout is pre-filled.
		 *
		 * Deliberately NOT a caller of UserManager::adopt_verified_email(), though it
		 * writes two of the same five things. This method has no VerifiedClaim and
		 * must not manufacture one: the address it is looking at was already adopted
		 * by the OTP flow, because block_unverified_email_change() below rejects every
		 * other route to changing it. Routing this through the adopter would mean
		 * giving it a signature an unproven address fits through, which is the one
		 * thing 14.2 exists to prevent. Housekeeping, running after somebody else's
		 * write.
		 */
		if ( $user && ! UserManager::is_synthetic_email( $user->user_email ) ) {
			delete_user_meta( $user_id, UserManager::META_SYNTHETIC );

			ProfileSeeder::seed_if_empty( $user_id, 'billing_email', (string) $user->user_email );
		}
	}

	/**
	 * Save the two-level address submitted by the profile form.
	 *
	 * A malformed pair is reported through wc_add_notice rather than saved, so
	 * the rest of the profile still updates and the customer sees why.
	 */
	private function save_account_address( int $user_id ): void {
		if ( ! Settings::is_on( 'address.enabled' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified its own nonce.
		if ( ! isset( $_POST[ AddressFields::FIELD_PROVINCE ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$clean = AddressFields::validate( wp_unslash( $_POST ), Settings::is_on( 'address.required_in_profile' ) );

		if ( is_wp_error( $clean ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $clean->get_error_message(), 'error' );
			}
			return;
		}

		AddressFields::save_for_user( $user_id, $clean );
	}

	/**
	 * Pre-fill the billing phone after an address edit, if it is still blank.
	 *
	 * Two separate rules meet here and both matter.
	 *
	 * A billing form cannot verify ownership, so it must never update the phone
	 * used for login — ContactVerificationService is the only write path for
	 * that, and this method has no route to it.
	 *
	 * But the reverse is not symmetric: `billing_phone` is a delivery contact
	 * with its own purpose. It may legitimately be a family member's number.
	 * This used to overwrite it unconditionally on every address save, which made
	 * that impossible — the customer typed a number, WooCommerce saved it, and
	 * this hook reset it a moment later. Seeding only when empty keeps the
	 * convenience without taking the field over.
	 *
	 * @param int    $user_id
	 * @param string $load_address billing|shipping
	 */
	public function sync_phone_from_address( $user_id, $load_address ): void {
		if ( 'billing' !== $load_address || ! Settings::is_on( 'woo.sync_billing_phone' ) ) {
			return;
		}

		$canonical = (string) get_user_meta( $user_id, UserManager::META_PHONE, true );
		$verified  = (string) get_user_meta( $user_id, UserManager::META_PHONE_VERIFIED, true );
		if ( '' === $canonical || '' === $verified ) {
			return;
		}

		$canonical = Phone::normalize( $canonical );
		if ( '' === $canonical || ! Phone::is_valid( $canonical ) ) {
			return;
		}

		ProfileSeeder::seed_if_empty( (int) $user_id, 'billing_phone', Phone::to_local( $canonical ) );
	}

	/**
	 * Checkout tweaks: phone always required, email optional when the site is
	 * phone-first and the admin opted in.
	 */
	public function filter_billing_fields( $fields ) {
		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['required'] = true;
		}

		if ( Settings::is_on( 'woo.relax_billing_email' ) && isset( $fields['billing_email'] ) ) {
			$fields['billing_email']['required'] = false;
		}

		return $fields;
	}

	/**
	 * Give the recipient's phone number a field of its own.
	 *
	 * `billing_phone` is now seeded from the login phone and never overwritten,
	 * which is the right rule but leaves a gap: a customer whose parcel should
	 * reach someone else needs a second number. WooCommerce ships
	 * `shipping_phone` in recent versions; this only adds it when a theme or an
	 * older release has left it out, and always as an optional field so no
	 * existing checkout starts failing validation.
	 *
	 * Nothing ever seeds this from an identity. It belongs entirely to the
	 * customer.
	 *
	 * @param array $fields
	 * @return array
	 */
	public function ensure_shipping_phone( $fields ) {
		if ( ! is_array( $fields ) || isset( $fields['shipping_phone'] ) ) {
			return $fields;
		}

		$fields['shipping_phone'] = array(
			'label'        => __( 'Số điện thoại người nhận', 'smart-login' ),
			'required'     => false,
			'type'         => 'tel',
			'class'        => array( 'form-row-wide' ),
			'validate'     => array( 'phone' ),
			'autocomplete' => 'tel',
			'priority'     => 100,
		);

		return $fields;
	}

	// -----------------------------------------------------------------
	// Mail guard
	// -----------------------------------------------------------------

	/**
	 * Remove placeholder addresses from any outgoing mail. Delivering to
	 * `@phone.invalid` can only ever produce a bounce, and enough of those
	 * damage the domain's sending reputation.
	 */
	public function strip_synthetic_recipients( $args ) {
		if ( empty( $args['to'] ) ) {
			return $args;
		}

		$recipients = is_array( $args['to'] ) ? $args['to'] : explode( ',', (string) $args['to'] );
		$kept       = array();

		foreach ( $recipients as $recipient ) {
			$address = $this->address_of( (string) $recipient );

			if ( '' !== $address && UserManager::is_synthetic_email( $address ) ) {
				continue;
			}

			$kept[] = trim( (string) $recipient );
		}

		$args['to'] = is_array( $args['to'] ) ? $kept : implode( ', ', $kept );

		return $args;
	}

	/**
	 * Nothing left to send to: report success without touching the mailer.
	 *
	 * @param null|bool $is_returning
	 * @param array     $atts
	 * @return null|bool
	 */
	public function abort_empty_mail( $is_returning, $atts ) {
		if ( null !== $is_returning ) {
			return $is_returning;
		}

		$to = $atts['to'] ?? '';

		if ( is_array( $to ) ) {
			$to = implode( ',', $to );
		}

		return '' === trim( (string) $to ) ? true : $is_returning;
	}

	/**
	 * Pull the bare address out of `Name <a@b.c>`.
	 */
	private function address_of( string $recipient ): string {
		$recipient = trim( $recipient );

		if ( preg_match( '/<([^>]+)>/', $recipient, $matches ) ) {
			return trim( $matches[1] );
		}

		return $recipient;
	}
}
