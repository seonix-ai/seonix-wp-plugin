<?php
/**
 * REST API endpoints for Seonix.
 *
 * Namespace: seonix/v1
 *
 * Endpoints:
 *   POST   /publish         - Create/publish a WordPress post (legacy alias)
 *   GET    /posts           - List posts with pagination
 *   POST   /posts           - Create/update a WordPress post
 *   GET    /posts/{id}      - Get a single post
 *   DELETE /posts/{id}      - Move a post to trash
 *   GET    /media           - List media (images) with pagination
 *   POST   /media           - Upload image (URL or file upload)
 *   GET    /verify          - Health check / connection verification
 *   GET    /categories      - List all categories
 *   POST   /setup-indexnow  - Set up IndexNow key file
 *   GET    /indexnow-status - Check IndexNow configuration
 *   GET    /llms-status     - Check LLMs.txt generation status
 *   POST   /connect/exchange - One-click connect handoff (since 2.5.0)
 *   POST   /tasks           - Task sink from the backend (since 2.5.0)
 *
 * SEO Fix routes live in class-seonix-seo-fix-controller.php (/seo-fix/*).
 * All routes are mirrored under the legacy content-engine/v1 namespace.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_REST_API {

	/** @var Seonix_Sync */
	private $sync;

	/** @var Seonix_Tasks */
	private $tasks;

	private const NAMESPACE = 'seonix/v1';

	/**
	 * Per-user cap for POST /score.
	 *
	 * The panel debounces 2s after typing stops, so an author editing flat out
	 * cannot legitimately exceed ~30/min; this sits at that ceiling so real
	 * work never trips it. It also keeps one account from eating the backend's
	 * 120/min per-IP budget, which the whole site shares.
	 */
	private const SCORE_MAX_PER_MINUTE = 30;

	/**
	 * Post meta holding the last /score result and the hash of the inputs that
	 * produced it. A cache hit skips the backend round-trip entirely.
	 */
	private const SCORE_CACHE_META = '_seonix_score_cache';

	/**
	 * Legacy REST namespace from the previous "Content Engine Connector" plugin.
	 * All routes are mirrored under this namespace so existing clients continue to work.
	 * Will be removed in a future major version.
	 */
	private const LEGACY_NAMESPACE = 'content-engine/v1';

	/**
	 * Allowed MIME types for featured image detection.
	 */
	private const ALLOWED_IMAGE_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Extension-to-MIME mapping for fallback detection.
	 */
	private const EXT_MIME_MAP = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	public function __construct( Seonix_Sync $sync = null, Seonix_Tasks $tasks = null ) {
		$this->sync  = $sync;
		$this->tasks = $tasks ?? new Seonix_Tasks();
	}

	/**
	 * Register all REST routes under both the new (seonix/v1) and the legacy
	 * (content-engine/v1) namespaces so existing integrations continue to work.
	 */
	public function register_routes() {
		foreach ( array( self::NAMESPACE, self::LEGACY_NAMESPACE ) as $ns ) {
			// Publish (create/update) a post — legacy alias.
			register_rest_route( $ns, '/publish', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_publish' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// Posts: list (GET) and create/update (POST).
			register_rest_route( $ns, '/posts', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_list_posts' ),
					'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_publish' ),
					'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
				),
			) );

			// Single post: GET and DELETE.
			register_rest_route( $ns, '/posts/(?P<id>\d+)', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get_post' ),
					'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_delete_post' ),
					'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
				),
			) );

			// Media library: list (GET) and upload (POST).
			register_rest_route( $ns, '/media', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_list_media' ),
					'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_upload_media' ),
					'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
				),
			) );

			// Verify / health check.
			register_rest_route( $ns, '/verify', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_verify' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// List categories.
			register_rest_route( $ns, '/categories', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_categories' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// IndexNow setup.
			register_rest_route( $ns, '/setup-indexnow', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_setup_indexnow' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// IndexNow status.
			register_rest_route( $ns, '/indexnow-status', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_indexnow_status' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// LLMs.txt status.
			register_rest_route( $ns, '/llms-status', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_llms_status' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// One-click connect handoff sink. Authenticated SOLELY by the
			// unguessable one-time nonce minted in wp-admin (the transient is
			// the proof a real site admin clicked "Connect"). No API key exists
			// yet at this point, so the permission callback is open and the
			// handler itself validates + one-time-consumes the nonce.
			register_rest_route( $ns, '/connect/exchange', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_connect_exchange' ),
				'permission_callback' => array( $this, 'connect_exchange_permission' ),
			) );

			// Task sink. The Seonix backend POSTs the canonical TaskView here
			// after every scan; the plugin replaces its local copy. Bearer auth
			// via the plugin's API key (same as every other write route).
			register_rest_route( $ns, '/tasks', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_tasks' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );
		}

		// Live content scoring for the editor panel.
		//
		// This route runs the OTHER WAY ROUND from every route above: those are
		// called BY the Seonix backend and authenticate with the plugin's API
		// key. This one is called by the logged-in author's browser and
		// authenticates as WordPress normally does (cookie + REST nonce +
		// capability). It then makes the outbound engine call server-side, so
		// the sx_ key never reaches the browser.
		//
		// Consequently it is NOT mirrored into the legacy content-engine/v1
		// namespace: it has no external clients to keep working, and mirroring
		// it would only widen the attack surface.
		register_rest_route( self::NAMESPACE, '/score', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_score' ),
			'permission_callback' => array( $this, 'score_permission' ),
		) );
	}

	// ─── Live content scoring ─────────────────────────────────────

	/**
	 * Permission for POST /score — the caller must be able to edit the post
	 * they are asking us to score.
	 *
	 * Cookie-authenticated REST requests only resolve to a logged-in user when
	 * a valid X-WP-Nonce accompanies them (WordPress's own rest_cookie_check_errors),
	 * so a capability check here also covers CSRF: without the nonce the request
	 * is anonymous and fails this check.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public function score_permission( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id );
		}
		// No post id yet (brand-new draft that has never been saved): fall back
		// to the generic authoring capability.
		return current_user_can( 'edit_posts' );
	}

	/**
	 * POST /score — score the content currently in the editor.
	 *
	 * Body: { post_id?, html_content, title?, focus_keyphrase?, meta_description?, slug? }
	 * Returns the engine payload: { seo_score, readability_score, seo_checks[],
	 * readability_checks[], summary }.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_score( WP_REST_Request $request ) {
		// Every other route here rate-limits on check_rate_limit(), which keys
		// off the Authorization / X-Seonix-Key header. This route is called by a
		// browser with a cookie and no such header, so that helper would hash
		// the empty string and drop every author on the site into ONE bucket.
		// Key on the user instead.
		$limited = $this->check_user_rate_limit( 'score', self::SCORE_MAX_PER_MINUTE );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$post_id = (int) $request->get_param( 'post_id' );

		$keyphrase = $request->get_param( 'focus_keyphrase' );
		$keyphrase = is_string( $keyphrase ) ? trim( $keyphrase ) : '';
		// The editor sends the keyphrase it has in its own store (Yoast/Rank Math
		// keep unsaved edits there). When it has none to send, fall back to the
		// last SAVED value via the meta bridge, which already reads whichever SEO
		// plugin the site runs. Fallback only — a keyphrase the author just typed
		// must win over the stale saved one.
		if ( '' === $keyphrase && $post_id > 0 && class_exists( 'Seonix_Meta_Bridge' ) ) {
			$keyphrase = (string) get_post_meta( $post_id, Seonix_Meta_Bridge::META_FOCUS_KW, true );
		}

		// Same fallback for the meta description. This one matters even more than
		// it looks: metaDescription carries weight 10 in the engine, so scoring
		// without it would report a red "no meta description" on a post that has
		// one saved — the panel would be lying about the author's own work.
		$description = $request->get_param( 'meta_description' );
		$description = is_string( $description ) ? trim( $description ) : '';
		if ( '' === $description && $post_id > 0 && class_exists( 'Seonix_Meta_Bridge' ) ) {
			$description = (string) get_post_meta( $post_id, Seonix_Meta_Bridge::META_DESC, true );
		}

		// The slug the editor holds is empty until a draft is first saved, while
		// the permalink WordPress shows is derived from the title. Mirror that so
		// slug checks judge the URL the post will actually get.
		$slug = $request->get_param( 'slug' );
		$slug = is_string( $slug ) ? trim( $slug ) : '';
		if ( '' === $slug ) {
			$post = $post_id > 0 ? get_post( $post_id ) : null;
			if ( $post instanceof WP_Post && '' !== $post->post_name ) {
				$slug = $post->post_name;
			} else {
				$title = $request->get_param( 'title' );
				$slug  = is_string( $title ) ? sanitize_title( $title ) : '';
			}
		}

		$score_input = array(
			'html_content'     => $request->get_param( 'html_content' ),
			'focus_keyphrase'  => $keyphrase,
			'title'            => $request->get_param( 'title' ),
			'meta_description' => $description,
			'slug'             => $slug,
			/*
			 * What is being scored — a post or a page.
			 *
			 * Read here rather than taken from the editor: the post type is
			 * the server's own fact, and the engine's thresholds hang off it
			 * (a 500-word service page is a good page; the same length in an
			 * article is thin). An unsaved draft has no post yet, so it
			 * scores as a post — the stricter default.
			 */
			'content_type'     => $post_id > 0 ? (string) get_post_type( $post_id ) : '',
			/**
			 * Filters the language the content is scored against.
			 *
			 * Defaults to the site locale ("de_DE"; the engine normalizes it
			 * to "de"). Multilingual sites that vary language per post can
			 * return the post's own locale here.
			 *
			 * @param string $locale  Locale to score against.
			 * @param int    $post_id Post being scored, 0 for an unsaved draft.
			 */
			'language'         => apply_filters( 'seonix_score_language', get_locale(), $post_id ),
		);

		// Cache the score against a hash of exactly what it depends on, so a post
		// re-opened without an edit doesn't round-trip to the backend every time
		// the editor mounts. The engine result is a pure function of these inputs;
		// change the text (or the keyphrase, meta, slug, type, language) and the
		// hash changes, so the cache invalidates itself — no stale score ever
		// survives an edit. SEONIX_VERSION is folded in so a plugin update, which
		// may change the scoring contract, busts every cache. Only saved posts are
		// cached (a draft has no row to attach meta to, and is being edited anyway).
		$cache_key = ( $post_id > 0 ) ? md5( SEONIX_VERSION . '|' . (string) wp_json_encode( $score_input ) ) : '';
		$result    = null;
		if ( '' !== $cache_key ) {
			$cached = get_post_meta( $post_id, self::SCORE_CACHE_META, true );
			if ( is_array( $cached ) && isset( $cached['hash'], $cached['payload'] ) && hash_equals( (string) $cached['hash'], $cache_key ) ) {
				$result = $cached['payload'];
			}
		}

		if ( null === $result ) {
			$result = Seonix_Content_Score::score( $score_input );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( '' !== $cache_key ) {
				update_post_meta( $post_id, self::SCORE_CACHE_META, array( 'hash' => $cache_key, 'payload' => $result ) );
			}
		}

		// Park the numbers for the toolbar. NOT written to post meta here: this
		// scored the text currently in the editor, which may never be saved.
		// Seonix_Metabox::persist_scores_on_save promotes them to meta when (and
		// only when) the post is actually saved, so the toolbar always reports
		// the revision a visitor can see.
		Seonix_Metabox::stash_scores( $post_id, $result );

		return new WP_REST_Response( $result, 200 );
	}

	// ─── Publish ──────────────────────────────────────────────────

	/**
	 * POST /publish, POST /posts - Create or update a WordPress post.
	 *
	 * Accepts:
	 *   title, content, status, slug, excerpt, categories[], tags[],
	 *   focus_keyword, meta_description, featured_image_url,
	 *   key_takeaways[], key_takeaways_title, ce_article_id,
	 *   wp_post_id (if > 0, updates existing post instead of creating new)
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_publish( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'publish', 30 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$title   = sanitize_text_field( $request->get_param( 'title' ) );
		$content = wp_kses_post( $request->get_param( 'content' ) );

		if ( empty( $title ) ) {
			return new WP_Error(
				'missing_title',
				'Post title is required.',
				array( 'status' => 400 )
			);
		}

		// Key takeaways: structured callout block. Rendered separately above
		// the article body — the Seonix backend forbids the AI from including
		// a takeaways section inside the body, so the block has a single
		// source of truth.
		$key_takeaways       = $this->sanitize_takeaways_items( $request->get_param( 'key_takeaways' ) );
		$key_takeaways_title = sanitize_text_field( (string) $request->get_param( 'key_takeaways_title' ) );

		// Brand accent — only canonical 7-char lowercase hex is accepted.
		// Anything else is dropped silently so a malformed payload never
		// reaches the inline-style attribute on the rendered <aside>.
		$brand_accent = $this->sanitize_brand_accent( $request->get_param( 'accent_color' ) );

		// Build SEO meta_input BEFORE wp_insert_post (critical for the SEO
		// plugin's indexable creation to pick up the values immediately).
		// The bridge fans the fields out to Seonix's canonical `_seonix_*` keys
		// plus every ACTIVE postmeta engine (Yoast / Rank Math / SEOPress /
		// TSF). AIOSEO keeps meta in its own table and needs the post to exist
		// first — completed right after wp_insert_post below.
		$focus_keyword    = sanitize_text_field( $request->get_param( 'focus_keyword' ) );
		$meta_description = sanitize_text_field( $request->get_param( 'meta_description' ) );
		$seo_title        = sanitize_text_field( (string) $request->get_param( 'seo_title' ) );
		$ce_article_id    = sanitize_text_field( $request->get_param( 'ce_article_id' ) );

		$seo_fields = array(
			'seo_title'        => $seo_title,
			'meta_description' => $meta_description,
			'focus_keyword'    => $focus_keyword,
		);
		$meta_input = Seonix_Meta_Bridge::meta_input( $seo_fields );

		if ( ! empty( $ce_article_id ) ) {
			$meta_input['_ce_article_id'] = $ce_article_id;
		}

		// Structured data: the backend generates the article's JSON-LD @graph
		// and sends it as schema_jsonld. Stored in post meta; rendered into
		// <head> by Seonix_Schema (auto mode: only when no SEO plugin owns it).
		// sanitize_jsonld returns null for empty/invalid/oversized payloads, in
		// which case we leave any existing meta untouched.
		$schema_jsonld = Seonix_Schema::sanitize_jsonld( $request->get_param( 'schema_jsonld' ) );
		if ( null !== $schema_jsonld ) {
			$meta_input[ Seonix_Schema::META_KEY ] = $schema_jsonld;
		}

		$meta_input['_ce_published_at'] = gmdate( 'c' );

		// Persist takeaways on the post so themes/AMP/llms.txt can read them
		// directly without re-parsing the rendered HTML. Stored even when the
		// HTML is also embedded above the body — they are the canonical copy.
		if ( ! empty( $key_takeaways ) ) {
			$meta_input['_seonix_key_takeaways']       = wp_json_encode( $key_takeaways );
			$meta_input['_seonix_key_takeaways_title'] = $key_takeaways_title;
		}
		if ( '' !== $brand_accent ) {
			// Stored alongside the takeaways so a theme template that re-renders
			// the callout can read the same accent the API call rendered with.
			$meta_input['_seonix_brand_accent'] = $brand_accent;
		}

		// Determine post status.
		$status = sanitize_text_field( $request->get_param( 'status' ) );
		if ( empty( $status ) || ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$status = 'draft';
		}

		// Determine post author.
		$author_id = (int) get_option( 'seonix_post_author', 0 );
		if ( $author_id <= 0 ) {
			$author_id = null; // WordPress will use current default.
		}

		// Prepare post data.
		// Convert HTML to Gutenberg blocks.
		$content = $this->html_to_blocks( $content );

		// Prepend the key-takeaways callout block above the body. Built as a
		// single wp:html block so themes render it inside `the_content` and
		// pick up the bundled stylesheet automatically. The block lives ABOVE
		// the article body — the AI is instructed not to write a takeaways
		// section inside the body, so the post gets one block, not two.
		if ( ! empty( $key_takeaways ) ) {
			$takeaways_block = $this->build_takeaways_block( $key_takeaways, $key_takeaways_title, $brand_accent );
			if ( '' !== $takeaways_block ) {
				$content = $takeaways_block . "\n\n" . $content;
			}
		}

		$post_data = array(
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'meta_input'   => $meta_input,
		);

		// If wp_post_id is provided, update existing post instead of creating new.
		$wp_post_id = (int) $request->get_param( 'wp_post_id' );
		if ( $wp_post_id > 0 ) {
			$post_data['ID'] = $wp_post_id;
		} elseif ( ! empty( $ce_article_id ) ) {
			// Idempotency guard: a previous publish call may have created the
			// post here but the response never reached the backend (network
			// timeout after wp_insert_post). Without this lookup a retry would
			// create a duplicate post. Match by _ce_article_id meta and turn
			// the create into an update.
			$existing = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// Only way to look up a post by an arbitrary meta key.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_ce_article_id',
						'value'   => $ce_article_id,
						'compare' => '=',
					),
				),
			) );
			if ( ! empty( $existing ) ) {
				$post_data['ID'] = (int) $existing[0];
			}
		}

		if ( $author_id ) {
			$post_data['post_author'] = $author_id;
		}

		$excerpt = $request->get_param( 'excerpt' );
		if ( null !== $excerpt ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $excerpt );
		}

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$post_data['post_name'] = sanitize_title( $slug );
		}

		// Handle categories BEFORE insert to pass as post_category.
		$categories_param = $request->get_param( 'categories' );
		$cat_ids          = array();

		if ( is_array( $categories_param ) && ! empty( $categories_param ) ) {
			foreach ( $categories_param as $cat_name ) {
				$cat_name = sanitize_text_field( $cat_name );
				if ( empty( $cat_name ) ) {
					continue;
				}

				$term = get_term_by( 'name', $cat_name, 'category' );
				if ( $term ) {
					$cat_ids[] = $term->term_id;
				} else {
					$result = wp_insert_term( $cat_name, 'category' );
					if ( ! is_wp_error( $result ) ) {
						$cat_ids[] = $result['term_id'];
					}
				}
			}

			if ( ! empty( $cat_ids ) ) {
				$post_data['post_category'] = $cat_ids;
			}
		}

		// Insert the post.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'post_creation_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Handle tags.
		$tags = $request->get_param( 'tags' );
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$sanitized_tags = array_map( 'sanitize_text_field', $tags );
			wp_set_post_tags( $post_id, $sanitized_tags );
		}

		// Handle featured image.
		$featured_image_url = esc_url_raw( $request->get_param( 'featured_image_url' ) );
		if ( ! empty( $featured_image_url ) ) {
			// Bug fix (2.2.5): backend sends `featured_image_alt` alongside the
			// URL but the plugin used to silently ignore it. Forward it to
			// set_featured_image_from_url so the imported attachment carries the
			// alt text Seonix generated.
			$featured_image_alt = sanitize_text_field( (string) $request->get_param( 'featured_image_alt' ) );
			$this->set_featured_image_from_url( $post_id, $featured_image_url, $featured_image_alt );
		}

		// Sideload inline <img> URLs from the post body into the WP media
		// library and rewrite their src to the local attachment URL. Without
		// this, the body keeps absolute Seonix-side URLs (e.g.
		// https://api.seonix.ai/api/uploads/...) that the front-end browser
		// would 404 on once the article is live. Runs AFTER
		// set_featured_image_from_url so the dedup index (_seonix_source_url
		// meta) already contains the featured image if it also appears
		// inline. Same insert/update flow — wp_insert_post above handles both
		// paths transparently via $post_data['ID'].
		$sideloaded_count = $this->sideload_inline_images_in_post( $post_id );
		if ( $sideloaded_count > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
			error_log( sprintf(
				'Seonix: sideloaded %d inline image(s) for post %d',
				$sideloaded_count,
				$post_id
			) );
		}

		// Rebuild the SEO indexable when a compatible engine is active.
		$this->rebuild_yoast_indexable( $post_id );

		// AIOSEO stores SEO fields in its own table (postmeta is ignored), so
		// its write needs the post ID and runs after the insert. No-op unless
		// AIOSEO is active. Non-empty fields only — publish never clears.
		if ( in_array( Seonix_SEO_Engine::AIOSEO, Seonix_SEO_Engine::detect_all(), true ) ) {
			Seonix_Meta_Bridge::write_aioseo( $post_id, array_filter( array(
				'seo_title'        => Seonix_Meta_Bridge::sanitize_value( $seo_title ),
				'meta_description' => Seonix_Meta_Bridge::sanitize_value( $meta_description ),
				'focus_keyword'    => Seonix_Meta_Bridge::sanitize_value( $focus_keyword ),
			), 'strlen' ) );
		}

		// Surface the fresh URL to the engines' XML sitemaps immediately.
		Seonix_Meta_Bridge::invalidate_sitemap_caches();

		// Mark site as connected on first successful publish (if not already).
		if ( ! Seonix_Auth::is_connected() ) {
			update_option( 'seonix_connected', true );
			update_option( 'seonix_connected_at', gmdate( 'c' ) );
		}

		// Trimmed in 2.2.5: backend reads only `{post_id, post_url}` from this
		// response (see publisher/wordpress.go::publishViaPlugin). Dropped:
		// `success`, `edit_url`, `featured_image_id`, `categories_created`.
		$response = array(
			'post_id'  => $post_id,
			'post_url' => get_permalink( $post_id ),
		);

		return rest_ensure_response( $response );
	}

	// ─── Posts (List / Single) ────────────────────────────────────

	/**
	 * GET /posts - List posts with pagination.
	 *
	 * Query params: page (default 1), per_page (default 20), status (default 'any').
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_list_posts( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$status   = sanitize_text_field( $request->get_param( 'status' ) );
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' );
		if ( empty( $status ) || ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'any';
		}

		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		// Trimmed in 2.2.5: the backend's importer uses a "stop when page returns
		// fewer than perPage rows" heuristic (see ImportService.ImportFromWP), so
		// we no longer compute a global total via wp_count_posts(). Envelope keys
		// `success`, `total`, `page`, `per_page` were also dropped — backend reads
		// only `posts`.
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = $this->format_post_data( $post );
		}

		return rest_ensure_response( array(
			'posts' => $items,
		) );
	}

	/**
	 * GET /posts/{id} - Get a single post.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_post( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				'Post not found.',
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'post'    => $this->format_post_data( $post ),
		) );
	}

	/**
	 * DELETE /posts/{id} - Move a post to trash.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete_post( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'delete_post', 30 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				'Post not found.',
				array( 'status' => 404 )
			);
		}

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			return new WP_Error(
				'trash_failed',
				'Failed to move post to trash.',
				array( 'status' => 500 )
			);
		}

		// Trimmed in 2.2.5: backend's deleteViaPlugin discards the body —
		// return 204 No Content instead of a redundant success envelope.
		return new WP_REST_Response( null, 204 );
	}

	// ─── Media ────────────────────────────────────────────────────

	/**
	 * GET /media - List media library images with pagination.
	 *
	 * Query params: page (default 1), per_page (default 20), search (default '').
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_list_media( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = sanitize_text_field( $request->get_param( 'search' ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$attachments = get_posts( $args );

		// Count total images.
		$count_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		if ( ! empty( $search ) ) {
			$count_args['s'] = $search;
		}
		$total = count( get_posts( $count_args ) );

		$items = array();
		foreach ( $attachments as $attachment ) {
			$metadata  = wp_get_attachment_metadata( $attachment->ID );
			$thumbnail = wp_get_attachment_image_src( $attachment->ID, 'thumbnail' );
			$medium    = wp_get_attachment_image_src( $attachment->ID, 'medium' );
			$full      = wp_get_attachment_image_src( $attachment->ID, 'full' );

			$items[] = array(
				'id'            => $attachment->ID,
				'title'         => $attachment->post_title,
				'filename'      => basename( get_attached_file( $attachment->ID ) ),
				'url'           => $full ? $full[0] : wp_get_attachment_url( $attachment->ID ),
				'thumbnail_url' => $thumbnail ? $thumbnail[0] : null,
				'medium_url'    => $medium ? $medium[0] : null,
				'width'         => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
				'height'        => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
				'mime_type'     => $attachment->post_mime_type,
			);
		}

		return rest_ensure_response( array(
			'success'  => true,
			'media'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		) );
	}

	/**
	 * POST /media - Upload an image to the WordPress media library.
	 *
	 * Supports two modes:
	 *   1. URL mode:  JSON body with { "url": "https://..." }
	 *   2. File mode: multipart/form-data with a "file" field
	 *
	 * Optional parameter "post_id" attaches the media to a post.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_upload_media( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'media', 30 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			$post_id = 0;
		}

		$image_url = $request->get_param( 'url' );
		$files     = $request->get_file_params();

		if ( ! empty( $image_url ) ) {
			// ── URL mode ──────────────────────────────────────────
			$image_url = esc_url_raw( $image_url );

			if ( ! $this->is_safe_url( $image_url ) ) {
				return new WP_Error( 'invalid_url', 'URL is not allowed.', array( 'status' => 400 ) );
			}

			$tmp = $this->safe_download_url( $image_url );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error(
					'download_failed',
					'Failed to download image from URL: ' . $tmp->get_error_message(),
					array( 'status' => 400 )
				);
			}

			$mime = $this->detect_mime_type( $tmp, $image_url );

			if ( ! in_array( $mime, self::ALLOWED_IMAGE_MIMES, true ) ) {
				wp_delete_file( $tmp );
				return new WP_Error(
					'invalid_mime_type',
					'The file is not a supported image type. Allowed: JPEG, PNG, GIF, WebP.',
					array( 'status' => 400 )
				);
			}

			$ext_map   = array_flip( self::EXT_MIME_MAP );
			$ext       = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';
			$url_path  = wp_parse_url( $image_url, PHP_URL_PATH );
			$base_name = $url_path ? sanitize_file_name( basename( $url_path ) ) : 'uploaded-image';

			$name_without_ext = preg_replace( '/\.[^.]+$/', '', $base_name );
			if ( empty( $name_without_ext ) ) {
				$name_without_ext = 'uploaded-image';
			}
			$file_name = $name_without_ext . '.' . $ext;

			$file_array = array(
				'name'     => $file_name,
				'tmp_name' => $tmp,
			);

			$media_id = media_handle_sideload( $file_array, $post_id );

			if ( is_wp_error( $media_id ) ) {
				wp_delete_file( $tmp );
				return new WP_Error(
					'upload_failed',
					'Failed to upload image: ' . $media_id->get_error_message(),
					array( 'status' => 500 )
				);
			}
		} elseif ( ! empty( $files['file'] ) ) {
			// ── File upload mode ──────────────────────────────────
			$file = $files['file'];

			if ( ! empty( $file['error'] ) ) {
				return new WP_Error(
					'upload_error',
					'File upload error (code ' . $file['error'] . ').',
					array( 'status' => 400 )
				);
			}

			$mime = $this->detect_mime_type( $file['tmp_name'], $file['name'] );

			if ( ! in_array( $mime, self::ALLOWED_IMAGE_MIMES, true ) ) {
				return new WP_Error(
					'invalid_mime_type',
					'The file is not a supported image type. Allowed: JPEG, PNG, GIF, WebP.',
					array( 'status' => 400 )
				);
			}

			$overrides = array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg' => 'image/jpeg',
					'png'      => 'image/png',
					'gif'      => 'image/gif',
					'webp'     => 'image/webp',
				),
			);

			$uploaded = wp_handle_upload( $file, $overrides );

			if ( isset( $uploaded['error'] ) ) {
				return new WP_Error(
					'upload_failed',
					'Failed to upload image: ' . $uploaded['error'],
					array( 'status' => 500 )
				);
			}

			$attachment = array(
				'post_title'     => sanitize_file_name( pathinfo( $uploaded['file'], PATHINFO_FILENAME ) ),
				'post_mime_type' => $uploaded['type'],
				'post_status'    => 'inherit',
			);

			$media_id = wp_insert_attachment( $attachment, $uploaded['file'], $post_id );

			if ( is_wp_error( $media_id ) || ! $media_id ) {
				return new WP_Error(
					'attachment_failed',
					'Failed to create attachment.',
					array( 'status' => 500 )
				);
			}

			$metadata = wp_generate_attachment_metadata( $media_id, $uploaded['file'] );
			wp_update_attachment_metadata( $media_id, $metadata );
		} else {
			return new WP_Error(
				'no_image',
				'Provide either a "url" parameter or a "file" upload.',
				array( 'status' => 400 )
			);
		}

		$alt = sanitize_text_field( $request->get_param( 'alt' ) );
		if ( ! empty( $alt ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', $alt );
		}

		// Trimmed in 2.2.5: backend's decode struct doesn't declare `alt` —
		// the field was echoed back for no consumer.
		return rest_ensure_response( array(
			'id'  => $media_id,
			'url' => wp_get_attachment_url( $media_id ),
		) );
	}

	// ─── Verify ───────────────────────────────────────────────────

	/**
	 * GET /verify - Health check and connection verification.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_verify( WP_REST_Request $request ) {
		// Mark as connected on successful verify.
		if ( ! Seonix_Auth::is_connected() ) {
			update_option( 'seonix_connected', true );
			update_option( 'seonix_connected_at', gmdate( 'c' ) );
		}

		// Self-configure from verify metadata (2.3.1+). The backend appends
		// `engine_url`, `project_id`, `project_name` as query params so the
		// plugin always knows where to send outbound sync events and which
		// Seonix project it belongs to. Each successful verify refreshes
		// these — that lets an operator move a site between Seonix projects
		// (or between backends, e.g. dev → prod) without editing options by
		// hand. Older backends that don't pass these params keep working
		// because the params are optional and silently skipped when missing.
		$engine_url = (string) $request->get_param( 'engine_url' );
		if ( '' !== $engine_url && Seonix_Sync::is_safe_url( $engine_url ) ) {
			update_option( 'seonix_engine_url', esc_url_raw( $engine_url ) );
		}

		$project_id = sanitize_text_field( (string) $request->get_param( 'project_id' ) );
		if ( '' !== $project_id ) {
			update_option( 'seonix_project_id', $project_id );
		}

		$project_name = sanitize_text_field( (string) $request->get_param( 'project_name' ) );
		if ( '' !== $project_name ) {
			update_option( 'seonix_project_name', $project_name );
		}

		// Trimmed in 2.2.5: backend read only `{site_name, site_url}` (see
		// channel_handler::Connect), so `success`, `version`, `php_version`,
		// `wp_version` were dropped as unconsumed.
		// 2.6.0: + the SEO environment report (which SEO plugin(s) own head
		// meta, and Seonix's own meta-render mode) so the dashboard can show
		// "synced with Yoast" vs "Seonix renders meta tags". Older backends
		// ignore the extra fields.
		// 2.11.0: `version` is back, and consumed this time — the dashboard
		// shows the installed version on the integrations page and flags an
		// available update. Connect/Verify read it here for an immediate
		// answer; steady-state refresh rides the X-Seonix-Plugin-Version
		// header on outbound calls (Seonix_Sync::stamp_plugin_version).
		return rest_ensure_response( array(
			'site_name'   => get_bloginfo( 'name' ),
			'site_url'    => home_url(),
			'version'     => SEONIX_VERSION,
			'seo_engines' => Seonix_Sync::seo_engines_report(),
			'meta_mode'   => Seonix_Meta_Renderer::mode(),
		) );
	}

	// ─── One-click connect ────────────────────────────────────────

	/**
	 * Permission callback for POST /connect/exchange.
	 *
	 * This route is intentionally unauthenticated-by-design: no API key exists yet
	 * at connect time, so there is nothing to authenticate against. The handler
	 * itself is the gate — it enforces a single-use, SHA-256-keyed one-time nonce
	 * (minted in wp-admin when a real site admin clicks "Connect") plus a per-IP
	 * rate limit. We use an explicit named callback rather than '__return_true' so
	 * the intent is documented in code and WP.org's Plugin Check doesn't flag a
	 * bare always-allow callback.
	 *
	 * As a cheap early-out we require the `nonce` param to be a present non-empty
	 * string; the handler re-validates and one-time-consumes it.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool Always grants access at this layer; the handler enforces the nonce.
	 */
	public function connect_exchange_permission( WP_REST_Request $request ) {
		$nonce = $request->get_param( 'nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}
		return true;
	}

	/**
	 * POST /connect/exchange — finish the one-click connect handshake.
	 *
	 * Flow: the site admin clicks "Connect to Seonix" in wp-admin, which mints a
	 * one-time nonce (stored hashed in a 15-minute transient) and hands off to
	 * app.seonix.ai/connect. The Seonix backend then calls THIS route with the
	 * nonce (in the JSON body, never the query string) plus the engine URL and
	 * project metadata. We validate + one-time-consume the nonce, self-configure
	 * (engine URL + project), mark the site connected, and return the plugin's
	 * API key so the backend can store it for outbound calls.
	 *
	 * Body: { nonce, engine_url, project_id, project_name }
	 * 200:  { api_key, site_name, site_url }
	 * 403:  nonce missing/expired.   400: engine_url failed the SSRF guard.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_connect_exchange( WP_REST_Request $request ) {
		// Per-IP rate limit. This route is unauthenticated (the one-time nonce is
		// the real gate), so it has no Bearer token — check_rate_limit would key
		// every caller on the same empty Authorization header, degenerating into
		// ONE global bucket that any visitor could exhaust to lock everyone out.
		// Key on REMOTE_ADDR instead so a single noisy/hostile IP only slows
		// itself. (Behind a CDN REMOTE_ADDR may be the edge IP — acceptable; the
		// nonce, not this limiter, is what actually authorizes the exchange.)
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rl_key = 'seonix_rl_cx_' . md5( $ip );
		$hits   = (int) get_transient( $rl_key );
		if ( $hits >= 10 ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}
		set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$nonce = isset( $params['nonce'] ) && is_string( $params['nonce'] ) ? $params['nonce'] : '';
		if ( '' === $nonce ) {
			return new WP_REST_Response( array( 'error' => 'missing_nonce' ), 403 );
		}

		// The transient key is the SHA-256 of the nonce, so the raw secret is
		// never persisted on disk. Consume it atomically (one-time use) before
		// doing anything else: only the single caller whose DELETE actually
		// removed the row/cache entry wins, so two concurrent replays of the same
		// nonce cannot both succeed. Missing/expired/already-consumed → 403.
		if ( ! $this->consume_connect_nonce( $nonce ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_nonce' ), 403 );
		}

		// Self-configure the engine URL behind the SSRF guard. A hostile or
		// misconfigured engine_url must never become a stored outbound target.
		$engine_url = isset( $params['engine_url'] ) && is_string( $params['engine_url'] ) ? $params['engine_url'] : '';
		if ( '' === $engine_url || ! Seonix_Sync::is_safe_url( $engine_url ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_engine_url' ), 400 );
		}

		update_option( 'seonix_engine_url', esc_url_raw( $engine_url ) );
		update_option( 'seonix_connected', true );
		update_option( 'seonix_connected_at', gmdate( 'c' ) );

		$project_id = isset( $params['project_id'] ) ? sanitize_text_field( (string) $params['project_id'] ) : '';
		if ( '' !== $project_id ) {
			update_option( 'seonix_project_id', $project_id );
		}
		$project_name = isset( $params['project_name'] ) ? sanitize_text_field( (string) $params['project_name'] ) : '';
		if ( '' !== $project_name ) {
			update_option( 'seonix_project_name', $project_name );
		}

		return new WP_REST_Response( array(
			'api_key'   => Seonix_Auth::get_key(),
			'site_name' => get_bloginfo( 'name' ),
			'site_url'  => home_url(),
		), 200 );
	}

	/**
	 * Atomically consume the one-time connect nonce.
	 *
	 * A plain get_transient()-then-delete_transient() is a check-then-act race:
	 * two concurrent /connect/exchange calls carrying the same nonce could both
	 * observe it present and both proceed. To make consumption exactly-once we do
	 * a single deleting operation and gate on whether THIS call is the one that
	 * actually removed the entry.
	 *
	 * Storage depends on the object cache (this is also how the companion was set,
	 * via set_transient() in Seonix_Admin::build_connect_url()):
	 *   - No external object cache (the common shared-host case): transients live
	 *     in the options table as `_transient_<key>` (+ a `_transient_timeout_<key>`
	 *     companion). We issue one atomic DELETE on the value row and only succeed
	 *     if it affected a row; then we best-effort delete the timeout companion.
	 *   - External object cache present: transients live in the cache, NOT the
	 *     options table, so the options DELETE would never match. Fall back to
	 *     get + delete, but still gate on get returning a live value so an
	 *     already-expired/consumed nonce is rejected.
	 *
	 * @param string $nonce The raw one-time nonce from the request body.
	 * @return bool True if this call consumed a live nonce; false if it was
	 *              missing, expired, or already consumed by a concurrent caller.
	 */
	private function consume_connect_nonce( $nonce ) {
		$transient_key = 'seonix_connect_' . hash( 'sha256', $nonce );

		// External object cache: transients are stored in the cache, not options.
		// We can't do the atomic options DELETE, so gate on get + delete instead.
		if ( wp_using_ext_object_cache() ) {
			if ( false === get_transient( $transient_key ) ) {
				return false;
			}
			delete_transient( $transient_key );
			return true;
		}

		global $wpdb;

		$value_option = '_transient_' . $transient_key;
		// Single atomic DELETE: only the caller whose query removes the row wins.
		// $wpdb->query returns the number of affected rows (0 if already gone).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time atomic nonce-gate delete; prepared; caching would defeat the gate.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				$value_option
			)
		);

		if ( ! $deleted ) {
			return false;
		}

		// Best-effort cleanup of the timeout companion row. Not load-bearing for
		// correctness (the value row is the one-time gate); also clear any cached
		// copy so a persistent options cache doesn't keep serving the stale value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- best-effort companion-row cleanup; prepared; cache cleared right after.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				'_transient_timeout_' . $transient_key
			)
		);
		wp_cache_delete( $value_option, 'options' );
		wp_cache_delete( '_transient_timeout_' . $transient_key, 'options' );

		return true;
	}

	// ─── Tasks ────────────────────────────────────────────────────

	/**
	 * POST /tasks — receive the canonical TaskView pushed after a Seonix scan
	 * and replace the plugin's local copy.
	 *
	 * The body is the canonical TaskView (see the Seonix backend's
	 * docs/TASKS_CONTRACT.md). We hand it to Seonix_Tasks::upsert_view, which
	 * sanitizes every field, clamps the vocab enums, truncate-inserts the rows,
	 * and stores the summary + synced_at. Returns { success: true }.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_tasks( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'tasks', 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$view = $request->get_json_params();
		if ( ! is_array( $view ) ) {
			return new WP_Error( 'invalid_payload', 'Request body must be a JSON TaskView object.', array( 'status' => 400 ) );
		}

		$result = $this->tasks->upsert_view( $view );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	// ─── Categories ───────────────────────────────────────────────

	/**
	 * GET /categories - List all categories.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_categories( WP_REST_Request $request ) {
		$terms = get_terms( array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			);
		}

		return rest_ensure_response( array(
			'success'    => true,
			'categories' => $categories,
		) );
	}

	// ─── IndexNow ─────────────────────────────────────────────────

	/**
	 * POST /setup-indexnow - Generate an IndexNow key and write the verification file.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_setup_indexnow( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'indexnow', 5 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		// Generate a new key or use existing.
		$key = get_option( 'seonix_indexnow_key', '' );
		if ( empty( $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			// autoload=false: only read by setup-indexnow flow.
			update_option( 'seonix_indexnow_key', $key, false );
		}

		// Resolve the writable target inside the WP uploads directory. The
		// IndexNow spec allows a non-root key file as long as we submit URLs
		// with the keyLocation parameter pointing at it, so this works without
		// touching ABSPATH.
		$location = $this->resolve_indexnow_location( $key );
		if ( $location instanceof WP_Error ) {
			return $location;
		}

		// Initialise WP_Filesystem on demand (same pattern as Yoast SEO's
		// WordPress_File_System_Adapter).
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'fs_unavailable',
				'WordPress filesystem is unavailable on this host. Ask your hosting provider to enable direct filesystem access for wp-content/uploads.',
				array( 'status' => 500 )
			);
		}
		global $wp_filesystem;

		// Ensure wp-content/uploads/seonix/ exists. wp_mkdir_p() recurses and
		// returns true on success or if the directory already exists.
		if ( ! $wp_filesystem->is_dir( $location['dir'] ) && ! wp_mkdir_p( $location['dir'] ) ) {
			return new WP_Error(
				'mkdir_failed',
				'Could not create uploads/seonix/ directory.',
				array( 'status' => 500 )
			);
		}

		$written = $wp_filesystem->put_contents( $location['path'], $key, FS_CHMOD_FILE );
		if ( false === $written ) {
			return new WP_Error(
				'write_failed',
				'Failed to write IndexNow key file.',
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'indexnow_key' => $key,
			'file_url'     => $location['url'],
		) );
	}

	/**
	 * GET /indexnow-status - Check if IndexNow is configured.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_indexnow_status( WP_REST_Request $request ) {
		$key = get_option( 'seonix_indexnow_key', '' );

		if ( empty( $key ) ) {
			return rest_ensure_response( array(
				'success'    => true,
				'configured' => false,
			) );
		}

		$location = $this->resolve_indexnow_location( $key );
		if ( $location instanceof WP_Error ) {
			return rest_ensure_response( array(
				'success'      => true,
				'configured'   => true,
				'indexnow_key' => $key,
				'file_exists'  => false,
				'file_url'     => '',
			) );
		}

		return rest_ensure_response( array(
			'success'      => true,
			'configured'   => true,
			'indexnow_key' => $key,
			'file_exists'  => file_exists( $location['path'] ),
			'file_url'     => $location['url'],
		) );
	}

	/**
	 * Resolve the on-disk path and public URL for the IndexNow key file.
	 * Lives under wp-content/uploads/seonix/{key}.txt so we never need to
	 * write to or hard-code ABSPATH. IndexNow accepts any publicly reachable
	 * keyLocation, so the Seonix backend just passes this URL when submitting
	 * URLs to the IndexNow API.
	 *
	 * @return array{dir:string,path:string,url:string}|\WP_Error
	 */
	private function resolve_indexnow_location( string $key ) {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'upload_dir_unavailable',
				'WordPress uploads directory is unavailable: ' . $upload_dir['error'],
				array( 'status' => 500 )
			);
		}
		$dir = trailingslashit( $upload_dir['basedir'] ) . 'seonix';
		return array(
			'dir'  => $dir,
			'path' => trailingslashit( $dir ) . $key . '.txt',
			'url'  => trailingslashit( $upload_dir['baseurl'] ) . 'seonix/' . $key . '.txt',
		);
	}

	// ─── LLMs.txt Status ─────────────────────────────────────────

	/**
	 * GET /llms-status - Check LLMs.txt generation status.
	 *
	 * Trimmed in 2.2.5: the backend only consumes `{success, enabled}` (see
	 * site_health_service::checkLLMsTxt). Extra fields (urls, timestamps,
	 * file-existence flags, wp_count_posts() stats) were dropped to avoid
	 * paying for them on every site-health probe.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_llms_status( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'success' => true,
			'enabled' => true,
		) );
	}

	// ─── Private helpers ──────────────────────────────────────────

	/**
	 * Format a WP_Post into a standardized array for API responses.
	 *
	 * Trimmed in 2.2.5: per-item payload now carries only what the Seonix
	 * import path actually reads (see ImportService.wpPostResponse). Dropped
	 * fields: `url`, `status`, `categories`, `tags`, `updated_at`,
	 * `yoast_title_template`, `blogname`. The two brand-suffix fields moved
	 * to the per-post snapshot endpoint (`/seo-fix/post-snapshot/{id}`), the
	 * only consumer that actually needs them.
	 *
	 * Also drops `get_the_terms()` ×2 per row — categories/tags were the
	 * heavy lookups in a list of 100 posts.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted post data.
	 */
	private function format_post_data( WP_Post $post ) {
		// SEO meta through the bridge: reads the PRIMARY active engine
		// (incl. AIOSEO's table via its model, SEOPress, TSF), falling back to
		// Seonix's own canonical keys — so imports capture existing meta no
		// matter which SEO plugin the site runs.
		$effective = Seonix_Meta_Bridge::read_effective( $post->ID );

		$seo_meta = array(
			'focus_keyword'    => '' !== $effective['focus_keyword'] ? $effective['focus_keyword'] : null,
			'meta_description' => '' !== $effective['meta_description'] ? $effective['meta_description'] : null,
			'seo_title'        => '' !== $effective['seo_title'] ? $effective['seo_title'] : null,
		);

		// Featured image.
		$featured_image_url = get_the_post_thumbnail_url( $post->ID, 'full' );

		return array(
			'wp_id'              => $post->ID,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'excerpt'            => get_the_excerpt( $post ),
			'content'            => $post->post_content,
			'featured_image_url' => $featured_image_url ?: null,
			'seo_meta'           => $seo_meta,
			'published_at'       => $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt
				? gmdate( 'c', strtotime( $post->post_date_gmt ) )
				: null,
		);
	}

	/**
	 * Read the SEO plugin's title template for a given post type.
	 *
	 * The SEO plugin stores per-post-type settings in the `wpseo_titles`
	 * option as a keyed array. For a post type of `post` the template key is
	 * `title-post`, commonly populated with `%%title%% %%sep%% %%sitename%%`.
	 * The class-based accessor `WPSEO_Options::get('title-post')` is the
	 * recommended path, but the option lookup is functionally equivalent and
	 * works the same across releases — no class-loading-order surprises.
	 *
	 * Returns null when no compatible SEO plugin is installed or the template
	 * is missing so the Seonix backend can fall back to suffix length 0
	 * without ambiguity.
	 *
	 * Public since 2.2.5 so the SEO-fix post-snapshot endpoint can read it
	 * without the BrandSuffixSnapshotTest's reflection workaround.
	 *
	 * @param string $post_type Post type slug (e.g. 'post', 'page').
	 * @return string|null Title template, or null when unavailable.
	 */
	public function get_yoast_title_template( $post_type ) {
		$post_type = is_string( $post_type ) ? sanitize_key( $post_type ) : '';
		if ( '' === $post_type ) {
			return null;
		}

		// Preferred path: the SEO plugin's own option accessor when its class
		// is loaded. Avoids any drift if the plugin ever changes its underlying
		// storage layout. We only access Yoast's title templates through their
		// public option API; we never read the underlying `wpseo_titles` array
		// directly. If the Yoast class isn't available we return null and let
		// the caller fall through to its own defaults.
		if ( class_exists( 'WPSEO_Options' ) && method_exists( 'WPSEO_Options', 'get' ) ) {
			$template = WPSEO_Options::get( 'title-' . $post_type );
			if ( is_string( $template ) && '' !== $template ) {
				return wp_strip_all_tags( $template );
			}
		}

		return null;
	}

	/**
	 * Download and attach a featured image from URL.
	 *
	 * Dedup contract: attachments imported by Seonix are tagged with
	 * `_seonix_source_url` meta. On republish, if the source URL matches an
	 * existing attachment, we reuse it instead of sideloading a duplicate.
	 * When the post's previous thumbnail was a Seonix-owned attachment that
	 * is no longer used anywhere else, we delete it to keep the media
	 * library clean. Anything we did not import is left alone.
	 *
	 * Uses robust MIME detection: mime_content_type -> finfo -> URL extension -> magic bytes.
	 *
	 * When `$alt` is non-empty we persist it as `_wp_attachment_image_alt` on
	 * the resolved attachment (both the dedup reuse path and the fresh import
	 * path). Empty alt is treated as "leave whatever's already there" so a
	 * republish without an alt won't blow away a previously set value.
	 *
	 * @param int    $post_id   The post ID to attach the image to.
	 * @param string $image_url The URL to download the image from.
	 * @param string $alt       Optional alt text from `featured_image_alt`.
	 * @return int|null The attachment ID, or null on failure.
	 */
	private function set_featured_image_from_url( $post_id, $image_url, $alt = '' ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! $this->is_safe_url( $image_url ) ) {
			return null;
		}

		$old_thumb_id = (int) get_post_thumbnail_id( $post_id );

		// Reuse existing attachment if we already imported this exact URL.
		$existing_id = $this->find_attachment_by_source_url( $image_url );
		if ( $existing_id ) {
			if ( $old_thumb_id !== $existing_id ) {
				set_post_thumbnail( $post_id, $existing_id );
				$this->maybe_delete_seonix_attachment( $old_thumb_id, array( $post_id, $existing_id ) );
			}
			if ( '' !== $alt ) {
				update_post_meta( $existing_id, '_wp_attachment_image_alt', $alt );
			}
			return $existing_id;
		}

		$tmp = $this->safe_download_url( $image_url );
		if ( is_wp_error( $tmp ) ) {
			return null;
		}

		// Detect MIME type with multiple fallbacks.
		$mime = $this->detect_mime_type( $tmp, $image_url );

		if ( ! in_array( $mime, self::ALLOWED_IMAGE_MIMES, true ) ) {
			wp_delete_file( $tmp );
			return null;
		}

		// Determine file extension from MIME type.
		$ext_map = array_flip( self::EXT_MIME_MAP );
		$ext     = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';

		// Build a clean file name from the URL.
		$url_path  = wp_parse_url( $image_url, PHP_URL_PATH );
		$base_name = $url_path ? sanitize_file_name( basename( $url_path ) ) : 'featured-image';

		// Ensure the file name has the correct extension.
		$name_without_ext = preg_replace( '/\.[^.]+$/', '', $base_name );
		if ( empty( $name_without_ext ) ) {
			$name_without_ext = 'featured-image';
		}
		$file_name = $name_without_ext . '.' . $ext;

		$file_array = array(
			'name'     => $file_name,
			'tmp_name' => $tmp,
		);

		$media_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $media_id ) ) {
			wp_delete_file( $tmp );
			return null;
		}

		// Tag the attachment so future republishes can dedup against this URL.
		update_post_meta( $media_id, '_seonix_source_url', esc_url_raw( $image_url ) );

		// Persist alt text when supplied (bug fix 2.2.5).
		if ( '' !== $alt ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', $alt );
		}

		set_post_thumbnail( $post_id, $media_id );

		// Drop the previous Seonix-owned thumbnail if nothing else references it.
		$this->maybe_delete_seonix_attachment( $old_thumb_id, array( $post_id, $media_id ) );

		return $media_id;
	}

	/**
	 * Sideload every inline <img> URL in a post's content into the WP media
	 * library, then rewrite the post body to reference the local attachment
	 * URLs. This is the inline-image companion to set_featured_image_from_url.
	 *
	 * Why this exists: the Seonix backend ships article bodies whose <img>
	 * tags still point at https://api.seonix.ai/api/uploads/...  (or, before
	 * the engine's absolutise pass landed, /api/uploads/... relative paths).
	 * Both shapes render fine inside the engine but 404 once the article is
	 * live on the WordPress site — the browser resolves /api/uploads against
	 * the WP host, or hits the engine's auth-gated endpoint on the absolute
	 * variant. Sideloading on receipt mirrors what every WordPress media
	 * importer plugin does and is the only way the published article remains
	 * self-contained.
	 *
	 * Pipeline mirrors set_featured_image_from_url:
	 *   is_safe_url -> find_attachment_by_source_url (dedup) ->
	 *   download_url -> detect_mime_type -> media_handle_sideload ->
	 *   _seonix_source_url meta.
	 *
	 * Behaviour:
	 *   - Skips <img> tags whose src already points at home_url() (already
	 *     local — nothing to do).
	 *   - Skips data:/blob: URIs, mailto:, javascript:, etc. (is_safe_url
	 *     fails them).
	 *   - Per-image failures are logged via error_log and the original src
	 *     is left in place; one broken inline image must not abort the
	 *     publish.
	 *   - Cross-image dedup: two <img> tags pointing at the same source URL
	 *     end up referencing the same attachment.
	 *
	 * @param int $post_id The post whose body should be sideloaded.
	 * @return int Number of inline images successfully sideloaded. Zero when
	 *             there's nothing to do (no body, no inline imgs, or every
	 *             img was already local). Failed sideloads do not count.
	 */
	private function sideload_inline_images_in_post( $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return 0;
		}

		// Match every <img ... src="https://..."> in the body. Accept both
		// double and single quotes — wp_kses passes both through in some
		// configurations, and the Gutenberg block markup we emit in
		// html_to_blocks preserves whatever quote style the source used.
		$pattern = '/<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i';
		if ( ! preg_match_all( $pattern, $post->post_content, $matches, PREG_SET_ORDER ) ) {
			return 0;
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$home_host = is_string( $home_host ) ? strtolower( $home_host ) : '';

		// Map: original_src => replacement_src. Single str_replace pass at
		// the end so two identical srcs both get rewritten and we don't
		// pay for re-parsing the (potentially large) Gutenberg-blocked body.
		$replacements = array();
		// Parallel map: replacement_src => attach_id. Drives the post-
		// processing pass that rewrites wp:image blocks into Gutenberg's
		// canonical form (id JSON attr + wp-image-{ID} class) so the
		// block validator stops flagging them as
		// "Block contains unexpected or invalid content".
		$id_by_new_src = array();
		$sideloaded    = 0;

		foreach ( $matches as $m ) {
			$src = ! empty( $m[1] ) ? $m[1] : ( ! empty( $m[2] ) ? $m[2] : '' );
			if ( '' === $src ) {
				continue;
			}
			if ( isset( $replacements[ $src ] ) ) {
				continue; // Already processed this URL once in this pass.
			}

			// Skip http/https-only — data:, blob:, mailto:, fragment-only,
			// and bare relative paths all fail is_safe_url and we'd just
			// log spam for each. The Go backend's absolutise pass should
			// have removed every /api/uploads/ relative path; anything
			// relative at this point is intentionally local-to-the-page
			// (e.g. a hash anchor or theme asset) and must not be touched.
			if ( ! preg_match( '#^https?://#i', $src ) ) {
				continue;
			}

			// Already pointing at the publishing site — nothing to sideload.
			$src_host = wp_parse_url( $src, PHP_URL_HOST );
			if ( is_string( $src_host ) && '' !== $home_host && strtolower( $src_host ) === $home_host ) {
				continue;
			}

			if ( ! $this->is_safe_url( $src ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
				error_log( sprintf( 'Seonix: skipping unsafe inline image src %s', self::safe_url_for_log( $src ) ) );
				continue;
			}

			// Dedup: if we've previously sideloaded this exact URL (on this
			// post or any other), reuse the existing attachment.
			$existing_id = $this->find_attachment_by_source_url( $src );
			if ( $existing_id ) {
				$existing_url = wp_get_attachment_url( $existing_id );
				if ( $existing_url ) {
					$replacements[ $src ]            = $existing_url;
					$id_by_new_src[ $existing_url ]  = (int) $existing_id;
					$sideloaded++;
					continue;
				}
			}

			$tmp = $this->safe_download_url( $src, 60 );
			if ( is_wp_error( $tmp ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
				error_log( sprintf(
					'Seonix: download_url failed for inline image %s: %s',
					self::safe_url_for_log( $src ),
					$tmp->get_error_message()
				) );
				continue;
			}

			$mime = $this->detect_mime_type( $tmp, $src );
			if ( ! in_array( $mime, self::ALLOWED_IMAGE_MIMES, true ) ) {
				wp_delete_file( $tmp );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
				error_log( sprintf( 'Seonix: rejected inline image %s (mime %s not allowed)', self::safe_url_for_log( $src ), $mime ) );
				continue;
			}

			$ext_map = array_flip( self::EXT_MIME_MAP );
			$ext     = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'jpg';

			$url_path  = wp_parse_url( $src, PHP_URL_PATH );
			$base_name = $url_path ? sanitize_file_name( basename( $url_path ) ) : 'inline-image';

			$name_without_ext = preg_replace( '/\.[^.]+$/', '', $base_name );
			if ( empty( $name_without_ext ) ) {
				$name_without_ext = 'inline-image';
			}
			$file_name = $name_without_ext . '.' . $ext;

			$file_array = array(
				'name'     => $file_name,
				'tmp_name' => $tmp,
			);

			$attach_id = media_handle_sideload( $file_array, $post_id );
			if ( is_wp_error( $attach_id ) ) {
				wp_delete_file( $tmp );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
				error_log( sprintf(
					'Seonix: media_handle_sideload failed for %s: %s',
					self::safe_url_for_log( $src ),
					$attach_id->get_error_message()
				) );
				continue;
			}

			update_post_meta( $attach_id, '_seonix_source_url', esc_url_raw( $src ) );

			$new_url = wp_get_attachment_url( $attach_id );
			if ( ! $new_url ) {
				// Attachment exists but URL resolution failed — leave the
				// original src in place and drop the half-broken record so
				// dedup on next publish picks it up afresh.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
				error_log( sprintf( 'Seonix: wp_get_attachment_url returned empty for attach %d (src %s)', $attach_id, self::safe_url_for_log( $src ) ) );
				continue;
			}

			$replacements[ $src ]      = $new_url;
			$id_by_new_src[ $new_url ] = (int) $attach_id;
			$sideloaded++;
		}

		// Single str_replace pass over the body. WordPress core stores
		// post_content as a plain string in wp_posts.post_content; we don't
		// re-block-parse here because the rewrite only swaps the src
		// attribute value, which is opaque to Gutenberg block delimiters.
		$updated_content = $post->post_content;
		if ( ! empty( $replacements ) ) {
			$old_srcs        = array_keys( $replacements );
			$new_srcs        = array_values( $replacements );
			$updated_content = str_replace( $old_srcs, $new_srcs, $updated_content );
		}

		// Even when nothing was sideloaded this pass, the post may carry
		// wp:image blocks whose <img> already points at a local attachment
		// (e.g. featured image reused inline, or a previous publish that
		// downloaded everything). Always run the block-normalizer so the
		// block validator never sees an id-less wp:image block.
		$updated_content = $this->normalize_image_blocks( $updated_content, $id_by_new_src );

		if ( $updated_content !== $post->post_content ) {
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $updated_content,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production diagnostics for image sideload failures.
				error_log( sprintf(
					'Seonix: wp_update_post failed after inline sideload for post %d: %s',
					$post_id,
					$result->get_error_message()
				) );
				return 0;
			}
		}

		return $sideloaded;
	}

	/**
	 * Rewrite every wp:image block into Gutenberg's canonical form.
	 *
	 * Why this exists: html_to_blocks() emits "<!-- wp:image -->" with no
	 * JSON attrs and the inline <img> carries the TipTap classes from the
	 * Seonix editor (e.g. max-w-full rounded-lg). Gutenberg's block
	 * validator requires (a) an "id" entry in the block's JSON attrs AND
	 * (b) a "wp-image-{ID}" class on the <img>. Missing either is what
	 * the editor reports as "Block contains unexpected or invalid
	 * content" — it can't reconcile the rendered HTML with what the
	 * image block's save() function would have produced.
	 *
	 * The pass walks every wp:image block in the post body. For each, it
	 * looks up the attachment id via (1) the per-request map populated by
	 * the sideload step, and (2) WP's own attachment_url_to_postid() as a
	 * fallback for images that were already local before this publish.
	 * When an id is found, the block is rebuilt in canonical shape:
	 *
	 *   <!-- wp:image {"id":N,"sizeSlug":"full","linkDestination":"none"} -->
	 *   <figure class="wp-block-image size-full">
	 *   <img src="..." alt="..." class="wp-image-N"/>
	 *   </figure>
	 *   <!-- /wp:image -->
	 *
	 * Blocks that already carry an "id" attr are left alone (somebody
	 * else, possibly the Gutenberg UI on a re-edit, set them and we don't
	 * second-guess). Blocks whose src never resolves to an attachment
	 * (genuine external/hotlinked images) are also kept untouched —
	 * Gutenberg tolerates a missing id when the figure has no
	 * wp-image-{ID} class at all; the validator only screams when the
	 * two halves contradict.
	 *
	 * @param string $content        Post content (Gutenberg block markup).
	 * @param array  $id_by_new_src  Map from final image URL to attach ID,
	 *                               built during sideload. Used for cache
	 *                               misses in attachment_url_to_postid().
	 * @return string Updated post content.
	 */
	private function normalize_image_blocks( $content, $id_by_new_src ) {
		if ( '' === $content || false === strpos( $content, '<!-- wp:image' ) ) {
			return $content;
		}

		$pattern = '/<!--\s*wp:image(\s+\{[^}]*\})?\s*-->\s*(.*?)\s*<!--\s*\/wp:image\s*-->/s';

		$result = preg_replace_callback( $pattern, function ( $match ) use ( $id_by_new_src ) {
			$existing_attrs = isset( $match[1] ) ? trim( $match[1] ) : '';
			$inner_html     = isset( $match[2] ) ? $match[2] : '';

			// Block already declares an id — trust the source and skip.
			if ( '' !== $existing_attrs && false !== strpos( $existing_attrs, '"id"' ) ) {
				return $match[0];
			}

			if ( ! preg_match( '/<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\')[^>]*>/i', $inner_html, $img_match ) ) {
				return $match[0];
			}
			$src = ! empty( $img_match[1] ) ? $img_match[1] : ( ! empty( $img_match[2] ) ? $img_match[2] : '' );
			if ( '' === $src ) {
				return $match[0];
			}

			// Resolve attach_id: prefer the per-request map (covers
			// just-sideloaded URLs whose postmeta cache may still be
			// cold), then fall back to WP's own resolver.
			$attach_id = isset( $id_by_new_src[ $src ] ) ? (int) $id_by_new_src[ $src ] : (int) attachment_url_to_postid( $src );
			if ( ! $attach_id ) {
				return $match[0];
			}

			$alt = '';
			if ( preg_match( '/<img\b[^>]*?\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $inner_html, $alt_match ) ) {
				$alt = isset( $alt_match[1] ) && '' !== $alt_match[1] ? $alt_match[1] : ( isset( $alt_match[2] ) ? $alt_match[2] : '' );
			}
			$alt_attr = ' alt="' . esc_attr( $alt ) . '"';

			return '<!-- wp:image {"id":' . $attach_id . ',"sizeSlug":"full","linkDestination":"none"} -->' . "\n"
				. '<figure class="wp-block-image size-full">'
				. '<img src="' . esc_url( $src ) . '"' . $alt_attr . ' class="wp-image-' . $attach_id . '"/>'
				. '</figure>' . "\n"
				. '<!-- /wp:image -->';
		}, $content );

		return null === $result ? $content : $result;
	}

	/**
	 * Find an attachment previously imported from this exact source URL.
	 *
	 * @param string $image_url Source URL recorded on the attachment meta.
	 * @return int|null Attachment ID, or null when none match.
	 */
	private function find_attachment_by_source_url( $image_url ) {
		// Look up an attachment by its original source URL stored in meta.
		// Only way to perform this lookup.
		$query = new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				array(
					'key'     => '_seonix_source_url',
					'value'   => esc_url_raw( $image_url ),
					'compare' => '=',
				),
			),
		) );
		if ( empty( $query->posts ) ) {
			return null;
		}
		return (int) $query->posts[0];
	}

	/**
	 * Delete a previous featured-image attachment iff Seonix imported it
	 * (carries the `_seonix_source_url` meta) and nothing else still uses
	 * it as a thumbnail. Anything we did not import is left alone.
	 *
	 * @param int   $attachment_id    The candidate to delete.
	 * @param int[] $exclude_post_ids Post IDs to ignore when scanning for other usages.
	 * @return void
	 */
	private function maybe_delete_seonix_attachment( $attachment_id, $exclude_post_ids = array() ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		$source_url = get_post_meta( $attachment_id, '_seonix_source_url', true );
		if ( empty( $source_url ) ) {
			return; // Not ours; never touch attachments other plugins/users uploaded.
		}

		$exclude_ids = array_filter( array_map( 'intval', (array) $exclude_post_ids ) );

		// Find another post still using this attachment as featured image.
		// post__not_in is needed to exclude the caller-supplied posts from
		// the "still in use elsewhere" check.
		$other_users = new WP_Query( array(
			'post_type'              => 'any',
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			'post__not_in'           => $exclude_ids,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				array(
					'key'     => '_thumbnail_id',
					'value'   => $attachment_id,
					'compare' => '=',
				),
			),
		) );
		if ( ! empty( $other_users->posts ) ) {
			return; // Still in use elsewhere as featured image.
		}

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Per-token, per-action transient bucket. Defends heavy endpoints from a
	 * compromised or misbehaving Seonix backend client without putting an
	 * external dependency in front of WordPress. The token is whatever was
	 * sent in the Authorization header (or X-Seonix-Key) — a hash of an empty
	 * string is fine because validate_request() has already rejected unauth'd
	 * traffic before the handler runs.
	 *
	 * Returns true on accept; WP_Error(429) on reject.
	 *
	 * @param WP_REST_Request $request        The current request.
	 * @param string          $action         A short identifier for the bucket.
	 * @param int             $max_per_minute Cap per minute for this bucket.
	 * @return true|WP_Error
	 */
	/**
	 * Force redirection=0 on outbound HTTP requests. Used as an http_request_args
	 * filter so download_url and friends do not silently follow 30x responses
	 * to a host we haven't validated with is_safe_url. Pairs SSRF guard with
	 * a redirect guard so an attacker can't 302 from a public origin into
	 * cloud metadata / private IPs.
	 *
	 * @param array $args HTTP request args.
	 * @return array Modified args.
	 */
	public static function force_no_redirect_args( $args ) {
		$args['redirection'] = 0;
		return $args;
	}

	/**
	 * Attach the Seonix API key to a media download aimed at the Seonix engine.
	 *
	 * Seonix is not a CDN: /api/uploads is moving to authenticated-only so that
	 * customer pages stop hotlinking it. This plugin sideloads those images into
	 * the WP media library server-side, where no browser session exists, so the
	 * fetch must carry the same sx_ secret the engine already knows.
	 *
	 * The header is attached ONLY when the request targets the configured engine
	 * origin AND the path is under /api/uploads/. sideload_inline_images_in_post
	 * also downloads third-party images referenced in the article body, and the
	 * key must never leak to them. force_no_redirect_args is applied alongside
	 * this filter, so a 30x cannot carry the header to another host mid-request.
	 *
	 * Harmless against an engine that still serves anonymously — an extra
	 * Authorization header on a public file is simply ignored, which is what lets
	 * the plugin ship before the engine flips the flag.
	 *
	 * @param array  $args HTTP request args.
	 * @param string $url  Request URL.
	 * @return array Modified args.
	 */
	public static function attach_seonix_media_auth_args( $args, $url = '' ) {
		if ( ! self::is_seonix_media_url( $url ) ) {
			return $args;
		}

		$key = Seonix_Auth::get_key();
		if ( ! is_string( $key ) || '' === $key ) {
			return $args;
		}

		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Authorization'] = 'Bearer ' . $key;

		return $args;
	}

	/**
	 * True when $url points at the configured Seonix engine's /api/uploads/ path.
	 *
	 * Host must match the stored engine origin exactly — a suffix match would let
	 * "evil-seonix.ai" collect the key. Returns false when the engine URL was
	 * never configured, which keeps the key off the wire entirely.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	private static function is_seonix_media_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$engine = get_option( 'seonix_engine_url', '' );
		if ( ! is_string( $engine ) || '' === $engine ) {
			return false;
		}

		$engine_host = wp_parse_url( $engine, PHP_URL_HOST );
		$url_host    = wp_parse_url( $url, PHP_URL_HOST );
		$url_path    = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $engine_host ) || ! is_string( $url_host ) || ! is_string( $url_path ) ) {
			return false;
		}
		if ( strtolower( $url_host ) !== strtolower( $engine_host ) ) {
			return false;
		}

		return 0 === strpos( $url_path, '/api/uploads/' );
	}

	/**
	 * Wraps download_url() to disable HTTP redirects for the duration of the
	 * call. Caller must have already validated $url via is_safe_url() — this
	 * just closes the 30x bypass.
	 *
	 * @param string $url     URL to download.
	 * @param int    $timeout Timeout in seconds. Defaults to 60 so a slow origin
	 *                        cannot pin a PHP-FPM worker for minutes; callers that
	 *                        need longer pass an explicit value.
	 * @return string|WP_Error Temp file path or WP_Error.
	 */
	private function safe_download_url( $url, $timeout = 60 ) {
		add_filter( 'http_request_args', array( __CLASS__, 'force_no_redirect_args' ), 10, 1 );
		// Authenticates the fetch when (and only when) it targets the Seonix
		// engine's /api/uploads/ — see attach_seonix_media_auth_args.
		add_filter( 'http_request_args', array( __CLASS__, 'attach_seonix_media_auth_args' ), 10, 2 );
		$tmp = download_url( $url, $timeout );
		remove_filter( 'http_request_args', array( __CLASS__, 'attach_seonix_media_auth_args' ), 10 );
		remove_filter( 'http_request_args', array( __CLASS__, 'force_no_redirect_args' ), 10 );
		return $tmp;
	}

	/**
	 * Strip query/fragment from a URL before logging. Signed S3/CDN URLs
	 * carry tokens in the query string; logging them to error_log leaks
	 * credentials to anyone with read access to debug.log.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Scheme + host + path.
	 */
	private static function safe_url_for_log( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return '[invalid url]';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		return $scheme . $host . $path;
	}

	/**
	 * Per-user rate limit for browser-authenticated routes.
	 *
	 * check_rate_limit() below keys off the plugin's API-key header, which only
	 * backend-to-plugin calls carry. Cookie-authenticated editor calls have no
	 * such header, so they need the logged-in user as the bucket key.
	 *
	 * Worth limiting even though the caller is authenticated: /score makes a
	 * blocking outbound request (up to 15s) per call, so a loop from a single
	 * Contributor account can pin PHP workers and burn the whole site's share
	 * of the backend's per-IP budget — every author on the site shares one
	 * outbound IP.
	 *
	 * @param string $action        Bucket name.
	 * @param int    $max_per_minute Allowed calls per minute per user.
	 * @return true|WP_Error
	 */
	private function check_user_rate_limit( $action, $max_per_minute ) {
		$user_id = get_current_user_id();
		$key     = 'seonix_rl_' . $action . '_u' . $user_id;
		$count   = (int) get_transient( $key );
		if ( $count >= $max_per_minute ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests', 'seonix' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function check_rate_limit( WP_REST_Request $request, $action, $max_per_minute = 60 ) {
		$token = (string) $request->get_header( 'authorization' );
		if ( '' === $token ) {
			$token = (string) $request->get_header( 'X-Seonix-Key' );
		}
		if ( '' === $token ) {
			$token = (string) $request->get_header( 'X-CE-Key' );
		}

		$key   = 'seonix_rl_' . $action . '_' . md5( $token );
		$count = (int) get_transient( $key );
		if ( $count >= $max_per_minute ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests', 'seonix' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Validate that a URL is safe to fetch (prevents SSRF).
	 *
	 * Delegates to Seonix_Auth::is_safe_url() — the single source of truth shared
	 * with Seonix_Sync::is_safe_url() — which fails closed on unresolvable DNS,
	 * blocks `localhost` / `*.localhost` / `*.local` / `0.0.0.0` by name, and
	 * rejects any private/reserved IPv4 (A) OR IPv6 (AAAA) address. Kept as a thin
	 * private wrapper to preserve the existing internal call sites.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if safe, false otherwise.
	 */
	private function is_safe_url( $url ) {
		return Seonix_Auth::is_safe_url( $url );
	}

	/**
	 * Detect MIME type using multiple fallback methods.
	 *
	 * Order: mime_content_type() -> finfo -> URL extension -> magic bytes.
	 *
	 * @param string $file_path Local file path.
	 * @param string $url       Original URL (for extension fallback).
	 * @return string Detected MIME type, or 'application/octet-stream' if unknown.
	 */
	private function detect_mime_type( $file_path, $url ) {
		// Method 1: mime_content_type (most reliable on most systems).
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $file_path );
			if ( $mime && 'application/octet-stream' !== $mime ) {
				return $mime;
			}
		}

		// Method 2: finfo extension.
		if ( class_exists( 'finfo' ) ) {
			$finfo = new finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->file( $file_path );
			if ( $mime && 'application/octet-stream' !== $mime ) {
				return $mime;
			}
		}

		// Method 3: URL extension.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $url_path ) {
			$ext = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );
			if ( isset( self::EXT_MIME_MAP[ $ext ] ) ) {
				return self::EXT_MIME_MAP[ $ext ];
			}
		}

		// Method 4: Magic bytes. Reading the first 12 bytes of a local tmp file
		// is far cheaper than spinning up WP_Filesystem (which would also force
		// FTP credentials on hosts without FS_METHOD set).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'rb' );
		if ( $handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$bytes = fread( $handle, 12 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			if ( $bytes ) {
				// JPEG: FF D8 FF
				if ( 0 === strpos( $bytes, "\xFF\xD8\xFF" ) ) {
					return 'image/jpeg';
				}
				// PNG: 89 50 4E 47
				if ( 0 === strpos( $bytes, "\x89\x50\x4E\x47" ) ) {
					return 'image/png';
				}
				// GIF: GIF87a or GIF89a
				if ( 0 === strpos( $bytes, 'GIF87a' ) || 0 === strpos( $bytes, 'GIF89a' ) ) {
					return 'image/gif';
				}
				// WEBP: RIFF....WEBP
				if ( 0 === strpos( $bytes, 'RIFF' ) && substr( $bytes, 8, 4 ) === 'WEBP' ) {
					return 'image/webp';
				}
			}
		}

		return 'application/octet-stream';
	}

	/**
	 * Sanitize an inbound brand accent colour.
	 *
	 * Accepts only canonical 7-character hex (`#rrggbb`), case-insensitive,
	 * with surrounding whitespace tolerated. Anything else (CSS keywords,
	 * shorthand, javascript: URLs, longer strings) returns "" so the value
	 * is safe to interpolate into a `style="--seonix-accent: …"` attribute
	 * without escaping concerns beyond the standard esc_attr() pass.
	 *
	 * @param mixed $raw The raw `accent_color` request param.
	 * @return string Lowercase canonical hex, or "" when invalid.
	 */
	private function sanitize_brand_accent( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$candidate = strtolower( trim( $raw ) );
		if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $candidate ) ) {
			return '';
		}
		return $candidate;
	}

	/**
	 * Sanitize the inbound key_takeaways array.
	 *
	 * Accepts a list of strings from the publish payload. Trims whitespace,
	 * drops empty entries, runs each bullet through sanitize_text_field to
	 * strip tags. Returns an empty array on any malformed input so a typo
	 * in the editor never ships a broken list.
	 *
	 * @param mixed $raw The raw `key_takeaways` request param.
	 * @return string[] Sanitized bullet strings.
	 */
	private function sanitize_takeaways_items( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$clean = sanitize_text_field( $item );
			if ( '' !== trim( $clean ) ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Build the key-takeaways Gutenberg block markup.
	 *
	 * Emits native Gutenberg blocks (wp:group → wp:heading + wp:list with
	 * wp:list-item children) so the callout is editable inside WordPress
	 * admin like any normal post content: an editor can fix a typo, reorder
	 * a bullet, or delete the whole block without ever seeing raw HTML.
	 * Previous versions wrapped the callout in a single wp:html block which
	 * round-tripped safely but appeared as an opaque "Custom HTML" element
	 * in the WP editor.
	 *
	 * The `seonix-key-takeaways*` CSS classes are still on the rendered
	 * DOM in the same places, so the bundled stylesheet
	 * (assets/seonix-content.css) keeps painting the callout exactly as
	 * before — no theme CSS change required. The `seonix-key-takeaways__head`
	 * wrapper was dropped because the new structure no longer needs an
	 * inner head row; the heading carries its own class.
	 *
	 * The optional brand accent flows through as a CSS custom property on
	 * the group's <div> via Gutenberg's `style` attribute support. If a
	 * user edits the group block in the WP admin Gutenberg editor and the
	 * editor strips unknown custom properties, the stylesheet falls back
	 * to its bundled default accent — visual loss only, no breakage.
	 *
	 * @param string[] $items        Bullet strings (already sanitized).
	 * @param string   $title        Heading text.
	 * @param string   $brand_accent Optional lowercase 7-char hex; "" disables.
	 * @return string Block markup, or empty string when there is nothing to render.
	 */
	private function build_takeaways_block( $items, $title, $brand_accent = '' ) {
		if ( empty( $items ) ) {
			return '';
		}

		// Bullets — each native wp:list-item carries the styling class so the
		// stylesheet's per-bullet rules (marker, spacing) still apply.
		$bullet_blocks = '';
		foreach ( $items as $item ) {
			$bullet_blocks .= "<!-- wp:list-item -->\n"
				. '<li class="seonix-key-takeaways__item">' . esc_html( $item ) . "</li>\n"
				. "<!-- /wp:list-item -->\n";
		}

		// Optional native heading block. Drop entirely when the title is
		// blank so the callout collapses to "just bullets" cleanly — empty
		// <h2> would trip Gutenberg's block-validation warning.
		$heading_block = '';
		if ( '' !== trim( $title ) ) {
			$heading_block = "<!-- wp:heading {\"className\":\"seonix-key-takeaways__title\"} -->\n"
				. '<h2 class="wp-block-heading seonix-key-takeaways__title">' . esc_html( $title ) . "</h2>\n"
				. "<!-- /wp:heading -->\n\n";
		}

		// Accent ride-along — CSS custom property on the group's <div>. The
		// inline style is also declared in the block's attributes JSON so
		// Gutenberg recognises it as a known attribute on the group block
		// (otherwise an editor save in WP admin would treat it as drift and
		// strip it). $brand_accent is already validated against
		// /^#[0-9a-f]{6}$/; esc_attr is layered on as defence-in-depth.
		$group_attrs_json = '{"className":"seonix-key-takeaways"';
		$style_attr       = '';
		if ( '' !== $brand_accent ) {
			$css_var          = '--seonix-accent: ' . esc_attr( $brand_accent ) . ';';
			$group_attrs_json .= ',"style":{"css":"' . $css_var . '"}';
			$style_attr        = ' style="' . $css_var . '"';
		}
		$group_attrs_json .= '}';

		$list_block = "<!-- wp:list {\"className\":\"seonix-key-takeaways__list\"} -->\n"
			. "<ul class=\"wp-block-list seonix-key-takeaways__list\">\n"
			. $bullet_blocks
			. "</ul>\n"
			. "<!-- /wp:list -->\n";

		return "<!-- wp:group " . $group_attrs_json . " -->\n"
			. '<div class="wp-block-group seonix-key-takeaways"' . $style_attr . ">\n"
			. $heading_block
			. $list_block
			. "</div>\n"
			. "<!-- /wp:group -->";
	}

	/**
	 * Convert HTML string to Gutenberg block markup.
	 *
	 * Wraps each top-level HTML element in the appropriate block comment.
	 * Supports: headings, paragraphs, lists (ul/ol), images, blockquotes, tables, hr.
	 * Unknown elements are wrapped as wp:html blocks.
	 *
	 * @param string $html The HTML content.
	 * @return string Gutenberg block markup.
	 */
	private function html_to_blocks( $html ) {
		if ( empty( $html ) ) {
			return '';
		}

		// If content already has block comments, return as-is.
		if ( false !== strpos( $html, '<!-- wp:' ) ) {
			return $html;
		}

		$html = trim( $html );

		// Use DOMDocument to parse top-level elements. LIBXML_NONET forbids the
		// parser from reaching out over the network even if a DTD or external
		// entity sneaks in; combined with libxml2 2.9+ defaults (external entity
		// loading off) this is sufficient XXE protection on the WP-supported
		// PHP range (7.4+).
		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @loadHTML is the documented way to suppress libxml HTML5-warning spam.
		@$doc->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			// Fallback: wrap entire content as one paragraph block.
			return "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $html ) . "</p>\n<!-- /wp:paragraph -->";
		}

		$blocks = array();

		foreach ( $body->childNodes as $node ) {
			if ( $node->nodeType === XML_TEXT_NODE ) {
				$text = trim( $node->textContent );
				if ( '' === $text ) {
					continue;
				}
				$blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
				continue;
			}

			if ( $node->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			$tag       = strtolower( $node->nodeName );
			$inner_html = '';
			foreach ( $node->childNodes as $child ) {
				$inner_html .= $doc->saveHTML( $child );
			}

			// Rebuild the outer HTML for block content.
			$outer_html = $doc->saveHTML( $node );

			switch ( $tag ) {
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
					$level    = (int) substr( $tag, 1 );
					$attrs    = $level !== 2 ? ' {"level":' . $level . '}' : '';
					$blocks[] = "<!-- wp:heading" . $attrs . " -->\n" . $outer_html . "\n<!-- /wp:heading -->";
					break;

				case 'p':
					$blocks[] = "<!-- wp:paragraph -->\n" . $outer_html . "\n<!-- /wp:paragraph -->";
					break;

				case 'ul':
					$blocks[] = "<!-- wp:list -->\n" . $outer_html . "\n<!-- /wp:list -->";
					break;

				case 'ol':
					$blocks[] = '<!-- wp:list {"ordered":true} -->' . "\n" . $outer_html . "\n<!-- /wp:list -->";
					break;

				case 'blockquote':
					$blocks[] = "<!-- wp:quote -->\n" . $outer_html . "\n<!-- /wp:quote -->";
					break;

				case 'img':
					$blocks[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $outer_html . "</figure>\n<!-- /wp:image -->";
					break;

				case 'figure':
					$blocks[] = "<!-- wp:image -->\n" . $outer_html . "\n<!-- /wp:image -->";
					break;

				case 'table':
					$blocks[] = "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $outer_html . "</figure>\n<!-- /wp:table -->";
					break;

				case 'hr':
					$blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
					break;

				default:
					$blocks[] = "<!-- wp:html -->\n" . $outer_html . "\n<!-- /wp:html -->";
					break;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Rebuild the SEO indexable after post creation.
	 *
	 * This ensures the SEO plugin processes the meta set via meta_input.
	 *
	 * @param int $post_id The post ID.
	 */
	private function rebuild_yoast_indexable( $post_id ) {
		// Check if a compatible SEO plugin is active and its indexable class
		// exists.
		if ( ! class_exists( 'Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
			return;
		}

		if ( ! function_exists( 'YoastSEO' ) ) {
			return;
		}

		try {
			$container  = YoastSEO()->classes;
			$repository = $container->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );

			if ( $repository && method_exists( $repository, 'find_by_id_and_type' ) ) {
				$indexable = $repository->find_by_id_and_type( $post_id, 'post' );
				if ( $indexable ) {
					$builder = $container->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
					if ( $builder && method_exists( $builder, 'build_for_id_and_type' ) ) {
						$builder->build_for_id_and_type( $post_id, 'post', $indexable );
						$indexable->save();
					}
				}
			}
		} catch ( \Exception $e ) {
			// Silently fail - indexable rebuild is best-effort.
		}
	}
}
