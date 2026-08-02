<?php
/**
 * Fix method: post_noindex.
 *
 * Sets a per-post "noindex" robots directive through the active SEO plugin's
 * own meta (Yoast / Rank Math), for pages the scanner identified as stubs —
 * indexable placeholders with almost no content that claim their exact-match
 * title in search and answer nothing. Noindexing the stub (until real content
 * is written) is the scanner's recommended remedy; both supported engines
 * also drop noindexed posts from their XML sitemaps automatically, so the
 * one write fixes both signals.
 *
 * Deliberately NARROW:
 *   - one post per explicit apply — there is no bulk mode; hiding pages from
 *     search is a per-page editorial decision, never a sweep;
 *   - only the two engines whose robots meta is a plain postmeta contract
 *     (Yoast: `_yoast_wpseo_meta-robots-noindex` = '1'; Rank Math:
 *      `rank_math_robots` array containing 'noindex'). AIOSEO keeps robots
 *     in its own table/model — unsupported here, is_available() says so;
 *   - reversible: rollback restores the exact previous meta state, with the
 *     same stale-guard as every other fix (if someone changed the robots
 *     state after our apply, we refuse instead of clobbering their choice).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Post_Noindex implements Seonix_Fix_Method {

	const YOAST_META = '_yoast_wpseo_meta-robots-noindex';
	const RM_META    = 'rank_math_robots';

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'post_noindex';
	}

	/**
	 * Capability gate: only offered when an engine with a plain-postmeta
	 * robots contract is active.
	 */
	public function is_available(): bool {
		return null !== $this->engine();
	}

	public function validate_params( array $params ) {
		if ( empty( $params['post_id'] ) || ! is_numeric( $params['post_id'] ) ) {
			return new WP_Error( 'missing_post_id', 'post_id is required.', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		return $this->describe( (int) $params['post_id'] );
	}

	public function apply( array $params ) {
		$post_id = (int) $params['post_id'];
		$result  = $this->describe( $post_id );
		if ( $result instanceof WP_Error || $result['no_op'] ) {
			return $result;
		}

		$engine = $this->engine();
		if ( 'yoast' === $engine ) {
			$ok = update_post_meta( $post_id, self::YOAST_META, '1' );
			if ( false === $ok ) {
				return new WP_Error( 'update_failed', 'update_post_meta returned false (yoast robots).', array( 'status' => 500 ) );
			}
			return $result;
		}

		// Rank Math: merge 'noindex' into the robots array, preserving any
		// other directives the owner set (nofollow, noarchive, …).
		$robots = $this->rank_math_robots( $post_id );
		$robots[] = 'noindex';
		$ok = update_post_meta( $post_id, self::RM_META, array_values( array_unique( $robots ) ) );
		if ( false === $ok ) {
			return new WP_Error( 'update_failed', 'update_post_meta returned false (rank_math_robots).', array( 'status' => 500 ) );
		}
		return $result;
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$post_id = (int) ( $entry['target_id'] ?? 0 );
		$before  = $entry['before_state'] ?? array();
		if ( $post_id <= 0 || ! is_array( $before ) || ! array_key_exists( 'meta_value', $before ) ) {
			return new WP_Error( 'invalid_history_entry', 'History entry is missing snapshot.', array( 'status' => 422 ) );
		}

		$engine = $this->engine();
		if ( null === $engine || ( isset( $entry['before_state']['engine'] ) && $entry['before_state']['engine'] !== $engine ) ) {
			return new WP_Error(
				'engine_changed',
				'The active SEO plugin changed since this fix was applied — restore the robots setting manually.',
				array( 'status' => 422 )
			);
		}

		// Stale-guard: the post must still be noindexed BY US. If the owner
		// already re-indexed it (or changed robots by hand), rolling back
		// would clobber that decision.
		if ( ! $this->is_noindexed( $post_id ) ) {
			return new WP_Error(
				'rollback_stale',
				'The robots setting was changed after this fix was applied — rolling back now would overwrite that change.',
				array( 'status' => 409 )
			);
		}

		$old = $before['meta_value'];
		$key = 'yoast' === $engine ? self::YOAST_META : self::RM_META;
		if ( null === $old || '' === $old || ( is_array( $old ) && empty( $old ) ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $old );
		}

		return array(
			'before' => array( 'noindexed' => true ),
			'after'  => array( 'noindexed' => $this->is_noindexed( $post_id ) ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * @return array|\WP_Error dry-run/apply result payload.
	 */
	private function describe( int $post_id ) {
		$engine = $this->engine();
		if ( null === $engine ) {
			return new WP_Error( 'no_seo_plugin', 'post_noindex requires Yoast SEO or Rank Math.', array( 'status' => 422 ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
		}

		$already = $this->is_noindexed( $post_id );

		// before_state.meta_value is the EXACT previous meta so rollback can
		// restore it byte-for-byte (Yoast: string; Rank Math: array).
		$key = 'yoast' === $engine ? self::YOAST_META : self::RM_META;

		return array(
			'before' => array(
				'noindexed'  => $already,
				'engine'     => $engine,
				'meta_value' => get_post_meta( $post_id, $key, true ),
			),
			'after'  => array(
				'noindexed' => true,
				// Both engines exclude noindexed posts from their XML sitemap
				// on their own — surfaced so the dashboard can say it.
				'sitemap_excluded' => true,
			),
			'no_op'  => $already,
			'target' => array(
				'type' => 'post',
				'id'   => $post_id,
			),
		);
	}

	/**
	 * @return 'yoast'|'rankmath'|null Engine with a plain-postmeta robots
	 *                                 contract (AIOSEO deliberately excluded).
	 */
	private function engine(): ?string {
		$engine = Seonix_SEO_Engine::detect();
		return in_array( $engine, array( 'yoast', 'rankmath' ), true ) ? $engine : null;
	}

	private function is_noindexed( int $post_id ): bool {
		if ( 'yoast' === $this->engine() ) {
			return '1' === (string) get_post_meta( $post_id, self::YOAST_META, true );
		}
		return in_array( 'noindex', $this->rank_math_robots( $post_id ), true );
	}

	/**
	 * @return string[] Rank Math robots directives for the post.
	 */
	private function rank_math_robots( int $post_id ): array {
		$robots = get_post_meta( $post_id, self::RM_META, true );
		if ( ! is_array( $robots ) ) {
			return array();
		}
		return array_values( array_filter( $robots, 'is_string' ) );
	}
}
