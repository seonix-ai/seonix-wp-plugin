<?php
/**
 * Cache purger that targets whatever cache plugin the site is running.
 *
 * After applying an SEO fix (broken_link rewrite, SEO meta change, alt
 * text update) the user expects the change to be visible on the live page
 * immediately. Most production WP sites sit behind one or more cache layers
 * (LiteSpeed, Cloudflare, WP Rocket, W3TC, page cache from the host) which
 * keep serving the stale rendering. Without a purge, "Apply" looks like it
 * silently failed even when the DB write succeeded.
 *
 * Detection is deliberately conservative — we only call into a vendor cache
 * plugin via its supported public API (do_action hook, public function,
 * documented WP-CLI command). No filesystem-poking or option-stomping.
 *
 * Engines covered:
 *   - litespeed   LiteSpeed Cache (do_action 'litespeed_purge_all', 'litespeed_purge_post')
 *   - cloudflare  Official Cloudflare plugin (CF\Hooks::purgeCacheEverything)
 *   - wp_rocket   WP Rocket (rocket_clean_post / rocket_clean_domain)
 *   - w3tc        W3 Total Cache (w3tc_flush_post / w3tc_flush_all)
 *   - super_cache WP Super Cache (wp_cache_post_change / wp_cache_clear_cache)
 *   - object_cache The WP object cache itself (wp_cache_flush) — always available
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Cache_Purger {

	/**
	 * Discover which cache engines this site has active. Frontend uses this
	 * to render "After applying, we'll purge: LiteSpeed, Cloudflare" hints
	 * and to grey out the toggle when nothing is installed.
	 *
	 * @return array<string,array{available:bool,name:string,version?:string}>
	 */
	public static function detect(): array {
		$engines = array();

		// LiteSpeed: ships an autoloaded LiteSpeed\Core class.
		$engines['litespeed'] = array(
			'available' => class_exists( 'LiteSpeed\Core' ) || function_exists( 'litespeed_purge_all' ),
			'name'      => 'LiteSpeed Cache',
		);

		// Cloudflare: the official plugin defines a CF namespace via Composer.
		$engines['cloudflare'] = array(
			'available' => class_exists( 'CF\WordPress\Hooks' ) || class_exists( 'CF\WordPress\Plugin' ),
			'name'      => 'Cloudflare',
		);

		// WP Rocket: rocket_clean_domain() is the public purge entry point.
		$engines['wp_rocket'] = array(
			'available' => function_exists( 'rocket_clean_domain' ),
			'name'      => 'WP Rocket',
		);

		// W3 Total Cache: exposes w3tc_flush_post / w3tc_flush_all helpers.
		$engines['w3tc'] = array(
			'available' => function_exists( 'w3tc_flush_post' ) || function_exists( 'w3tc_pgcache_flush' ),
			'name'      => 'W3 Total Cache',
		);

		// WP Super Cache: wp_cache_clear_cache for full, wp_cache_post_change per-post.
		$engines['super_cache'] = array(
			'available' => function_exists( 'wp_cache_clear_cache' ),
			'name'      => 'WP Super Cache',
		);

		// WP object cache is always present (in-process or external like Redis).
		$engines['object_cache'] = array(
			'available' => function_exists( 'wp_cache_flush' ),
			'name'      => 'WP object cache',
		);

		return $engines;
	}

	/**
	 * Returns the names of every active cache engine on this site. Cheap call
	 * used by the SEO-fix controller's /capabilities endpoint.
	 *
	 * @return string[]
	 */
	public static function active_engines(): array {
		$out = array();
		foreach ( self::detect() as $key => $info ) {
			if ( $info['available'] ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Purge everything every detected engine knows about. Used as a fallback
	 * when we don't have a precise post id list.
	 *
	 * @return array<string,bool> map of engine → success
	 */
	public static function purge_all(): array {
		$results = array();
		foreach ( self::detect() as $key => $info ) {
			if ( ! $info['available'] ) {
				continue;
			}
			$results[ $key ] = self::purge_all_for_engine( $key );
		}
		return $results;
	}

	/**
	 * Purge a list of specific post IDs from every detected per-post-aware
	 * engine. Engines that only support full-flush (Cloudflare, generally)
	 * fall through to a domain-wide purge.
	 *
	 * @param int[] $post_ids
	 * @return array<string,bool>
	 */
	public static function purge_posts( array $post_ids ): array {
		$post_ids = array_map( 'intval', $post_ids );
		$post_ids = array_values( array_unique( array_filter( $post_ids, fn ( $i ) => $i > 0 ) ) );
		$results  = array();

		foreach ( self::detect() as $key => $info ) {
			if ( ! $info['available'] ) {
				continue;
			}
			$results[ $key ] = self::purge_posts_for_engine( $key, $post_ids );
		}
		return $results;
	}

	// ─── Per-engine implementations ─────────────────────────────────────

	private static function purge_all_for_engine( string $engine ): bool {
		try {
			switch ( $engine ) {
				case 'litespeed':
					// 'litespeed_purge_all' is LiteSpeed Cache's own action hook,
					// not one we define. We are invoking their public API.
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					do_action( 'litespeed_purge_all' );
					return true;

				case 'cloudflare':
					if ( class_exists( 'CF\WordPress\Hooks' ) ) {
						$hooks = new \CF\WordPress\Hooks();
						if ( method_exists( $hooks, 'purgeCacheEverything' ) ) {
							$hooks->purgeCacheEverything();
							return true;
						}
					}
					return false;

				case 'wp_rocket':
					if ( function_exists( 'rocket_clean_domain' ) ) {
						rocket_clean_domain();
						return true;
					}
					return false;

				case 'w3tc':
					if ( function_exists( 'w3tc_pgcache_flush' ) ) {
						w3tc_pgcache_flush();
						return true;
					}
					if ( function_exists( 'w3tc_flush_all' ) ) {
						w3tc_flush_all();
						return true;
					}
					return false;

				case 'super_cache':
					if ( function_exists( 'wp_cache_clear_cache' ) ) {
						wp_cache_clear_cache();
						return true;
					}
					return false;

				case 'object_cache':
					if ( function_exists( 'wp_cache_flush' ) ) {
						return (bool) wp_cache_flush();
					}
					return false;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for cache-engine integration failures.
			error_log( '[Seonix] cache purge_all failed for ' . $engine . ': ' . $e->getMessage() );
			return false;
		}
		return false;
	}

	private static function purge_posts_for_engine( string $engine, array $post_ids ): bool {
		try {
			switch ( $engine ) {
				case 'litespeed':
					foreach ( $post_ids as $id ) {
						// 'litespeed_purge_post' is LiteSpeed Cache's own action
						// hook; we are invoking their public API.
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						do_action( 'litespeed_purge_post', $id );
					}
					return true;

				case 'wp_rocket':
					if ( function_exists( 'rocket_clean_post' ) ) {
						foreach ( $post_ids as $id ) {
							rocket_clean_post( $id );
						}
						return true;
					}
					// Fall back to domain-wide.
					return self::purge_all_for_engine( 'wp_rocket' );

				case 'w3tc':
					if ( function_exists( 'w3tc_flush_post' ) ) {
						foreach ( $post_ids as $id ) {
							w3tc_flush_post( $id );
						}
						return true;
					}
					return self::purge_all_for_engine( 'w3tc' );

				case 'super_cache':
					if ( function_exists( 'wp_cache_post_change' ) ) {
						foreach ( $post_ids as $id ) {
							wp_cache_post_change( $id );
						}
						return true;
					}
					return self::purge_all_for_engine( 'super_cache' );

				case 'cloudflare':
				case 'object_cache':
					// Cloudflare plugin doesn't expose per-URL purge through its
					// PHP API (only via the dashboard / API tokens). Object cache
					// is process-local; flushing one key vs all is the same cost.
					return self::purge_all_for_engine( $engine );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for cache-engine integration failures.
			error_log( '[Seonix] cache purge_posts failed for ' . $engine . ': ' . $e->getMessage() );
			return false;
		}
		return false;
	}
}
