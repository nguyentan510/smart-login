<?php
/**
 * Makes the Users screen usable now that user_login is opaque.
 *
 * This is the agreed cost of the structural fix in docs/identity-model.md §3:
 * `ow_9f2c…` tells support staff nothing. Two additions pay it back —
 * a column showing the account's real identities, and a search that matches
 * them, so "find the customer who called about 0969789475" still works.
 *
 * @package OmniWP
 */

namespace OmniWP\Admin;

use OmniWP\Identity\ChannelRegistry;
use OmniWP\Identity\IdentityDirectory;
use OmniWP\Installer;

defined( 'ABSPATH' ) || exit;

final class UsersColumn {

	const COLUMN = 'OmniWP_identity';

	private IdentityDirectory $directory;
	private ChannelRegistry $channels;

	public function __construct( ?IdentityDirectory $directory = null ) {
		$this->directory = $directory ?? new IdentityDirectory();
		$this->channels  = $this->directory->channels();
	}

	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_action( 'pre_get_users', array( $this, 'search_by_identity' ) );
	}

	/**
	 * @param array $columns
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Định danh chính', 'omniwp' );

		return $columns;
	}

	/**
	 * @param string $output
	 * @param string $column
	 * @param int    $user_id
	 * @return string
	 */
	public function render_column( $output, $column, $user_id ) {
		if ( self::COLUMN !== $column ) {
			return $output;
		}

		$lines = array();

		foreach ( $this->directory->for_user( (int) $user_id ) as $record ) {
			$channel = $this->channels->get( $record->channel() );
			$label   = $channel ? $channel->label() : $record->channel();

			// Masked, not raw. An admin list is a screen-sharing hazard, and the
			// full value is one click away on the profile itself.
			$subject = $channel ? $channel->mask( $record->subject() ) : '•••';

			$lines[] = sprintf(
				'<span class="sl-identity"><strong>%1$s</strong> %2$s</span>',
				esc_html( $label ),
				esc_html( $subject )
			);
		}

		if ( ! $lines ) {
			return '<span class="sl-identity sl-identity--none">' . esc_html__( '— chưa có —', 'omniwp' ) . '</span>';
		}

		return implode( '<br />', $lines );
	}

	/**
	 * Let the Users search box match an identity subject.
	 *
	 * The subject is normalised through its channel first, so staff can paste
	 * `0969789475` and match the stored `84969789475`.
	 *
	 * Implementation note. The obvious approach — appending
	 * `OR ID IN (…)` to WP_User_Query::$query_where — is wrong: AND binds tighter
	 * than OR, so `role_clause AND search_clause OR ID IN (…)` returns the
	 * identity matches regardless of any active role or site filter. Narrowing
	 * with `include` instead keeps every other filter intact and needs no SQL
	 * surgery.
	 *
	 * Trade-off, deliberate: when a term resolves to an identity, the name and
	 * email matches are dropped in favour of the exact owner. A phone number is
	 * essentially never also a meaningful name, and an exact answer is what the
	 * person searching wanted.
	 *
	 * @param \WP_User_Query $query
	 */
	public function search_by_identity( $query ): void {
		if ( ! is_admin() || ! current_user_can( 'list_users' ) ) {
			return;
		}

		// The Users screen wraps the term in wildcards; identity lookups are exact.
		$term = trim( trim( (string) $query->get( 'search' ) ), '*' );

		if ( '' === $term ) {
			return;
		}

		$ids = array();

		foreach ( $this->channels->all() as $channel ) {
			$claim = $this->channels->claim( $channel->id(), $term );

			if ( $claim->is_empty() ) {
				continue;
			}

			$record = $this->directory->identities()->find( $claim );

			if ( $record ) {
				$ids[] = $record->user_id();
			}
		}

		if ( ! $ids ) {
			return;
		}

		$query->set( 'search', '' );
		$query->set( 'include', array_values( array_unique( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Diagnostics for the settings screen: is the identity table populated?
	 */
	public static function identity_count(): int {
		global $wpdb;

		$table = Installer::identities_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
