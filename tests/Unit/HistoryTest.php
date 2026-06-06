<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_SEO_Fix_History;

final class HistoryTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $wpdb;

    private Seonix_SEO_Fix_History $history;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb         = Mockery::mock();
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->insert_id = 0;
        $this->history      = new Seonix_SEO_Fix_History( $this->wpdb );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_table_name_uses_wpdb_prefix(): void {
        $this->assertSame( 'wp_seonix_seo_fix_history', $this->history->table_name() );
    }

    public function test_record_dry_run_inserts_with_dry_run_status_and_returns_id(): void {
        $this->wpdb->insert_id = 42;
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_seonix_seo_fix_history',
                Mockery::on( function ( $row ) {
                    return $row['fix_id'] === 'fix-uuid-1'
                        && $row['method'] === 'broken_link'
                        && $row['status'] === 'dry_run'
                        && $row['target_type'] === 'post'
                        && (int) $row['target_id'] === 7
                        && json_decode( $row['params'], true ) === array( 'k' => 'v' )
                        && json_decode( $row['before_state'], true ) === array( 'a' => 1 )
                        && json_decode( $row['after_state'], true ) === array( 'a' => 2 );
                } )
            )
            ->andReturn( 1 );

        $id = $this->history->record_dry_run(
            'fix-uuid-1',
            'broken_link',
            array( 'k' => 'v' ),
            'post',
            7,
            array( 'a' => 1 ),
            array( 'a' => 2 )
        );

        $this->assertSame( 42, $id );
    }

    public function test_record_apply_inserts_with_applied_status(): void {
        $this->wpdb->insert_id = 99;
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_seonix_seo_fix_history',
                Mockery::on( fn ( $row ) => $row['status'] === 'applied' )
            )
            ->andReturn( 1 );

        $id = $this->history->record_apply(
            'fix-uuid-2',
            'ssl_mixed_content',
            array(),
            'post',
            5,
            array( 'before' => 1 ),
            array( 'after' => 1 )
        );

        $this->assertSame( 99, $id );
    }

    public function test_mark_rolled_back_updates_status(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_seonix_seo_fix_history',
                Mockery::on( fn ( $data ) => $data['status'] === 'rolled_back' ),
                array( 'id' => 42 )
            )
            ->andReturn( 1 );

        $this->assertTrue( $this->history->mark_rolled_back( 42 ) );
    }

    public function test_find_by_fix_id_returns_null_when_missing(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $this->wpdb->shouldReceive( 'get_row' )
            ->with( 'SQL', ARRAY_A )
            ->andReturn( null );

        $this->assertNull( $this->history->find_by_fix_id( 'unknown' ) );
    }

    public function test_find_by_fix_id_returns_row_when_present(): void {
        $row = array( 'id' => 1, 'fix_id' => 'fix-x', 'status' => 'applied' );

        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $this->wpdb->shouldReceive( 'get_row' )
            ->with( 'SQL', ARRAY_A )
            ->andReturn( $row );

        $this->assertSame( $row, $this->history->find_by_fix_id( 'fix-x' ) );
    }

    public function test_get_returns_row_with_decoded_json_fields(): void {
        $stored = array(
            'id'           => 1,
            'fix_id'       => 'fx',
            'method'       => 'broken_link',
            'params'       => '{"k":"v"}',
            'before_state' => '{"a":1}',
            'after_state'  => '{"a":2}',
            'target_type'  => 'post',
            'target_id'    => 7,
            'status'       => 'applied',
        );

        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $this->wpdb->shouldReceive( 'get_row' )->with( 'SQL', ARRAY_A )->andReturn( $stored );

        $row = $this->history->get( 1 );

        $this->assertNotNull( $row );
        $this->assertSame( array( 'k' => 'v' ), $row['params'] );
        $this->assertSame( array( 'a' => 1 ), $row['before_state'] );
        $this->assertSame( array( 'a' => 2 ), $row['after_state'] );
    }

    public function test_create_table_calls_dbDelta_with_create_table_statement(): void {
        Monkey\Functions\expect( 'dbDelta' )
            ->once()
            ->with( Mockery::on( fn ( $sql ) => str_contains( $sql, 'CREATE TABLE' )
                && str_contains( $sql, 'wp_seonix_seo_fix_history' ) ) );

        // Charset/collate accessor used by dbDelta-style installs.
        $this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET=utf8mb4' );

        $this->history->create_table();

        $this->assertTrue( true ); // assertion is in the expect() above
    }
}
