<?php
/**
 * REST controller for the native Seonix redirect manager.
 *
 * Routes (registered under both seonix/v1 and content-engine/v1 for
 * back-compat, mirroring the seo-fix controller):
 *   GET  /redirects        Full rule list + tombstones (Seonix-managed
 *                          deletions the service has not seen yet).
 *   POST /redirects/sync   Reconcile: upsert service-managed rules by
 *                          seonix_id, hard-delete by seonix_id, report
 *                          per-item errors, return the fresh state.
 *
 * Auth is the plugin's Bearer connection token (Seonix_Auth::validate_request)
 * — the same permission callback every other machine endpoint uses.
 *
 * Wire contract (the Seonix Go backend implements the client side):
 *
 *   GET → 200 {
 *     "items":      [ {id, seonix_id, from_path, to_url, status_code, enabled, hits, created_at, updated_at} ],
 *     "tombstones": [ {seonix_id, deleted_at} ],
 *     "version":    "<plugin version>"
 *   }
 *
 *   POST body { "upsert":[{seonix_id, from_path, to_url, status_code, enabled}], "delete_seonix_ids":["uuid"] }
 *     → 200 { items, tombstones, version, "applied":n, "deleted":n,
 *             "errors":[{seonix_id, code:"from_path_conflict"|"invalid", message}] }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Controller {

	private const NAMESPACE        = 'seonix/v1';
	private const LEGACY_NAMESPACE = 'content-engine/v1';
	private const BASE             = '/redirects';

	/** @var Seonix_Redirects_Store */
	private $store;

	public function __construct( Seonix_Redirects_Store $store ) {
		$this->store = $store;
	}

	public function register_routes(): void {
		foreach ( array( self::NAMESPACE, self::LEGACY_NAMESPACE ) as $ns ) {
			register_rest_route( $ns, self::BASE, array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			register_rest_route( $ns, self::BASE . '/sync', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_sync' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );
		}
	}

	// ─── Handlers ────────────────────────────────────────────────────────

	/**
	 * GET /redirects
	 */
	public function handle_list( WP_REST_Request $request ) {
		return new WP_REST_Response( $this->state_payload() );
	}

	/**
	 * POST /redirects/sync
	 * Body: { upsert?: item[], delete_seonix_ids?: string[] }
	 */
	public function handle_sync( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'redirects_sync', 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$upsert = $request->get_param( 'upsert' );
		$upsert = is_array( $upsert ) ? $upsert : array();
		// WordPress wp_slash()es incoming REST params; the store compares and
		// persists raw values, so strip the added backslashes first (same
		// treatment as the seo-fix controller).
		$upsert = wp_unslash( $upsert );

		$delete_ids = $request->get_param( 'delete_seonix_ids' );
		$delete_ids = is_array( $delete_ids ) ? array_map( 'strval', $delete_ids ) : array();

		$result = $this->store->apply_sync( $upsert, $delete_ids );

		// Tombstone hygiene rides along with sync — the service is pulling
		// state right now, so anything older than the retention window has
		// had every chance to be observed.
		$this->store->prune_tombstones();

		return new WP_REST_Response( array_merge(
			$this->state_payload(),
			array(
				'applied' => $result['applied'],
				'deleted' => $result['deleted'],
				'errors'  => $result['errors'],
			)
		) );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * The shared items + tombstones + version body both endpoints return.
	 *
	 * @return array<string,mixed>
	 */
	private function state_payload(): array {
		$items = array();
		foreach ( $this->store->get_items() as $row ) {
			$items[] = self::item_payload( $row );
		}

		$tombstones = array();
		foreach ( $this->store->get_tombstones() as $row ) {
			$tombstones[] = array(
				'seonix_id'  => (string) $row['seonix_id'],
				'deleted_at' => (string) $row['deleted_at'],
			);
		}

		return array(
			'items'      => $items,
			'tombstones' => $tombstones,
			'version'    => SEONIX_VERSION,
		);
	}

	/**
	 * Coerce one DB row into the wire shape (wpdb returns everything as
	 * strings; the Go client decodes into typed struct fields).
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function item_payload( array $row ): array {
		$seonix_id = isset( $row['seonix_id'] ) && '' !== (string) $row['seonix_id'] && null !== $row['seonix_id']
			? (string) $row['seonix_id']
			: null;

		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'seonix_id'   => $seonix_id,
			'from_path'   => (string) ( $row['from_path'] ?? '' ),
			'to_url'      => (string) ( $row['to_url'] ?? '' ),
			'status_code' => (int) ( $row['status_code'] ?? 301 ),
			'enabled'     => (bool) (int) ( $row['enabled'] ?? 0 ),
			'hits'        => (int) ( $row['hits'] ?? 0 ),
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
			'updated_at'  => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Per-token, per-action transient bucket — same mechanism as
	 * Seonix_SEO_Fix_Controller::check_rate_limit() so every machine surface
	 * has its own budget.
	 *
	 * @return true|\WP_Error
	 */
	private function check_rate_limit( WP_REST_Request $request, string $action, int $max_per_minute = 60 ) {
		$token = (string) $request->get_header( 'authorization' );
		if ( '' === $token ) {
			$token = (string) $request->get_header( 'X-Seonix-Key' );
		}
		if ( '' === $token ) {
			$token = (string) $request->get_header( 'X-CE-Key' );
		}

		$key   = 'seonix_rl_' . $action . '_' . md5( $token );
		$count = (int) get_transient( $key );
		if ( $count >= $max_per_minute ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests', 'seonix' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
