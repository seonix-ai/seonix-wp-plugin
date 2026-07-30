<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Seonix_Content_Write::update_preserving_modified — SEO-fix content repairs
 * (alt attributes, dead links, http:// URLs) must not re-stamp the public
 * "last modified" date: bulk runs used to mark dozens of old posts as
 * "Updated today" with no reader-visible change (false freshness).
 *
 * Mechanism under test: wp_insert_post() IGNORES caller-supplied
 * post_modified on updates (verified against core 6.x/7.x — it stamps
 * current_time() unconditionally; Trac #36595 never merged), so the helper
 * pins the dates via a scoped `wp_insert_post_data` filter instead. These
 * tests exercise the captured filter callback directly, including the
 * post-ID guard that protects nested writes to OTHER posts.
 */
final class ContentWriteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeWpPost( string $modified, string $modified_gmt ): \WP_Post {
		return new \WP_Post( array(
			'ID'                => 7,
			'post_modified'     => $modified,
			'post_modified_gmt' => $modified_gmt,
		) );
	}

	public function test_pins_dates_via_scoped_insert_data_filter(): void {
		Functions\when( 'get_post' )->justReturn(
			$this->makeWpPost( '2025-03-10 09:00:00', '2025-03-10 08:00:00' )
		);
		$captured_cb = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $prio = 10, $args = 1 ) use ( &$captured_cb ) {
				if ( 'wp_insert_post_data' === $hook ) {
					$captured_cb = $cb;
				}
				return true;
			}
		);
		$removed = false;
		Functions\when( 'remove_filter' )->alias(
			static function ( $hook ) use ( &$removed ) {
				if ( 'wp_insert_post_data' === $hook ) {
					$removed = true;
				}
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( $postarr, $wp_error = false ) use ( &$captured_cb ) {
				// Simulate core: it computes fresh dates, then applies the filter.
				$data = array(
					'post_content'      => $postarr['post_content'],
					'post_modified'     => '2026-07-30 00:00:00',
					'post_modified_gmt' => '2026-07-30 00:00:00',
				);
				$data = call_user_func( $captured_cb, $data, array( 'ID' => $postarr['ID'] ) );
				// The filter must have restored the ORIGINAL dates.
				if ( '2025-03-10 09:00:00' !== $data['post_modified']
					|| '2025-03-10 08:00:00' !== $data['post_modified_gmt'] ) {
					return 0; // fail the test via the assertion below
				}
				return (int) $postarr['ID'];
			}
		);

		$result = \Seonix_Content_Write::update_preserving_modified(
			array( 'ID' => 7, 'post_content' => 'fixed' ),
			true
		);

		$this->assertSame( 7, $result, 'filter must restore the original modified dates' );
		$this->assertNotNull( $captured_cb, 'wp_insert_post_data filter must be registered' );
		$this->assertTrue( $removed, 'filter must be removed after the write' );
	}

	public function test_filter_guards_on_post_id_for_nested_writes(): void {
		Functions\when( 'get_post' )->justReturn(
			$this->makeWpPost( '2025-03-10 09:00:00', '2025-03-10 08:00:00' )
		);
		$captured_cb = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb ) use ( &$captured_cb ) {
				if ( 'wp_insert_post_data' === $hook ) {
					$captured_cb = $cb;
				}
				return true;
			}
		);
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'wp_update_post' )->alias(
			static fn ( $postarr, $wp_error = false ) => (int) $postarr['ID']
		);

		\Seonix_Content_Write::update_preserving_modified( array( 'ID' => 7, 'post_content' => 'x' ) );

		// A nested write to a DIFFERENT post (ID 99) must keep its fresh dates.
		$fresh = array( 'post_modified' => '2026-07-30 11:11:11', 'post_modified_gmt' => '2026-07-30 11:11:11' );
		$other = call_user_func( $captured_cb, $fresh, array( 'ID' => 99 ) );
		$this->assertSame( '2026-07-30 11:11:11', $other['post_modified'], 'other posts keep the normal bump' );

		// The targeted post gets its dates pinned.
		$same = call_user_func( $captured_cb, $fresh, array( 'ID' => 7 ) );
		$this->assertSame( '2025-03-10 09:00:00', $same['post_modified'] );
		$this->assertSame( '2025-03-10 08:00:00', $same['post_modified_gmt'] );
	}

	public function test_falls_back_to_plain_update_when_post_missing(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\expect( 'add_filter' )->never();
		Functions\when( 'wp_update_post' )->alias(
			static fn ( $postarr, $wp_error = false ) => (int) $postarr['ID']
		);

		$result = \Seonix_Content_Write::update_preserving_modified( array( 'ID' => 7, 'post_content' => 'x' ) );

		$this->assertSame( 7, $result );
	}

	public function test_skips_zeroed_modified_date(): void {
		Functions\when( 'get_post' )->justReturn(
			$this->makeWpPost( '0000-00-00 00:00:00', '0000-00-00 00:00:00' )
		);
		Functions\expect( 'add_filter' )->never();
		Functions\when( 'wp_update_post' )->alias(
			static fn ( $postarr, $wp_error = false ) => (int) $postarr['ID']
		);

		$result = \Seonix_Content_Write::update_preserving_modified( array( 'ID' => 7, 'post_content' => 'x' ) );

		$this->assertSame( 7, $result );
	}
}
