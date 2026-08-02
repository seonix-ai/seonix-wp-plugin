<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_SSL_Mixed_Content;
use Seonix_Fix_Single_Meta;
use Seonix_Fix_Term_Meta_Description;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Rollback stale-guards: a rollback must only run while the target still
 * holds exactly the value the apply wrote. If anything edited the value
 * after the fix (an author, another tool, a second fix), restoring the
 * pre-fix snapshot would silently erase that later change — the methods
 * must refuse with `rollback_stale` (409) instead.
 *
 * Pinned scenarios per method family:
 *  - single-meta (meta_title / meta_description / image_alt attachment meta)
 *  - ssl_mixed_content (full post_content snapshot restore — highest risk)
 *  - term_meta_description
 * plus the legacy escape hatch: entries without an after_state snapshot
 * (written by plugin versions that predate it) skip the guard.
 */
final class SeoFixRollbackStaleGuardTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_plugin_active' )->justReturn( false );

		\WPSEO_Taxonomy_Meta::reset();
		unset( $GLOBALS['wpdb'] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Concrete single-meta method pinned to a plain postmeta key (no bridge),
	 * so the base-class guard is exercised directly.
	 */
	private function plain_meta_method(): Seonix_Fix_Single_Meta {
		return new class( $this->history ) extends Seonix_Fix_Single_Meta {
			public function key(): string {
				return 'test_meta';
			}
			protected function meta_key(): string {
				return '_test_meta';
			}
			protected function target_type(): string {
				return 'post';
			}
		};
	}

	private function meta_entry(): array {
		return array(
			'target_id'    => 7,
			'before_state' => array( 'value' => 'Old title' ),
			'after_state'  => array( 'value' => 'New title' ),
		);
	}

	// ─── single-meta ─────────────────────────────────────────────────────

	public function test_single_meta_rollback_refuses_when_value_changed_since_apply(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->meta_entry() );
		Functions\when( 'get_post_meta' )->justReturn( 'Edited later by a human' );
		Functions\expect( 'update_post_meta' )->never();

		$r = $this->plain_meta_method()->rollback( 42 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'rollback_stale', $r->get_error_code() );
		$data = $r->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 'New title', $data['applied'] );
		$this->assertSame( 'Edited later by a human', $data['current'] );
	}

	public function test_single_meta_rollback_restores_when_value_untouched(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->meta_entry() );
		Functions\when( 'get_post_meta' )->justReturn( 'New title' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 7, '_test_meta', wp_slash( 'Old title' ) )
			->andReturn( true );

		$r = $this->plain_meta_method()->rollback( 42 );

		$this->assertIsArray( $r );
		$this->assertSame( 'Old title', $r['after']['value'] );
	}

	public function test_single_meta_legacy_entry_without_after_state_skips_guard(): void {
		$entry = $this->meta_entry();
		unset( $entry['after_state'] );
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $entry );
		// get_post_meta must not even be consulted — nothing to compare against.
		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 7, '_test_meta', wp_slash( 'Old title' ) )
			->andReturn( true );

		$r = $this->plain_meta_method()->rollback( 42 );

		$this->assertIsArray( $r );
		$this->assertSame( 'Old title', $r['after']['value'] );
	}

	// ─── ssl_mixed_content (full-snapshot restore) ───────────────────────

	private function ssl_entry(): array {
		return array(
			'target_id'    => 7,
			'before_state' => array( 'post_content' => 'See http://example.com/a' ),
			'after_state'  => array( 'post_content' => 'See https://example.com/a' ),
		);
	}

	public function test_ssl_rollback_refuses_when_content_edited_since_apply(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->ssl_entry() );
		Functions\when( 'get_post' )->justReturn( (object) array(
			'ID'           => 7,
			'post_content' => 'See https://example.com/a — plus a whole new paragraph.',
		) );
		Functions\expect( 'wp_update_post' )->never();

		$r = ( new Seonix_Fix_SSL_Mixed_Content( $this->history ) )->rollback( 42 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'rollback_stale', $r->get_error_code() );
		$this->assertSame( 409, $r->get_error_data()['status'] );
	}

	public function test_ssl_rollback_restores_when_content_untouched(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->ssl_entry() );
		Functions\when( 'get_post' )->justReturn( (object) array(
			'ID'                => 7,
			'post_content'      => 'See https://example.com/a',
			'post_modified_gmt' => '',
		) );
		Functions\expect( 'wp_update_post' )
			->once()
			->andReturnUsing( function ( $postarr ) {
				\PHPUnit\Framework\Assert::assertSame( 7, (int) $postarr['ID'] );
				\PHPUnit\Framework\Assert::assertSame(
					wp_slash( 'See http://example.com/a' ),
					$postarr['post_content']
				);
				return 7;
			} );

		$r = ( new Seonix_Fix_SSL_Mixed_Content( $this->history ) )->rollback( 42 );

		$this->assertIsArray( $r );
		$this->assertSame( 'See http://example.com/a', $r['after']['post_content'] );
	}

	public function test_ssl_rollback_reports_missing_post(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->ssl_entry() );
		Functions\when( 'get_post' )->justReturn( null );

		$r = ( new Seonix_Fix_SSL_Mixed_Content( $this->history ) )->rollback( 42 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'post_not_found', $r->get_error_code() );
	}

	// ─── term_meta_description ───────────────────────────────────────────

	private function term_entry(): array {
		return array(
			'target_id'    => 7,
			'before_state' => array(
				'value'    => 'Original description',
				'taxonomy' => 'category',
			),
			'after_state'  => array(
				'value'    => 'Seonix description',
				'taxonomy' => 'category',
			),
		);
	}

	public function test_term_rollback_refuses_when_description_changed_since_apply(): void {
		Functions\when( 'is_plugin_active' )
			->alias( fn ( $p ) => $p === 'wordpress-seo/wp-seo.php' );
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->term_entry() );
		\WPSEO_Taxonomy_Meta::seed( 7, 'category', 'Rewritten by the shop owner' );

		$r = ( new Seonix_Fix_Term_Meta_Description( $this->history ) )->rollback( 42 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'rollback_stale', $r->get_error_code() );
		// The guard must trip before any write reaches Yoast.
		$this->assertSame( 0, \WPSEO_Taxonomy_Meta::$set_calls );
	}

	public function test_term_rollback_restores_when_description_untouched(): void {
		Functions\when( 'is_plugin_active' )
			->alias( fn ( $p ) => $p === 'wordpress-seo/wp-seo.php' );
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->term_entry() );
		\WPSEO_Taxonomy_Meta::seed( 7, 'category', 'Seonix description' );

		$r = ( new Seonix_Fix_Term_Meta_Description( $this->history ) )->rollback( 42 );

		$this->assertIsArray( $r );
		$this->assertSame( 'Original description', $r['after']['value'] );
		$this->assertSame( 1, \WPSEO_Taxonomy_Meta::$set_calls );
		$this->assertSame(
			'Original description',
			\WPSEO_Taxonomy_Meta::get_term_meta( 7, 'category', 'desc' )
		);
	}
}
