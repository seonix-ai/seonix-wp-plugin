<?php
/**
 * JSON-LD structured-data output for Seonix-published articles.
 *
 * The Seonix backend generates a schema.org @graph at publish time and sends it
 * in the publish payload (`schema_jsonld`). The REST controller stores it in
 * post meta (`_seonix_schema_jsonld`); this class renders it into <head>.
 *
 * Anti-duplication: when a dedicated SEO plugin (Yoast / Rank Math / AIOSEO) is
 * active it already emits Article/WebPage/BreadcrumbList JSON-LD from the meta
 * keys Seonix writes (focus keyword, meta description). In the default "auto"
 * mode we stay silent in that case to avoid two competing graphs of the same
 * @type — which makes Google ignore both. Operators can force output with mode
 * "on" or disable it entirely with "off".
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and renders article JSON-LD.
 */
class Seonix_Schema {

	const META_KEY    = '_seonix_schema_jsonld';
	const OPTION_MODE = 'seonix_schema_mode';

	/**
	 * Upper bound on the stored payload. An article @graph is a few KB; 100 KB
	 * is generous and caps a malformed / hostile payload.
	 */
	const MAX_BYTES = 100000;

	/**
	 * Validate and normalize a raw JSON-LD string from the publish payload.
	 *
	 * Returns a safe, re-encoded JSON string (slashes escaped, so the value can
	 * never break out of the surrounding <script> tag with "</script>") or null
	 * when the input is empty, oversized, not valid JSON, or not a schema.org
	 * document.
	 *
	 * @param mixed $raw Raw value from the request.
	 * @return string|null Safe JSON string, or null to skip storing.
	 */
	public static function sanitize_jsonld( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > self::MAX_BYTES ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Must look like a schema.org document — a non-empty @graph, or a
		// @context string that points at schema.org. Guards against storing
		// arbitrary JSON that happens to parse (e.g. {"@context":"evil"}).
		$has_graph         = ! empty( $decoded['@graph'] );
		$context           = isset( $decoded['@context'] ) ? $decoded['@context'] : null;
		$has_valid_context = is_string( $context ) && false !== strpos( $context, 'schema.org' );
		if ( ! $has_graph && ! $has_valid_context ) {
			return null;
		}
		// Re-encode through wp_json_encode so slashes are escaped ("<\/script>").
		// This is what makes the stored value safe to echo inside <script>.
		$encoded = wp_json_encode( $decoded );
		if ( false === $encoded ) {
			return null;
		}
		return $encoded;
	}

	/**
	 * Output mode: 'auto' (default), 'on', or 'off'. Unknown values clamp to
	 * 'auto' so a corrupt option never silently disables structured data.
	 *
	 * @return string
	 */
	public static function mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, 'auto' );
		if ( ! in_array( $mode, array( 'auto', 'on', 'off' ), true ) ) {
			return 'auto';
		}
		return $mode;
	}

	/**
	 * Detect the active SEO plugin in priority order. Mirrors the SEO-Fix
	 * subsystem's detector so both halves of the plugin agree on which engine
	 * owns structured data.
	 *
	 * @return string|null One of 'yoast'|'rankmath'|'aioseo', or null when none.
	 */
	public static function detect_active_engine(): ?string {
		$is_active = static function ( string $plugin ): bool {
			if ( function_exists( 'is_plugin_active' ) ) {
				return (bool) call_user_func( 'is_plugin_active', $plugin );
			}
			$active = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
			return in_array( $plugin, $active, true );
		};

		if ( $is_active( 'wordpress-seo/wp-seo.php' )
			|| $is_active( 'wordpress-seo-premium/wp-seo-premium.php' )
			|| class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( $is_active( 'seo-by-rank-math/rank-math.php' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( $is_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' )
			|| $is_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' )
			|| defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return null;
	}

	/**
	 * Decide whether Seonix should emit its own JSON-LD, given the mode and the
	 * presence of a competing SEO plugin.
	 *
	 * @return bool
	 */
	public static function should_output(): bool {
		switch ( self::mode() ) {
			case 'off':
				return false;
			case 'on':
				return true;
			case 'auto':
			default:
				// Only emit when no dedicated SEO plugin already owns the graph.
				return null === self::detect_active_engine();
		}
	}

	/**
	 * wp_head hook: print the stored JSON-LD for a singular post when output is
	 * enabled. No-op on archives, when disabled, or when the post has no stored
	 * schema.
	 *
	 * @return void
	 */
	public function render_head(): void {
		if ( ! is_singular() ) {
			return;
		}
		if ( ! self::should_output() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$jsonld = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_string( $jsonld ) || '' === trim( $jsonld ) ) {
			return;
		}
		// The value was re-encoded via wp_json_encode at store time (slashes
		// escaped), so "</script>" cannot appear. Echo verbatim — esc_* helpers
		// would corrupt the JSON.
		echo "\n<script type=\"application/ld+json\">" . $jsonld . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD validated and slash-escaped at store time; esc_* would break the JSON.
	}
}
