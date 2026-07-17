<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Broken_Link;
use Seonix_SEO_Fix_History;

/**
 * Builder-safety + escaped-slash coverage for broken_link — the guarantee that
 * the site-wide content rewrite is safe to run unattended on ANY WordPress site.
 *
 *  - A page builder owns the layout in its own postmeta, so the primary write is
 *    skipped and deep-mode skips builder-owned posts row by row.
 *  - The block editor stores link URLs with escaped slashes inside block JSON as
 *    well as in the rendered href; both copies must be rewritten together or the
 *    block goes "invalid" on the next edit.
 */
final class BrokenLinkBuilderSkipTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Broken_Link $method;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Broken_Link( $this->history );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value = null ) => $value );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = null;
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_apply_skips_primary_write_on_builder_owned_post(): void {
		$GLOBALS['wpdb'] = null; // no deep targets

		$post = (object) array(
			'ID'           => 5,
			'post_content' => 'Visit <a href="https://example.com/dead/">our page</a>.',
		);
		Functions\when( 'get_post' )->justReturn( $post );
		// Post 5 is Elementor-owned.
		Functions\when( 'get_post_meta' )->alias( static function ( $id, $key, $single = false ) {
			return ( 5 === (int) $id && '_elementor_edit_mode' === $key ) ? 'builder' : '';
		} );
		// The whole point: the builder-owned post is NEVER written.
		Functions\expect( 'wp_update_post' )->never();

		$r = $this->method->apply( array(
			'post_id' => 5,
			'old_url' => 'https://example.com/dead/',
			'new_url' => 'https://example.com/live/',
			'mode'    => 'rewrite',
		) );

		$this->assertIsArray( $r );
		$this->assertTrue( $r['no_op'], 'A builder-skipped primary with no deep rewrites changed nothing → no_op.' );
		$this->assertArrayHasKey( 'skipped_builder', $r['after'] );
		$this->assertTrue( $r['after']['skipped_builder'] );
		$this->assertSame( 0, $r['after']['replacements'] );
	}

	public function test_deep_rewrite_skips_builder_rows_and_reports_count(): void {
		// Primary post has no occurrence → primary is a no-op; only deep runs.
		$primary = (object) array( 'ID' => 5, 'post_content' => 'nothing here' );
		Functions\when( 'get_post' )->justReturn( $primary );

		// Post 21 is a Divi builder; post 5 and 20 are plain.
		Functions\when( 'get_post_meta' )->alias( static function ( $id, $key, $single = false ) {
			return ( 21 === (int) $id && '_et_pb_use_builder' === $key ) ? 'on' : '';
		} );

		$wpdb = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn ( $s ) => $s );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $q ) => $q );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array(
			(object) array( 'ID' => 20, 'post_content' => '<a href="https://example.com/dead/">a</a>' ),
			(object) array( 'ID' => 21, 'post_content' => '<a href="https://example.com/dead/">b</a>' ),
		) );
		$GLOBALS['wpdb'] = $wpdb;

		$written = array();
		Functions\when( 'wp_update_post' )->alias( function ( $args ) use ( &$written ) {
			$written[] = (int) $args['ID'];
			return (int) $args['ID'];
		} );

		$r = $this->method->apply( array(
			'post_id' => 5,
			'old_url' => 'https://example.com/dead/',
			'new_url' => 'https://example.com/live/',
			'mode'    => 'rewrite',
		) );

		$this->assertSame( array( 20 ), $written, 'Only the non-builder deep post is written; the Divi post is skipped.' );
		$this->assertArrayHasKey( 'deep_rewrites', $r['after'] );
		$this->assertArrayHasKey( 20, $r['after']['deep_rewrites'] );
		$this->assertArrayNotHasKey( 21, $r['after']['deep_rewrites'] );
		$this->assertSame( 1, $r['after']['deep_count'] );
		$this->assertFalse( $r['no_op'] );
	}

	public function test_rewrite_covers_escaped_slash_block_json_and_plain_href(): void {
		// The same internal link appears twice: once in the rendered <a href> and
		// once inside the block-comment JSON with escaped slashes. Rewriting only
		// the plain href would leave the block markup inconsistent with its
		// attributes → "invalid content" on next edit.
		$content = '<!-- wp:paragraph --><p><a href="https://example.com/old/">x</a></p><!-- /wp:paragraph -->'
			. ' meta {"url":"https:\/\/example.com\/old\/","kind":"post-type"}';
		$post = (object) array( 'ID' => 7, 'post_content' => $content );
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // not a builder

		$r = $this->method->dry_run( array(
			'post_id' => 7,
			'old_url' => 'https://example.com/old/',
			'new_url' => 'https://example.com/new/',
			'mode'    => 'rewrite',
		) );

		$after = $r['after']['post_content'];
		$this->assertStringContainsString( 'href="https://example.com/new/"', $after );
		$this->assertStringContainsString( '"url":"https:\/\/example.com\/new\/"', $after, 'The escaped-slash block-JSON copy must be rewritten too.' );
		$this->assertStringNotContainsString( '/old/', $after, 'No stale copy of the old URL may remain, escaped or plain.' );
		$this->assertSame( 2, $r['after']['replacements'], 'Both the plain href and the escaped JSON copy count as replacements.' );
	}
}
