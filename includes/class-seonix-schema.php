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
 * keys Seonix writes (focus keyword, meta description). Emitting our own copy of
 * those @types would create two competing graphs — which makes Google ignore
 * both. So in the default "auto" mode, when an engine is detected, Seonix emits
 * ONLY the supplemental @types that engine does not produce (FAQ/Q&A, Review,
 * ItemList, the LocalBusiness family, Service — see SUPPLEMENTAL_TYPES) — which
 * add an AI-citation signal (AI Overviews, ChatGPT, Perplexity) without
 * duplicating the core graph. When no engine is active we emit the full @graph.
 * Operators can force the full graph with mode "on" or disable output with "off".
 * Sites can extend the supplemental allowlist via the
 * `seonix_schema_supplemental_types` filter.
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
	 * @types Seonix may emit alongside a dedicated SEO engine. These add signals
	 * a core engine does not produce: FAQ/Q&A citation blocks, a LocalBusiness
	 * node (NAP + service area), Review testimonials and ItemList directories.
	 * Yoast Free / Rank Math / AIOSEO do not emit LocalBusiness unless a paid
	 * Local-SEO addon is configured, and none of them auto-emit Review or
	 * ItemList, so all of these are genuinely supplemental and never collide
	 * with the engine's core graph. None of these appear in ENGINE_OWNED_TYPES,
	 * so supplemental_only keeps them.
	 *
	 * This is the built-in default; the effective list is supplemental_types(),
	 * which sites can extend via the `seonix_schema_supplemental_types` filter.
	 */
	const SUPPLEMENTAL_TYPES = array(
		'FAQPage',
		'QAPage',
		'LocalBusiness',
		'HomeAndConstructionBusiness',
		'MovingCompany',
		'GeneralContractor',
		'ProfessionalService',
		'Store',
		// A per-page Service node (provider → the site's business @id) is the
		// schema.org-correct way to mark up a service/city landing page without
		// spawning a second business entity. No SEO engine emits it.
		'Service',
		// Review (testimonial pages) and ItemList (service-area / hub pages).
		// Engines emit BreadcrumbList, which is a distinct @type string, so the
		// exact-match intersect below can never confuse the two.
		'Review',
		'ItemList',
	);

	/**
	 * @types a dedicated SEO engine owns. A node carrying any of these is
	 * dropped from the supplemental graph even if it is also tagged with a
	 * supplemental type, so a multi-typed core node can never reintroduce a
	 * duplicate Article / WebPage / Breadcrumb.
	 */
	const ENGINE_OWNED_TYPES = array(
		'Article',
		'NewsArticle',
		'BlogPosting',
		'WebPage',
		'WebSite',
		'BreadcrumbList',
		'Organization',
		'Person',
		'ImageObject',
	);

	/**
	 * The effective supplemental-type allowlist.
	 *
	 * The `seonix_schema_supplemental_types` filter lets a site add @types the
	 * built-in list does not cover (e.g. 'Product', 'Event') — or remove some —
	 * without forking the plugin. The filter cannot bypass the anti-duplication
	 * guard: supplemental_only() drops nodes carrying an ENGINE_OWNED_TYPES
	 * @type regardless of this list, so filtering in 'Article' still never
	 * duplicates the engine's core graph.
	 *
	 * @return string[] Flat list of @type strings; non-string entries returned
	 *                  by a filter callback are discarded.
	 */
	public static function supplemental_types(): array {
		$types = apply_filters( 'seonix_schema_supplemental_types', self::SUPPLEMENTAL_TYPES );
		return array_values( array_filter( (array) $types, 'is_string' ) );
	}

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
		// Re-encode through wp_json_encode so slashes are escaped ("<\/script>")
		// — that is what makes the stored value safe to echo inside <script>.
		// JSON_UNESCAPED_UNICODE keeps non-ASCII text (umlauts, €, dashes) as
		// plain UTF-8 instead of \uXXXX escapes: the value crosses slashing
		// boundaries (meta_input → wp_unslash) on its way into post meta, and a
		// plain-UTF-8 body leaves nothing for a missed slashing layer to eat.
		$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return null;
		}
		return $encoded;
	}

	/**
	 * Repair a stored JSON-LD string whose \uXXXX escapes lost their backslashes.
	 *
	 * Plugin versions ≤ 2.12.10 passed the sanitized JSON to wp_insert_post()
	 * via meta_input without wp_slash(). Core wp_unslash()es meta_input, so one
	 * backslash level was eaten: every "ö" became "u00f6" and "<\/" became
	 * "</". The document stayed valid JSON, but the human-readable text inside
	 * (FAQ questions, priceRange "€€") turned into garbage like
	 * "Mu00f6belmontage" — which is what Google and AI crawlers then read.
	 *
	 * The repair reinstates a backslash before every orphaned u+4-hex sequence
	 * and re-runs sanitize_jsonld (which also restores the "<\/" script-tag
	 * armor). Only the ranges real content produces are targeted — u0xxx
	 * (Latin-1 umlauts, Latin Extended, Cyrillic, Greek) and u20xx (€, dashes,
	 * typographic quotes, ellipsis) — because an orphan can sit mid-word
	 * ("Mu00f6bel"), so the char before it cannot be used to disambiguate;
	 * matching all 4-hex runs would rewrite legitimate "u"+hex substrings in
	 * URLs or IDs. The result must still decode as a schema.org document or
	 * the original string is kept.
	 *
	 * @param string $raw Stored meta value.
	 * @return string|null Re-sanitized JSON when something was repaired and it
	 *                     validates; null when there is nothing to heal or the
	 *                     repaired string fails validation.
	 */
	public static function heal_escapes( string $raw ): ?string {
		$pattern = '/(?<!\\\\)u(0[0-9a-f]{3}|20[0-9a-f]{2})/';
		if ( ! preg_match( $pattern, $raw ) ) {
			return null;
		}
		$repaired = preg_replace( $pattern, '\\\\u$1', $raw );
		if ( ! is_string( $repaired ) || $repaired === $raw ) {
			return null;
		}
		return self::sanitize_jsonld( $repaired );
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
	 * Reduce a stored @graph to only the supplemental nodes (FAQ / Review /
	 * ItemList / LocalBusiness family — see supplemental_types()) that a
	 * dedicated SEO engine does not emit. Nodes carrying an engine-owned @type
	 * are dropped even when also tagged supplemental, so the result can never
	 * duplicate the engine's Article / WebPage / Breadcrumb.
	 *
	 * Returns a re-encoded, slash-escaped JSON document wrapped in a fresh
	 * @graph envelope, or null when the payload has nothing supplemental.
	 *
	 * @param string $jsonld Stored, already-sanitized JSON-LD string.
	 * @return string|null Safe JSON string, or null when there is nothing to emit.
	 */
	public static function supplemental_only( string $jsonld ): ?string {
		$decoded = json_decode( $jsonld, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Normalize to a flat list of nodes: an @graph envelope, or a single node.
		$nodes = ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) )
			? $decoded['@graph']
			: array( $decoded );

		$supplemental = self::supplemental_types();

		$kept = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
				continue;
			}
			$types       = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			$is_supp     = array_intersect( $types, $supplemental );
			$is_engine   = array_intersect( $types, self::ENGINE_OWNED_TYPES );
			if ( $is_supp && ! $is_engine ) {
				unset( $node['@context'] ); // context lives on the envelope, not per-node.
				$kept[] = $node;
			}
		}
		if ( empty( $kept ) ) {
			return null;
		}

		$envelope = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $kept ),
		);
		// Same encoding contract as sanitize_jsonld: slashes escaped for
		// <script> safety, non-ASCII kept as readable UTF-8.
		$encoded = wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE );
		return false === $encoded ? null : $encoded;
	}

	/**
	 * wp_head hook: print the stored JSON-LD for a singular post.
	 *
	 * When no dedicated SEO engine is active (or mode is "on") the full stored
	 * @graph is emitted. When an engine owns the core graph (auto mode) only the
	 * supplemental nodes (FAQ, Review, ItemList, LocalBusiness family) are
	 * emitted, so we add an AI-citation signal without duplicating Article /
	 * WebPage / Breadcrumb. No-op on archives, when disabled, or when the post
	 * has no stored schema.
	 *
	 * @return void
	 */
	public function render_head(): void {
		if ( ! is_singular() ) {
			return;
		}
		if ( 'off' === self::mode() ) {
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

		// Self-heal payloads stored by ≤ 2.12.10, whose \uXXXX escapes were
		// eaten by an unslashed meta_input write. Persist the repaired value
		// (wp_slash — update_post_meta unslashes) so the regex runs once per
		// post, not on every render.
		$healed = self::heal_escapes( $jsonld );
		if ( null !== $healed ) {
			update_post_meta( $post_id, self::META_KEY, wp_slash( $healed ) );
			$jsonld = $healed;
		}

		// should_output() is true when no engine owns the graph (or mode "on") —
		// emit everything. Otherwise emit only the supplemental nodes.
		if ( self::should_output() ) {
			$emit = $jsonld;
		} else {
			$emit = self::supplemental_only( $jsonld );
			if ( null === $emit ) {
				return;
			}
		}

		// The value was re-encoded via wp_json_encode (slashes escaped), so
		// "</script>" cannot appear. Echo verbatim — esc_* helpers would corrupt
		// the JSON.
		echo "\n<script type=\"application/ld+json\">" . $emit . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD validated and slash-escaped at store/build time; esc_* would break the JSON.
	}
}
