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
 *     when a competing SEO plugin owns the graph;
 *   • supplemental_types() exposes the allowlist through the
 *     `seonix_schema_supplemental_types` filter without ever weakening the
 *     engine-owned-type drop in supplemental_only().
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

	public function test_sanitize_jsonld_keeps_unicode_readable(): void {
		// Umlauts and € must come back as plain UTF-8, not \uXXXX escapes:
		// escaped forms are what the historic meta_input unslash bug corrupted.
		$out = Seonix_Schema::sanitize_jsonld(
			'{"@context":"https://schema.org","@graph":[{"@type":"FAQPage","name":"Was kostet die Möbelmontage?","priceRange":"€€"}]}'
		);
		$this->assertIsString( $out );
		$this->assertStringContainsString( 'Möbelmontage', $out );
		$this->assertStringContainsString( '€€', $out );
		$this->assertStringNotContainsString( '\\u00f6', $out );
	}

	// ─── heal_escapes: repair of backslash-eaten \uXXXX payloads ──────────

	public function test_heal_escapes_restores_eaten_backslashes(): void {
		// Real corruption shape from ≤ 2.12.10: meta_input went through
		// wp_unslash, so "€" was stored as "u20ac" and "ö" as "u00f6".
		$broken = '{"@context":"https://schema.org","@graph":[{"@type":"GeneralContractor","priceRange":"u20acu20ac"},{"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Was kostet die Mu00f6belmontage?","acceptedAnswer":{"@type":"Answer","text":"Kleinere Auftru00e4ge nach Stundensatz u2013 einfach anfragen."}}]}]}';
		$healed = Seonix_Schema::heal_escapes( $broken );
		$this->assertIsString( $healed );
		$this->assertStringContainsString( '€€', $healed );
		$this->assertStringContainsString( 'Möbelmontage', $healed );
		$this->assertStringContainsString( 'Aufträge nach Stundensatz –', $healed );
		$this->assertStringNotContainsString( 'u20ac', $healed );
		$this->assertNotNull( json_decode( $healed, true ) );
	}

	public function test_heal_escapes_returns_null_when_nothing_to_heal(): void {
		// A healthy plain-UTF-8 payload has no orphaned uXXXX sequences.
		$clean = Seonix_Schema::sanitize_jsonld(
			'{"@context":"https://schema.org","@graph":[{"@type":"FAQPage","name":"Möbelmontage €€"}]}'
		);
		$this->assertNull( Seonix_Schema::heal_escapes( $clean ) );
	}

	public function test_heal_escapes_is_idempotent(): void {
		$broken = '{"@context":"https://schema.org","@graph":[{"@type":"FAQPage","name":"Mu00f6bel"}]}';
		$healed = Seonix_Schema::heal_escapes( $broken );
		$this->assertIsString( $healed );
		$this->assertNull( Seonix_Schema::heal_escapes( $healed ) );
	}

	public function test_heal_escapes_ignores_properly_escaped_sequences(): void {
		// An intact escape (backslash-u00f6) must not match the orphan pattern
		// — the negative lookbehind excludes sequences that kept their slash.
		$intact = '{"@context":"https://schema.org","@graph":[{"@type":"FAQPage","name":"M\\u00f6bel"}]}';
		$this->assertNull( Seonix_Schema::heal_escapes( $intact ) );
	}

	public function test_heal_escapes_returns_null_when_repair_stays_invalid(): void {
		// Matches the corruption pattern but is not valid JSON even repaired.
		$this->assertNull( Seonix_Schema::heal_escapes( '{broken u20ac payload' ) );
	}

	public function test_supplemental_only_keeps_service_node(): void {
		// A Service node (provider-referencing a business @id) must survive the
		// supplemental filter under an active engine — engines never emit it.
		$graph = '{"@context":"https://schema.org","@graph":[{"@type":"Service","name":"Möbelmontage Oranienburg","provider":{"@id":"https://example.com/#organization"}},{"@type":"WebPage","name":"drop me"}]}';
		$out   = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '"@type":"Service"', $out );
		$this->assertStringNotContainsString( 'WebPage', $out );
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

	public function test_supplemental_only_keeps_review_and_itemlist(): void {
		// A testimonial page stores Review nodes and a service-area hub stores
		// an ItemList (the wohnartstudio.de shape). No engine emits either, so
		// both must survive under an active engine; the WebPage is Yoast's.
		$graph = wp_json_encode( array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebPage', 'name' => 'Bewertungen' ),
				array(
					'@type'      => 'Review',
					'reviewBody' => 'Sehr zuverlässig, gerne wieder.',
					'author'     => array( '@type' => 'Person', 'name' => 'M. K.' ),
				),
				array(
					'@type'           => 'ItemList',
					'itemListElement' => array(
						array(
							'@type'    => 'ListItem',
							'position' => 1,
							'url'      => 'https://example.com/moebelmontage/berlin/',
						),
					),
				),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '"@type":"Review"', $out );
		$this->assertStringContainsString( '"@type":"ItemList"', $out );
		$this->assertStringNotContainsString( 'WebPage', $out );
	}

	public function test_supplemental_only_escapes_slashes_to_protect_script_tag(): void {
		// supplemental_only re-encodes through wp_json_encode just like
		// sanitize_jsonld — a kept node containing "</script>" must come back
		// slash-escaped so it cannot terminate the surrounding <script> block.
		$graph = wp_json_encode( array(
			'@graph' => array(
				array( '@type' => 'Review', 'reviewBody' => 'a</script>b' ),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringNotContainsString( '</script>', $out );
		$this->assertStringContainsString( '<\/script>', $out );
	}

	// ─── supplemental_types: the allowlist filter ─────────────────────────

	public function test_supplemental_and_engine_owned_lists_never_overlap(): void {
		// Design invariant from the SUPPLEMENTAL_TYPES docblock: a type in both
		// lists would be dead weight (the engine-owned drop always wins).
		$this->assertSame(
			array(),
			array_intersect( Seonix_Schema::SUPPLEMENTAL_TYPES, Seonix_Schema::ENGINE_OWNED_TYPES )
		);
	}

	public function test_supplemental_types_defaults_include_review_and_itemlist(): void {
		// No filter registered → apply_filters passes the built-in list through.
		$types = Seonix_Schema::supplemental_types();
		$this->assertContains( 'FAQPage', $types );
		$this->assertContains( 'Review', $types );
		$this->assertContains( 'ItemList', $types );
	}

	public function test_supplemental_types_filter_extends_allowlist(): void {
		// A site can allow an extra @type without forking the plugin.
		Monkey\Filters\expectApplied( 'seonix_schema_supplemental_types' )
			->andReturnUsing( static function ( array $types ) {
				$types[] = 'Product';
				return $types;
			} );

		$graph = wp_json_encode( array(
			'@graph' => array(
				array( '@type' => 'Product', 'name' => 'Regal nach Maß' ),
				array( '@type' => 'WebPage', 'name' => 'drop me' ),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '"@type":"Product"', $out );
		$this->assertStringNotContainsString( 'WebPage', $out );
	}

	public function test_supplemental_types_filter_cannot_bypass_engine_owned_drop(): void {
		// Filtering IN an engine-owned type must not reintroduce a duplicate
		// core graph: the ENGINE_OWNED_TYPES drop runs regardless of the
		// allowlist, for single- and multi-typed nodes alike.
		Monkey\Filters\expectApplied( 'seonix_schema_supplemental_types' )
			->andReturnUsing( static function ( array $types ) {
				$types[] = 'Article';
				return $types;
			} );

		$graph = wp_json_encode( array(
			'@graph' => array(
				array( '@type' => 'Article', 'headline' => 'still dropped' ),
				array( '@type' => array( 'Review', 'Article' ), 'reviewBody' => 'dropped too' ),
				array( '@type' => 'FAQPage', 'mainEntity' => array() ),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringNotContainsString( 'Article', $out );
		$this->assertStringNotContainsString( 'Review', $out );
		$this->assertStringContainsString( 'FAQPage', $out );
	}

	public function test_supplemental_types_discards_non_string_filter_entries(): void {
		// A sloppy callback mixing garbage into the list must not break the
		// array_intersect in supplemental_only — non-strings are discarded.
		Monkey\Filters\expectApplied( 'seonix_schema_supplemental_types' )
			->andReturn( array( 'Review', 123, null, array( 'nested' ), 'ItemList' ) );

		$this->assertSame( array( 'Review', 'ItemList' ), Seonix_Schema::supplemental_types() );
	}

	public function test_supplemental_types_filter_can_remove_a_default(): void {
		// Returning a reduced list suppresses a default type — an operator can
		// opt a site out of e.g. Review while keeping FAQ output.
		Monkey\Filters\expectApplied( 'seonix_schema_supplemental_types' )
			->andReturnUsing( static function ( array $types ) {
				return array_values( array_diff( $types, array( 'Review' ) ) );
			} );

		$graph = wp_json_encode( array(
			'@graph' => array(
				array( '@type' => 'Review', 'reviewBody' => 'suppressed' ),
				array( '@type' => 'FAQPage', 'mainEntity' => array() ),
			),
		) );
		$out = Seonix_Schema::supplemental_only( $graph );
		$this->assertIsString( $out );
		$this->assertStringNotContainsString( 'Review', $out );
		$this->assertStringContainsString( 'FAQPage', $out );
	}
}
