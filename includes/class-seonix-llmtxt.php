<?php
/**
 * LLMs.txt generator for Seonix.
 *
 * Generates llms.txt (index) and llms-full.txt (full content) following the
 * llmstxt.org specification so AI assistants can discover and ingest the site's
 * canonical content.
 *
 * Serves files DYNAMICALLY via WP rewrite rules + query_var dispatch. We never
 * write to the filesystem (no `ABSPATH . 'llms.txt'`, no `file_put_contents`,
 * no WP_Filesystem) — the content is built per request from `get_posts()` /
 * `get_terms()` and emitted with text/markdown headers + ETag/304 support.
 *
 * Rationale (vs Yoast's get_home_path() + WP_Filesystem approach): rewrite-based
 * serving works on every host regardless of root-dir write permissions, never
 * goes stale, and avoids touching the filesystem entirely — which sidesteps the
 * "do not write outside wp-content/uploads" guideline.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_LLMTxt {

	/**
	 * Register rewrite rules for serving llms.txt and llms-full.txt dynamically.
	 */
	public function register_rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?seonix_llmtxt=index', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?seonix_llmtxt=full', 'top' );
	}

	/**
	 * Register query var for llms.txt rewrite.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'seonix_llmtxt';
		return $vars;
	}

	/**
	 * Intercept requests for llms.txt / llms-full.txt and serve them dynamically.
	 */
	public function handle_request() {
		$type = get_query_var( 'seonix_llmtxt' );
		if ( ! $type ) {
			return;
		}

		if ( $type === 'full' ) {
			$content = $this->build_full();
		} else {
			$content = $this->build_index();
		}

		$etag = '"' . md5( $content ) . '"';

		// Support conditional GET (304 Not Modified).
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) )
			: '';
		if ( $if_none_match === $etag ) {
			status_header( 304 );
			exit;
		}

		// Get latest post modification date for Last-Modified header.
		$latest_post = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );
		if ( ! empty( $latest_post ) ) {
			$last_modified = gmdate( 'D, d M Y H:i:s', strtotime( $latest_post[0]->post_modified_gmt ) ) . ' GMT';
			header( 'Last-Modified: ' . $last_modified );
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=3600, must-revalidate' );

		// The body is plain text/markdown (Content-Type: text/markdown), NOT
		// HTML — every field is normalized via clean_text() (tags stripped,
		// entities decoded, control chars removed) and the URLs via esc_url_raw.
		// esc_html() here was wrong: it re-encoded "&" → "&amp;", so a category
		// "Tipps & Tricks" went out as "Tipps &amp; Tricks" (and any surviving
		// entity was doubled). Emit the raw markdown.
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text/markdown response; fields normalized in clean_text(), esc_html corrupts "&" and markdown.
		exit;
	}

	/**
	 * Cancel WordPress's canonical redirect for our virtual endpoints.
	 *
	 * Without this, a request for /llms.txt is 301-redirected to /llms.txt/
	 * (WP treats the rule-matched request as a slash-less permalink), and the
	 * slashed URL then matches no rewrite rule at all. Returning the requested
	 * URL unchanged when our query var is set short-circuits the redirect so
	 * /llms.txt serves canonically without a trailing slash.
	 *
	 * @param string $redirect_url  The URL WordPress wants to redirect to.
	 * @param string $requested_url The originally requested URL.
	 * @return string The requested URL (cancels the redirect) for our endpoints.
	 */
	public function prevent_canonical_redirect( $redirect_url, $requested_url ) {
		if ( get_query_var( 'seonix_llmtxt' ) ) {
			return $requested_url;
		}
		return $redirect_url;
	}

	/**
	 * Build the llms.txt index content.
	 *
	 * Structure per llmstxt.org spec:
	 * - # Site Name
	 * - > Description
	 * - ## Pages (ordered by menu_order)
	 * - ## Category Name (posts grouped by category)
	 *
	 * @return string
	 */
	private function build_index() {
		$site_name = $this->clean_text( get_bloginfo( 'name' ) );
		$site_desc = $this->clean_text( get_bloginfo( 'description' ) );

		$llm_txt = "# {$site_name}\n\n";
		if ( $site_desc ) {
			$llm_txt .= "> {$site_desc}\n\n";
		}

		// Pages section (ordered by menu_order).
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );

		if ( ! empty( $pages ) ) {
			// Order by IMPORTANCE, not raw menu_order: front page first, then
			// top-level pages (service hubs) before nested children (city /
			// doorway pages typically sit under a hub), then menu_order, then
			// title. Hand-built pages usually share menu_order=0, so ordering on
			// menu_order alone buried the money pages among doorway clones and
			// gave AI crawlers no signal of which pages matter.
			$front_id = (int) get_option( 'page_on_front' );
			usort( $pages, function ( $a, $b ) use ( $front_id ) {
				$fa = ( $front_id && (int) $a->ID === $front_id ) ? 0 : 1;
				$fb = ( $front_id && (int) $b->ID === $front_id ) ? 0 : 1;
				if ( $fa !== $fb ) {
					return $fa - $fb;
				}
				$da = $a->post_parent ? 1 : 0;
				$db = $b->post_parent ? 1 : 0;
				if ( $da !== $db ) {
					return $da - $db;
				}
				if ( (int) $a->menu_order !== (int) $b->menu_order ) {
					return (int) $a->menu_order - (int) $b->menu_order;
				}
				return strcasecmp( (string) $a->post_title, (string) $b->post_title );
			} );

			$llm_txt .= "## Pages\n\n";
			foreach ( $pages as $page ) {
				$llm_txt .= $this->format_link_line( $page ) . "\n";
			}
			$llm_txt .= "\n";
		}

		// Blog posts grouped by category.
		$categories = get_terms( array(
			'taxonomy'   => 'category',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $category ) {
				$posts = get_posts( array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'category'       => $category->term_id,
					'orderby'        => 'date',
					'order'          => 'DESC',
				) );

				if ( empty( $posts ) ) {
					continue;
				}

				$llm_txt .= '## ' . $this->clean_text( $category->name ) . "\n\n";
				foreach ( $posts as $post ) {
					$llm_txt .= $this->format_link_line( $post ) . "\n";
				}
				$llm_txt .= "\n";
			}
		}

		return $llm_txt;
	}

	/**
	 * Format a single link line for the index.
	 *
	 * @param WP_Post $post The post/page object.
	 * @return string Formatted markdown link line.
	 */
	private function format_link_line( $post ) {
		$line = '- [' . $this->clean_text( $post->post_title ) . '](' . esc_url_raw( get_permalink( $post ) ) . ')';
		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			$line .= ': ' . $this->clean_text( wp_trim_words( $excerpt, 20, '...' ) );
		}
		return $line;
	}

	/**
	 * Build the llms-full.txt content.
	 *
	 * @return string
	 */
	private function build_full() {
		$site_name = $this->clean_text( get_bloginfo( 'name' ) );
		$site_desc = $this->clean_text( get_bloginfo( 'description' ) );

		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$llm_full = "# {$site_name}\n\n";
		if ( $site_desc ) {
			$llm_full .= "> {$site_desc}\n\n";
		}

		foreach ( $posts as $post ) {
			$llm_full .= "---\n\n";
			$llm_full .= '## ' . $this->clean_text( $post->post_title ) . "\n\n";
			$llm_full .= 'URL: ' . esc_url_raw( get_permalink( $post ) ) . "\n";
			$llm_full .= "Type: " . $post->post_type . "\n";
			$llm_full .= "Date: " . $post->post_date . "\n\n";
			$llm_full .= $this->html_to_markdown( $post->post_content ) . "\n\n";
		}

		return $llm_full;
	}

	/**
	 * Convert HTML to basic Markdown using regex.
	 *
	 * Handles Gutenberg block comments, headings, bold, italic, links,
	 * images, lists, paragraphs, and line breaks.
	 *
	 * @param string $html HTML content.
	 * @return string Markdown content.
	 */
	private function html_to_markdown( $html ) {
		// Remove Gutenberg block comments.
		$md = preg_replace( '/<!--.*?-->/s', '', $html );

		// Headings h2-h6.
		for ( $i = 6; $i >= 2; $i-- ) {
			$hashes = str_repeat( '#', $i );
			$md = preg_replace( '/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si', "\n" . $hashes . ' $1' . "\n", $md );
		}

		// Bold.
		$md = preg_replace( '/<(strong|b)>(.*?)<\/\1>/si', '**$2**', $md );
		// Italic.
		$md = preg_replace( '/<(em|i)>(.*?)<\/\1>/si', '*$2*', $md );
		// Links.
		$md = preg_replace( '/<a[^>]+href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $md );
		// Images (alt before src).
		$md = preg_replace( '/<img[^>]+alt=["\']([^"\']*)["\'][^>]+src=["\']([^"\']*)["\'][^>]*\/?>/si', '![$1]($2)', $md );
		// Images (src before alt).
		$md = preg_replace( '/<img[^>]+src=["\']([^"\']*)["\'][^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/si', '![$2]($1)', $md );
		// List items.
		$md = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', '- $1', $md );
		// Remove remaining ul/ol tags.
		$md = preg_replace( '/<\/?[uo]l[^>]*>/si', '', $md );
		// Paragraphs.
		$md = preg_replace( '/<p[^>]*>(.*?)<\/p>/si', "$1\n\n", $md );
		// Line breaks.
		$md = preg_replace( '/<br\s*\/?>/si', "\n", $md );
		// Strip remaining HTML tags.
		$md = wp_strip_all_tags( $md );
		// Clean up excessive newlines.
		$md = preg_replace( '/\n{3,}/', "\n\n", $md );

		return trim( $md );
	}

	/**
	 * Normalize a WordPress-sourced string for plain-text/markdown output.
	 *
	 * WordPress returns term names, titles and excerpts already HTML-entity
	 * encoded (a category stored as "Tipps & Tricks" comes back as
	 * "Tipps &amp; Tricks"), and real post content can carry invisible
	 * zero-width / soft-hyphen format characters. wp_strip_all_tags removes
	 * tags only — it decodes nothing and strips no format chars — so those
	 * artifacts used to land verbatim in llms.txt (literal "&amp;", stray
	 * U+200B). This helper strips tags, decodes entities, removes zero-width /
	 * BOM / soft-hyphen characters, and collapses whitespace.
	 *
	 * @param string $s Raw WordPress string.
	 * @return string Clean plain text.
	 */
	private function clean_text( $s ) {
		$s = wp_strip_all_tags( (string) $s );
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip zero-width space/joiner/non-joiner, BOM, and soft hyphen.
		$s = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $s );
		// Collapse any whitespace run (incl. newlines) to a single space.
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( $s );
	}
}
