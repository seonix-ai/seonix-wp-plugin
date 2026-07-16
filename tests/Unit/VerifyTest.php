<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_REST_API;
use WP_REST_Request;

/**
 * Covers the self-configure contract of `handle_verify` (plugin 2.3.1+).
 *
 * The Seonix backend calls `GET /wp-json/seonix/v1/verify` with query
 * params `engine_url`, `project_id`, `project_name`. handle_verify saves
 * the accepted values into options so outbound sync and the
 * Settings → Seonix UI track the current backend without manual operator
 * edits. Older backends that don't pass these params keep working —
 * handle_verify just skips empty values.
 */
final class VerifyTest extends TestCase {

    private Seonix_REST_API $api;

    /** Captured options written during the request, keyed by option name. */
    private array $updated = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // handle_verify now runs the SEO-engine detection (detect_all() probes
        // is_plugin_active for the non-Yoast engines) — stub it so these
        // pre-existing verify-contract tests exercise their own path only.
        Functions\when( 'is_plugin_active' )->justReturn( false );

        $this->updated = [];

        // Skip the `if ( ! is_connected() )` branch — those writes aren't
        // what these tests are about.
        Functions\when( 'get_option' )->alias(
            static fn ( $name, $default = '' ) => $name === 'seonix_connected' ? true : $default
        );

        Functions\when( 'sanitize_text_field' )->alias(
            static fn ( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : ''
        );
        Functions\when( 'esc_url_raw' )->alias(
            static fn ( $value ) => is_string( $value ) ? filter_var( $value, FILTER_SANITIZE_URL ) : ''
        );
        // wp_parse_url + gethostbynamel are provided by tests/bootstrap.php
        // and PHP itself — Seonix_Sync::is_safe_url uses them directly.
        Functions\when( 'get_bloginfo' )->justReturn( 'Example Studio' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'rest_ensure_response' )->returnArg();

        // Capture every update_option(name, value) so tests can assert which
        // ones the handler wrote without round-tripping through a real DB.
        $captured =& $this->updated;
        Functions\when( 'update_option' )->alias(
            static function ( $name, $value ) use ( &$captured ) {
                $captured[ $name ] = $value;
                return true;
            }
        );

        $this->api = new Seonix_REST_API();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_verify_saves_metadata_from_query_params(): void {
        // example.com resolves to a real public IP, so the SSRF guard
        // (Seonix_Sync::is_safe_url) accepts it without a DNS stub.
        $request = $this->makeRequest( [
            'engine_url'   => 'https://example.com',
            'project_id'   => 'c48ddd33-c2fa-45bc-b3b0-956fe1234567',
            'project_name' => 'Example',
        ] );

        $this->api->handle_verify( $request );

        $this->assertSame(
            'https://example.com',
            $this->updated['seonix_engine_url'] ?? null,
            'engine_url should be persisted to seonix_engine_url option'
        );
        $this->assertSame(
            'c48ddd33-c2fa-45bc-b3b0-956fe1234567',
            $this->updated['seonix_project_id'] ?? null
        );
        $this->assertSame( 'Example', $this->updated['seonix_project_name'] ?? null );
    }

    public function test_verify_skips_empty_params_for_backwards_compat(): void {
        // Pre-2.3.1 backends don't pass these. handle_verify must NOT
        // overwrite existing options with empty strings — otherwise an old
        // backend would wipe engine_url and break outbound sync.
        $request = $this->makeRequest( [] );

        $this->api->handle_verify( $request );

        $this->assertArrayNotHasKey( 'seonix_engine_url', $this->updated );
        $this->assertArrayNotHasKey( 'seonix_project_id', $this->updated );
        $this->assertArrayNotHasKey( 'seonix_project_name', $this->updated );
    }

    public function test_verify_returns_site_metadata(): void {
        $request = $this->makeRequest( [] );

        $response = $this->api->handle_verify( $request );

        // Contract extended (SEO Meta Bridge): verify now also reports the
        // site's detected SEO engine(s) and the current meta mode so the
        // backend can decide whether the reverse SEO-meta sync is usable.
        // FakeYoast makes Yoast the sole active engine; meta_mode defaults to
        // 'auto' (get_option is stubbed to return the default here).
        // 2.11.0: `version` — the dashboard's integrations page reads it to
        // show the installed version and flag an available update. Note the
        // two distinct `version` keys: the top-level one is OUR plugin, the
        // nested one belongs to the detected third-party SEO engine.
        $this->assertSame( [
            'site_name'   => 'Example Studio',
            'site_url'    => 'https://example.com',
            'version'     => SEONIX_VERSION,
            'seo_engines' => [
                [ 'key' => 'yoast', 'version' => '99.9-test', 'primary' => true ],
            ],
            'meta_mode'   => 'auto',
        ], $response );
    }

    private function makeRequest( array $params ): WP_REST_Request {
        return new WP_REST_Request( $params );
    }
}
