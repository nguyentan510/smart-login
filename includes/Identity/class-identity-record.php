<?php
/**
 * One row of smartlogin_identities, as an object.
 *
 * A record can only be created from a VerifiedClaim, so an unproven subject has
 * no route into the table. from_row() is the read path used by the Phase 2
 * repository; to_row() is the write path.
 *
 * @package SmartLogin
 */

namespace SmartLogin\Identity;

defined( 'ABSPATH' ) || exit;

final class IdentityRecord {

	const BY_REGISTRATION = 'registration';
	const BY_OTP          = 'otp';
	const BY_OAUTH        = 'oauth';
	const BY_AUTO_EMAIL   = 'auto_email';
	const BY_ADMIN        = 'admin';

	private int $id;
	private int $user_id;
	private string $channel;
	private string $subject;
	private bool $is_primary;
	private string $verified_at;
	private string $linked_by;
	private array $meta;

	private function __construct( array $data ) {
		$this->id          = (int) ( $data['id'] ?? 0 );
		$this->user_id     = (int) ( $data['user_id'] ?? 0 );
		$this->channel     = sanitize_key( (string) ( $data['channel'] ?? '' ) );
		$this->subject     = trim( (string) ( $data['subject'] ?? '' ) );
		$this->is_primary  = ! empty( $data['is_primary'] );
		$this->verified_at = (string) ( $data['verified_at'] ?? '' );
		$this->linked_by   = sanitize_key( (string) ( $data['linked_by'] ?? '' ) );
		$this->meta        = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
	}

	/**
	 * Read path: hydrate from a database row.
	 *
	 * `meta_json` is decoded here so no caller has to know the column is JSON.
	 */
	public static function from_row( array $row ): self {
		$meta = array();

		if ( ! empty( $row['meta_json'] ) ) {
			$decoded = json_decode( (string) $row['meta_json'], true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			array(
				'id'          => $row['id'] ?? 0,
				'user_id'     => $row['user_id'] ?? 0,
				'channel'     => $row['channel'] ?? '',
				'subject'     => $row['subject'] ?? '',
				'is_primary'  => ! empty( $row['is_primary'] ),
				'verified_at' => $row['verified_at'] ?? '',
				'linked_by'   => $row['linked_by'] ?? '',
				'meta'        => $meta,
			)
		);
	}

	/**
	 * Write path: a record exists only where proof exists.
	 */
	public static function create( int $user_id, VerifiedClaim $claim, string $linked_by, bool $is_primary = false, array $meta = array() ): self {
		return new self(
			array(
				'user_id'     => $user_id,
				'channel'     => $claim->channel(),
				'subject'     => $claim->subject(),
				'is_primary'  => $is_primary,
				'verified_at' => $claim->verified_at(),
				'linked_by'   => $linked_by,
				'meta'        => $meta,
			)
		);
	}

	public function id(): int {
		return $this->id;
	}

	public function user_id(): int {
		return $this->user_id;
	}

	public function channel(): string {
		return $this->channel;
	}

	public function subject(): string {
		return $this->subject;
	}

	public function is_primary(): bool {
		return $this->is_primary;
	}

	public function verified_at(): string {
		return $this->verified_at;
	}

	public function linked_by(): string {
		return $this->linked_by;
	}

	public function meta(): array {
		return $this->meta;
	}

	public function claim(): Claim {
		return Claim::canonical( $this->channel, $this->subject );
	}

	/**
	 * Column map for $wpdb->insert(). `id` is omitted so the caller cannot
	 * accidentally overwrite an existing row through the insert path.
	 *
	 * @return array<string,mixed>
	 */
	public function to_row(): array {
		return array(
			'user_id'     => $this->user_id,
			'channel'     => $this->channel,
			'subject'     => $this->subject,
			'is_primary'  => $this->is_primary ? 1 : 0,
			'verified_at' => $this->verified_at,
			'linked_by'   => $this->linked_by,
			'meta_json'   => $this->meta ? wp_json_encode( $this->meta ) : null,
		);
	}
}
