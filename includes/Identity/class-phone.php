<?php
/**
 * Phone number normalisation, validation and masking.
 *
 * Canonical storage format is E.164 without the leading plus: 84969789475.
 * That matches what the account screen displays and keeps user_login clean.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

class Phone {

	/**
	 * Valid Vietnamese mobile prefixes, expressed on the 9-digit national
	 * significant number (i.e. after dropping the trunk "0").
	 *
	 *  3[2-9]  Viettel      5[2689] Vietnamobile/Reddi
	 *  7[06-9] Mobifone     8[1-9]  Vinaphone/Itel
	 *  9[0-9]  all carriers
	 */
	const VN_MOBILE_NSN = '/^(3[2-9]|5[2689]|7[06-9]|8[1-9]|9[0-9])[0-9]{7}$/';

	/**
	 * Normalise arbitrary user input into canonical E.164 digits.
	 *
	 * Accepts: 0969789475, 969789475, +84 969 789 475, 0084-969-789-475, 84.969.789.475
	 *
	 * @param string      $input        Raw input.
	 * @param string|null $country_code Defaults to the configured country code.
	 * @return string Canonical digits, or '' when the input cannot be interpreted.
	 */
	public static function normalize( string $input, ?string $country_code = null ): string {
		$cc = $country_code ?? (string) Settings::get( 'identity.country_code', '84' );
		$cc = preg_replace( '/[^0-9]/', '', $cc );

		$raw = trim( $input );
		if ( '' === $raw ) {
			return '';
		}

		$had_plus = ( 0 === strpos( $raw, '+' ) );
		$digits   = preg_replace( '/[^0-9]/', '', $raw );

		if ( '' === $digits ) {
			return '';
		}

		// International prefix written as 00.
		if ( ! $had_plus && 0 === strpos( $digits, '00' ) ) {
			$digits   = substr( $digits, 2 );
			$had_plus = true;
		}

		if ( $had_plus ) {
			return self::finalize( $digits );
		}

		// Trunk prefix: 0969789475 -> 84969789475.
		if ( 0 === strpos( $digits, '0' ) ) {
			return self::finalize( $cc . ltrim( $digits, '0' ) );
		}

		// Already carries the country code.
		if ( 0 === strpos( $digits, $cc ) && strlen( $digits ) > strlen( $cc ) ) {
			return self::finalize( $digits );
		}

		// Bare national significant number: 969789475.
		return self::finalize( $cc . $digits );
	}

	/**
	 * Length sanity check shared by every branch above.
	 */
	private static function finalize( string $digits ): string {
		// E.164 allows at most 15 digits including the country code.
		if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Is this a plausible mobile number we are willing to send an SMS to?
	 *
	 * Only Vietnamese numbers get carrier-prefix validation; other country
	 * codes fall back to a generic length check so the plugin stays usable
	 * outside VN.
	 */
	public static function is_valid( string $canonical ): bool {
		if ( '' === $canonical || ! ctype_digit( $canonical ) ) {
			return false;
		}

		$cc = preg_replace( '/[^0-9]/', '', (string) Settings::get( 'identity.country_code', '84' ) );

		if ( ! self::country_allowed( $canonical ) ) {
			// The allowlist is asked first, because the question it answers comes
			// first: not "is this a well-formed number" but "is this a number this
			// site would ever legitimately message". A generic length check said
			// yes to every country on earth, which is the precondition SMS pumping
			// needs — codes aimed at a premium range in a country where the
			// attacker shares the termination revenue.
			//
			// It sets $valid rather than returning, so the single exit below stays
			// single. The Phase 4 rule in run-core-tests.php caught the first
			// version of this change doing otherwise, which is exactly what that
			// rule was written for.
			$valid = false;
		} elseif ( '84' === $cc && 0 === strpos( $canonical, '84' ) ) {
			// Carrier-prefix validation for Vietnamese numbers.
			$valid = (bool) preg_match( self::VN_MOBILE_NSN, substr( $canonical, 2 ) );
		} else {
			// Generic length check, so the plugin stays usable outside VN.
			$valid = strlen( $canonical ) >= 8 && strlen( $canonical ) <= 15;
		}

		/**
		 * Allow site owners to plug in their own numbering plan.
		 *
		 * Applies to every country code including the default 84, and to numbers
		 * the allowlist rejected — a filter that can only tighten is not the last
		 * word. The Vietnamese branch used to return before reaching this filter,
		 * which made the documented hook dead on the one configuration almost
		 * every site uses.
		 *
		 * @param bool   $valid
		 * @param string $canonical
		 */
		return (bool) apply_filters( 'smart_login_phone_is_valid', $valid, $canonical );
	}

	/**
	 * Country codes this site is willing to send a code to.
	 *
	 * An empty setting means **only the configured default country code**, not
	 * "anything". That makes the safe reading the default one and needs no
	 * migration: on a site left at `84` the behaviour is what it already was.
	 * The settings help text says this outright — an empty field that silently
	 * means "restricted" is only safe if the screen admits it.
	 *
	 * @return string[] Digit-only codes, never empty.
	 */
	public static function allowed_codes(): array {
		$configured = (string) Settings::get( 'identity.allowed_country_codes', '' );
		$codes      = array();

		foreach ( preg_split( '/[\s,;]+/', $configured ) ?: array() as $candidate ) {
			$digits = preg_replace( '/[^0-9]/', '', (string) $candidate );

			if ( '' !== $digits ) {
				$codes[] = $digits;
			}
		}

		if ( ! $codes ) {
			$default = preg_replace( '/[^0-9]/', '', (string) Settings::get( 'identity.country_code', '84' ) );
			$codes   = '' !== $default ? array( $default ) : array( '84' );
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Does this canonical number begin with an allowed country code?
	 *
	 * No prefix table. E.164 country codes are one to three digits and
	 * variable-length, so resolving "which country is this" properly would need
	 * one — but that is not the question. The question is whether the number
	 * starts with a code the operator listed, and a prefix comparison answers
	 * exactly that with nothing to keep up to date.
	 */
	private static function country_allowed( string $canonical ): bool {
		foreach ( self::allowed_codes() as $code ) {
			if ( 0 === strpos( $canonical, $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does this string look like a phone number rather than an email?
	 */
	public static function looks_like_phone( string $input ): bool {
		if ( false !== strpos( $input, '@' ) ) {
			return false;
		}

		return (bool) preg_match( '/^[\s\+\-\.\(\)0-9]+$/', trim( $input ) );
	}

	/**
	 * Render canonical digits back into the local format users recognise.
	 * 84969789475 -> 0969789475
	 */
	public static function to_local( string $canonical ): string {
		$cc = preg_replace( '/[^0-9]/', '', (string) Settings::get( 'identity.country_code', '84' ) );

		if ( '' !== $cc && 0 === strpos( $canonical, $cc ) ) {
			return '0' . substr( $canonical, strlen( $cc ) );
		}

		return $canonical;
	}

	/**
	 * 84969789475 -> "096••••475" for the OTP screen.
	 */
	public static function mask( string $canonical ): string {
		$local = self::to_local( $canonical );
		$len   = strlen( $local );

		if ( $len <= 6 ) {
			return str_repeat( '•', max( 0, $len - 2 ) ) . substr( $local, -2 );
		}

		return substr( $local, 0, 3 ) . str_repeat( '•', $len - 6 ) . substr( $local, -3 );
	}

	/**
	 * Mask an email: nguyen@example.com -> ng••••@example.com
	 */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at ) {
			return '•••';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		$keep   = min( 2, strlen( $local ) );

		return substr( $local, 0, $keep ) . str_repeat( '•', max( 2, strlen( $local ) - $keep ) ) . $domain;
	}
}
