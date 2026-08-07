<?php
/**
 * The set of identity namespaces this site understands.
 *
 * Replaces the `id_mode` setting, whose three hard-coded values
 * (phone_only / email_only / both) cannot express a fourth channel. Enablement
 * becomes per channel, so adding one does not require a new setting shape.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

use SmartLogin\Identity\Channels\FederatedChannel;
use SmartLogin\Identity\Channels\IdentityChannel;
use SmartLogin\Identity\Channels\MailChannel;
use SmartLogin\Identity\Channels\PhoneChannel;
use SmartLogin\Settings;

defined( 'ABSPATH' ) || exit;

final class ChannelRegistry {

	/** @var array<string,IdentityChannel> */
	private array $channels = array();

	/**
	 * @param IdentityChannel[]|null $channels Defaults to the built-in set.
	 */
	public function __construct( ?array $channels = null ) {
		foreach ( $channels ?? self::defaults() as $channel ) {
			if ( $channel instanceof IdentityChannel ) {
				$this->register( $channel );
			}
		}
	}

	/**
	 * @return IdentityChannel[]
	 */
	public static function defaults(): array {
		return array(
			new PhoneChannel(),
			new MailChannel(),
			new FederatedChannel( 'google', __( 'Google', 'smart-login' ) ),
		);
	}

	public function register( IdentityChannel $channel ): void {
		$this->channels[ $channel->id() ] = $channel;
	}

	public function get( string $id ): ?IdentityChannel {
		return $this->channels[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * @return array<string,IdentityChannel>
	 */
	public function all(): array {
		return $this->channels;
	}

	/**
	 * @return array<string,IdentityChannel>
	 */
	public function enabled(): array {
		$out = array();

		foreach ( $this->channels as $id => $channel ) {
			if ( $this->is_enabled( (string) $id ) ) {
				$out[ $id ] = $channel;
			}
		}

		return $out;
	}

	public function is_enabled( string $id ): bool {
		$id         = sanitize_key( $id );
		$configured = Settings::get( 'channels.enabled', null );

		if ( is_array( $configured ) ) {
			$enabled = in_array( $id, $configured, true );
		} else {
			// Phase 4 introduces the `channels_enabled` setting. Until it exists,
			// derive from the legacy flags so behaviour is unchanged.
			switch ( $id ) {
				case PhoneChannel::ID:
					$enabled = Settings::phone_enabled();
					break;
				case MailChannel::ID:
					$enabled = Settings::email_enabled();
					break;
				case 'google':
					$enabled = Settings::is_on( 'providers.google.enabled' );
					break;
				default:
					// A channel registered by third-party code has no legacy flag
					// to derive from. It opts in through the filter below.
					$enabled = false;
			}
		}

		/**
		 * Whether an identity channel is available on this site.
		 *
		 * @param bool   $enabled
		 * @param string $id
		 */
		return (bool) apply_filters( 'smart_login_channel_enabled', $enabled, $id );
	}

	/**
	 * The safe path from raw input to a Claim: normalise, validate, wrap.
	 *
	 * Returns Claim::none() for anything the channel rejects, so a caller can
	 * check is_empty() instead of distinguishing "unknown channel" from
	 * "malformed subject" — neither is actionable by the user differently.
	 */
	public function claim( string $channel_id, string $raw ): Claim {
		$channel = $this->get( $channel_id );

		if ( ! $channel ) {
			return Claim::none();
		}

		$subject = $channel->normalize( $raw );

		if ( '' === $subject || ! $channel->is_valid( $subject ) ) {
			return Claim::none();
		}

		return Claim::canonical( $channel->id(), $subject );
	}

	/**
	 * Which enabled, self-asserted channel does this raw input belong to?
	 *
	 * Used by a single login/register field that accepts either a phone number or
	 * an email address. Federated channels are excluded: their subjects never
	 * come from a text box.
	 */
	public function claim_any( string $raw ): Claim {
		foreach ( $this->enabled() as $channel ) {
			if ( ! $channel->is_self_asserted() ) {
				continue;
			}

			$claim = $this->claim( $channel->id(), $raw );

			if ( ! $claim->is_empty() ) {
				return $claim;
			}
		}

		return Claim::none();
	}
}
