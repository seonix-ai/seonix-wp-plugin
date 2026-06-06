<?php
/**
 * Fix method: term_meta_description.
 *
 * Sets the SEO meta description for a *taxonomy term* (category, tag, custom
 * taxonomy archive). Identified by the archive URL the scanner reports —
 * the plugin resolves the URL to a term, reads/writes the description through
 * whichever supported SEO plugin is active, and never invents content on its
 * own. The suggested string is produced by the Seonix backend.
 *
 * Lives outside Seonix_Fix_Single_Meta because that base assumes a numeric
 * post_id and a single post-meta key, whereas terms need URL→term resolution
 * and a per-engine storage abstraction (one engine uses an options array, the
 * others use term meta).
 *
 * Backwards-compatible with the legacy `meta_description` method, which keeps
 * handling singular post URLs. Older Seonix backends that don't know about
 * `term_meta_description` simply won't dispatch to it — the registry tells
 * them what's available via the capabilities endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Term_Meta_Description implements Seonix_Fix_Method {

	protected Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'term_meta_description';
	}

	public function validate_params( array $params ) {
		if ( empty( $params['term_url'] ) || ! is_string( $params['term_url'] ) ) {
			return new WP_Error( 'missing_term_url', 'term_url is required and must be a string.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '#^https?://#i', $params['term_url'] ) ) {
			return new WP_Error( 'invalid_term_url', 'term_url must be an absolute http(s) URL.', array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( 'suggested_value', $params ) || ! is_string( $params['suggested_value'] ) ) {
			return new WP_Error( 'missing_suggested_value', 'suggested_value is required and must be a string.', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		$resolved = $this->resolve_term( (string) $params['term_url'] );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}
		$current = $this->read_description( $resolved['term_id'], $resolved['taxonomy'] );
		if ( $current instanceof WP_Error ) {
			return $current;
		}
		return $this->describe_result(
			$resolved['term_id'],
			$resolved['taxonomy'],
			$current,
			sanitize_text_field( (string) $params['suggested_value'] )
		);
	}

	public function apply( array $params ) {
		$resolved = $this->resolve_term( (string) $params['term_url'] );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}

		$term_id   = $resolved['term_id'];
		$taxonomy  = $resolved['taxonomy'];
		$suggested = sanitize_text_field( (string) $params['suggested_value'] );
		$current   = $this->read_description( $term_id, $taxonomy );
		if ( $current instanceof WP_Error ) {
			return $current;
		}

		// Safety guard mirrors Seonix_Fix_Single_Meta: never wipe an existing
		// non-empty description with an empty suggestion.
		if ( '' === $suggested && '' !== $current ) {
			return new WP_Error(
				'refuse_overwrite_empty',
				'Refusing to overwrite an existing term meta description with an empty suggestion. Fill suggested_value before applying.',
				array( 'status' => 422 )
			);
		}

		$result = $this->describe_result( $term_id, $taxonomy, $current, $suggested );
		if ( $result['no_op'] ) {
			return $result;
		}

		$written = $this->write_description( $term_id, $taxonomy, $suggested );
		if ( $written instanceof WP_Error ) {
			return $written;
		}
		return $result;
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$term_id  = (int) ( $entry['target_id'] ?? 0 );
		$taxonomy = (string) ( $entry['before_state']['taxonomy'] ?? ( $entry['after_state']['taxonomy'] ?? '' ) );
		$old_val  = $entry['before_state']['value'] ?? null;

		if ( $term_id <= 0 || ! is_string( $old_val ) || '' === $taxonomy ) {
			return new WP_Error( 'invalid_history_entry', 'History entry is missing term snapshot.', array( 'status' => 422 ) );
		}

		$written = $this->write_description( $term_id, $taxonomy, $old_val );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		return array(
			'before' => array( 'value' => (string) ( $entry['after_state']['value'] ?? '' ), 'taxonomy' => $taxonomy ),
			'after'  => array( 'value' => $old_val, 'taxonomy' => $taxonomy ),
		);
	}

	// ─── URL → Term resolution ───────────────────────────────────────────

	/**
	 * Resolve an archive URL to a term. Returns ['term_id'=>int,'taxonomy'=>string]
	 * or WP_Error.
	 *
	 * Strategy: prefer WP core's url_to_termid() when available (rare — many
	 * sites have it disabled by themes). Otherwise parse `/category/<slug>` or
	 * `/tag/<slug>` segments from the URL (also tolerates trailing pagination
	 * `/page/N/`) and look the slug up. Custom taxonomy archive URLs are best-
	 * effort: we sniff any non-pagination slug after a known taxonomy base.
	 *
	 * @return array{term_id:int,taxonomy:string}|\WP_Error
	 */
	private function resolve_term( string $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return new WP_Error( 'term_url_no_path', 'term_url has no path component.', array( 'status' => 400 ) );
		}

		// Try WP core resolver first if available — it understands custom
		// permalink structures and the full term_taxonomy table.
		if ( function_exists( 'url_to_termid' ) ) {
			$term_id = call_user_func( 'url_to_termid', $url );
			if ( is_numeric( $term_id ) && (int) $term_id > 0 ) {
				$term = function_exists( 'get_term' ) ? get_term( (int) $term_id ) : null;
				if ( $term && ! ( $term instanceof WP_Error ) && isset( $term->taxonomy ) ) {
					return array(
						'term_id'  => (int) $term_id,
						'taxonomy' => (string) $term->taxonomy,
					);
				}
			}
		}

		// Strip pagination tail: /page/2/ etc.
		$path = preg_replace( '#/page/\d+/?$#', '/', $path );
		$segments = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ) ) );
		if ( empty( $segments ) ) {
			return new WP_Error( 'term_url_empty_path', 'term_url path resolved to empty segments.', array( 'status' => 400 ) );
		}

		// Map URL base segment → taxonomy. Default WP slugs first; custom
		// taxonomy bases can be added later without changing callers.
		$base_to_tax = array(
			'category'   => 'category',
			'tag'        => 'post_tag',
			'kategorie'  => 'category',  // common German rewrite
			'schlagwort' => 'post_tag',
		);

		$slug     = null;
		$taxonomy = null;
		foreach ( $segments as $i => $seg ) {
			if ( isset( $base_to_tax[ $seg ] ) && isset( $segments[ $i + 1 ] ) ) {
				$taxonomy = $base_to_tax[ $seg ];
				$slug     = $segments[ $i + 1 ];
				break;
			}
		}

		// Fallback: last segment as category slug. Helpful for category bases
		// removed via permalink settings ("Strip the category base").
		if ( null === $slug ) {
			$slug     = end( $segments );
			$taxonomy = 'category';
		}

		if ( ! function_exists( 'get_term_by' ) ) {
			return new WP_Error( 'wp_not_loaded', 'get_term_by is not available.', array( 'status' => 500 ) );
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		// If the default-taxonomy lookup failed and we used the last-segment
		// fallback, try post_tag too before giving up.
		if ( ( ! $term || $term instanceof WP_Error ) && 'category' === $taxonomy ) {
			$alt = get_term_by( 'slug', $slug, 'post_tag' );
			if ( $alt && ! ( $alt instanceof WP_Error ) ) {
				$term     = $alt;
				$taxonomy = 'post_tag';
			}
		}

		if ( ! $term || $term instanceof WP_Error || empty( $term->term_id ) ) {
			return new WP_Error(
				'term_not_found',
				sprintf( 'No term found for slug "%s" in taxonomy "%s".', $slug, $taxonomy ),
				array( 'status' => 404 )
			);
		}

		return array(
			'term_id'  => (int) $term->term_id,
			'taxonomy' => (string) ( $term->taxonomy ?? $taxonomy ),
		);
	}

	// ─── SEO-engine detection + read/write ───────────────────────────────

	/**
	 * Detect the active SEO plugin in priority order.
	 * @return 'yoast'|'rankmath'|'aioseo'|null
	 */
	private function detect_active_engine(): ?string {
		$is_active = function ( string $plugin ): bool {
			if ( function_exists( 'is_plugin_active' ) ) {
				return (bool) call_user_func( 'is_plugin_active', $plugin );
			}
			$active = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
			return in_array( $plugin, $active, true );
		};

		// Detection also accepts presence of the WPSEO class so the
		// premium-only file path doesn't matter.
		if ( $is_active( 'wordpress-seo/wp-seo.php' )
			|| $is_active( 'wordpress-seo-premium/wp-seo-premium.php' )
			|| class_exists( 'WPSEO_Options' )
			|| class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
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
	 * Read the current term meta description from the active SEO plugin.
	 * Returns the string (possibly '') or a WP_Error when no plugin is detected.
	 *
	 * @return string|\WP_Error
	 */
	private function read_description( int $term_id, string $taxonomy ) {
		$engine = $this->detect_active_engine();
		if ( null === $engine ) {
			return new WP_Error( 'no_seo_plugin', 'No supported SEO plugin detected.', array( 'status' => 422 ) );
		}

		switch ( $engine ) {
			case 'yoast':
				return (string) $this->engine_read( $term_id, $taxonomy );
			case 'rankmath':
				return (string) get_term_meta( $term_id, 'rank_math_description', true );
			case 'aioseo':
				return (string) get_term_meta( $term_id, '_aioseo_description', true );
		}
		return '';
	}

	/**
	 * Write the term meta description for the active SEO plugin.
	 *
	 * @return true|\WP_Error
	 */
	private function write_description( int $term_id, string $taxonomy, string $value ) {
		$engine = $this->detect_active_engine();
		if ( null === $engine ) {
			return new WP_Error( 'no_seo_plugin', 'No supported SEO plugin detected.', array( 'status' => 422 ) );
		}

		switch ( $engine ) {
			case 'yoast':
				return $this->engine_write( $term_id, $taxonomy, $value );
			case 'rankmath':
				$ok = update_term_meta( $term_id, 'rank_math_description', $value );
				return false !== $ok ? true : new WP_Error( 'update_failed', 'update_term_meta returned false (rank_math_description).', array( 'status' => 500 ) );
			case 'aioseo':
				$ok = update_term_meta( $term_id, '_aioseo_description', $value );
				return false !== $ok ? true : new WP_Error( 'update_failed', 'update_term_meta returned false (_aioseo_description).', array( 'status' => 500 ) );
		}
		return new WP_Error( 'no_seo_plugin', 'Unhandled SEO engine branch.', array( 'status' => 500 ) );
	}

	/**
	 * Read the term description through Yoast SEO's public class API
	 * (`WPSEO_Taxonomy_Meta::get_term_meta`). If the class is unreachable we
	 * treat the value as empty rather than reach into the `wpseo_taxonomy_meta`
	 * option array directly — the option is owned by Yoast and we only
	 * interact with it through their public API.
	 */
	private function engine_read( int $term_id, string $taxonomy ): string {
		if ( ! class_exists( 'WPSEO_Taxonomy_Meta' ) || ! is_callable( array( 'WPSEO_Taxonomy_Meta', 'get_term_meta' ) ) ) {
			return '';
		}
		$val = call_user_func( array( 'WPSEO_Taxonomy_Meta', 'get_term_meta' ), $term_id, $taxonomy, 'desc' );
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Write the term description through Yoast SEO's public class API
	 * (`WPSEO_Taxonomy_Meta::set_value`). The setter handles validation,
	 * cache invalidation and the underlying option write — we never touch
	 * the `wpseo_taxonomy_meta` option key directly.
	 *
	 * This branch is reached only when `detect_active_engine() === 'yoast'`;
	 * we additionally gate on `WPSEO_VERSION` so the write fails loud and
	 * early on hosts where the Yoast PHP class is unreachable for any
	 * reason (engine flag drift, partial activation, etc.).
	 *
	 * @return true|\WP_Error
	 */
	private function engine_write( int $term_id, string $taxonomy, string $value ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return new WP_Error(
				'yoast_inactive',
				'Yoast SEO plugin is not active — engine_write is a no-op.',
				array( 'status' => 412 )
			);
		}

		if ( ! class_exists( 'WPSEO_Taxonomy_Meta' ) || ! is_callable( array( 'WPSEO_Taxonomy_Meta', 'set_value' ) ) ) {
			return new WP_Error(
				'yoast_api_unavailable',
				'Yoast SEO public class API (WPSEO_Taxonomy_Meta::set_value) is unavailable.',
				array( 'status' => 412 )
			);
		}

		call_user_func( array( 'WPSEO_Taxonomy_Meta', 'set_value' ), $term_id, $taxonomy, 'wpseo_desc', $value );
		$this->engine_sync_indexable( $term_id, $taxonomy, $value );
		return true;
	}

	/**
	 * The SEO indexable layer (v14+) renders meta tags from the
	 * `wp_yoast_indexable` table, not from the `wpseo_taxonomy_meta` option.
	 * If we update the option but not the indexable row, the storage layer
	 * keeps shipping the old (NULL/empty) description in og:description and
	 * <meta name="description"> on the live page — so the scanner keeps
	 * reporting meta_description_missing even though the back-end value is set.
	 *
	 * Sync the indexable row directly. We touch only the description column;
	 * the SEO plugin's normal cron-based reconciliation handles everything
	 * else. Silent best-effort: the indexable table is optional, only present
	 * when the Indexables module is enabled (default since v14).
	 */
	private function engine_sync_indexable( int $term_id, string $taxonomy, string $value ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . 'yoast_indexable';
		// Suppress errors so missing-table on installs without the Indexables
		// module degrades to a no-op without surfacing a WP notice.
		$prev_show_errors    = isset( $wpdb->show_errors ) ? $wpdb->show_errors : false;
		$prev_suppress_errors = method_exists( $wpdb, 'suppress_errors' )
			? $wpdb->suppress_errors( true )
			: false;
		// The indexables table is the source of truth for SEO descriptions
		// when the Indexables module is active. Direct update is the only path.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'description' => $value ),
			array(
				'object_type'     => 'term',
				'object_sub_type' => $taxonomy,
				'object_id'       => $term_id,
			),
			array( '%s' ),
			array( '%s', '%s', '%d' )
		);
		if ( method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( $prev_suppress_errors );
		}
		// Drop the per-indexable object cache so the next request reads
		// fresh from the DB.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( "yoast_indexable_term_{$term_id}_{$taxonomy}", 'yoast_indexables' );
		}
	}

	// ─── Result shape ────────────────────────────────────────────────────

	private function describe_result( int $term_id, string $taxonomy, string $current, string $suggested ): array {
		$is_no_op = $current === $suggested;
		$warnings = array();
		if ( ! $is_no_op && $suggested !== '' ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $suggested ) : strlen( $suggested );
			if ( $len < 30 || $len > 160 ) {
				$warnings[] = sprintf( 'suggested length %d outside the conventional 30–160 char range', $len );
			}
		}
		return array(
			'before'   => array( 'value' => $current, 'taxonomy' => $taxonomy ),
			'after'    => array( 'value' => $suggested, 'taxonomy' => $taxonomy ),
			'no_op'    => $is_no_op,
			'warnings' => $warnings,
			'target'   => array(
				'type'     => 'term',
				'id'       => $term_id,
				'taxonomy' => $taxonomy,
			),
		);
	}

	private function truncate( string $s, int $max = 80 ): string {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max - 1 ) . '…' : $s;
		}
		return strlen( $s ) > $max ? substr( $s, 0, $max - 1 ) . '…' : $s;
	}
}
