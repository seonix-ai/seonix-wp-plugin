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

		// Escape on output. The body is markdown built from already-stripped
		// post titles, excerpts and content (wp_strip_all_tags applied in
		// html_to_markdown). esc_html() only encodes `<>&"'` — none of which
		// are valid markdown control characters, so the rendered markdown
		// structure (headings, links, list markers) is fully preserved.
		echo esc_html( $content );
		exit;
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
		$site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$site_desc = wp_strip_all_tags( get_bloginfo( 'description' ) );

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

				$llm_txt .= '## ' . wp_strip_all_tags( $category->name ) . "\n\n";
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
		$line = '- [' . wp_strip_all_tags( $post->post_title ) . '](' . esc_url_raw( get_permalink( $post ) ) . ')';
		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			$line .= ': ' . wp_trim_words( $excerpt, 20, '...' );
		}
		return $line;
	}

	/**
	 * Build the llms-full.txt content.
	 *
	 * @return string
	 */
	private function build_full() {
		$site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$site_desc = wp_strip_all_tags( get_bloginfo( 'description' ) );

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
			$llm_full .= '## ' . wp_strip_all_tags( $post->post_title ) . "\n\n";
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
}
