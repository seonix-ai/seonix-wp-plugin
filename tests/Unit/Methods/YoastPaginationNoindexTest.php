<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Pagination_Noindex;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Covers the yoast_setting_pagination_noindex fix method.
 *
 * The method's job is two-fold:
 *   1. Flip wpseo_titles['noindex-subpages-wpseo'] to true while preserving
 *      every other key in the option.
 *   2. Force-rebuild affected term indexables (UPDATE wp_yoast_indexable
 *      SET is_robots_noindex = NULL WHERE object_type = 'term') so Yoast
 *      renders the new robots tag on the live page without waiting for
 *      its background cron.
 *
 * Tests use Brain Monkey to stub WordPress option functions and a Mockery
 * spy on $wpdb to assert the UPDATE happens with the right WHERE clause.
 */
final class YoastPaginationNoindexTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Pagination_Noindex $method;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Pagination_Noindex( $this->history );

		// Fresh Yoast option store per test. The bootstrap defines the fake +
		// WPSEO_VERSION process-wide; an un-seeded store reads back false, i.e.
		// "subpages still indexed", which is the natural starting point.
		\WPSEO_Options::reset();
		unset( $GLOBALS['wpdb'] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_key_is_yoast_setting_pagination_noindex(): void {
		$this->assertSame( 'yoast_setting_pagination_noindex', $this->method->key() );
	}

	public function test_validate_params_accepts_empty_payload(): void {
		// The recommendation is site-level — params are intentionally
		// minimal. An empty array must validate.
		$this->assertTrue( $this->method->validate_params( array() ) );
	}

	public function test_validate_params_accepts_site_url_string(): void {
		$this->assertTrue( $this->method->validate_params( array( 'site_url' => 'https://example.com/' ) ) );
	}

	public function test_validate_params_rejects_non_string_site_url(): void {
		$r = $this->method->validate_params( array( 'site_url' => 42 ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_site_url', $r->get_error_code() );
	}

	public function test_dry_run_reports_false_to_true_when_option_unset(): void {
		// Yoast option exists but the subpages key is not set yet.
		Functions\when( 'get_option' )->justReturn( array(
			'separator'  => 'sc-dash',
			'title-post' => '%%title%%',
		) );

		$r = $this->method->dry_run( array() );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertFalse( $r['before']['value'] );
		$this->assertTrue( $r['after']['value'] );
		$this->assertSame( 'option', $r['target']['type'] );
		$this->assertSame( 'wpseo_titles.noindex-subpages-wpseo', $r['target']['key'] );
	}

	public function test_dry_run_is_no_op_when_already_true(): void {
		// Yoast already reports the subpages-noindex toggle as on.
		\WPSEO_Options::$store['noindex-subpages-wpseo'] = true;

		$r = $this->method->dry_run( array() );

		$this->assertTrue( $r['no_op'] );
		$this->assertTrue( $r['before']['value'] );
		$this->assertTrue( $r['after']['value'] );
	}

	public function test_dry_run_does_not_persist(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'update_option' )->never();

		$this->method->dry_run( array() );

		$this->assertTrue( true );
	}

	public function test_apply_writes_option_preserving_other_keys(): void {
		// Existing wpseo_titles keys, as Yoast's option API would report them.
		\WPSEO_Options::$store = array(
			'separator'          => 'sc-dash',
			'title-post'         => '%%title%% %%sep%% %%sitename%%',
			'breadcrumbs-enable' => true,
		);

		// $wpdb spy so the indexable rebuild step can be asserted.
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'suppress_errors' )->andReturn( false );
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::pattern( '/UPDATE wp_yoast_indexable SET is_robots_noindex = NULL WHERE object_type = %s/' ), 'term' )
			->andReturn( "UPDATE wp_yoast_indexable SET is_robots_noindex = NULL WHERE object_type = 'term'" );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );
		$GLOBALS['wpdb'] = $wpdb;

		// The indexable rebuild step finishes by flushing Yoast's object
		// cache. Prefer wp_cache_flush_group when available, else fall back
		// to wp_cache_flush — stub both so we don't care which path runs.
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );

		$r = $this->method->apply( array() );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertTrue( $r['after']['value'] );

		// The write went through Yoast's public setter exactly once and only
		// touched the one key — every other key in the option survives, which
		// is the setter's job, not ours.
		$this->assertSame( 1, \WPSEO_Options::$set_calls );
		$this->assertTrue( \WPSEO_Options::get( 'noindex-subpages-wpseo' ) );
		$this->assertSame( 'sc-dash', \WPSEO_Options::get( 'separator' ) );
		$this->assertSame( '%%title%% %%sep%% %%sitename%%', \WPSEO_Options::get( 'title-post' ) );
		$this->assertTrue( \WPSEO_Options::get( 'breadcrumbs-enable' ) );
	}

	public function test_apply_is_idempotent_no_op_when_already_true(): void {
		// Already true → apply must NOT write through Yoast's setter and must
		// NOT touch the indexable table (no $wpdb spy is wired, so any DB call
		// would fail). The dashboard relies on this for the "already applied"
		// status badge.
		\WPSEO_Options::$store = array(
			'noindex-subpages-wpseo' => true,
			'separator'              => 'sc-dash',
		);

		$r = $this->method->apply( array() );

		$this->assertTrue( $r['no_op'] );
		$this->assertSame( 0, \WPSEO_Options::$set_calls );
	}

	public function test_rollback_restores_previous_value(): void {
		$this->history->shouldReceive( 'get' )
			->with( 77 )
			->andReturn( array(
				'id'           => 77,
				'method'       => 'yoast_setting_pagination_noindex',
				'target_type'  => 'option',
				'target_id'    => 0,
				'before_state' => array( 'value' => false ),
				'after_state'  => array( 'value' => true ),
			) );

		\WPSEO_Options::$store = array(
			'noindex-subpages-wpseo' => true,
			'separator'              => 'sc-dash',
		);

		$r = $this->method->rollback( 77 );

		$this->assertIsArray( $r );
		$this->assertTrue( $r['before']['value'] );
		$this->assertFalse( $r['after']['value'] );

		// Rollback wrote the previous value back through Yoast's public setter,
		// flipping noindex-subpages off while leaving sibling keys intact.
		$this->assertSame( 1, \WPSEO_Options::$set_calls );
		$this->assertFalse( \WPSEO_Options::get( 'noindex-subpages-wpseo' ) );
		$this->assertSame( 'sc-dash', \WPSEO_Options::get( 'separator' ) );
	}

	public function test_rollback_returns_error_for_unknown_history_id(): void {
		$this->history->shouldReceive( 'get' )->with( 999 )->andReturn( null );

		$r = $this->method->rollback( 999 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'unknown_history_entry', $r->get_error_code() );
	}
}
