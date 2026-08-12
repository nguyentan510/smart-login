<?php
/**
 * Immutable description of the proof used to authenticate a visitor.
 *
 * @package OmniWP
 */

namespace OmniWP\Auth;

defined( 'ABSPATH' ) || exit;

final class AuthContext {

	public string $auth_method;
	public ?string $provider;
	public ?string $provider_subject;
	public ?int $user_id;
	public bool $is_new_user;
	public bool $is_linking;
	public ?string $email;
	public bool $email_verified;
	public ?string $phone;
	public bool $phone_verified;
	public string $intended_url;
	public string $correlation_id;

	/**
	 * Whether the flow that authenticated this visitor draws its own screens.
	 *
	 * A page-hosted flow finishes by navigating: it has nowhere to put the
	 * welcome screen except another page, so `PostAuthRedirector` sends a new
	 * member to `profile_url()`. A dialog does have somewhere — itself — and
	 * navigating away from a product page is the defect this whole phase exists
	 * to fix. `profile_url()` falls back to `admin_url( 'profile.php' )` without
	 * WooCommerce, so registering from a blog post has been landing people in
	 * wp-admin.
	 *
	 * It belongs here rather than in a global or a query parameter because it is
	 * a fact about *this request*, which is what this object is. Default false,
	 * so every existing caller keeps the behaviour it has today.
	 */
	public bool $in_place;

	public function __construct( array $data = array() ) {
		$this->auth_method      = (string) ( $data['auth_method'] ?? 'password' );
		$this->provider         = ! empty( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : null;
		$this->provider_subject = ! empty( $data['provider_subject'] ) ? (string) $data['provider_subject'] : null;
		$this->user_id          = ! empty( $data['user_id'] ) ? (int) $data['user_id'] : null;
		$this->is_new_user      = ! empty( $data['is_new_user'] );
		$this->is_linking       = ! empty( $data['is_linking'] );
		$this->email            = ! empty( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : null;
		$this->email_verified   = ! empty( $data['email_verified'] );
		$this->phone            = ! empty( $data['phone'] ) ? (string) $data['phone'] : null;
		$this->phone_verified   = ! empty( $data['phone_verified'] );
		$this->intended_url     = (string) ( $data['intended_url'] ?? '' );
		$this->in_place         = ! empty( $data['in_place'] );
		$this->correlation_id   = ! empty( $data['correlation_id'] ) ? (string) $data['correlation_id'] : bin2hex( random_bytes( 16 ) );
	}
}
