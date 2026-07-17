<?php
namespace Seonix\Tests\Unit\Doubles;

use Seonix_Redirects_Log;

/**
 * Log double for the admin screen: canned rows in, deletions recorded.
 *
 * Extends the real log because that is what the admin constructor type-hints.
 * Every method the screen or the noise-dismiss handler reaches for is
 * overridden; anything else falls through to SQL on a null wpdb and fails
 * loudly, which is the intent.
 */
final class FakeRedirectsLog extends Seonix_Redirects_Log {

	/** @var array<int,array<string,mixed>> */
	private $rows;

	/** @var int[] Ids handed to delete_ids(), in order. */
	public $deleted_ids = array();

	/** @param array<int,array<string,mixed>> $rows */
	public function __construct( array $rows = array() ) {
		parent::__construct( null );
		$this->rows = $rows;
	}

	public function get_top( int $limit = 100 ): array {
		return array_slice( $this->rows, 0, max( 1, $limit ) );
	}

	public function all(): array {
		return $this->rows;
	}

	public function count(): int {
		return count( $this->rows );
	}

	public function delete_ids( array $ids ): void {
		$this->deleted_ids = array_merge( $this->deleted_ids, array_map( 'intval', $ids ) );
	}
}
