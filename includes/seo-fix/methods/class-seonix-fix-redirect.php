<?php
/**
 * Fix method: redirect.
 *
 * Creates 301 redirects in the plugin's OWN redirect manager
 * ({$wpdb->prefix}seonix_redirects, served by Seonix_Redirects_Runner) — no
 * third-party plugin required. Until 2.7.0 this method wrote into the
 * Redirection plugin's wp_redirection_items table and returned a 412 when that
 * plugin was missing; the native table removes the dependency entirely.
 *
 * Rows created here are "Local" (seonix_id NULL): the fix flow has its own
 * idempotency and rollback bookkeeping via fix history, separate from the
 * /redirects/sync reconcile the Seonix service drives. The created row id is
 * remembered in the fix history state (`native_redirect_id`) so rollback can
 * delete precisely that row.
 *
 * Back-compat: rollback still understands history entries written by older
 * plugin versions (their after_state carries `redirect_id`, the row id inside
 * the Redirection plugin's table) and reverses them against that table.
 *
 * Idempotent: skips insert when an active redirect already claims the same
 * source path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Redirect implements Seonix_Fix_Method {

	/** Legacy (pre-2.7.0) storage: the Redirection plugin's table. Rollback-only. */
	private const LEGACY_REDIRECTION_TABLE_SUFFIX = 'redirection_items';

	private Seonix_SEO_Fix_History $history;

	/** @var \wpdb */
	private $wpdb;

	/** @var Seonix_Redirects_Store */
	private $store;

	public function __construct( Seonix_SEO_Fix_History $history, $wpdb = null, Seonix_Redirects_Store $store = null ) {
		$this->history = $history;
		$this->wpdb    = $wpdb ?? $GLOBALS['wpdb'];
		$this->store   = $store ?? new Seonix_Redirects_Store( $wpdb );
	}

	public function key(): string {
		return 'redirect';
	}

	public function validate_params( array $params ) {
		if ( empty( $params['source_url'] ) ) {
			return new WP_Error( 'missing_source_url', 'source_url is required.', array( 'status' => 400 ) );
		}
		if ( empty( $params['target_url'] ) ) {
			return new WP_Error( 'missing_target_url', 'target_url is required.', array( 'status' => 400 ) );
		}
		// The native redirect manager matches exact paths; regex rules were a
		// Redirection-plugin capability the Seonix backend never emits
		// (translator + AI matcher always send match_type "url").
		$match_type = $params['match_type'] ?? 'url';
		if ( 'url' !== $match_type ) {
			return new WP_Error(
				'unsupported_match_type',
				'The native Seonix redirect manager supports exact path matches only (match_type "url").',
				array( 'status' => 422 )
			);
		}
		return true;
	}

	public function dry_run( array $params ) {
		$plan = $this->prepare( $params );
		if ( $plan instanceof WP_Error ) {
			return $plan;
		}

		$existing = $this->store->find_active_conflict( $plan['from_path'] );
		if ( $existing ) {
			return $this->describe_existing( $existing );
		}

		return array(
			'before' => null,
			'after'  => array(
				'from_path'   => $plan['from_path'],
				'source_url'  => $params['source_url'],
				'target_url'  => $plan['to_url'],
				'status_code' => 301,
			),
			'no_op'  => false,
			'target' => array(
				'type' => 'redirect',
				'id'   => 0,
			),
		);
	}

	public function apply( array $params ) {
		$plan = $this->prepare( $params );
		if ( $plan instanceof WP_Error ) {
			return $plan;
		}

		$existing = $this->store->find_active_conflict( $plan['from_path'] );
		if ( $existing ) {
			return $this->describe_existing( $existing );
		}

		$created = $this->store->create( array(
			'seonix_id'   => null,
			'from_path'   => $plan['from_path'],
			'to_url'      => $plan['to_url'],
			'status_code' => 301,
			'enabled'     => true,
		) );
		if ( $created instanceof WP_Error ) {
			return $created;
		}
		if ( ! is_int( $created ) || $created <= 0 ) {
			return new WP_Error(
				'insert_failed',
				'Could not create the redirect row.',
				array( 'status' => 500 )
			);
		}

		return array(
			'before' => null,
			'after'  => array(
				'native_redirect_id' => $created,
				'from_path'          => $plan['from_path'],
				'source_url'         => $params['source_url'],
				'target_url'         => $plan['to_url'],
				'status_code'        => 301,
			),
			'no_op'  => false,
			'target' => array(
				'type' => 'redirect',
				'id'   => $created,
			),
		);
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$after = is_array( $entry['after_state'] ?? null ) ? $entry['after_state'] : array();

		// Native rows (2.7.0+) carry native_redirect_id.
		$native_id = (int) ( $after['native_redirect_id'] ?? 0 );
		if ( $native_id > 0 ) {
			$this->store->hard_delete( $native_id );
			return array(
				'before' => $after,
				'after'  => null,
			);
		}

		// Legacy entries (pre-2.7.0) carry redirect_id — a row id inside the
		// Redirection plugin's table. Keep reversing those against that table.
		$legacy_id = (int) ( $after['redirect_id'] ?? 0 );
		if ( $legacy_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reversing a row this plugin created in the Redirection plugin's table.
			$this->wpdb->delete( $this->legacy_table_name(), array( 'id' => $legacy_id ) );
			return array(
				'before' => $after,
				'after'  => null,
			);
		}

		return new WP_Error( 'invalid_history_entry', 'History entry has no redirect id to delete.', array( 'status' => 422 ) );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Reduce the incoming params to the native rule to create: from_path is
	 * derived from source_url (which the backend may send as an absolute URL
	 * or a site-relative path), target is validated as path-or-http(s).
	 *
	 * Sources that only differ by query string cannot be represented — the
	 * native manager matches on path alone, and silently widening
	 * "/shop?orderby=price" into all of "/shop" could hijack a live page. The
	 * same reasoning refuses the site root.
	 *
	 * @return array{from_path:string,to_url:string}|\WP_Error
	 */
	private function prepare( array $params ) {
		$source = trim( (string) $params['source_url'] );
		$parts  = wp_parse_url( $source );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return new WP_Error(
				'invalid_source_url',
				sprintf( 'source_url has no usable path: %s', $source ),
				array( 'status' => 422 )
			);
		}
		if ( ! empty( $parts['query'] ) ) {
			return new WP_Error(
				'source_query_unsupported',
				'The native redirect manager matches paths only; a redirect scoped to a query string cannot be created. Remove the query from source_url or handle this URL manually.',
				array( 'status' => 422 )
			);
		}

		$from_path = Seonix_Redirects_Store::normalize_from_path( (string) $parts['path'] );
		if ( null === $from_path ) {
			return new WP_Error(
				'invalid_source_url',
				sprintf( 'source_url does not reduce to a valid site path: %s', $source ),
				array( 'status' => 422 )
			);
		}
		if ( '/' === Seonix_Redirects_Store::match_key( $from_path ) ) {
			return new WP_Error(
				'invalid_source_url',
				'Refusing to create a redirect for the site root.',
				array( 'status' => 422 )
			);
		}

		$to_url = trim( (string) $params['target_url'] );
		$check  = Seonix_Redirects_Store::validate_rule( $from_path, $to_url, 301 );
		if ( ! $check['ok'] ) {
			return new WP_Error( 'invalid_target_url', $check['error'], array( 'status' => 422 ) );
		}

		return array(
			'from_path' => $check['from_path'],
			'to_url'    => $check['to_url'],
		);
	}

	/**
	 * Summary for the no-op path (an active rule already claims the path).
	 * Deliberately keyed `existing_redirect_id`, NOT `native_redirect_id`:
	 * rollback only deletes rows whose history entry proves this fix created
	 * them, and a no-op created nothing — rolling one back must refuse rather
	 * than delete a rule that predates the fix.
	 *
	 * @param array<string,mixed> $existing Active native row claiming the path.
	 * @return array<string,mixed>
	 */
	private function describe_existing( array $existing ): array {
		$summary = array(
			'existing_redirect_id' => (int) $existing['id'],
			'from_path'            => (string) $existing['from_path'],
			'target_url'           => (string) $existing['to_url'],
			'status_code'          => (int) $existing['status_code'],
		);
		return array(
			'before' => $summary,
			'after'  => $summary,
			'no_op'  => true,
			'target' => array(
				'type' => 'redirect',
				'id'   => (int) $existing['id'],
			),
		);
	}

	private function legacy_table_name(): string {
		return $this->wpdb->prefix . self::LEGACY_REDIRECTION_TABLE_SUFFIX;
	}
}
