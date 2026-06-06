<?php
/**
 * Fix method: redirect.
 *
 * Creates 301 redirects via the Redirection plugin's wp_redirection_items table.
 * Per the project decision (do not write to .htaccess), this method requires the
 * Redirection plugin to be installed and active. If it isn't, the dry-run /
 * apply path returns a 412 "redirection_plugin_required" so the Seonix UI can
 * surface a clear "install Redirection first" hint to the user.
 *
 * Idempotent: skips insert if a redirect for the same source URL already exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Redirect implements Seonix_Fix_Method {

	private const REDIRECTION_TABLE_SUFFIX = 'redirection_items';

	private Seonix_SEO_Fix_History $history;

	/** @var \wpdb */
	private $wpdb;

	public function __construct( Seonix_SEO_Fix_History $history, $wpdb = null ) {
		$this->history = $history;
		$this->wpdb    = $wpdb ?? $GLOBALS['wpdb'];
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
		$match_type = $params['match_type'] ?? 'url';
		if ( ! in_array( $match_type, array( 'url', 'regex' ), true ) ) {
			return new WP_Error( 'invalid_match_type', 'match_type must be "url" or "regex".', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		$gate = $this->require_redirection();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$existing = $this->find_existing_redirect( $params['source_url'] );
		if ( $existing ) {
			return $this->describe_existing( $params, $existing );
		}

		return $this->describe_planned( $params );
	}

	public function apply( array $params ) {
		$gate = $this->require_redirection();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$existing = $this->find_existing_redirect( $params['source_url'] );
		if ( $existing ) {
			return $this->describe_existing( $params, $existing );
		}

		$row = $this->build_row( $params );
		$ok  = $this->wpdb->insert( $this->table_name(), $row );
		if ( ! $ok ) {
			return new WP_Error(
				'insert_failed',
				sprintf( 'Could not create redirect row: %s', $this->wpdb->last_error ?? 'unknown error' ),
				array( 'status' => 500 )
			);
		}

		$id = (int) $this->wpdb->insert_id;
		return array(
			'before' => null,
			'after'  => array(
				'redirect_id' => $id,
				'source_url'  => $params['source_url'],
				'target_url'  => $params['target_url'],
				'match_type'  => $params['match_type'] ?? 'url',
			),
			'no_op'  => false,
			'target' => array(
				'type' => 'redirect',
				'id'   => $id,
			),
		);
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$after = is_array( $entry['after_state'] ?? null ) ? $entry['after_state'] : array();
		$redirect_id = (int) ( $after['redirect_id'] ?? 0 );

		if ( $redirect_id <= 0 ) {
			return new WP_Error( 'invalid_history_entry', 'History entry has no redirect_id to delete.', array( 'status' => 422 ) );
		}

		$this->wpdb->delete( $this->table_name(), array( 'id' => $redirect_id ) );

		return array(
			'before' => $after,
			'after'  => null,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function table_name(): string {
		return $this->wpdb->prefix . self::REDIRECTION_TABLE_SUFFIX;
	}

	/**
	 * Returns null when the Redirection plugin tables exist on this site,
	 * otherwise a WP_Error the caller can return verbatim.
	 *
	 * @return null|\WP_Error
	 */
	private function require_redirection() {
		$table = $this->table_name();
		// Plugin Check's NotPrepared rule does not recognise $this->wpdb
		// (constructor-injected for testability). The prepare() call below
		// IS used with a %s placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( $found === $table ) {
			return null;
		}
		return new WP_Error(
			'redirection_plugin_required',
			'The Redirection plugin must be installed and active to manage redirects from Seonix. Install it from Plugins → Add New, then re-run this fix.',
			array( 'status' => 412, 'install_url' => 'https://wordpress.org/plugins/redirection/' )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_existing_redirect( string $source_url ): ?array {
		// The Redirection plugin's table name is interpolated because $wpdb
		// placeholders do not support identifiers. The value comes from
		// table_name() which only ever concatenates $wpdb->prefix with the
		// constant REDIRECTION_TABLE_SUFFIX, so it is safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT id, url, action_data FROM {$this->table_name()} WHERE url = %s LIMIT 1",
			$source_url
		);
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_row( array $params ): array {
		$is_regex = ( $params['match_type'] ?? 'url' ) === 'regex';
		return array(
			'url'         => $params['source_url'],
			'match_url'   => $params['source_url'],
			'match_type'  => $is_regex ? 'regex' : 'url',
			'action_type' => 'url',
			'action_data' => $params['target_url'],
			'action_code' => 301,
			'regex'       => $is_regex ? 1 : 0,
			'position'    => 0,
			'status'      => 'enabled',
			'group_id'    => 1,
			'last_count'  => 0,
		);
	}

	private function describe_existing( array $params, array $existing ): array {
		return array(
			'before' => $existing,
			'after'  => $existing,
			'no_op'  => true,
			'target' => array(
				'type' => 'redirect',
				'id'   => (int) $existing['id'],
			),
		);
	}

	private function describe_planned( array $params ): array {
		return array(
			'before' => null,
			'after'  => array(
				'source_url' => $params['source_url'],
				'target_url' => $params['target_url'],
				'match_type' => $params['match_type'] ?? 'url',
			),
			'no_op'  => false,
			'target' => array(
				'type' => 'redirect',
				'id'   => 0,
			),
		);
	}
}
