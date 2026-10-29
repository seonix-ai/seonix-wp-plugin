<?php
/**
 * Test double for Rank Math's public settings writer.
 *
 * Production code changes a Rank Math site-wide setting only through
 * `RankMath\Helper::update_all_settings( $general, $titles, $sitemap )` — the
 * helper Rank Math's own importers use, which update_option()s every non-null
 * group. This fake keeps that signature and that effect, so a test backs it with
 * the same get_option/update_option stubs the code under test reads, and counts
 * calls so the no-op paths can be shown never to write.
 *
 * Unlike FakeYoast, loading it switches nothing else in the suite on:
 * RANK_MATH_VERSION stays undefined (Seonix_SEO_Engine, the schema emitter and
 * the meta bridge key on that constant), and only the clear_pagination_noindex
 * method looks for this class.
 */

namespace RankMath;

if ( ! class_exists( 'RankMath\\Helper' ) ) {
	class Helper {
		/** Number of update_all_settings() calls since the last reset(). */
		public static int $update_calls = 0;

		public static function reset(): void {
			self::$update_calls = 0;
		}

		public static function update_all_settings( $general, $titles, $sitemap ) {
			++self::$update_calls;

			if ( ! is_null( $general ) ) {
				update_option( 'rank-math-options-general', $general );
			}
			if ( ! is_null( $titles ) ) {
				update_option( 'rank-math-options-titles', $titles );
			}
			if ( ! is_null( $sitemap ) ) {
				update_option( 'rank-math-options-sitemap', $sitemap );
			}
		}
	}
}
