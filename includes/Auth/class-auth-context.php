<?php
/**
 * Immutable description of the proof used to authenticate a visitor.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

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
		$this->correlation_id   = ! empty( $data['correlation_id'] ) ? (string) $data['correlation_id'] : bin2hex( random_bytes( 16 ) );
	}
}
