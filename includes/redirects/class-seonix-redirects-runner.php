<?php
/**
 * Front-end executor for the native Seonix redirect manager.
 *
 * Hooks `template_redirect` at priority 1 — late enough that WordPress has
 * fully resolved rewrites and pretty-permalink REST/llms.txt handlers have had
 * their chance, early enough to beat the template loader and canonical
 * redirect churn for a URL that is about to be redirected anyway.
 *
 * Matching model (all pure static functions, unit-tested directly):
 *   - the request path is url-decoded, lower-cased, and compared trailing-
 *     slash-insensitively (map keyed by rtrim(path, '/'), site root stays '/');
 *   - the query string is never part of matching and is re-appended to the
 *     target verbatim on redirect;
 *   - if the target is itself a from_path in the map, the redirect flattens
 *     ONE hop to the final target (cycle-detected: an A→B→A chain aborts the
 *     redirect entirely rather than bouncing the browser);
 *   - a target that resolves back to the current path never redirects.
 *
 * The enabled-rules map is cached in wp_cache + a transient
 * (`seonix_redirects_map`); every store write invalidates both layers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Runner {

	/** Transient / wp_cache TTL for the compiled map (rebuilt on invalidation). */
	const CACHE_TTL = 12 * 3600;

	/** @var Seonix_Redirects_Store */
	private $store;

	public function __construct( Seonix_Redirects_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Attach the front-end hook.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Match the current request against the redirect map and 301/302 away on a
	 * hit. Runs on every front-end request, so the fast path (no match) is one
	 * cached-array lookup.
	 */
	public function maybe_redirect(): void {
		if ( $this->is_excluded_request() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw path is required for matching; used only for comparison and re-emitted via wp_safe_redirect.
		if ( '' === $request_uri ) {
			return;
		}

		$parts = wp_parse_url( $request_uri );
		$path  = is_array( $parts ) && ! empty( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = is_array( $parts ) && ! empty( $parts['query'] ) ? (string) $parts['query'] : '';
		if ( '' === $path ) {
			return;
		}

		$map = $this->get_map();
		$hit = ! empty( $map ) ? self::resolve( $map, $path ) : null;

		// Literal rules win. Only when none matched do we pay for the regex pass,
		// so the common case stays a single hash lookup no matter how many
		// patterns the site accumulates.
		if ( null === $hit ) {
			$hit = self::resolve_regex( $this->get_regex_rules(), $path );
		}
		if ( null === $hit ) {
			return;
		}

		$this->store->increment_hits( $hit['id'] );

		// 410 Gone is not a redirect: there is no target and nowhere to send the
		// visitor. Saying "gone" instead of "not found" is what makes crawlers
		// drop the URL quickly rather than retrying it for months.
		if ( 410 === (int) $hit['status'] ) {
			$this->serve_gone();
			return;
		}

		// resolve() catches self-loops for relative targets; absolute targets
		// pointing back at this very path on THIS host need the site host to
		// detect, which the pure matcher deliberately doesn't know about.
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( self::is_same_host_self_target( $hit['target'], $path, $home_host ) ) {
			return;
		}

		// One request, ONE redirect: follow our own rule chain to its final
		// local destination (absolute same-host targets — what the Seonix fix
		// applier writes — are folded into local paths first), then land on the
		// site's canonical slash form. Without this a fix chained through
		// rules and the theme's canonical redirect: /old → /mid → /new →
		// /new/ cost three 301s where one suffices. A null means our rules
		// form a cycle — serve the page rather than bounce the browser.
		$target = self::flatten_chain( $hit['target'], $map, Seonix_Redirects_Store::match_key( $path ), $home_host );
		if ( null === $target ) {
			return;
		}
		$target = Seonix_Redirects_Store::canonicalize_target( $target );

		$target = self::append_query( $target, $query );

		// wp_safe_redirect() only allows hosts returned by the
		// allowed_redirect_hosts filter and silently rewrites anything else to
		// the admin fallback URL. Redirect targets in our table are written
		// exclusively through authenticated surfaces (the Seonix service over
		// Bearer-authed REST, or a manage_options admin), so honouring the
		// stored external host is intentional — allow exactly that one host
		// for exactly this redirect.
		$external_host = self::external_host( $target );
		if ( null !== $external_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $external_host ) {
					$hosts   = is_array( $hosts ) ? $hosts : array();
					$hosts[] = $external_host;
					return $hosts;
				}
			);
		}

		wp_safe_redirect( $target, $hit['status'], 'Seonix' );
		exit;
	}

	/**
	 * Requests the redirect engine must never touch: admin screens, login,
	 * REST, XML-RPC, favicon/robots. template_redirect does not fire for most
	 * of these, but the guards are cheap and make the contract explicit.
	 */
	private function is_excluded_request(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against fixed literals only.
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return false;
		}
		$basename = strtolower( basename( $path ) );
		if ( in_array( $basename, array( 'wp-login.php', 'xmlrpc.php', 'favicon.ico', 'robots.txt' ), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Send 410 Gone and stop.
	 *
	 * Deliberately not wp_die(): that renders a styled error page and reads as a
	 * site fault. A bare 410 with a one-line body is what a crawler wants, and a
	 * human following a dead link gets the theme's own 404 handling on the next
	 * navigation rather than a WordPress error screen.
	 */
	private function serve_gone(): void {
		if ( ! headers_sent() ) {
			status_header( 410 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
		echo esc_html__( 'This page has been permanently removed.', 'seonix' );
		exit;
	}

	// ─── Map access ───────────────────────────────────────────────────────

	/**
	 * Fetch the compiled redirect map: wp_cache → transient → rebuild from the
	 * store. Both cache layers are dropped by Seonix_Redirects_Store on every
	 * write.
	 *
	 * @return array<string,array{id:int,target:string,status:int}>
	 */
	private function get_map(): array {
		if ( function_exists( 'wp_cache_get' ) ) {
			$cached = wp_cache_get( Seonix_Redirects_Store::CACHE_KEY, Seonix_Redirects_Store::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$map = get_transient( Seonix_Redirects_Store::CACHE_KEY );
		if ( ! is_array( $map ) ) {
			$map = self::build_map( $this->store->get_active_rows() );
			set_transient( Seonix_Redirects_Store::CACHE_KEY, $map, self::CACHE_TTL );
		}

		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( Seonix_Redirects_Store::CACHE_KEY, $map, Seonix_Redirects_Store::CACHE_GROUP, self::CACHE_TTL );
		}

		return $map;
	}

	// ─── Pure matching logic (unit-tested directly) ───────────────────────

	/**
	 * Compile enabled rows into the lookup map keyed by match key. When two
	 * rows collide on a key (possible for pre-normalization legacy data), the
	 * OLDEST row wins — rows arrive ordered by id ASC and the first claim on a
	 * key is kept, so behaviour is deterministic across rebuilds.
	 *
	 * @param array<int,array<string,mixed>> $rows Active rows (id, from_path, to_url, status_code).
	 * @return array<string,array{id:int,target:string,status:int}>
	 */
	public static function build_map( array $rows ): array {
		$map = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_regex'] ) ) {
				continue; // regex rules live in their own ordered pass
			}
			$from   = isset( $row['from_path'] ) ? (string) $row['from_path'] : '';
			$to     = isset( $row['to_url'] ) ? (string) $row['to_url'] : '';
			$status = (int) ( $row['status_code'] ?? 301 );
			if ( '' === $from ) {
				continue;
			}
			// A 410 legitimately has no target; every other code needs one.
			if ( '' === $to && ! in_array( $status, Seonix_Redirects_Store::TARGETLESS_STATUS_CODES, true ) ) {
				continue;
			}
			$key = Seonix_Redirects_Store::match_key( $from );
			if ( isset( $map[ $key ] ) ) {
				continue;
			}
			$status = (int) ( $row['status_code'] ?? 301 );
			if ( ! in_array( $status, Seonix_Redirects_Store::ALLOWED_STATUS_CODES, true ) ) {
				$status = 301;
			}
			$map[ $key ] = array(
				'id'     => (int) ( $row['id'] ?? 0 ),
				'target' => $to,
				'status' => $status,
			);
		}
		return $map;
	}

	/**
	 * Resolve a request path against the map.
	 *
	 * Returns the matched rule (with the target flattened one hop when the
	 * target is itself a from_path) or null when the request must pass
	 * through untouched: no match, a self-loop, or a detected cycle. The
	 * status code and hit attribution always belong to the rule that matched
	 * the request, not the hop target.
	 *
	 * @param array<string,array{id:int,target:string,status:int}> $map
	 * @param string                                               $request_path Raw request path (no query).
	 * @return array{id:int,target:string,status:int}|null
	 */
	/**
	 * Regex rules for the second pass, oldest first (creation order is the
	 * operator's priority — first match wins, like every other redirect plugin).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_regex_rules(): array {
		$rules = array();
		foreach ( $this->store->get_active_rows() as $row ) {
			if ( ! empty( $row['is_regex'] ) ) {
				$rules[] = $row;
			}
		}
		return $rules;
	}

	/**
	 * First matching regex rule, with $1…$9 expanded into the target.
	 *
	 * Matched against the raw path (not the lower-cased match key): a pattern's
	 * own captures must return the path as written, and the `i` flag added by
	 * compile_regex() already makes matching case-insensitive.
	 *
	 * @param array<int,array<string,mixed>> $rules
	 * @param string                         $request_path
	 * @return array{id:int,target:string,status:int}|null
	 */
	public static function resolve_regex( array $rules, string $request_path ): ?array {
		foreach ( $rules as $row ) {
			$pattern = Seonix_Redirects_Store::compile_regex( (string) ( $row['from_path'] ?? '' ) );
			if ( null === $pattern ) {
				continue; // stored pattern went bad — skip, never fatal on a page view
			}

			$matches = array();
			// A pattern that backtracks catastrophically returns false here rather
			// than hanging the request: PHP gives up at pcre.backtrack_limit. Skip
			// it like any other non-match.
			$ok = @preg_match( $pattern, $request_path, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad stored pattern must not warn on every page view.
			if ( 1 !== $ok ) {
				continue;
			}

			$status = (int) ( $row['status_code'] ?? 301 );
			$target = (string) ( $row['to_url'] ?? '' );
			if ( '' === $target && ! in_array( $status, Seonix_Redirects_Store::TARGETLESS_STATUS_CODES, true ) ) {
				continue;
			}
			$target = self::expand_captures( $target, $matches );

			// An expanded target that lands back on the requested path would loop.
			if ( '' !== $target && Seonix_Redirects_Store::match_key( $target ) === Seonix_Redirects_Store::match_key( $request_path ) ) {
				continue;
			}

			return array(
				'id'     => (int) ( $row['id'] ?? 0 ),
				'target' => $target,
				'status' => $status,
			);
		}
		return null;
	}

	/**
	 * Substitute $1..$9 in a regex target with the captured groups.
	 *
	 * Capture groups come from the visitor's own URL, so they are URL-encoded on
	 * the way in to keep a crafted path from injecting anything into the Location
	 * header. A reference with no matching group collapses to an empty string —
	 * the same thing Apache's mod_rewrite does.
	 *
	 * @param string             $target
	 * @param array<int,string>  $matches
	 */
	public static function expand_captures( string $target, array $matches ): string {
		if ( false === strpos( $target, '$' ) ) {
			return $target;
		}
		return (string) preg_replace_callback(
			'/\$([1-9])/',
			static function ( $m ) use ( $matches ) {
				$i = (int) $m[1];
				if ( ! isset( $matches[ $i ] ) ) {
					return '';
				}
				// rawurlencode would escape the slashes a capture legitimately
				// carries; encode only what can break out of a URL path.
				//
				// '%' MUST be first: str_replace applies pairs in order, so
				// encoding it last would find the '%' in the '%20' this very call
				// just produced and double-encode it into '%2520'.
				return str_replace(
					array( '%', '"', '<', '>', ' ', '#', '?' ),
					array( '%25', '%22', '%3C', '%3E', '%20', '%23', '%3F' ),
					(string) $matches[ $i ]
				);
			},
			$target
		);
	}

	public static function resolve( array $map, string $request_path ) {
		$key = Seonix_Redirects_Store::match_key( $request_path );
		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		$entry  = $map[ $key ];
		$target = $entry['target'];

		// Self-loop guard: the target resolves to the page being requested.
		$target_key = self::local_target_key( $target );
		if ( $target_key === $key ) {
			return null;
		}

		// One-hop flattening: the target is itself redirected by our table.
		if ( null !== $target_key && isset( $map[ $target_key ] ) ) {
			$final           = $map[ $target_key ];
			$final_local_key = self::local_target_key( $final['target'] );
			if ( $final_local_key === $key ) {
				// Cycle (A→B, B→A): redirecting either way would bounce the
				// browser forever — serve the page instead.
				return null;
			}
			// Don't flatten onto a rule that has no target of its own (410).
			// Collapsing A→B into A→"" sends the visitor nowhere — it 404s. The
			// honest chain is A→B (301), and B answers 410 on arrival. This is
			// reachable in one click: rename a post, then trash it.
			if ( '' !== (string) $final['target'] ) {
				$target = $final['target'];
			}
		}

		return array(
			'id'     => $entry['id'],
			'target' => $target,
			'status' => $entry['status'],
		);
	}

	/**
	 * Match key of a target when it is site-local (relative path), or null for
	 * absolute URLs — flattening and loop detection only reason about our own
	 * path space; absolute targets leave the site and are taken at face value.
	 *
	 * @return string|null
	 */
	public static function local_target_key( string $target ) {
		if ( '' === $target || '/' !== $target[0] || 0 === strpos( $target, '//' ) ) {
			return null;
		}
		$path = $target;
		$cut  = strcspn( $path, '?#' );
		if ( $cut < strlen( $path ) ) {
			$path = substr( $path, 0, $cut );
		}
		if ( '' === $path ) {
			return null;
		}
		return Seonix_Redirects_Store::match_key( $path );
	}

	/**
	 * Fold an absolute same-host target into its site-relative form (path +
	 * query + fragment) so chain flattening and slash canonicalization reason
	 * about it like any local path. External hosts and already-relative
	 * targets come back unchanged. The Seonix fix applier writes absolute
	 * same-host targets, which the local-path logic used to treat as opaque —
	 * that is how the wohnart rule dodged both flattening and loop detection.
	 */
	public static function relativize_same_host( string $target, string $home_host ): string {
		if ( '' === $home_host || ! preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		$host = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $host ) || 0 !== strcasecmp( $host, $home_host ) ) {
			return $target;
		}
		$parts = wp_parse_url( $target );
		if ( ! is_array( $parts ) ) {
			return $target;
		}
		$rel  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		$rel .= isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		$rel .= isset( $parts['fragment'] ) && '' !== $parts['fragment'] ? '#' . $parts['fragment'] : '';
		return $rel;
	}

	/**
	 * Follow our own rule chain from an already-resolved target to its final
	 * local destination — at most 3 further hops, each one relativized first
	 * so absolute same-host links keep the chain walkable. Extends resolve()'s
	 * one-hop flattening to the shapes it could not see (absolute same-host
	 * targets, longer chains).
	 *
	 * Stops early, returning the CURRENT target, when the next rule is a
	 * targetless 410 (the honest chain lets the 410 answer itself — see
	 * resolve()) or when the target leaves our path space. Returns null when
	 * the chain cycles back to the requested page: redirecting would bounce
	 * the browser forever, so the caller serves the page instead, matching
	 * resolve()'s two-rule-cycle behaviour.
	 *
	 * @param string $target      Target of the matched rule.
	 * @param array  $map         Compiled literal-rule map (match key → entry).
	 * @param string $request_key Match key of the page being requested.
	 * @param string $home_host   This site's host, for relativizing.
	 * @return string|null Final target, or null on a cycle.
	 */
	public static function flatten_chain( string $target, array $map, string $request_key, string $home_host ) {
		$visited = array( $request_key => true );
		for ( $hops = 0; $hops < 3; $hops++ ) {
			$target = self::relativize_same_host( $target, $home_host );
			$key    = self::local_target_key( $target );
			if ( null === $key || ! isset( $map[ $key ] ) ) {
				return $target; // left our rule space — this is the final stop
			}
			if ( isset( $visited[ $key ] ) ) {
				return null; // cycle — redirecting would loop the browser
			}
			$next = (string) $map[ $key ]['target'];
			if ( '' === $next ) {
				return $target; // next rule is a 410 — stop before it
			}
			$visited[ $key ] = true;
			$target          = $next;
		}
		return self::relativize_same_host( $target, $home_host );
	}

	/**
	 * Re-attach the original request's query string to the redirect target.
	 * The query never participates in matching, but dropping it would break
	 * campaign parameters and paginated links.
	 */
	public static function append_query( string $target, string $query ): string {
		if ( '' === $query ) {
			return $target;
		}
		$separator = false !== strpos( $target, '?' ) ? '&' : '?';
		return $target . $separator . $query;
	}

	/**
	 * True when an ABSOLUTE target points at this very path on this very host
	 * — the one self-loop shape the pure resolve() cannot see because it never
	 * knows the site host. Relative self-loops are already filtered there.
	 */
	public static function is_same_host_self_target( string $target, string $request_path, string $home_host ): bool {
		if ( '' === $home_host || ! preg_match( '#^https?://#i', $target ) ) {
			return false;
		}
		$target_host = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $target_host ) || 0 !== strcasecmp( $target_host, $home_host ) ) {
			return false;
		}
		$target_path = wp_parse_url( $target, PHP_URL_PATH );
		$target_path = is_string( $target_path ) && '' !== $target_path ? $target_path : '/';
		return Seonix_Redirects_Store::match_key( $target_path ) === Seonix_Redirects_Store::match_key( $request_path );
	}

	/**
	 * Host of an absolute http(s) target when it differs from this site's
	 * host, or null for relative / same-host targets.
	 *
	 * @return string|null
	 */
	private static function external_host( string $target ) {
		if ( ! preg_match( '#^https?://#i', $target ) ) {
			return null;
		}
		$host = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $home_host ) && 0 === strcasecmp( $host, $home_host ) ) {
			return null;
		}
		return $host;
	}
}
