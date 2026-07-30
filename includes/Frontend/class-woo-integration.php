<?php
/**
 * WooCommerce integration: My Account screens, phone sync, and keeping
 * transactional mail away from placeholder addresses.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Address\AddressFields;
use SmartLogin\Identity\IdentityResolver;
use SmartLogin\Identity\Phone;
use SmartLogin\Identity\UserManager;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class WooIntegration {

	public function register(): void {
		if ( Settings::is_on( 'woo_replace_login_form' ) ) {
			add_filter( 'woocommerce_locate_template', array( $this, 'swap_template' ), 10, 3 );
		}

		// Runs before WC_Form_Handler::save_account_details (template_redirect @20).
		add_action( 'template_redirect', array( $this, 'prepare_account_post' ), 10 );

		add_action( 'woocommerce_save_account_details', array( $this, 'save_account_details' ), 10, 1 );
		add_filter( 'woocommerce_save_account_details_errors', array( $this, 'block_unverified_email_change' ), 10, 2 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'sync_phone_from_address' ), 10, 2 );

		add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields' ), 20, 1 );

		// The account template is loaded by Woo, not by a shortcode, so it needs
		// the plugin's styles and scripts enqueued explicitly.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_account_page' ), 20 );

		add_action( 'woocommerce_account_content', array( $this, 'render_registration_success' ), 1 );

		if ( Settings::is_on( 'woo_block_synthetic_emails' ) ) {
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
	 * over the account content for this one request to show the success screen.
	 */
	public function render_registration_success(): void {
		if ( Flow::STEP_DONE !== Flow::step( '' ) ) {
			return;
		}

		remove_action( 'woocommerce_account_content', 'woocommerce_account_content' );

		Assets::enqueue();

		TemplateLoader::output(
			'registered-success',
			array(
				'notices'  => Notices::all(),
				'redirect' => (string) Flow::data( 'redirect', wc_get_account_endpoint_url( 'edit-account' ) ),
				'user_id'  => (int) Flow::data( 'user_id', 0 ),
			)
		);
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
	 *  - The form collects one "Họ tên" field; Woo wants first/last/display.
	 */
	public function prepare_account_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies its own nonce moments later.
		if ( empty( $_POST['save_account_details'] ) || ! is_user_logged_in() ) {
			return;
		}

		if ( empty( $_POST['account_email'] ) ) {
			$user = wp_get_current_user();

			if ( UserManager::is_synthetic_email( $user->user_email ) ) {
				$_POST['account_email'] = $user->user_email;
			}
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
			$dob = \SmartLogin\Auth\RegisterHandler::parse_dob( (string) wp_unslash( $_POST['smartlogin_dob'] ) );

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

		if ( isset( $_POST['smartlogin_referral_code'] ) ) {
			$referral = sanitize_text_field( wp_unslash( $_POST['smartlogin_referral_code'] ) );

			if ( '' === $referral ) {
				delete_user_meta( $user_id, UserManager::META_REFERRAL );
			} else {
				update_user_meta( $user_id, UserManager::META_REFERRAL, $referral );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$this->save_account_address( $user_id );

		$user = get_userdata( $user_id );

		// A real address has replaced the placeholder — drop the flag and mirror
		// it into billing so checkout is pre-filled.
		if ( $user && ! UserManager::is_synthetic_email( $user->user_email ) ) {
			delete_user_meta( $user_id, UserManager::META_SYNTHETIC );

			if ( ! get_user_meta( $user_id, 'billing_email', true ) ) {
				update_user_meta( $user_id, 'billing_email', $user->user_email );
			}
		}
	}

	/**
	 * Save the two-level address submitted by the profile form.
	 *
	 * A malformed pair is reported through wc_add_notice rather than saved, so
	 * the rest of the profile still updates and the customer sees why.
	 */
	private function save_account_address( int $user_id ): void {
		if ( ! Settings::is_on( 'address_enabled' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified its own nonce.
		if ( ! isset( $_POST[ AddressFields::FIELD_PROVINCE ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$clean = AddressFields::validate( wp_unslash( $_POST ), Settings::is_on( 'address_required_in_profile' ) );

		if ( is_wp_error( $clean ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $clean->get_error_message(), 'error' );
			}
			return;
		}

		AddressFields::save_for_user( $user_id, $clean );
	}

	/**
	 * Restore the verified canonical phone after a billing-address edit.
	 *
	 * A billing form cannot verify ownership, so it must not update the phone
	 * used for login. ContactVerificationService is the only write path.
	 *
	 * @param int    $user_id
	 * @param string $load_address billing|shipping
	 */
	public function sync_phone_from_address( $user_id, $load_address ): void {
		if ( 'billing' !== $load_address || ! Settings::is_on( 'woo_sync_billing_phone' ) ) {
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

		update_user_meta( $user_id, 'billing_phone', Phone::to_local( $canonical ) );
	}

	/**
	 * Checkout tweaks: phone always required, email optional when the site is
	 * phone-first and the admin opted in.
	 */
	public function filter_billing_fields( $fields ) {
		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['required'] = true;
		}

		if ( Settings::is_on( 'woo_relax_billing_email' ) && isset( $fields['billing_email'] ) ) {
			$fields['billing_email']['required'] = false;
		}

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
	 * @param null|bool $return
	 * @param array     $atts
	 * @return null|bool
	 */
	public function abort_empty_mail( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		$to = $atts['to'] ?? '';

		if ( is_array( $to ) ) {
			$to = implode( ',', $to );
		}

		return '' === trim( (string) $to ) ? true : $return;
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
