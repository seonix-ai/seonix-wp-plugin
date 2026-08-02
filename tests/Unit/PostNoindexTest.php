<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Post_Noindex;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * post_noindex fix: per-post robots noindex through the active SEO engine's
 * meta, one explicit apply per page, reversible with a stale-guard.
 *
 * The bootstrap defines the WPSEO fake classes process-wide, so
 * Seonix_SEO_Engine::detect() always resolves to 'yoast' here — the Rank
 * Math branch (rank_math_robots array merge) mirrors the same contract and
 * is exercised in the live wp-meta-matrix e2e instead.
 */
final class PostNoindexTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Post_Noindex $method;

	/** @var array<int,array<string,mixed>> post_id => meta map */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Post_Noindex( $this->history );
		$this->meta    = array();

		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_post' )->alias(
			static fn ( $id ) => $id > 0 && $id < 900 ? (object) array( 'ID' => (int) $id ) : null
		);
		Functions\when( 'get_post_meta' )->alias(
			fn ( $post_id, $key, $single ) => $this->meta[ $post_id ][ $key ] ?? ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ $post_id ][ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_key_and_availability(): void {
		$this->assertSame( 'post_noindex', $this->method->key() );
		$this->assertTrue( $this->method->is_available() );
	}

	public function test_validate_requires_post_id(): void {
		$r = $this->method->validate_params( array() );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'missing_post_id', $r->get_error_code() );
	}

	public function test_dry_run_reports_transition_without_writing(): void {
		$r = $this->method->dry_run( array( 'post_id' => 7 ) );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertFalse( $r['before']['noindexed'] );
		$this->assertTrue( $r['after']['noindexed'] );
		$this->assertTrue( $r['after']['sitemap_excluded'] );
		// Pure function — nothing written.
		$this->assertArrayNotHasKey( 7, $this->meta );
	}

	public function test_apply_sets_yoast_robots_meta(): void {
		$r = $this->method->apply( array( 'post_id' => 7 ) );

		$this->assertIsArray( $r );
		$this->assertSame( '1', $this->meta[7][ Seonix_Fix_Post_Noindex::YOAST_META ] );
	}

	public function test_apply_is_idempotent(): void {
		$this->meta[7][ Seonix_Fix_Post_Noindex::YOAST_META ] = '1';

		$r = $this->method->apply( array( 'post_id' => 7 ) );

		$this->assertIsArray( $r );
		$this->assertTrue( $r['no_op'] );
	}

	public function test_apply_missing_post_errors(): void {
		$r = $this->method->apply( array( 'post_id' => 999 ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'post_not_found', $r->get_error_code() );
	}

	private function history_entry( $before_meta ): array {
		return array(
			'target_id'    => 7,
			'before_state' => array(
				'noindexed'  => false,
				'engine'     => 'yoast',
				'meta_value' => $before_meta,
			),
			'after_state'  => array( 'noindexed' => true ),
		);
	}

	public function test_rollback_restores_absent_meta(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->history_entry( '' ) );
		// Still noindexed by us — guard passes.
		$this->meta[7][ Seonix_Fix_Post_Noindex::YOAST_META ] = '1';

		$r = $this->method->rollback( 42 );

		$this->assertIsArray( $r );
		$this->assertArrayNotHasKey( Seonix_Fix_Post_Noindex::YOAST_META, $this->meta[7] ?? array() );
		$this->assertFalse( $r['after']['noindexed'] );
	}

	public function test_rollback_refuses_when_owner_already_reindexed(): void {
		$this->history->shouldReceive( 'get' )->with( 42 )->andReturn( $this->history_entry( '' ) );
		// The owner already removed the noindex by hand.
		$this->meta[7][ Seonix_Fix_Post_Noindex::YOAST_META ] = '';

		$r = $this->method->rollback( 42 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'rollback_stale', $r->get_error_code() );
	}

	public function test_rollback_unknown_history(): void {
		$this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );
		$this->assertInstanceOf( WP_Error::class, $this->method->rollback( 99 ) );
	}
}
