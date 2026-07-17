<?php
/**
 * The 404 log: every dead URL a visitor actually hit, so the operator can turn
 * it into a redirect with one click instead of guessing what is broken.
 *
 * This is the reason people install a dedicated redirect plugin — not the rule
 * table, the log that feeds it. Ours is deliberately smaller than Redirection's
 * per-request log: rows are aggregated BY PATH (one row per dead URL, a hit
 * counter and a last-seen stamp), because on any real site the per-hit log is
 * mostly bot noise hammering the same handful of URLs, and the operator only
 * ever asks "which dead URLs get traffic, and how much". Aggregation answers
 * that and bounds storage to the number of distinct broken paths.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Log {

	/** @var string */
	const TABLE_SUFFIX = 'seonix_redirect_404s';

	/**
	 * Hard cap on distinct paths kept. A busy site is crawled for thousands of
	 * junk URLs; past this we prune the least-recently-seen so the table can
	 * never grow without bound. Comfortably above the count of paths a human
	 * would ever triage.
	 */
	const MAX_ROWS = 1000;

	/** File extensions that are never worth logging — asset 404s, not pages. */
	const SKIP_EXTENSIONS = array( 'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm', 'pdf', 'zip' );

	/** @var wpdb|null */
	private $wpdb;

	/** @var string|null */
	private $table;

	/**
	 * @param wpdb|null $wpdb Injected for tests; defaults to the global.
	 */
	public function __construct( $wpdb = null ) {
		$this->wpdb = $wpdb;
	}

	/** @return wpdb */
	private function db() {
		if ( null !== $this->wpdb ) {
			return $this->wpdb;
		}
		global $wpdb;
		return $wpdb;
	}

	public function table_name(): string {
		if ( null === $this->table ) {
			$this->table = $this->db()->prefix . self::TABLE_SUFFIX;
		}
		return $this->table;
	}

	/**
	 * Create the table. Mirrors the rules store: dbDelta, UUID-free, one row per
	 * distinct path (UNIQUE) so recording is an upsert.
	 */
	public function create_table(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table_name();
		$charset = $this->db()->get_charset_collate();

		// path is UNIQUE so record() upserts. 190 chars keeps the index within
		// InnoDB's utf8mb4 limit while still holding any realistic path.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			path VARCHAR(190) NOT NULL,
			hits INT UNSIGNED NOT NULL DEFAULT 1,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY path (path),
			KEY last_seen_at (last_seen_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Normalize a raw request path for logging: strip the query string, decode,
	 * lower-case, collapse a trailing slash. Returns '' for anything we should
	 * not log — the front page, an asset, an over-long path, a non-path.
	 *
	 * @param string $raw Raw REQUEST_URI or path.
	 */
	public static function normalize( $raw ): string {
		$raw = (string) $raw;
		// Drop the query string and fragment — /x?utm=1 and /x are the same dead
		// page, and the counter should reflect that.
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}
		$path = rawurldecode( $path );
		$path = strtolower( $path );
		// The front page is not a 404; never log '/'.
		if ( '/' === $path || '' === trim( $path, '/' ) ) {
			return '';
		}
		// Collapse a single trailing slash so /old and /old/ aggregate together.
		$path = '/' . trim( $path, '/' );

		// Asset extensions are noise — a missing image is not a page redirect.
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && in_array( $ext, self::SKIP_EXTENSIONS, true ) ) {
			return '';
		}
		// The column is 190 chars; a longer path is almost always a scanner probe.
		if ( strlen( $path ) > 190 ) {
			return '';
		}
		return $path;
	}

	/**
	 * Record one hit on a dead path (upsert: new row, or +1 on the existing).
	 *
	 * Returns silently on an unloggable path. Prunes opportunistically when the
	 * table is over the cap so it never runs away, without a cron.
	 *
	 * @param string $raw_path Raw request path.
	 */
	public function record( string $raw_path ): void {
		$path = self::normalize( $raw_path );
		if ( '' === $path ) {
			return;
		}
		$wpdb  = $this->db();
		$table = $this->table_name();
		$now   = current_time( 'mysql', true );

		// Upsert: INSERT, or bump hits + last_seen on the existing path row.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (path, hits, last_seen_at, created_at) VALUES (%s, 1, %s, %s)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen_at = VALUES(last_seen_at)",
				$path,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->maybe_prune();
	}

	/**
	 * Keep the table under MAX_ROWS by dropping the least-recently-seen paths.
	 * Only touches the DB when actually over the cap.
	 */
	private function maybe_prune(): void {
		$wpdb  = $this->db();
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count <= self::MAX_ROWS ) {
			return;
		}
		$excess = $count - self::MAX_ROWS;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} ORDER BY last_seen_at ASC LIMIT %d",
				$excess
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The busiest dead paths, most-hit first.
	 *
	 * @param int $limit
	 * @return array<int,array<string,mixed>>
	 */
	public function get_top( int $limit = 100 ): array {
		$wpdb  = $this->db();
		$table = $this->table_name();
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, path, hits, last_seen_at FROM {$table} ORDER BY hits DESC, last_seen_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/** Total distinct dead paths on record. */
	public function count(): int {
		$wpdb  = $this->db();
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Forget one logged path (dismissed, or a redirect was just created for it). */
	public function delete( int $id ): void {
		$wpdb = $this->db();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table_name(), array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Forget a path once it has a redirect, so a resolved URL leaves the log. */
	public function forget_path( string $path ): void {
		$path = self::normalize( $path );
		if ( '' === $path ) {
			return;
		}
		$wpdb = $this->db();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table_name(), array( 'path' => $path ), array( '%s' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Empty the log. */
	public function clear(): void {
		$wpdb  = $this->db();
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Every logged path, busiest first. The bulk noise-dismiss sweeps the whole
	 * log, not just the rendered page; MAX_ROWS keeps this bounded.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$wpdb  = $this->db();
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, path, hits, last_seen_at FROM {$table} ORDER BY hits DESC, last_seen_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Forget a batch of logged paths by id (a bulk dismiss).
	 *
	 * @param int[] $ids
	 */
	public function delete_ids( array $ids ): void {
		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);
		if ( empty( $ids ) ) {
			return;
		}
		$wpdb  = $this->db();
		$table = $this->table_name();
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$marks})", $ids ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
