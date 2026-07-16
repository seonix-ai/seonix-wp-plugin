<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Sync;

/**
 * Locks Seonix_Sync::stamp_plugin_version, the `http_request_args` filter that
 * tells the backend which plugin version a site runs.
 *
 * The filter is global — WordPress runs it for every wp_remote_* call made by
 * any plugin on the site — so the leak tests here matter more than the happy
 * path: stamping a version header onto a third-party request would hand an
 * unrelated endpoint a fingerprint of the site's Seonix install.
 */
final class PluginVersionHeaderTest extends TestCase {

    private const ENGINE = 'https://api.seonix.ai';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'trailingslashit' )->alias( fn ( $v ) => rtrim( (string) $v, '/' ) . '/' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stamp( array $args, $url, string $engine = self::ENGINE ): array {
        Functions\when( 'get_option' )->justReturn( $engine );
        return Seonix_Sync::stamp_plugin_version( $args, $url );
    }

    public function test_stamps_version_on_seonix_api_calls(): void {
        $args = $this->stamp( array(), self::ENGINE . '/api/plugin/sync' );

        $this->assertSame( SEONIX_VERSION, $args['headers']['X-Seonix-Plugin-Version'] );
    }

    public function test_stamps_every_plugin_route_not_just_sync(): void {
        foreach ( array( 'sync', 'tasks', 'account', 'content-event', 'score-content' ) as $route ) {
            $args = $this->stamp( array(), self::ENGINE . '/api/plugin/' . $route );

            $this->assertSame(
                SEONIX_VERSION,
                $args['headers']['X-Seonix-Plugin-Version'],
                "/api/plugin/$route must carry the version header"
            );
        }
    }

    public function test_preserves_existing_headers(): void {
        $args = $this->stamp(
            array( 'headers' => array( 'Authorization' => 'Bearer sx_secret' ) ),
            self::ENGINE . '/api/plugin/sync'
        );

        $this->assertSame( 'Bearer sx_secret', $args['headers']['Authorization'] );
        $this->assertSame( SEONIX_VERSION, $args['headers']['X-Seonix-Plugin-Version'] );
    }

    public function test_does_not_leak_version_to_third_party_hosts(): void {
        $args = $this->stamp( array(), 'https://not-seonix.example/api/plugin/sync' );

        $this->assertArrayNotHasKey( 'headers', $args );
    }

    /**
     * A host that merely starts with the engine URL is a different origin:
     * `https://api.seonix.ai.evil.test` must not be treated as ours. Guarded by
     * the trailing slash trailingslashit() appends before the prefix compare.
     */
    public function test_does_not_leak_version_to_lookalike_host(): void {
        $args = $this->stamp( array(), self::ENGINE . '.evil.test/api/plugin/sync' );

        $this->assertArrayNotHasKey( 'headers', $args );
    }

    public function test_does_not_stamp_non_plugin_routes_on_our_own_host(): void {
        $args = $this->stamp( array(), self::ENGINE . '/api/public/landing/scan' );

        $this->assertArrayNotHasKey( 'headers', $args );
    }

    public function test_does_not_stamp_when_engine_url_is_unset(): void {
        $args = $this->stamp( array(), self::ENGINE . '/api/plugin/sync', '' );

        $this->assertArrayNotHasKey( 'headers', $args );
    }

    /**
     * WP_Http accepts a raw string for `headers`. Replacing it with an array
     * would silently drop whatever the caller set, so such requests pass
     * through untouched rather than getting corrupted for a version stamp.
     */
    public function test_leaves_string_headers_untouched(): void {
        $args = $this->stamp(
            array( 'headers' => "Authorization: Bearer sx_secret\r\n" ),
            self::ENGINE . '/api/plugin/sync'
        );

        $this->assertSame( "Authorization: Bearer sx_secret\r\n", $args['headers'] );
    }

    /**
     * `http_request_args` hands the URL through as-is, and WP core does not
     * guarantee a string (a filter earlier in the chain can return anything).
     * strpos() would fatal on null under PHP 8.
     */
    public function test_ignores_non_string_url(): void {
        $args = $this->stamp( array(), null );

        $this->assertArrayNotHasKey( 'headers', $args );
    }
}
