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

	protected function meta_key(): string {
		return '_yoast_wpseo_title';
	}

	protected function target_type(): string {
		return 'post';
	}
}
