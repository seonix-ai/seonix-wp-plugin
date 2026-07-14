<?php
/**
 * Standalone SEO meta output — only when no dedicated SEO plugin owns <head>.
 *
 * Mirrors the Seonix_Schema pattern: an output mode option ('auto' default /
 * 'on' / 'off') where 'auto' self-suppresses as soon as ANY known SEO plugin
 * (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, Squirrly) is active,
 * so Seonix never double-emits a description/OG block next to an engine's.
 *
 * What it emits for singular posts that carry Seonix SEO meta:
 *   - document <title> (via the pre_get_document_title filter, not echo)
 *   - <meta name="description">
 *   - Open Graph: og:type/title/description/url/site_name/locale (+ og:image
 *     with dimensions when a featured image exists) + article times
 *   - Twitter: card/title/description (+ image)
 *
 * Deliberately NOT emitted:
 *   - <link rel="canonical"> — WordPress core already prints one for singular
 *     views (rel_canonical on wp_head); emitting our own would duplicate it.
 *   - <meta name="robots"> — core's wp_robots handles it; a meta plugin must
 *     never risk flipping a page to noindex.
 *
 * Everything is wrapped in `<!-- Seonix SEO -->` markers and every tag group
 * has a kill-switch filter, so a theme/agency can resolve any conflict without
 * patching the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Meta_Renderer {

	const OPTION_MODE = 'seonix_meta_mode';

	/**
	 * Output mode: 'auto' (default), 'on', or 'off'. Unknown values clamp to
	 * 'auto' so a corrupt option never silently force-enables output next to
	 * an SEO plugin.
	 *
	 * @return string
	 */
	public static function mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, 'auto' );
		if ( ! in_array( $mode, array( 'auto', 'on', 'off' ), true ) ) {
			return 'auto';
		}
		return $mode;
	}

	/**
	 * Decide whether Seonix should emit its own head meta, given the mode and
	 * the presence of a competing SEO plugin.
	 *
	 * @return bool
	 */
	public static function should_output(): bool {
		switch ( self::mode() ) {
			case 'off':
				$decision = false;
				break;
			case 'on':
				$decision = true;
				break;
			case 'auto':
			default:
				// Only emit when no dedicated SEO plugin already owns <head>.
				$decision = array() === Seonix_SEO_Engine::detect_all();
				break;
		}

		/**
		 * Final gate for Seonix's own meta output.
		 *
		 * @param bool $decision Computed decision (mode + engine detection).
		 */
		return (bool) apply_filters( 'seonix_meta_output_enabled', $decision );
	}

	/**
	 * Register front-end hooks. Called once from seonix_init().
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 1 mirrors where Yoast/Rank Math mount their head containers,
		// so caching/optimizer plugins see the tags in the same early slot.
		add_action( 'wp_head', array( $this, 'render_head' ), 1 );
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 15 );
	}

	/**
	 * The post ID meta should be rendered for, or 0 when this request is not
	 * an eligible singular view.
	 *
	 * @return int
	 */
	private function eligible_post_id(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		$post_id = get_queried_object_id();
		return $post_id > 0 ? (int) $post_id : 0;
	}

	/**
	 * pre_get_document_title: serve the Seonix SEO title for singular views.
	 * Returning '' hands control back to WordPress' normal title build.
	 *
	 * @param string $title Prior filter value ('' unless someone else filled it).
	 * @return string
	 */
	public function filter_document_title( $title ) {
		// Respect an earlier filter that already produced a title.
		if ( is_string( $title ) && '' !== $title ) {
			return $title;
		}
		if ( ! self::should_output() ) {
			return $title;
		}
		$post_id = $this->eligible_post_id();
		if ( ! $post_id ) {
			return $title;
		}
		$seo_title = (string) get_post_meta( $post_id, Seonix_Meta_Bridge::META_TITLE, true );

		/**
		 * Filter the document title Seonix is about to serve. Return '' to
		 * fall back to the theme's title.
		 *
		 * @param string $seo_title Stored Seonix SEO title.
		 * @param int    $post_id   Post being rendered.
		 */
		$seo_title = (string) apply_filters( 'seonix_meta_title', $seo_title, $post_id );

		return '' !== $seo_title ? wp_strip_all_tags( $seo_title ) : $title;
	}

	/**
	 * wp_head: print description + social meta for singular posts carrying
	 * Seonix SEO meta. No-op when suppressed or when there is nothing to say.
	 *
	 * @return void
	 */
	public function render_head(): void {
		if ( ! self::should_output() ) {
			return;
		}
		$post_id = $this->eligible_post_id();
		if ( ! $post_id ) {
			return;
		}

		$own       = Seonix_Meta_Bridge::read_own( $post_id );
		$seo_title = (string) apply_filters( 'seonix_meta_title', $own['seo_title'], $post_id );

		/**
		 * Filter the meta description Seonix is about to print. Return '' to
		 * suppress the tag.
		 *
		 * @param string $description Stored Seonix meta description.
		 * @param int    $post_id     Post being rendered.
		 */
		$description = (string) apply_filters( 'seonix_meta_description', $own['meta_description'], $post_id );

		/**
		 * Toggle the Open Graph / Twitter block independently of the
		 * description tag.
		 *
		 * @param bool $enabled Default true.
		 * @param int  $post_id Post being rendered.
		 */
		$social_enabled = (bool) apply_filters( 'seonix_meta_social_enabled', true, $post_id );

		$og_title = '' !== $seo_title ? $seo_title : get_the_title( $post_id );

		$lines = array();

		if ( '' !== $description ) {
			$lines[] = '<meta name="description" content="' . esc_attr( $description ) . '" />';
		}

		if ( $social_enabled && ( '' !== $og_title || '' !== $description ) ) {
			$permalink = get_permalink( $post_id );
			$lines[]   = '<meta property="og:type" content="article" />';
			if ( '' !== $og_title ) {
				$lines[] = '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />';
			}
			if ( '' !== $description ) {
				$lines[] = '<meta property="og:description" content="' . esc_attr( $description ) . '" />';
			}
			if ( $permalink ) {
				$lines[] = '<meta property="og:url" content="' . esc_url( $permalink ) . '" />';
			}
			$site_name = get_bloginfo( 'name' );
			if ( '' !== $site_name ) {
				$lines[] = '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />';
			}
			$locale = get_locale();
			if ( '' !== $locale ) {
				$lines[] = '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />';
			}

			$published = get_post_time( 'c', true, $post_id );
			$modified  = get_post_modified_time( 'c', true, $post_id );
			if ( $published ) {
				$lines[] = '<meta property="article:published_time" content="' . esc_attr( $published ) . '" />';
			}
			if ( $modified && $modified !== $published ) {
				$lines[] = '<meta property="article:modified_time" content="' . esc_attr( $modified ) . '" />';
			}

			$image_id = get_post_thumbnail_id( $post_id );
			$image    = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$lines[] = '<meta property="og:image" content="' . esc_url( $image[0] ) . '" />';
				if ( ! empty( $image[1] ) && ! empty( $image[2] ) ) {
					$lines[] = '<meta property="og:image:width" content="' . esc_attr( (string) $image[1] ) . '" />';
					$lines[] = '<meta property="og:image:height" content="' . esc_attr( (string) $image[2] ) . '" />';
				}
				$alt = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
				if ( '' !== $alt ) {
					$lines[] = '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '" />';
				}
			}

			$lines[] = '<meta name="twitter:card" content="summary_large_image" />';
			if ( '' !== $og_title ) {
				$lines[] = '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '" />';
			}
			if ( '' !== $description ) {
				$lines[] = '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />';
			}
			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$lines[] = '<meta name="twitter:image" content="' . esc_url( $image[0] ) . '" />';
			}
		}

		if ( empty( $lines ) ) {
			return;
		}

		// Identifying markers make duplicate-tag debugging tractable, mirroring
		// what every SEO engine does. Each line is esc_attr/esc_url-escaped
		// above.
		echo "\n<!-- Seonix SEO -->\n" . implode( "\n", $lines ) . "\n<!-- / Seonix SEO -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every line built above from escaped parts.
	}
}
