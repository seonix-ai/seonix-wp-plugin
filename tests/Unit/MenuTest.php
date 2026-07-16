<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Unit\Doubles\FakeRedirectsStore;
use Seonix_Admin;
use Seonix_Redirects_Admin;

/**
 * Covers the top-level admin menu registration (2.5.0).
 *
 * The plugin moved from a Settings submenu (add_options_page) to a top-level
 * menu (add_menu_page) with a self-contained base64 SVG icon. The Problems
 * screen reuses the parent slug `seonix`; Settings is a second submenu, and
 * Redirects — registered by its own class on a later hook — sits between them.
 */
final class MenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Seonix_Admin lazily news up Seonix_Tasks(), whose constructor reads
		// $GLOBALS['wpdb']->prefix. Provide a minimal stand-in so menu
		// registration (the only thing under test here) doesn't trip on it.
		$wpdb         = new \stdClass();
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_registers_top_level_menu_with_slug_and_data_uri_icon(): void {
		$captured = array();

		Functions\expect( 'add_menu_page' )
			->once()
			->andReturnUsing( function ( $page_title, $menu_title, $cap, $slug, $cb, $icon, $position ) use ( &$captured ) {
				$captured = compact( 'page_title', 'menu_title', 'cap', 'slug', 'icon', 'position' );
				return 'toplevel_page_seonix';
			} );

		// Two submenus: Problems (parent slug) + Settings.
		Functions\expect( 'add_submenu_page' )->twice();

		// __() is hit for the submenu labels.
		Functions\when( '__' )->returnArg();

		( new Seonix_Admin() )->add_menu_page();

		$this->assertSame( 'seonix', $captured['slug'], 'top-level menu must use slug "seonix"' );
		$this->assertSame( 'manage_options', $captured['cap'] );
		$this->assertSame( 58, $captured['position'] );

		// Icon must be a self-contained base64 SVG data URI.
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $captured['icon'] );
		$b64 = substr( $captured['icon'], strlen( 'data:image/svg+xml;base64,' ) );
		$decoded = base64_decode( $b64, true );
		$this->assertNotFalse( $decoded, 'icon payload must be valid base64' );
		$this->assertStringContainsString( '<svg', $decoded, 'decoded icon must be an SVG' );
	}

	public function test_registers_dashboard_and_settings_submenus(): void {
		$submenus = array();

		Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_seonix' );
		Functions\when( '__' )->returnArg();
		Functions\expect( 'add_submenu_page' )
			->twice()
			->andReturnUsing( function ( $parent, $page_title, $menu_title, $cap, $slug ) use ( &$submenus ) {
				$submenus[] = array( 'parent' => $parent, 'slug' => $slug );
				return '';
			} );

		( new Seonix_Admin() )->add_menu_page();

		$this->assertCount( 2, $submenus );
		// First submenu = Problems, reuses the parent slug.
		$this->assertSame( 'seonix', $submenus[0]['parent'] );
		$this->assertSame( 'seonix', $submenus[0]['slug'] );
		// Second submenu = Settings.
		$this->assertSame( 'seonix', $submenus[1]['parent'] );
		$this->assertSame( 'seonix-settings', $submenus[1]['slug'] );
	}

	/**
	 * Reproduce core's add_submenu_page() insertion, verbatim (WP 6.9).
	 *
	 * A mock that just records the $position argument passes while the real menu
	 * comes out in the wrong order, because core's positions are INSERTION
	 * INDICES: `$position >= count()` appends, so 10/20/30 silently degrades to
	 * registration order. That exact bug shipped past a green "positions are ints
	 * and sort correctly" test and was only caught on a live site. So the ordering
	 * rule is exercised here rather than described.
	 *
	 * @param array<int,array<string,mixed>> $submenu Menu built so far, by reference.
	 */
	private function core_add_submenu( array &$submenu, string $slug, $position ): void {
		$item = array( 'slug' => $slug, 'position' => $position );

		if ( null === $position || 0 === count( $submenu ) || $position >= count( $submenu ) ) {
			$submenu[] = $item;
			return;
		}
		$position = max( (int) $position, 0 );
		if ( 0 === $position ) {
			array_unshift( $submenu, $item );
			return;
		}
		$submenu = array_merge(
			array_slice( $submenu, 0, $position ),
			array( $item ),
			array_slice( $submenu, $position )
		);
	}

	/**
	 * The sidebar and the in-page nav row must name the same three screens in
	 * the same order: Problems, Redirects, Settings.
	 *
	 * Redirects registers on a later hook than the other two, so the order only
	 * holds because of the declared positions — asserted through core's real
	 * insertion rule, in both hook orders, since a plugin cannot rely on which
	 * of the two admin_menu callbacks runs first.
	 */
	public function test_submenu_order_matches_the_nav_row(): void {
		foreach ( array( false, true ) as $redirects_first ) {
			$submenu = array();

			Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_seonix' );
			Functions\when( '__' )->returnArg();
			Functions\when( 'add_submenu_page' )->alias(
				function ( $parent, $page_title, $menu_title, $cap, $slug, $cb = null, $position = null ) use ( &$submenu ) {
					$this->core_add_submenu( $submenu, $slug, $position );
					return '';
				}
			);

			$admin     = new Seonix_Admin();
			$redirects = new Seonix_Redirects_Admin( new FakeRedirectsStore() );

			if ( $redirects_first ) {
				$redirects->add_menu();
				$admin->add_menu_page();
			} else {
				$admin->add_menu_page();
				$redirects->add_menu();
			}

			foreach ( $submenu as $item ) {
				$this->assertIsInt( $item['position'], $item['slug'] . ' must declare an explicit menu position' );
			}

			$this->assertSame(
				array( 'seonix', 'seonix-redirects', 'seonix-settings' ),
				array_column( $submenu, 'slug' ),
				'sidebar must read Problems → Redirects → Settings (redirects registered '
					. ( $redirects_first ? 'first' : 'last' ) . ')'
			);
		}
	}

	/** Dropping the Redirects screen must not disturb the other two. */
	public function test_submenu_order_survives_redirects_not_registering(): void {
		$submenu = array();

		Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_seonix' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_submenu_page' )->alias(
			function ( $parent, $page_title, $menu_title, $cap, $slug, $cb = null, $position = null ) use ( &$submenu ) {
				$this->core_add_submenu( $submenu, $slug, $position );
				return '';
			}
		);

		( new Seonix_Admin() )->add_menu_page();

		$this->assertSame( array( 'seonix', 'seonix-settings' ), array_column( $submenu, 'slug' ) );
	}
}
