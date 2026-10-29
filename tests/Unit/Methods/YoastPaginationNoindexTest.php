<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Pagination_Noindex;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Covers the retired yoast_setting_pagination_noindex fix method.
 *
 * It flipped wpseo_titles['noindex-subpages-wpseo'], a key Yoast has not read
 * since 6.3 (2018), so it never changed a rendered page. It is now rollback-only:
 * dry_run() and apply() refuse with the 422 code the backend records as a clean
 * skip, the /capabilities handshake never offers it, and rollback() still writes
 * the previous value back through Yoast's public option API (the
 * WPSEO_Options fake from tests/Fakes/FakeYoast.php).
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
		// WPSEO_VERSION process-wide.
		\WPSEO_Options::reset();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_key_is_yoast_setting_pagination_noindex(): void {
		$this->assertSame( 'yoast_setting_pagination_noindex', $this->method->key() );
	}

	public function test_is_never_advertised(): void {
		// Even with Yoast active (WPSEO_VERSION is defined here).
		$this->assertFalse( $this->method->is_available() );
	}

	public function test_validate_params_accepts_empty_payload_and_site_url(): void {
		$this->assertTrue( $this->method->validate_params( array() ) );
		$this->assertTrue( $this->method->validate_params( array( 'site_url' => 'https://example.com/' ) ) );
	}

	public function test_validate_params_rejects_non_string_site_url(): void {
		$r = $this->method->validate_params( array( 'site_url' => 42 ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_site_url', $r->get_error_code() );
	}

	public function test_dry_run_and_apply_refuse_without_writing(): void {
		// A run queued before the retirement, or an older backend, must get the
		// clean-skip contract — not a success for a write that changes nothing.
		foreach ( array( 'dry_run', 'apply' ) as $call ) {
			$r = $this->method->$call( array( 'site_url' => 'https://example.com/' ) );

			$this->assertInstanceOf( WP_Error::class, $r, $call );
			$this->assertSame( 'no_supported_seo_plugin', $r->get_error_code(), $call );
			$this->assertSame( 422, $r->get_error_data()['status'], $call );
		}
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
