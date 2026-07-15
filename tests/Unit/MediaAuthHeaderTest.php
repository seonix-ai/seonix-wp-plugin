<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_REST_API;

/**
 * Covers the Authorization header the sideloader attaches when fetching inline
 * images from the Seonix engine.
 *
 * Seonix is not a CDN: /api/uploads is moving to authenticated-only so customer
 * pages stop hotlinking it. This plugin sideloads those images server-side,
 * where no browser session exists, so the fetch has to carry the sx_ secret.
 *
 * The security-critical half is the NEGATIVE case: sideload_inline_images_in_post
 * also downloads third-party images referenced in an article body, and the key
 * must never ride along to them.
 */
final class MediaAuthHeaderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // wp_parse_url comes from the shared test stubs — it is defined before
        // Patchwork loads, so it must not be re-stubbed here.
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stubs the two options the code reads: the engine origin and the API key
     * (Seonix_Auth::get_key() reads seonix_api_key).
     */
    private function stubOptions( string $engineUrl, string $apiKey = 'sx_test_secret' ): void {
        Functions\when( 'get_option' )->alias(
            static function ( $name, $default = '' ) use ( $engineUrl, $apiKey ) {
                if ( 'seonix_engine_url' === $name ) {
                    return $engineUrl;
                }
                if ( 'seonix_api_key' === $name ) {
                    return $apiKey;
                }
                return $default;
            }
        );
    }

    public function test_attaches_key_to_engine_uploads_url(): void {
        $this->stubOptions( 'https://api.seonix.ai' );

        $args = Seonix_REST_API::attach_seonix_media_auth_args(
            array(),
            'https://api.seonix.ai/api/uploads/1f0b/cover.webp'
        );

        $this->assertSame( 'Bearer sx_test_secret', $args['headers']['Authorization'] );
    }

    public function test_never_leaks_key_to_a_third_party_host(): void {
        // The sideloader downloads any external <img> in the body. A stock photo
        // host, an attacker-controlled host — none of them may see the secret.
        $this->stubOptions( 'https://api.seonix.ai' );

        foreach (
            array(
                'https://images.pexels.com/photos/1/x.jpg',
                'https://evil.example/api/uploads/x.webp',
                // Suffix-style lookalike: must NOT match the engine host.
                'https://evil-api.seonix.ai.attacker.test/api/uploads/x.webp',
            ) as $url
        ) {
            $args = Seonix_REST_API::attach_seonix_media_auth_args( array(), $url );
            $this->assertArrayNotHasKey(
                'Authorization',
                $args['headers'] ?? array(),
                "key leaked to {$url}"
            );
        }
    }

    public function test_no_key_outside_the_uploads_path(): void {
        // Same host, different path — nothing there needs the media secret.
        $this->stubOptions( 'https://api.seonix.ai' );

        $args = Seonix_REST_API::attach_seonix_media_auth_args(
            array(),
            'https://api.seonix.ai/api/projects/x'
        );

        $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? array() );
    }

    public function test_no_key_when_engine_url_unconfigured(): void {
        $this->stubOptions( '' );

        $args = Seonix_REST_API::attach_seonix_media_auth_args(
            array(),
            'https://api.seonix.ai/api/uploads/1f0b/cover.webp'
        );

        $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? array() );
    }

    public function test_no_key_when_plugin_has_no_api_key(): void {
        $this->stubOptions( 'https://api.seonix.ai', '' );

        $args = Seonix_REST_API::attach_seonix_media_auth_args(
            array(),
            'https://api.seonix.ai/api/uploads/1f0b/cover.webp'
        );

        $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? array() );
    }

    public function test_preserves_existing_headers(): void {
        $this->stubOptions( 'https://api.seonix.ai' );

        $args = Seonix_REST_API::attach_seonix_media_auth_args(
            array( 'headers' => array( 'X-Existing' => 'kept' ) ),
            'https://api.seonix.ai/api/uploads/1f0b/cover.webp'
        );

        $this->assertSame( 'kept', $args['headers']['X-Existing'] );
        $this->assertSame( 'Bearer sx_test_secret', $args['headers']['Authorization'] );
    }
}
