<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_REST_API;
use WP_Query;
use WP_REST_Request;

/**
 * Covers GET /inventory (plugin 2.17.0) — the read side of the site inventory.
 *
 * Why the route exists: the plugin's own push runs on a weekly cron that is
 * scheduled at ACTIVATION, i.e. one firing BEFORE the site knows which backend
 * it belongs to (push_full_sync() returns early with no engine_url) and the next
 * one seven days later. Until then Seonix knew none of the site's pages — no
 * internal-link targets, nothing to optimise, an empty content browser. Being
 * readable lets the backend fetch the inventory the moment a site connects.
 *
 * The response shape is deliberately identical to the pushed one
 * (Seonix_Sync::format_item), so the backend cannot tell the two roads apart.
 */
final class InventoryRouteTest extends TestCase {

    private Seonix_REST_API $api;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        WP_Query::reset();

        Functions\when( 'get_permalink' )->alias( static fn ( $id ) => "https://example.test/?p=$id" );
        Functions\when( 'rest_ensure_response' )->returnArg();

        $this->api = new Seonix_REST_API();
    }

    protected function tearDown(): void {
        WP_Query::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_inventory_returns_push_compatible_items(): void {
        WP_Query::$next_posts = array(
            $this->makePost( 11, 'page', 'Over ons', 'over-ons' ),
            $this->makePost( 12, 'post', 'Nieuws', 'nieuws' ),
        );

        $response = $this->api->handle_inventory( $this->makeRequest() );

        $this->assertArrayHasKey( 'items', $response );
        $this->assertCount( 2, $response['items'] );

        $first = $response['items'][0];
        // Exactly the keys the backend's sync decoder reads — no more, no less.
        $this->assertSame(
            array( 'wp_id', 'content_type', 'title', 'slug', 'url', 'status', 'updated_at' ),
            array_keys( $first )
        );
        $this->assertSame( 11, $first['wp_id'] );
        $this->assertSame( 'page', $first['content_type'] );
        $this->assertSame( 'Over ons', $first['title'] );
        $this->assertSame( 'over-ons', $first['slug'] );
        $this->assertSame( 'https://example.test/?p=11', $first['url'] );
        $this->assertSame( 'publish', $first['status'] );
        // RFC3339 — the Go decoder rejects anything else.
        $this->assertSame( '2026-08-01T10:30:00+00:00', $first['updated_at'] );

        $this->assertSame( 'post', $response['items'][1]['content_type'] );
    }

    public function test_inventory_maps_unknown_post_types_to_post(): void {
        // A custom post type that slipped into the query must not land in the
        // backend's three-value content_type column as itself.
        WP_Query::$next_posts = array( $this->makePost( 30, 'portfolio', 'Case', 'case' ) );

        $response = $this->api->handle_inventory( $this->makeRequest() );

        $this->assertSame( 'post', $response['items'][0]['content_type'] );
    }

    public function test_inventory_maps_products(): void {
        WP_Query::$next_posts = array( $this->makePost( 31, 'product', 'Mok', 'mok' ) );

        $response = $this->api->handle_inventory( $this->makeRequest() );

        $this->assertSame( 'product', $response['items'][0]['content_type'] );
    }

    public function test_inventory_reports_has_more_from_query(): void {
        WP_Query::$next_max_num_pages = 3;
        WP_Query::$next_posts         = array( $this->makePost( 1, 'post', 'A', 'a' ) );

        $this->assertTrue( $this->api->handle_inventory( $this->makeRequest( array( 'page' => 2 ) ) )['has_more'] );
        $this->assertFalse( $this->api->handle_inventory( $this->makeRequest( array( 'page' => 3 ) ) )['has_more'] );
    }

    public function test_inventory_queries_published_content_in_stable_order(): void {
        $this->api->handle_inventory( $this->makeRequest() );

        $args = WP_Query::$calls[0];
        $this->assertSame( 'publish', $args['post_status'], 'A draft is not a link target' );
        // Pagination has to stay stable while the site is being edited, so the
        // order cannot be date/modified based.
        $this->assertSame( 'ID', $args['orderby'] );
        $this->assertSame( 'ASC', $args['order'] );
        // An inventory reads scalar columns only; term/meta caches would
        // multiply the queries for data nothing here touches.
        $this->assertFalse( $args['update_post_term_cache'] );
        $this->assertFalse( $args['update_post_meta_cache'] );
    }

    public function test_inventory_includes_products_only_with_woocommerce(): void {
        $this->api->handle_inventory( $this->makeRequest() );
        $this->assertSame( array( 'page', 'post' ), WP_Query::$calls[0]['post_type'] );
    }

    public function test_inventory_clamps_per_page(): void {
        // The backend paginates at 100; a caller asking for 5000 must not turn
        // one request into a full table dump.
        $this->api->handle_inventory( $this->makeRequest( array( 'per_page' => 5000 ) ) );
        $this->assertSame( 100, WP_Query::$calls[0]['posts_per_page'] );

        $this->api->handle_inventory( $this->makeRequest( array( 'per_page' => 25 ) ) );
        $this->assertSame( 25, WP_Query::$calls[1]['posts_per_page'] );

        // Garbage must not become posts_per_page => 0 or -1 (unbounded).
        $this->api->handle_inventory( $this->makeRequest( array( 'per_page' => -3 ) ) );
        $this->assertSame( 100, WP_Query::$calls[2]['posts_per_page'] );
    }

    public function test_inventory_floors_page_at_one(): void {
        $this->api->handle_inventory( $this->makeRequest( array( 'page' => 0 ) ) );
        $this->assertSame( 1, WP_Query::$calls[0]['paged'] );
    }

    public function test_inventory_on_empty_site(): void {
        $response = $this->api->handle_inventory( $this->makeRequest() );

        $this->assertSame( array(), $response['items'] );
        $this->assertFalse( $response['has_more'] );
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function makePost( int $id, string $type, string $title, string $slug ): object {
        return (object) array(
            'ID'                => $id,
            'post_type'         => $type,
            'post_title'        => $title,
            'post_name'         => $slug,
            'post_status'       => 'publish',
            'post_modified_gmt' => '2026-08-01 10:30:00',
        );
    }

    /** @param array<string,mixed> $params */
    private function makeRequest( array $params = array() ): WP_REST_Request {
        return new WP_REST_Request( $params );
    }
}
