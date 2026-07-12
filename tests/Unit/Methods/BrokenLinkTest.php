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

    public function test_apply_before_after_serialize_as_json_objects_not_arrays(): void {
        // Regression for the [] vs {} wire bug: apply() must never emit an empty
        // PHP array for before/after — it serializes to "[]" and the Go backend
        // decodes before/after as a map, rejecting the whole (successful) apply
        // and losing rollback capability. Both must be non-empty JSON objects.
        $post = (object) array( 'ID' => 5, 'post_content' => '<a href="/old">x</a>' );
        Functions\when( 'get_post' )->justReturn( $post );
        Functions\expect( 'wp_update_post' )->once()->andReturn( 5 );

        $r = $this->method->apply( array(
            'post_id' => 5,
            'old_url' => '/old',
            'new_url' => '/new',
        ) );

        $this->assertNotSame( '[]', json_encode( $r['before'] ) );
        $this->assertNotSame( '[]', json_encode( $r['after'] ) );
        $this->assertStringStartsWith( '{', json_encode( $r['before'] ) );
        $this->assertStringStartsWith( '{', json_encode( $r['after'] ) );
        // The change summary survives for the dashboard.
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
        $captured = null;
        Functions\expect( 'wp_update_post' )
            ->once()
            ->with( Mockery::on( function ( $u ) use ( &$captured ) {
                $captured = $u['post_content'];
                return true;
            } ), true )
            ->andReturn( 5 );

        $r = $this->method->apply( array(
            'post_id' => 5,
            'old_url' => 'https://example.com/old/',
            'new_url' => 'https://example.com/new/',
        ) );

        $this->assertSame( 1, $r['after']['replacements'] );
        // apply() no longer returns a page-content copy — assert on what was
        // actually written to the DB. Needles are quote-free: wp_slash() escapes
        // the surrounding quotes in the written content.
        $this->assertStringContainsString( '/new/', $captured );          // mine rewritten
        $this->assertStringContainsString( 'other.com/old/', $captured ); // other host untouched
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

    public function test_rollback_surgically_reverses_new_url_to_old_on_current_content(): void {
        // Rollback must NOT restore a stored page copy — it reverses the exact
        // substitution (new_url -> old_url) on the CURRENT content, so an edit
        // made to the page after the fix survives the undo.
        $this->history->shouldReceive( 'get' )
            ->with( 7 )
            ->andReturn( array(
                'id'          => 7,
                'method'      => 'broken_link',
                'params'      => array( 'old_url' => '/old', 'new_url' => '/new', 'mode' => 'rewrite' ),
                'target_id'   => 5,
                'after_state' => array( 'replacements' => 1 ),
            ) );

        Functions\when( 'get_post' )->justReturn( (object) array(
            'ID'           => 5,
            'post_content' => '<a href="/new">x</a> <p>edited later</p>',
        ) );

        $captured = null;
        Functions\expect( 'wp_update_post' )
            ->once()
            ->with( Mockery::on( function ( $u ) use ( &$captured ) {
                $captured = $u['post_content'];
                return 5 === (int) $u['ID'];
            } ), true )
            ->andReturn( 5 );

        $r = $this->method->rollback( 7 );

        $this->assertIsArray( $r );
        // Quote-free needles: wp_slash() escapes quotes in the written content.
        $this->assertStringContainsString( '/old', $captured );         // reversed back
        $this->assertStringNotContainsString( '/new', $captured );      // new_url gone
        $this->assertStringContainsString( 'edited later', $captured ); // unrelated edit preserved
        $this->assertSame( 1, $r['before']['reverted_posts'] );
    }

    public function test_rollback_reverses_primary_and_deep_posts(): void {
        // Primary target AND every deep-rewritten post in after_state must revert.
        $this->history->shouldReceive( 'get' )
            ->with( 7 )
            ->andReturn( array(
                'id'          => 7,
                'method'      => 'broken_link',
                'params'      => array( 'old_url' => '/old', 'new_url' => '/new', 'mode' => 'rewrite' ),
                'target_id'   => 5,
                'after_state' => array( 'deep_rewrites' => array( '8' => 1, '9' => 2 ) ),
            ) );

        Functions\when( 'get_post' )->alias( function ( $id ) {
            return (object) array( 'ID' => (int) $id, 'post_content' => '<a href="/new">x</a>' );
        } );

        $ids = array();
        Functions\expect( 'wp_update_post' )
            ->times( 3 )
            ->with( Mockery::on( function ( $u ) use ( &$ids ) {
                $ids[] = (int) $u['ID'];
                return false !== strpos( $u['post_content'], '/old' );
            } ), true )
            ->andReturn( 1 );

        $r = $this->method->rollback( 7 );

        sort( $ids );
        $this->assertSame( array( 5, 8, 9 ), $ids );
        $this->assertSame( 3, $r['before']['reverted_posts'] );
    }

    public function test_rollback_skips_post_where_new_url_already_gone(): void {
        // If the page was edited and no longer contains new_url, there is nothing
        // to undo there — skip it silently, do not write or error.
        $this->history->shouldReceive( 'get' )
            ->with( 7 )
            ->andReturn( array(
                'method'    => 'broken_link',
                'params'    => array( 'old_url' => '/old', 'new_url' => '/new', 'mode' => 'rewrite' ),
                'target_id' => 5,
            ) );

        Functions\when( 'get_post' )->justReturn( (object) array(
            'ID'           => 5,
            'post_content' => '<p>the link was removed by the author</p>',
        ) );
        Functions\expect( 'wp_update_post' )->never();

        $r = $this->method->rollback( 7 );

        $this->assertIsArray( $r );
        $this->assertSame( 0, $r['before']['reverted_posts'] );
        $this->assertSame( 1, $r['before']['skipped_posts'] );
    }

    public function test_rollback_remove_link_mode_is_not_reversible(): void {
        // remove_link stripped the <a> wrapper — no reliable surgical way to
        // re-create it, so rollback reports it honestly instead of guessing.
        $this->history->shouldReceive( 'get' )
            ->with( 7 )
            ->andReturn( array(
                'method'    => 'broken_link',
                'params'    => array( 'old_url' => '/old', 'mode' => 'remove_link' ),
                'target_id' => 5,
            ) );
        Functions\expect( 'wp_update_post' )->never();

        $r = $this->method->rollback( 7 );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'not_reversible', $r->get_error_code() );
    }

    public function test_rollback_unknown_history_returns_error(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );
        $r = $this->method->rollback( 99 );
        $this->assertInstanceOf( WP_Error::class, $r );
    }
}
