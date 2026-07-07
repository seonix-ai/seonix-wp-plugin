<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Schema;

/**
 * Covers the JSON-LD structured-data contract added for auto-schema-at-publish:
 *   • sanitize_jsonld validates + slash-escapes the payload (so it can't break
 *     out of the surrounding <script>) and rejects empty/oversized/non-schema
 *     input;
 *   • mode() clamps the stored option to auto|on|off;
 *   • detect_active_engine() recognises an active SEO plugin (FakeYoast defines
 *     WPSEO_Options in the test bootstrap);
 *   • should_output() honours the mode and, in auto mode, suppresses output
 *     when a competing SEO plugin owns the graph.
 */
final class SchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// wp_json_encode is provided by the test bootstrap (a real json_encode
		// wrapper — slashes escaped by default), so we don't stub it here.
		// No SEO plugin "file" active by default; class presence (FakeYoast) is
		// what detect() keys on in the test environment.
		Functions\when( 'is_plugin_active' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── sanitize_jsonld ──────────────────────────────────────────────────

	public function test_sanitize_jsonld_accepts_graph_envelope(): void {
		$out = Seonix_Schema::sanitize_jsonld( '{"@context":"https://schema.org","@graph":[{"@type":"Article"}]}' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '"@type":"Article"', $out );
	}

	public function test_sanitize_jsonld_accepts_single_node_with_context(): void {
		$out = Seonix_Schema::sanitize_jsonld( '{"@context":"https://schema.org","@type":"WebPage"}' );
		$this->assertIsString( $out );
	}

	public function test_sanitize_jsonld_escapes_slashes_to_protect_script_tag(): void {
		// A value containing "</script>" must come back slash-escaped so it
		// cannot terminate the surrounding <script> block.
		$out = Seonix_Schema::sanitize_jsonld(
			'{"@context":"https://schema.org","@graph":[{"@type":"Article","headline":"a</script>b"}]}'
		);
		$this->assertIsString( $out );
		$this->assertStringNotContainsString( '</script>', $out );
		$this->assertStringContainsString( '<\/script>', $out );
	}

	public function test_sanitize_jsonld_rejects_non_string(): void {
		$this->assertNull( Seonix_Schema::sanitize_jsonld( array( 'x' => 1 ) ) );
		$this->assertNull( Seonix_Schema::sanitize_jsonld( null ) );
	}

	public function test_sanitize_jsonld_rejects_empty(): void {
		$this->assertNull( Seonix_Schema::sanitize_jsonld( '   ' ) );
	}

	public function test_sanitize_jsonld_rejects_invalid_json(): void {
		$this->assertNull( Seonix_Schema::sanitize_jsonld( '{not json' ) );
	}

	public function test_sanitize_jsonld_rejects_non_schema_json(): void {
		// Parses fine but isn't a schema.org document (no @context / @graph).
		$this->assertNull( Seonix_Schema::sanitize_jsonld( '{"foo":"bar"}' ) );
	}

	public function test_sanitize_jsonld_rejects_context_not_pointing_at_schema_org(): void {
		// Has @context but it doesn't point at schema.org and there's no @graph.
		$this->assertNull( Seonix_Schema::sanitize_jsonld( '{"@context":"https://evil.example","@type":"Article"}' ) );
	}

	public function test_sanitize_jsonld_rejects_oversized(): void {
		$huge = '{"@context":"https://schema.org","x":"' . str_repeat( 'a', 100001 ) . '"}';
		$this->assertNull( Seonix_Schema::sanitize_jsonld( $huge ) );
	}

	// ─── mode ─────────────────────────────────────────────────────────────

	public function test_mode_defaults_to_auto(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default = false ) => $default
		);
		$this->assertSame( 'auto', Seonix_Schema::mode() );
	}

	public function test_mode_passes_known_values_and_clamps_unknown(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		$this->assertSame( 'on', Seonix_Schema::mode() );

		Functions\when( 'get_option' )->justReturn( 'off' );
		$this->assertSame( 'off', Seonix_Schema::mode() );

		Functions\when( 'get_option' )->justReturn( 'garbage' );
		$this->assertSame( 'auto', Seonix_Schema::mode() );
	}

	// ─── detect / should_output ───────────────────────────────────────────

	public function test_detect_active_engine_recognises_yoast_class(): void {
		// FakeYoast (loaded by the test bootstrap) defines WPSEO_Options.
		$this->assertSame( 'yoast', Seonix_Schema::detect_active_engine() );
	}

	public function test_should_output_off_is_false(): void {
		Functions\when( 'get_option' )->justReturn( 'off' );
		$this->assertFalse( Seonix_Schema::should_output() );
	}

	public function test_should_output_on_is_true_even_with_seo_plugin(): void {
		Functions\when( 'get_option' )->justReturn( 'on' );
		$this->assertTrue( Seonix_Schema::should_output() );
	}

	public function test_should_output_auto_suppresses_when_seo_plugin_active(): void {
		// auto + Yoast present (FakeYoast) → don't duplicate its graph.
		Functions\when( 'get_option' )->justReturn( 'auto' );
		$this->assertFalse( Seonix_Schema::should_output() );
	}

	// ─── supplemental_only: LocalBusiness survives under an active engine ────

	public function test_supplemental_only_keeps_localbusiness_and_faq_drops_core(): void {
		// Under an active engine (Yoast), supplemental_only must keep the
		// LocalBusiness + FAQPage nodes (engines don't emit them) and drop the
		// engine-owned Article / WebPage so we never duplicate the core graph.
		$graph = wp_json_encode( array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'Article', 'headline' => 'x' ),
				array( '@type' => 'WebPage', 'name' => 'x' ),
				array( '@type' => 'HomeAndConstructionBusiness', 'name' => 'Wohnart', 'telephone' => '+49 1' ),
				array( '@type' => 'FAQPage', 'mainEntity' => array() ),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '"@type":"HomeAndConstructionBusiness"', $out );
		$this->assertStringContainsString( '"@type":"FAQPage"', $out );
		$this->assertStringNotContainsString( 'Article', $out );
		$this->assertStringNotContainsString( 'WebPage', $out );
	}

	public function test_supplemental_only_drops_multityped_localbusiness_organization(): void {
		// A node ALSO tagged Organization (engine-owned) must be dropped, so a
		// multi-typed node can never reintroduce a duplicate Organization.
		$graph = wp_json_encode( array(
			'@graph' => array(
				array( '@type' => array( 'LocalBusiness', 'Organization' ), 'name' => 'x' ),
				array( '@type' => 'FAQPage', 'mainEntity' => array() ),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringNotContainsString( 'LocalBusiness', $out );
		$this->assertStringContainsString( 'FAQPage', $out );
	}
}
