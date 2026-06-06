<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Seonix_REST_API;

/**
 * Covers the key-takeaways callout block contract introduced in plugin 2.2.0:
 *   • only well-formed accent colours reach the inline-style attribute;
 *   • bullet sanitisation drops empty/whitespace-only entries;
 *   • the rendered <aside> carries the expected CSS hooks so the bundled
 *     stylesheet (assets/seonix-content.css) can target it; and
 *   • a brand accent threads through as a CSS custom property without
 *     breaking out of the attribute.
 *
 * The methods under test are private — exercised via reflection so the
 * production class doesn't grow a public surface just for tests.
 */
final class KeyTakeawaysTest extends TestCase {

    private Seonix_REST_API $api;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Minimal WP function aliases so the production code paths we exercise
        // behave the way they would inside WordPress.
        Functions\when( 'sanitize_text_field' )->alias(
            static fn ( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : ''
        );
        Functions\when( 'esc_html' )->alias(
            static fn ( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
        );
        Functions\when( 'esc_attr' )->alias(
            static fn ( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
        );

        $this->api = new Seonix_REST_API();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ─── sanitize_brand_accent ────────────────────────────────────────────

    public function test_sanitize_brand_accent_accepts_canonical_hex(): void {
        $this->assertSame( '#a931fb', $this->call( 'sanitize_brand_accent', '#a931fb' ) );
    }

    public function test_sanitize_brand_accent_lowercases_uppercase_input(): void {
        $this->assertSame( '#a931fb', $this->call( 'sanitize_brand_accent', '#A931FB' ) );
    }

    public function test_sanitize_brand_accent_trims_surrounding_whitespace(): void {
        $this->assertSame( '#a931fb', $this->call( 'sanitize_brand_accent', "  #A931FB \n" ) );
    }

    public function test_sanitize_brand_accent_rejects_shorthand_hex(): void {
        $this->assertSame( '', $this->call( 'sanitize_brand_accent', '#abc' ) );
    }

    public function test_sanitize_brand_accent_rejects_css_keyword(): void {
        $this->assertSame( '', $this->call( 'sanitize_brand_accent', 'red' ) );
    }

    public function test_sanitize_brand_accent_rejects_javascript_url(): void {
        // Defence-in-depth: even though esc_attr() would neutralise this on
        // output, reject the value at parse time so it never lands in meta.
        $this->assertSame( '', $this->call( 'sanitize_brand_accent', 'javascript:alert(1)' ) );
    }

    public function test_sanitize_brand_accent_rejects_non_string(): void {
        $this->assertSame( '', $this->call( 'sanitize_brand_accent', null ) );
        $this->assertSame( '', $this->call( 'sanitize_brand_accent', array( '#a931fb' ) ) );
    }

    // ─── sanitize_takeaways_items ─────────────────────────────────────────

    public function test_sanitize_takeaways_items_drops_empty_and_whitespace(): void {
        $items = $this->call( 'sanitize_takeaways_items', array( 'First', '', '   ', 'Second' ) );
        $this->assertSame( array( 'First', 'Second' ), $items );
    }

    public function test_sanitize_takeaways_items_strips_html_tags(): void {
        // sanitize_text_field strips tags but keeps any text content that was
        // inside them (the canonical WP behaviour). The contract that matters
        // for our callout block is that the bullet text never carries raw
        // markup into esc_html() — `<script>` itself must be gone.
        $items = $this->call( 'sanitize_takeaways_items', array( 'Plain', '<script>alert(1)</script>Bad' ) );
        $this->assertCount( 2, $items );
        $this->assertSame( 'Plain', $items[0] );
        $this->assertStringNotContainsString( '<', $items[1] );
        $this->assertStringNotContainsString( '>', $items[1] );
    }

    public function test_sanitize_takeaways_items_returns_empty_for_non_array(): void {
        $this->assertSame( array(), $this->call( 'sanitize_takeaways_items', null ) );
        $this->assertSame( array(), $this->call( 'sanitize_takeaways_items', 'not-an-array' ) );
    }

    // ─── build_takeaways_block ────────────────────────────────────────────

    public function test_build_takeaways_block_returns_empty_when_no_items(): void {
        $this->assertSame( '', $this->call( 'build_takeaways_block', array(), 'Heading', '' ) );
    }

    public function test_build_takeaways_block_emits_seonix_css_classes(): void {
        $html = $this->call(
            'build_takeaways_block',
            array( 'Ship small', 'Iterate often' ),
            'Key takeaways',
            ''
        );

        // The classes are the styling contract with the bundled stylesheet.
        // The previous `__head` wrapper was dropped when the callout moved
        // from a single wp:html block to native wp:group + wp:heading + wp:list,
        // since the heading now stands on its own as a Gutenberg block and
        // doesn't need an inner row wrapper.
        $this->assertStringContainsString( 'class="wp-block-group seonix-key-takeaways"', $html );
        $this->assertStringContainsString( 'seonix-key-takeaways__title', $html );
        $this->assertStringContainsString( 'seonix-key-takeaways__list', $html );
        $this->assertStringContainsString( 'class="seonix-key-takeaways__item"', $html );

        // Wrapped as native Gutenberg group + heading + list blocks so the
        // callout is editable inside the WP admin Gutenberg editor instead
        // of showing up as an opaque "Custom HTML" block.
        $this->assertStringStartsWith( '<!-- wp:group ', $html );
        $this->assertStringEndsWith( '<!-- /wp:group -->', $html );
        $this->assertStringContainsString( '<!-- wp:heading ', $html );
        $this->assertStringContainsString( '<!-- wp:list ', $html );
        $this->assertStringContainsString( '<!-- wp:list-item -->', $html );
    }

    public function test_build_takeaways_block_threads_accent_into_inline_style(): void {
        $html = $this->call(
            'build_takeaways_block',
            array( 'A bullet' ),
            'Heading',
            '#27b5fa'
        );

        // The CSS custom property is the contract the bundled stylesheet reads.
        $this->assertStringContainsString( 'style="--seonix-accent: #27b5fa;"', $html );
    }

    public function test_build_takeaways_block_omits_inline_style_when_no_accent(): void {
        $html = $this->call(
            'build_takeaways_block',
            array( 'A bullet' ),
            'Heading',
            ''
        );
        $this->assertStringNotContainsString( 'style=', $html );
    }

    public function test_build_takeaways_block_escapes_bullet_text(): void {
        $html = $this->call(
            'build_takeaways_block',
            array( '<script>alert(1)</script>' ),
            'Heading',
            ''
        );
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
    }

    public function test_build_takeaways_block_drops_heading_when_blank(): void {
        $html = $this->call(
            'build_takeaways_block',
            array( 'A' ),
            '   ',
            ''
        );
        $this->assertStringNotContainsString( 'seonix-key-takeaways__head', $html );
        $this->assertStringNotContainsString( '<h2', $html );
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
