<?php
/**
 * Per-post page-builder detection.
 *
 * The broken_link / broken_image fix methods rewrite a post's post_content.
 * Page builders (Elementor, Beaver, Brizy) DON'T keep the rendered layout in
 * post_content — they store it in their own postmeta blob and regenerate
 * post_content as a throwaway cache. Rewriting post_content there is a no-op on
 * the visible page (the builder re-renders from its meta) and, worse, a partial
 * rewrite of a builder's own serialized/escaped data can corrupt the layout.
 *
 * So before any post_content write, a fix asks this detector "does a builder own
 * this post?" and SKIPS the write when it does, surfacing the post for manual /
 * builder-aware handling instead. This keeps the fix safe on ANY WordPress site,
 * which is the whole point of running it unattended across every customer.
 *
 * Detection is per-POST (postmeta lookup), not per-SITE (is_plugin_active): a
 * site can run Elementor yet still have classic/Gutenberg posts that ARE safe to
 * rewrite — only the builder-owned posts must be skipped. We deliberately use a
 * cheap meta_key presence check, never a post_content scan.
 *
 * Conservative by design: we'd rather skip a post the fix could technically have
 * handled (Divi/WPBakery keep shortcodes in post_content, so a plain-URL rewrite
 * would often work) than risk corrupting one. "Issue left for manual review" is
 * always safe; "layout broken on a live site" is not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Builder_Detector {

	/**
	 * Postmeta signals that a page builder owns a post's layout. Map of
	 * meta_key => optional expected value (null = presence of a non-empty value
	 * is enough). Chosen to be the stable, documented per-post markers each
	 * builder writes when its editor is used on that post.
	 *
	 * @var array<string,?string>
	 */
	const BUILDER_META = array(
		'_elementor_edit_mode' => 'builder', // Elementor: 'builder' when the Elementor editor owns the page.
		'_et_pb_use_builder'   => 'on',      // Divi Builder.
		'_fl_builder_enabled'  => null,      // Beaver Builder: '1' when enabled.
		'_wpb_vc_js_status'    => null,      // WPBakery (Visual Composer) backend editor marker.
		'brizy_post_uid'       => null,      // Brizy.
	);

	/**
	 * Does a page builder own this post's layout?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function post_uses_builder( int $post_id ): bool {
		$is_builder = self::detect( $post_id );

		/**
		 * Filter the builder verdict for a post. Lets a site force a post to be
		 * treated as (not) builder-owned — e.g. a builder we don't map yet, or a
		 * false positive from a leftover meta row.
		 *
		 * @param bool $is_builder
		 * @param int  $post_id
		 */
		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'seonix_post_uses_builder', $is_builder, $post_id );
		}
		return $is_builder;
	}

	/**
	 * Raw detection (no filter) — separated so the filter can't recurse.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function detect( int $post_id ): bool {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		foreach ( self::BUILDER_META as $meta_key => $expected ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( null === $expected ) {
				// Presence check: any non-empty value counts. Guard against the
				// literal empty-string / '0' rows WordPress returns for absent meta.
				if ( '' !== $value && false !== $value && null !== $value ) {
					return true;
				}
			} elseif ( (string) $value === $expected ) {
				return true;
			}
		}
		return false;
	}
}
