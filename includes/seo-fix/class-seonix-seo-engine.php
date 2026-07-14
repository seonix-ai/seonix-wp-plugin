<?php
/**
 * Active SEO-plugin detection + storage mapping.
 *
 * Seonix writes SEO titles/descriptions where the site's active SEO plugin
 * reads them. Yoast, Rank Math, SEOPress and The SEO Framework all keep the
 * per-post SEO title/description in standard postmeta, so Seonix writes them
 * with update_post_meta() under the right key. Writing postmeta another plugin
 * reads is the documented way to set these (Yoast's REST API is read-only and
 * exposes no PHP setter for post meta), and is what the dashboard's "Fix with
 * AI" relies on.
 *
 * All in One SEO stores per-post data in its own `wp_aioseo_posts` table (JSON),
 * NOT postmeta — the `_aioseo_*` postmeta rows are a one-way mirror AIOSEO
 * writes for WPML compatibility and never reads back. post_title_key()/
 * post_desc_key() therefore return null for AIOSEO; writes go through its Post
 * model instead (see Seonix_Meta_Bridge::write_aioseo()).
 *
 * Squirrly is detected (so the standalone meta renderer knows another plugin
 * owns the head) but not written to — it stores snippets in its own wp_qss
 * table and holds <1% share.
 *
 * This is the single source of truth for "which SEO plugin is active" — the
 * meta bridge, the standalone renderer, the schema emitter and the SEO-Fix
 * methods all delegate here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_SEO_Engine {

	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';
	const AIOSEO   = 'aioseo';
	const SEOPRESS = 'seopress';
	const TSF      = 'tsf';
	const SQUIRRLY = 'squirrly';

	/**
	 * Detect the active SEO plugin in priority order (install-share order).
	 *
	 * @return string|null One of the engine constants, or null when none.
	 */
	public static function detect(): ?string {
		$all = self::detect_all();
		return empty( $all ) ? null : $all[0];
	}

	/**
	 * Detect ALL active SEO plugins, in priority (install-share) order. The
	 * first entry is the "primary" engine used for read/precedence decisions;
	 * writes go to every entry that supports postmeta (see the meta bridge).
	 * Two or more entries = duplicate-tags misconfiguration on the site (we
	 * still write to all so whichever the owner keeps has the data).
	 *
	 * @return string[] Engine slugs, possibly empty.
	 */
	public static function detect_all(): array {
		$is_active = static function ( string $plugin ): bool {
			if ( function_exists( 'is_plugin_active' ) ) {
				return (bool) call_user_func( 'is_plugin_active', $plugin );
			}
			$active = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
			return in_array( $plugin, $active, true );
		};

		$engines = array();

		// Prefer the cheap class/constant checks first: they need no WP function
		// (is_plugin_active lives in wp-admin and isn't loaded on the front end),
		// short-circuit before is_plugin_active(), and the class/constant being
		// defined is a stronger "this plugin is really running" signal than the
		// active-plugins option. The is_active() file-path check is the fallback.
		if ( class_exists( 'WPSEO_Options' )
			|| class_exists( 'WPSEO_Taxonomy_Meta' )
			|| $is_active( 'wordpress-seo/wp-seo.php' )
			|| $is_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
			$engines[] = self::YOAST;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || $is_active( 'seo-by-rank-math/rank-math.php' ) ) {
			$engines[] = self::RANKMATH;
		}
		if ( defined( 'AIOSEO_VERSION' )
			|| $is_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' )
			|| $is_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ) ) {
			$engines[] = self::AIOSEO;
		}
		if ( defined( 'SEOPRESS_VERSION' ) || $is_active( 'wp-seopress/seopress.php' ) || $is_active( 'wp-seopress-pro/seopress-pro.php' ) ) {
			$engines[] = self::SEOPRESS;
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || $is_active( 'autodescription/autodescription.php' ) ) {
			$engines[] = self::TSF;
		}
		if ( defined( 'SQ_VERSION' ) || $is_active( 'squirrly-seo/squirrly.php' ) ) {
			$engines[] = self::SQUIRRLY;
		}

		return $engines;
	}

	/**
	 * Version string of a detected engine, or '' when unknown. Used only for
	 * the environment report to the backend — never for behavior gates.
	 *
	 * @param string $engine Engine slug.
	 * @return string
	 */
	public static function version( string $engine ): string {
		switch ( $engine ) {
			case self::YOAST:
				return defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '';
			case self::RANKMATH:
				return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
			case self::AIOSEO:
				return defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '';
			case self::SEOPRESS:
				return defined( 'SEOPRESS_VERSION' ) ? (string) SEOPRESS_VERSION : '';
			case self::TSF:
				return defined( 'THE_SEO_FRAMEWORK_VERSION' ) ? (string) THE_SEO_FRAMEWORK_VERSION : '';
			case self::SQUIRRLY:
				return defined( 'SQ_VERSION' ) ? (string) SQ_VERSION : '';
			default:
				return '';
		}
	}

	/**
	 * Postmeta key that stores the SEO title for the given (or active) engine,
	 * or null when the engine has no postmeta-based title we can write
	 * (AIOSEO — custom table; Squirrly — custom table; or no SEO plugin).
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
			case self::SEOPRESS:
				return '_seopress_titles_title';
			case self::TSF:
				return '_genesis_title';
			default:
				return null;
		}
	}

	/**
	 * Postmeta key that stores the SEO meta description for the given (or
	 * active) engine, or null when unavailable (AIOSEO / Squirrly — custom
	 * tables — or none).
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
			case self::SEOPRESS:
				return '_seopress_titles_desc';
			case self::TSF:
				return '_genesis_description';
			default:
				return null;
		}
	}

	/**
	 * Postmeta key that stores the focus keyword/keyphrase for the given (or
	 * active) engine, or null when the engine keeps it elsewhere (AIOSEO
	 * table JSON, Squirrly) or has no such concept (TSF).
	 *
	 * @param string|null $engine Engine slug, or null to detect the active one.
	 * @return string|null
	 */
	public static function post_focus_kw_key( ?string $engine = null ): ?string {
		$engine = null !== $engine ? $engine : self::detect();
		switch ( $engine ) {
			case self::YOAST:
				return '_yoast_wpseo_focuskw';
			case self::RANKMATH:
				return 'rank_math_focus_keyword';
			case self::SEOPRESS:
				return '_seopress_analysis_target_kw';
			default:
				return null;
		}
	}
}
