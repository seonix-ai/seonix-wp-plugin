<?php
namespace Seonix\Tests\Fakes;

/**
 * Rank Math's settings writer grown a fourth required parameter — the shape
 * clear_pagination_noindex must refuse, since its three-argument call would
 * then fail with an ArgumentCountError.
 */
final class ExtraParamRankMathHelper {

	public static function update_all_settings( $general, $titles, $sitemap, $instant_indexing ) {
		throw new \LogicException( 'A settings writer with an extra required parameter must never be called.' );
	}
}
