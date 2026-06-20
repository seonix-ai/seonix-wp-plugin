<?php
/**
 * IndexNow submission for Seonix.
 *
 * Pings the IndexNow API (Bing + Yandex; Google does NOT participate) whenever
 * a public post/page is published or updated, so those engines re-crawl the
 * changed URL within minutes instead of waiting for a scheduled crawl.
 *
 * Self-provisioning: the verification key is generated lazily on first use and
 * served VIRTUALLY at the site root (https://site/{key}.txt) via a
 * template_redirect hook — no file is written to disk. The key MUST be at the
 * root: IndexNow only authorises a key for URLs at or below the key file's own
 * directory, so a key under /wp-content/uploads/... would be rejected (422)
 * for site pages.
 *
 * All methods are static and stateless (state lives in options), so callers
 * (the save_post hook, the template_redirect key-file route) can use it
 * without wiring.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_IndexNow {

	const KEY_OPTION  = 'seonix_indexnow_key';
	const AUTO_OPTION = 'seonix_indexnow_auto';
	const LAST_OPTION = 'seonix_indexnow_last';

	// Neutral IndexNow endpoint — forwards the submission to every
	// participating engine (Bing, Yandex, Seznam, Naver), so we don't have to
	// ping each one. Fixed public host: no SSRF surface.
	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	// IndexNow accepts up to 10,000 URLs per request.
	const MAX_BATCH = 10000;

	// Per-request URL queue, flushed once as a single batch on `shutdown`.
	// Keyed by URL so a bulk import of N posts in one request becomes ONE
	// IndexNow call instead of N.
	private static $queue = array();
	private static $flush_registered = false;

	/**
	 * Whether auto-submit on publish/update is enabled. On by default; the
	 * operator can disable it by setting the option to '0'.
	 *
	 * @return bool
	 */
	public static function is_auto_enabled() {
		return '1' === (string) get_option( self::AUTO_OPTION, '1' );
	}

	/**
	 * Public URL of the key file, served at the SITE ROOT. IndexNow only trusts
	 * a key to authorise URLs at or below the key file's own directory, so the
	 * key must be root-level to submit arbitrary site pages.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function key_url( string $key ) {
		return home_url( '/' . $key . '.txt' );
	}

	/**
	 * Return the IndexNow key + its public root URL, generating the key on first
	 * use. No filesystem write — the key file is served virtually by serve_key().
	 *
	 * @return array{key:string,url:string}
	 */
	public static function ensure_key() {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' === $key ) {
			$key = bin2hex( random_bytes( 16 ) );
			// autoload=false: read only on submit and on the key-file request.
			update_option( self::KEY_OPTION, $key, false );
		}
		return array(
			'key' => $key,
			'url' => self::key_url( $key ),
		);
	}

	/**
	 * Serve the verification key as plain text at its root URL. Hooked on
	 * template_redirect (front-end only): a request for /{key}.txt has no
	 * physical file and falls through to WordPress, where we answer it with the
	 * key. This is how IndexNow verifies domain ownership without a disk write.
	 * One exact path compare; a no-op for every other request.
	 */
	public static function serve_key() {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' === $key ) {
			return;
		}
		$req_path = isset( $_SERVER['REQUEST_URI'] )
			? trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' )
			: '';
		if ( '' === $req_path ) {
			return;
		}
		$key_path = trim( (string) wp_parse_url( self::key_url( $key ), PHP_URL_PATH ), '/' );
		if ( $req_path !== $key_path ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			nocache_headers();
		}
		echo esc_html( $key );
		exit;
	}

	/**
	 * Submit one or more URLs to IndexNow. Non-blocking fire-and-forget (the
	 * save flow must not wait on an external HTTP call). Only same-host http(s)
	 * URLs are sent — IndexNow rejects a submission whose URLs don't match the
	 * host that owns the key.
	 *
	 * @param string[] $urls
	 * @return bool True if a request was dispatched.
	 */
	public static function submit( array $urls ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		$clean = array();
		$seen  = array();
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}
			if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				continue;
			}
			if ( strtolower( $parts['host'] ) !== strtolower( $host ) ) {
				continue; // foreign host — IndexNow would reject the whole batch
			}
			$seen[ $url ] = true;
			$clean[]      = $url;
		}
		if ( empty( $clean ) ) {
			return false;
		}

		if ( count( $clean ) > self::MAX_BATCH ) {
			$clean = array_slice( $clean, 0, self::MAX_BATCH );
		}

		$loc = self::ensure_key();
		if ( $loc instanceof WP_Error ) {
			return false;
		}

		// IndexNow requires the key file to live on the same host as the
		// submitted URLs. If uploads are offloaded to a CDN/separate domain,
		// keyLocation's host won't match and the API would 403 the batch — skip.
		$key_host = wp_parse_url( $loc['url'], PHP_URL_HOST );
		if ( empty( $key_host ) || strtolower( $key_host ) !== strtolower( $host ) ) {
			return false;
		}

		$body = wp_json_encode( array(
			'host'        => $host,
			'key'         => $loc['key'],
			'keyLocation' => $loc['url'],
			'urlList'     => $clean,
		) );
		if ( false === $body ) {
			return false;
		}

		wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'  => 15,
				'blocking' => false,
				'headers'  => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'     => $body,
			)
		);

		// Lightweight observability for the status endpoint / admin.
		update_option(
			self::LAST_OPTION,
			array(
				'at'    => time(),
				'count' => count( $clean ),
			),
			false
		);

		return true;
	}

	/**
	 * Gate + submit a single post's permalink. Called from the save_post hook
	 * for published public content. Skips drafts, non-public types, noindex
	 * pages, and repeat submissions of the same URL within a 10-minute window.
	 *
	 * @param WP_Post $post
	 */
	public static function submit_post( $post ) {
		if ( ! self::is_auto_enabled() ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) || 'publish' !== $post->post_status ) {
			return;
		}
		// Only public post types — a private/attachment URL has no business in
		// a search index.
		$type_obj = get_post_type_object( $post->post_type );
		if ( ! $type_obj || empty( $type_obj->public ) ) {
			return;
		}
		if ( self::is_noindex( $post->ID ) ) {
			return;
		}

		$url = get_permalink( $post );
		if ( empty( $url ) ) {
			return;
		}

		// Debounce: save_post can fire more than once per real edit. One submit
		// per URL per 10 minutes is plenty for "tell Bing it changed".
		$tkey = 'seonix_inow_' . $post->ID;
		if ( get_transient( $tkey ) ) {
			return;
		}
		set_transient( $tkey, 1, 10 * MINUTE_IN_SECONDS );

		// Queue and flush once on shutdown, so a bulk operation that saves many
		// posts in one request results in a single batched IndexNow call.
		self::$queue[ $url ] = true;
		if ( ! self::$flush_registered ) {
			self::$flush_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Dispatch the queued URLs as one IndexNow batch. Registered on `shutdown`
	 * by submit_post so the save request returns before the HTTP call. Public
	 * because it's a hook callback.
	 */
	public static function flush() {
		if ( empty( self::$queue ) ) {
			return;
		}
		$urls         = array_keys( self::$queue );
		self::$queue  = array();
		self::submit( $urls );
	}

	/**
	 * Best-effort noindex check across the common SEO plugins. Absence of any
	 * signal is treated as indexable.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function is_noindex( $post_id ) {
		// Yoast SEO: '1' means noindex.
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		// SEOPress: 'yes' means noindex.
		if ( 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}
		// Rank Math: 'noindex' present in the robots array.
		$rm = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
			return true;
		}
		return false;
	}
}
