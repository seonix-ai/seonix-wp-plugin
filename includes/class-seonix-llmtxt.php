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
	 * - ## Pages (ordered by importance)
	 * - ## Category Name (each post listed ONCE, under its primary category;
	 *   sections below the minimum link count fold into an ancestor section)
	 * - ## Optional (utility pages + llms-full.txt companion)
	 *
	 * Curation rules (llms.txt is a curated map, not a sitemap dump):
	 * - each post appears exactly once — under its primary category (SEO-plugin
	 *   primary term when set, else the most specific assigned category). The
	 *   old per-category loop used WP's hierarchy-inclusive `category` query,
	 *   so one post fanned out into every category AND every ancestor section
	 *   (71 posts became ~380 lines on a 52-category site);
	 * - noindex, password-protected, placeholder ("coming soon" stubs) and
	 *   utility pages (contact, privacy, cart, …) are excluded;
	 * - descriptions prefer the SEO meta description (a complete sentence
	 *   written for exactly this purpose) over the auto-excerpt.
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

		// Business facts (NAP + service area) straight from the Seonix
		// profile — the one block an AI assistant can quote for identity
		// questions without parsing any page. Empty string when no profile.
		$llm_txt .= Seonix_Business_Profile::llms_block();

		// Site-specific business facts the profile does not model yet —
		// pricing, availability, review counts. Operators (or Seonix-managed
		// mu-plugins) append pre-formatted markdown here; AI assistants
		// answer "what does it cost" questions from exactly this block.
		$extra = apply_filters( 'seonix_llmstxt_business_extra', '' );
		if ( is_string( $extra ) && '' !== trim( $extra ) ) {
			$llm_txt .= rtrim( $extra ) . "\n\n";
		}

		// Safety cap for very large sites; llms.txt should stay consumable.
		$max_items = (int) apply_filters( 'seonix_llmstxt_max_items', 2000 );
		$emitted   = 0;

		// Pages section (ordered by menu_order).
		$raw_pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );
		$pages = array_values( array_filter( $raw_pages, array( $this, 'should_include' ) ) );

		// Utility pages (contact, privacy, terms …) are excluded from the main
		// map but belong in the spec's "## Optional" section: secondary URLs a
		// consumer may skip for a shorter context, yet exactly what an AI
		// assistant needs for "how do I contact them" questions. Password-
		// protected and noindex pages stay out entirely; the stub check is
		// deliberately NOT applied — utility pages are legitimately short.
		$optional_pages = array();
		foreach ( $raw_pages as $p ) {
			if ( '' !== (string) $p->post_password || $this->is_noindex( $p->ID ) ) {
				continue;
			}
			if ( in_array( $p->post_name, $this->excluded_page_slugs(), true ) ) {
				$optional_pages[] = $p;
			}
		}

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
				if ( $emitted >= $max_items ) {
					break;
				}
				$llm_txt .= $this->format_link_line( $page ) . "\n";
				$emitted++;
			}
			$llm_txt .= "\n";
		}

		// Blog posts: ONE query, then group in PHP by primary category. (The
		// old shape — one hierarchy-inclusive get_posts() per category — both
		// duplicated entries and cost 50+ queries per request.)
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$groups      = array(); // cat name => post list, insertion order = date DESC per group.
		$group_terms = array(); // cat name => WP_Term, for the ancestor-fold pass below.
		$orphans     = array(); // posts without any category.
		foreach ( $posts as $post ) {
			if ( ! $this->should_include( $post ) ) {
				continue;
			}
			$term = $this->primary_category( $post );
			if ( null === $term ) {
				$orphans[] = $post;
				continue;
			}
			$name = $this->clean_text( $term->name );
			if ( ! isset( $groups[ $name ] ) ) {
				$groups[ $name ]      = array();
				$group_terms[ $name ] = $term;
			}
			$groups[ $name ][] = $post;
		}

		// Section granularity: a "## Category" heading over a single link is
		// taxonomy noise, not a curated map (a 30-post site rendered 25+
		// one-link sections — virus.nl audit v3). Sections below the minimum
		// fold into the nearest ANCESTOR category that has its own section;
		// with no such ancestor the posts join the generic Posts tail.
		// Deepest sections fold first so a child single can rescue its parent
		// (two related singles become one two-link section instead of two
		// orphans).
		$min_section_links = max( 1, (int) apply_filters( 'seonix_llmstxt_min_section_links', 2 ) );
		if ( $min_section_links > 1 ) {
			$names = array_keys( $groups );
			usort( $names, function ( $a, $b ) use ( $group_terms ) {
				$da = isset( $group_terms[ $a ] ) ? count( get_ancestors( $group_terms[ $a ]->term_id, 'category' ) ) : 0;
				$db = isset( $group_terms[ $b ] ) ? count( get_ancestors( $group_terms[ $b ]->term_id, 'category' ) ) : 0;
				if ( $da !== $db ) {
					return $db - $da; // deepest first
				}
				return strcasecmp( $a, $b );
			} );
			foreach ( $names as $name ) {
				if ( ! isset( $groups[ $name ] ) || count( $groups[ $name ] ) >= $min_section_links ) {
					continue;
				}
				$folded = false;
				if ( isset( $group_terms[ $name ] ) ) {
					foreach ( get_ancestors( $group_terms[ $name ]->term_id, 'category' ) as $anc_id ) {
						$anc = get_term( (int) $anc_id, 'category' );
						if ( $anc && ! is_wp_error( $anc ) && isset( $anc->name ) ) {
							$anc_name = $this->clean_text( $anc->name );
							if ( $anc_name !== $name && isset( $groups[ $anc_name ] ) ) {
								$groups[ $anc_name ] = array_merge( $groups[ $anc_name ], $groups[ $name ] );
								$folded              = true;
								break;
							}
						}
					}
				}
				if ( ! $folded ) {
					$orphans = array_merge( $orphans, $groups[ $name ] );
				}
				unset( $groups[ $name ], $group_terms[ $name ] );
			}
		}
		uksort( $groups, 'strcasecmp' );

		foreach ( $groups as $name => $group_posts ) {
			if ( $emitted >= $max_items ) {
				break;
			}
			$llm_txt .= '## ' . $name . "\n\n";
			foreach ( $group_posts as $post ) {
				if ( $emitted >= $max_items ) {
					break;
				}
				$llm_txt .= $this->format_link_line( $post ) . "\n";
				$emitted++;
			}
			$llm_txt .= "\n";
		}

		if ( ! empty( $orphans ) && $emitted < $max_items ) {
			$llm_txt .= "## Posts\n\n";
			foreach ( $orphans as $post ) {
				if ( $emitted >= $max_items ) {
					break;
				}
				$llm_txt .= $this->format_link_line( $post ) . "\n";
				$emitted++;
			}
			$llm_txt .= "\n";
		}

		// "## Optional" per llmstxt.org: secondary URLs, safe to skip when the
		// consumer wants a shorter context. Utility pages (capped) plus the
		// full-text companion file.
		$llm_txt .= "## Optional\n\n";
		$optional_count = 0;
		foreach ( $optional_pages as $post ) {
			if ( $optional_count >= 5 ) {
				break;
			}
			$llm_txt .= $this->format_link_line( $post ) . "\n";
			$optional_count++;
		}
		$llm_txt .= '- [Full text version](' . esc_url_raw( home_url( '/llms-full.txt' ) ) . "): Complete article texts of this site in one markdown file.\n\n";

		return $llm_txt;
	}

	/**
	 * Whether a post/page belongs in llms.txt at all.
	 *
	 * Excludes password-protected content, anything the site's SEO plugin
	 * marks noindex, utility pages (contact/privacy/cart/…), and placeholder
	 * stubs — a "Binnenkort…" body next to real articles poisons the map AI
	 * assistants build of the site.
	 *
	 * Public only because array_filter needs the callback; not an API.
	 *
	 * @param WP_Post $post The post/page object.
	 * @return bool
	 */
	public function should_include( $post ) {
		$include = true;

		if ( '' !== (string) $post->post_password ) {
			$include = false;
		} elseif ( $this->is_noindex( $post->ID ) ) {
			$include = false;
		} elseif ( 'page' === $post->post_type
			&& in_array( $post->post_name, $this->excluded_page_slugs(), true ) ) {
			$include = false;
		} elseif ( $this->is_stub( $post ) ) {
			$include = false;
		}

		/**
		 * Final per-item override for llms.txt / llms-full.txt inclusion.
		 *
		 * @param bool    $include Whether the item will be listed.
		 * @param WP_Post $post    The candidate post/page.
		 */
		return (bool) apply_filters( 'seonix_llmstxt_include_item', $include, $post );
	}

	/**
	 * Best-effort noindex check across the common SEO plugins (same signals
	 * Seonix_IndexNow uses). Absence of any signal = indexable.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_noindex( $post_id ) {
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		if ( 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}
		$rm = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Placeholder detection: publish-status stubs ("Binnenkort…", "Coming
	 * soon", an empty shell awaiting content) that should never be offered to
	 * AI crawlers. A post is a stub when its rendered body is under 40 words
	 * AND it has no hand-written excerpt. Page-builder pages (content lives
	 * outside post_content) and the front page are exempt — their body length
	 * says nothing about the rendered page.
	 *
	 * @param WP_Post $post The post/page object.
	 * @return bool
	 */
	private function is_stub( $post ) {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return false;
		}
		if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			return false;
		}
		// Elementor (and imports from it) keep real content in meta.
		if ( '' !== (string) get_post_meta( $post->ID, '_elementor_data', true ) ) {
			return false;
		}
		$text = $this->clean_text( strip_shortcodes( (string) $post->post_content ) );
		if ( '' === $text ) {
			return true;
		}
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return count( $words ) < 40;
	}

	/**
	 * Utility-page slugs that carry no informational value for AI assistants
	 * (multi-language: EN/NL/DE + WooCommerce endpoints).
	 *
	 * @return string[]
	 */
	private function excluded_page_slugs() {
		// Only UNAMBIGUOUS utility slugs. Deliberately absent: bare "privacy",
		// "cookies", "terms" — those collide with legitimate content pages (a
		// security blog's /privacy/ guide, a bakery's /cookies/, a glossary's
		// /terms/), and a policy page slipping INTO llms.txt is harmless noise
		// while a content page dropping OUT of it is real loss.
		$slugs = array(
			'contact', 'contact-us', 'kontakt', 'contacto',
			'privacy-policy', 'privacybeleid', 'privacyverklaring',
			'datenschutz', 'datenschutzerklaerung', 'datenschutzerklarung',
			'cookie-policy', 'cookiebeleid', 'cookieverklaring',
			'terms-of-use', 'terms-of-service', 'terms-and-conditions',
			'algemene-voorwaarden', 'agb', 'imprint', 'impressum',
			'cart', 'winkelwagen', 'warenkorb', 'checkout', 'afrekenen', 'kasse',
			'my-account', 'mijn-account', 'mein-konto', 'login', 'register',
			'thank-you', 'bedankt', 'danke',
		);

		/**
		 * Page slugs excluded from llms.txt / llms-full.txt.
		 *
		 * @param string[] $slugs Exact post_name matches to skip.
		 */
		return (array) apply_filters( 'seonix_llmstxt_excluded_page_slugs', $slugs );
	}

	/**
	 * The single category a post is listed under.
	 *
	 * Honors the SEO plugin's "primary category" pick (Rank Math / Yoast)
	 * when it is set and still assigned; otherwise the most specific
	 * (deepest) assigned category, ties broken by lowest term_id so output
	 * is deterministic.
	 *
	 * @param WP_Post $post The post object.
	 * @return WP_Term|null
	 */
	private function primary_category( $post ) {
		$terms = get_the_terms( $post, 'category' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		foreach ( array( 'rank_math_primary_category', '_yoast_wpseo_primary_category' ) as $meta_key ) {
			$primary_id = (int) get_post_meta( $post->ID, $meta_key, true );
			if ( $primary_id > 0 ) {
				foreach ( $terms as $term ) {
					if ( (int) $term->term_id === $primary_id ) {
						return $term;
					}
				}
			}
		}

		$best       = null;
		$best_depth = -1;
		foreach ( $terms as $term ) {
			$depth = count( get_ancestors( $term->term_id, 'category' ) );
			if ( $depth > $best_depth
				|| ( $depth === $best_depth && $best && (int) $term->term_id < (int) $best->term_id ) ) {
				$best       = $term;
				$best_depth = $depth;
			}
		}
		return $best;
	}

	/**
	 * The description shown after a link: SEO meta description first (it is a
	 * complete, hand- or AI-written sentence sized for exactly this job),
	 * auto-excerpt as fallback. Values containing SEO-plugin template
	 * variables (%sitename%, %%excerpt%% …) are unusable as plain text.
	 *
	 * @param WP_Post $post The post/page object.
	 * @return string
	 */
	private function item_description( $post ) {
		$desc = '';
		if ( class_exists( 'Seonix_Meta_Bridge' ) && class_exists( 'Seonix_SEO_Engine' ) ) {
			$engine = Seonix_SEO_Engine::detect();
			if ( null !== $engine ) {
				if ( Seonix_SEO_Engine::AIOSEO === $engine ) {
					// Mirror meta only: the model API costs a query per post.
					$desc = (string) get_post_meta( $post->ID, '_aioseo_description', true );
				} else {
					$key = Seonix_SEO_Engine::post_desc_key( $engine );
					if ( null !== $key ) {
						$desc = (string) get_post_meta( $post->ID, $key, true );
					}
				}
			}
			if ( '' === $desc ) {
				$own  = Seonix_Meta_Bridge::read_own( $post->ID );
				$desc = $own['meta_description'];
			}
			if ( '' !== $desc && preg_match( '/%%?[a-z_]+%%?/i', $desc ) ) {
				$desc = '';
			}
		}
		if ( '' !== $desc ) {
			return $this->clean_text( $desc );
		}
		return $this->fallback_description( $post );
	}

	/**
	 * Build a description from the post body when no SEO meta description
	 * exists. Differences from core's get_the_excerpt(), both audit findings
	 * (virus.nl v3):
	 * - headings are stripped BEFORE excerpting — core keeps their text, so
	 *   every description opened with the post's first (title-like) heading;
	 * - the cut lands on a sentence boundary, not the mid-word "…" of a double
	 *   word-trim (core's 55 words, then our 30).
	 *
	 * @param WP_Post $post The post/page object.
	 * @return string Sentence-bounded plain-text description ('' when empty).
	 */
	private function fallback_description( $post ) {
		$content = strip_shortcodes( (string) $post->post_content );
		// Drop headings with their text (h1-h6) so the description starts at
		// the first real paragraph, and drop Gutenberg block comments.
		$content = preg_replace( '/<h[1-6][^>]*>.*?<\/h[1-6]>/is', ' ', $content );
		$content = preg_replace( '/<!--.*?-->/s', ' ', $content );
		$text    = $this->clean_text( $content );
		if ( '' === $text ) {
			return '';
		}

		// Accumulate whole sentences up to ~220 chars; always keep at least
		// one. A first sentence longer than 300 chars falls back to a
		// word-boundary trim (rare degenerate case: no punctuation at all).
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $sentences ) ) {
			return $this->clean_text( wp_trim_words( $text, 30, '…' ) );
		}
		$out = '';
		foreach ( $sentences as $sentence ) {
			if ( '' === $out ) {
				$out = $sentence;
				continue;
			}
			if ( mb_strlen( $out . ' ' . $sentence ) > 220 ) {
				break;
			}
			$out .= ' ' . $sentence;
		}
		if ( mb_strlen( $out ) > 300 ) {
			return $this->clean_text( wp_trim_words( $out, 30, '…' ) );
		}
		return $out;
	}

	/**
	 * Format a single link line for the index.
	 *
	 * @param WP_Post $post The post/page object.
	 * @return string Formatted markdown link line.
	 */
	private function format_link_line( $post ) {
		$line = '- [' . $this->clean_text( $post->post_title ) . '](' . esc_url_raw( get_permalink( $post ) ) . ')';
		$desc = $this->item_description( $post );
		if ( $desc ) {
			$line .= ': ' . $desc;
		}
		return $line;
	}

	/**
	 * Build the llms-full.txt content.
	 *
	 * Applies the same should_include() curation as the index. That matters
	 * beyond noise here: post_status=publish INCLUDES password-protected
	 * posts, and this builder dumps full post_content as markdown — without
	 * the filter it published protected content to any crawler, bypassing
	 * the password entirely.
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
		$posts = array_values( array_filter( $posts, array( $this, 'should_include' ) ) );

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
