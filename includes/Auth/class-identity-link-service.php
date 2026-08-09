<?php
/**
 * List and remove the identities attached to an account.
 *
 * The repository has been able to do this since Phase 2, but nothing called it:
 * the UI offered "link" unconditionally and never showed what was already linked,
 * so a user could accumulate connections and never remove one. That made the
 * architecture's own promise — "only when the user wants to change provider does
 * anything change" — unimplementable.
 *
 * Two rules govern removal, and both fail closed.
 *
 * The **orphan guard**: an account must keep at least one identity. Since
 * user_login is opaque (see docs/identity-model.md §3), a password is not a way
 * back in on its own — there is no identifier left to type. Removing the last
 * identity would lock the owner out permanently with no recovery path, so it is
 * refused rather than confirmed.
 *
 * **Re-authentication**: unlinking is how an attacker with a borrowed session
 * would detach a victim's real provider before attaching their own, so it
 * requires the account password rather than just a nonce.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Auth;

use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Claim;
use SmartLogin\Identity\ChannelRegistry;
use SmartLogin\Identity\IdentityDirectory;
use SmartLogin\Identity\IdentityHistory;
use SmartLogin\Identity\IdentityRecord;
use SmartLogin\Security\AuditLog;
use SmartLogin\Security\RateLimiter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class IdentityLinkService {

	private IdentityDirectory $directory;
	private ChannelRegistry $channels;

	public function __construct( ?IdentityDirectory $directory = null ) {
		$this->directory = $directory ?? new IdentityDirectory();
		$this->channels  = $this->directory->channels();
	}

	/**
	 * Everything currently linked to an account, ready for display.
	 *
	 * Subjects are masked. A profile screen is a screen-sharing hazard, and the
	 * full value is of no use to the person who already owns it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function linked( int $user_id ): array {
		$out = array();

		foreach ( $this->directory->for_user( $user_id ) as $record ) {
			$channel = $this->channels->get( $record->channel() );

			$out[] = array(
				'channel'     => $record->channel(),
				'subject'     => $record->subject(),
				'masked'      => $channel ? $channel->mask( $record->subject() ) : '•••',
				'display'     => $this->display_for( $record ),
				'label'       => $channel ? $channel->label() : $record->channel(),
				'federated'   => $channel ? ! $channel->is_self_asserted() : false,
				'is_primary'  => $record->is_primary(),
				'linked_by'   => $record->linked_by(),
				'verified_at' => $record->verified_at(),
				'removable'   => $this->can_unlink( $user_id ),
			);
		}

		return $out;
	}

	/**
	 * Something a person can recognise, in place of the subject.
	 *
	 * A federated subject is the provider's `sub` claim, and its owner has never
	 * seen that number — not here and not at the provider. Masking it, which is
	 * what this screen used to render, produces `1171••••••`: an identifier for
	 * nobody, and two indistinguishable rows on an account with two links.
	 *
	 * Three levels, most identifying first, and the third always answers so a
	 * row can never come out blank:
	 *
	 *   1. the display name the provider sent
	 *   2. the provider address, masked — this one is a real identifier, so the
	 *      screen-sharing rule that applies to subjects applies to it
	 *   3. the date the link was made
	 *
	 * Resolved here rather than in the template because `meta_json` holding the
	 * provider's claims is a storage fact, and profile-summary renders the same
	 * partial. A second reader would be a second place to know it.
	 *
	 * **This is a link-time snapshot and it can go stale**, which is accepted
	 * deliberately: the row's subject is "which account you attached", and that
	 * is exactly the fact recorded at link time. See docs/sign-in-card.md,
	 * decision 5.
	 */
	private function display_for( IdentityRecord $record ): string {
		$meta = $record->meta();
		$name = trim( (string) ( $meta['name'] ?? '' ) );

		if ( '' !== $name ) {
			return $name;
		}

		$email = trim( (string) ( $meta['email'] ?? '' ) );

		if ( '' !== $email ) {
			$mail = $this->channels->get( MailChannel::ID );

			return $mail ? $mail->mask( $email ) : $email;
		}

		$linked = strtotime( $record->verified_at() );

		if ( ! $linked ) {
			// Every record carries a verified_at — the column is NOT NULL — so
			// this is unreachable rather than merely unlikely. It returns the
			// label's own fallback instead of an empty cell, because a row with
			// no value at all reads as a rendering fault.
			return '—';
		}

		return sprintf(
			/* translators: %s: date the provider account was linked, d/m/Y. */
			__( 'Đã liên kết %s', 'smart-login' ),
			gmdate( 'd/m/Y', $linked )
		);
	}

	/**
	 * Whether the account has anything to spare.
	 */
	public function can_unlink( int $user_id ): bool {
		$remaining = $this->directory->identities()->count_for_user( $user_id );

		/**
		 * Allow removing the last identity anyway.
		 *
		 * Default false, and it should stay false for anything self-service: an
		 * account with no identities cannot be signed into or recovered, because
		 * user_login is opaque. Intended for an administrative tool that has
		 * another way to reach the owner.
		 *
		 * @param bool $allow
		 * @param int  $user_id
		 * @param int  $remaining
		 */
		if ( apply_filters( 'smart_login_allow_orphan_unlink', false, $user_id, $remaining ) ) {
			return $remaining >= 1;
		}

		return $remaining >= 2;
	}

	/**
	 * Detach one identity from an account.
	 *
	 * @param int    $user_id
	 * @param string $channel_id
	 * @param string $subject
	 * @param string $password   The account password, for re-authentication.
	 * @return true|WP_Error
	 */
	public function unlink( int $user_id, string $channel_id, string $subject, string $password ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return new WP_Error( 'smart_login_no_user', __( 'Không tìm thấy tài khoản.', 'smart-login' ) );
		}

		$claim = $this->channels->claim( $channel_id, $subject );

		if ( $claim->is_empty() ) {
			return new WP_Error( 'smart_login_bad_identity', __( 'Thông tin định danh không hợp lệ.', 'smart-login' ) );
		}

		// Ownership is the directory's answer, not the caller's assertion. A
		// request naming somebody else's identity is refused with the same message
		// as one naming a nonexistent identity, so it reveals nothing.
		$resolution = $this->directory->resolve( $claim );

		if ( ! $resolution->has_owner() || $resolution->user_id() !== $user_id ) {
			return new WP_Error(
				'smart_login_not_linked',
				__( 'Liên kết này không thuộc về tài khoản của bạn.', 'smart-login' )
			);
		}

		if ( ! $this->can_unlink( $user_id ) ) {
			return new WP_Error(
				'smart_login_last_identity',
				__( 'Đây là cách đăng nhập duy nhất của bạn. Hãy thêm số điện thoại, email hoặc một liên kết khác trước khi bỏ liên kết này.', 'smart-login' )
			);
		}

		$reauth = $this->reauthenticate( $user, $password );

		if ( is_wp_error( $reauth ) ) {
			return $reauth;
		}

		if ( $this->directory->retire( $claim, 'unlinked_by_user' ) !== $user_id ) {
			return new WP_Error( 'smart_login_unlink_failed', __( 'Không thể bỏ liên kết. Vui lòng thử lại.', 'smart-login' ) );
		}

		AuditLog::record(
			AuditLog::PROVIDER_UNLINKED,
			RateLimiter::mask_identity( $claim->subject() ),
			array( 'channel' => $claim->channel() ),
			$user_id
		);

		/**
		 * An identity was detached from an account.
		 *
		 * @param int   $user_id
		 * @param Claim $claim
		 */
		do_action( 'smart_login_identity_unlinked', $user_id, $claim );

		return true;
	}

	/**
	 * Confirm the person asking is the account owner, not a borrowed session.
	 *
	 * Password only. An OTP challenge would be the natural alternative for an
	 * account whose owner has never set a password — a provider-only signup — but
	 * such an account has exactly one identity and is already refused by the
	 * orphan guard above, so the gap does not arise in practice. The one corner
	 * left is an account holding two federated identities and no contact channel:
	 * it cannot re-authenticate and must add a password or a contact first. That
	 * fails closed, which is the right direction.
	 *
	 * @param \WP_User $user
	 * @return true|WP_Error
	 */
	private function reauthenticate( $user, string $password ) {
		if ( '' === $password ) {
			return new WP_Error(
				'smart_login_password_required',
				__( 'Vui lòng nhập mật khẩu để xác nhận.', 'smart-login' )
			);
		}

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			AuditLog::record( AuditLog::LOGIN_FAILED, '', array( 'context' => 'unlink_reauth' ), (int) $user->ID );

			return new WP_Error( 'smart_login_bad_password', __( 'Mật khẩu không đúng.', 'smart-login' ) );
		}

		return true;
	}

	/**
	 * Which providers are not linked yet, so the UI can offer only those.
	 *
	 * @param int      $user_id
	 * @param string[] $available Provider ids the site has configured.
	 * @return string[]
	 */
	public function unlinked_providers( int $user_id, array $available ): array {
		$linked = array();

		foreach ( $this->directory->for_user( $user_id ) as $record ) {
			$linked[ $record->channel() ] = true;
		}

		return array_values( array_filter( $available, static fn( string $id ): bool => ! isset( $linked[ $id ] ) ) );
	}

	/**
	 * Recent lifecycle events for one identity, for a support screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function history( string $channel_id, string $subject ): array {
		$claim = $this->channels->claim( $channel_id, $subject );

		if ( $claim->is_empty() ) {
			return array();
		}

		return $this->directory->identities()->history()->for_subject( $claim );
	}
}
