<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Broken_Link;
use Seonix_SEO_Fix_History;

/**
 * Coverage for the broken_link fix method's `remove_link` mode — the fallback
 * the backend uses when the AI matcher can't find a confident redirect target.
 * The anchor wrapper is stripped, the inner text remains, the surrounding
 * paragraph keeps reading naturally.
 */
final class BrokenLinkRemoveModeTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Broken_Link $method;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Broken_Link( $this->history );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_validate_in_remove_link_mode_skips_new_url_requirement(): void {
		$r = $this->method->validate_params( array(
			'post_id' => 5,
			'old_url' => 'https://example.com/dead/',
			'mode'    => 'remove_link',
		) );
		$this->assertTrue( $r, 'remove_link must not require new_url' );
	}

	public function test_validate_rejects_unknown_mode(): void {
		$r = $this->method->validate_params( array(
			'post_id' => 5,
			'old_url' => '/dead/',
			'mode'    => 'destroy',
		) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 'invalid_mode', $r->get_error_code() );
	}

	public function test_remove_link_strips_anchor_with_absolute_href(): void {
		$post = (object) array(
			'ID'           => 5,
			'post_content' => 'Visit <a href="https://example.com/dead/">our old page</a> for more info.',
		);
		Functions\when( 'get_post' )->justReturn( $post );

		$captured = null;
		Functions\when( 'wp_update_post' )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return (int) $args['ID'];
		} );

		// deep_remove_other_posts queries $wpdb. Stub it out so the deep pass is
		// a no-op (no extra rewrites). We test deep path implicitly through the
		// primary-post assertions.
		global $wpdb;
		$wpdb = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn ( $s ) => $s );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( ...$a ) => $a[0] );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$r = $this->method->apply( array(
			'post_id' => 5,
			'old_url' => 'https://example.com/dead/',
			'mode'    => 'remove_link',
		) );

		$this->assertIsArray( $r );
		$this->assertEmpty( $r['no_op'] ?? false );
		$this->assertSame( 1, $r['after']['replacements'] );
		$this->assertNotNull( $captured, 'wp_update_post should have been called' );
		$this->assertStringContainsString( 'Visit our old page for more info.', $captured['post_content'] );
		$this->assertStringNotContainsString( '<a ', $captured['post_content'] );
		$this->assertStringNotContainsString( 'href=', $captured['post_content'] );
	}

	public function test_remove_link_strips_anchor_with_relative_href_when_old_url_on_home_host(): void {
		// Block editor stores internal hrefs as /path/. old_url comes through
		// as an absolute URL — the strip helper must match both.
		$post = (object) array(
			'ID'           => 7,
			'post_content' => 'See <a href="/services-north/">North</a> below.',
		);
		Functions\when( 'get_post' )->justReturn( $post );

		$captured = null;
		Functions\when( 'wp_update_post' )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return (int) $args['ID'];
		} );

		global $wpdb;
		$wpdb = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn ( $s ) => $s );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( ...$a ) => $a[0] );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$r = $this->method->apply( array(
			'post_id' => 7,
			'old_url' => 'https://example.com/services-north/',
			'mode'    => 'remove_link',
		) );

		$this->assertEmpty( $r['no_op'] ?? false );
		$this->assertSame( 1, $r['after']['replacements'] );
		$this->assertStringContainsString( 'See North below.', $captured['post_content'] );
		$this->assertStringNotContainsString( '<a ', $captured['post_content'] );
	}

	public function test_remove_link_no_op_when_anchor_not_present(): void {
		$post = (object) array(
			'ID'           => 5,
			'post_content' => 'No anchor matching the URL here at all.',
		);
		Functions\when( 'get_post' )->justReturn( $post );

		// Should not write to the post when there's nothing to strip and no
		// deep matches.
		Functions\expect( 'wp_update_post' )->never();

		global $wpdb;
		$wpdb = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn ( $s ) => $s );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( ...$a ) => $a[0] );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$r = $this->method->apply( array(
			'post_id' => 5,
			'old_url' => 'https://example.com/never-linked/',
			'mode'    => 'remove_link',
		) );

		$this->assertTrue( $r['no_op'] );
		$this->assertSame( 0, $r['after']['replacements'] );
	}
}
