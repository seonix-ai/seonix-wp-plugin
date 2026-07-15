<?php
/**
 * REST controller for SEO-fix operations.
 *
 * Routes (registered under both seonix/v1 and content-engine/v1 for back-compat):
 *   GET  /seo-fix/capabilities      What this site can fix right now.
 *   POST /seo-fix/dry-run           Preview a fix without applying.
 *   POST /seo-fix/apply             Apply a fix. Idempotent on fix_id.
 *   POST /seo-fix/rollback          Restore the pre-fix state for a history entry.
 *   GET  /seo-fix/history           Paginate history.
 *
 * The controller is the integration point between the registry, the history
 * store, and the WP REST layer. Fix methods themselves know nothing about HTTP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_SEO_Fix_Controller {

	private const NAMESPACE        = 'seonix/v1';
	private const LEGACY_NAMESPACE = 'content-engine/v1';
	private const BASE             = '/seo-fix';

	private Seonix_SEO_Fix_Registry $registry;
	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_Registry $registry, Seonix_SEO_Fix_History $history ) {
		$this->registry = $registry;
		$this->history  = $history;
	}

	public function register_routes(): void {
		foreach ( array( self::NAMESPACE, self::LEGACY_NAMESPACE ) as $ns ) {
			register_rest_route( $ns, self::BASE . '/capabilities', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_capabilities' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			register_rest_route( $ns, self::BASE . '/dry-run', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_dry_run' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			register_rest_route( $ns, self::BASE . '/apply', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_apply' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			register_rest_route( $ns, self::BASE . '/rollback', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rollback' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			register_rest_route( $ns, self::BASE . '/history', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_history' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// AI fillers need the page content + current SEO meta + focus
			// keyword (for meta_title / meta_description) and image filename +
			// parent post context (for image_alt). Keep these endpoints scoped
			// to the SEO-fix base so they aren't exposed to other plugins.
			register_rest_route( $ns, self::BASE . '/post-snapshot/(?P<id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_post_snapshot' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			register_rest_route( $ns, self::BASE . '/attachment-snapshot/(?P<id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_attachment_snapshot' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// Look up an attachment by its URL (the form scanner.images_without_alt
			// reports). Returns the same snapshot shape as /attachment-snapshot/{id}
			// so AI suggesters can use one fetcher contract.
			register_rest_route( $ns, self::BASE . '/attachment-by-url', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_attachment_by_url' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );

			// Cache purge: standalone endpoint so the dashboard can trigger
			// "clear cache now" independent of an apply, plus auto-call by the
			// backend after a successful apply (see ApplyRun on the Go side).
			register_rest_route( $ns, self::BASE . '/cache/purge', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_cache_purge' ),
				'permission_callback' => array( 'Seonix_Auth', 'validate_request' ),
			) );
		}
	}

	// ─── Handlers ────────────────────────────────────────────────────────

	public function handle_capabilities( WP_REST_Request $request ) {
		return new WP_REST_Response( array(
			'methods' => $this->registry->capabilities(),
			'cache'   => array(
				'engines' => Seonix_Cache_Purger::detect(),
				'active'  => Seonix_Cache_Purger::active_engines(),
			),
			// Native redirect manager (2.7.0+): advertises that this site
			// serves GET/POST /redirects(/sync) itself — the backend uses this
			// to route redirect management to the plugin instead of requiring
			// the third-party Redirection plugin. Bump `version` on breaking
			// contract changes; the backend parses tolerantly.
			'redirects' => array(
				'version' => 1,
			),
		) );
	}

	/**
	 * POST /seo-fix/cache/purge
	 * Body: { post_ids?: int[] }
	 *
	 * If post_ids is provided we ask each engine to purge those specific
	 * post URLs (engines that don't support per-URL fall back to a domain
	 * purge). Without post_ids we trigger a full flush.
	 */
	public function handle_cache_purge( WP_REST_Request $request ) {
		$post_ids = $request->get_param( 'post_ids' );
		if ( is_array( $post_ids ) && count( $post_ids ) > 0 ) {
			Seonix_Cache_Purger::purge_posts( $post_ids );
		} else {
			Seonix_Cache_Purger::purge_all();
		}
		// Trimmed in 2.2.5: backend's PurgeCache discarded the body — return
		// 204 No Content instead. Also drops the `active_engines()` walk that
		// detected every supported engine on every call only to populate the
		// echoed-and-ignored `active` field.
		return new WP_REST_Response( null, 204 );
	}

	public function handle_dry_run( WP_REST_Request $request ) {
		$prep = $this->prepare_method_call( $request );
		if ( $prep instanceof WP_Error ) {
			return $prep;
		}
		[ $method, $fix_id, $params ] = $prep;

		$result = $method->dry_run( $params );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$target = $this->extract_target( $result );
		$id     = $this->history->record_dry_run(
			$fix_id,
			$method->key(),
			$params,
			$target['type'],
			$target['id'],
			$result['before'] ?? null,
			$result['after'] ?? null
		);

		// Trimmed in 2.2.5: backend's FixResponse no longer decodes `diff` or
		// `target` — `diff` was a UI sugar string the backend never read, and
		// `target` was always echoed but never consumed. Dropping them avoids
		// a per-call sprintf in every fix method (see fix methods' return
		// arrays — the `diff` value is still computed there but no longer
		// shipped over the wire).
		return new WP_REST_Response( array(
			'history_id' => $id,
			'status'     => 'dry_run',
			'before'     => $result['before'] ?? null,
			'after'      => $result['after'] ?? null,
		) );
	}

	public function handle_apply( WP_REST_Request $request ) {
		$rl = $this->check_rate_limit( $request, 'seo_fix_apply', 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$prep = $this->prepare_method_call( $request );
		if ( $prep instanceof WP_Error ) {
			return $prep;
		}
		[ $method, $fix_id, $params ] = $prep;

		// Idempotency: if this fix_id has already been applied, return the prior outcome.
		$existing = $this->history->find_by_fix_id( $fix_id );
		if ( $existing && in_array( $existing['status'], array(
			Seonix_SEO_Fix_History::STATUS_APPLIED,
			Seonix_SEO_Fix_History::STATUS_ALREADY_APPLIED,
		), true ) ) {
			return new WP_REST_Response( array(
				'history_id' => (int) $existing['id'],
				'status'     => 'already_applied',
				'before'     => $this->maybe_decode( $existing['before_state'] ?? null ),
				'after'      => $this->maybe_decode( $existing['after_state'] ?? null ),
			) );
		}

		$result = $method->apply( $params );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$target = $this->extract_target( $result );

		// Method-level no-op: nothing to change. Record it so we have an audit trail
		// and respond with the same shape as the controller-level idempotency hit.
		// Trimmed in 2.2.5: `target` was echoed back but never decoded backend-side.
		if ( ! empty( $result['no_op'] ) ) {
			$id = $this->history->record_no_op(
				$fix_id,
				$method->key(),
				$params,
				$target['type'],
				$target['id'],
				$result['before'] ?? null
			);

			return new WP_REST_Response( array(
				'history_id' => $id,
				'status'     => 'already_applied',
				'before'     => $result['before'] ?? null,
				'after'      => $result['after'] ?? null,
			) );
		}

		$id = $this->history->record_apply(
			$fix_id,
			$method->key(),
			$params,
			$target['type'],
			$target['id'],
			$result['before'] ?? null,
			$result['after'] ?? null
		);

		return new WP_REST_Response( array(
			'history_id' => $id,
			'status'     => 'applied',
			'before'     => $result['before'] ?? null,
			'after'      => $result['after'] ?? null,
		) );
	}

	public function handle_rollback( WP_REST_Request $request ) {
		$history_id = (int) $request->get_param( 'history_id' );
		if ( $history_id <= 0 ) {
			return new WP_Error( 'missing_history_id', 'history_id is required.', array( 'status' => 400 ) );
		}

		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		// Trimmed in 2.2.5: backend hardcodes `model.SeoFixItemStatusRolledBack`
		// after every successful rollback response — it never reads `status` off
		// the wire, so the already-rolled-back short-circuit and the success path
		// both omit it. The history_id round-trip is enough for the backend to
		// know the call succeeded.
		if ( ( $entry['status'] ?? '' ) === Seonix_SEO_Fix_History::STATUS_ROLLED_BACK ) {
			return new WP_REST_Response( array(
				'history_id' => $history_id,
			) );
		}

		$method = $this->registry->get( $entry['method'] ?? '' );
		if ( ! $method ) {
			return new WP_Error( 'unknown_method', 'The fix method for this entry is no longer registered.', array( 'status' => 404 ) );
		}

		$result = $method->rollback( $history_id );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$this->history->mark_rolled_back( $history_id );

		return new WP_REST_Response( array(
			'history_id' => $history_id,
			'before'     => $result['before'] ?? null,
			'after'      => $result['after'] ?? null,
		) );
	}

	public function handle_history( WP_REST_Request $request ) {
		// History listing is intentionally left thin for the MVP — the backend
		// keeps its own canonical store; this endpoint is for plugin-side debug.
		return new WP_REST_Response( array(
			'items' => array(),
			'note'  => 'History listing not implemented in MVP; use Seonix backend.',
		) );
	}

	/**
	 * GET /seo-fix/post-snapshot/{id}
	 *
	 * Returns the bundle a backend AI suggester needs to write a meta_title
	 * or meta_description for this post: title, content, current SEO meta,
	 * and focus keyword. Works for any post type the user has on the site
	 * (post, page, custom service pages …) — unlike /posts/{id} which is
	 * scoped to post_type='post' for legacy reasons.
	 */
	public function handle_post_snapshot( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
		}

		// Trimmed in 2.2.5: backend's FetchPostSnapshot declares `type, url`
		// but never assigns them — dropped. The brand-suffix context
		// (`yoast_title_template`, `blogname`) was previously on every
		// `/posts` list row but only this snapshot endpoint actually feeds
		// the AI title suggester's character-budget calc, so it moved here.
		$api                  = new Seonix_REST_API();
		$yoast_title_template = $api->get_yoast_title_template( $post->post_type );
		$blogname             = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );

		return new WP_REST_Response( array(
			'id'                   => (int) $post->ID,
			'title'                => $post->post_title,
			'content'              => $post->post_content,
			'meta_title'           => (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true ),
			'meta_description'     => (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ),
			'focus_keyword'        => (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ),
			'yoast_title_template' => $yoast_title_template,
			'blogname'             => $blogname,
		) );
	}

	/**
	 * GET /seo-fix/attachment-by-url?url=<url>
	 *
	 * Resolves a public image URL (typically what the SEO scanner reports for
	 * images_missing_alt) to the underlying attachment + parent page context.
	 * Tries the URL as-given first, then re-tries with WP's "-WIDTHxHEIGHT"
	 * size suffix stripped so we hit the original upload.
	 */
	public function handle_attachment_by_url( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', 'url query param is required.', array( 'status' => 400 ) );
		}

		$id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
		if ( 0 === $id ) {
			$normalised = $this->strip_size_suffix( $url );
			if ( $normalised !== $url ) {
				$id = (int) attachment_url_to_postid( $normalised );
			}
		}
		if ( 0 === $id ) {
			return new WP_Error(
				'attachment_not_found',
				sprintf( 'No attachment matches URL: %s', $url ),
				array( 'status' => 404 )
			);
		}

		// Reuse the id-keyed handler so the response shape stays identical.
		$request->set_param( 'id', $id );
		return $this->handle_attachment_snapshot( $request );
	}

	private function strip_size_suffix( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return $url;
		}
		$path = $parts['path'];
		$base = basename( $path );
		$ext  = '';
		if ( false !== ( $dot = strrpos( $base, '.' ) ) ) {
			$ext  = substr( $base, $dot );
			$base = substr( $base, 0, $dot );
		}
		$cleaned = preg_replace( '/-\d+x\d+$/', '', $base );
		if ( $cleaned === $base ) {
			return $url;
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' )
			. dirname( $path ) . '/' . $cleaned . $ext;
	}

	/**
	 * GET /seo-fix/attachment-snapshot/{id}
	 *
	 * Returns image metadata + parent-page context the image_alt suggester
	 * needs. Filename carries most of the signal on quality WP sites.
	 */
	public function handle_attachment_snapshot( WP_REST_Request $request ) {
		$attachment_id = (int) $request['id'];
		$post          = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'attachment_not_found', sprintf( 'Attachment %d not found.', $attachment_id ), array( 'status' => 404 ) );
		}

		$url      = (string) wp_get_attachment_url( $attachment_id );
		$filename = $url ? basename( wp_parse_url( $url, PHP_URL_PATH ) ) : '';

		$page_id    = (int) ( $post->post_parent ?? 0 );
		$page_title = '';
		$page_url   = '';
		if ( $page_id > 0 && $parent = get_post( $page_id ) ) {
			$page_title = $parent->post_title;
			$page_url   = (string) get_permalink( $parent->ID );
		}

		// Trimmed in 2.2.5: backend's ImageSnapshot struct does not declare
		// `page_id`, and `AttachmentID` (`id` on the wire) is assigned but
		// never read. Both fields are dropped; the AI alt-suggester reads
		// only filename/page_title/page_url.
		return new WP_REST_Response( array(
			'url'        => $url,
			'filename'   => $filename,
			'page_title' => $page_title,
			'page_url'   => $page_url,
		) );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Validate the common shape of dry-run / apply requests and resolve the method.
	 *
	 * @return array{0:Seonix_Fix_Method,1:string,2:array}|\WP_Error
	 */
	private function prepare_method_call( WP_REST_Request $request ) {
		$method_key = (string) $request->get_param( 'method' );
		$fix_id     = (string) $request->get_param( 'fix_id' );
		$params     = $request->get_param( 'params' );
		$params     = is_array( $params ) ? $params : array();

		// WordPress applies wp_slash() to incoming REST params (so $_POST stays
		// consistent), turning every "/" into "\/" inside string values. Fix
		// methods do exact str_replace / get_post_meta comparisons, which the
		// extra backslashes break. Strip them before handing params off.
		$params = wp_unslash( $params );

		if ( '' === $method_key ) {
			return new WP_Error( 'missing_method', 'method is required.', array( 'status' => 400 ) );
		}
		if ( '' === $fix_id ) {
			return new WP_Error( 'missing_fix_id', 'fix_id is required.', array( 'status' => 400 ) );
		}

		$method = $this->registry->get( $method_key );
		if ( ! $method ) {
			return new WP_Error( 'unknown_method', sprintf( 'Unknown fix method: %s', $method_key ), array( 'status' => 404 ) );
		}

		$validation = $method->validate_params( $params );
		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		return array( $method, $fix_id, $params );
	}

	/**
	 * Pull a target descriptor out of the method result, defaulting to (none, 0).
	 *
	 * @return array{type:string,id:int}
	 */
	private function extract_target( array $result ): array {
		$target = $result['target'] ?? array();
		return array(
			'type' => isset( $target['type'] ) ? (string) $target['type'] : '',
			'id'   => isset( $target['id'] ) ? (int) $target['id'] : 0,
		);
	}

	private function maybe_decode( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$decoded = json_decode( $value, true );
		return null === $decoded ? $value : $decoded;
	}

	/**
	 * Per-token, per-action transient bucket for SEO-fix endpoints.
	 *
	 * Mirrors Seonix_REST_API::check_rate_limit() so each surface has its own
	 * counters and one noisy backend cannot drain budgets across surfaces.
	 *
	 * @param WP_REST_Request $request        The current request.
	 * @param string          $action         A short identifier for the bucket.
	 * @param int             $max_per_minute Cap per minute for this bucket.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( WP_REST_Request $request, string $action, int $max_per_minute = 60 ) {
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
}
