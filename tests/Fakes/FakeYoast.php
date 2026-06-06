<?php
/**
 * Test doubles for the Yoast SEO public APIs the plugin talks to.
 *
 * Since 2.4.2 ("Yoast public-API-only" hotfix) production code only ever
 * touches Yoast through these public surfaces — `WPSEO_Options::{get,set}` for
 * the title / robots-subpages options and `WPSEO_Taxonomy_Meta::{get_term_meta,
 * set_value}` for term descriptions — never the underlying `wpseo_*` option
 * arrays via get_option()/update_option(). These fakes mirror that contract
 * with an in-memory store so unit tests can exercise the Yoast-active path
 * without loading the real plugin.
 *
 * `WPSEO_VERSION` is the constant the plugin checks to decide Yoast is active;
 * the fix methods bail with a 412 WP_Error when it is undefined. Defining it
 * here flips the whole unit suite into "Yoast active" mode — safe because the
 * only consumers of these symbols are the three Yoast-dependent classes, each
 * exercised solely by its own test, and an un-seeded store reads back exactly
 * like an inactive install (empty / false).
 *
 * Each fake exposes a public static store plus reset()/set-call counters so a
 * test can seed a starting state, assert what was written, and prove the no-op
 * paths never call the setter. Reset the relevant store in setUp() to isolate
 * tests.
 */

if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '99.9-test' );
}

if ( ! class_exists( 'WPSEO_Options' ) ) {
	/**
	 * Mirror of Yoast's option accessor. get()/set() route through a flat
	 * key→value store; set() touches only the named key, exactly like Yoast's
	 * real setter — which is why callers can rely on "every other key in
	 * wpseo_titles is preserved" without managing the array themselves.
	 */
	class WPSEO_Options {
		/** @var array<string,mixed> */
		public static array $store = array();

		/** Number of set() calls since the last reset() — lets tests assert no-op writes. */
		public static int $set_calls = 0;

		public static function reset(): void {
			self::$store     = array();
			self::$set_calls = 0;
		}

		public static function get( $key, $default = null ) {
			return array_key_exists( $key, self::$store ) ? self::$store[ $key ] : $default;
		}

		public static function set( $key, $value ): void {
			++self::$set_calls;
			self::$store[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
	/**
	 * Mirror of Yoast's taxonomy-meta accessor. Reads take the short meta key
	 * ('desc'); writes take the full storage key ('wpseo_desc') — matching
	 * Yoast's real signatures — so the fake normalises reads to the 'wpseo_'
	 * prefix to keep the two consistent.
	 */
	class WPSEO_Taxonomy_Meta {
		/** @var array<string,array<int,array<string,mixed>>> taxonomy → term_id → key → value */
		public static array $store = array();

		/** Number of set_value() calls since the last reset(). */
		public static int $set_calls = 0;

		public static function reset(): void {
			self::$store     = array();
			self::$set_calls = 0;
		}

		public static function get_term_meta( $term_id, $taxonomy, $meta ) {
			$full = 'wpseo_' . $meta;
			return self::$store[ $taxonomy ][ $term_id ][ $full ] ?? '';
		}

		public static function set_value( $term_id, $taxonomy, $meta_key, $meta_value ): void {
			++self::$set_calls;
			self::$store[ $taxonomy ][ $term_id ][ $meta_key ] = $meta_value;
		}

		/** Seed a starting description without counting it as a write. */
		public static function seed( $term_id, $taxonomy, $value ): void {
			self::$store[ $taxonomy ][ $term_id ]['wpseo_desc'] = $value;
		}
	}
}
