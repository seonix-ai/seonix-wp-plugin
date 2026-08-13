<?php
/**
 * Fix method: set_post_robots_noindex.
 *
 * Site Health's "this page is a stub — hide it from search until it has real
 * content" one-click fix. Writes a per-post robots noindex through the active
 * SEO plugin's own storage (Yoast postmeta / Rank Math robots array — see
 * Seonix_Robots_Noindex for the engine map), snapshots the exact previous
 * value under the backend-issued `revert_token`, then fetches the live URL to
 * verify the rendered HTML actually carries the noindex.
 *
 * Contract with the Seonix backend (Site Health recommendations):
 *   params   { post_id: int, revert_token: string }
 *   response envelope + top-level `applied` / `verified` (+ `verify_details`
 *            when unverified, `note: "already_noindexed"` on the idempotent
 *            path); no supported engine → WP_Error `no_supported_seo_plugin`
 *            (HTTP 422, error data carries `unsupported_reason`).
 *   undo     dispatch `revert_post_robots_noindex` with the same token (the
 *            classic history-id /seo-fix/rollback also works).
 *
 * Deliberately single-target: hiding a page from search is a per-page
 * editorial decision — `post_id` is one scalar ID, there is no batch mode, so
 * one request can never sweep more than one page (the backend caps its own
 * batches at 50 tasks; each arrives as its own request here).
 *
 * Writes postmeta only — never wp_update_post() — so the post's public
 * "last modified" date is untouched by design (Seonix_Content_Write rule).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Set_Post_Robots_Noindex implements Seonix_Fix_Method {

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'set_post_robots_noindex';
	}

	/**
	 * Advertised to /capabilities: only offered while an engine with a
	 * writable robots contract (Yoast / Rank Math) is active.
	 */
	public function is_available(): bool {
		return null !== Seonix_Robots_Noindex::engine();
	}

	public function validate_params( array $params ) {
		if ( empty( $params['post_id'] ) || is_array( $params['post_id'] ) || ! is_numeric( $params['post_id'] ) ) {
			return new WP_Error( 'missing_post_id', 'post_id is required and must be a single numeric ID (no batch form).', array( 'status' => 400 ) );
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
		return $this->describe( $prep['post_id'], $prep['engine'], $prep['state'] );
	}

	public function apply( array $params ) {
		$prep = $this->prepare( $params );
		if ( $prep instanceof WP_Error ) {
			return $prep;
		}
		$post_id = $prep['post_id'];
		$engine  = $prep['engine'];
		$state   = $prep['state'];

		// Snapshot BEFORE any write, put-if-absent so a retried apply can never
		// replace the true pre-fix state. Taken on the no-op path too: reverting
		// an apply that changed nothing then restores exactly that unchanged state.
		Seonix_Robots_Noindex::snapshot_put_if_absent(
			Seonix_Robots_Noindex::sanitize_token( $params['revert_token'] ),
			array(
				'kind'       => 'post',
				'post_id'    => $post_id,
				'engine'     => $engine,
				'noindexed'  => $state['noindexed'],
				'meta_value' => $state['meta_value'],
				'created_at' => time(),
			)
		);

		$result = $this->describe( $post_id, $engine, $state );

		if ( $result['no_op'] ) {
			return $this->finish( $result, $post_id, 'already_noindexed' );
		}

		$written = Seonix_Robots_Noindex::set_post_noindex( $post_id, $engine );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		return $this->finish( $result, $post_id, null );
	}

	/**
	 * Classic history-id rollback (POST /seo-fix/rollback). Same semantics as
	 * Seonix_Fix_Post_Noindex: engine guard + stale-guard, then a byte-exact
	 * restore of before_state.meta_value.
	 */
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

		$engine = Seonix_Robots_Noindex::engine();
		if ( null === $engine || ( isset( $before['engine'] ) && $before['engine'] !== $engine ) ) {
			return new WP_Error(
				'engine_changed',
				'The active SEO plugin changed since this fix was applied — restore the robots setting manually.',
				array( 'status' => 422 )
			);
		}

		// Stale-guard: refuse when the owner already re-indexed the post by hand;
		// restoring the snapshot would clobber that decision.
		$state = Seonix_Robots_Noindex::post_state( $post_id, $engine );
		if ( ! $state['noindexed'] ) {
			return new WP_Error(
				'rollback_stale',
				'The robots setting was changed after this fix was applied — rolling back now would overwrite that change.',
				array( 'status' => 409 )
			);
		}

		$restored = Seonix_Robots_Noindex::restore_post( $post_id, $engine, $before['meta_value'] );
		if ( $restored instanceof WP_Error ) {
			return $restored;
		}

		return array(
			'before' => array( 'noindexed' => true ),
			'after'  => array( 'noindexed' => Seonix_Robots_Noindex::post_state( $post_id, $engine )['noindexed'] ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Resolve engine + post + current robots state, or the blocking WP_Error.
	 *
	 * @return array{post_id:int, engine:string, state:array}|WP_Error
	 */
	private function prepare( array $params ) {
		$engine = Seonix_Robots_Noindex::engine();
		if ( null === $engine ) {
			return Seonix_Robots_Noindex::unsupported_error();
		}
		$post_id = (int) $params['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
		}
		return array(
			'post_id' => $post_id,
			'engine'  => $engine,
			'state'   => Seonix_Robots_Noindex::post_state( $post_id, $engine ),
		);
	}

	/**
	 * Dry-run/apply payload skeleton. before/after carry the state transition;
	 * meta_value is the exact previous value so the history row alone can
	 * drive a rollback.
	 */
	private function describe( int $post_id, string $engine, array $state ): array {
		return array(
			'before' => array(
				'noindexed'  => $state['noindexed'],
				'engine'     => $engine,
				'meta_value' => $state['meta_value'],
			),
			'after'  => array(
				'noindexed'        => true,
				// Yoast and Rank Math both exclude noindexed posts from their XML
				// sitemaps on their own — surfaced so the dashboard can say it.
				'sitemap_excluded' => true,
			),
			'no_op'  => $state['noindexed'],
			'target' => array(
				'type' => 'post',
				'id'   => $post_id,
			),
		);
	}

	/**
	 * Run post-write verification and stamp the contract fields — top-level
	 * (controller lifts them into the response) and mirrored into `after` (so
	 * a replayed fix_id serves them from the history row).
	 *
	 * @param array       $result  describe() payload.
	 * @param int         $post_id Post ID.
	 * @param string|null $note    'already_noindexed' on the idempotent path.
	 */
	private function finish( array $result, int $post_id, $note ): array {
		$permalink = get_permalink( $post_id );
		$verify    = Seonix_Robots_Noindex::verify_url( is_string( $permalink ) ? $permalink : '', true );

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
