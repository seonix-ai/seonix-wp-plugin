<?php
/**
 * Active SEO-plugin detection + storage mapping.
 *
 * Seonix never owns the SEO title/description — it writes them where the site's
 * active SEO plugin reads them. Yoast and Rank Math both keep the per-post SEO
 * title/description in standard postmeta, so Seonix writes them with
 * update_post_meta() under the right key. Writing postmeta another plugin reads
 * is the documented way to set these (Yoast's REST API is read-only and exposes
 * no PHP setter for post meta), and is what the dashboard's "Fix with AI" relies
 * on.
 *
 * All in One SEO stores per-post data in its own `wp_aioseo_posts` table (JSON),
 * NOT postmeta, so post title/description aren't writable through this path yet:
 * detect() still reports 'aioseo' (term descriptions are handled elsewhere via
 * term meta), but post_title_key()/post_desc_key() return null so the fix is
 * gated off instead of writing meta no plugin will read.
 *
 * This is the single source of truth for "which SEO plugin is active" — the
 * term-meta fix delegates here too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_SEO_Engine {

	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';
	const AIOSEO   = 'aioseo';

	/**
	 * Detect the active SEO plugin in priority order.
	 *
	 * @return string|null One of self::YOAST|RANKMATH|AIOSEO, or null when none.
	 */
	public static function detect(): ?string {
		$is_active = static function ( string $plugin ): bool {
			if ( function_exists( 'is_plugin_active' ) ) {
				return (bool) call_user_func( 'is_plugin_active', $plugin );
			}
			$active = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
			return in_array( $plugin, $active, true );
		};

		// Prefer the cheap class/constant checks first: they need no WP function
		// (is_plugin_active lives in wp-admin and isn't loaded on the front end),
		// short-circuit before is_plugin_active(), and the class/constant being
		// defined is a stronger "this plugin is really running" signal than the
		// active-plugins option. The is_active() file-path check is the fallback.
		if ( class_exists( 'WPSEO_Options' )
			|| class_exists( 'WPSEO_Taxonomy_Meta' )
			|| $is_active( 'wordpress-seo/wp-seo.php' )
			|| $is_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
			return self::YOAST;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || $is_active( 'seo-by-rank-math/rank-math.php' ) ) {
			return self::RANKMATH;
		}
		if ( defined( 'AIOSEO_VERSION' )
			|| $is_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' )
			|| $is_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ) ) {
			return self::AIOSEO;
		}
		return null;
	}

	/**
	 * Postmeta key that stores the SEO title for the given (or active) engine,
	 * or null when the engine has no postmeta-based title we can write
	 * (AIOSEO — custom table — or no SEO plugin at all).
	 *
	 * @param string|null $engine Engine slug, or null to detect the active one.
	 * @return string|null
	 */
	public static function post_title_key( ?string $engine = null ): ?string {
		$engine = null !== $engine ? $engine : self::detect();
		switch ( $engine ) {
			case self::YOAST:
				return '_yoast_wpseo_title';
			case self::RANKMATH:
				return 'rank_math_title';
			default:
				return null;
		}
	}

	/**
	 * Postmeta key that stores the SEO meta description for the given (or active)
	 * engine, or null when unavailable (AIOSEO — custom table — or none).
	 *
	 * @param string|null $engine Engine slug, or null to detect the active one.
	 * @return string|null
	 */
	public static function post_desc_key( ?string $engine = null ): ?string {
		$engine = null !== $engine ? $engine : self::detect();
		switch ( $engine ) {
			case self::YOAST:
				return '_yoast_wpseo_metadesc';
			case self::RANKMATH:
				return 'rank_math_description';
			default:
				return null;
		}
	}
}
