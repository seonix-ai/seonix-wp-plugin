<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Controller;
use Seonix_Redirects_Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Wire contract of GET /redirects and POST /redirects/sync.
 */
final class RedirectsControllerTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $store;

    private Seonix_Redirects_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // The rate limiter shares the process-wide transient stub — reset it so
        // budgets never leak between tests.
        \Seonix\Tests\TransientStub::$store = array();
        $this->store      = Mockery::mock( Seonix_Redirects_Store::class );
        $this->controller = new Seonix_Redirects_Controller( $this->store );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A raw DB row exactly as wpdb returns it (all strings).
     */
    private function db_row( array $overrides = array() ): array {
        return array_merge( array(
            'id'          => '123',
            'seonix_id'   => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'from_path'   => '/a',
            'to_url'      => '/b',
            'status_code' => '301',
            'enabled'     => '1',
            'hits'        => '5',
            'created_at'  => '2026-07-15 10:00:00',
            'updated_at'  => '2026-07-15 11:00:00',
            'deleted_at'  => null,
        ), $overrides );
    }

    // ─── GET /redirects ──────────────────────────────────────────────────

    public function test_list_returns_items_tombstones_and_version(): void {
        $this->store->shouldReceive( 'get_items' )->once()->andReturn( array(
            $this->db_row(),
            $this->db_row( array( 'id' => '124', 'seonix_id' => null, 'from_path' => '/local', 'enabled' => '0', 'hits' => '0' ) ),
        ) );
        $this->store->shouldReceive( 'get_tombstones' )->once()->andReturn( array(
            array( 'seonix_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'deleted_at' => '2026-07-10 00:00:00' ),
        ) );

        $response = $this->controller->handle_list( new WP_REST_Request() );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();

        $this->assertSame( SEONIX_VERSION, $data['version'] );

        $this->assertSame( array(
            'id'          => 123,
            'seonix_id'   => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'from_path'   => '/a',
            'to_url'      => '/b',
            'status_code' => 301,
            'enabled'     => true,
            'hits'        => 5,
            'created_at'  => '2026-07-15 10:00:00',
            'updated_at'  => '2026-07-15 11:00:00',
        ), $data['items'][0] );

        // Local row: seonix_id serialises as null, enabled as false.
        $this->assertNull( $data['items'][1]['seonix_id'] );
        $this->assertFalse( $data['items'][1]['enabled'] );
        $this->assertSame( 0, $data['items'][1]['hits'] );

        $this->assertSame( array(
            array(
                'seonix_id'  => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'deleted_at' => '2026-07-10 00:00:00',
            ),
        ), $data['tombstones'] );
    }

    // ─── POST /redirects/sync ────────────────────────────────────────────

    public function test_sync_applies_payload_prunes_and_returns_fresh_state(): void {
        $upsert = array(
            array(
                'seonix_id'   => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'from_path'   => '/a',
                'to_url'      => '/b',
                'status_code' => 301,
                'enabled'     => true,
            ),
        );
        $delete_ids = array( 'cccccccc-cccc-cccc-cccc-cccccccccccc' );

        $this->store->shouldReceive( 'apply_sync' )
            ->once()
            ->with( $upsert, $delete_ids )
            ->andReturn( array(
                'applied' => 1,
                'deleted' => 1,
                'errors'  => array(
                    array(
                        'seonix_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
                        'code'      => 'from_path_conflict',
                        'message'   => 'from_path /x is already used by another redirect on this site.',
                    ),
                ),
            ) );
        $this->store->shouldReceive( 'prune_tombstones' )->once();
        $this->store->shouldReceive( 'get_items' )->once()->andReturn( array( $this->db_row() ) );
        $this->store->shouldReceive( 'get_tombstones' )->once()->andReturn( array() );

        $request = new WP_REST_Request(
            array(
                'upsert'            => $upsert,
                'delete_seonix_ids' => $delete_ids,
            ),
            array( 'authorization' => 'Bearer sx_sync_happy' )
        );

        $response = $this->controller->handle_sync( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();

        $this->assertSame( 1, $data['applied'] );
        $this->assertSame( 1, $data['deleted'] );
        $this->assertSame( 'from_path_conflict', $data['errors'][0]['code'] );
        $this->assertCount( 1, $data['items'] );
        $this->assertSame( array(), $data['tombstones'] );
        $this->assertSame( SEONIX_VERSION, $data['version'] );
    }

    public function test_sync_tolerates_missing_or_malformed_body_fields(): void {
        $this->store->shouldReceive( 'apply_sync' )
            ->once()
            ->with( array(), array() )
            ->andReturn( array( 'applied' => 0, 'deleted' => 0, 'errors' => array() ) );
        $this->store->shouldReceive( 'prune_tombstones' )->once();
        $this->store->shouldReceive( 'get_items' )->once()->andReturn( array() );
        $this->store->shouldReceive( 'get_tombstones' )->once()->andReturn( array() );

        $request = new WP_REST_Request(
            array( 'upsert' => 'not-an-array' ),
            array( 'authorization' => 'Bearer sx_sync_malformed' )
        );

        $response = $this->controller->handle_sync( $request );

        $data = $response->get_data();
        $this->assertSame( 0, $data['applied'] );
        $this->assertSame( 0, $data['deleted'] );
        $this->assertSame( array(), $data['errors'] );
    }

    public function test_sync_unslashes_upsert_values(): void {
        // WP core wp_slash()es REST params; the controller must hand the store
        // clean values (same contract as the seo-fix controller).
        $this->store->shouldReceive( 'apply_sync' )
            ->once()
            ->with(
                array(
                    array(
                        'seonix_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                        'from_path' => '/a',
                        'to_url'    => "/o'brien",
                    ),
                ),
                array()
            )
            ->andReturn( array( 'applied' => 1, 'deleted' => 0, 'errors' => array() ) );
        $this->store->shouldReceive( 'prune_tombstones' )->once();
        $this->store->shouldReceive( 'get_items' )->once()->andReturn( array() );
        $this->store->shouldReceive( 'get_tombstones' )->once()->andReturn( array() );

        $request = new WP_REST_Request(
            array(
                'upsert' => array(
                    array(
                        'seonix_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                        'from_path' => '/a',
                        'to_url'    => "/o\\'brien",
                    ),
                ),
            ),
            array( 'authorization' => 'Bearer sx_sync_slashes' )
        );

        $response = $this->controller->handle_sync( $request );

        $this->assertSame( 1, $response->get_data()['applied'] );
    }

    public function test_sync_is_rate_limited_per_token(): void {
        // The 429 branch translates its message; Brain Monkey doesn't auto-stub
        // translation functions.
        Monkey\Functions\when( '__' )->returnArg();

        $this->store->shouldReceive( 'apply_sync' )->andReturn( array( 'applied' => 0, 'deleted' => 0, 'errors' => array() ) );
        $this->store->shouldReceive( 'prune_tombstones' );
        $this->store->shouldReceive( 'get_items' )->andReturn( array() );
        $this->store->shouldReceive( 'get_tombstones' )->andReturn( array() );

        $request = new WP_REST_Request( array(), array( 'authorization' => 'Bearer sx_rate_limited' ) );

        for ( $i = 0; $i < 60; $i++ ) {
            $this->assertInstanceOf( WP_REST_Response::class, $this->controller->handle_sync( $request ) );
        }

        $blocked = $this->controller->handle_sync( $request );

        $this->assertInstanceOf( WP_Error::class, $blocked );
        $this->assertSame( 'rate_limited', $blocked->get_error_code() );
        $this->assertSame( 429, $blocked->get_error_data()['status'] ?? 0 );
    }
}
