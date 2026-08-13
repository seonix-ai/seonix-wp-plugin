<?php
/**
 * Fix method: set_term_robots_noindex.
 *
 * Site Health's "this category/tag archive duplicates a real content page —
 * hide the archive from search" one-click fix. Same machinery as
 * set_post_robots_noindex but for a taxonomy term archive: the noindex is
 * written through the active SEO plugin's own TERM storage (Yoast's
 * `wpseo_taxonomy_meta` option via the public WPSEO_Taxonomy_Meta API, plus an
 * immediate indexable-row sync; Rank Math's `rank_math_robots` term meta),
 * snapshotted under the backend's `revert_token`, then verified against the
 * live archive URL.
 *
 * Contract with the Seonix backend:
 *   params   { term_id: int, taxonomy: string, revert_token: string }
 *   response envelope + top-level `applied` / `verified` (+ `verify_details`,
 *            `note: "already_noindexed"`); no supported engine → WP_Error
 *            `no_supported_seo_plugin` (422).
 *   undo     dispatch `revert_term_robots_noindex` with the same token.
 *
 * The taxonomy is validated to exist and the term to belong to it — the
 * backend addresses terms by (term_id, taxonomy) pair, and a stale pair must
 * fail loudly rather than noindex whatever term now wears that ID. Single
 * target per request by design (no batch form).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Set_Term_Robots_Noindex implements Seonix_Fix_Method {

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'set_term_robots_noindex';
	}

	public function is_available(): bool {
		return null !== Seonix_Robots_Noindex::engine();
	}

	public function validate_params( array $params ) {
		if ( empty( $params['term_id'] ) || is_array( $params['term_id'] ) || ! is_numeric( $params['term_id'] ) ) {
			return new WP_Error( 'missing_term_id', 'term_id is required and must be a single numeric ID (no batch form).', array( 'status' => 400 ) );
		}
		if ( empty( $params['taxonomy'] ) || ! is_string( $params['taxonomy'] ) ) {
			return new WP_Error( 'missing_taxonomy', 'taxonomy is required and must be a string.', array( 'status' => 400 ) );
		}
		if ( sanitize_key( $params['taxonomy'] ) !== $params['taxonomy'] ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be a registered taxonomy key (lowercase alphanumerics, dashes, underscores).', array( 'status' => 400 ) );
		}
		if ( '' === Seonix_Robots_Noindex::sanitize_token( $params['revert_token'] ?? null ) ) {
			return new WP_Error( 'missing_revert_token', 'revert_token is required (1-128 chars of [A-Za-z0-9._:-]).', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		$prep = $this->prepare( $params );
		if ( $prep instanceof WP_Error ) {
			return $prep;
		}
		return $this->describe( $prep['term_id'], $prep['taxonomy'], $prep['engine'], $prep['state'] );
	}

	public function apply( array $params ) {
		$prep = $this->prepare( $params );
		if ( $prep instanceof WP_Error ) {
			return $prep;
		}
		$term_id  = $prep['term_id'];
		$taxonomy = $prep['taxonomy'];
		$engine   = $prep['engine'];
		$state    = $prep['state'];

		// Snapshot BEFORE any write; put-if-absent guards retried applies.
		Seonix_Robots_Noindex::snapshot_put_if_absent(
			Seonix_Robots_Noindex::sanitize_token( $params['revert_token'] ),
			array(
				'kind'       => 'term',
				'term_id'    => $term_id,
				'taxonomy'   => $taxonomy,
				'engine'     => $engine,
				'noindexed'  => $state['noindexed'],
				'meta_value' => $state['meta_value'],
				'created_at' => time(),
			)
		);

		$result = $this->describe( $term_id, $taxonomy, $engine, $state );

		if ( $result['no_op'] ) {
			return $this->finish( $result, $prep['term'], 'already_noindexed' );
		}

		$written = Seonix_Robots_Noindex::set_term_noindex( $term_id, $taxonomy, $engine );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		return $this->finish( $result, $prep['term'], null );
	}

	/**
	 * Classic history-id rollback: engine guard + stale-guard, then restore
	 * the snapshotted term robots value.
	 */
	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$term_id  = (int) ( $entry['target_id'] ?? 0 );
		$before   = $entry['before_state'] ?? array();
		$taxonomy = is_array( $before ) && isset( $before['taxonomy'] ) ? (string) $before['taxonomy'] : '';
		if ( $term_id <= 0 || '' === $taxonomy || ! is_array( $before ) || ! array_key_exists( 'meta_value', $before ) ) {
			return new WP_Error( 'invalid_history_entry', 'History entry is missing term snapshot.', array( 'status' => 422 ) );
		}

		$engine = Seonix_Robots_Noindex::engine();
		if ( null === $engine || ( isset( $before['engine'] ) && $before['engine'] !== $engine ) ) {
			return new WP_Error(
				'engine_changed',
				'The active SEO plugin changed since this fix was applied — restore the robots setting manually.',
				array( 'status' => 422 )
			);
		}

		$state = Seonix_Robots_Noindex::term_state( $term_id, $taxonomy, $engine );
		if ( $state instanceof WP_Error ) {
			return $state;
		}
		if ( ! $state['noindexed'] ) {
			return new WP_Error(
				'rollback_stale',
				'The robots setting was changed after this fix was applied — rolling back now would overwrite that change.',
				array( 'status' => 409 )
			);
		}

		$restored = Seonix_Robots_Noindex::restore_term( $term_id, $taxonomy, $engine, $before['meta_value'] );
		if ( $restored instanceof WP_Error ) {
			return $restored;
		}

		$after = Seonix_Robots_Noindex::term_state( $term_id, $taxonomy, $engine );
		return array(
			'before' => array( 'noindexed' => true, 'taxonomy' => $taxonomy ),
			'after'  => array(
				'noindexed' => $after instanceof WP_Error ? false : $after['noindexed'],
				'taxonomy'  => $taxonomy,
			),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Resolve engine + taxonomy + term + current robots state, or the
	 * blocking WP_Error.
	 *
	 * @return array{term_id:int, taxonomy:string, engine:string, state:array, term:object}|WP_Error
	 */
	private function prepare( array $params ) {
		$engine = Seonix_Robots_Noindex::engine();
		if ( null === $engine ) {
			return Seonix_Robots_Noindex::unsupported_error();
		}

		$term_id  = (int) $params['term_id'];
		$taxonomy = (string) $params['taxonomy'];

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'unknown_taxonomy', sprintf( 'Taxonomy "%s" is not registered on this site.', $taxonomy ), array( 'status' => 404 ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || $term instanceof WP_Error || (int) ( $term->term_id ?? 0 ) !== $term_id || ( $term->taxonomy ?? '' ) !== $taxonomy ) {
			return new WP_Error(
				'term_not_found',
				sprintf( 'Term %d does not exist in taxonomy "%s".', $term_id, $taxonomy ),
				array( 'status' => 404 )
			);
		}

		$state = Seonix_Robots_Noindex::term_state( $term_id, $taxonomy, $engine );
		if ( $state instanceof WP_Error ) {
			return $state;
		}

		return array(
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'engine'   => $engine,
			'state'    => $state,
			'term'     => $term,
		);
	}

	private function describe( int $term_id, string $taxonomy, string $engine, array $state ): array {
		return array(
			'before' => array(
				'noindexed'  => $state['noindexed'],
				'engine'     => $engine,
				'taxonomy'   => $taxonomy,
				'meta_value' => $state['meta_value'],
			),
			'after'  => array(
				'noindexed'        => true,
				'taxonomy'         => $taxonomy,
				// Both engines skip noindexed terms in their XML sitemaps on
				// their own — no sitemap surgery needed.
				'sitemap_excluded' => true,
			),
			'no_op'  => $state['noindexed'],
			'target' => array(
				'type'     => 'term',
				'id'       => $term_id,
				'taxonomy' => $taxonomy,
			),
		);
	}

	/**
	 * Verify against the live archive URL and stamp contract fields (top-level
	 * + mirrored into `after` for history replays).
	 *
	 * @param array       $result describe() payload.
	 * @param object      $term   Resolved term (WP_Term shaped).
	 * @param string|null $note   'already_noindexed' on the idempotent path.
	 */
	private function finish( array $result, $term, $note ): array {
		$link   = get_term_link( $term );
		$verify = Seonix_Robots_Noindex::verify_url( is_string( $link ) ? $link : '', true );

		$result['applied']  = true;
		$result['verified'] = $verify['verified'];
		if ( '' !== $verify['details'] ) {
			$result['verify_details'] = $verify['details'];
		}
		if ( null !== $note ) {
			$result['note'] = $note;
		}

		$result['after']['applied']  = true;
		$result['after']['verified'] = $verify['verified'];
		if ( null !== $note ) {
			$result['after']['note'] = $note;
		}
		return $result;
	}
}
