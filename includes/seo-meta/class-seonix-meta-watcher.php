<?php
/**
 * Reverse SEO-meta sync: site-owner edits in Yoast / Rank Math / AIOSEO /
 * SEOPress / TSF flow back into Seonix.
 *
 * Strategy: effective-value diff, not per-key spying. On every save of a
 * Seonix-managed post (and on direct SEO-meta updates from quick-edit /
 * sidebar AJAX paths) the post ID is queued; on shutdown each queued post's
 * effective values are read through the bridge and hashed against the stored
 * fingerprint. A mismatch that Seonix itself did not write means the site
 * owner edited the SEO fields in their SEO plugin — the canonical `_seonix_*`
 * copy is refreshed and a `seo_meta_updated` content-event is pushed to the
 * backend (fire-and-forget, same channel as content sync).
 *
 * Loop safety, in order:
 *   1. Seonix_Meta_Bridge::$writing — our own writes never enqueue.
 *   2. Fingerprint hash — unchanged values never notify.
 *   3. A per-post transient debounces bursts (autosave storms, bulk edits).
 *
 * Scope: v1 tracks ONLY Seonix-managed posts (those carrying _ce_article_id);
 * other content is none of our business.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Meta_Watcher {

	/**
	 * Engine meta keys that mark "the SEO fields possibly changed" when
	 * written outside a full post save (Gutenberg sidebar AJAX, quick edit,
	 * or another plugin's own save route).
	 */
	const WATCHED_META_KEYS = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'_seopress_titles_title',
		'_seopress_titles_desc',
		'_seopress_analysis_target_kw',
		'_genesis_title',
		'_genesis_description',
		// AIOSEO's one-way postmeta mirror: AIOSEO refreshes it after saving
		// its own table, which makes it a usable change signal even though the
		// values themselves are read through the model.
		'_aioseo_title',
		'_aioseo_description',
	);

	/**
	 * Post IDs queued for a shutdown check, deduped.
	 *
	 * @var array<int,bool>
	 */
	private static $queued = array();

	/**
	 * @var Seonix_Sync
	 */
	private $sync;

	public function __construct( Seonix_Sync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * Register hooks. Called once from seonix_init().
	 *
	 * @return void
	 */
	public function register(): void {
		// Late priority: run after the SEO plugin's own save_post handlers have
		// persisted their fields, so the shutdown diff reads final values.
		add_action( 'save_post', array( $this, 'on_save_post' ), 99, 2 );
		add_action( 'rest_after_insert_post', array( $this, 'on_rest_insert' ), 99 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );

		// Backfill: an SEO plugin activated AFTER Seonix has been managing meta
		// standalone gets the canonical values copied in, once, in background.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		add_action( 'seonix_meta_backfill', array( $this, 'run_backfill' ) );
	}

	// ─── Change detection ─────────────────────────────────────────

	/**
	 * save_post: queue Seonix-managed posts for the shutdown diff.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_save_post( $post_id, $post = null ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$this->queue( (int) $post_id );
	}

	/**
	 * rest_after_insert_post: Gutenberg/REST saves.
	 *
	 * @param WP_Post $post Inserted/updated post.
	 * @return void
	 */
	public function on_rest_insert( $post ): void {
		if ( $post instanceof WP_Post ) {
			$this->queue( (int) $post->ID );
		}
	}

	/**
	 * updated_post_meta / added_post_meta: catch SEO-field writes that happen
	 * outside a post save (sidebar AJAX, quick edit, WP-CLI).
	 *
	 * @param int    $meta_id  Meta row ID (unused).
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key that changed.
	 * @return void
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ): void {
		if ( ! in_array( (string) $meta_key, self::WATCHED_META_KEYS, true ) ) {
			return;
		}
		$this->queue( (int) $post_id );
	}

	/**
	 * Queue a post for the shutdown diff (managed posts only, our own writes
	 * excluded). Registers the shutdown flusher on first use.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function queue( int $post_id ): void {
		if ( $post_id <= 0 || Seonix_Meta_Bridge::$writing ) {
			return;
		}
		if ( isset( self::$queued[ $post_id ] ) ) {
			return;
		}
		// Only Seonix-managed posts are synced back (v1 scope).
		$ce_article_id = (string) get_post_meta( $post_id, '_ce_article_id', true );
		if ( '' === $ce_article_id ) {
			return;
		}
		if ( empty( self::$queued ) ) {
			add_action( 'shutdown', array( $this, 'flush_queue' ), 5 );
		}
		self::$queued[ $post_id ] = true;
	}

	/**
	 * shutdown: diff every queued post and push events for real changes.
	 *
	 * @return void
	 */
	public function flush_queue(): void {
		$queued       = array_keys( self::$queued );
		self::$queued = array();
		foreach ( $queued as $post_id ) {
			$this->check_post( (int) $post_id );
		}
	}

	/**
	 * Diff one post's effective SEO fields against the stored fingerprint;
	 * on a real external change, refresh the canonical copy and notify the
	 * backend.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function check_post( int $post_id ): void {
		// Debounce bursts: at most one check per post per 15 seconds.
		$debounce_key = 'seonix_meta_diff_' . $post_id;
		if ( false !== get_transient( $debounce_key ) ) {
			return;
		}
		set_transient( $debounce_key, 1, 15 );

		$effective = Seonix_Meta_Bridge::read_effective( $post_id );
		$hash      = Seonix_Meta_Bridge::hash_triple(
			$effective['seo_title'],
			$effective['meta_description'],
			$effective['focus_keyword']
		);

		$fingerprint = Seonix_Meta_Bridge::fingerprint( $post_id );
		if ( null !== $fingerprint && $fingerprint['h'] === $hash ) {
			return; // Nothing actually changed.
		}
		// No fingerprint at all + no values → nothing to sync (e.g. a managed
		// post from before this feature with no SEO meta anywhere).
		if ( null === $fingerprint
			&& '' === $effective['seo_title']
			&& '' === $effective['meta_description']
			&& '' === $effective['focus_keyword'] ) {
			return;
		}

		$engine = null !== $effective['engine'] ? $effective['engine'] : 'none';

		// Refresh the canonical copy. write() re-fingerprints with the wp:*
		// source, so repeated saves of the same values stay quiet, and our own
		// write here cannot re-enqueue (bridge guard flag).
		Seonix_Meta_Bridge::write(
			$post_id,
			array(
				'seo_title'        => $effective['seo_title'],
				'meta_description' => $effective['meta_description'],
				'focus_keyword'    => $effective['focus_keyword'],
			),
			'wp:' . $engine
		);

		$this->sync->push_seo_meta_event( $post_id, $effective );
	}

	// ─── Backfill on SEO-plugin activation ────────────────────────

	/**
	 * activated_plugin: when a known SEO plugin is switched on after Seonix
	 * has been managing meta, schedule a one-time background backfill that
	 * copies the canonical `_seonix_*` values into the new engine's storage.
	 * Scheduled (not inline) so plugin activation never slows down or dies on
	 * a large site.
	 *
	 * @param string $plugin Plugin basename being activated.
	 * @return void
	 */
	public function on_plugin_activated( $plugin ): void {
		$known = array(
			'wordpress-seo/wp-seo.php',
			'wordpress-seo-premium/wp-seo-premium.php',
			'seo-by-rank-math/rank-math.php',
			'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
			'wp-seopress/seopress.php',
			'wp-seopress-pro/seopress-pro.php',
			'autodescription/autodescription.php',
		);
		if ( ! in_array( (string) $plugin, $known, true ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'seonix_meta_backfill' ) ) {
			wp_schedule_single_event( time() + 30, 'seonix_meta_backfill' );
		}
	}

	/**
	 * Cron: copy canonical Seonix meta into the (now) active engines for every
	 * managed post that has any. Bridge writes are idempotent, and its
	 * fingerprint refresh keeps the watcher quiet about our own backfill.
	 *
	 * @return void
	 */
	public function run_backfill(): void {
		$post_ids = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 500, // Managed-article counts are far below this; hard cap keeps the cron bounded.
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// Only way to select by an arbitrary meta key.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array(
					'key'     => '_ce_article_id',
					'compare' => 'EXISTS',
				),
			),
		) );

		foreach ( $post_ids as $post_id ) {
			$own = Seonix_Meta_Bridge::read_own( (int) $post_id );
			if ( '' === $own['seo_title'] && '' === $own['meta_description'] && '' === $own['focus_keyword'] ) {
				continue;
			}
			$fields = array();
			foreach ( $own as $field => $value ) {
				if ( '' !== $value ) {
					$fields[ $field ] = $value;
				}
			}
			Seonix_Meta_Bridge::write( (int) $post_id, $fields, 'seonix' );
		}
	}
}
