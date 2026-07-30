<?php
/**
 * Content writes that keep the post's public "last modified" date.
 *
 * SEO-fix methods repair markup (alt attributes, dead links, http:// asset
 * URLs) — they do not update the article for readers. WordPress stamps
 * post_modified on every wp_update_post(), and themes / feeds / schema
 * surface that date as "Updated on …". A bulk fix run therefore used to
 * re-stamp dozens of old posts in one day with no visible content change —
 * which reads as manufactured freshness to both users and search engines
 * (deep-scan fixes fan out to up to 200 posts per applied item).
 *
 * HOW: wp_insert_post() unconditionally overwrites post_modified with
 * current_time() on every update — caller-supplied values in $postarr are
 * ignored (verified against core; Trac #36595 was never merged). The only
 * reliable hook is the `wp_insert_post_data` filter, which runs after core
 * computes the dates and immediately before the DB write. The filter is
 * added just for the duration of the call and guards on the post ID, so a
 * nested wp_update_post() fired by some save_post hook for a DIFFERENT post
 * keeps its normal date bump.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seonix_Content_Write {

	/**
	 * wp_update_post() drop-in that preserves the post's modified date.
	 *
	 * @param array $postarr  Post fields including ID (same as wp_update_post).
	 * @param bool  $wp_error Whether to return a WP_Error on failure.
	 * @return int|WP_Error Post ID on success; 0 or WP_Error on failure
	 *                      (matches wp_update_post's signature).
	 */
	public static function update_preserving_modified( array $postarr, bool $wp_error = false ) {
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$current = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! ( $current instanceof WP_Post )
			|| empty( $current->post_modified_gmt )
			|| '0000-00-00 00:00:00' === $current->post_modified_gmt ) {
			return wp_update_post( $postarr, $wp_error );
		}

		$keep     = $current->post_modified;
		$keep_gmt = $current->post_modified_gmt;
		$filter   = static function ( $data, $filter_postarr ) use ( $post_id, $keep, $keep_gmt ) {
			// Only pin the dates of THE post this write targets — a nested
			// wp_update_post() from a save_post hook (another plugin touching
			// a different post) must keep its normal bump.
			if ( is_array( $data ) && is_array( $filter_postarr )
				&& (int) ( $filter_postarr['ID'] ?? 0 ) === $post_id ) {
				$data['post_modified']     = $keep;
				$data['post_modified_gmt'] = $keep_gmt;
			}
			return $data;
		};

		add_filter( 'wp_insert_post_data', $filter, PHP_INT_MAX, 2 );
		try {
			return wp_update_post( $postarr, $wp_error );
		} finally {
			remove_filter( 'wp_insert_post_data', $filter, PHP_INT_MAX );
		}
	}
}
