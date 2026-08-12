<?php
/**
 * When the account's own security facts last changed.
 *
 * One class, so the meta key and the sentence that renders it cannot drift
 * apart. Before 17.6 nothing in the plugin recorded when a password was set:
 * three call sites wrote `user_pass` and none of them left a date behind, so the
 * security card could offer "Đổi" and nothing else.
 *
 * **Recorded at the writers, not through a hook.** `UserManager::apply_password
 * _hash()` writes through `$wpdb` directly and fires no WordPress event at all,
 * so a listener on `profile_update` or `wp_set_password` would miss the one
 * writer with nothing to listen to. A fitness rule ties the two together
 * instead: a file that writes a password and does not call this is a failing
 * suite.
 *
 * **A provisioned password is not a change.** `AccountProvisioner` writes a
 * 64-character random string for an account signing in through a provider, and
 * its holder has never seen it. "Đổi lần cuối 2 năm trước" about a password
 * nobody chose is the class of statement this phase exists to remove, so that
 * one writer is exempt — and the exemption is declared in the rule, at the call
 * site, rather than implied by its absence here.
 *
 * @package OmniWP
 */

namespace OmniWP\Security;

defined( 'ABSPATH' ) || exit;

final class SecurityMeta {

	const META_PASSWORD_CHANGED = '_OmniWP_password_changed_at';

	/**
	 * Note that the account holder just chose a password.
	 *
	 * GMT, matching every other timestamp this plugin stores.
	 */
	public static function record_password_change( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		update_user_meta( $user_id, self::META_PASSWORD_CHANGED, current_time( 'mysql', true ) );
	}

	/**
	 * The stored timestamp, or '' when there is none.
	 */
	public static function password_changed_at( int $user_id ): string {
		return $user_id > 0 ? (string) get_user_meta( $user_id, self::META_PASSWORD_CHANGED, true ) : '';
	}

	/**
	 * How long ago, in words, or '' when nothing was recorded.
	 *
	 * **'' is a designed answer, not a fallback.** Every account that exists on
	 * the day this ships has no stored timestamp, and "chưa rõ" is the truth for
	 * all of them. The card renders the action without an age rather than
	 * guessing one — a date invented from `user_registered` would be wrong for
	 * exactly the people most likely to read it.
	 *
	 * Own strings rather than `human_time_diff()`, which is translated by
	 * WordPress core: on a site whose core is English that function turns this
	 * row into "Mật khẩu · đổi lần cuối 3 months trước".
	 */
	public static function describe_password_age( int $user_id ): string {
		$stored = self::password_changed_at( $user_id );

		if ( '' === $stored ) {
			return '';
		}

		$then = strtotime( $stored . ' UTC' );

		if ( ! $then ) {
			return '';
		}

		$seconds = time() - $then;

		// A clock that has gone backwards — a restored backup, a corrected server
		// time — reads as today rather than as a negative age.
		if ( $seconds < DAY_IN_SECONDS ) {
			return __( 'hôm nay', 'omniwp' );
		}

		$days = (int) floor( $seconds / DAY_IN_SECONDS );

		if ( $days < 30 ) {
			/* translators: %d: number of days. */
			return sprintf( _n( '%d ngày trước', '%d ngày trước', $days, 'omniwp' ), $days );
		}

		if ( $days < 365 ) {
			$months = (int) floor( $days / 30 );

			/* translators: %d: number of months. */
			return sprintf( _n( '%d tháng trước', '%d tháng trước', $months, 'omniwp' ), $months );
		}

		$years = (int) floor( $days / 365 );

		/* translators: %d: number of years. */
		return sprintf( _n( '%d năm trước', '%d năm trước', $years, 'omniwp' ), $years );
	}
}
