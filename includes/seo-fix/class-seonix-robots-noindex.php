<?php
/**
 * Shared plumbing for the robots-noindex SEO fixes.
 *
 * Four fix methods share this class: set_post_robots_noindex /
 * set_term_robots_noindex (the Site Health "hide this stub / duplicate archive
 * from search" one-click fixes) and their revert twins. Everything they have in
 * common lives here so the four method classes stay thin executors:
 *
 *   - ENGINE MAP. The noindex is written through the active SEO plugin's OWN
 *     storage so the site's SEO stack keeps owning the state (it shows up in
 *     that plugin's UI, and both supported engines drop noindexed content from
 *     their XML sitemaps automatically — no sitemap surgery needed):
 *       Yoast     post: `_yoast_wpseo_meta-robots-noindex` = '1' postmeta;
 *                 term: `wpseo_noindex` via the public WPSEO_Taxonomy_Meta API
 *                 (never the `wpseo_taxonomy_meta` option directly — 2.4.2
 *                 "public-API-only" rule), plus a best-effort
 *                 `wp_yoast_indexable.is_robots_noindex` sync because the
 *                 indexables layer (v14+) renders robots from its own table,
 *                 not from the option/meta, and nothing rebuilds it when the
 *                 option changes outside Yoast's admin screens.
 *       RankMath  post/term: merge 'noindex' into the `rank_math_robots`
 *                 array meta, preserving any other directives the owner set.
 *       Others    (incl. AIOSEO — custom-table model) unsupported: the Seonix
 *                 standalone renderer deliberately never emits a robots meta
 *                 (see Seonix_Meta_Renderer), so with no supported engine the
 *                 methods report `no_supported_seo_plugin` instead of writing
 *                 state nothing would render.
 *
 *   - REVERT SNAPSHOTS. The Seonix backend hands every apply a `revert_token`;
 *     the exact previous native value is stored under that token in a plugin
 *     option BEFORE anything is written. Bounded: at most SNAPSHOT_CAP entries,
 *     pruned oldest-first (insertion order). put-if-absent so a retried apply
 *     can never overwrite the original pre-fix state with its own result.
 *
 *   - SELF-VERIFICATION. After a write the affected URL is fetched over
 *     loopback HTTP (cache-buster query + a Seonix Optimizer page-cache purge
 *     for that URL when the Optimizer plugin is installed) and the rendered
 *     HTML / X-Robots-Tag header is checked for a robots noindex. Verification
 *     failure never fails the apply — the write is reported `applied` with
 *     `verified=false` plus details (page caches and login walls exist).
 *
 * None of this touches wp_update_post(), so a robots change can never bump
 * post_modified — same discipline as Seonix_Content_Write, achieved by simply
 * never writing the posts table at all (postmeta/termmeta/options only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Robots_Noindex {

	/** Plugin option holding revert snapshots, keyed by revert_token. Not autoloaded. */
	const SNAPSHOT_OPTION = 'seonix_robots_revert_snapshots';

	/** Max snapshots kept; oldest pruned first when exceeded. */
	const SNAPSHOT_CAP = 100;

	/** Yoast per-post robots noindex postmeta ('1' = noindex, '2' = index, '' = default). */
	const YOAST_POST_META = '_yoast_wpseo_meta-robots-noindex';

	/** Rank Math robots directive array meta — same key for posts and terms. */
	const RM_META = 'rank_math_robots';

	/** Contract string reported when no engine with a writable robots contract is active. */
	const UNSUPPORTED_REASON = 'no_supported_seo_plugin';

	// ─── Engine ──────────────────────────────────────────────────────────

	/**
	 * The active engine these fixes can write robots state through, or null.
	 * Same set as Seonix_Fix_Post_Noindex: Yoast + Rank Math only (plain
	 * postmeta / public-API contracts). AIOSEO keeps robots in its own table
	 * model — deliberately excluded, we never write AIOSEO internals.
	 *
	 * @return 'yoast'|'rankmath'|null
	 */
	public static function engine(): ?string {
		$engine = Seonix_SEO_Engine::detect();
		return in_array( $engine, array( 'yoast', 'rankmath' ), true ) ? $engine : null;
	}

	/**
	 * The WP_Error every method returns when engine() is null. The error data
	 * carries the contract fields (`unsupported_reason`, `applied`, `verified`)
	 * so the backend can read them off the error payload as well as the code.
	 *
	 * @return WP_Error
	 */
	public static function unsupported_error(): WP_Error {
		return new WP_Error(
			self::UNSUPPORTED_REASON,
			'Robots noindex needs Yoast SEO or Rank Math (Seonix itself deliberately emits no robots meta, and AIOSEO internals are not written).',
			array(
				'status'             => 422,
				'applied'            => false,
				'verified'           => false,
				'unsupported_reason' => self::UNSUPPORTED_REASON,
			)
		);
	}

	// ─── Post robots state ───────────────────────────────────────────────

	/**
	 * Current robots state of a post under the given engine.
	 *
	 * `meta_value` is the EXACT stored native value (Yoast: string; Rank Math:
	 * array) so a snapshot restore is byte-for-byte.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $engine  'yoast'|'rankmath'.
	 * @return array{noindexed:bool, meta_value:mixed}
	 */
	public static function post_state( int $post_id, string $engine ): array {
		if ( 'yoast' === $engine ) {
			$value = get_post_meta( $post_id, self::YOAST_POST_META, true );
			return array(
				'noindexed'  => '1' === (string) $value,
				'meta_value' => $value,
			);
		}
		$robots = self::rank_math_robots( get_post_meta( $post_id, self::RM_META, true ) );
		return array(
			'noindexed'  => in_array( 'noindex', $robots, true ),
			'meta_value' => $robots,
		);
	}

	/**
	 * Write "noindex" for a post through the engine's native storage.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $engine  'yoast'|'rankmath'.
	 * @return true|WP_Error
	 */
	public static function set_post_noindex( int $post_id, string $engine ) {
		if ( 'yoast' === $engine ) {
			if ( false === update_post_meta( $post_id, self::YOAST_POST_META, '1' ) ) {
				return new WP_Error( 'update_failed', 'update_post_meta returned false (yoast robots).', array( 'status' => 500 ) );
			}
			// Make the change render NOW: Yoast's meta watcher only rebuilds the
			// indexable row at shutdown, which is after our verification fetch.
			self::yoast_sync_post_indexable( $post_id, 1 );
			return true;
		}

		$robots   = self::rank_math_robots( get_post_meta( $post_id, self::RM_META, true ) );
		$robots[] = 'noindex';
		if ( false === update_post_meta( $post_id, self::RM_META, array_values( array_unique( $robots ) ) ) ) {
			return new WP_Error( 'update_failed', 'update_post_meta returned false (rank_math_robots).', array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Restore a post's robots meta to a snapshotted value. Empty snapshot
	 * (never had the meta) deletes the key rather than writing ''.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $engine     'yoast'|'rankmath'.
	 * @param mixed  $meta_value Exact previous native value.
	 * @return true|WP_Error
	 */
	public static function restore_post( int $post_id, string $engine, $meta_value ) {
		$key = 'yoast' === $engine ? self::YOAST_POST_META : self::RM_META;
		if ( null === $meta_value || '' === $meta_value || ( is_array( $meta_value ) && array() === $meta_value ) ) {
			delete_post_meta( $post_id, $key );
		} elseif ( false === update_post_meta( $post_id, $key, $meta_value ) ) {
			return new WP_Error( 'update_failed', sprintf( 'update_post_meta returned false (%s).', $key ), array( 'status' => 500 ) );
		}

		if ( 'yoast' === $engine ) {
			// '1' = noindex, '2' = explicit index, anything else = site default.
			$flag = null;
			if ( '1' === (string) $meta_value ) {
				$flag = 1;
			} elseif ( '2' === (string) $meta_value ) {
				$flag = 0;
			}
			self::yoast_sync_post_indexable( $post_id, $flag );
		}
		return true;
	}

	// ─── Term robots state ───────────────────────────────────────────────

	/**
	 * Current robots state of a taxonomy term under the given engine.
	 *
	 * Yoast keeps term robots in the `wpseo_taxonomy_meta` option; we only read
	 * it through the public WPSEO_Taxonomy_Meta API (value 'noindex'|'index'|
	 * 'default', absent reads as default). Rank Math uses `rank_math_robots`
	 * term meta, same array shape as posts.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug the term belongs to.
	 * @param string $engine   'yoast'|'rankmath'.
	 * @return array{noindexed:bool, meta_value:mixed}|WP_Error
	 */
	public static function term_state( int $term_id, string $taxonomy, string $engine ) {
		if ( 'yoast' === $engine ) {
			if ( ! class_exists( 'WPSEO_Taxonomy_Meta' ) || ! is_callable( array( 'WPSEO_Taxonomy_Meta', 'get_term_meta' ) ) ) {
				return self::yoast_api_unavailable();
			}
			$value = call_user_func( array( 'WPSEO_Taxonomy_Meta', 'get_term_meta' ), $term_id, $taxonomy, 'noindex' );
			$value = is_string( $value ) ? $value : '';
			return array(
				'noindexed'  => 'noindex' === $value,
				'meta_value' => $value,
			);
		}
		$robots = self::rank_math_robots( get_term_meta( $term_id, self::RM_META, true ) );
		return array(
			'noindexed'  => in_array( 'noindex', $robots, true ),
			'meta_value' => $robots,
		);
	}

	/**
	 * Write "noindex" for a term archive through the engine's native storage.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $engine   'yoast'|'rankmath'.
	 * @return true|WP_Error
	 */
	public static function set_term_noindex( int $term_id, string $taxonomy, string $engine ) {
		if ( 'yoast' === $engine ) {
			$written = self::yoast_write_term_noindex( $term_id, $taxonomy, 'noindex' );
			if ( $written instanceof WP_Error ) {
				return $written;
			}
			self::yoast_sync_term_indexable( $term_id, $taxonomy, 1 );
			return true;
		}

		$robots   = self::rank_math_robots( get_term_meta( $term_id, self::RM_META, true ) );
		$robots[] = 'noindex';
		if ( false === update_term_meta( $term_id, self::RM_META, array_values( array_unique( $robots ) ) ) ) {
			return new WP_Error( 'update_failed', 'update_term_meta returned false (rank_math_robots).', array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Restore a term's robots setting to a snapshotted value.
	 *
	 * @param int    $term_id    Term ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param string $engine     'yoast'|'rankmath'.
	 * @param mixed  $meta_value Exact previous native value.
	 * @return true|WP_Error
	 */
	public static function restore_term( int $term_id, string $taxonomy, string $engine, $meta_value ) {
		if ( 'yoast' === $engine ) {
			// Anything that wasn't an explicit 'index'/'noindex' override restores
			// as 'default' — Yoast's own validator strips default-valued keys from
			// the option, so this is exactly "remove our override".
			$value = ( is_string( $meta_value ) && in_array( $meta_value, array( 'index', 'noindex' ), true ) )
				? $meta_value
				: 'default';
			$written = self::yoast_write_term_noindex( $term_id, $taxonomy, $value );
			if ( $written instanceof WP_Error ) {
				return $written;
			}
			$flag = null;
			if ( 'noindex' === $value ) {
				$flag = 1;
			} elseif ( 'index' === $value ) {
				$flag = 0;
			}
			self::yoast_sync_term_indexable( $term_id, $taxonomy, $flag );
			return true;
		}

		if ( null === $meta_value || '' === $meta_value || ( is_array( $meta_value ) && array() === $meta_value ) ) {
			delete_term_meta( $term_id, self::RM_META );
			return true;
		}
		if ( false === update_term_meta( $term_id, self::RM_META, $meta_value ) ) {
			return new WP_Error( 'update_failed', 'update_term_meta returned false (rank_math_robots).', array( 'status' => 500 ) );
		}
		return true;
	}

	// ─── Revert snapshots (bounded, FIFO) ────────────────────────────────

	/**
	 * Validate + normalize a backend-issued revert token. Tokens are opaque
	 * identifiers — accept a conservative charset and length so the option key
	 * space can't be abused, and return '' for anything else.
	 *
	 * @param mixed $raw Raw param value.
	 * @return string Sanitized token, or '' when invalid.
	 */
	public static function sanitize_token( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		return preg_match( '/\A[A-Za-z0-9._:-]{1,128}\z/', $raw ) ? $raw : '';
	}

	/**
	 * Fetch the snapshot stored under a token, or null.
	 *
	 * @param string $token Sanitized revert token.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot_get( string $token ) {
		$store = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $store ) || ! isset( $store[ $token ] ) || ! is_array( $store[ $token ] ) ) {
			return null;
		}
		return $store[ $token ];
	}

	/**
	 * Store a snapshot under a token UNLESS one already exists — a retried
	 * apply must never replace the original pre-fix state with its own result
	 * (that would make revert restore the fix instead of undoing it).
	 *
	 * The store is insertion-ordered; when it exceeds SNAPSHOT_CAP the oldest
	 * entries are pruned. Stored with autoload=false — reverts are rare admin
	 * actions, the blob has no business on every page load.
	 *
	 * @param string              $token Sanitized revert token.
	 * @param array<string,mixed> $data  Snapshot payload.
	 * @return void
	 */
	public static function snapshot_put_if_absent( string $token, array $data ): void {
		$store = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}
		if ( isset( $store[ $token ] ) ) {
			return;
		}
		$store[ $token ] = $data;
		if ( count( $store ) > self::SNAPSHOT_CAP ) {
			// PHP assoc arrays preserve insertion order — keep the newest CAP.
			$store = array_slice( $store, -self::SNAPSHOT_CAP, null, true );
		}
		update_option( self::SNAPSHOT_OPTION, $store, false );
	}

	// ─── Verification ────────────────────────────────────────────────────

	/**
	 * Fetch the affected URL server-side and confirm whether its rendered
	 * robots signal (meta tag or X-Robots-Tag header) contains "noindex".
	 *
	 * Before fetching, the Seonix Optimizer page cache for that URL is purged
	 * when the Optimizer plugin is installed, and a cache-buster query arg
	 * defeats URL-keyed page caches. `verified` is true when the observed
	 * state matches $expect_noindex; on any fetch problem it is false with the
	 * reason in `details` — a failed verification never fails the fix itself.
	 *
	 * @param string $url            Absolute public URL of the changed page.
	 * @param bool   $expect_noindex Whether noindex should now be present.
	 * @return array{verified:bool, details:string}
	 */
	public static function verify_url( string $url, bool $expect_noindex ): array {
		if ( '' === $url ) {
			return array(
				'verified' => false,
				'details'  => 'public URL could not be resolved for verification',
			);
		}

		self::purge_optimizer_cache( $url );

		$response = wp_remote_get(
			add_query_arg( 'seonix_verify', (string) time(), $url ),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				// The robots meta lives in <head>; no need to download a megabyte
				// of page builder markup to find it.
				'limit_response_size' => 262144,
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( $response instanceof WP_Error ) {
			return array(
				'verified' => false,
				'details'  => 'verification fetch failed: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'verified' => false,
				'details'  => sprintf( 'verification fetch returned HTTP %d', $code ),
			);
		}

		$found = self::noindex_present( (string) wp_remote_retrieve_body( $response ), $response );
		if ( $found === $expect_noindex ) {
			return array( 'verified' => true, 'details' => '' );
		}
		return array(
			'verified' => false,
			'details'  => $expect_noindex
				? 'rendered page shows no robots noindex yet (a page cache may still be serving the old HTML)'
				: 'rendered page still shows a robots noindex (a page cache may still be serving the old HTML)',
		);
	}

	/**
	 * Does the fetched page carry a noindex — in a `<meta name="robots">` tag
	 * (either attribute order) or an X-Robots-Tag response header?
	 *
	 * @param string $body     Response body (possibly truncated to <head>+).
	 * @param mixed  $response Full wp_remote_get response (for headers).
	 * @return bool
	 */
	public static function noindex_present( string $body, $response ): bool {
		$header = wp_remote_retrieve_header( $response, 'x-robots-tag' );
		foreach ( is_array( $header ) ? $header : array( $header ) as $h ) {
			if ( is_string( $h ) && false !== stripos( $h, 'noindex' ) ) {
				return true;
			}
		}

		if ( '' === $body || ! preg_match_all( '/<meta\b[^>]*>/i', $body, $tags ) ) {
			return false;
		}
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\bname\s*=\s*["\']?robots["\']?/i', $tag ) ) {
				continue;
			}
			if ( preg_match( '/\bcontent\s*=\s*["\']([^"\']*)["\']/i', $tag, $m )
				&& false !== stripos( $m[1], 'noindex' ) ) {
				return true;
			}
		}
		return false;
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Purge the Seonix Optimizer page cache for one URL, if the Optimizer
	 * plugin is installed on this site. Best-effort by design: any failure in
	 * the sibling plugin must never break an SEO fix.
	 *
	 * @param string $url Absolute URL (purged WITHOUT the cache-buster query —
	 *                    the Optimizer's cache directories are path-keyed).
	 * @return void
	 */
	private static function purge_optimizer_cache( string $url ): void {
		if ( ! class_exists( 'Seonix_Opt_Purge' ) || ! is_callable( array( 'Seonix_Opt_Purge', 'purge_url' ) ) ) {
			return;
		}
		try {
			call_user_func( array( 'Seonix_Opt_Purge', 'purge_url' ), $url );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for cross-plugin integration failures.
			error_log( '[Seonix] optimizer cache purge failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Write the Yoast term noindex value through the public API
	 * (`WPSEO_Taxonomy_Meta::set_value`). Yoast's setter does the safe
	 * read-modify-write of the `wpseo_taxonomy_meta` option — validation, the
	 * option-array merge and default-stripping are all theirs; we never touch
	 * the option key directly.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $value    'noindex'|'index'|'default'.
	 * @return true|WP_Error
	 */
	private static function yoast_write_term_noindex( int $term_id, string $taxonomy, string $value ) {
		if ( ! defined( 'WPSEO_VERSION' )
			|| ! class_exists( 'WPSEO_Taxonomy_Meta' )
			|| ! is_callable( array( 'WPSEO_Taxonomy_Meta', 'set_value' ) ) ) {
			return self::yoast_api_unavailable();
		}
		call_user_func( array( 'WPSEO_Taxonomy_Meta', 'set_value' ), $term_id, $taxonomy, 'wpseo_noindex', $value );
		return true;
	}

	/**
	 * @return WP_Error 412 for a Yoast install whose public class API is unreachable.
	 */
	private static function yoast_api_unavailable(): WP_Error {
		return new WP_Error(
			'yoast_api_unavailable',
			'Yoast SEO public class API (WPSEO_Taxonomy_Meta) is unavailable.',
			array( 'status' => 412 )
		);
	}

	/**
	 * Best-effort sync of `wp_yoast_indexable.is_robots_noindex` for a post.
	 *
	 * Yoast v14+ renders robots from the indexables table. For postmeta writes
	 * Yoast's own meta watcher rebuilds the row — but only at request shutdown,
	 * which is AFTER our loopback verification fetch. Syncing the one column
	 * directly makes the change visible immediately; the watcher's shutdown
	 * rebuild then lands on the same value. Mirrors the pattern established by
	 * Seonix_Fix_Term_Meta_Description::engine_sync_indexable().
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $flag    1 = noindex, 0 = index, null = default.
	 * @return void
	 */
	private static function yoast_sync_post_indexable( int $post_id, $flag ): void {
		self::yoast_sync_indexable(
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			$flag,
			"yoast_indexable_{$post_id}"
		);
	}

	/**
	 * Best-effort sync of `wp_yoast_indexable.is_robots_noindex` for a term.
	 * Unlike posts, NOTHING rebuilds a term indexable when the taxonomy-meta
	 * option changes outside Yoast's own admin screen — without this sync the
	 * live archive keeps rendering the old robots until Yoast's cron
	 * reconciliation, and the noindex looks like it silently failed.
	 *
	 * @param int      $term_id  Term ID.
	 * @param string   $taxonomy Taxonomy slug.
	 * @param int|null $flag     1 = noindex, 0 = index, null = default.
	 * @return void
	 */
	private static function yoast_sync_term_indexable( int $term_id, string $taxonomy, $flag ): void {
		self::yoast_sync_indexable(
			array(
				'object_type'     => 'term',
				'object_sub_type' => $taxonomy,
				'object_id'       => $term_id,
			),
			$flag,
			"yoast_indexable_term_{$term_id}_{$taxonomy}"
		);
	}

	/**
	 * The shared indexable-column UPDATE. Errors suppressed: the table only
	 * exists when the Indexables module is on (default since v14); on installs
	 * without it this degrades to a silent no-op and Yoast renders robots from
	 * the meta directly.
	 *
	 * @param array<string,mixed> $where     Indexable row selector.
	 * @param int|null            $flag      is_robots_noindex value (null = default).
	 * @param string              $cache_key Per-indexable object-cache key to drop.
	 * @return void
	 */
	private static function yoast_sync_indexable( array $where, $flag, string $cache_key ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : false;
		// The indexables table is Yoast's render-time source of truth for robots;
		// a direct one-column update is the only immediate path (see docblocks).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'yoast_indexable',
			array( 'is_robots_noindex' => $flag ),
			$where
		);
		if ( method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( $suppress );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $cache_key, 'yoast_indexables' );
		}
	}

	/**
	 * Normalize a stored rank_math_robots meta value to a clean string list.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return string[]
	 */
	private static function rank_math_robots( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( $raw, 'is_string' ) );
	}
}
