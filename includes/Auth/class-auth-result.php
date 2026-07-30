<?php
/**
 * Result returned after a WordPress session is issued.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

defined( 'ABSPATH' ) || exit;

final class AuthResult {

	public int $user_id;
	public bool $is_new_user;
	public string $auth_method;
	public ?string $provider;
	public ?string $provider_subject;
	public array $profile_status;
	public bool $needs_onboarding;
	public bool $needs_profile_gate;
	public string $redirect_url = '';

	public function __construct( int $user_id, AuthContext $context, array $profile_status ) {
		$this->user_id             = $user_id;
		$this->is_new_user         = $context->is_new_user;
		$this->auth_method         = $context->auth_method;
		$this->provider            = $context->provider;
		$this->provider_subject    = $context->provider_subject;
		$this->profile_status      = $profile_status;
		$this->needs_profile_gate  = ! empty( $profile_status['required_missing'] );
		$this->needs_onboarding    = $context->is_new_user;
	}
}
