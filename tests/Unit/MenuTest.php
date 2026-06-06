<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Admin;

/**
 * Covers the top-level admin menu registration (2.5.0).
 *
 * The plugin moved from a Settings submenu (add_options_page) to a top-level
 * menu (add_menu_page) with a self-contained base64 SVG icon. The Problems
 * screen reuses the parent slug `seonix`; Settings is a second submenu.
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
}
