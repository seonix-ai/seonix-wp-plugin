<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Redirect;
use Seonix_SEO_Fix_History;
use WP_Error;

final class RedirectTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $history;

    /** @var \Mockery\MockInterface */
    private $wpdb;

    private Seonix_Fix_Redirect $method;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->history       = Mockery::mock( Seonix_SEO_Fix_History::class );
        $this->wpdb          = Mockery::mock();
        $this->wpdb->prefix  = 'wp_';
        $this->wpdb->insert_id = 0;
        $this->method        = new Seonix_Fix_Redirect( $this->history, $this->wpdb );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_key_is_redirect(): void {
        $this->assertSame( 'redirect', $this->method->key() );
    }

    public function test_validate_params_requires_source_and_target(): void {
        $r = $this->method->validate_params( array() );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'missing_source_url', $r->get_error_code() );

        $r = $this->method->validate_params( array( 'source_url' => '/x' ) );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'missing_target_url', $r->get_error_code() );
    }

    public function test_validate_params_rejects_invalid_match_type(): void {
        $r = $this->method->validate_params( array(
            'source_url' => '/old',
            'target_url' => '/new',
            'match_type' => 'fuzzy',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'invalid_match_type', $r->get_error_code() );
    }

    public function test_validate_params_accepts_valid_url_and_regex(): void {
        foreach ( array( 'url', 'regex' ) as $type ) {
            $r = $this->method->validate_params( array(
                'source_url' => '/old',
                'target_url' => '/new',
                'match_type' => $type,
            ) );
            $this->assertTrue( $r );
        }
    }

    public function test_dry_run_when_redirection_plugin_missing_returns_412(): void {
        $this->stub_redirection_table_missing();

        $r = $this->method->dry_run( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'redirection_plugin_required', $r->get_error_code() );
        $this->assertSame( 412, $r->get_error_data()['status'] ?? 0 );
    }

    public function test_dry_run_when_redirect_already_exists_returns_no_op(): void {
        $this->stub_redirection_table_present();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_row' )
            ->andReturn( array( 'id' => 99, 'url' => '/old', 'action_data' => '/new' ) );

        $r = $this->method->dry_run( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertTrue( $r['no_op'] );
        $this->assertSame( 99, $r['target']['id'] );
    }

    public function test_dry_run_describes_planned_redirect(): void {
        $this->stub_redirection_table_present();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

        // dry_run must NOT insert anything.
        $this->wpdb->shouldNotReceive( 'insert' );

        $r = $this->method->dry_run( array(
            'source_url' => '/services-east/',
            'target_url' => '/services/east/',
        ) );

        $this->assertFalse( $r['no_op'] ?? false );
        $this->assertSame( '/services-east/', $r['after']['source_url'] );
        $this->assertSame( '/services/east/', $r['after']['target_url'] );
        $this->assertSame( 'redirect', $r['target']['type'] );
    }

    public function test_apply_inserts_redirect_into_redirection_items(): void {
        $this->stub_redirection_table_present();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

        $this->wpdb->insert_id = 555;
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_redirection_items',
                Mockery::on( function ( $row ) {
                    return $row['url'] === '/old'
                        && $row['action_data'] === '/new'
                        && $row['action_type'] === 'url'
                        && $row['action_code'] === 301
                        && $row['status'] === 'enabled'
                        && (int) $row['regex'] === 0;
                } )
            )
            ->andReturn( 1 );

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
        $this->assertSame( 555, $r['after']['redirect_id'] );
    }

    public function test_apply_with_regex_match_type_sets_regex_flag(): void {
        $this->stub_redirection_table_present();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
        $this->wpdb->insert_id = 1;
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_redirection_items',
                Mockery::on( fn ( $row ) => (int) $row['regex'] === 1 && $row['match_type'] === 'regex' )
            )
            ->andReturn( 1 );

        $r = $this->method->apply( array(
            'source_url' => '/services-([a-z-]+)/?',
            'target_url' => '/services/$1/',
            'match_type' => 'regex',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
    }

    public function test_apply_when_already_exists_returns_no_op(): void {
        $this->stub_redirection_table_present();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 7, 'url' => '/old', 'action_data' => '/new' ) );
        $this->wpdb->shouldNotReceive( 'insert' );

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertTrue( $r['no_op'] );
    }

    public function test_apply_failure_returns_error(): void {
        $this->stub_redirection_table_present();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
        $this->wpdb->shouldReceive( 'insert' )->andReturn( false );
        $this->wpdb->last_error = 'duplicate key';

        $r = $this->method->apply( array(
            'source_url' => '/old',
            'target_url' => '/new',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'insert_failed', $r->get_error_code() );
    }

    public function test_rollback_deletes_inserted_redirect(): void {
        $this->history->shouldReceive( 'get' )
            ->with( 42 )
            ->andReturn( array(
                'id'           => 42,
                'method'       => 'redirect',
                'target_type'  => 'redirect',
                'target_id'    => 555,
                'before_state' => null,
                'after_state'  => array( 'redirect_id' => 555, 'source_url' => '/old', 'target_url' => '/new' ),
            ) );

        $this->wpdb->shouldReceive( 'delete' )
            ->once()
            ->with( 'wp_redirection_items', array( 'id' => 555 ) )
            ->andReturn( 1 );

        $r = $this->method->rollback( 42 );

        $this->assertIsArray( $r );
        $this->assertSame( 555, $r['before']['redirect_id'] );
        $this->assertNull( $r['after'] );
    }

    public function test_rollback_for_unknown_history_returns_error(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );
        $r = $this->method->rollback( 99 );
        $this->assertInstanceOf( WP_Error::class, $r );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function stub_redirection_table_missing(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
    }

    private function stub_redirection_table_present(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_redirection_items' );
    }
}
