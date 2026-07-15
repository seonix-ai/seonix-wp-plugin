<?php
namespace Seonix\Tests\Unit\Redirects;

use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Store;

/**
 * Reconcile semantics of POST /redirects/sync, tested through the pure
 * planning step (Seonix_Redirects_Store::plan_sync).
 */
final class PlanSyncTest extends TestCase {

    private const UUID_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const UUID_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row( array $overrides = array() ): array {
        return array_merge( array(
            'id'         => 1,
            'seonix_id'  => null,
            'from_path'  => '/a',
            'deleted_at' => null,
        ), $overrides );
    }

    private function item( array $overrides = array() ): array {
        return array_merge( array(
            'seonix_id'   => self::UUID_A,
            'from_path'   => '/a',
            'to_url'      => '/b',
            'status_code' => 301,
            'enabled'     => true,
        ), $overrides );
    }

    // ─── Inserts ─────────────────────────────────────────────────────────

    public function test_upsert_inserts_new_rule(): void {
        $plan = Seonix_Redirects_Store::plan_sync( array(), array( $this->item() ), array() );

        $this->assertSame( 1, $plan['applied'] );
        $this->assertSame( 0, $plan['deleted'] );
        $this->assertSame( array(), $plan['errors'] );
        $this->assertCount( 1, $plan['ops'] );
        $this->assertSame( 'insert', $plan['ops'][0]['op'] );
        $this->assertSame( self::UUID_A, $plan['ops'][0]['data']['seonix_id'] );
        $this->assertSame( '/a', $plan['ops'][0]['data']['from_path'] );
        $this->assertSame( '/b', $plan['ops'][0]['data']['to_url'] );
        $this->assertSame( 301, $plan['ops'][0]['data']['status_code'] );
        $this->assertSame( 1, $plan['ops'][0]['data']['enabled'] );
    }

    public function test_upsert_defaults_enabled_true_and_status_301(): void {
        $item = $this->item();
        unset( $item['enabled'], $item['status_code'] );

        $plan = Seonix_Redirects_Store::plan_sync( array(), array( $item ), array() );

        $this->assertSame( 1, $plan['ops'][0]['data']['enabled'] );
        $this->assertSame( 301, $plan['ops'][0]['data']['status_code'] );
    }

    public function test_upsert_disabled_item_keeps_enabled_zero(): void {
        $plan = Seonix_Redirects_Store::plan_sync( array(), array( $this->item( array( 'enabled' => false ) ) ), array() );

        $this->assertSame( 0, $plan['ops'][0]['data']['enabled'] );
    }

    // ─── Updates & resurrection ──────────────────────────────────────────

    public function test_upsert_updates_existing_row_by_seonix_id(): void {
        $rows = array( $this->row( array( 'id' => 7, 'seonix_id' => self::UUID_A, 'from_path' => '/a' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync(
            $rows,
            array( $this->item( array( 'from_path' => '/moved', 'to_url' => '/target', 'status_code' => 302 ) ) ),
            array()
        );

        $this->assertSame( array(), $plan['errors'] );
        $this->assertCount( 1, $plan['ops'] );
        $this->assertSame( 'update', $plan['ops'][0]['op'] );
        $this->assertSame( 7, $plan['ops'][0]['id'] );
        $this->assertSame( '/moved', $plan['ops'][0]['data']['from_path'] );
        $this->assertSame( 302, $plan['ops'][0]['data']['status_code'] );
        $this->assertNull( $plan['ops'][0]['data']['deleted_at'] );
        $this->assertSame( 1, $plan['applied'] );
    }

    public function test_upsert_resurrects_tombstoned_row(): void {
        $rows = array( $this->row( array(
            'id'         => 3,
            'seonix_id'  => self::UUID_A,
            'from_path'  => '/gone',
            'deleted_at' => '2026-07-01 00:00:00',
        ) ) );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array( $this->item( array( 'from_path' => '/gone' ) ) ), array() );

        $this->assertSame( array(), $plan['errors'] );
        $this->assertSame( 'update', $plan['ops'][0]['op'] );
        $this->assertSame( 3, $plan['ops'][0]['id'] );
        $this->assertNull( $plan['ops'][0]['data']['deleted_at'] );
    }

    public function test_update_to_same_from_path_is_not_a_self_conflict(): void {
        $rows = array( $this->row( array( 'id' => 7, 'seonix_id' => self::UUID_A, 'from_path' => '/a' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array( $this->item( array( 'to_url' => '/elsewhere' ) ) ), array() );

        $this->assertSame( array(), $plan['errors'] );
        $this->assertSame( 'update', $plan['ops'][0]['op'] );
    }

    // ─── Conflicts ───────────────────────────────────────────────────────

    public function test_from_path_conflict_with_local_row_is_skipped(): void {
        $rows = array( $this->row( array( 'id' => 1, 'seonix_id' => null, 'from_path' => '/a' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array( $this->item() ), array() );

        $this->assertSame( array(), $plan['ops'] );
        $this->assertSame( 0, $plan['applied'] );
        $this->assertCount( 1, $plan['errors'] );
        $this->assertSame( 'from_path_conflict', $plan['errors'][0]['code'] );
        $this->assertSame( self::UUID_A, $plan['errors'][0]['seonix_id'] );
    }

    public function test_conflict_is_match_key_insensitive(): void {
        // '/A/' and '/a' collide on the runtime match key.
        $rows = array( $this->row( array( 'id' => 1, 'seonix_id' => null, 'from_path' => '/A/' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array( $this->item( array( 'from_path' => '/a' ) ) ), array() );

        $this->assertSame( 'from_path_conflict', $plan['errors'][0]['code'] );
    }

    public function test_conflict_with_other_seonix_row_is_skipped(): void {
        $rows = array( $this->row( array( 'id' => 1, 'seonix_id' => self::UUID_B, 'from_path' => '/a' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array( $this->item() ), array() );

        $this->assertSame( 'from_path_conflict', $plan['errors'][0]['code'] );
    }

    public function test_tombstoned_row_does_not_block_from_path(): void {
        $rows = array( $this->row( array(
            'id'         => 1,
            'seonix_id'  => self::UUID_B,
            'from_path'  => '/a',
            'deleted_at' => '2026-07-01 00:00:00',
        ) ) );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array( $this->item() ), array() );

        $this->assertSame( array(), $plan['errors'] );
        $this->assertSame( 'insert', $plan['ops'][0]['op'] );
    }

    public function test_conflict_within_one_batch(): void {
        $plan = Seonix_Redirects_Store::plan_sync(
            array(),
            array(
                $this->item( array( 'seonix_id' => self::UUID_A, 'from_path' => '/x' ) ),
                $this->item( array( 'seonix_id' => self::UUID_B, 'from_path' => '/x/' ) ),
            ),
            array()
        );

        $this->assertSame( 1, $plan['applied'] );
        $this->assertCount( 1, $plan['errors'] );
        $this->assertSame( 'from_path_conflict', $plan['errors'][0]['code'] );
        $this->assertSame( self::UUID_B, $plan['errors'][0]['seonix_id'] );
    }

    public function test_update_frees_previous_from_path_for_later_item(): void {
        // uuid-A moves off /a; uuid-B claims /a in the same payload.
        $rows = array( $this->row( array( 'id' => 7, 'seonix_id' => self::UUID_A, 'from_path' => '/a' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync(
            $rows,
            array(
                $this->item( array( 'seonix_id' => self::UUID_A, 'from_path' => '/renamed' ) ),
                $this->item( array( 'seonix_id' => self::UUID_B, 'from_path' => '/a' ) ),
            ),
            array()
        );

        $this->assertSame( array(), $plan['errors'] );
        $this->assertSame( 2, $plan['applied'] );
    }

    // ─── Invalid items ───────────────────────────────────────────────────

    public function test_invalid_items_are_reported_not_planned(): void {
        $plan = Seonix_Redirects_Store::plan_sync(
            array(),
            array(
                $this->item( array( 'from_path' => 'no-slash' ) ),
                $this->item( array( 'seonix_id' => self::UUID_B, 'to_url' => '' ) ),
                $this->item( array( 'seonix_id' => '' ) ),
            ),
            array()
        );

        $this->assertSame( array(), $plan['ops'] );
        $this->assertSame( 0, $plan['applied'] );
        $this->assertCount( 3, $plan['errors'] );
        foreach ( $plan['errors'] as $error ) {
            $this->assertSame( 'invalid', $error['code'] );
        }
    }

    public function test_duplicate_seonix_id_in_batch_is_invalid(): void {
        $plan = Seonix_Redirects_Store::plan_sync(
            array(),
            array(
                $this->item( array( 'from_path' => '/x' ) ),
                $this->item( array( 'from_path' => '/y' ) ),
            ),
            array()
        );

        $this->assertSame( 1, $plan['applied'] );
        $this->assertCount( 1, $plan['errors'] );
        $this->assertSame( 'invalid', $plan['errors'][0]['code'] );
    }

    // ─── Deletions ───────────────────────────────────────────────────────

    public function test_delete_seonix_ids_hard_deletes_rows(): void {
        $rows = array(
            $this->row( array( 'id' => 5, 'seonix_id' => self::UUID_A, 'from_path' => '/a' ) ),
            $this->row( array( 'id' => 6, 'seonix_id' => self::UUID_B, 'from_path' => '/b', 'deleted_at' => '2026-07-01 00:00:00' ) ),
        );

        $plan = Seonix_Redirects_Store::plan_sync( $rows, array(), array( self::UUID_A, self::UUID_B ) );

        $this->assertSame( 2, $plan['deleted'] );
        $this->assertSame(
            array(
                array( 'op' => 'delete', 'id' => 5 ),
                array( 'op' => 'delete', 'id' => 6 ),
            ),
            $plan['ops']
        );
    }

    public function test_delete_unknown_seonix_id_is_ignored(): void {
        $plan = Seonix_Redirects_Store::plan_sync( array(), array(), array( self::UUID_A ) );

        $this->assertSame( 0, $plan['deleted'] );
        $this->assertSame( array(), $plan['ops'] );
        $this->assertSame( array(), $plan['errors'] );
    }

    public function test_delete_frees_from_path_for_upsert_in_same_payload(): void {
        // Move scenario: uuid-A (owns /x) is deleted while uuid-B takes /x over.
        $rows = array( $this->row( array( 'id' => 5, 'seonix_id' => self::UUID_A, 'from_path' => '/x' ) ) );

        $plan = Seonix_Redirects_Store::plan_sync(
            $rows,
            array( $this->item( array( 'seonix_id' => self::UUID_B, 'from_path' => '/x' ) ) ),
            array( self::UUID_A )
        );

        $this->assertSame( array(), $plan['errors'] );
        $this->assertSame( 1, $plan['deleted'] );
        $this->assertSame( 1, $plan['applied'] );
        $this->assertSame( 'delete', $plan['ops'][0]['op'] );
        $this->assertSame( 'insert', $plan['ops'][1]['op'] );
    }
}
