<?php
/**
 * Assembles the account editing surface from sections.
 *
 * Normative spec: docs/account-surface.md.
 *
 * Before this class existed the surface was 330 lines of markup in
 * templates/woocommerce/form-edit-account.php with services instantiated between
 * the tags, and a second, drifted copy of two of its blocks in
 * templates/profile-summary.php. Whoever edited one did not know about the
 * other, so the maintained copy grew a heading and an action link while the Woo
 * copy stayed an implode() of labels.
 *
 * Everything here answers one of three questions: which sections apply, what
 * each one needs, and whether it saves through the form or through its own
 * request.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Frontend;

use SmartLogin\Address\AddressFields;
use SmartLogin\Auth\ContactVerificationService;
use SmartLogin\Auth\IdentityLinkService;
use SmartLogin\Auth\ProfileCompletionService;
use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Identity\UserManager;
use SmartLogin\Settings;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class AccountForm {

	/** WooCommerce owns the nonce and the save on its own page. */
	const CONTEXT_WOOCOMMERCE = 'woocommerce';

	/** Anywhere else: the plugin owns both. */
	const CONTEXT_STANDALONE = 'standalone';

	/** Read-only: shows the same status and providers, edits nothing. */
	const CONTEXT_SUMMARY = 'summary';

	/**
	 * Section id => whether it persists through its own request.
	 *
	 * `true` means the section does not wait for the form's submit button:
	 * `contact` verifies over REST and `providers` leaves through an OAuth
	 * redirect. This is a data property, not a note about presentation. The
	 * renderer keeps those sections out of the form's dirty-state accounting,
	 * and 8.4 draws a badge from it — a control that takes effect immediately
	 * while sitting inside a form you must still submit is the single most
	 * confusing thing about the screen this replaces.
	 *
	 * Order is render order.
	 */
	const SECTIONS = array(
		'profile'   => false,
		'contact'   => true,
		'providers' => true,
		'address'   => false,
		'password'  => false,
	);

	/**
	 * What the form loop draws, in order.
	 *
	 * `providers` is missing on purpose. It is a section — profile-summary asks
	 * for it by name — but on the editing surface it belongs *inside* the
	 * contact card, because "how you sign in" and "how we reach you" are one
	 * question, and answering it in two places is what the old screen did.
	 */
	const FORM_SECTIONS = array( 'profile', 'contact', 'address', 'password' );

	/**
	 * Heading for each section. Real headings, not <p class="sl-lead">: the old
	 * screen had no document outline at all.
	 *
	 * @return array<string,string>
	 */
	public static function headings(): array {
		return array(
			'profile'   => __( 'Thông tin cá nhân', 'smart-login' ),
			'contact'   => __( 'Đăng nhập & liên hệ', 'smart-login' ),
			'address'   => __( 'Địa chỉ giao hàng', 'smart-login' ),
			'password'  => __( 'Bảo mật', 'smart-login' ),
			'providers' => __( 'Cách đăng nhập của bạn', 'smart-login' ),
		);
	}

	private int $user_id;
	private string $context;
	private ?WP_User $user;

	public function __construct( int $user_id, string $context = self::CONTEXT_STANDALONE ) {
		$known = array( self::CONTEXT_WOOCOMMERCE, self::CONTEXT_STANDALONE, self::CONTEXT_SUMMARY );

		$this->user_id = $user_id;
		$this->context = in_array( $context, $known, true ) ? $context : self::CONTEXT_STANDALONE;
		$this->user    = get_user_by( 'id', $user_id ) ?: null;
	}

	public function context(): string {
		return $this->context;
	}

	public function user(): ?WP_User {
		return $this->user;
	}

	public static function saves_own( string $section ): bool {
		return ! empty( self::SECTIONS[ $section ] );
	}

	/**
	 * Sections that apply to this account, in render order.
	 *
	 * A section that would render nothing is dropped here rather than returning
	 * early inside its own partial, so a caller can ask what the page contains
	 * without rendering it.
	 *
	 * @return string[]
	 */
	public function sections(): array {
		$sections = array();

		foreach ( self::FORM_SECTIONS as $section ) {
			if ( $this->applies( $section ) ) {
				$sections[] = $section;
			}
		}

		/**
		 * Filter the sections drawn on the account surface.
		 *
		 * @param string[]    $sections
		 * @param AccountForm $form
		 */
		return (array) apply_filters( 'smart_login_account_sections', $sections, $this );
	}

	private function applies( string $section ): bool {
		switch ( $section ) {
			case 'providers':
				return array() !== $this->link_providers() || array() !== $this->linked();

			case 'address':
				return Settings::is_on( 'address.enabled' );

			default:
				return true;
		}
	}

	public function output_section( string $section ): void {
		if ( ! isset( self::SECTIONS[ $section ] ) || ! $this->user ) {
			return;
		}

		TemplateLoader::output( 'partials/account/' . $section, $this->args_for( $section ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function args_for( string $section ): array {
		$common = array(
			'sl_user'      => $this->user,
			'sl_context'   => $this->context,
			'sl_saves_own' => self::saves_own( $section ),
		);

		switch ( $section ) {
			case 'profile':
				return $common + array(
					'sl_gender' => (string) get_user_meta( $this->user_id, UserManager::META_GENDER, true ),
					'sl_dob'    => $this->dob(),
				);

			case 'contact':
				return $common + array(
					'sl_phone'      => (string) get_user_meta( $this->user_id, UserManager::META_PHONE, true ),
					'sl_synthetic'  => UserManager::is_synthetic_email( (string) $this->user->user_email ),
					'sl_pending'    => ( new ContactVerificationService() )->pending( $this->user_id ),
					'sl_otp_length' => Settings::get_int( 'otp.length', 6 ),
					'sl_providers'  => $this->args_for( 'providers' ),
				);

			case 'providers':
				$links = new IdentityLinkService();

				return $common + array(
					'sl_identities'     => $links->linked( $this->user_id ),
					'sl_can_unlink'     => $links->can_unlink( $this->user_id ),
					'sl_redirect'       => $this->redirect_url(),
					'sl_link_providers' => $this->link_providers(),
				);

			case 'address':
				return $common + array(
					'sl_values'   => AddressFields::get_for_user( $this->user_id ),
					'sl_required' => Settings::is_on( 'address.required_in_profile' ),
				);

			default:
				return $common;
		}
	}

	/**
	 * Stored as Y-m-d, shown as d/m/Y.
	 */
	private function dob(): string {
		$stored = (string) get_user_meta( $this->user_id, UserManager::META_DOB, true );

		return '' === $stored ? '' : (string) gmdate( 'd/m/Y', (int) strtotime( $stored ) );
	}

	/**
	 * Providers worth offering: enabled, and not already attached.
	 *
	 * The Woo template used to call ProviderRegistry::available() directly and
	 * so invited an account to link a provider it had linked years ago.
	 *
	 * @return array<int,object>
	 */
	public function link_providers(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$available = ( new ProviderRegistry() )->available();
		$offerable = ( new IdentityLinkService() )->unlinked_providers(
			$this->user_id,
			array_map( static fn( $provider ): string => $provider->id(), $available )
		);

		$cache = array_values(
			array_filter(
				$available,
				static fn( $provider ): bool => in_array( $provider->id(), $offerable, true )
			)
		);

		return $cache;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function linked(): array {
		return ( new IdentityLinkService() )->linked( $this->user_id );
	}

	/**
	 * Arguments for the status notice, which sits outside the form.
	 *
	 * @return array<string,mixed>
	 */
	public function status_args(): array {
		return array(
			'sl_status'   => ( new ProfileCompletionService() )->status( $this->user_id ),
			'sl_pending'  => ( new ContactVerificationService() )->pending( $this->user_id ),
			// phpcs:ignore WordPress.Security.NonceVerification -- read-only presentation switch.
			'sl_welcome'  => ! empty( $_GET['smartlogin_welcome'] ),
			// On the editing surface itself there is nowhere to send anybody.
			'sl_edit_url' => $this->is_editing_surface() ? '' : self::edit_url(),
		);
	}

	public function output_status(): void {
		TemplateLoader::output( 'partials/account/status', $this->status_args() );
	}

	private function is_editing_surface(): bool {
		return self::CONTEXT_SUMMARY !== $this->context;
	}

	/**
	 * Where "Cập nhật ngay" leads, or '' when there is nowhere to send anybody.
	 *
	 * This used to fall back to admin_url( 'profile.php' ) — sending a customer
	 * into wp-admin, which is a leak rather than a fallback. Returning '' is the
	 * honest answer, and every caller already renders no link for it.
	 *
	 * Resolution order: an explicit filter, then WooCommerce's endpoint, then a
	 * page hosting the [smart_account] shortcode.
	 */
	public static function edit_url(): string {
		/**
		 * Point the account links at a specific page.
		 *
		 * @param string $url
		 */
		$filtered = (string) apply_filters( 'smart_login_account_url', '' );

		if ( '' !== $filtered ) {
			return $filtered;
		}

		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) wc_get_account_endpoint_url( 'edit-account' );
		}

		return self::shortcode_page_url();
	}

	/**
	 * The first published page containing [smart_account].
	 *
	 * Cached in an option because it changes about as often as the site's page
	 * structure does, and the alternative is a LIKE query on post_content for
	 * every notice that wants to link somewhere.
	 */
	private static function shortcode_page_url(): string {
		$cached = get_option( 'smart_login_account_page', null );

		if ( null !== $cached && '' !== $cached ) {
			$url = get_permalink( (int) $cached );

			if ( $url ) {
				return (string) $url;
			}
		}

		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				's'                => '[smart_account',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$page_id = $pages ? (int) $pages[0] : 0;

		update_option( 'smart_login_account_page', $page_id, false );

		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	private function redirect_url(): string {
		$permalink = get_permalink();

		return $permalink ? (string) $permalink : (string) home_url( '/' );
	}
}
