<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Metabox;

/**
 * Covers Seonix_Metabox::extract_links — the post-body link inventory shown in
 * the metabox Links section (and mirrored client-side by editor-panel.js).
 *
 * Contract: relative + same-host anchors are internal, other hosts external,
 * fragment/mailto/tel/javascript/data are skipped, and both lists are
 * de-duplicated by href. www. is ignored when comparing hosts.
 */
final class LinksExtractionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->justReturn( 'https://wohnartstudio.de' );
		// wp_parse_url is provided by tests/bootstrap.php; wp_strip_all_tags is
		// not, so alias it to strip_tags for the anchor text.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array{internal:array,external:array} */
	private function extract( string $html ): array {
		$obj = ( new \ReflectionClass( Seonix_Metabox::class ) )->newInstanceWithoutConstructor();
		$m   = new \ReflectionMethod( Seonix_Metabox::class, 'extract_links' );
		$m->setAccessible( true );
		return $m->invoke( $obj, $html );
	}

	public function test_relative_and_same_host_links_are_internal(): void {
		$r = $this->extract(
			'<a href="/blog">Blog</a>'
			. '<a href="https://wohnartstudio.de/x">X</a>'
			. '<a href="https://www.wohnartstudio.de/y">Y</a>'
		);
		$this->assertCount( 3, $r['internal'] );
		$this->assertCount( 0, $r['external'] );
	}

	public function test_other_host_links_are_external_with_anchor(): void {
		$r = $this->extract( '<a href="https://example.com/a">Example</a>' );
		$this->assertCount( 0, $r['internal'] );
		$this->assertCount( 1, $r['external'] );
		$this->assertSame( 'https://example.com/a', $r['external'][0]['href'] );
		$this->assertSame( 'Example', $r['external'][0]['anchor'] );
	}

	public function test_skips_fragment_mailto_tel_and_js(): void {
		$r = $this->extract(
			'<a href="#top">Top</a><a href="mailto:a@b.com">Mail</a>'
			. '<a href="tel:+49">Call</a><a href="javascript:void(0)">JS</a>'
		);
		$this->assertCount( 0, $r['internal'] );
		$this->assertCount( 0, $r['external'] );
	}

	public function test_dedupes_by_href(): void {
		$r = $this->extract(
			'<a href="/blog">One</a><a href="/blog">Two</a>'
			. '<a href="https://example.com">A</a><a href="https://example.com">B</a>'
		);
		$this->assertCount( 1, $r['internal'] );
		$this->assertCount( 1, $r['external'] );
	}

	public function test_empty_body_yields_no_links(): void {
		$r = $this->extract( '' );
		$this->assertSame( array(), $r['internal'] );
		$this->assertSame( array(), $r['external'] );
	}
}
