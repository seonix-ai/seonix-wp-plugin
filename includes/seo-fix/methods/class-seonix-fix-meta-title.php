<?php
/**
 * Fix method: meta_title.
 *
 * Sets the SEO plugin's post title meta. Suggested string is generated
 * upstream by the Seonix backend (AI-shortened to ≤60 chars while preserving
 * meaning).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Meta_Title extends Seonix_Fix_Single_Meta {

	public function key(): string {
		return 'meta_title';
	}

	/**
	 * Static fallback (the abstract contract). The live key is chosen per
	 * active SEO plugin in resolve_meta_key().
	 */
	protected function meta_key(): string {
		$key = Seonix_SEO_Engine::post_title_key();
		return null !== $key ? $key : '_yoast_wpseo_title';
	}

	protected function target_type(): string {
		return 'post';
	}

	/**
	 * Write to the post-title key of whichever SEO plugin is active (Yoast →
	 * _yoast_wpseo_title, Rank Math → rank_math_title). When neither stores the
	 * title in postmeta we can write (AIOSEO's custom table, or no SEO plugin),
	 * fail loud with 412 so the dashboard never writes meta no plugin reads.
	 *
	 * @return string|\WP_Error
	 */
	protected function resolve_meta_key() {
		$key = Seonix_SEO_Engine::post_title_key();
		if ( null === $key ) {
			return new WP_Error(
				'no_seo_plugin',
				'No supported SEO plugin (Yoast or Rank Math) is active to store the SEO title.',
				array( 'status' => 412 )
			);
		}
		return $key;
	}

	/**
	 * Advertised to the /capabilities handshake: the dashboard only offers this
	 * fix when an SEO plugin that stores the title in postmeta is active.
	 */
	public function is_available(): bool {
		return null !== Seonix_SEO_Engine::post_title_key();
	}
}
