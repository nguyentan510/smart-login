<?php
/**
 * Provider-normalised identity. Tokens never live in this object.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth\Providers;

defined( 'ABSPATH' ) || exit;

final class ProviderIdentity {

	public string $provider;
	public string $subject;
	public string $email;
	public bool $email_verified;
	public string $phone;
	public bool $phone_verified;
	public string $display_name;
	public string $avatar;
	public array $claims;

	public function __construct( array $data ) {
		$this->provider       = sanitize_key( (string) ( $data['provider'] ?? '' ) );
		$this->subject        = trim( (string) ( $data['subject'] ?? '' ) );
		$this->email          = ! empty( $data['email'] ) ? strtolower( sanitize_email( (string) $data['email'] ) ) : '';
		$this->email_verified = ! empty( $data['email_verified'] );
		$this->phone          = trim( (string) ( $data['phone'] ?? '' ) );
		$this->phone_verified = ! empty( $data['phone_verified'] );
		$this->display_name   = sanitize_text_field( (string) ( $data['display_name'] ?? '' ) );
		$this->avatar         = ! empty( $data['avatar'] ) ? esc_url_raw( (string) $data['avatar'] ) : '';
		$this->claims         = is_array( $data['claims'] ?? null ) ? $data['claims'] : array();
	}
}
