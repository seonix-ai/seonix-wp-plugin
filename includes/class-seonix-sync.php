<?php
/**
 * Site data sync for Seonix.
 *
 * Collects pages, posts, and WooCommerce products from the WordPress site
 * and pushes them to the Seonix backend for internal linking context.
 *
 * Sync only fires when seonix_engine_url is configured. If not set, all push
 * operations are silently skipped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Sync {

	/**
	 * SSRF guard for the configured Seonix engine URL.
	 *
	 * Delegates to Seonix_Auth::is_safe_url() — the single source of truth shared
	 * with Seonix_REST_API::is_safe_url() — so the sync layer never fires a request
	 * at a private or loopback IP (IPv4 OR IPv6), even if an attacker plants a
	 * malicious value in `seonix_engine_url`. Kept as a thin wrapper to preserve
	 * the existing Seonix_Sync::is_safe_url() call sites and contract.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if safe, false otherwise.
	 */
	public static function is_safe_url( $url ) {
		return Seonix_Auth::is_safe_url( $url );
	}

	/**
	 * Stamp outbound Seonix API calls with the running plugin version.
	 *
	 * Hooked on `http_request_args`, which WordPress fires for every wp_remote_*
	 * call made anywhere on the site — including other plugins' — so the URL
	 * prefix guard below is what keeps the header off third-party requests. It
	 * is deliberately a single filter rather than an edit to each of the seven
	 * call sites: any future outbound route is stamped for free.
	 *
	 * The backend reads the header off any authenticated /api/plugin/* call and
	 * records it on the channel. That is what lets the dashboard show which
	 * plugin version a site runs, and flag an available update, without us
	 * having to poll the site.
	 *
	 * @param array  $args HTTP request args.
	 * @param string $url  Request URL.
	 * @return array Args, unchanged for anything that is not a Seonix API call.
	 */
	public static function stamp_plugin_version( $args, $url ) {
		$engine_url = get_option( 'seonix_engine_url', '' );
		if ( empty( $engine_url ) || ! is_string( $url ) ) {
			return $args;
		}

		if ( 0 !== strpos( $url, trailingslashit( $engine_url ) . 'api/plugin/' ) ) {
			return $args;
		}

		// `headers` is documented as an array but WP_Http also accepts a raw
		// string; overwriting that with an array would drop whatever the caller
		// set, so leave those requests alone rather than corrupt them.
		if ( isset( $args['headers'] ) && ! is_array( $args['headers'] ) ) {
			return $args;
		}
		if ( ! isset( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['X-Seonix-Plugin-Version'] = SEONIX_VERSION;

		return $args;
	}

	/**
	 * Collect all published content from the site.
	 *
	 * @return array Array of content items grouped by type.
	 */
	public function collect_all_content() {
		$items = array();

		// Collect pages.
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		foreach ( $pages as $page ) {
			$items[] = $this->format_item( $page, 'page' );
		}

		// Collect posts.
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		foreach ( $posts as $post ) {
			$items[] = $this->format_item( $post, 'post' );
		}

		// Collect WooCommerce products if available.
		if ( class_exists( 'WooCommerce' ) ) {
			$products = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
			foreach ( $products as $product ) {
				$items[] = $this->format_item( $product, 'product' );
			}
		}

		return $items;
	}

	/**
	 * Push full site content sync to Seonix backend.
	 *
	 * Skips silently if seonix_engine_url is not configured.
	 *
	 * @return array|WP_Error|null Response data, error, or null if skipped.
	 */
	public function push_full_sync() {
		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();

		// Always collect and store counts locally, even without engine URL.
		$items = $this->collect_all_content();

		$counts = array(
			'pages'    => count( array_filter( $items, function ( $item ) { return 'page' === $item['content_type']; } ) ),
			'posts'    => count( array_filter( $items, function ( $item ) { return 'post' === $item['content_type']; } ) ),
			'products' => count( array_filter( $items, function ( $item ) { return 'product' === $item['content_type']; } ) ),
		);

		update_option( 'seonix_sync_counts', $counts );
		update_option( 'seonix_sync_pages', $counts['pages'] );
		update_option( 'seonix_sync_posts', $counts['posts'] );
		update_option( 'seonix_sync_products', $counts['products'] );
		update_option( 'seonix_last_synced_at', gmdate( 'c' ) );

		// Skip push if engine URL is not set.
		if ( empty( $engine_url ) ) {
			return null;
		}

		if ( ! self::is_safe_url( $engine_url ) ) {
			return new WP_Error( 'invalid_engine_url', 'Configured engine URL is not allowed.' );
		}

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'API key is not configured.' );
		}

		$response = wp_remote_post(
			trailingslashit( $engine_url ) . 'api/plugin/sync',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( array(
					'items'       => $items,
					// Environment report: which SEO plugin(s) own the site's
					// head meta and whether Seonix renders it itself. Older
					// backends ignore unknown fields, so this is forward-safe.
					'seo_engines' => self::seo_engines_report(),
					'meta_mode'   => Seonix_Meta_Renderer::mode(),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Push a single content event to Seonix backend.
	 *
	 * Skips silently if seonix_engine_url is not configured.
	 *
	 * @param string  $action 'created', 'updated', or 'deleted'.
	 * @param WP_Post $post   The WordPress post object.
	 */
	public function push_content_event( $action, $post ) {
		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();

		// Skip if engine URL is not set.
		if ( empty( $engine_url ) || empty( $api_key ) ) {
			return;
		}

		// SSRF guard — refuse to fire push at private/loopback hosts.
		if ( ! self::is_safe_url( $engine_url ) ) {
			return;
		}

		$content_type = $this->resolve_content_type( $post->post_type );
		if ( ! $content_type ) {
			return;
		}

		$item = $this->format_item( $post, $content_type );

		wp_remote_post(
			trailingslashit( $engine_url ) . 'api/plugin/content-event',
			array(
				'timeout'  => 15,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'     => wp_json_encode( array(
					'action' => $action,
					'item'   => $item,
				) ),
			)
		);
	}

	/**
	 * Push a `seo_meta_updated` event: the site owner edited the SEO
	 * title/description/keyword in their SEO plugin and Seonix should mirror
	 * the change into the article. Fire-and-forget like every content event —
	 * an unreachable or older backend (which 400s unknown actions) costs
	 * nothing.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $effective ['seo_title','meta_description','focus_keyword','engine'] from the bridge.
	 * @return void
	 */
	public function push_seo_meta_event( $post_id, array $effective ) {
		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();

		if ( empty( $engine_url ) || empty( $api_key ) || ! self::is_safe_url( $engine_url ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		wp_remote_post(
			trailingslashit( $engine_url ) . 'api/plugin/content-event',
			array(
				'timeout'  => 15,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'     => wp_json_encode( array(
					'action'   => 'seo_meta_updated',
					'item'     => $this->format_item( $post, $this->resolve_content_type( $post->post_type ) ?: 'post' ),
					'seo_meta' => array(
						'ce_article_id'    => (string) get_post_meta( $post_id, '_ce_article_id', true ),
						'seo_title'        => (string) $effective['seo_title'],
						'meta_description' => (string) $effective['meta_description'],
						'focus_keyword'    => (string) $effective['focus_keyword'],
						'source_engine'    => null !== $effective['engine'] ? (string) $effective['engine'] : 'none',
					),
				) ),
			)
		);
	}

	/**
	 * Active SEO engines + versions for the backend environment report.
	 *
	 * @return array<int,array{key:string,version:string,primary:bool}>
	 */
	public static function seo_engines_report() {
		$report = array();
		foreach ( Seonix_SEO_Engine::detect_all() as $i => $engine ) {
			$report[] = array(
				'key'     => $engine,
				'version' => Seonix_SEO_Engine::version( $engine ),
				'primary' => 0 === $i,
			);
		}
		return $report;
	}

	/**
	 * Hook callback for save_post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ) {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only track published content.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$content_type = $this->resolve_content_type( $post->post_type );
		if ( ! $content_type ) {
			return;
		}

		$action = $update ? 'updated' : 'created';
		$this->push_content_event( $action, $post );

		// Ping IndexNow (Bing/Yandex) so they re-crawl the changed URL within
		// minutes. Independent of the Seonix backend connection — it's a pure
		// SEO benefit gated only by the auto-IndexNow option. Non-blocking.
		Seonix_IndexNow::submit_post( $post );
	}

	/**
	 * Hook callback for before_delete_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$content_type = $this->resolve_content_type( $post->post_type );
		if ( ! $content_type ) {
			return;
		}

		$this->push_content_event( 'deleted', $post );
	}

	/**
	 * Format a WP_Post into a sync item.
	 *
	 * @param WP_Post $post         The post object.
	 * @param string  $content_type One of 'page', 'post', 'product'.
	 * @return array
	 */
	private function format_item( $post, $content_type ) {
		return array(
			'wp_id'        => $post->ID,
			'content_type' => $content_type,
			'title'        => $post->post_title,
			'slug'         => $post->post_name,
			'url'          => get_permalink( $post->ID ),
			'status'       => $post->post_status,
			'updated_at'   => self::to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * Convert a MySQL GMT datetime ("2026-04-15 10:30:00") into RFC3339
	 * ("2026-04-15T10:30:00+00:00") for the Go backend, which decodes
	 * updated_at as *time.Time and rejects any other format with BAD_REQUEST.
	 *
	 * Returns null for zero-dates ("0000-00-00 00:00:00") and unparseable
	 * input so the backend stores SQL NULL rather than choking on the whole
	 * payload.
	 */
	private static function to_rfc3339( $mysql_gmt ) {
		if ( empty( $mysql_gmt ) || 0 === strpos( (string) $mysql_gmt, '0000-00-00' ) ) {
			return null;
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d\TH:i:sP', $ts );
	}

	/**
	 * Map WordPress post type to our content type.
	 *
	 * @param string $post_type WP post type.
	 * @return string|false Content type or false if not tracked.
	 */
	private function resolve_content_type( $post_type ) {
		$map = array(
			'page'    => 'page',
			'post'    => 'post',
			'product' => 'product',
		);
		return isset( $map[ $post_type ] ) ? $map[ $post_type ] : false;
	}
}
