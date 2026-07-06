<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Broken_Link;
use Seonix_SEO_Fix_History;
use WP_Error;

final class BrokenLinkTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $history;

    private Seonix_Fix_Broken_Link $method;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
        $this->method  = new Seonix_Fix_Broken_Link( $this->history );

        // home_url drives the relative-vs-absolute variant logic; default it
        // to example.com so each test can override per-case.
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_key_is_broken_link(): void {
        $this->assertSame( 'broken_link', $this->method->key() );
    }

    public function test_validate_params_requires_post_id_old_url_new_url(): void {
        $r = $this->method->validate_params( array() );
        $this->assertInstanceOf( WP_Error::class, $r );

        $r = $this->method->validate_params( array( 'post_id' => 5 ) );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'missing_old_url', $r->get_error_code() );

        $r = $this->method->validate_params( array( 'post_id' => 5, 'old_url' => '/old' ) );
        $this->assertSame( 'missing_new_url', $r->get_error_code() );
    }

    public function test_validate_rejects_when_old_and_new_are_equal(): void {
        $r = $this->method->validate_params( array(
            'post_id' => 5,
            'old_url' => '/same',
            'new_url' => '/same',
        ) );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'noop_params', $r->get_error_code() );
    }

    public function test_validate_accepts_valid_input(): void {
        $this->assertTrue( $this->method->validate_params( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) ) );
    }

    public function test_dry_run_post_not_found_returns_error(): void {
        Functions\when( 'get_post' )->justReturn( null );

        $r = $this->method->dry_run( array(
            'post_id' => 99,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'post_not_found', $r->get_error_code() );
    }

    public function test_dry_run_no_op_when_old_url_not_in_content(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => 'No links here at all',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $r = $this->method->dry_run( array(
            'post_id' => 5,
            'old_url' => '/missing-url',
            'new_url' => '/whatever',
        ) );

        $this->assertTrue( $r['no_op'] );
        $this->assertSame( 0, $r['after']['replacements'] );
    }

    public function test_apply_replaces_relative_href_when_given_absolute_urls(): void {
        // WP block editor stores internal links as relative paths
        // (href="/services-north/"), but the Seonix backend always
        // sends absolute URLs in old/new params. The method must try the
        // path-only variant when both URLs point at our own host, otherwise
        // every internal-link fix on a block-editor site no-ops.
        \Brain\Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $post = (object) array(
            'ID'           => 2130,
            'post_content' => 'See <a href="/services-north/">North</a>',
        );
        \Brain\Monkey\Functions\when( 'get_post' )->justReturn( $post );
        \Brain\Monkey\Functions\expect( 'wp_update_post' )
            ->once()
            ->with(
                Mockery::on( fn ( $u ) =>
                    str_contains( $u['post_content'], '/services/north/' )
                    && ! str_contains( $u['post_content'], '/services-north/' )
                ),
                true
            )
            ->andReturn( 2130 );

        $r = $this->method->apply( array(
            'post_id' => 2130,
            'old_url' => 'https://example.com/services-north/',
            'new_url' => 'https://example.com/services/north/',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
        $this->assertSame( 1, $r['after']['replacements'] );
    }

    public function test_apply_replaces_absolute_form_when_present(): void {
        \Brain\Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $post = (object) array(
            'ID'           => 2130,
            'post_content' => 'External: <a href="https://example.com/services-north/">Foo</a>',
        );
        \Brain\Monkey\Functions\when( 'get_post' )->justReturn( $post );
        \Brain\Monkey\Functions\expect( 'wp_update_post' )
            ->once()
            ->andReturn( 2130 );

        $r = $this->method->apply( array(
            'post_id' => 2130,
            'old_url' => 'https://example.com/services-north/',
            'new_url' => 'https://example.com/services/north/',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
        $this->assertSame( 1, $r['after']['replacements'] );
    }

    public function test_apply_replaces_both_absolute_and_relative_forms_in_one_post(): void {
        \Brain\Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $post = (object) array(
            'ID'           => 2130,
            'post_content' => '<a href="/old/">a</a> ' .
                              '<a href="https://example.com/old/">b</a>',
        );
        \Brain\Monkey\Functions\when( 'get_post' )->justReturn( $post );
        \Brain\Monkey\Functions\expect( 'wp_update_post' )->once()->andReturn( 2130 );

        $r = $this->method->apply( array(
            'post_id' => 2130,
            'old_url' => 'https://example.com/old/',
            'new_url' => 'https://example.com/new/',
        ) );

        $this->assertSame( 2, $r['after']['replacements'] );
    }

    public function test_dry_run_replaces_all_occurrences_and_reports_count(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/services-east/">city</a> ' .
                              'and again <a href="/services-east/">same</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $r = $this->method->dry_run( array(
            'post_id' => 5,
            'old_url' => '/services-east/',
            'new_url' => '/services/east/',
        ) );

        $this->assertFalse( $r['no_op'] ?? false );
        $this->assertSame( 2, $r['after']['replacements'] );
        $this->assertStringContainsString( '/services/east/', $r['after']['post_content'] );
        $this->assertStringNotContainsString( '/services-east/', $r['after']['post_content'] );
    }

    public function test_dry_run_does_not_persist(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/old">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )->never();

        $this->method->dry_run( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertTrue( true );
    }

    public function test_apply_persists_replaced_content(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/old">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )
            ->once()
            ->with( Mockery::on( fn ( $u ) => (int) $u['ID'] === 5
                && false === strpos( $u['post_content'], '/old' )
                && false !== strpos( $u['post_content'], '/new' ) ), true )
            ->andReturn( 5 );

        $r = $this->method->apply( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
        $this->assertSame( 1, $r['after']['replacements'] );
    }

    public function test_apply_no_op_when_old_url_absent(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => 'no link here',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )->never();

        $r = $this->method->apply( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertTrue( $r['no_op'] );
    }

    public function test_apply_propagates_wp_update_post_error(): void {
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/old">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\when( 'wp_update_post' )->justReturn( new WP_Error( 'db', 'fail' ) );

        $r = $this->method->apply( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'update_failed', $r->get_error_code() );
    }

    public function test_replacement_does_not_touch_partially_matching_urls(): void {
        // The old plain str_replace corrupted every URL that merely STARTED
        // with the broken one: /foo also rewrote /foo-bar, /foobar and
        // /foo/child. The bounded replacement must only touch the exact URL.
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/foo">a</a> <a href="/foo-bar">b</a> ' .
                              '<a href="/foobar">c</a> <a href="/foo/child">d</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $r = $this->method->dry_run( array(
            'post_id' => 5,
            'old_url' => '/foo',
            'new_url' => '/new',
        ) );

        $this->assertSame( 1, $r['after']['replacements'] );
        $this->assertStringContainsString( 'href="/new"', $r['after']['post_content'] );
        $this->assertStringContainsString( 'href="/foo-bar"', $r['after']['post_content'] );
        $this->assertStringContainsString( 'href="/foobar"', $r['after']['post_content'] );
        $this->assertStringContainsString( 'href="/foo/child"', $r['after']['post_content'] );
    }

    public function test_replacement_does_not_touch_same_path_on_other_host(): void {
        // The path-only variant pair ('/old/' → '/new/') must not rewrite the
        // same path inside a DIFFERENT host's absolute URL.
        \Brain\Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/old/">mine</a> ' .
                              '<a href="https://other.com/old/">theirs</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )->once()->andReturn( 5 );

        $r = $this->method->apply( array(
            'post_id' => 5,
            'old_url' => 'https://example.com/old/',
            'new_url' => 'https://example.com/new/',
        ) );

        $this->assertSame( 1, $r['after']['replacements'] );
        $this->assertStringContainsString( 'href="/new/"', $r['after']['post_content'] );
        $this->assertStringContainsString( 'https://other.com/old/', $r['after']['post_content'] );
    }

    public function test_replacement_preserves_trailing_query_string(): void {
        // A query string extends the URL but is not part of the path segment —
        // the same behaviour str_replace had for this legitimate case.
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/old?page=2">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $r = $this->method->dry_run( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertSame( 1, $r['after']['replacements'] );
        $this->assertStringContainsString( 'href="/new?page=2"', $r['after']['post_content'] );
    }

    public function test_replacement_handles_regex_special_chars_in_urls(): void {
        // preg_quote must neutralise regex metacharacters in the needle, and
        // the callback-based replacement must insert '$' in the NEW url
        // literally (a plain preg_replace would eat '$1' as a backreference).
        $post = (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/old(1)">x</a>',
        );
        Functions\when( 'get_post' )->justReturn( $post );

        $r = $this->method->dry_run( array(
            'post_id' => 5,
            'old_url' => '/old(1)',
            'new_url' => '/new$1',
        ) );

        $this->assertSame( 1, $r['after']['replacements'] );
        $this->assertStringContainsString( 'href="/new$1"', $r['after']['post_content'] );
    }

    public function test_rollback_restores_post_content_from_history(): void {
        $this->history->shouldReceive( 'get' )
            ->with( 7 )
            ->andReturn( array(
                'id'           => 7,
                'method'       => 'broken_link',
                'target_type'  => 'post',
                'target_id'    => 5,
                'before_state' => array( 'post_content' => '<a href="/old">x</a>' ),
                'after_state'  => array( 'post_content' => '<a href="/new">x</a>', 'replacements' => 1 ),
            ) );

        Functions\expect( 'wp_update_post' )
            ->once()
            ->with( Mockery::on( fn ( $u ) => $u['ID'] === 5 && false !== strpos( $u['post_content'], '/old' ) ), true )
            ->andReturn( 5 );

        $r = $this->method->rollback( 7 );

        $this->assertIsArray( $r );
        $this->assertStringContainsString( '/old', $r['after']['post_content'] );
    }

    public function test_rollback_unknown_history_returns_error(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );
        $r = $this->method->rollback( 99 );
        $this->assertInstanceOf( WP_Error::class, $r );
    }
}
