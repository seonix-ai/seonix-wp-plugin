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
		if ( empty( $map ) ) {
			return;
		}

		$hit = self::resolve( $map, $path );
		if ( null === $hit ) {
			return;
		}

		// resolve() catches self-loops for relative targets; absolute targets
		// pointing back at this very path on THIS host need the site host to
		// detect, which the pure matcher deliberately doesn't know about.
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( self::is_same_host_self_target( $hit['target'], $path, $home_host ) ) {
			return;
		}

		$this->store->increment_hits( $hit['id'] );

		$target = self::append_query( $hit['target'], $query );

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
			$from = isset( $row['from_path'] ) ? (string) $row['from_path'] : '';
			$to   = isset( $row['to_url'] ) ? (string) $row['to_url'] : '';
			if ( '' === $from || '' === $to ) {
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
			$target = $final['target'];
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
