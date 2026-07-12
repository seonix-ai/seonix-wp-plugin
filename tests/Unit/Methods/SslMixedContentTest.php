<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_SSL_Mixed_Content;
use Seonix_SEO_Fix_History;
use WP_Error;

final class SslMixedContentTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $history;

    private Seonix_Fix_SSL_Mixed_Content $method;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
        $this->method  = new Seonix_Fix_SSL_Mixed_Content( $this->history );

        // Default stub: own site is example.com.
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_key_is_ssl_mixed_content(): void {
        $this->assertSame( 'ssl_mixed_content', $this->method->key() );
    }

    public function test_validate_params_requires_post_id(): void {
        $result = $this->method->validate_params( array() );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'missing_post_id', $result->get_error_code() );
    }

    public function test_validate_params_accepts_valid_input(): void {
        $this->assertTrue( $this->method->validate_params( array( 'post_id' => 5 ) ) );
    }

    public function test_dry_run_returns_error_when_post_does_not_exist(): void {
        Functions\when( 'get_post' )->justReturn( null );

        $result = $this->method->dry_run( array( 'post_id' => 99 ) );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'post_not_found', $result->get_error_code() );
    }

    public function test_dry_run_returns_no_op_when_no_mixed_content(): void {
        $post           = (object) array(
            'ID'           => 5,
            'post_content' => 'Clean content https://example.com/page',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $result = $this->method->dry_run( array( 'post_id' => 5 ) );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['no_op'] );
        $this->assertSame( $post->post_content, $result['before']['post_content'] );
        $this->assertSame( $post->post_content, $result['after']['post_content'] );
    }

    public function test_dry_run_replaces_own_domain_http_with_https(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => 'See <a href="http://example.com/about">our page</a> for info.',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $result = $this->method->dry_run( array( 'post_id' => 5 ) );

        $this->assertFalse( $result['no_op'] ?? false );
        $this->assertStringContainsString( 'https://example.com/about', $result['after']['post_content'] );
        $this->assertStringNotContainsString( 'http://example.com', $result['after']['post_content'] );
        $this->assertSame( 'post', $result['target']['type'] );
        $this->assertSame( 5, $result['target']['id'] );
    }

    public function test_dry_run_leaves_third_party_http_urls_alone(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => 'External: http://example.net/img.png and own: http://example.com/x',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $result = $this->method->dry_run( array( 'post_id' => 5 ) );

        $this->assertStringContainsString( 'http://example.net', $result['after']['post_content'] );
        $this->assertStringContainsString( 'https://example.com/x', $result['after']['post_content'] );
    }

    public function test_dry_run_does_not_persist(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="http://example.com/x">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        // wp_update_post must NOT be called during dry_run.
        Functions\expect( 'wp_update_post' )->never();

        $this->method->dry_run( array( 'post_id' => 5 ) );

        $this->assertTrue( true );
    }

    public function test_apply_persists_replaced_content(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="http://example.com/x">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )
            ->once()
            ->with( Mockery::on( function ( $update ) {
                return is_array( $update )
                    && (int) $update['ID'] === 5
                    && false === strpos( $update['post_content'], 'http://example.com' )
                    && false !== strpos( $update['post_content'], 'https://example.com' );
            } ), true )
            ->andReturn( 5 );

        $result = $this->method->apply( array( 'post_id' => 5 ) );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result['no_op'] ?? false );
        $this->assertStringContainsString( 'https://example.com', $result['after']['post_content'] );
    }

    public function test_apply_wp_slashes_content_preserving_backslashes(): void {
        // Content carries a literal backslash (a JS \uXXXX escape). wp_update_post
        // runs wp_unslash() on its input, so without wp_slash the backslash would
        // be silently stripped and the snippet corrupted. Regression for the
        // missing-wp_slash content-corruption bug.
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="http://example.com/x">x</a> snippet: \uD83D',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $captured = null;
        Functions\when( 'wp_update_post' )->alias( function ( $args ) use ( &$captured ) {
            $captured = $args;
            return (int) $args['ID'];
        } );

        $this->method->apply( array( 'post_id' => 5 ) );

        $this->assertNotNull( $captured, 'wp_update_post should have been called' );
        // The value handed to wp_update_post is slashed, so WordPress' internal
        // wp_unslash round-trips it back to the intended content — backslash intact.
        $roundTripped = wp_unslash( $captured['post_content'] );
        $this->assertStringContainsString( 'snippet: \uD83D', $roundTripped );
        $this->assertStringContainsString( 'https://example.com', $roundTripped );
        // The stored (slashed) form must differ from the round-tripped value —
        // proof the backslash was doubled rather than passed through raw (which
        // wp_unslash would then have stripped).
        $this->assertNotSame( $roundTripped, $captured['post_content'] );
    }

    public function test_apply_returns_no_op_when_already_https(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => 'all https://example.com/clean',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )->never();

        $result = $this->method->apply( array( 'post_id' => 5 ) );

        $this->assertTrue( $result['no_op'] );
    }

    public function test_apply_propagates_wp_update_post_error(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="http://example.com/x">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'wp_update_post' )->justReturn(
            new WP_Error( 'db_update_error', 'failed' )
        );

        $result = $this->method->apply( array( 'post_id' => 5 ) );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'update_failed', $result->get_error_code() );
    }

    public function test_rollback_restores_post_content_from_history(): void {
        $this->history->shouldReceive( 'get' )
            ->with( 42 )
            ->andReturn( array(
                'id'           => 42,
                'method'       => 'ssl_mixed_content',
                'target_type'  => 'post',
                'target_id'    => 5,
                'before_state' => array( 'post_content' => '<a href="http://example.com/x">x</a>' ),
                'after_state'  => array( 'post_content' => '<a href="https://example.com/x">x</a>' ),
            ) );

        Functions\expect( 'wp_update_post' )
            ->once()
            ->with(
                Mockery::on( fn ( $u ) => (int) $u['ID'] === 5
                    && false !== strpos( $u['post_content'], 'http://example.com' ) ),
                true
            )
            ->andReturn( 5 );

        $result = $this->method->rollback( 42 );

        $this->assertIsArray( $result );
        $this->assertStringContainsString( 'http://example.com', $result['after']['post_content'] );
    }

    public function test_rollback_returns_error_for_unknown_history_id(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );

        $result = $this->method->rollback( 99 );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'unknown_history_entry', $result->get_error_code() );
    }
}
