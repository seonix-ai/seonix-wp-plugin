<?php
/**
 * Local task store for Seonix.
 *
 * Seonix scans the connected site (on connect + weekly) and turns the issues
 * it finds into "tasks". So the WordPress site never has to hit the Seonix API
 * on every admin page view, the plugin keeps its own copy of the canonical
 * TaskView in a local table and renders the Dashboard straight from it.
 *
 * Freshness is driven two ways:
 *   - PUSH (primary): after a scan, the Seonix backend POSTs the TaskView to
 *     {site}/wp-json/seonix/v1/tasks (see Seonix_REST_API::handle_tasks).
 *   - PULL (fallback): the Dashboard "Refresh" button (and a soft 24h-stale
 *     auto-pull) GETs {engine}/api/plugin/tasks with the plugin's Bearer key.
 *
 * Both paths funnel through upsert_view() so the on-disk shape is identical.
 *
 * The canonical contract is documented in the Seonix backend
 * (docs/TASKS_CONTRACT.md). This file mirrors the field set; it stores raw
 * values and the Dashboard escapes every field on emit.
 *
 * Schema is created on activation via dbDelta and re-applied on version bump,
 * mirroring Seonix_SEO_Fix_History. Uses raw $wpdb so tests can mock it with
 * Mockery exactly like HistoryTest does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Tasks {

	/**
	 * Highest TaskView schema_version this plugin understands. A payload whose
	 * schema_version exceeds this is rejected so a future breaking backend
	 * change can't silently corrupt the local store.
	 *
	 * v2 adds the fourth "speed" category pillar (median per-page PageSpeed
	 * score) and a NULLABLE category `score` — null until the first speed pass,
	 * rendered "—" and excluded from the overall mean. Mirrors the frontend
	 * Site Health page's four-pillar model.
	 */
	const SUPPORTED_SCHEMA_VERSION = 3;

	/** Option holding the JSON summary (open/solved/regressed/score + categories). */
	const OPTION_SUMMARY = 'seonix_tasks_summary';

	/** Option holding the unix timestamp of the last successful sync. */
	const OPTION_SYNCED_AT = 'seonix_tasks_synced_at';

	/** Transient lock guarding the 24h soft auto-pull against a thundering herd. */
	const PULL_LOCK = 'seonix_tasks_pull_lock';

	/** Soft staleness window (seconds) before the Dashboard triggers an auto-pull.
	 * 5 hours (owner-tuned, 2026-07-17): opening the Dashboard re-syncs from
	 * Seonix when the local copy is older than this; fresher views render the
	 * stored copy with zero API calls, and a site nobody opens never syncs. */
	const STALE_AFTER = 18000; // 5h

	/** @var \wpdb|null Resolved lazily so constructing the class never requires $wpdb. */
	private $wpdb;

	/** @var string|null */
	private $table;

	public function __construct( $wpdb = null ) {
		// Resolve $wpdb lazily. An injected instance (tests) wins; otherwise we
		// reach for the global the first time the DB is actually touched. This
		// keeps `new Seonix_Tasks()` cheap and side-effect-free, so callers that
		// only need the connect/verify paths don't pull in $wpdb at all.
		$this->wpdb = $wpdb;
	}

	/**
	 * Resolve the $wpdb handle on first use (injected or global).
	 *
	 * @return \wpdb
	 */
	private function db() {
		if ( null === $this->wpdb ) {
			$this->wpdb = $GLOBALS['wpdb'];
		}
		return $this->wpdb;
	}

	public function table_name(): string {
		if ( null === $this->table ) {
			$this->table = $this->db()->prefix . 'seonix_tasks';
		}
		return $this->table;
	}

	/**
	 * Install / upgrade the tasks table. Safe to call repeatedly — dbDelta is
	 * idempotent and only emits ALTER statements for real schema diffs. Mirrors
	 * Seonix_SEO_Fix_History::create_table().
	 *
	 * Columns mirror the canonical task fields plus a local synced_at. Free-text
	 * fields (title/description/recommendation/affected_url) are LONGTEXT because
	 * the backend stores no length bound on them.
	 */
	public function create_table(): void {
		$wpdb    = $this->db();
		$table   = $this->table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id VARCHAR(191) NOT NULL DEFAULT '',
			code VARCHAR(191) NOT NULL DEFAULT '',
			title TEXT NULL,
			description LONGTEXT NULL,
			recommendation LONGTEXT NULL,
			why_it_matters LONGTEXT NULL,
			how_to_fix_steps LONGTEXT NULL,
			bad_example_code LONGTEXT NULL,
			bad_example_caption TEXT NULL,
			good_example_code LONGTEXT NULL,
			good_example_caption TEXT NULL,
			warnings LONGTEXT NULL,
			severity VARCHAR(20) NOT NULL DEFAULT 'notice',
			priority VARCHAR(20) NOT NULL DEFAULT 'low',
			category VARCHAR(20) NOT NULL DEFAULT 'seo',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			affected_url TEXT NULL,
			affected_count INT UNSIGNED NOT NULL DEFAULT 1,
			affected_pages LONGTEXT NULL,
			first_seen_at DATETIME NULL,
			last_seen_at DATETIME NULL,
			solved_at DATETIME NULL,
			regression_count INT UNSIGNED NOT NULL DEFAULT 0,
			informational TINYINT(1) NOT NULL DEFAULT 0,
			synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_task_id (task_id),
			KEY idx_code (code),
			KEY idx_status (status),
			KEY idx_category (category)
		) {$charset};";

		// dbDelta lives in wp-admin/includes/upgrade.php in real WP, but in tests
		// it's injected via Brain Monkey, so we only require it when present.
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}

	/**
	 * Replace the entire local task set with the rows from a canonical
	 * TaskView, and persist the summary + synced_at.
	 *
	 * Truncate-then-insert (allowed by the plan): a scan produces the full
	 * current picture, so the simplest correct model is "the latest scan IS the
	 * table". Every value is sanitized on store; the vocab fields (severity /
	 * priority / category / status) are clamped to the known enums so a hostile
	 * payload can never inject an unexpected string the Dashboard then renders.
	 *
	 * @param array $view Decoded TaskView (associative array).
	 * @return true|WP_Error True on success; WP_Error on an unsupported schema.
	 */
	public function upsert_view( array $view ) {
		$schema = isset( $view['schema_version'] ) ? (int) $view['schema_version'] : 0;
		if ( $schema > self::SUPPORTED_SCHEMA_VERSION ) {
			return new WP_Error(
				'unsupported_schema',
				'Task payload schema_version is newer than this plugin understands. Update the Seonix plugin.',
				array( 'status' => 400 )
			);
		}

		$tasks = isset( $view['tasks'] ) && is_array( $view['tasks'] ) ? $view['tasks'] : array();

		$wpdb  = $this->db();
		$table = $this->table_name();

		// Replace the full set. delete-all then insert keeps the table in lockstep
		// with the latest scan. $table is internal (prefix + constant), never
		// user-controlled — same rationale as Seonix_SEO_Fix_History.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DELETE FROM {$table}" );

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'task_id'          => $this->str( $task, 'id', 191 ),
					'code'             => $this->str( $task, 'code', 191 ),
					'title'            => $this->str( $task, 'title' ),
					'description'      => $this->str( $task, 'description' ),
					'recommendation'   => $this->str( $task, 'recommendation' ),
					'why_it_matters'   => $this->str( $task, 'why_it_matters' ),
					'how_to_fix_steps' => wp_json_encode( $this->string_list( $task, 'how_to_fix_steps' ) ),
					'bad_example_code'    => $this->raw( $task, 'bad_example_code' ),
					'bad_example_caption' => $this->str( $task, 'bad_example_caption' ),
					'good_example_code'    => $this->raw( $task, 'good_example_code' ),
					'good_example_caption' => $this->str( $task, 'good_example_caption' ),
					'warnings'         => wp_json_encode( $this->string_list( $task, 'warnings' ) ),
					'severity'         => $this->enum( $task, 'severity', array( 'error', 'warning', 'notice' ), 'notice' ),
					'priority'         => $this->enum( $task, 'priority', array( 'high', 'medium', 'low' ), 'low' ),
					'category'         => $this->enum( $task, 'category', array( 'seo', 'technical', 'speed', 'ai' ), 'seo' ),
					'status'           => $this->enum( $task, 'status', array( 'open', 'solved', 'regressed' ), 'open' ),
					'affected_url'     => $this->url( $task, 'affected_url' ),
					'affected_count'   => max( 1, $this->int( $task, 'affected_count' ) ),
					'affected_pages'   => wp_json_encode( $this->pages_json( $task ) ),
					'first_seen_at'    => $this->datetime( $task, 'first_seen_at' ),
					'last_seen_at'     => $this->datetime( $task, 'last_seen_at' ),
					'solved_at'        => $this->datetime( $task, 'solved_at' ),
					'regression_count' => $this->int( $task, 'regression_count' ),
					'informational'    => ! empty( $task['informational'] ) ? 1 : 0,
					'synced_at'        => $now,
				),
				// Format hints, in the same column order as the data array above:
				// strings/text/datetime → '%s', integer counts → '%d'.
				array(
					'%s', // task_id
					'%s', // code
					'%s', // title
					'%s', // description
					'%s', // recommendation
					'%s', // why_it_matters
					'%s', // how_to_fix_steps (JSON)
					'%s', // bad_example_code
					'%s', // bad_example_caption
					'%s', // good_example_code
					'%s', // good_example_caption
					'%s', // warnings (JSON)
					'%s', // severity
					'%s', // priority
					'%s', // category
					'%s', // status
					'%s', // affected_url
					'%d', // affected_count
					'%s', // affected_pages
					'%s', // first_seen_at
					'%s', // last_seen_at
					'%s', // solved_at
					'%d', // regression_count
					'%d', // informational
					'%s', // synced_at
				)
			);
		}

		// Persist the summary (open/solved/regressed/score + category gauges) so
		// the Dashboard can paint the headline numbers without scanning the table.
		$summary    = isset( $view['summary'] ) && is_array( $view['summary'] ) ? $view['summary'] : array();
		$categories = isset( $view['categories'] ) && is_array( $view['categories'] ) ? $view['categories'] : array();

		$stored_summary = array(
			'open'       => isset( $summary['open'] ) ? (int) $summary['open'] : 0,
			'solved'     => isset( $summary['solved'] ) ? (int) $summary['solved'] : 0,
			'regressed'  => isset( $summary['regressed'] ) ? (int) $summary['regressed'] : 0,
			// Canonical page-count headlines computed by the backend on ONE basis
			// (ProblemPageCount — affected pages of real error/warning issue types),
			// so the plugin renders the SAME numbers as the app.seonix.ai dashboard:
			//   active    → the app's activeProblemPageTotal ("Active issues")
			//   fixed     → the app's counts.solved_problems ("Fixed")
			//   came_back → the regressed slice of active ("Came back")
			// -1 = field absent (older backend) → the Dashboard falls back to a
			// local task-row count until the next sync.
			'active'     => isset( $summary['active'] ) ? (int) $summary['active'] : -1,
			'fixed'      => isset( $summary['fixed'] ) ? (int) $summary['fixed'] : -1,
			'came_back'  => isset( $summary['came_back'] ) ? (int) $summary['came_back'] : -1,
			'score'      => isset( $summary['score'] ) ? (int) $summary['score'] : 0,
			'categories' => array(),
		);
		foreach ( $categories as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			// Only the four pillars this plugin renders are stored. An unknown
			// key (e.g. the v3 "content" pillar this version does not render yet)
			// is SKIPPED rather than clamped to "seo" — clamping would create a
			// duplicate SEO gauge in the header.
			$cat_key = isset( $cat['key'] ) ? (string) $cat['key'] : '';
			if ( ! in_array( $cat_key, array( 'seo', 'technical', 'speed', 'ai' ), true ) ) {
				continue;
			}
			// The speed pillar's score is null until the first per-page speed
			// pass measures it. Preserve that null (the Dashboard renders "—" and
			// the backend already excludes it from the overall mean) instead of
			// coercing it to 0, which would read as a real zero score.
			$cat_score = null;
			if ( array_key_exists( 'score', $cat ) && null !== $cat['score'] ) {
				$cat_score = (int) $cat['score'];
			}
			$stored_summary['categories'][] = array(
				'key'   => $cat_key,
				'score' => $cat_score,
				'open'  => isset( $cat['open'] ) ? (int) $cat['open'] : 0,
			);
		}

		update_option( self::OPTION_SUMMARY, wp_json_encode( $stored_summary ) );
		update_option( self::OPTION_SYNCED_AT, time() );

		return true;
	}

	/**
	 * Fetch all stored tasks, ordered for display: active first (open then
	 * regressed), then by severity (error > warning > notice), then code.
	 *
	 * @return array<int,array<string,mixed>> Raw rows (associative).
	 */
	public function all(): array {
		$table = $this->table_name();
		// $table is internal (prefix + constant), never user-controlled.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db()->get_results(
			"SELECT * FROM {$table}
			 ORDER BY
			   FIELD(status, 'open', 'regressed', 'solved'),
			   FIELD(severity, 'error', 'warning', 'notice'),
			   code ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Decoded summary option (open/solved/regressed/score + categories).
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		$raw = get_option( self::OPTION_SUMMARY, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Unix timestamp of the last successful sync, or 0 if never synced.
	 */
	public function synced_at(): int {
		return (int) get_option( self::OPTION_SYNCED_AT, 0 );
	}

	/**
	 * Decode a stored affected_pages JSON string into a safe, indexed list of
	 * `[ 'url' => string, 'status' => string ]` entries. Garbage in (non-JSON,
	 * non-array, missing url) yields an empty array — the renderer falls back to
	 * the single affected_url line when this is empty. Static so the Dashboard
	 * render code can call it without an instance.
	 *
	 * @param mixed $raw The raw affected_pages column value (string|null).
	 * @return array<int,array{url:string,status:string}>
	 */
	public static function decode_pages( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$url = isset( $p['url'] ) && is_scalar( $p['url'] ) ? (string) $p['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			$status = isset( $p['status'] ) && is_scalar( $p['status'] ) ? (string) $p['status'] : 'open';
			if ( ! in_array( $status, array( 'open', 'solved', 'regressed' ), true ) ) {
				$status = 'open';
			}
			$out[] = array( 'url' => $url, 'status' => $status );
		}
		return $out;
	}

	/**
	 * Normalize a URL for tolerant page matching: lowercase host without a
	 * leading "www.", path with no trailing slash, no scheme / query / fragment.
	 * So "https://www.Example.com/About/?x=1" and "http://example.com/about"
	 * compare equal. Static so the meta box can call it without an instance.
	 *
	 * @param string $url Any absolute or relative URL.
	 * @return string Normalized "host/path" (or "" for an empty input).
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( rtrim( $url, '/' ) );
		}
		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$host = preg_replace( '/^www\./', '', $host );
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			$path = '/';
		}
		return $host . $path;
	}

	/**
	 * Return the ACTIVE (open/regressed) issues recorded against one page URL,
	 * sorted error→warning→notice then high→low priority. Used by the per-page
	 * editor meta box to show "what's wrong with THIS page" from the last scan.
	 * Matching is tolerant (see normalize_url) so the WP permalink lines up with
	 * the canonical crawl URL despite www / trailing-slash / scheme differences.
	 *
	 * @param string $url The page URL (typically get_permalink()).
	 * @return array<int,array<string,mixed>> Indexed issue rows for that URL.
	 */
	public function issues_for_url( string $url ): array {
		$target = self::normalize_url( $url );
		if ( '' === $target ) {
			return array();
		}

		$out = array();
		foreach ( $this->all() as $row ) {
			// Find this URL's status within the task's affected-pages list; fall
			// back to the single affected_url + the row's own status.
			$page_status = null;
			foreach ( self::decode_pages( isset( $row['affected_pages'] ) ? $row['affected_pages'] : '' ) as $pg ) {
				if ( self::normalize_url( $pg['url'] ) === $target ) {
					$page_status = $pg['status'];
					break;
				}
			}
			if ( null === $page_status ) {
				$affected_url = isset( $row['affected_url'] ) ? (string) $row['affected_url'] : '';
				if ( '' !== $affected_url && self::normalize_url( $affected_url ) === $target ) {
					$page_status = isset( $row['status'] ) ? (string) $row['status'] : 'open';
				}
			}
			// Not on this page, or already solved on it → not an active page issue.
			if ( null === $page_status || 'solved' === $page_status ) {
				continue;
			}

			$category = isset( $row['category'] ) ? (string) $row['category'] : 'seo';
			if ( ! in_array( $category, array( 'seo', 'technical', 'ai' ), true ) ) {
				$category = 'seo';
			}
			$severity = isset( $row['severity'] ) ? (string) $row['severity'] : 'notice';
			if ( ! in_array( $severity, array( 'error', 'warning', 'notice' ), true ) ) {
				$severity = 'notice';
			}
			$priority = isset( $row['priority'] ) ? (string) $row['priority'] : 'low';
			if ( ! in_array( $priority, array( 'high', 'medium', 'low' ), true ) ) {
				$priority = 'low';
			}

			$steps = json_decode( isset( $row['how_to_fix_steps'] ) ? (string) $row['how_to_fix_steps'] : '', true );
			$warns = json_decode( isset( $row['warnings'] ) ? (string) $row['warnings'] : '', true );
			$out[] = array(
				'title'                => isset( $row['title'] ) ? (string) $row['title'] : '',
				'description'          => isset( $row['description'] ) ? (string) $row['description'] : '',
				'recommendation'       => isset( $row['recommendation'] ) ? (string) $row['recommendation'] : '',
				'category'             => $category,
				'severity'             => $severity,
				'priority'             => $priority,
				'code'                 => isset( $row['code'] ) ? (string) $row['code'] : '',
				'informational'        => ! empty( $row['informational'] ),
				'status'               => $page_status,
				// Remediation detail (mirrored from the backend catalog) so the
				// editor panel can show the same per-issue drill-down as the dashboard.
				'why_it_matters'       => isset( $row['why_it_matters'] ) ? (string) $row['why_it_matters'] : '',
				'how_to_fix_steps'     => is_array( $steps ) ? array_values( array_filter( array_map( 'strval', $steps ) ) ) : array(),
				'bad_example_code'     => isset( $row['bad_example_code'] ) ? (string) $row['bad_example_code'] : '',
				'bad_example_caption'  => isset( $row['bad_example_caption'] ) ? (string) $row['bad_example_caption'] : '',
				'good_example_code'    => isset( $row['good_example_code'] ) ? (string) $row['good_example_code'] : '',
				'good_example_caption' => isset( $row['good_example_caption'] ) ? (string) $row['good_example_caption'] : '',
				'warnings'             => is_array( $warns ) ? array_values( array_filter( array_map( 'strval', $warns ) ) ) : array(),
			);
		}

		$sev_rank  = array( 'error' => 0, 'warning' => 1, 'notice' => 2 );
		$prio_rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
		usort(
			$out,
			static function ( $a, $b ) use ( $sev_rank, $prio_rank ) {
				if ( $sev_rank[ $a['severity'] ] !== $sev_rank[ $b['severity'] ] ) {
					return $sev_rank[ $a['severity'] ] - $sev_rank[ $b['severity'] ];
				}
				return $prio_rank[ $a['priority'] ] - $prio_rank[ $b['priority'] ];
			}
		);
		return $out;
	}

	/**
	 * Pull the latest TaskView from the connected Seonix backend and store it.
	 *
	 * Server-side GET {engine}/api/plugin/tasks with the plugin's own Bearer
	 * key. The response is the canonical TaskView emitted VERBATIM (NOT wrapped
	 * in {data:...}). Reuses the SSRF guard (Seonix_Sync::is_safe_url) before
	 * firing, exactly like the outbound sync path.
	 *
	 * @return true|WP_Error True on success, WP_Error otherwise.
	 */
	public function pull_from_engine() {
		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();

		if ( empty( $engine_url ) ) {
			return new WP_Error( 'not_connected', __( 'Connect this site to Seonix first.', 'seonix' ), array( 'status' => 400 ) );
		}
		if ( ! Seonix_Sync::is_safe_url( $engine_url ) ) {
			return new WP_Error( 'invalid_engine_url', __( 'Configured engine URL is not allowed.', 'seonix' ), array( 'status' => 400 ) );
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'API key is not configured.', 'seonix' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			trailingslashit( $engine_url ) . 'api/plugin/tasks',
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'pull_failed',
				/* translators: %d: HTTP status code returned by the Seonix backend. */
				sprintf( __( 'Seonix returned HTTP %d.', 'seonix' ), $status ),
				array( 'status' => 502 )
			);
		}

		$view = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $view ) ) {
			return new WP_Error( 'bad_payload', __( 'Seonix returned an unreadable response.', 'seonix' ), array( 'status' => 502 ) );
		}

		return $this->upsert_view( $view );
	}

	/**
	 * Soft auto-pull used on Dashboard view: if the local copy is older than
	 * STALE_AFTER, kick a single pull. A short transient lock prevents many
	 * concurrent admin loads from stampeding the backend. Best-effort —
	 * failures are swallowed so a Dashboard view never errors on a flaky pull.
	 *
	 * @return void
	 */
	public function maybe_auto_pull(): void {
		$synced = $this->synced_at();
		if ( $synced > 0 && ( time() - $synced ) < self::STALE_AFTER ) {
			return; // Fresh enough.
		}
		// Single-flight: first caller takes the lock, the rest skip.
		if ( get_transient( self::PULL_LOCK ) ) {
			return;
		}
		set_transient( self::PULL_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		// Only attempt when actually connected — avoids noisy errors on a fresh
		// install that has never paired.
		if ( empty( get_option( 'seonix_engine_url', '' ) ) ) {
			return;
		}
		$this->pull_from_engine(); // Result intentionally ignored (best-effort).
	}

	// ─── sanitizing accessors ─────────────────────────────────────

	/**
	 * Sanitize a string field, optionally length-capping (for VARCHAR columns).
	 */
	private function str( array $task, string $key, int $max = 0 ): string {
		$value = isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ? (string) $task[ $key ] : '';
		$value = sanitize_text_field( $value );
		if ( $max > 0 && strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	/**
	 * Preserve a multi-line / HTML-bearing string field verbatim (e.g. a bad/good
	 * code example). NOT sanitize_text_field — that strips tags and newlines,
	 * which would destroy the example markup. Output is always esc_html()'d at
	 * render time, so storing it raw is safe.
	 */
	private function raw( array $task, string $key ): string {
		return isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ? (string) $task[ $key ] : '';
	}

	/**
	 * Sanitize a payload field that should be a list of plain strings (fix steps,
	 * warnings) into a clean indexed array. Non-arrays and non-scalar members are
	 * dropped; each kept member is sanitize_text_field'd.
	 *
	 * @return array<int,string>
	 */
	private function string_list( array $task, string $key ): array {
		$out = array();
		if ( ! isset( $task[ $key ] ) || ! is_array( $task[ $key ] ) ) {
			return $out;
		}
		foreach ( $task[ $key ] as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$s = sanitize_text_field( (string) $item );
			if ( '' !== $s ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	private function url( array $task, string $key ): string {
		$value = isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ? (string) $task[ $key ] : '';
		return esc_url_raw( $value );
	}

	/**
	 * Build the clean, indexed list of affected pages for one task.
	 *
	 * The canonical task carries a `pages` array — every affected page for that
	 * grouped issue: `[ { "url": "https://…/p", "status": "open" }, … ]`. We
	 * sanitize each entry (URL through esc_url_raw, status clamped to the known
	 * lifecycle enum), drop anything that isn't an array or has no URL, and cap
	 * the result at 200 entries defensively so a hostile payload can't bloat the
	 * stored JSON. The return value is JSON-encoded into the affected_pages
	 * column by upsert_view() and read back via decode_pages().
	 *
	 * @param array<string,mixed> $task One raw task from the TaskView.
	 * @return array<int,array{url:string,status:string}> Indexed, sanitized.
	 */
	private function pages_json( array $task ): array {
		$pages = isset( $task['pages'] ) && is_array( $task['pages'] ) ? $task['pages'] : array();
		$out   = array();
		foreach ( $pages as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$url = isset( $p['url'] ) && is_scalar( $p['url'] ) ? esc_url_raw( (string) $p['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'url'    => $url,
				'status' => $this->enum( $p, 'status', array( 'open', 'solved', 'regressed' ), 'open' ),
			);
			if ( count( $out ) >= 200 ) {
				break; // Defensive cap.
			}
		}
		return $out;
	}

	private function int( array $task, string $key ): int {
		return isset( $task[ $key ] ) ? absint( $task[ $key ] ) : 0;
	}

	/**
	 * Clamp a value to a known enum, falling back to a safe default. Guarantees
	 * the Dashboard only ever renders a vocab term it has a badge/class for.
	 */
	private function enum( array $task, string $key, array $allowed, string $default ): string {
		$value = isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ? (string) $task[ $key ] : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Normalize an RFC3339 (or any strtotime-parseable) timestamp into a MySQL
	 * DATETIME in UTC, or null when missing / unparseable.
	 */
	private function datetime( array $task, string $key ): ?string {
		$value = isset( $task[ $key ] ) && is_scalar( $task[ $key ] ) ? (string) $task[ $key ] : '';
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
