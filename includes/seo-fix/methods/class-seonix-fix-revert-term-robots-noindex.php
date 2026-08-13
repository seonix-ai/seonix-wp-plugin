<?php
/**
 * Fix method: revert_term_robots_noindex.
 *
 * Undo twin of set_term_robots_noindex, dispatched through the same
 * /seo-fix/apply envelope. Restores the term-archive robots value snapshotted
 * under the backend's `revert_token` — through Yoast's public
 * WPSEO_Taxonomy_Meta API (an absent override restores as 'default', which
 * Yoast's own validator strips back out of the option) or Rank Math's
 * `rank_math_robots` term meta — then verifies the live archive no longer
 * renders the noindex.
 *
 * Contract with the Seonix backend:
 *   params   { term_id: int, taxonomy: string, revert_token: string }
 *   response envelope + top-level `applied` / `verified` (+ `verify_details`,
 *            `note: "already_reverted"` when the owner already re-indexed the
 *            archive by hand — reported as the idempotent no-op, never
 *            clobbered).
 *
 * Same guards as the post revert: unknown/foreign token, engine change since
 * apply, and target validation ((term_id, taxonomy) must still resolve).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Revert_Term_Robots_Noindex implements Seonix_Fix_Method {

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'revert_term_robots_noindex';
	}

	public function is_available(): bool {
		return null !== Seonix_Robots_Noindex::engine();
	}

	public function validate_params( array $params ) {
		if ( empty( $params['term_id'] ) || is_array( $params['term_id'] ) || ! is_numeric( $params['term_id'] ) ) {
			return new WP_Error( 'missing_term_id', 'term_id is required and must be a single numeric ID.', array( 'status' => 400 ) );
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
		return $this->describe( $prep );
	}

	public function apply( array $params ) {
		$prep = $this->prepare( $params );
		if ( $prep instanceof WP_Error ) {
			return $prep;
		}
		$result = $this->describe( $prep );

		if ( $result['no_op'] ) {
			return $this->finish( $result, $prep, 'already_reverted' );
		}

		$restored = Seonix_Robots_Noindex::restore_term(
			$prep['term_id'],
			$prep['taxonomy'],
			$prep['engine'],
			$prep['snapshot']['meta_value']
		);
		if ( $restored instanceof WP_Error ) {
			return $restored;
		}

		return $this->finish( $result, $prep, null );
	}

	/**
	 * Rolling back a revert = re-applying the fix; dispatch
	 * set_term_robots_noindex again instead of layering undo-of-undo state.
	 */
	public function rollback( int $history_id ) {
		return new WP_Error(
			'rollback_not_supported',
			'A revert cannot be rolled back — dispatch set_term_robots_noindex again to re-apply the noindex.',
			array( 'status' => 422 )
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Resolve token → snapshot → engine → term → current state, or the
	 * blocking error.
	 *
	 * @return array{term_id:int, taxonomy:string, engine:string, snapshot:array, state:array, term:object}|WP_Error
	 */
	private function prepare( array $params ) {
		$term_id  = (int) $params['term_id'];
		$taxonomy = (string) $params['taxonomy'];
		$token    = Seonix_Robots_Noindex::sanitize_token( $params['revert_token'] );
		$snapshot = Seonix_Robots_Noindex::snapshot_get( $token );

		if ( null === $snapshot ) {
			return new WP_Error(
				'unknown_revert_token',
				'No stored snapshot for that revert_token (it may have been pruned — snapshots are capped).',
				array( 'status' => 404 )
			);
		}
		if ( ( $snapshot['kind'] ?? '' ) !== 'term'
			|| (int) ( $snapshot['term_id'] ?? 0 ) !== $term_id
			|| ( $snapshot['taxonomy'] ?? '' ) !== $taxonomy ) {
			return new WP_Error(
				'revert_token_mismatch',
				'The revert_token belongs to a different target than (term_id, taxonomy).',
				array( 'status' => 422 )
			);
		}

		$engine = Seonix_Robots_Noindex::engine();
		if ( null === $engine ) {
			return Seonix_Robots_Noindex::unsupported_error();
		}
		if ( isset( $snapshot['engine'] ) && $snapshot['engine'] !== $engine ) {
			return new WP_Error(
				'engine_changed',
				'The active SEO plugin changed since this fix was applied — restore the robots setting manually.',
				array( 'status' => 422 )
			);
		}

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
			'snapshot' => $snapshot,
			'state'    => $state,
			'term'     => $term,
		);
	}

	private function describe( array $prep ): array {
		$snapshot_noindexed = ! empty( $prep['snapshot']['noindexed'] );
		return array(
			'before' => array(
				'noindexed' => $prep['state']['noindexed'],
				'engine'    => $prep['engine'],
				'taxonomy'  => $prep['taxonomy'],
			),
			'after'  => array(
				'noindexed' => $snapshot_noindexed,
				'taxonomy'  => $prep['taxonomy'],
			),
			// Nothing to write when the archive is not noindexed any more.
			'no_op'  => ! $prep['state']['noindexed'],
			'target' => array(
				'type'     => 'term',
				'id'       => $prep['term_id'],
				'taxonomy' => $prep['taxonomy'],
			),
		);
	}

	/**
	 * Verify the restored state on the live archive URL and stamp contract
	 * fields (top-level + mirrored into `after` for history replays).
	 */
	private function finish( array $result, array $prep, $note ): array {
		$state = Seonix_Robots_Noindex::term_state( $prep['term_id'], $prep['taxonomy'], $prep['engine'] );
		$now_noindexed = $state instanceof WP_Error ? false : $state['noindexed'];

		$link   = get_term_link( $prep['term'] );
		$verify = Seonix_Robots_Noindex::verify_url( is_string( $link ) ? $link : '', $now_noindexed );

		$result['after']['noindexed'] = $now_noindexed;

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
