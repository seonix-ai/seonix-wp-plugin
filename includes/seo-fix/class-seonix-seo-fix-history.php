<?php
/**
 * Persistence layer for SEO-fix runs: dry-runs, applies, no-ops, rollbacks.
 *
 * Every entry captures enough state (before/after JSON snapshots) to support
 * rollback within the configured retention window. The fix_id column carries
 * the backend-issued idempotency key — a fix method can look up prior history
 * by fix_id to short-circuit duplicate applies.
 *
 * Schema is created on activation via dbDelta. Intentionally uses raw $wpdb
 * (not Eloquent / WP REST internal) so the fix runtime stays fast on busy
 * sites and so the tests can mock $wpdb cleanly via Mockery.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_SEO_Fix_History {

	const STATUS_DRY_RUN         = 'dry_run';
	const STATUS_APPLIED         = 'applied';
	const STATUS_ALREADY_APPLIED = 'already_applied';
	const STATUS_ROLLED_BACK     = 'rolled_back';

	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $table;

	public function __construct( $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = $this->wpdb->prefix . 'seonix_seo_fix_history';
	}

	public function table_name(): string {
		return $this->table;
	}

	/**
	 * Install the history table. Safe to call repeatedly — dbDelta is idempotent
	 * and only emits ALTER statements for actual schema diffs.
	 */
	public function create_table(): void {
		$charset = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fix_id VARCHAR(64) NOT NULL,
			method VARCHAR(64) NOT NULL,
			params LONGTEXT NULL,
			target_type VARCHAR(32) NOT NULL DEFAULT '',
			target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			before_state LONGTEXT NULL,
			after_state LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'dry_run',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_fix_id (fix_id),
			KEY idx_method_status (method, status),
			KEY idx_target (target_type, target_id),
			KEY idx_created (created_at)
		) {$charset};";

		// dbDelta lives in wp-admin/includes/upgrade.php in real WP, but in tests
		// it's injected via Brain Monkey, so we don't require_once here.
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}

	public function record_dry_run(
		string $fix_id,
		string $method,
		array $params,
		string $target_type,
		int $target_id,
		$before_state,
		$after_state
	): int {
		return $this->insert_row( $fix_id, $method, $params, $target_type, $target_id, $before_state, $after_state, self::STATUS_DRY_RUN );
	}

	public function record_apply(
		string $fix_id,
		string $method,
		array $params,
		string $target_type,
		int $target_id,
		$before_state,
		$after_state
	): int {
		return $this->insert_row( $fix_id, $method, $params, $target_type, $target_id, $before_state, $after_state, self::STATUS_APPLIED );
	}

	public function record_no_op(
		string $fix_id,
		string $method,
		array $params,
		string $target_type,
		int $target_id,
		$current_state
	): int {
		return $this->insert_row( $fix_id, $method, $params, $target_type, $target_id, $current_state, $current_state, self::STATUS_ALREADY_APPLIED );
	}

	public function mark_rolled_back( int $id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'status' => self::STATUS_ROLLED_BACK ),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/**
	 * Look up the most recent history entry for a given fix_id.
	 * Used for idempotency checks before applying a fix.
	 *
	 * @return array<string,mixed>|null Raw row (still JSON-encoded fields) or null if missing.
	 */
	public function find_by_fix_id( string $fix_id ): ?array {
		// $this->table is internal: $wpdb->prefix . 'seonix_seo_fix_history',
		// never user-controlled. $wpdb placeholders do not support
		// identifiers, so we interpolate the table name.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE fix_id = %s ORDER BY id DESC LIMIT 1",
			$fix_id
		);
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	/**
	 * Fetch a history row by primary key with JSON columns decoded.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		// $this->table is internal (see find_by_fix_id() for rationale).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			$id
		);
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return null;
		}
		return $this->decode_row( $row );
	}

	private function insert_row(
		string $fix_id,
		string $method,
		array $params,
		string $target_type,
		int $target_id,
		$before_state,
		$after_state,
		string $status
	): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'fix_id'       => $fix_id,
				'method'       => $method,
				'params'       => wp_json_encode( $params ),
				'target_type'  => $target_type,
				'target_id'    => $target_id,
				'before_state' => wp_json_encode( $before_state ),
				'after_state'  => wp_json_encode( $after_state ),
				'status'       => $status,
			)
		);
		return (int) $this->wpdb->insert_id;
	}

	private function decode_row( array $row ): array {
		foreach ( array( 'params', 'before_state', 'after_state' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
				$decoded = json_decode( $row[ $field ], true );
				if ( null !== $decoded ) {
					$row[ $field ] = $decoded;
				}
			}
		}
		return $row;
	}
}
