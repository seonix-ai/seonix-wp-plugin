<?php
/**
 * Persistence layer for the native Seonix redirect manager.
 *
 * Redirect rules live in a dedicated {$wpdb->prefix}seonix_redirects table.
 * Rows come from two sources:
 *   - the Seonix service, which manages rows through POST /redirects/sync and
 *     stamps each with its own UUID (`seonix_id`);
 *   - the site operator, who creates "Local" rows from the wp-admin Redirects
 *     screen (`seonix_id` IS NULL).
 *
 * Deletions are asymmetric on purpose: deleting a Seonix-managed row locally
 * sets `deleted_at` (a tombstone) so the service learns about the deletion on
 * its next GET /redirects, while service-initiated deletions and Local rows
 * are removed outright. Tombstones are pruned during sync (max 200 rows, max
 * 90 days).
 *
 * from_path uniqueness is enforced in code among `deleted_at IS NULL` rows —
 * MySQL cannot express a partial unique index — and the comparison happens on
 * the runtime MATCH KEY (lower-cased, trailing-slash-insensitive), because two
 * rows that differ only in case or trailing slash would collide in the
 * redirect map and one of them would silently never fire.
 *
 * Schema is created on activation and re-applied by the version-gated dbDelta
 * in seonix_init(), mirroring Seonix_Tasks. Raw $wpdb (lazily resolved) so
 * tests can inject a Mockery double exactly like TasksTest / HistoryTest do.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Store {

	/** Table suffix under $wpdb->prefix. */
	const TABLE_SUFFIX = 'seonix_redirects';

	/** Cache key (both wp_cache and transient) for the runtime redirect map. */
	const CACHE_KEY = 'seonix_redirects_map';

	/** wp_cache group. */
	const CACHE_GROUP = 'seonix';

	/** Keep at most this many tombstones (pruned oldest-first during sync). */
	const TOMBSTONE_MAX = 200;

	/** Tombstones older than this many days are pruned during sync. */
	const TOMBSTONE_TTL_DAYS = 90;

	/**
	 * Status codes a rule may carry.
	 *
	 * 301/308 permanent, 302/307 temporary — the pairs differ only in whether the
	 * browser may turn a POST into a GET (301/302 historically do; 307/308 must
	 * not), which matters for form endpoints. 410 is not a redirect at all: it
	 * tells crawlers the URL is gone for good, which drops it from the index far
	 * faster than a 404, so it needs no target.
	 */
	const ALLOWED_STATUS_CODES = array( 301, 302, 307, 308, 410 );

	/** Codes that send the visitor somewhere and therefore require a target. */
	const TARGETLESS_STATUS_CODES = array( 410 );

	/**
	 * Longest regex pattern we accept.
	 *
	 * A regex runs against every unmatched request, so a pathological pattern is
	 * a self-inflicted DoS. The cap doesn't prevent catastrophic backtracking on
	 * its own — compile_regex() also enforces a backtrack limit at match time —
	 * but it keeps the obvious footguns out of the table.
	 */
	const REGEX_MAX_LENGTH = 255;

	/** @var \wpdb|null Resolved lazily so constructing the class never requires $wpdb. */
	private $wpdb;

	/** @var string|null */
	private $table;

	public function __construct( $wpdb = null ) {
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
			$this->table = $this->db()->prefix . self::TABLE_SUFFIX;
		}
		return $this->table;
	}

	/**
	 * Install / upgrade the redirects table. Safe to call repeatedly — dbDelta
	 * is idempotent and only emits ALTER statements for real schema diffs.
	 * Mirrors Seonix_SEO_Fix_History::create_table().
	 *
	 * seonix_id is CHAR(36) (canonical UUID length) and UNIQUE — MySQL unique
	 * indexes allow any number of NULLs, so Local rows don't collide.
	 */
	public function create_table(): void {
		$wpdb    = $this->db();
		$table   = $this->table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			seonix_id CHAR(36) NULL DEFAULT NULL,
			from_path VARCHAR(191) NOT NULL,
			to_url TEXT NULL DEFAULT NULL,
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			is_regex TINYINT(1) NOT NULL DEFAULT 0,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_accessed_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			deleted_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_seonix_id (seonix_id),
			KEY idx_from_path (from_path),
			KEY idx_deleted_at (deleted_at),
			KEY idx_regex (is_regex)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
		$this->relax_to_url_nullability();
	}

	/**
	 * Make to_url nullable on tables created before 410 support existed.
	 *
	 * dbDelta adds columns and indexes but does NOT reliably change an existing
	 * column's nullability — it compares the column definition loosely and skips
	 * NOT NULL → NULL. A table created by an older version therefore keeps
	 * `to_url TEXT NOT NULL`, and every 410 insert fails silently (wpdb returns
	 * false, create() hands back id 0, and the rule just never appears).
	 *
	 * Runs only when the column is actually still NOT NULL, so it's a no-op on
	 * fresh installs and on every subsequent upgrade.
	 */
	private function relax_to_url_nullability(): void {
		$wpdb  = $this->db();
		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'to_url'" );
		if ( ! $column || ! isset( $column->Null ) || 'NO' !== $column->Null ) {
			return;
		}
		$wpdb->query( "ALTER TABLE {$table} MODIFY to_url TEXT NULL DEFAULT NULL" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	// ─── Normalization & validation (pure, unit-tested directly) ─────────

	/**
	 * Normalize a raw from_path into its storable form.
	 *
	 * Rules: leading '/', path only (query/fragment stripped), no scheme or
	 * host, must fit VARCHAR(191). Trailing slash is preserved as given —
	 * matching is slash-insensitive at runtime, storage stays faithful.
	 *
	 * @param mixed $raw Raw path input.
	 * @return string|null Normalized path, or null when unrepresentable.
	 */
	public static function normalize_from_path( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$path = trim( $raw );
		if ( '' === $path ) {
			return null;
		}
		// No scheme (https://…) and no protocol-relative (//host/…) sources.
		if ( false !== strpos( $path, '://' ) || 0 === strpos( $path, '//' ) ) {
			return null;
		}
		if ( '/' !== $path[0] ) {
			return null;
		}
		// Query and fragment are never part of matching — strip them.
		$cut = strcspn( $path, '?#' );
		if ( $cut < strlen( $path ) ) {
			$path = substr( $path, 0, $cut );
		}
		if ( '' === $path || strlen( $path ) > 191 ) {
			return null;
		}
		return $path;
	}

	/**
	 * Reduce a path to the key the runtime matcher (and the uniqueness check)
	 * operates on: url-decoded, lower-cased, trailing-slash-insensitive. The
	 * site root stays '/'.
	 *
	 * mb_strtolower because decoded paths are UTF-8 (WordPress permalinks
	 * routinely carry non-ASCII slugs) and byte-wise strtolower() would leave
	 * "Ü" and "ü" as different keys; plain strtolower is the fallback on
	 * hosts without mbstring.
	 */
	public static function match_key( string $path ): string {
		$key = urldecode( $path );
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $key, 'UTF-8' ) : strtolower( $key );
		$key = rtrim( $key, '/' );
		return '' === $key ? '/' : $key;
	}

	/**
	 * Validate a redirect target. Accepts a site-relative path ('/x', not
	 * protocol-relative '//x') or an absolute http(s) URL — everything else
	 * (javascript:, data:, bare words) is rejected.
	 */
	public static function is_valid_to_url( $to_url ): bool {
		if ( ! is_string( $to_url ) || '' === trim( $to_url ) ) {
			return false;
		}
		$to_url = trim( $to_url );
		if ( '/' === $to_url[0] ) {
			return 0 !== strpos( $to_url, '//' );
		}
		return (bool) preg_match( '#^https?://#i', $to_url );
	}

	/**
	 * Canonicalize a redirect target's PATH to the site's own trailing-slash
	 * convention, so our 301 lands directly on the page's canonical form.
	 *
	 * Why: WordPress (and most hosts at the server level) 301 a page to its
	 * canonical slash form — with pretty permalinks ending "/", /a/b sits one
	 * extra hop away from /a/b/. A fix that writes the uncanonical form buys
	 * every visitor and crawler an avoidable second redirect: the wohnart
	 * AI-verdict fix did exactly that (target …/oberkraemer on a slashed-
	 * permalink site → 301 chain of three). Applied on save (validate_rule)
	 * AND at serve time (runner), so already-stored rows are healed without a
	 * data migration.
	 *
	 * Untouched: external hosts (not our canonical space to reason about),
	 * targets whose last segment looks like a file (/brochure.pdf), plain-
	 * permalink sites (no convention to follow), and the bare root "/".
	 * Regex rules are exempt at the call sites — their targets carry $1
	 * expansions, not literal paths.
	 */
	public static function canonicalize_target( string $to_url ): string {
		if ( '' === $to_url ) {
			return $to_url;
		}
		// Absolute URL: only this site's own host follows our permalink
		// convention. Rebuild with the canonical path, preserving everything else.
		if ( preg_match( '#^https?://#i', $to_url ) ) {
			$home_host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
			$host      = wp_parse_url( $to_url, PHP_URL_HOST );
			if ( '' === $home_host || ! is_string( $host ) || 0 !== strcasecmp( $host, $home_host ) ) {
				return $to_url;
			}
			$parts = wp_parse_url( $to_url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return $to_url;
			}
			$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
			$canon = self::canonical_slash_form( $path );
			if ( $canon === $path ) {
				return $to_url;
			}
			$rebuilt  = $parts['scheme'] . '://' . $parts['host'];
			$rebuilt .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			$rebuilt .= $canon;
			$rebuilt .= isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
			$rebuilt .= isset( $parts['fragment'] ) && '' !== $parts['fragment'] ? '#' . $parts['fragment'] : '';
			return $rebuilt;
		}
		// Relative: canonicalize the path part, keep query/fragment verbatim.
		if ( '/' !== $to_url[0] || 0 === strpos( $to_url, '//' ) ) {
			return $to_url;
		}
		$cut    = strcspn( $to_url, '?#' );
		$path   = substr( $to_url, 0, $cut );
		$suffix = (string) substr( $to_url, $cut );
		return self::canonical_slash_form( $path ) . $suffix;
	}

	/**
	 * The site's canonical trailing-slash form of one local path. Pure string
	 * logic over get_option('permalink_structure'): structure ending "/" →
	 * slashed, structure set but unslashed → unslashed, plain permalinks ("")
	 * → the path as given.
	 */
	public static function canonical_slash_form( string $path ): string {
		if ( '' === $path || '/' === $path ) {
			return $path;
		}
		// Never touch a path whose last segment looks like a file (.pdf, .xml…):
		// files have no canonical slash form and slashing them 404s.
		$last = (string) substr( $path, (int) strrpos( $path, '/' ) + 1 );
		if ( '' !== $last && false !== strpos( $last, '.' ) ) {
			return $path;
		}
		$structure = function_exists( 'get_option' ) ? (string) get_option( 'permalink_structure', '' ) : '';
		if ( '' === $structure ) {
			return $path;
		}
		if ( '/' === substr( $structure, -1 ) ) {
			return rtrim( $path, '/' ) . '/';
		}
		$trimmed = rtrim( $path, '/' );
		return '' === $trimmed ? '/' : $trimmed;
	}

	/**
	 * Validate one redirect rule (shared by REST sync, the admin form, and the
	 * seo-fix method).
	 *
	 * @param mixed $from_path_raw Raw from_path.
	 * @param mixed $to_url        Raw to_url.
	 * @param mixed $status_code   Raw status code.
	 * @return array{ok:bool, from_path?:string, to_url?:string, status_code?:int, error?:string}
	 */
	public static function validate_rule( $from_path_raw, $to_url, $status_code, $is_regex = false ): array {
		$is_regex    = (bool) $is_regex;
		$status_code = (int) $status_code;

		if ( ! in_array( $status_code, self::ALLOWED_STATUS_CODES, true ) ) {
			return array(
				'ok'    => false,
				'error' => 'status_code must be one of 301, 302, 307, 308, 410.',
			);
		}

		$targetless = in_array( $status_code, self::TARGETLESS_STATUS_CODES, true );

		// ── from_path ───────────────────────────────────────────────────
		if ( $is_regex ) {
			$pattern = is_string( $from_path_raw ) ? trim( $from_path_raw ) : '';
			if ( '' === $pattern ) {
				return array( 'ok' => false, 'error' => 'A regex rule needs a pattern.' );
			}
			if ( strlen( $pattern ) > self::REGEX_MAX_LENGTH ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'Regex pattern is too long (max %d characters).', self::REGEX_MAX_LENGTH ),
				);
			}
			if ( null === self::compile_regex( $pattern ) ) {
				return array( 'ok' => false, 'error' => 'That regex pattern is not valid.' );
			}
			$from_path = $pattern;
		} else {
			$from_path = self::normalize_from_path( $from_path_raw );
			if ( null === $from_path ) {
				return array(
					'ok'    => false,
					'error' => 'from_path must be a site-relative path starting with "/" (no scheme, host, or query), max 191 chars.',
				);
			}
		}

		// ── to_url ──────────────────────────────────────────────────────
		if ( $targetless ) {
			// 410 says "this is gone" — there is nowhere to send anyone. Accept a
			// blank target and store NULL rather than pretending an empty string
			// is a URL.
			return array(
				'ok'          => true,
				'from_path'   => $from_path,
				'to_url'      => null,
				'status_code' => $status_code,
				'is_regex'    => $is_regex,
			);
		}

		if ( ! self::is_valid_to_url( $to_url ) ) {
			return array(
				'ok'    => false,
				'error' => 'to_url must be a non-empty relative path or an absolute http(s) URL.',
			);
		}
		$to_url = trim( (string) $to_url );

		// Land the fix on the page's canonical slash form so the stored rule is
		// the LAST hop, not one 301 short of it (see canonicalize_target). Regex
		// rules are exempt: their targets carry $1 expansions, not literal paths.
		if ( ! $is_regex ) {
			$to_url = self::canonicalize_target( $to_url );
		}

		// Self-redirect guard: a relative target that resolves to the same match
		// key would loop on itself at runtime — reject upfront. Regex rules are
		// exempt: their target usually carries $1-style references, so comparing
		// the literal strings would be meaningless (the runner's own self-target
		// check catches an expanded loop at request time).
		if ( ! $is_regex && '/' === $to_url[0] ) {
			$target_path = self::normalize_from_path( $to_url );
			if ( null !== $target_path && self::match_key( $target_path ) === self::match_key( $from_path ) ) {
				return array(
					'ok'    => false,
					'error' => 'to_url must differ from from_path.',
				);
			}
		}

		return array(
			'ok'          => true,
			'from_path'   => $from_path,
			'to_url'      => $to_url,
			'status_code' => $status_code,
			'is_regex'    => $is_regex,
		);
	}

	/**
	 * Turn a stored pattern into a delimited PCRE, or null if it won't compile.
	 *
	 * Patterns are stored bare ("^/blog/(\d+)/?$") — the operator never writes
	 * delimiters, so we own them and `#` is used with the `u` (UTF-8) and `i`
	 * (case-insensitive) flags to match the literal matcher's own case rules.
	 * Any `#` inside the pattern is escaped first so a stray one can't terminate
	 * the expression early and smuggle in flags.
	 *
	 * @param string $pattern Bare pattern as stored.
	 * @return string|null Delimited pattern ready for preg_*, or null if invalid.
	 */
	public static function compile_regex( string $pattern ): ?string {
		$delimited = '#' . str_replace( '#', '\#', $pattern ) . '#iu';

		// A pattern that doesn't compile returns false AND emits a warning; the
		// operator is mid-typing, that's expected, so silence it and report null.
		$ok = @preg_match( $delimited, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid patterns are user input, not an exceptional condition.
		if ( false === $ok ) {
			return null;
		}
		return $delimited;
	}

	// ─── Reads ────────────────────────────────────────────────────────────

	/**
	 * All non-tombstoned rows (enabled AND disabled), oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_items(): array {
		$wpdb = $this->db();
		// $this->table_name() is internal: $wpdb->prefix plus a class constant,
		// never user-controlled. $wpdb placeholders do not support identifiers.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT * FROM {$this->table_name()} WHERE deleted_at IS NULL ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rules are currently being served.
	 *
	 * Counted in SQL rather than by measuring get_active_rows(): this runs on
	 * every Seonix admin screen to fill the nav-tab badge, and a badge is not
	 * worth pulling every row across the wire for.
	 */
	public function count_active(): int {
		$wpdb = $this->db();
		// Internal identifier interpolation — see get_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name()} WHERE deleted_at IS NULL AND enabled = 1"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Enabled, non-tombstoned rows — the input for the runtime redirect map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_active_rows(): array {
		$wpdb = $this->db();
		// Internal identifier interpolation — see get_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// is_regex MUST be selected: the runner splits these rows into the literal
		// map and the regex pass by that flag, so omitting it silently files every
		// pattern as a literal path — which matches nothing and makes regex rules
		// look like they were never saved.
		$rows = $wpdb->get_results(
			"SELECT id, from_path, to_url, status_code, is_regex FROM {$this->table_name()} WHERE deleted_at IS NULL AND enabled = 1 ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Tombstoned rows the Seonix service cares about (only rows it manages —
	 * Local rows are hard-deleted and never tombstone).
	 *
	 * @return array<int,array{seonix_id:string,deleted_at:string}>
	 */
	public function get_tombstones(): array {
		$wpdb = $this->db();
		// Internal identifier interpolation — see get_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT seonix_id, deleted_at FROM {$this->table_name()} WHERE deleted_at IS NOT NULL AND seonix_id IS NOT NULL ORDER BY deleted_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch one row by primary key (tombstoned or not).
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		$wpdb = $this->db();
		// Internal identifier interpolation — see get_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ?: null;
	}

	/**
	 * Find the active (non-tombstoned) row whose from_path collides with the
	 * given path on the runtime match key. Comparison happens in PHP because
	 * the key is lower-cased + trailing-slash-insensitive, which SQL cannot
	 * express portably; redirect tables are small so the scan is cheap.
	 *
	 * @param string $from_path  Normalized candidate path.
	 * @param int    $exclude_id Row id to ignore (when updating that row).
	 * @return array<string,mixed>|null
	 */
	public function find_active_conflict( string $from_path, int $exclude_id = 0 ): ?array {
		$key = self::match_key( $from_path );
		foreach ( $this->get_items() as $row ) {
			if ( $exclude_id > 0 && (int) $row['id'] === $exclude_id ) {
				continue;
			}
			if ( self::match_key( (string) $row['from_path'] ) === $key ) {
				return $row;
			}
		}
		return null;
	}

	// ─── Writes ───────────────────────────────────────────────────────────

	/**
	 * Create a rule after validating it and checking from_path uniqueness
	 * among active rows. Used by the admin form and the seo-fix method; the
	 * sync path plans its own conflict checks batch-wide (see plan_sync).
	 *
	 * @param array{from_path:string,to_url:string,status_code?:int,enabled?:bool,seonix_id?:?string} $data
	 * @return int|\WP_Error New row id.
	 */
	public function create( array $data ) {
		$check = self::validate_rule(
			$data['from_path'] ?? '',
			$data['to_url'] ?? '',
			$data['status_code'] ?? 301,
			! empty( $data['is_regex'] )
		);
		if ( ! $check['ok'] ) {
			return new WP_Error( 'invalid_redirect', $check['error'], array( 'status' => 422 ) );
		}

		// Uniqueness is a literal-path property: two regexes may legitimately
		// overlap (the runner takes the first match, oldest first), and a regex
		// never collides with a literal because they're matched in separate
		// passes.
		if ( empty( $check['is_regex'] ) && null !== $this->find_active_conflict( $check['from_path'] ) ) {
			return new WP_Error(
				'from_path_conflict',
				sprintf( 'A redirect for %s already exists.', $check['from_path'] ),
				array( 'status' => 409 )
			);
		}

		$id = $this->insert_row( array(
			'seonix_id'   => $data['seonix_id'] ?? null,
			'from_path'   => $check['from_path'],
			'to_url'      => $check['to_url'],
			'status_code' => $check['status_code'],
			'is_regex'    => ! empty( $check['is_regex'] ) ? 1 : 0,
			'enabled'     => ( ! isset( $data['enabled'] ) || $data['enabled'] ) ? 1 : 0,
		) );

		// insert_row() reports a rejected write as id 0. Returning that as if it
		// were an id makes the failure invisible: the caller shows "Redirect
		// added" and the rule simply isn't there (which is exactly how a stale
		// NOT NULL to_url column swallowed every 410).
		if ( $id <= 0 ) {
			return new WP_Error(
				'redirect_insert_failed',
				__( 'The redirect could not be saved. Please try again.', 'seonix' ),
				array( 'status' => 500 )
			);
		}

		return $id;
	}

	/**
	 * Raw insert (no validation — callers validate first). Invalidates the map.
	 *
	 * @param array<string,mixed> $data Column => value.
	 * @return int New row id (0 on failure).
	 */
	public function insert_row( array $data ): int {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		$ok = $wpdb->insert( $this->table_name(), $data );
		$this->invalidate_cache();
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Raw update by id (no validation — callers validate first). Invalidates
	 * the map. `deleted_at => null` in $data resurrects a tombstoned row.
	 *
	 * @param array<string,mixed> $data Column => value.
	 */
	public function update_row( int $id, array $data ): bool {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		$result = $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) );
		$this->invalidate_cache();
		return false !== $result;
	}

	/**
	 * Enable / disable a rule.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update_row( $id, array( 'enabled' => $enabled ? 1 : 0 ) );
	}

	/**
	 * Soft-delete: keep the row as a tombstone so the Seonix service learns
	 * about the local deletion on its next pull. Only meaningful for
	 * Seonix-managed rows — Local rows should be hard_delete()d.
	 */
	public function tombstone( int $id ): bool {
		return $this->update_row( $id, array(
			'deleted_at' => gmdate( 'Y-m-d H:i:s' ),
			'enabled'    => 0,
		) );
	}

	/**
	 * Remove a row outright (Local rows, service-initiated deletions, seo-fix
	 * rollbacks).
	 */
	public function hard_delete( int $id ): bool {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		$result = $wpdb->delete( $this->table_name(), array( 'id' => $id ) );
		$this->invalidate_cache();
		return false !== $result;
	}

	/**
	 * Count a hit on a matched rule. Deliberately a single UPDATE with no map
	 * invalidation — hits are not part of the redirect map, and invalidating
	 * on every front-end match would defeat the cache entirely.
	 */
	public function increment_hits( int $id ): void {
		$wpdb = $this->db();
		// last_accessed_at rides along with the counter: "342 hits" says nothing
		// about whether the rule still earns its place, "342 hits, last one in
		// March" says it can probably go.
		// Internal identifier interpolation — see get_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name()} SET hits = hits + 1, last_accessed_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ─── Sync reconcile ───────────────────────────────────────────────────

	/**
	 * Pure planning step for POST /redirects/sync — computes the operations a
	 * sync payload implies against a snapshot of the current table, without
	 * touching the database. Extracted so the reconcile semantics are
	 * unit-testable with plain arrays.
	 *
	 * Semantics:
	 *   - delete_seonix_ids are planned FIRST so a "move" (delete uuid-A that
	 *     held /x, upsert uuid-B at /x) inside one payload does not
	 *     false-positive as a conflict. Service deletions are hard deletes —
	 *     the service initiated them, so no tombstone is needed.
	 *   - upsert matches by seonix_id: update when the row exists (clearing
	 *     deleted_at resurrects a tombstoned row), insert otherwise.
	 *   - a from_path colliding (on match key) with a DIFFERENT active row —
	 *     pre-existing or introduced earlier in this batch — skips the item
	 *     with an errors[] entry, code "from_path_conflict".
	 *   - invalid items (bad from_path/to_url/status_code, missing or
	 *     duplicated seonix_id) skip with errors[] code "invalid".
	 *
	 * @param array<int,array<string,mixed>> $rows              Current table snapshot
	 *        (id, seonix_id, from_path, deleted_at at minimum).
	 * @param array<int,array<string,mixed>> $upsert            Items to upsert.
	 * @param array<int,string>              $delete_seonix_ids Service-side deletions.
	 * @return array{ops:array<int,array<string,mixed>>, errors:array<int,array{seonix_id:string,code:string,message:string}>, applied:int, deleted:int}
	 */
	public static function plan_sync( array $rows, array $upsert, array $delete_seonix_ids ): array {
		$ops     = array();
		$errors  = array();
		$applied = 0;
		$deleted = 0;

		// Index the snapshot.
		$by_seonix_id = array(); // seonix_id => row
		$active_keys  = array(); // match_key => row id (deleted_at IS NULL rows only)
		$key_of_row   = array(); // row id => match_key (active rows only)
		foreach ( $rows as $row ) {
			$row_id = (int) ( $row['id'] ?? 0 );
			if ( ! empty( $row['seonix_id'] ) ) {
				$by_seonix_id[ (string) $row['seonix_id'] ] = $row;
			}
			if ( empty( $row['deleted_at'] ) ) {
				$key                   = self::match_key( (string) ( $row['from_path'] ?? '' ) );
				$active_keys[ $key ]   = $row_id;
				$key_of_row[ $row_id ] = $key;
			}
		}

		// 1) Deletions first (frees from_path claims for the upserts below).
		foreach ( $delete_seonix_ids as $seonix_id ) {
			$seonix_id = (string) $seonix_id;
			if ( '' === $seonix_id || ! isset( $by_seonix_id[ $seonix_id ] ) ) {
				continue; // Unknown id — nothing to delete; not an error.
			}
			$row_id = (int) $by_seonix_id[ $seonix_id ]['id'];
			$ops[]  = array(
				'op' => 'delete',
				'id' => $row_id,
			);
			$deleted++;
			if ( isset( $key_of_row[ $row_id ] ) ) {
				unset( $active_keys[ $key_of_row[ $row_id ] ], $key_of_row[ $row_id ] );
			}
			unset( $by_seonix_id[ $seonix_id ] );
		}

		// 2) Upserts.
		$seen_in_batch = array();
		$next_pseudo   = -1; // Pseudo row ids for rows inserted within this batch.
		foreach ( $upsert as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$seonix_id = isset( $item['seonix_id'] ) ? (string) $item['seonix_id'] : '';
			if ( '' === $seonix_id ) {
				$errors[] = array(
					'seonix_id' => '',
					'code'      => 'invalid',
					'message'   => 'seonix_id is required for sync-managed redirects.',
				);
				continue;
			}
			if ( isset( $seen_in_batch[ $seonix_id ] ) ) {
				$errors[] = array(
					'seonix_id' => $seonix_id,
					'code'      => 'invalid',
					'message'   => 'Duplicate seonix_id in one sync payload.',
				);
				continue;
			}
			$seen_in_batch[ $seonix_id ] = true;

			$check = self::validate_rule(
				$item['from_path'] ?? '',
				$item['to_url'] ?? '',
				$item['status_code'] ?? 301
			);
			if ( ! $check['ok'] ) {
				$errors[] = array(
					'seonix_id' => $seonix_id,
					'code'      => 'invalid',
					'message'   => $check['error'],
				);
				continue;
			}

			$key      = self::match_key( $check['from_path'] );
			$existing = $by_seonix_id[ $seonix_id ] ?? null;
			$row_id   = $existing ? (int) $existing['id'] : 0;

			// Conflict: the match key is claimed by a different active row.
			if ( isset( $active_keys[ $key ] ) && $active_keys[ $key ] !== $row_id ) {
				$errors[] = array(
					'seonix_id' => $seonix_id,
					'code'      => 'from_path_conflict',
					'message'   => sprintf( 'from_path %s is already used by another redirect on this site.', $check['from_path'] ),
				);
				continue;
			}

			$data = array(
				'from_path'   => $check['from_path'],
				'to_url'      => $check['to_url'],
				'status_code' => $check['status_code'],
				'enabled'     => ( ! isset( $item['enabled'] ) || $item['enabled'] ) ? 1 : 0,
			);

			if ( $existing ) {
				// Update — clearing deleted_at resurrects a tombstoned row.
				$data['deleted_at'] = null;
				$ops[]              = array(
					'op'   => 'update',
					'id'   => $row_id,
					'data' => $data,
				);
				// The row may have been tombstoned (not in active sets) or may
				// move to a new key — refresh both indexes.
				if ( isset( $key_of_row[ $row_id ] ) ) {
					unset( $active_keys[ $key_of_row[ $row_id ] ] );
				}
				$active_keys[ $key ]   = $row_id;
				$key_of_row[ $row_id ] = $key;
			} else {
				$data['seonix_id'] = $seonix_id;
				$ops[]             = array(
					'op'   => 'insert',
					'data' => $data,
				);
				$pseudo                = $next_pseudo--;
				$active_keys[ $key ]   = $pseudo;
				$key_of_row[ $pseudo ] = $key;
			}
			$applied++;
		}

		return array(
			'ops'     => $ops,
			'errors'  => $errors,
			'applied' => $applied,
			'deleted' => $deleted,
		);
	}

	/**
	 * Execute a sync payload: plan against the current table, apply the
	 * planned operations, invalidate the map once.
	 *
	 * @param array<int,array<string,mixed>> $upsert
	 * @param array<int,string>              $delete_seonix_ids
	 * @return array{applied:int, deleted:int, errors:array<int,array{seonix_id:string,code:string,message:string}>}
	 */
	public function apply_sync( array $upsert, array $delete_seonix_ids ): array {
		$wpdb = $this->db();
		// Snapshot includes tombstoned rows — plan_sync needs them for
		// resurrection and seonix_id matching.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, seonix_id, from_path, deleted_at FROM {$this->table_name()} ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = is_array( $rows ) ? $rows : array();

		$plan = self::plan_sync( $rows, $upsert, $delete_seonix_ids );

		foreach ( $plan['ops'] as $op ) {
			switch ( $op['op'] ) {
				case 'insert':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
					$wpdb->insert( $this->table_name(), $op['data'] );
					break;
				case 'update':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
					$wpdb->update( $this->table_name(), $op['data'], array( 'id' => $op['id'] ) );
					break;
				case 'delete':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
					$wpdb->delete( $this->table_name(), array( 'id' => $op['id'] ) );
					break;
			}
		}

		$this->invalidate_cache();

		return array(
			'applied' => $plan['applied'],
			'deleted' => $plan['deleted'],
			'errors'  => $plan['errors'],
		);
	}

	/**
	 * Tombstone hygiene, run during sync: drop tombstones older than 90 days,
	 * then cap the remainder at the newest 200.
	 */
	public function prune_tombstones(): void {
		$wpdb   = $this->db();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TOMBSTONE_TTL_DAYS * DAY_IN_SECONDS );

		// Internal identifier interpolation — see get_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table_name()} WHERE deleted_at IS NOT NULL AND deleted_at < %s", $cutoff )
		);

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name()} WHERE deleted_at IS NOT NULL"
		);
		if ( $count > self::TOMBSTONE_MAX ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table_name()} WHERE deleted_at IS NOT NULL ORDER BY deleted_at ASC, id ASC LIMIT %d",
					$count - self::TOMBSTONE_MAX
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ─── Cache ────────────────────────────────────────────────────────────

	/**
	 * Drop both cache layers of the runtime redirect map. Called by every
	 * write path (inserts, updates, deletes, sync) — the runner rebuilds the
	 * map lazily on the next front-end request.
	 */
	public function invalidate_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		}
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
