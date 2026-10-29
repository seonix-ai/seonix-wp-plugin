<?php
namespace Seonix\Tests\Fakes;

/**
 * Rank Math's settings writer with its parameters reordered — the shape
 * clear_pagination_noindex must refuse instead of calling positionally, since
 * its titles array would then land in another settings group.
 */
final class DriftedRankMathHelper {

	public static function update_all_settings( $general, $sitemap, $titles ) {
		throw new \LogicException( 'A settings writer with a reordered signature must never be called.' );
	}
}
