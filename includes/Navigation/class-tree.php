<?php
/**
 * A depth-capped list of navigation nodes.
 *
 * The cap lives here rather than in each renderer for the reason the component
 * catalog exists at all: three renderers each enforcing their own depth is three
 * lists that have to agree by hand, and one of them will not.
 *
 * @package OmniWP
 */

namespace OmniWP\Navigation;

defined( 'ABSPATH' ) || exit;

final class Tree {

	/**
	 * F1 → F2 → F3, and no further.
	 *
	 * Every reference store stops at three and puts a `Xem tất cả` link at the
	 * foot of each F2 column instead of a fourth level, because a panel that
	 * scrolls is a panel nobody reaches the bottom of.
	 */
	public const MAX_DEPTH = 3;

	/** @var Node[] */
	private $roots;

	/**
	 * @param Node[] $roots
	 */
	private function __construct( array $roots ) {
		$this->roots = $roots;
	}

	/**
	 * Build a tree, truncating anything deeper than MAX_DEPTH.
	 *
	 * Truncate rather than throw. A real shop's category tree being four deep is
	 * ordinary; refusing to render it would turn a display decision into an
	 * outage, and the levels that fit are still useful.
	 *
	 * @param Node[]|array<int,array<string,mixed>> $nodes
	 */
	public static function of( array $nodes ): self {
		return new self( self::cap( $nodes, 1 ) );
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * @param Node[]|array<int,array<string,mixed>> $nodes
	 *
	 * @return Node[]
	 */
	private static function cap( array $nodes, int $depth ): array {
		$capped = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof Node ) {
				$node = Node::make( (array) $node );
			}

			$children = $depth >= self::MAX_DEPTH
				? array()
				: self::cap( $node->children(), $depth + 1 );

			$capped[] = $node->with_children( $children );
		}

		return $capped;
	}

	/** @return Node[] */
	public function roots(): array {
		return $this->roots;
	}

	public function is_empty(): bool {
		return array() === $this->roots;
	}

	/**
	 * Visit every node, deepest last, with the depth it was found at.
	 *
	 * @param callable $visitor fn( Node $node, int $depth ): void
	 */
	public function walk( callable $visitor ): void {
		self::visit( $this->roots, 1, $visitor );
	}

	/**
	 * @param Node[] $nodes
	 */
	private static function visit( array $nodes, int $depth, callable $visitor ): void {
		foreach ( $nodes as $node ) {
			$visitor( $node, $depth );
			self::visit( $node->children(), $depth + 1, $visitor );
		}
	}

	/** @return Node[] */
	public function flatten(): array {
		$flat = array();

		$this->walk(
			static function ( Node $node ) use ( &$flat ): void {
				$flat[] = $node;
			}
		);

		return $flat;
	}

	public function find( string $id ): ?Node {
		$found = null;

		$this->walk(
			static function ( Node $node ) use ( $id, &$found ): void {
				if ( null === $found && $node->id() === $id ) {
					$found = $node;
				}
			}
		);

		return $found;
	}

	public function count(): int {
		return count( $this->flatten() );
	}

	/** The deepest level actually present, 0 for an empty tree. */
	public function depth(): int {
		$deepest = 0;

		$this->walk(
			static function ( Node $node, int $depth ) use ( &$deepest ): void {
				$deepest = max( $deepest, $depth );
			}
		);

		return $deepest;
	}

	/** @return array<int,array<string,mixed>> */
	public function to_array(): array {
		return array_map(
			static function ( Node $node ): array {
				return $node->to_array();
			},
			$this->roots
		);
	}
}
