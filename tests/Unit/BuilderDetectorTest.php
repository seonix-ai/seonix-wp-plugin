<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Builder_Detector;

/**
 * Per-post page-builder detection. A post_content rewrite (broken_link /
 * broken_image) must SKIP builder-owned posts, so this class is the gate that
 * keeps those fixes from no-oping or corrupting a builder's serialized layout on
 * any customer site.
 */
final class BuilderDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Filter is a pass-through in tests unless a case overrides it.
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value = null ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $meta meta_key => value
	 */
	private function withMeta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias( static function ( $id, $key, $single = false ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );
	}

	public function test_elementor_builder_post_is_detected(): void {
		$this->withMeta( array( '_elementor_edit_mode' => 'builder' ) );
		$this->assertTrue( Seonix_Builder_Detector::post_uses_builder( 10 ) );
	}

	public function test_elementor_non_builder_edit_mode_is_not_detected(): void {
		// Elementor writes '_elementor_edit_mode' only when its editor owns the
		// page; any other value must NOT count.
		$this->withMeta( array( '_elementor_edit_mode' => 'editor' ) );
		$this->assertFalse( Seonix_Builder_Detector::post_uses_builder( 10 ) );
	}

	public function test_divi_builder_post_is_detected(): void {
		$this->withMeta( array( '_et_pb_use_builder' => 'on' ) );
		$this->assertTrue( Seonix_Builder_Detector::post_uses_builder( 11 ) );
	}

	public function test_beaver_builder_post_is_detected(): void {
		$this->withMeta( array( '_fl_builder_enabled' => '1' ) );
		$this->assertTrue( Seonix_Builder_Detector::post_uses_builder( 12 ) );
	}

	public function test_wpbakery_post_is_detected(): void {
		$this->withMeta( array( '_wpb_vc_js_status' => 'true' ) );
		$this->assertTrue( Seonix_Builder_Detector::post_uses_builder( 13 ) );
	}

	public function test_brizy_post_is_detected(): void {
		$this->withMeta( array( 'brizy_post_uid' => 'abc123' ) );
		$this->assertTrue( Seonix_Builder_Detector::post_uses_builder( 14 ) );
	}

	public function test_classic_gutenberg_post_is_not_a_builder(): void {
		// No builder meta at all — a plain classic/Gutenberg post is safe to rewrite.
		$this->withMeta( array() );
		$this->assertFalse( Seonix_Builder_Detector::post_uses_builder( 15 ) );
	}

	public function test_empty_and_zero_presence_meta_do_not_false_positive(): void {
		// WordPress returns '' for absent meta; '0'/'' must not be read as "enabled".
		$this->withMeta( array( '_fl_builder_enabled' => '' ) );
		$this->assertFalse( Seonix_Builder_Detector::post_uses_builder( 16 ) );
	}

	public function test_invalid_post_id_is_not_a_builder(): void {
		$this->withMeta( array( '_elementor_edit_mode' => 'builder' ) );
		$this->assertFalse( Seonix_Builder_Detector::post_uses_builder( 0 ) );
	}

	public function test_filter_can_force_builder_verdict(): void {
		$this->withMeta( array() ); // not a builder by meta
		Functions\when( 'apply_filters' )->alias( static function ( $tag, $value = null, $post_id = 0 ) {
			return ( 'seonix_post_uses_builder' === $tag ) ? true : $value;
		} );
		$this->assertTrue(
			Seonix_Builder_Detector::post_uses_builder( 17 ),
			'The seonix_post_uses_builder filter must be able to force a post to be treated as builder-owned.'
		);
	}
}
