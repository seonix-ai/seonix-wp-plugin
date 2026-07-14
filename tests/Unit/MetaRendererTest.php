<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Meta_Bridge;
use Seonix_Meta_Renderer;

/**
 * Covers the standalone meta renderer's suppression contract — the property
 * that guarantees Seonix never double-emits meta tags next to an SEO plugin:
 *   • mode() clamps the stored option to auto|on|off;
 *   • should_output() honours the mode and, in auto mode, self-suppresses
 *     because FakeYoast makes an SEO engine "active" in the test env;
 *   • render_head() emits nothing when suppressed, and emits exactly one
 *     marker-wrapped block (description + OG + Twitter) when forced on;
 *   • filter_document_title() serves the stored SEO title only when allowed
 *     and never overrides an earlier filter's value.
 */
final class MetaRendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'esc_attr' )->alias(
			static fn ( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $s ) => trim( strip_tags( (string) $s ) )
		);
		Functions\when( 'esc_url' )->alias( static fn ( $u ) => str_replace( '&', '&#038;', (string) $u ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── mode() ───────────────────────────────────────────────────────────

	public function test_mode_clamps_unknown_values_to_auto(): void {
		Functions\when( 'get_option' )->justReturn( 'bogus' );
		$this->assertSame( 'auto', Seonix_Meta_Renderer::mode() );

		Functions\when( 'get_option' )->justReturn( 'on' );
		$this->assertSame( 'on', Seonix_Meta_Renderer::mode() );
	}

	// ─── should_output() ─────────────────────────────────────────────────

	public function test_auto_mode_suppresses_when_seo_engine_active(): void {
		// FakeYoast defines WPSEO_Options → detect_all() is non-empty.
		Functions\when( 'get_option' )->justReturn( 'auto' );
		$this->assertFalse( Seonix_Meta_Renderer::should_output() );
	}

	public function test_on_mode_forces_output_despite_engine(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		$this->assertTrue( Seonix_Meta_Renderer::should_output() );
	}

	public function test_off_mode_never_outputs(): void {
		Functions\when( 'get_option' )->justReturn( 'off' );
		$this->assertFalse( Seonix_Meta_Renderer::should_output() );
	}

	// ─── render_head() ───────────────────────────────────────────────────

	public function test_render_head_silent_when_suppressed(): void {
		Functions\when( 'get_option' )->justReturn( 'auto' ); // + FakeYoast → suppressed.

		$renderer = new Seonix_Meta_Renderer();
		ob_start();
		$renderer->render_head();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_head_emits_marked_block_when_on(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
			$own = array(
				Seonix_Meta_Bridge::META_TITLE    => 'SERP Title',
				Seonix_Meta_Bridge::META_DESC     => 'A "great" description.',
				Seonix_Meta_Bridge::META_FOCUS_KW => 'kw',
			);
			return isset( $own[ $key ] ) ? $own[ $key ] : '';
		} );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post-7/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_post_time' )->justReturn( '2026-07-13T10:00:00+00:00' );
		Functions\when( 'get_post_modified_time' )->justReturn( '2026-07-13T10:00:00+00:00' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$renderer = new Seonix_Meta_Renderer();
		ob_start();
		$renderer->render_head();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<!-- Seonix SEO -->', $html );
		$this->assertStringContainsString( '<!-- / Seonix SEO -->', $html );
		// Exactly ONE description tag, attribute-escaped.
		$this->assertSame( 1, substr_count( $html, '<meta name="description"' ) );
		$this->assertStringContainsString( 'A &quot;great&quot; description.', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="SERP Title"', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.com/post-7/"', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image"', $html );
		// No canonical — WordPress core already prints one for singular views.
		$this->assertStringNotContainsString( 'rel="canonical"', $html );
		// No robots — never risk flipping indexability.
		$this->assertStringNotContainsString( 'name="robots"', $html );
	}

	public function test_render_head_silent_when_post_has_no_meta(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( '' );

		$renderer = new Seonix_Meta_Renderer();
		ob_start();
		$renderer->render_head();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_head_silent_on_archives(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		Functions\when( 'is_singular' )->justReturn( false );

		$renderer = new Seonix_Meta_Renderer();
		ob_start();
		$renderer->render_head();
		$this->assertSame( '', ob_get_clean() );
	}

	// ─── filter_document_title() ─────────────────────────────────────────

	public function test_title_filter_serves_stored_seo_title(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->justReturn( 'SERP Title' );

		$renderer = new Seonix_Meta_Renderer();
		$this->assertSame( 'SERP Title', $renderer->filter_document_title( '' ) );
	}

	public function test_title_filter_respects_prior_filter_value(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );

		$renderer = new Seonix_Meta_Renderer();
		$this->assertSame( 'Someone Else', $renderer->filter_document_title( 'Someone Else' ) );
	}

	public function test_title_filter_passthrough_when_suppressed(): void {
		Functions\when( 'get_option' )->justReturn( 'auto' ); // + FakeYoast → suppressed.

		$renderer = new Seonix_Meta_Renderer();
		$this->assertSame( '', $renderer->filter_document_title( '' ) );
	}

	public function test_title_filter_passthrough_when_no_seo_title(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$renderer = new Seonix_Meta_Renderer();
		$this->assertSame( '', $renderer->filter_document_title( '' ) );
	}
}
