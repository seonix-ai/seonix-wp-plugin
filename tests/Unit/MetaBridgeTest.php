<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Meta_Bridge;
use Seonix_SEO_Engine;

/**
 * Covers the SEO meta bridge contract:
 *   • sanitize_value strips every engine's template-variable syntax so
 *     AI-written copy can never smuggle a %%title%% / %sep% / #post_title
 *     that the engine would expand at render time;
 *   • meta_input fans the fields out to the canonical `_seonix_*` keys plus
 *     every ACTIVE engine's postmeta keys (FakeYoast makes Yoast active in
 *     the test env) and stamps a fingerprint;
 *   • write() mirrors an existing post's fields the same way and re-stamps
 *     the fingerprint with the requested source;
 *   • fingerprints hash the field triple stably and decode defensively.
 */
final class MetaBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_plugin_active' )->justReturn( false );
		// Mirrors core's strip-then-collapse order. Deliberately NOT returnArg():
		// sanitize_value() leans on this to keep markup out of the values it fans
		// into other plugins' postmeta, so a passthrough stub would make the
		// stripping assertions below pass without stripping anything.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				$value = strip_tags( (string) $value );
				$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );
				return trim( $value );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── sanitize_value ───────────────────────────────────────────────────

	// Every value that passes through here is written verbatim into OTHER
	// plugins' postmeta (_yoast_wpseo_focuskw, rank_math_focus_keyword,
	// _seopress_analysis_target_kw, AIOSEO's keyphrases model), bypassing the
	// save-time handling those plugins apply to their own input. What they do
	// with it afterwards — their admin screens, their REST output, their
	// rendered tags — is outside this codebase. So the markup has to die here.
	// The REST/block-editor write path reaches storage through this function
	// alone; only the classic form sanitises upstream.
	public function test_sanitize_strips_markup_before_it_reaches_another_plugins_meta(): void {
		$this->assertSame(
			'standing desk',
			Seonix_Meta_Bridge::sanitize_value( '<b>standing</b> desk' )
		);

		// The classic attribute-breakout payload. What matters is that the
		// executable element is gone; the leftover `">` is inert text that the
		// render path escapes anyway (esc_attr), and asserting on the exact
		// remainder documents that we strip tags rather than mangle punctuation.
		$payload = Seonix_Meta_Bridge::sanitize_value( '"><img src=x onerror=alert(document.domain)>' );
		$this->assertSame( '">', $payload );
		$this->assertStringNotContainsString( 'onerror', $payload );
		$this->assertStringNotContainsString( '<img', $payload );
	}

	public function test_sanitize_strips_yoast_template_variables(): void {
		$this->assertSame(
			'My Great Post',
			Seonix_Meta_Bridge::sanitize_value( 'My %%sep%% Great %%sitename%% Post' )
		);
	}

	public function test_sanitize_strips_rankmath_template_variables(): void {
		$this->assertSame(
			'My Great Post',
			Seonix_Meta_Bridge::sanitize_value( 'My %sep% Great %sitename% Post' )
		);
	}

	public function test_sanitize_strips_known_aioseo_smart_tags_only(): void {
		// #post_title is an AIOSEO smart tag — stripped. "#seo" is an innocent
		// hashtag — preserved.
		$this->assertSame(
			'Read about #seo today',
			Seonix_Meta_Bridge::sanitize_value( 'Read #post_title about #seo today' )
		);
	}

	public function test_sanitize_preserves_percentages_in_normal_copy(): void {
		$this->assertSame(
			'Save 50% on hosting in 2026',
			Seonix_Meta_Bridge::sanitize_value( 'Save 50% on hosting in 2026' )
		);
	}

	public function test_sanitize_collapses_leftover_whitespace(): void {
		$this->assertSame(
			'Left Right',
			Seonix_Meta_Bridge::sanitize_value( 'Left %%sep%%  %%sitename%% Right' )
		);
	}

	// ─── meta_input (publish path) ────────────────────────────────────────

	public function test_meta_input_writes_canonical_and_active_engine_keys(): void {
		$input = Seonix_Meta_Bridge::meta_input( array(
			'seo_title'        => 'SERP Title',
			'meta_description' => 'A description.',
			'focus_keyword'    => 'main keyword',
		) );

		// Canonical Seonix keys — always.
		$this->assertSame( 'SERP Title', $input[ Seonix_Meta_Bridge::META_TITLE ] );
		$this->assertSame( 'A description.', $input[ Seonix_Meta_Bridge::META_DESC ] );
		$this->assertSame( 'main keyword', $input[ Seonix_Meta_Bridge::META_FOCUS_KW ] );

		// Active engine (FakeYoast in the test bootstrap) keys.
		$this->assertSame( 'SERP Title', $input['_yoast_wpseo_title'] );
		$this->assertSame( 'A description.', $input['_yoast_wpseo_metadesc'] );
		$this->assertSame( 'main keyword', $input['_yoast_wpseo_focuskw'] );

		// Inactive engines' keys must NOT be littered into the DB.
		$this->assertArrayNotHasKey( 'rank_math_title', $input );
		$this->assertArrayNotHasKey( '_seopress_titles_title', $input );
		$this->assertArrayNotHasKey( '_genesis_title', $input );

		// Fingerprint stamped as a Seonix-authored write.
		$fp = json_decode( $input[ Seonix_Meta_Bridge::META_FINGERPRINT ], true );
		$this->assertSame( 'seonix', $fp['src'] );
		$this->assertSame(
			Seonix_Meta_Bridge::hash_triple( 'SERP Title', 'A description.', 'main keyword' ),
			$fp['h']
		);
	}

	public function test_meta_input_skips_empty_fields_entirely(): void {
		$this->assertSame( array(), Seonix_Meta_Bridge::meta_input( array(
			'seo_title'        => '',
			'meta_description' => '',
		) ) );
	}

	public function test_meta_input_partial_fields_only_touch_their_keys(): void {
		$input = Seonix_Meta_Bridge::meta_input( array( 'meta_description' => 'Only desc.' ) );
		$this->assertArrayHasKey( Seonix_Meta_Bridge::META_DESC, $input );
		$this->assertArrayHasKey( '_yoast_wpseo_metadesc', $input );
		$this->assertArrayNotHasKey( Seonix_Meta_Bridge::META_TITLE, $input );
		$this->assertArrayNotHasKey( '_yoast_wpseo_title', $input );
	}

	// ─── write (fix / reverse-sync path) ─────────────────────────────────

	public function test_write_mirrors_to_canonical_and_engine_and_refingerprints(): void {
		Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
			// read_own during the fingerprint refresh sees the fresh values.
			$own = array(
				Seonix_Meta_Bridge::META_TITLE    => 'T',
				Seonix_Meta_Bridge::META_DESC     => 'D',
				Seonix_Meta_Bridge::META_FOCUS_KW => 'K',
			);
			return isset( $own[ $key ] ) ? $own[ $key ] : '';
		} );
		$writes = array();
		Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$writes ) {
			$writes[ $key ] = $value;
			return true;
		} );

		Seonix_Meta_Bridge::write( 7, array( 'seo_title' => 'T' ), 'wp:yoast' );

		$this->assertSame( 'T', $writes[ Seonix_Meta_Bridge::META_TITLE ] );
		$this->assertSame( 'T', $writes['_yoast_wpseo_title'] );
		$fp = json_decode( $writes[ Seonix_Meta_Bridge::META_FINGERPRINT ], true );
		$this->assertSame( 'wp:yoast', $fp['src'] );
		$this->assertSame( Seonix_Meta_Bridge::hash_triple( 'T', 'D', 'K' ), $fp['h'] );
	}

	public function test_write_sets_guard_flag_only_during_write(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$seen = null;
		Functions\when( 'update_post_meta' )->alias( function () use ( &$seen ) {
			$seen = Seonix_Meta_Bridge::$writing;
			return true;
		} );

		$this->assertFalse( Seonix_Meta_Bridge::$writing );
		Seonix_Meta_Bridge::write( 7, array( 'seo_title' => 'X' ) );
		$this->assertTrue( $seen, 'guard flag must be up during the write' );
		$this->assertFalse( Seonix_Meta_Bridge::$writing, 'guard flag must drop after the write' );
	}

	// ─── fingerprint ──────────────────────────────────────────────────────

	public function test_fingerprint_decodes_stored_json(): void {
		Functions\when( 'get_post_meta' )->justReturn(
			Seonix_Meta_Bridge::build_fingerprint( 'a', 'b', 'c', 'seonix' )
		);
		$fp = Seonix_Meta_Bridge::fingerprint( 7 );
		$this->assertSame( 'seonix', $fp['src'] );
		$this->assertSame( Seonix_Meta_Bridge::hash_triple( 'a', 'b', 'c' ), $fp['h'] );
	}

	public function test_fingerprint_null_on_missing_or_corrupt(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertNull( Seonix_Meta_Bridge::fingerprint( 7 ) );

		Functions\when( 'get_post_meta' )->justReturn( '{not json' );
		$this->assertNull( Seonix_Meta_Bridge::fingerprint( 7 ) );
	}

	// ─── engine detection additions ───────────────────────────────────────

	public function test_detect_all_reports_yoast_from_fake_and_maps_keys(): void {
		$engines = Seonix_SEO_Engine::detect_all();
		$this->assertContains( Seonix_SEO_Engine::YOAST, $engines );
		$this->assertSame( Seonix_SEO_Engine::YOAST, Seonix_SEO_Engine::detect() );

		// Storage mapping for the newly supported engines.
		$this->assertSame( '_seopress_titles_title', Seonix_SEO_Engine::post_title_key( Seonix_SEO_Engine::SEOPRESS ) );
		$this->assertSame( '_seopress_titles_desc', Seonix_SEO_Engine::post_desc_key( Seonix_SEO_Engine::SEOPRESS ) );
		$this->assertSame( '_genesis_title', Seonix_SEO_Engine::post_title_key( Seonix_SEO_Engine::TSF ) );
		$this->assertSame( '_genesis_description', Seonix_SEO_Engine::post_desc_key( Seonix_SEO_Engine::TSF ) );
		// AIOSEO stays postmeta-null (custom table — bridge writes via its model).
		$this->assertNull( Seonix_SEO_Engine::post_title_key( Seonix_SEO_Engine::AIOSEO ) );
		$this->assertNull( Seonix_SEO_Engine::post_desc_key( Seonix_SEO_Engine::AIOSEO ) );
		// Squirrly is detect-only.
		$this->assertNull( Seonix_SEO_Engine::post_title_key( Seonix_SEO_Engine::SQUIRRLY ) );
	}
}
