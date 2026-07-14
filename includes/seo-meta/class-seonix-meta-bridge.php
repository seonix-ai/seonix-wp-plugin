<?php
/**
 * SEO meta bridge — Seonix's canonical per-post SEO fields + fan-out to the
 * active SEO plugin(s).
 *
 * Seonix always persists its own copy of the SEO title / meta description /
 * focus keyword under `_seonix_*` postmeta (the canonical store — it survives
 * the site switching from Yoast to Rank Math, or removing the SEO plugin
 * altogether), and mirrors the values into every ACTIVE engine's storage:
 *
 *   Yoast / Rank Math / SEOPress / TSF → their documented postmeta keys
 *   AIOSEO                             → its Post model (custom wp_aioseo_posts
 *                                        table; `_aioseo_*` postmeta is a
 *                                        one-way mirror AIOSEO never reads, so
 *                                        writing it would be a silent no-op)
 *
 * A fingerprint meta records the last synced hash + who wrote it, so the
 * reverse-sync watcher can tell "the site owner edited this in their SEO
 * plugin" apart from "Seonix just wrote this itself" and never loops.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Meta_Bridge {

	const META_TITLE       = '_seonix_seo_title';
	const META_DESC        = '_seonix_meta_description';
	const META_FOCUS_KW    = '_seonix_focus_keyword';
	const META_FINGERPRINT = '_seonix_meta_fingerprint';

	/**
	 * True while Seonix itself is writing SEO meta in this request. The
	 * reverse-sync watcher checks this so our own update_post_meta() calls
	 * never masquerade as site-owner edits.
	 *
	 * @var bool
	 */
	public static $writing = false;

	/**
	 * Strip SEO-plugin template variables from a value before it is stored.
	 *
	 * Every engine expands its template syntax even inside per-post values:
	 * Yoast `%%title%%`, Rank Math `%title%`, AIOSEO `#post_title`. AI-written
	 * copy must never smuggle one in, or the rendered tag would show the
	 * expanded variable instead of the literal text. AIOSEO's `#` syntax is
	 * stripped from a known-tag allowlist only, so an innocent hashtag like
	 * "#seo" survives.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_value( string $value ): string {
		// Yoast %%var%% first (greedier), then Rank Math / SEOPress %var%.
		$value = (string) preg_replace( '/%%[a-z0-9_]+%%/i', '', $value );
		$value = (string) preg_replace( '/%[a-z0-9_]+%/i', '', $value );
		// AIOSEO smart tags — known names only.
		$aioseo_tags = '(?:post_title|site_title|separator_sa|separator|tagline|post_excerpt|post_content|post_date|post_day|post_month|post_year|current_date|current_day|current_month|current_year|author_first_name|author_last_name|author_name|categories|taxonomy_title|permalink|attachment_caption|alt_tag)';
		$value       = (string) preg_replace( '/#' . $aioseo_tags . '\b/i', '', $value );
		// Collapse whitespace runs left behind by removed variables.
		$value = (string) preg_replace( '/\s{2,}/', ' ', $value );
		return trim( $value );
	}

	/**
	 * Normalize an incoming fields array: keep only the three known fields,
	 * sanitize each, drop nulls (null = "leave untouched").
	 *
	 * @param array $fields ['seo_title' => ?string, 'meta_description' => ?string, 'focus_keyword' => ?string].
	 * @return array<string,string> Sanitized subset.
	 */
	private static function normalize_fields( array $fields ): array {
		$out = array();
		foreach ( array( 'seo_title', 'meta_description', 'focus_keyword' ) as $key ) {
			if ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) ) {
				$out[ $key ] = self::sanitize_value( $fields[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Build a meta_input array for wp_insert_post() covering the Seonix
	 * canonical keys + every active postmeta engine. Passing SEO meta through
	 * meta_input (rather than update_post_meta after insert) matters for
	 * Yoast: its indexable is built during the insert and picks the values up
	 * immediately.
	 *
	 * AIOSEO is NOT covered here (custom table, needs the post to exist) —
	 * call write_aioseo() after the insert.
	 *
	 * Empty-string values are skipped: publish semantics are "set when
	 * provided", never "clear".
	 *
	 * @param array $fields See normalize_fields().
	 * @return array<string,string> meta_input additions.
	 */
	public static function meta_input( array $fields ): array {
		$fields = self::normalize_fields( $fields );
		$input  = array();

		$title = isset( $fields['seo_title'] ) ? $fields['seo_title'] : '';
		$desc  = isset( $fields['meta_description'] ) ? $fields['meta_description'] : '';
		$kw    = isset( $fields['focus_keyword'] ) ? $fields['focus_keyword'] : '';

		if ( '' !== $title ) {
			$input[ self::META_TITLE ] = $title;
		}
		if ( '' !== $desc ) {
			$input[ self::META_DESC ] = $desc;
		}
		if ( '' !== $kw ) {
			$input[ self::META_FOCUS_KW ] = $kw;
		}
		if ( empty( $input ) ) {
			return array();
		}

		foreach ( Seonix_SEO_Engine::detect_all() as $engine ) {
			if ( '' !== $title ) {
				$key = Seonix_SEO_Engine::post_title_key( $engine );
				if ( null !== $key ) {
					$input[ $key ] = $title;
				}
				if ( Seonix_SEO_Engine::TSF === $engine ) {
					// TSF appends the blogname to custom titles unless told not
					// to — our titles already budget for branding upstream.
					$input['_tsf_title_no_blogname'] = '1';
				}
			}
			if ( '' !== $desc ) {
				$key = Seonix_SEO_Engine::post_desc_key( $engine );
				if ( null !== $key ) {
					$input[ $key ] = $desc;
				}
			}
			if ( '' !== $kw ) {
				$key = Seonix_SEO_Engine::post_focus_kw_key( $engine );
				if ( null !== $key ) {
					$input[ $key ] = $kw;
				}
			}
		}

		// Fingerprint what we are about to write so the reverse-sync watcher
		// recognizes this write as Seonix's own.
		$input[ self::META_FINGERPRINT ] = self::build_fingerprint( $title, $desc, $kw, 'seonix' );

		return $input;
	}

	/**
	 * Write SEO fields for an existing post: canonical `_seonix_*` keys, every
	 * active postmeta engine, and AIOSEO's model when active. Used by the
	 * SEO-Fix methods, the reverse-sync backfill, and post-insert completion.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  See normalize_fields(). Null values untouched;
	 *                       empty strings ARE written (deliberate clear —
	 *                       callers guard against accidental empties).
	 * @param string $source Fingerprint source ('seonix' or 'wp:<engine>').
	 * @return void
	 */
	public static function write( int $post_id, array $fields, string $source = 'seonix' ): void {
		$fields = self::normalize_fields( $fields );
		if ( empty( $fields ) || $post_id <= 0 ) {
			return;
		}

		self::$writing = true;
		try {
			$own_map = array(
				'seo_title'        => self::META_TITLE,
				'meta_description' => self::META_DESC,
				'focus_keyword'    => self::META_FOCUS_KW,
			);
			foreach ( $fields as $field => $value ) {
				// wp_slash: update_post_meta() wp_unslash()es the value; without
				// re-slashing a backslash in the text would be silently eaten.
				update_post_meta( $post_id, $own_map[ $field ], wp_slash( $value ) );
			}

			foreach ( Seonix_SEO_Engine::detect_all() as $engine ) {
				if ( Seonix_SEO_Engine::AIOSEO === $engine ) {
					self::write_aioseo( $post_id, $fields );
					continue;
				}
				if ( isset( $fields['seo_title'] ) ) {
					$key = Seonix_SEO_Engine::post_title_key( $engine );
					if ( null !== $key ) {
						update_post_meta( $post_id, $key, wp_slash( $fields['seo_title'] ) );
					}
					if ( Seonix_SEO_Engine::TSF === $engine && '' !== $fields['seo_title'] ) {
						update_post_meta( $post_id, '_tsf_title_no_blogname', '1' );
					}
				}
				if ( isset( $fields['meta_description'] ) ) {
					$key = Seonix_SEO_Engine::post_desc_key( $engine );
					if ( null !== $key ) {
						update_post_meta( $post_id, $key, wp_slash( $fields['meta_description'] ) );
					}
				}
				if ( isset( $fields['focus_keyword'] ) ) {
					$key = Seonix_SEO_Engine::post_focus_kw_key( $engine );
					if ( null !== $key ) {
						update_post_meta( $post_id, $key, wp_slash( $fields['focus_keyword'] ) );
					}
				}
			}

			// Refresh the fingerprint against the now-effective values so a
			// partial write (e.g. description-only fix) still hashes the full
			// triple.
			$effective = self::read_own( $post_id );
			update_post_meta(
				$post_id,
				self::META_FINGERPRINT,
				self::build_fingerprint(
					$effective['seo_title'],
					$effective['meta_description'],
					$effective['focus_keyword'],
					$source
				)
			);
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Write title/description/focus keyphrase into AIOSEO's own storage via
	 * its Post model — the only write path AIOSEO actually reads (postmeta is
	 * ignored). Best-effort: a broken/old AIOSEO install must never fail the
	 * publish, so every model interaction is wrapped.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Normalized fields.
	 * @return bool True when the model write succeeded.
	 */
	public static function write_aioseo( int $post_id, array $fields ): bool {
		if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return false;
		}
		try {
			$aioseo_post          = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
			$aioseo_post->post_id = $post_id;
			if ( isset( $fields['seo_title'] ) ) {
				$aioseo_post->title = $fields['seo_title'];
			}
			if ( isset( $fields['meta_description'] ) ) {
				$aioseo_post->description = $fields['meta_description'];
			}
			if ( isset( $fields['focus_keyword'] ) && '' !== $fields['focus_keyword'] ) {
				$aioseo_post->keyphrases = wp_json_encode( array(
					'focus'      => array( 'keyphrase' => $fields['focus_keyword'] ),
					'additional' => array(),
				) );
			}
			$aioseo_post->save();
			return true;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics; AIOSEO model API is undocumented and may change.
			error_log( 'Seonix: AIOSEO meta write failed for post ' . $post_id . ': ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Seonix's own stored values for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{seo_title:string,meta_description:string,focus_keyword:string}
	 */
	public static function read_own( int $post_id ): array {
		return array(
			'seo_title'        => (string) get_post_meta( $post_id, self::META_TITLE, true ),
			'meta_description' => (string) get_post_meta( $post_id, self::META_DESC, true ),
			'focus_keyword'    => (string) get_post_meta( $post_id, self::META_FOCUS_KW, true ),
		);
	}

	/**
	 * Effective values as the PRIMARY active engine sees them, falling back to
	 * Seonix's own store when no engine is active (or the engine has no value).
	 * This is what the reverse-sync watcher diffs and what post imports read.
	 *
	 * @param int $post_id Post ID.
	 * @return array{seo_title:string,meta_description:string,focus_keyword:string,engine:?string}
	 */
	public static function read_effective( int $post_id ): array {
		$own    = self::read_own( $post_id );
		$engine = Seonix_SEO_Engine::detect();
		$out    = array(
			'seo_title'        => $own['seo_title'],
			'meta_description' => $own['meta_description'],
			'focus_keyword'    => $own['focus_keyword'],
			'engine'           => $engine,
		);
		if ( null === $engine ) {
			return $out;
		}

		if ( Seonix_SEO_Engine::AIOSEO === $engine ) {
			$model = self::read_aioseo( $post_id );
			foreach ( array( 'seo_title', 'meta_description', 'focus_keyword' ) as $field ) {
				if ( '' !== $model[ $field ] ) {
					$out[ $field ] = $model[ $field ];
				}
			}
			return $out;
		}

		$title_key = Seonix_SEO_Engine::post_title_key( $engine );
		$desc_key  = Seonix_SEO_Engine::post_desc_key( $engine );
		$kw_key    = Seonix_SEO_Engine::post_focus_kw_key( $engine );
		if ( null !== $title_key ) {
			$val = (string) get_post_meta( $post_id, $title_key, true );
			if ( '' !== $val ) {
				$out['seo_title'] = $val;
			}
		}
		if ( null !== $desc_key ) {
			$val = (string) get_post_meta( $post_id, $desc_key, true );
			if ( '' !== $val ) {
				$out['meta_description'] = $val;
			}
		}
		if ( null !== $kw_key ) {
			$val = (string) get_post_meta( $post_id, $kw_key, true );
			if ( '' !== $val ) {
				$out['focus_keyword'] = $val;
			}
		}
		return $out;
	}

	/**
	 * Read title/description/focus keyphrase from AIOSEO's model. Empty
	 * strings when unavailable.
	 *
	 * @param int $post_id Post ID.
	 * @return array{seo_title:string,meta_description:string,focus_keyword:string}
	 */
	private static function read_aioseo( int $post_id ): array {
		$out = array(
			'seo_title'        => '',
			'meta_description' => '',
			'focus_keyword'    => '',
		);
		if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			// Mirror postmeta is stale-prone but better than nothing when the
			// model class is missing (very old AIOSEO).
			$out['seo_title']        = (string) get_post_meta( $post_id, '_aioseo_title', true );
			$out['meta_description'] = (string) get_post_meta( $post_id, '_aioseo_description', true );
			return $out;
		}
		try {
			$aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
			if ( $aioseo_post && $aioseo_post->exists() ) {
				$out['seo_title']        = (string) $aioseo_post->title;
				$out['meta_description'] = (string) $aioseo_post->description;
				$keyphrases              = is_string( $aioseo_post->keyphrases ) ? json_decode( $aioseo_post->keyphrases, true ) : (array) $aioseo_post->keyphrases;
				if ( isset( $keyphrases['focus']['keyphrase'] ) && is_string( $keyphrases['focus']['keyphrase'] ) ) {
					$out['focus_keyword'] = $keyphrases['focus']['keyphrase'];
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Diagnostics-only path; fall through to empty values.
		}
		return $out;
	}

	/**
	 * Fingerprint JSON for the given triple.
	 *
	 * @param string $title  SEO title.
	 * @param string $desc   Meta description.
	 * @param string $kw     Focus keyword.
	 * @param string $source 'seonix' or 'wp:<engine>'.
	 * @return string JSON.
	 */
	public static function build_fingerprint( string $title, string $desc, string $kw, string $source ): string {
		return (string) wp_json_encode( array(
			'h'   => self::hash_triple( $title, $desc, $kw ),
			'src' => $source,
			't'   => time(),
		) );
	}

	/**
	 * Stable hash of the field triple.
	 */
	public static function hash_triple( string $title, string $desc, string $kw ): string {
		return hash( 'sha256', $title . '|' . $desc . '|' . $kw );
	}

	/**
	 * Stored fingerprint, decoded. Null when absent/corrupt.
	 *
	 * @param int $post_id Post ID.
	 * @return array{h:string,src:string,t:int}|null
	 */
	public static function fingerprint( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, self::META_FINGERPRINT, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['h'] ) ) {
			return null;
		}
		return array(
			'h'   => (string) $decoded['h'],
			'src' => isset( $decoded['src'] ) ? (string) $decoded['src'] : '',
			't'   => isset( $decoded['t'] ) ? (int) $decoded['t'] : 0,
		);
	}

	/**
	 * Invalidate SEO-plugin sitemap caches after a publish/update so the new
	 * URL (and its lastmod) appears without waiting for the cache to expire.
	 * Guarded per engine; never fatal.
	 *
	 * @return void
	 */
	public static function invalidate_sitemap_caches(): void {
		try {
			if ( class_exists( 'WPSEO_Sitemaps_Cache' ) && method_exists( 'WPSEO_Sitemaps_Cache', 'clear' ) ) {
				WPSEO_Sitemaps_Cache::clear( array( 'post' ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		try {
			if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
				\RankMath\Sitemap\Cache::invalidate_storage( 'post' );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
