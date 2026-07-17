<?php
/**
 * Fix method: broken_link.
 *
 * Replaces a broken URL with a working one inside a single post's post_content.
 * The Seonix backend is responsible for matching broken_url → new_url (via the
 * deterministic slug-similarity matcher, with optional AI fallback for low-
 * confidence cases). The plugin just executes the substitution it's told.
 *
 * Replacement strategy: boundary-anchored exact-URL replacement (see
 * replace_url_bounded). This catches URLs in href, src, and plain-text
 * mentions while refusing partial matches — replacing /foo never rewrites
 * /foo-bar, /foo/child, or the same path on a different host. The dry-run
 * reports the occurrence count so the user can review before applying.
 *
 * Idempotent: if the old_url no longer appears in the post, returns no_op.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Broken_Link implements Seonix_Fix_Method {

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'broken_link';
	}

	public function validate_params( array $params ) {
		if ( empty( $params['post_id'] ) || ! is_numeric( $params['post_id'] ) ) {
			return new WP_Error( 'missing_post_id', 'post_id is required.', array( 'status' => 400 ) );
		}
		if ( empty( $params['old_url'] ) ) {
			return new WP_Error( 'missing_old_url', 'old_url is required.', array( 'status' => 400 ) );
		}

		$mode = isset( $params['mode'] ) ? (string) $params['mode'] : 'rewrite';
		if ( 'rewrite' !== $mode && 'remove_link' !== $mode ) {
			return new WP_Error( 'invalid_mode', 'mode must be "rewrite" or "remove_link".', array( 'status' => 400 ) );
		}

		// remove_link skips new_url entirely — when the AI/backend can't find a
		// confident redirect target the right behaviour is to strip the <a>
		// wrapper and keep the inner text. Only rewrite mode needs new_url.
		if ( 'rewrite' === $mode ) {
			if ( empty( $params['new_url'] ) ) {
				return new WP_Error( 'missing_new_url', 'new_url is required.', array( 'status' => 400 ) );
			}
			if ( $params['old_url'] === $params['new_url'] ) {
				return new WP_Error( 'noop_params', 'old_url and new_url must differ.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	public function dry_run( array $params ) {
		$post = $this->load_post( (int) $params['post_id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$mode   = isset( $params['mode'] ) ? (string) $params['mode'] : 'rewrite';
		$result = ( 'remove_link' === $mode )
			? $this->describe_anchor_removal( $post, (string) $params['old_url'] )
			: $this->describe_replacement( $post, $params['old_url'], $params['new_url'] );

		// Builder preview: a page builder owns this post's layout, so apply()
		// will skip the primary write. Reflect that here instead of previewing a
		// change that won't happen. (Pure read — get_post_meta only; no DB write,
		// keeping dry_run side-effect free.)
		if ( Seonix_Builder_Detector::post_uses_builder( (int) $post->ID ) ) {
			$result['after']           = $result['before'];
			$result['no_op']           = true;
			$result['skipped_builder'] = true;
		}

		return $result;
	}

	public function apply( array $params ) {
		$post = $this->load_post( (int) $params['post_id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$mode = isset( $params['mode'] ) ? (string) $params['mode'] : 'rewrite';

		$result = ( 'remove_link' === $mode )
			? $this->describe_anchor_removal( $post, (string) $params['old_url'] )
			: $this->describe_replacement( $post, $params['old_url'], $params['new_url'] );

		$primary_no_op = ! empty( $result['no_op'] );

		// Builder guard: a page builder owns this post's layout in its own
		// postmeta, so rewriting post_content wouldn't change the rendered page
		// and a partial rewrite of the builder's serialized data can corrupt the
		// layout. Skip the primary write and leave the issue for manual handling;
		// deep mode still runs (it skips builder-owned posts row by row).
		$primary_is_builder = Seonix_Builder_Detector::post_uses_builder( (int) $post->ID );

		if ( $primary_no_op || $primary_is_builder ) {
			// Nothing to write on the primary post (already correct, or a builder
			// owns it) — we may still have deep rewrites in OTHER posts, so fall
			// through to deep mode.
			$primary_written = false;
		} else {
			$update = wp_update_post( array(
				'ID'           => (int) $post->ID,
				// wp_slash: wp_update_post() wp_unslash()es its input, so content
				// derived from the DB-read post_content must be re-slashed to keep
				// literal backslashes intact.
				'post_content' => wp_slash( $result['after']['post_content'] ),
			), true );

			if ( $update instanceof WP_Error || 0 === (int) $update ) {
				return new WP_Error( 'update_failed', 'wp_update_post returned an error or zero id.', array( 'status' => 500 ) );
			}
			$primary_written = true;
		}

		// Deep mode: also rewrite/strip the same broken URL in any OTHER post
		// that references it. Reusable blocks (post_type='wp_block'), template
		// parts, and other shared content can carry the same broken href and
		// would keep the page broken on every consumer page until fixed.
		$deep_capped = false;
		$deep = ( 'remove_link' === $mode )
			? $this->deep_remove_other_posts( (int) $post->ID, (string) $params['old_url'], $deep_capped )
			: $this->deep_rewrite_other_posts( (int) $post->ID, $params['old_url'], $params['new_url'], $deep_capped );

		// Did this apply change anything at all? A builder-skipped primary with no
		// deep rewrites changed nothing — report no_op so the controller records
		// 'already_applied' and the issue stays open for manual handling, rather
		// than a phantom "fixed".
		$changed = $primary_written || ! empty( $deep );
		$result['no_op'] = ! $changed;

		// Replace the heavy page-content snapshot with a compact summary. We keep
		// NO page copy — rollback() is surgical (it reverses new_url -> old_url on
		// the CURRENT content of the affected posts, read from params +
		// deep_rewrites), so a copy is dead weight and restoring from one would
		// clobber later edits to those pages.
		//
		// before/after MUST remain non-empty JSON OBJECTS: an empty PHP array
		// serializes to "[]", which the backend decodes into a map and rejects —
		// marking the whole apply failed (and losing plugin_history_id, hence
		// rollback) even though the WordPress write already succeeded.
		$after_summary = array(
			'replacements' => $primary_written && isset( $result['after']['replacements'] ) ? (int) $result['after']['replacements'] : 0,
		);
		if ( ! empty( $deep ) ) {
			$after_summary['deep_rewrites'] = $deep;
			// Explicit blast-radius count for the operator/backend: how many
			// OTHER posts this one fix rewrote (each fires a save_post).
			$after_summary['deep_count'] = count( $deep );
		}
		if ( $deep_capped ) {
			// The deep scan hit its 200-post limit — more posts may still
			// reference this URL, so the fix's reach was truncated.
			$after_summary['deep_capped'] = true;
		}
		if ( $primary_is_builder ) {
			// Tell the backend WHY the primary post wasn't touched, so it can
			// keep the issue open and flag it for manual review rather than
			// treating a builder-owned page as fixed.
			$after_summary['skipped_builder'] = true;
		}
		$result['before'] = array( 'mode' => $mode );
		$result['after']  = $after_summary;

		return $result;
	}

	/**
	 * Replace old_url with new_url in every post (other than $skip_post_id)
	 * whose post_content references old_url. Returns map of post_id →
	 * replacement count.
	 *
	 * Tries the same str_replace variant pairs the per-post path uses
	 * (absolute + path-only when both URLs share our home host).
	 *
	 * @return array<int,int>
	 */
	private function deep_rewrite_other_posts( int $skip_post_id, string $old_url, string $new_url, bool &$capped = false ): array {
		global $wpdb;
		if ( ! $wpdb || empty( $wpdb->posts ) ) {
			return array();
		}

		// Search by the longest stable substring of old_url (path) to keep
		// the LIKE index-friendly and not blow up on very-long encoded URLs.
		$path = wp_parse_url( $old_url, PHP_URL_PATH );
		$needle = is_string( $path ) && '' !== $path ? $path : $old_url;
		$like     = '%' . $wpdb->esc_like( $needle ) . '%';
		// Also match the escaped-slash form (…\/foo\/bar…) the block editor keeps
		// in block-comment JSON — otherwise the deep scan never DISCOVERS a
		// Gutenberg post whose only copy of the URL is the escaped one, and the
		// broken link survives on every page that reuses that block.
		$like_esc = '%' . $wpdb->esc_like( self::escape_slashes( $needle ) ) . '%';

		// Direct DB query: WP_Query has no efficient LIKE-on-post_content
		// support, and caching a one-shot deep-rewrite scan would be useless.
		// {$wpdb->posts} is the standard WordPress posts table identifier.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE ( post_content LIKE %s OR post_content LIKE %s ) AND ID <> %d AND post_status IN ('publish','draft','pending')
			 LIMIT 200",
			$like, $like_esc, $skip_post_id
		) );
		// LIMIT 200 hit: there may be more posts referencing this URL than we
		// scanned, so the fix's reach is truncated — surface it to the operator.
		$capped = is_array( $rows ) && count( $rows ) >= 200;
		if ( empty( $rows ) ) {
			return array();
		}

		$pairs = $this->url_variant_pairs( $old_url, $new_url );
		$updates = array();
		foreach ( $rows as $row ) {
			// A page builder owns this post's layout in its own postmeta: a
			// post_content rewrite wouldn't change the rendered page and could
			// corrupt the builder's serialized data. Skip it — the issue is left
			// for manual handling rather than silently mis-"fixed".
			if ( Seonix_Builder_Detector::post_uses_builder( (int) $row->ID ) ) {
				continue;
			}
			$replaced = $row->post_content;
			$total = 0;
			foreach ( $pairs as $pair ) {
				$c = 0;
				$replaced = $this->replace_url_bounded( $pair[0], $pair[1], $replaced, $c );
				$total += $c;
			}
			if ( $total > 0 && $replaced !== $row->post_content ) {
				$ok = wp_update_post( array(
					'ID'           => (int) $row->ID,
					// wp_slash: $replaced comes from the DB-read post_content;
					// wp_update_post() unslashes, so re-slash to preserve backslashes.
					'post_content' => wp_slash( $replaced ),
				), true );
				if ( ! ( $ok instanceof WP_Error ) && (int) $ok > 0 ) {
					$updates[ (int) $row->ID ] = $total;
				}
			}
		}
		return $updates;
	}

	/**
	 * Undo a broken_link fix SURGICALLY — reverse the exact substitution the
	 * fix made, on the CURRENT content of every post it touched, and only where
	 * that substitution is still present. We never restore a stored page copy,
	 * so any edits made to those posts after the fix (other fixes, manual edits)
	 * are preserved.
	 *
	 * rewrite mode: replace new_url back to old_url (boundary-anchored, same as
	 * apply but reversed) on the primary target and every deep-rewritten post
	 * recorded in after_state['deep_rewrites']. A post whose new_url is gone is
	 * left untouched (nothing to undo there).
	 *
	 * remove_link mode: the fix stripped the <a> wrapper and kept only the inner
	 * text; re-wrapping that text in the original anchor can't be done reliably
	 * without a page copy (which we intentionally don't keep), so this fix is
	 * reported as not automatically reversible rather than guessed at.
	 *
	 * ACCEPTED LIMITATION (no-page-copy design): we reverse every boundary-
	 * matched occurrence of new_url, without provenance. In the rare case where
	 * new_url legitimately appears on the page for another reason — e.g. a second,
	 * independent broken_link fix on the SAME post whose chosen target is the SAME
	 * URL — this undo reverts that occurrence too. Distinguishing them would
	 * require the stored page copy the product owner deliberately rejected, so
	 * this is a conscious trade-off, not a bug. Boundary anchoring still prevents
	 * partial / cross-host mismatches.
	 */
	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$params  = isset( $entry['params'] ) && is_array( $entry['params'] ) ? $entry['params'] : array();
		$mode    = isset( $params['mode'] ) ? (string) $params['mode'] : 'rewrite';
		$old_url = isset( $params['old_url'] ) ? (string) $params['old_url'] : '';
		$new_url = isset( $params['new_url'] ) ? (string) $params['new_url'] : '';

		if ( 'remove_link' === $mode ) {
			return new WP_Error(
				'not_reversible',
				'Removing a broken link cannot be undone automatically — the original link wrapper was not kept.',
				array( 'status' => 422 )
			);
		}

		if ( '' === $old_url || '' === $new_url ) {
			return new WP_Error(
				'invalid_history_entry',
				'History entry is missing the URLs needed to reverse this fix.',
				array( 'status' => 422 )
			);
		}

		$reverted = 0;
		$skipped  = 0;
		foreach ( $this->rollback_target_ids( $entry ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$skipped++;
				continue;
			}
			$content = (string) $post->post_content;
			$total   = 0;
			// Reverse direction: put new_url back to old_url. Reusing
			// url_variant_pairs keeps the absolute/relative handling identical to
			// apply, just swapped.
			foreach ( $this->url_variant_pairs( $new_url, $old_url ) as $pair ) {
				$c       = 0;
				$content = $this->replace_url_bounded( $pair[0], $pair[1], $content, $c );
				$total  += $c;
			}
			if ( $total > 0 && $content !== $post->post_content ) {
				$ok = wp_update_post( array(
					'ID'           => (int) $post_id,
					// wp_slash: content is DB-read; wp_update_post() unslashes it.
					'post_content' => wp_slash( $content ),
				), true );
				if ( ! ( $ok instanceof WP_Error ) && (int) $ok > 0 ) {
					$reverted++;
					continue;
				}
			}
			// new_url no longer present (page edited/removed since), or the write
			// failed — nothing to undo on this post. Not an error.
			$skipped++;
		}

		return array(
			'before' => array( 'url' => $new_url, 'reverted_posts' => $reverted, 'skipped_posts' => $skipped ),
			'after'  => array( 'url' => $old_url, 'reverted_posts' => $reverted ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * The full set of posts a broken_link fix mutated: the primary target plus
	 * every post recorded in the deep_rewrites map. Deduplicated (the primary is
	 * excluded from the deep scan, but guard anyway). Keys of deep_rewrites are
	 * post ids (JSON object keys decode to strings — cast to int).
	 *
	 * @return int[]
	 */
	private function rollback_target_ids( array $entry ): array {
		$ids     = array();
		$primary = (int) ( $entry['target_id'] ?? 0 );
		if ( $primary > 0 ) {
			$ids[ $primary ] = true;
		}
		$deep = $entry['after_state']['deep_rewrites'] ?? null;
		if ( is_array( $deep ) ) {
			foreach ( array_keys( $deep ) as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}
		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * @return object|\WP_Error
	 */
	private function load_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
		}
		return $post;
	}

	private function describe_replacement( $post, string $old_url, string $new_url ): array {
		$replaced     = $post->post_content;
		$total_count  = 0;
		foreach ( $this->url_variant_pairs( $old_url, $new_url ) as $pair ) {
			$count    = 0;
			$replaced = $this->replace_url_bounded( $pair[0], $pair[1], $replaced, $count );
			$total_count += $count;
		}
		$is_no_op = 0 === $total_count;

		// `diff` dropped in 2.2.5: controller no longer ships it on the wire
		// and the backend never read the string. Keeping the sprintf in dry-run
		// previews was pure waste.
		return array(
			'before' => array(
				'post_content' => $post->post_content,
			),
			'after'  => array(
				'post_content' => $replaced,
				'replacements' => $total_count,
			),
			'no_op'  => $is_no_op,
			'target' => array(
				'type' => 'post',
				'id'   => (int) $post->ID,
			),
		);
	}

	/**
	 * remove_link mode: strip every `<a ...>TEXT</a>` whose href matches
	 * old_url (absolute or matching relative form) and keep TEXT in place.
	 *
	 * Used when the AI/backend cannot find a confident redirect target — the
	 * scanner has confirmed the URL is dead, so leaving the anchor would keep
	 * the same broken-link issue flagged forever. Stripping the anchor leaves
	 * the surrounding text intact and removes the reported issue.
	 */
	private function describe_anchor_removal( $post, string $old_url ): array {
		$count    = 0;
		$replaced = $this->strip_anchors( (string) $post->post_content, $old_url, $count );
		$is_no_op = 0 === $count;

		return array(
			'before' => array(
				'post_content' => $post->post_content,
			),
			'after'  => array(
				'post_content' => $replaced,
				'replacements' => $count,
			),
			'no_op'  => $is_no_op,
			'target' => array(
				'type' => 'post',
				'id'   => (int) $post->ID,
			),
		);
	}

	/**
	 * Replace every `<a href="$url">TEXT</a>` (or any other attribute order
	 * around href) with its inner TEXT. Matches both the absolute form of
	 * old_url and the path-only relative form when old_url points at our own
	 * host. Returns the rewritten HTML and writes the number of stripped
	 * anchors into &$count.
	 *
	 * Regex tradeoff: <a> is HTML so a parser would be more robust, but the
	 * block editor stores hand-edited HTML in post_content and DOMDocument
	 * mangles it (UTF-8, self-closing tags, comments). The regex below tolerates
	 * any attribute order, single or double quoted href values, mixed-case
	 * tag names, and nested attributes; it gives up only on anchors that contain
	 * another `<a` inside their text (extremely rare and would be invalid HTML).
	 */
	private function strip_anchors( string $html, string $old_url, int &$count ): string {
		$count = 0;
		$urls  = array( $old_url );

		// Add the path-only variant when old_url is on our own host so we also
		// match block-editor relative hrefs.
		$home_host = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : null;
		$old_host  = wp_parse_url( $old_url, PHP_URL_HOST );
		if ( $home_host && $old_host && strcasecmp( $home_host, $old_host ) === 0 ) {
			$path = (string) wp_parse_url( $old_url, PHP_URL_PATH );
			if ( '' !== $path && $path !== $old_url && ! in_array( $path, $urls, true ) ) {
				$urls[] = $path;
			}
		}

		// Escaped-slash forms (block-comment JSON), mirroring the rewrite path.
		foreach ( array_values( $urls ) as $u ) {
			$e = self::escape_slashes( $u );
			if ( $e !== $u && ! in_array( $e, $urls, true ) ) {
				$urls[] = $e;
			}
		}

		$out = $html;
		foreach ( $urls as $target ) {
			$quoted = preg_quote( $target, '#' );
			// Two patterns — one for double-quoted href, one for single-quoted.
			// Splitting them avoids nested-quote escaping inside the regex string.
			$patterns = array(
				'#<a\b([^>]*?\bhref\s*=\s*"' . $quoted . '"[^>]*)>(.*?)</a\s*>#is',
				'#<a\b([^>]*?\bhref\s*=\s*' . "'" . $quoted . "'" . '[^>]*)>(.*?)</a\s*>#is',
			);
			foreach ( $patterns as $pat ) {
				$out = preg_replace_callback(
					$pat,
					function ( $m ) use ( &$count ) {
						$count++;
						return isset( $m[2] ) ? $m[2] : '';
					},
					$out
				);
			}
		}

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Deep remove_link: walk other posts referencing old_url and strip the
	 * matching anchors. Returns map of post_id → stripped count.
	 *
	 * Same scan strategy as deep_rewrite_other_posts — index-friendly LIKE
	 * on the URL path.
	 *
	 * @return array<int,int>
	 */
	private function deep_remove_other_posts( int $skip_post_id, string $old_url, bool &$capped = false ): array {
		global $wpdb;
		if ( ! $wpdb || empty( $wpdb->posts ) ) {
			return array();
		}

		$path   = wp_parse_url( $old_url, PHP_URL_PATH );
		$needle = is_string( $path ) && '' !== $path ? $path : $old_url;
		$like     = '%' . $wpdb->esc_like( $needle ) . '%';
		$like_esc = '%' . $wpdb->esc_like( self::escape_slashes( $needle ) ) . '%';

		// Same rationale as deep_rewrite_other_posts(): one-shot LIKE scan,
		// no caching layer makes sense for an SEO-fix dispatch.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE ( post_content LIKE %s OR post_content LIKE %s ) AND ID <> %d AND post_status IN ('publish','draft','pending')
			 LIMIT 200",
			$like, $like_esc, $skip_post_id
		) );
		$capped = is_array( $rows ) && count( $rows ) >= 200;
		if ( empty( $rows ) ) {
			return array();
		}

		$updates = array();
		foreach ( $rows as $row ) {
			// Builder-owned posts: skip (see deep_rewrite_other_posts()).
			if ( Seonix_Builder_Detector::post_uses_builder( (int) $row->ID ) ) {
				continue;
			}
			$count = 0;
			$replaced = $this->strip_anchors( (string) $row->post_content, $old_url, $count );
			if ( $count > 0 && $replaced !== $row->post_content ) {
				$ok = wp_update_post( array(
					'ID'           => (int) $row->ID,
					// wp_slash: $replaced comes from the DB-read post_content;
					// wp_update_post() unslashes, so re-slash to preserve backslashes.
					'post_content' => wp_slash( $replaced ),
				), true );
				if ( ! ( $ok instanceof WP_Error ) && (int) $ok > 0 ) {
					$updates[ (int) $row->ID ] = $count;
				}
			}
		}
		return $updates;
	}

	/**
	 * Boundary-anchored URL replacement.
	 *
	 * A plain str_replace on a URL corrupts partial matches: replacing "/foo"
	 * would also rewrite "/foo-bar", "/foobar", "/foo/child" — and, for the
	 * path-only variant, the same path inside a DIFFERENT host's absolute URL
	 * ("https://other.com/foo"). In deep mode that corruption multiplies
	 * across up to 200 posts. This anchors the match on both sides:
	 *
	 *  - LEFT: the preceding character must not be a host/path character
	 *    ([A-Za-z0-9_.-]), so a path needle can't match mid-URL (the char
	 *    before a legit occurrence is a quote, whitespace, '(' or '=').
	 *  - RIGHT: the following character must not extend the path
	 *    ([A-Za-z0-9_.-] or '/'), so "/foo" never matches inside "/foo-bar"
	 *    or "/foo/child". A query string or fragment MAY follow and is
	 *    preserved: "/foo?page=2" → "/new?page=2".
	 *
	 * Deliberately stricter than str_replace: a trailing-slash mismatch
	 * ("/foo" vs "/foo/" in content) no longer half-matches — the fix
	 * no-ops instead of corrupting, and the issue stays visible for a rerun
	 * with the exact URL. Replacement goes through preg_replace_callback so
	 * "$" and "\" in the new URL are inserted literally.
	 *
	 * @param string $old     Exact URL (or path) to replace.
	 * @param string $new     Replacement URL (or path).
	 * @param string $subject Post content to rewrite.
	 * @param int    $count   OUT: number of replacements made.
	 * @return string The rewritten content ($subject untouched on regex failure).
	 */
	private function replace_url_bounded( string $old, string $new, string $subject, int &$count ): string {
		$count   = 0;
		$pattern = '#(?<![A-Za-z0-9_.\-])' . preg_quote( $old, '#' ) . '(?![A-Za-z0-9_.\-/])#';
		$result  = preg_replace_callback(
			$pattern,
			static function () use ( $new, &$count ) {
				$count++;
				return $new;
			},
			$subject
		);
		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Returns pairs of (old_variant, new_variant) for replace_url_bounded, in
	 * priority order (most specific first). When both URLs point at this site,
	 * we ALSO try the path-only relative form because WP block editor stores
	 * internal links as relative href values ("/foo/" not "https://host/foo/").
	 *
	 * Each pair preserves the absolute/relative form of the two sides, so we
	 * never replace an absolute href with a path or vice versa.
	 *
	 * @return array<array{0:string,1:string}>
	 */
	private function url_variant_pairs( string $old_url, string $new_url ): array {
		$pairs = array( array( $old_url, $new_url ) );

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $home_host ) {
			$same_host = function ( $url ) use ( $home_host ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				return $host && strcasecmp( $host, $home_host ) === 0;
			};

			if ( $same_host( $old_url ) && $same_host( $new_url ) ) {
				$old_path = (string) wp_parse_url( $old_url, PHP_URL_PATH );
				$new_path = (string) wp_parse_url( $new_url, PHP_URL_PATH );
				if ( '' !== $old_path && '' !== $new_path && $old_path !== $old_url ) {
					$pairs[] = array( $old_path, $new_path );
				}
			}
		}

		// Escaped-slash variants. The block editor stores link URLs inside
		// block-comment JSON with forward slashes backslash-escaped
		// (…<!-- wp:… {"url":"https:\/\/site\/page"} -->…) AS WELL AS in the
		// rendered <a href="https://site/page">. Rewriting only the plain href
		// leaves the escaped JSON copy stale, so the block's saved markup no
		// longer matches its attributes → "This block contains unexpected or
		// invalid content" the next time it's opened. Emitting the escaped form
		// as its own pair keeps both copies in sync. replace_url_bounded()
		// preg_quote()s the needle, so the literal backslashes match safely.
		foreach ( $pairs as $pair ) {
			$eo = self::escape_slashes( $pair[0] );
			$en = self::escape_slashes( $pair[1] );
			if ( $eo !== $pair[0] ) {
				$pairs[] = array( $eo, $en );
			}
		}

		return $pairs;
	}

	/**
	 * Backslash-escape forward slashes, matching how WordPress' block editor
	 * (and other JSON-in-post_content producers) serialize URLs inside block
	 * attributes: "https://site/page" is stored as "https:\/\/site\/page".
	 */
	private static function escape_slashes( string $s ): string {
		return str_replace( '/', '\\/', $s );
	}
}
