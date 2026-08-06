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
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Identity\IdentityDirectory;
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
	 * What each section that draws a heading is called, and what it is marked
	 * with. One array, so the two cannot drift.
	 *
	 * Real headings, not <p class="sl-lead">: the old screen had no document
	 * outline at all.
	 *
	 * **This was `headings()` until 17.8, and returned labels only.** Every one
	 * of the four partials then wrote its own `<span class="sl-card__icon">`
	 * carrying the same `&#9679;` — four identical marks, which distinguish
	 * nothing, in four places, which is the four-way drift the `FieldRegistry`
	 * rewrite exists to make unrepresentable. A section's name and its mark are
	 * one decision and are declared together.
	 *
	 * The marks are inline SVG at 18×18, matching what `icon_svg()` already
	 * returns for a provider, so the card has one glyph size and not two.
	 * `currentColor` throughout: the slot decides the colour, and the accent is
	 * already on `.sl-card__icon`.
	 *
	 * `providers` is absent, and that is not the same absence as in
	 * FORM_SECTIONS above. It is a section, and it renders — but since 16.3 it
	 * renders as rows inside the contact card's own list, under the contact
	 * card's own heading. A `providers` entry here would be a translatable string
	 * naming a heading nothing draws, which is the class of statement 15.4 exists
	 * to stop the plugin shipping.
	 *
	 * @return array<string,array{label:string,icon:string}>
	 */
	public static function sections_meta(): array {
		$svg = static function ( string $body ): string {
			return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
				. ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
				. ' focusable="false" aria-hidden="true">' . $body . '</svg>';
		};

		return array(
			'profile'  => array(
				'label' => __( 'Thông tin cá nhân', 'smart-login' ),
				'icon'  => $svg( '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>' ),
			),
			'contact'  => array(
				'label' => __( 'Đăng nhập & liên hệ', 'smart-login' ),
				'icon'  => $svg( '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>' ),
			),
			'address'  => array(
				'label' => __( 'Địa chỉ nhận hàng', 'smart-login' ),
				'icon'  => $svg( '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>' ),
			),
			'password' => array(
				'label' => __( 'Bảo mật', 'smart-login' ),
				'icon'  => $svg( '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>' ),
			),
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

			case 'password':
				return $common + array(
					'sl_has_contact' => $this->has_contact_identity(),
				);

			default:
				return $common;
		}
	}

	/**
	 * Can this account be reached by anything its owner could type?
	 *
	 * Asked of the directory, and **not** of `user_email`. An account created by a
	 * Google login holds the verified Google address in `wp_users.user_email`, so
	 * `UserManager::is_synthetic_email()` answers *false* for exactly the population
	 * this question exists to identify — and the security section would then offer a
	 * "current password" box holding a 64-character random string its owner has never
	 * seen. Before 14.4 there was no email identity in that case; after it there is,
	 * when the provider flag is on. Either way the directory is the only thing that
	 * knows, which is what Invariant 1 says.
	 *
	 * Phone counts as well as email: either is an identifier the login screen accepts.
	 */
	private function has_contact_identity(): bool {
		foreach ( ( new IdentityDirectory() )->for_user( $this->user_id ) as $record ) {
			if ( in_array( $record->claim()->channel(), array( MailChannel::ID, PhoneChannel::ID ), true ) ) {
				return true;
			}
		}

		return false;
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
