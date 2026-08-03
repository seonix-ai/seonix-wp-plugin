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

	/**
	 * Shell-escaped CLI edits ("Jetzt anfragen\!" — bash history-expansion
	 * escape saved literally) and markdown-escaping editors leave backslashes
	 * in meta descriptions. They are meaningless in plain text and used to be
	 * copied verbatim into llms.txt (found on a live site, 2026-08-04).
	 */
	public function test_strips_stray_escape_backslashes(): void {
		$this->assertSame( 'Jetzt anfragen!', $this->cleanText( 'Jetzt anfragen\!' ) );
		$this->assertSame( 'Aufbau & Transport', $this->cleanText( 'Aufbau \& Transport' ) );
		$this->assertSame( 'Preis ab 50$ pro Stunde', $this->cleanText( 'Preis ab 50\$ pro Stunde' ) );
		$this->assertSame( 'Wirklich? Ja!', $this->cleanText( 'Wirklich\? Ja\!' ) );
	}

	/** A backslash that carries meaning (\\ or before a word char) survives. */
	public function test_keeps_meaningful_backslashes(): void {
		$this->assertSame( 'C:\\Users\\Docs', $this->cleanText( 'C:\\Users\\Docs' ) );
		$this->assertSame( 'regex \d+ matcht Ziffern', $this->cleanText( 'regex \d+ matcht Ziffern' ) );
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
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://virus.test' . $path );
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
		// Curation: the stub is gone, the real page stays in ## Pages.
		$this->assertStringNotContainsString( 'Binnenkort', $out );
		$this->assertStringContainsString( 'https://virus.test/over-ons/', $out );
		$this->assertStringContainsString( '## Pages', $out );
		// Utility pages move to ## Optional (llmstxt.org spec) instead of
		// vanishing, and the section links the full-text companion file.
		$this->assertStringContainsString( '## Optional', $out );
		$this->assertSame( 1, substr_count( $out, 'https://virus.test/contact/' ) );
		$this->assertGreaterThan(
			strpos( $out, '## Optional' ),
			strpos( $out, 'https://virus.test/contact/' ),
			'contact page must be listed inside ## Optional, not in the main map'
		);
		$this->assertStringContainsString( 'https://virus.test/llms-full.txt', $out );
	}

	public function test_build_index_folds_single_link_sections_into_ancestor(): void {
		$malware  = $this->makeTerm( 7, 'Malware' );
		$rootkits = $this->makeTerm( 12, 'Rootkits' );
		$spyware  = $this->makeTerm( 20, 'Spyware' );

		// Two posts land in Malware; ONE lands in the child Rootkits and ONE in
		// Spyware (no ancestor section) — Rootkits folds up, Spyware goes to Posts.
		$a = $this->makePost( array( 'ID' => 1, 'post_name' => 'malware-a', 'post_title' => 'Malware A' ) );
		$b = $this->makePost( array( 'ID' => 2, 'post_name' => 'malware-b', 'post_title' => 'Malware B' ) );
		$c = $this->makePost( array( 'ID' => 3, 'post_name' => 'rootkit-c', 'post_title' => 'Rootkit C' ) );
		$d = $this->makePost( array( 'ID' => 4, 'post_name' => 'spyware-d', 'post_title' => 'Spyware D' ) );

		Functions\when( 'get_bloginfo' )->alias( static fn ( $k ) => 'name' === $k ? 'Virus Test' : '' );
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( $a, $b, $c, $d ) {
				return 'page' === ( $args['post_type'] ?? 'post' ) ? array() : array( $a, $b, $c, $d );
			}
		);
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'strip_shortcodes' )->alias( static fn ( $s ) => (string) $s );
		Functions\when( 'get_post_meta' )->alias( static fn ( $id, $key = '', $single = false ) => $single ? '' : array() );
		Functions\when( 'get_the_terms' )->alias(
			static function ( $post ) use ( $malware, $rootkits, $spyware ) {
				switch ( (int) $post->ID ) {
					case 1:
					case 2:
						return array( $malware );
					case 3:
						return array( $rootkits );
					default:
						return array( $spyware );
				}
			}
		);
		Functions\when( 'get_ancestors' )->alias(
			static fn ( $term_id ) => 12 === $term_id ? array( 7 ) : array()
		);
		Functions\when( 'get_term' )->alias(
			static function ( $term_id ) use ( $malware ) {
				return 7 === (int) $term_id ? $malware : null;
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static fn ( $post ) => 'https://virus.test/' . $post->post_name . '/'
		);
		Functions\when( 'esc_url_raw' )->alias( static fn ( $u ) => $u );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://virus.test' . $path );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_trim_words' )->alias( static fn ( $t ) => $t );
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$m = new \ReflectionMethod( \Seonix_LLMTxt::class, 'build_index' );
		$m->setAccessible( true );
		$out = $m->invoke( new \Seonix_LLMTxt() );

		// No one-link "## Rootkits" / "## Spyware" sections survive.
		$this->assertStringNotContainsString( '## Rootkits', $out );
		$this->assertStringNotContainsString( '## Spyware', $out );
		// The rootkit post folded into its ancestor Malware section.
		$this->assertStringContainsString( '## Malware', $out );
		$malware_at = strpos( $out, '## Malware' );
		$posts_at   = strpos( $out, '## Posts' );
		$rootkit_at = strpos( $out, 'https://virus.test/rootkit-c/' );
		$spyware_at = strpos( $out, 'https://virus.test/spyware-d/' );
		$this->assertNotFalse( $posts_at, 'ancestor-less single must produce the Posts tail' );
		$this->assertGreaterThan( $malware_at, $rootkit_at );
		$this->assertLessThan( $posts_at, $rootkit_at, 'rootkit post belongs to Malware, not Posts' );
		$this->assertGreaterThan( $posts_at, $spyware_at, 'spyware post belongs to the Posts tail' );
	}

	public function test_description_rejects_template_variables(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			static fn ( $id, $key = '', $single = false ) => '_yoast_wpseo_metadesc' === $key
				? '%%excerpt%% - %%sitename%%'
				: ''
		);
		Functions\when( 'strip_shortcodes' )->alias( static fn ( $s ) => (string) $s );
		$post = $this->makePost( array(
			'post_content' => '<h2>Wat is een rootkit? | Diepgaande bescherming</h2><p>Eerste zin van het artikel. Tweede zin met wat meer detail erin.</p>',
		) );
		// Template-variable meta is rejected; the fallback description comes
		// from the body WITHOUT the leading (title-like) heading and ends on a
		// sentence boundary.
		$desc = $this->itemDescription( $post );
		$this->assertSame( 'Eerste zin van het artikel. Tweede zin met wat meer detail erin.', $desc );
		$this->assertStringNotContainsString( 'rootkit? |', $desc );
	}

	public function test_fallback_description_cuts_on_sentence_boundary(): void {
		Functions\when( 'strip_shortcodes' )->alias( static fn ( $s ) => (string) $s );
		$long = str_repeat( 'Deze zin bevat een aantal woorden en eindigt netjes. ', 10 );
		$post = $this->makePost( array( 'post_content' => '<p>' . $long . '</p>' ) );

		$m = new \ReflectionMethod( \Seonix_LLMTxt::class, 'fallback_description' );
		$m->setAccessible( true );
		$desc = $m->invoke( new \Seonix_LLMTxt(), $post );

		$this->assertLessThanOrEqual( 300, mb_strlen( $desc ) );
		$this->assertMatchesRegularExpression( '/[.!?]$/u', $desc, 'description must end at a sentence boundary, not mid-word with …' );
	}
}
