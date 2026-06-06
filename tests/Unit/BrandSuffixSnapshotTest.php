<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Seonix_REST_API;

/**
 * Covers the brand-suffix context added to the per-post snapshot in 2.2.1
 * (Bug 4 — title_too_long after AI rewrite). The Seonix backend reads
 * `yoast_title_template` + `blogname` to size the AI title-suggester's
 * character budget so the meta title plus Yoast's appended sitename suffix
 * stays under the rendered <title> length cap.
 *
 * The lookup method is private — exercised via reflection so the production
 * class doesn't grow a public surface just for tests.
 */
final class BrandSuffixSnapshotTest extends TestCase {

    private Seonix_REST_API $api;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // sanitize_key: WP's canonical implementation lowercases and strips to
        // [a-z0-9_-]. A minimal alias is enough for the post-type paths we test.
        Functions\when( 'sanitize_key' )->alias(
            static function ( $value ) {
                $value = is_string( $value ) ? strtolower( $value ) : '';
                return preg_replace( '/[^a-z0-9_\-]/', '', $value );
            }
        );

        // wp_strip_all_tags: production strips script/style first then any tag.
        // The cheap alias is fine for assertions on template text.
        Functions\when( 'wp_strip_all_tags' )->alias(
            static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) )
        );

        // Fresh Yoast option store per test. The title-template reader talks
        // only to WPSEO_Options::get (the bootstrap defines the fake); an
        // un-seeded store reads back null, i.e. "no template configured".
        \WPSEO_Options::reset();

        $this->api = new Seonix_REST_API();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ─── get_yoast_title_template ─────────────────────────────────────────

    public function test_returns_null_when_post_type_empty(): void {
        // No options need to be read for the empty-input short-circuit.
        $this->assertNull( $this->call( 'get_yoast_title_template', '' ) );
        $this->assertNull( $this->call( 'get_yoast_title_template', null ) );
    }

    public function test_reads_title_template_via_yoast_options_api(): void {
        // Since 2.4.2 the reader only ever goes through Yoast's public option
        // accessor (WPSEO_Options::get('title-<type>')) — it never reads the
        // underlying wpseo_titles array directly. Seed the accessor and assert
        // the per-post-type template comes back verbatim.
        \WPSEO_Options::$store = array(
            'title-post' => '%%title%% %%sep%% %%sitename%%',
            'title-page' => '%%title%% — %%sitename%%',
        );

        $this->assertSame(
            '%%title%% %%sep%% %%sitename%%',
            $this->call( 'get_yoast_title_template', 'post' )
        );
        $this->assertSame(
            '%%title%% — %%sitename%%',
            $this->call( 'get_yoast_title_template', 'page' )
        );
    }

    public function test_returns_null_when_wpseo_titles_missing(): void {
        Functions\when( 'get_option' )->justReturn( false );
        $this->assertNull( $this->call( 'get_yoast_title_template', 'post' ) );
    }

    public function test_returns_null_when_post_type_key_missing(): void {
        Functions\when( 'get_option' )->alias(
            static function ( $name ) {
                if ( 'wpseo_titles' === $name ) {
                    return array( 'title-page' => '%%title%% — %%sitename%%' );
                }
                return false;
            }
        );
        // No `title-product` key configured by Yoast → backend should see null
        // and fall back to suffix length 0 (no shrinkage).
        $this->assertNull( $this->call( 'get_yoast_title_template', 'product' ) );
    }

    public function test_strips_tags_from_template(): void {
        // Defensive: wp_options is operator-controlled but defence-in-depth
        // is cheap — strip any tags so a malformed template never reaches the
        // JSON response with embedded markup.
        \WPSEO_Options::$store = array(
            'title-post' => '%%title%%<script>x</script> %%sitename%%',
        );
        $template = $this->call( 'get_yoast_title_template', 'post' );
        $this->assertIsString( $template );
        $this->assertStringNotContainsString( '<', $template );
        $this->assertStringNotContainsString( '>', $template );
        $this->assertStringContainsString( '%%title%%', $template );
        $this->assertStringContainsString( '%%sitename%%', $template );
    }

    public function test_returns_null_for_invalid_post_type_characters(): void {
        // sanitize_key strips disallowed chars; "../etc" → "etc" which won't
        // match a Yoast key, so the lookup returns null.
        Functions\when( 'get_option' )->alias(
            static function ( $name ) {
                if ( 'wpseo_titles' === $name ) {
                    return array( 'title-post' => '%%title%%' );
                }
                return false;
            }
        );
        $this->assertNull( $this->call( 'get_yoast_title_template', '../etc/passwd' ) );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Invoke a private method on the REST controller via reflection.
     */
    private function call( string $method, ...$args ) {
        $ref = new ReflectionClass( $this->api );
        $m   = $ref->getMethod( $method );
        $m->setAccessible( true );
        return $m->invokeArgs( $this->api, $args );
    }
}
