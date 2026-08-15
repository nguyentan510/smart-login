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
 * @package OmniWP
 */

namespace OmniWP\Frontend;

use OmniWP\Address\AddressFields;
use OmniWP\Auth\ContactVerificationService;
use OmniWP\Auth\IdentityLinkService;
use OmniWP\Auth\ProfileCompletionService;
use OmniWP\Auth\Providers\ProviderRegistry;
use OmniWP\Identity\Channels\MailChannel;
use OmniWP\Identity\Channels\PhoneChannel;
use OmniWP\Identity\IdentityDirectory;
use OmniWP\Identity\UserManager;
use OmniWP\Settings;
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
	 * The marks come from `IconSet` since 21.2. They used to be built here by a
	 * local closure, which made this the second of three places in the plugin
	 * that knew how to draw a glyph — the drift this method's own history is
	 * about, one level up. What is declared here is *which* mark a section
	 * carries; what a mark looks like is not this class's decision.
	 *
	 * Still 18×18 and still `currentColor`: the slot decides the colour, and the
	 * accent is already on `.sl-card__icon`.
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
		return array(
			'profile'  => array(
				'label' => __( 'Thông tin cá nhân', 'omniwp' ),
				'icon'  => IconSet::get( 'user' ),
			),
			'contact'  => array(
				'label' => __( 'Đăng nhập & liên hệ', 'omniwp' ),
				'icon'  => IconSet::get( 'lock' ),
			),
			'address'  => array(
				'label' => __( 'Địa chỉ nhận hàng', 'omniwp' ),
				'icon'  => IconSet::get( 'map-pin' ),
			),
			'password' => array(
				'label' => __( 'Bảo mật', 'omniwp' ),
				'icon'  => IconSet::get( 'shield' ),
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
		return (array) apply_filters( 'OMNIWP_account_sections', $sections, $this );
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
			'ow_user'      => $this->user,
			'ow_context'   => $this->context,
			'ow_saves_own' => self::saves_own( $section ),
		);

		switch ( $section ) {
			case 'profile':
				return $common + array(
					'ow_gender' => (string) get_user_meta( $this->user_id, UserManager::META_GENDER, true ),
					'ow_dob'    => $this->dob(),
				);

			case 'contact':
				return $common + array(
					'ow_phone'      => (string) get_user_meta( $this->user_id, UserManager::META_PHONE, true ),
					'ow_synthetic'  => UserManager::is_synthetic_email( (string) $this->user->user_email ),
					'ow_pending'    => ( new ContactVerificationService() )->pending( $this->user_id ),
					'ow_otp_length' => Settings::get_int( 'otp.length', 6 ),
					'ow_providers'  => $this->args_for( 'providers' ),
				);

			case 'providers':
				$links = new IdentityLinkService();

				return $common + array(
					'ow_identities'     => $links->linked( $this->user_id ),
					'ow_can_unlink'     => $links->can_unlink( $this->user_id ),
					'ow_redirect'       => $this->redirect_url(),
					'ow_link_providers' => $this->link_providers(),
				);

			case 'address':
				return $common + array(
					'ow_values'   => AddressFields::get_for_user( $this->user_id ),
					'ow_required' => Settings::is_on( 'address.required_in_profile' ),
				);

			case 'password':
				return $common + array(
					'ow_has_contact' => $this->has_contact_identity(),
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
			'ow_status'   => ( new ProfileCompletionService() )->status( $this->user_id ),
			'ow_pending'  => ( new ContactVerificationService() )->pending( $this->user_id ),
			// phpcs:ignore WordPress.Security.NonceVerification -- read-only presentation switch.
			'ow_welcome'  => ! empty( $_GET['OmniWP_welcome'] ),
			// On the editing surface itself there is nowhere to send anybody.
			'ow_edit_url' => $this->is_editing_surface() ? '' : self::edit_url(),
		);
	}

	public function output_status(): void {
		TemplateLoader::output( 'partials/account/status', $this->status_args() );
	}

	private function is_editing_surface(): bool {
		return self::CONTEXT_SUMMARY !== $this->context;
	}

	/**
	 * Where "Cập nhật ngay" and Account Menu links lead.
	 *
	 * Prioritizes the page hosting the [smart_account] (Smart Account Hub) shortcode,
	 * supporting deep linking (?tab=...), then falls back to WooCommerce or home_url.
	 *
	 * @param string $tab Optional tab identifier (e.g. 'orders', 'address', 'profile', 'security').
	 * @return string
	 */
	public static function edit_url( string $tab = '' ): string {
		/**
		 * Point the account links at a specific page.
		 *
		 * @param string $url
		 * @param string $tab
		 */
		$filtered = (string) apply_filters( 'OMNIWP_account_url', '', $tab );

		if ( '' !== $filtered ) {
			return ! empty( $tab ) ? add_query_arg( 'tab', $tab, $filtered ) : $filtered;
		}

		// 1. Prioritize Smart Account Hub page ([smart_account])
		$shortcode_url = self::shortcode_page_url();
		if ( '' !== $shortcode_url ) {
			return ! empty( $tab ) ? add_query_arg( 'tab', $tab, $shortcode_url ) : $shortcode_url;
		}

		// 2. Fallback to WooCommerce account endpoint if shortcode page is not found
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			if ( 'vouchers' === $tab ) {
				return add_query_arg( 'tab', 'vouchers', (string) wc_get_account_endpoint_url( 'edit-account' ) );
			}
			$wc_endpoint = 'orders' === $tab ? 'orders' : ( 'address' === $tab ? 'edit-address' : 'edit-account' );
			return (string) wc_get_account_endpoint_url( $wc_endpoint );
		}

		return (string) home_url( '/' );
	}


	/**
	 * The first published page containing [smart_account].
	 *
	 * Cached in an option because it changes about as often as the site's page
	 * structure does, and the alternative is a LIKE query on post_content for
	 * every notice that wants to link somewhere.
	 */
	public static function shortcode_page_url(): string {
		return SitePage::url( array( 'smart_account' ), 'OMNIWP_account_page' );
	}

	private function redirect_url(): string {
		$permalink = get_permalink();

		return $permalink ? (string) $permalink : (string) home_url( '/' );
	}
}
