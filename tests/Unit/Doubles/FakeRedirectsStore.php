<?php
namespace Seonix\Tests\Unit\Doubles;

use Seonix_Redirects_Store;

/**
 * Store double for the admin screens: the rule list and the count behind the
 * Redirects tab badge.
 *
 * Extends the real store because that is what the constructors type-hint.
 * Passing null for $wpdb is safe as long as every method a screen reaches for
 * is overridden here — anything else falls through to SQL and fails loudly,
 * which is the intent.
 *
 * @param array<int,array<string,mixed>> $items
 */
final class FakeRedirectsStore extends Seonix_Redirects_Store {

	/** @var int */
	private $active;

	/** @var array<int,array<string,mixed>> */
	private $items;

	public function __construct( int $active = 0, array $items = array() ) {
		parent::__construct( null );
		$this->active = $active;
		$this->items  = $items;
	}

	public function count_active(): int {
		return $this->active;
	}

	public function get_items(): array {
		return $this->items;
	}
}
