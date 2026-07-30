<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Covers Seonix_LLMTxt::clean_text — the normalizer that fixes the llms.txt
 * output defects: HTML-entity decoding ("Tipps &amp; Tricks" → "Tipps &
 * Tricks"), zero-width / soft-hyphen stripping (invisible chars copied from WP
 * content), and whitespace collapsing. Before this the plugin emitted literal
 * "&amp;amp;" and stray U+200B characters.
 */
final class LLMTxtTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// clean_text() strips tags first; mirror production with strip_tags.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $s ) => trim( strip_tags( (string) $s ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cleanText( string $in ): string {
		$m = new \ReflectionMethod( \Seonix_LLMTxt::class, 'clean_text' );
		$m->setAccessible( true );
		return $m->invoke( new \Seonix_LLMTxt(), $in );
	}

	public function test_decodes_html_entities(): void {
		$this->assertSame( 'Tipps & Tricks', $this->cleanText( 'Tipps &amp; Tricks' ) );
	}

	public function test_decodes_double_encoded_ampersand(): void {
		// WP display filters can double-encode; the on-output esc_html made it
		// worse. clean_text decodes one layer; combined with removing esc_html
		// on output the live file no longer shows &amp;amp;.
		$this->assertSame( 'A & B', $this->cleanText( 'A &amp; B' ) );
	}

	public function test_strips_zero_width_space(): void {
		// "Moebelmontage" followed by U+200B (zero-width space, UTF-8 E2 80 8B).
		$this->assertSame( 'Moebelmontage', $this->cleanText( "Moebelmontage\xE2\x80\x8B" ) );
	}

	public function test_strips_soft_hyphen(): void {
		// U+00AD soft hyphen (UTF-8 C2 AD) between letters.
		$this->assertSame( 'ab', $this->cleanText( "a\xC2\xADb" ) );
	}

	public function test_collapses_whitespace(): void {
		$this->assertSame( 'a b c', $this->cleanText( "a\n  b\tc" ) );
	}

	public function test_strips_tags(): void {
		$this->assertSame( 'Hello world', $this->cleanText( '<b>Hello</b> world' ) );
	}

	// ------------------------------------------------------------------
	// Curation: should_include / primary_category / item_description
	// (dedup + noise filtering — a 71-post site used to emit ~380 lines,
	// stubs like "Binnenkort…" and /contact/ included).
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $over Field overrides.
	 */
	private function makePost( array $over = array() ): \stdClass {
		$post = new \stdClass();
		$post->ID            = $over['ID'] ?? 42;
		$post->post_type     = $over['post_type'] ?? 'post';
		$post->post_name     = $over['post_name'] ?? 'some-article';
		$post->post_password = $over['post_password'] ?? '';
		$post->post_excerpt  = $over['post_excerpt'] ?? '';
		$post->post_content  = $over['post_content'] ?? str_repeat( 'word ', 500 );
		$post->post_title    = $over['post_title'] ?? 'Some Article';
		return $post;
	}

	/** Default WP mocks for inclusion checks: indexable, no meta, no front page. */
	private function mockIncludeEnv( array $meta = array() ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key = '', $single = false ) use ( $meta ) {
				return $meta[ $key ] ?? ( $single ? '' : array() );
			}
		);
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'strip_shortcodes' )->alias( static fn ( $s ) => preg_replace( '/\[[^\]]*\]/', '', (string) $s ) );
	}

	private function shouldInclude( \stdClass $post ): bool {
		return ( new \Seonix_LLMTxt() )->should_include( $post );
	}

	public function test_excludes_password_protected(): void {
		$this->mockIncludeEnv();
		$this->assertFalse( $this->shouldInclude( $this->makePost( array( 'post_password' => 'secret' ) ) ) );
	}

	public function test_excludes_rank_math_noindex(): void {
		$this->mockIncludeEnv( array( 'rank_math_robots' => array( 'noindex', 'follow' ) ) );
		$this->assertFalse( $this->shouldInclude( $this->makePost() ) );
	}

	public function test_excludes_yoast_noindex(): void {
		$this->mockIncludeEnv( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
		$this->assertFalse( $this->shouldInclude( $this->makePost() ) );
	}

	public function test_excludes_utility_page_slugs(): void {
		$this->mockIncludeEnv();
		foreach ( array( 'contact', 'privacy-policy', 'winkelwagen', 'impressum' ) as $slug ) {
			$this->assertFalse(
				$this->shouldInclude( $this->makePost( array( 'post_type' => 'page', 'post_name' => $slug ) ) ),
				"page slug '{$slug}' must be excluded"
			);
		}
	}

	public function test_utility_slug_only_applies_to_pages(): void {
		$this->mockIncludeEnv();
		// A POST whose slug happens to be "contact" is still content.
		$this->assertTrue( $this->shouldInclude( $this->makePost( array( 'post_name' => 'contact' ) ) ) );
	}

	public function test_excludes_placeholder_stub(): void {
		$this->mockIncludeEnv();
		$stub = $this->makePost( array( 'post_content' => 'Binnenkort…' ) );
		$this->assertFalse( $this->shouldInclude( $stub ) );
	}

	public function test_stub_with_manual_excerpt_is_kept(): void {
		$this->mockIncludeEnv();
		$post = $this->makePost( array( 'post_content' => 'Short.', 'post_excerpt' => 'Hand-written summary.' ) );
		$this->assertTrue( $this->shouldInclude( $post ) );
	}

	public function test_stub_check_exempts_elementor_pages(): void {
		// Elementor keeps content in meta; a near-empty post_content is normal.
		$this->mockIncludeEnv( array( '_elementor_data' => '[{"id":"abc"}]' ) );
		$post = $this->makePost( array( 'post_content' => '' ) );
		$this->assertTrue( $this->shouldInclude( $post ) );
	}

	public function test_includes_normal_post(): void {
		$this->mockIncludeEnv();
		$this->assertTrue( $this->shouldInclude( $this->makePost() ) );
	}

	private function primaryCategory( \stdClass $post ): ?object {
		$m = new \ReflectionMethod( \Seonix_LLMTxt::class, 'primary_category' );
		$m->setAccessible( true );
		return $m->invoke( new \Seonix_LLMTxt(), $post );
	}

	private function makeTerm( int $id, string $name ): \stdClass {
		$t          = new \stdClass();
		$t->term_id = $id;
		$t->name    = $name;
		return $t;
	}

	public function test_primary_category_honors_rank_math_pick(): void {
		$malware = $this->makeTerm( 7, 'Malware' );
		$rootkit = $this->makeTerm( 12, 'Rootkits' );
		Functions\when( 'get_the_terms' )->justReturn( array( $malware, $rootkit ) );
		Functions\when( 'get_post_meta' )->alias(
			static fn ( $id, $key = '', $single = false ) => 'rank_math_primary_category' === $key ? 12 : ''
		);
		$this->assertSame( 12, $this->primaryCategory( $this->makePost() )->term_id );
	}

	public function test_primary_category_falls_back_to_deepest(): void {
		$parent = $this->makeTerm( 7, 'Malware' );
		$child  = $this->makeTerm( 12, 'Rootkits' );
		Functions\when( 'get_the_terms' )->justReturn( array( $parent, $child ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_ancestors' )->alias(
			static fn ( $term_id ) => 12 === $term_id ? array( 7 ) : array()
		);
		$this->assertSame( 12, $this->primaryCategory( $this->makePost() )->term_id );
	}

	private function itemDescription( \stdClass $post ): string {
		$m = new \ReflectionMethod( \Seonix_LLMTxt::class, 'item_description' );
		$m->setAccessible( true );
		return $m->invoke( new \Seonix_LLMTxt(), $post );
	}

	public function test_description_prefers_seo_meta_over_excerpt(): void {
		// FakeYoast (bootstrap) makes Seonix_SEO_Engine::detect() return yoast;
		// detect_all() still probes the other engines via get_option.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			static fn ( $id, $key = '', $single = false ) => '_yoast_wpseo_metadesc' === $key
				? 'A complete, hand-written meta description.'
				: ''
		);
		Functions\when( 'get_the_excerpt' )->justReturn( 'Auto excerpt that would get trimmed' );
		$this->assertSame(
			'A complete, hand-written meta description.',
			$this->itemDescription( $this->makePost() )
		);
	}

	// ------------------------------------------------------------------
	// build_index end-to-end: dedup + grouping + filtering working together.
	// The old builder ran one hierarchy-inclusive query per category, so a
	// post in a child category was emitted in EVERY ancestor section too
	// (71 posts → ~380 lines on a real 52-category site).
	// ------------------------------------------------------------------

	public function test_build_index_lists_each_post_once_under_primary_category(): void {
		$malware  = $this->makeTerm( 7, 'Malware' );
		$rootkits = $this->makeTerm( 12, 'Rootkits' );

		$postA = $this->makePost( array( 'ID' => 1, 'post_name' => 'rootkit-artikel', 'post_title' => 'Rootkit Artikel' ) );
		$postB = $this->makePost( array( 'ID' => 2, 'post_name' => 'malware-scan', 'post_title' => 'Malware Scan' ) );
		$stub  = $this->makePost( array( 'ID' => 3, 'post_name' => 'baiting', 'post_title' => 'Baiting', 'post_content' => 'Binnenkort…' ) );
		$contact = $this->makePost( array( 'ID' => 4, 'post_type' => 'page', 'post_name' => 'contact', 'post_title' => 'Contact' ) );
		$about   = $this->makePost( array( 'ID' => 5, 'post_type' => 'page', 'post_name' => 'over-ons', 'post_title' => 'Over Ons' ) );

		Functions\when( 'get_bloginfo' )->alias(
			static fn ( $k ) => 'name' === $k ? 'Virus Test' : 'Testbeschrijving'
		);
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( $postA, $postB, $stub, $contact, $about ) {
				return 'page' === ( $args['post_type'] ?? 'post' )
					? array( $contact, $about )
					: array( $postA, $postB, $stub );
			}
		);
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'strip_shortcodes' )->alias( static fn ( $s ) => (string) $s );
		// Post A pins Rootkits as Rank Math primary; post B falls back to depth.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key = '', $single = false ) {
				if ( 1 === $id && 'rank_math_primary_category' === $key ) {
					return 12;
				}
				return $single ? '' : array();
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( $post ) use ( $malware, $rootkits ) {
				return in_array( (int) $post->ID, array( 1, 2 ), true ) ? array( $malware, $rootkits ) : array();
			}
		);
		Functions\when( 'get_ancestors' )->alias(
			static fn ( $term_id ) => 12 === $term_id ? array( 7 ) : array()
		);
		Functions\when( 'get_permalink' )->alias(
			static fn ( $post ) => 'https://virus.test/' . $post->post_name . '/'
		);
		Functions\when( 'esc_url_raw' )->alias( static fn ( $u ) => $u );
		Functions\when( 'get_the_excerpt' )->justReturn( 'Korte samenvatting van het artikel.' );
		Functions\when( 'wp_trim_words' )->alias( static fn ( $t ) => $t );
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$m = new \ReflectionMethod( \Seonix_LLMTxt::class, 'build_index' );
		$m->setAccessible( true );
		$out = $m->invoke( new \Seonix_LLMTxt() );

		// Each post appears exactly ONCE, in its primary-category section only.
		$this->assertSame( 1, substr_count( $out, 'https://virus.test/rootkit-artikel/' ) );
		$this->assertSame( 1, substr_count( $out, 'https://virus.test/malware-scan/' ) );
		$this->assertStringContainsString( '## Rootkits', $out );
		// Both posts resolve to Rootkits (primary meta / deepest term) — the
		// Malware ancestor section must not exist at all.
		$this->assertStringNotContainsString( "## Malware\n", $out );
		// Curation: stub and contact page are gone, the real page stays.
		$this->assertStringNotContainsString( 'Binnenkort', $out );
		$this->assertStringNotContainsString( '/contact/', $out );
		$this->assertStringContainsString( 'https://virus.test/over-ons/', $out );
		$this->assertStringContainsString( '## Pages', $out );
	}

	public function test_description_rejects_template_variables(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			static fn ( $id, $key = '', $single = false ) => '_yoast_wpseo_metadesc' === $key
				? '%%excerpt%% - %%sitename%%'
				: ''
		);
		Functions\when( 'get_the_excerpt' )->justReturn( 'Real excerpt text.' );
		Functions\when( 'wp_trim_words' )->alias(
			static function ( $text, $num = 55, $more = '…' ) {
				$words = preg_split( '/\s+/', trim( (string) $text ) );
				return count( $words ) > $num ? implode( ' ', array_slice( $words, 0, $num ) ) . $more : $text;
			}
		);
		$this->assertSame( 'Real excerpt text.', $this->itemDescription( $this->makePost() ) );
	}
}
