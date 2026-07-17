<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Redirect;
use Seonix_Redirects_Store;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * The redirect fix method against the NATIVE redirect manager (2.7.0+):
 * apply creates rows in wp_seonix_redirects via the store, rollback deletes
 * exactly the created row, and history entries written by older plugin
 * versions still roll back against the Redirection plugin's table.
 */
final class RedirectTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $history;

    /** @var \Mockery\MockInterface */
    private $wpdb;

    /** @var \Mockery\MockInterface */
    private $store;

    private Seonix_Fix_Redirect $method;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // validate_rule now canonicalizes targets against the permalink
        // convention + site host; "no convention" keeps these fixtures literal.
        Functions\when( 'get_option' )->alias( static function ( $key, $default = '' ) {
            return $default;
        } );
        Functions\when( 'home_url' )->justReturn( 'http://example.org' );
        $this->history      = Mockery::mock( Seonix_SEO_Fix_History::class );
        $this->wpdb         = Mockery::mock();
        $this->wpdb->prefix = 'wp_';
        $this->store        = Mockery::mock( Seonix_Redirects_Store::class );
        $this->method       = new Seonix_Fix_Redirect( $this->history, $this->wpdb, $this->store );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_key_is_redirect(): void {
        $this->assertSame( 'redirect', $this->method->key() );
    }

    // ─── validate_params ─────────────────────────────────────────────────

    public function test_validate_params_requires_source_and_target(): void {
        $r = $this->method->validate_params( array() );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'missing_source_url', $r->get_error_code() );

        $r = $this->method->validate_params( array( 'source_url' => '/x' ) );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'missing_target_url', $r->get_error_code() );
    }

    public function test_validate_params_rejects_regex_match_type(): void {
        $r = $this->method->validate_params( array(
            'source_url' => '/services-([a-z-]+)/?',
            'target_url' => '/services/$1/',
            'match_type' => 'regex',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'unsupported_match_type', $r->get_error_code() );
        $this->assertSame( 422, $r->get_error_data()['status'] ?? 0 );
    }

    public function test_validate_params_accepts_url_match_type_and_default(): void {
        $this->assertTrue( $this->method->validate_params( array(
            'source_url' => '/old',
            'target_url' => '/new',
            'match_type' => 'url',
        ) ) );
        $this->assertTrue( $this->method->validate_params( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) ) );
    }

    // ─── dry_run ─────────────────────────────────────────────────────────

    public function test_dry_run_describes_planned_redirect(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->with( '/services-east/' )->andReturn( null );
        $this->store->shouldNotReceive( 'create' );

        $r = $this->method->dry_run( array(
            'source_url' => '/services-east/',
            'target_url' => '/services/east/',
        ) );

        $this->assertFalse( $r['no_op'] ?? false );
        $this->assertNull( $r['before'] );
        $this->assertSame( '/services-east/', $r['after']['from_path'] );
        $this->assertSame( '/services/east/', $r['after']['target_url'] );
        $this->assertSame( 301, $r['after']['status_code'] );
        $this->assertSame( 'redirect', $r['target']['type'] );
    }

    public function test_dry_run_reduces_absolute_source_url_to_path(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->with( '/old-page' )->andReturn( null );

        $r = $this->method->dry_run( array(
            'source_url' => 'https://wohnartstudio.de/old-page',
            'target_url' => '/new-page',
        ) );

        $this->assertSame( '/old-page', $r['after']['from_path'] );
    }

    public function test_dry_run_when_redirect_already_exists_returns_no_op(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->with( '/old' )->andReturn( array(
            'id'          => '99',
            'from_path'   => '/old',
            'to_url'      => '/new',
            'status_code' => '301',
        ) );

        $r = $this->method->dry_run( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertTrue( $r['no_op'] );
        $this->assertSame( 99, $r['target']['id'] );
        // No-op state must NOT carry native_redirect_id — rollback of an
        // already_applied entry must never delete a pre-existing rule.
        $this->assertArrayNotHasKey( 'native_redirect_id', $r['after'] );
        $this->assertSame( 99, $r['after']['existing_redirect_id'] );
    }

    public function test_dry_run_rejects_source_with_query_string(): void {
        $r = $this->method->dry_run( array(
            'source_url' => '/shop?orderby=price',
            'target_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'source_query_unsupported', $r->get_error_code() );
        $this->assertSame( 422, $r->get_error_data()['status'] ?? 0 );
    }

    public function test_dry_run_rejects_site_root_source(): void {
        $r = $this->method->dry_run( array(
            'source_url' => 'https://x.test/',
            'target_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'invalid_source_url', $r->get_error_code() );
    }

    public function test_dry_run_rejects_invalid_target(): void {
        $r = $this->method->dry_run( array(
            'source_url' => '/old',
            'target_url' => 'javascript:alert(1)',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'invalid_target_url', $r->get_error_code() );
    }

    // ─── apply ───────────────────────────────────────────────────────────

    public function test_apply_creates_native_redirect(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->with( '/old' )->andReturn( null );
        $this->store->shouldReceive( 'create' )
            ->once()
            ->with( array(
                'seonix_id'   => null,
                'from_path'   => '/old',
                'to_url'      => '/new',
                'status_code' => 301,
                'enabled'     => true,
            ) )
            ->andReturn( 555 );

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
        $this->assertSame( 555, $r['after']['native_redirect_id'] );
        $this->assertSame( '/old', $r['after']['from_path'] );
        $this->assertSame( 555, $r['target']['id'] );
    }

    public function test_apply_when_already_exists_returns_no_op(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->with( '/old' )->andReturn( array(
            'id'          => '7',
            'from_path'   => '/old',
            'to_url'      => '/new',
            'status_code' => '301',
        ) );
        $this->store->shouldNotReceive( 'create' );

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertTrue( $r['no_op'] );
        $this->assertSame( 7, $r['target']['id'] );
    }

    public function test_apply_propagates_store_error(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->andReturn( null );
        $this->store->shouldReceive( 'create' )
            ->andReturn( new WP_Error( 'from_path_conflict', 'taken', array( 'status' => 409 ) ) );

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'from_path_conflict', $r->get_error_code() );
    }

    public function test_apply_zero_insert_id_returns_error(): void {
        $this->store->shouldReceive( 'find_active_conflict' )->andReturn( null );
        $this->store->shouldReceive( 'create' )->andReturn( 0 );

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'insert_failed', $r->get_error_code() );
    }

    // ─── rollback ────────────────────────────────────────────────────────

    public function test_rollback_deletes_native_redirect(): void {
        $this->history->shouldReceive( 'get' )
            ->with( 42 )
            ->andReturn( array(
                'id'          => 42,
                'method'      => 'redirect',
                'after_state' => array(
                    'native_redirect_id' => 555,
                    'from_path'          => '/old',
                    'target_url'         => '/new',
                ),
            ) );
        $this->store->shouldReceive( 'hard_delete' )->once()->with( 555 )->andReturn( true );

        $r = $this->method->rollback( 42 );

        $this->assertIsArray( $r );
        $this->assertSame( 555, $r['before']['native_redirect_id'] );
        $this->assertNull( $r['after'] );
    }

    public function test_rollback_legacy_entry_deletes_from_redirection_table(): void {
        // Entry created by a pre-2.7.0 plugin version — after_state carries
        // redirect_id (row inside the Redirection plugin's table).
        $this->history->shouldReceive( 'get' )
            ->with( 43 )
            ->andReturn( array(
                'id'          => 43,
                'method'      => 'redirect',
                'after_state' => array(
                    'redirect_id' => 812,
                    'source_url'  => '/old',
                    'target_url'  => '/new',
                ),
            ) );
        $this->store->shouldNotReceive( 'hard_delete' );
        $this->wpdb->shouldReceive( 'delete' )
            ->once()
            ->with( 'wp_redirection_items', array( 'id' => 812 ) )
            ->andReturn( 1 );

        $r = $this->method->rollback( 43 );

        $this->assertIsArray( $r );
        $this->assertSame( 812, $r['before']['redirect_id'] );
        $this->assertNull( $r['after'] );
    }

    public function test_rollback_of_no_op_entry_refuses(): void {
        $this->history->shouldReceive( 'get' )
            ->with( 44 )
            ->andReturn( array(
                'id'          => 44,
                'method'      => 'redirect',
                'after_state' => array(
                    'existing_redirect_id' => 7,
                    'from_path'            => '/old',
                ),
            ) );
        $this->store->shouldNotReceive( 'hard_delete' );

        $r = $this->method->rollback( 44 );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'invalid_history_entry', $r->get_error_code() );
    }

    public function test_rollback_for_unknown_history_returns_error(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );
        $r = $this->method->rollback( 99 );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'unknown_history_entry', $r->get_error_code() );
    }
}
