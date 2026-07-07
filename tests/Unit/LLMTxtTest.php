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
}
