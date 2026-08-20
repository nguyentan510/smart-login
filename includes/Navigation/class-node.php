<?php
/**
 * One entry in a navigation tree.
 *
 * A node is deliberately not "a term". docs/navigation.md §3.2 records why: the
 * reference stores put a brand-logo grid and a promo banner inside the same panel
 * as the category columns, and a model that only knows terms cannot express
 * either — which is discovered late, after nodes are stored, and paid for with a
 * migration.
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

defined( 'ABSPATH' ) || exit;

final class Node {

	/**
	 * What a node can be. This list is the whole vocabulary.
	 *
	 * `term`  — a taxonomy term, optionally carrying a visual and a count.
	 * `link`  — an arbitrary URL.
	 * `group` — a labelled column heading that owns its children.
	 * `block` — a named render callback: brand grid, banner, promo.
	 */
	public const TYPES = array( 'term', 'link', 'group', 'block' );

	/** The device axis. Resolved in CSS, never on the server — §3.4. */
	public const DEVICES = array( 'all', 'desktop', 'mobile' );

	/** @var string */
	private $id;

	/** @var string */
	private $type;

	/** @var string */
	private $label;

	/** @var string */
	private $url;

	/** @var Node[] */
	private $children;

	/** @var array<string,mixed> Image, colour, icon — whatever the projection can use. */
	private $visual;

	/** @var string */
	private $badge;

	/** @var string One of DEVICES. */
	private $devices;

	/** @var string Provider id that produced this node. */
	private $source;

	/** @var array<string,mixed> */
	private $meta;

	/**
	 * @param array<string,mixed> $spec
	 */
	private function __construct( array $spec ) {
		$this->id       = (string) ( $spec['id'] ?? '' );
		$this->type     = (string) ( $spec['type'] ?? 'link' );
		$this->label    = (string) ( $spec['label'] ?? '' );
		$this->url      = (string) ( $spec['url'] ?? '' );
		$this->children = array();
		$this->visual   = (array) ( $spec['visual'] ?? array() );
		$this->badge    = (string) ( $spec['badge'] ?? '' );
		$this->devices  = self::normalise_devices( $spec['devices'] ?? 'all' );
		$this->source   = (string) ( $spec['source'] ?? '' );
		$this->meta     = (array) ( $spec['meta'] ?? array() );

		foreach ( (array) ( $spec['children'] ?? array() ) as $child ) {
			$this->children[] = $child instanceof self ? $child : self::make( (array) $child );
		}
	}

	/**
	 * Build a node, refusing a type outside the vocabulary.
	 *
	 * Type throws and device does not, and the asymmetry is the point: `type` is
	 * set by a provider, so a bad one is a programming error and should stop the
	 * build. `devices` arrives from nav-menu-item meta a human typed years ago,
	 * where the honest answer to an unrecognised value is "show it to everyone"
	 * rather than a fatal on a shop's live menu.
	 *
	 * @param array<string,mixed> $spec
	 *
	 * @throws \InvalidArgumentException When `type` is not in TYPES.
	 */
	public static function make( array $spec ): self {
		$type = (string) ( $spec['type'] ?? 'link' );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown navigation node type "%s".', esc_html( $type ) )
			);
		}

		return new self( $spec );
	}

	private static function normalise_devices( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, self::DEVICES, true ) ? $value : 'all';
	}

	/**
	 * A copy of this node carrying a different child list.
	 *
	 * Tree::of() needs to rebuild a branch when it caps depth, and a node that
	 * could be mutated in place would let a projection edit the tree another
	 * projection is holding.
	 *
	 * @param Node[] $children
	 */
	public function with_children( array $children ): self {
		$clone           = clone $this;
		$clone->children = array_values(
			array_filter(
				$children,
				static function ( $child ): bool {
					return $child instanceof self;
				}
			)
		);

		return $clone;
	}

	public function id(): string {
		return $this->id;
	}

	public function type(): string {
		return $this->type;
	}

	public function label(): string {
		return $this->label;
	}

	public function url(): string {
		return $this->url;
	}

	/** @return Node[] */
	public function children(): array {
		return $this->children;
	}

	public function has_children(): bool {
		return array() !== $this->children;
	}

	/** @return array<string,mixed> */
	public function visual(): array {
		return $this->visual;
	}

	public function badge(): string {
		return $this->badge;
	}

	public function devices(): string {
		return $this->devices;
	}

	public function source(): string {
		return $this->source;
	}

	/**
	 * @param string $key
	 * @param mixed  $fallback
	 *
	 * @return mixed
	 */
	public function meta( string $key, $fallback = null ) {
		return $this->meta[ $key ] ?? $fallback;
	}

	/**
	 * The class a projection puts on the element for the device axis.
	 *
	 * Returned as a class rather than a boolean because the alternative — asking
	 * here whether to render — is exactly the server-side device decision §3.4
	 * forbids. The node always renders; CSS decides whether it is seen.
	 */
	public function device_class(): string {
		return 'all' === $this->devices ? '' : 'ow-nav--only-' . $this->devices;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'type'     => $this->type,
			'label'    => $this->label,
			'url'      => $this->url,
			'visual'   => $this->visual,
			'badge'    => $this->badge,
			'devices'  => $this->devices,
			'source'   => $this->source,
			'meta'     => $this->meta,
			'children' => array_map(
				static function ( self $child ): array {
					return $child->to_array();
				},
				$this->children
			),
		);
	}
}
