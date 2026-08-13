<?php
/**
 * Fix method: revert_post_robots_noindex.
 *
 * Undo twin of set_post_robots_noindex, dispatched through the same
 * /seo-fix/apply envelope. Looks up the snapshot the set-method stored under
 * the backend's `revert_token` and restores the exact previous robots value
 * (including "no override at all" — an absent meta is deleted back to absent),
 * then verifies the live page no longer renders the noindex.
 *
 * Contract with the Seonix backend:
 *   params   { post_id: int, revert_token: string }
 *   response envelope + top-level `applied` / `verified` (+ `verify_details`,
 *            `note: "already_reverted"` when the owner already re-indexed the
 *            page by hand — we refuse to clobber a newer manual state and
 *            report the no-op instead).
 *
 * Guards, mirroring the classic rollback path:
 *   - unknown/foreign token → 404 / 422 (a token must match its target);
 *   - active SEO engine changed since the apply → 422 engine_changed
 *     (restoring Yoast meta while Rank Math owns robots would fix nothing);
 *   - snapshots survive a successful revert, so a retried revert lands on the
 *     idempotent "already_reverted" path instead of an error.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Revert_Post_Robots_Noindex implements Seonix_Fix_Method {

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'revert_post_robots_noindex';
	}

	public function is_available(): bool {
		return null !== Seonix_Robots_Noindex::engine();
	}

	public function validate_params( array $params ) {
		if ( empty( $params['post_id'] ) || is_array( $params['post_id'] ) || ! is_numeric( $params['post_id'] ) ) {
			return new WP_Error( 'missing_post_id', 'post_id is required and must be a single numeric ID.', array( 'status' => 400 ) );
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

		// Owner already re-indexed the page (or the apply never changed it and
		// someone since removed the noindex) — restoring the snapshot now could
		// clobber that newer manual state. Report the idempotent no-op.
		if ( $result['no_op'] ) {
			return $this->finish( $result, $prep, 'already_reverted' );
		}

		$restored = Seonix_Robots_Noindex::restore_post( $prep['post_id'], $prep['engine'], $prep['snapshot']['meta_value'] );
		if ( $restored instanceof WP_Error ) {
			return $restored;
		}

		return $this->finish( $result, $prep, null );
	}

	/**
	 * Rolling back a revert = re-applying the fix; dispatch
	 * set_post_robots_noindex again instead of layering undo-of-undo state.
	 */
	public function rollback( int $history_id ) {
		return new WP_Error(
			'rollback_not_supported',
			'A revert cannot be rolled back — dispatch set_post_robots_noindex again to re-apply the noindex.',
			array( 'status' => 422 )
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Resolve token → snapshot → engine → current state, or the blocking error.
	 *
	 * @return array{post_id:int, engine:string, snapshot:array, state:array}|WP_Error
	 */
	private function prepare( array $params ) {
		$post_id  = (int) $params['post_id'];
		$token    = Seonix_Robots_Noindex::sanitize_token( $params['revert_token'] );
		$snapshot = Seonix_Robots_Noindex::snapshot_get( $token );

		if ( null === $snapshot ) {
			return new WP_Error(
				'unknown_revert_token',
				'No stored snapshot for that revert_token (it may have been pruned — snapshots are capped).',
				array( 'status' => 404 )
			);
		}
		if ( ( $snapshot['kind'] ?? '' ) !== 'post' || (int) ( $snapshot['post_id'] ?? 0 ) !== $post_id ) {
			return new WP_Error(
				'revert_token_mismatch',
				'The revert_token belongs to a different target than post_id.',
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
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
		}

		return array(
			'post_id'  => $post_id,
			'engine'   => $engine,
			'snapshot' => $snapshot,
			'state'    => Seonix_Robots_Noindex::post_state( $post_id, $engine ),
		);
	}

	private function describe( array $prep ): array {
		$snapshot_noindexed = ! empty( $prep['snapshot']['noindexed'] );
		return array(
			'before' => array(
				'noindexed' => $prep['state']['noindexed'],
				'engine'    => $prep['engine'],
			),
			'after'  => array(
				// Restoring the snapshot lands the page back on the snapshot's
				// own robots state (usually indexable again; still noindexed
				// when the apply had been a no-op on an already-hidden page).
				'noindexed' => $snapshot_noindexed,
			),
			// Nothing to write when the page is not noindexed any more.
			'no_op'  => ! $prep['state']['noindexed'],
			'target' => array(
				'type' => 'post',
				'id'   => $prep['post_id'],
			),
		);
	}

	/**
	 * Verify the restored state on the live URL and stamp contract fields.
	 * The expectation follows the state actually restored — after reverting a
	 * no-op apply on an already-hidden page the noindex legitimately stays.
	 */
	private function finish( array $result, array $prep, $note ): array {
		$state     = Seonix_Robots_Noindex::post_state( $prep['post_id'], $prep['engine'] );
		$permalink = get_permalink( $prep['post_id'] );
		$verify    = Seonix_Robots_Noindex::verify_url( is_string( $permalink ) ? $permalink : '', $state['noindexed'] );

		$result['after']['noindexed'] = $state['noindexed'];

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
