<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Unit\Doubles\FakeRedirectsStore;
use Seonix_Admin_Shell;

/**
 * The chrome every Seonix screen renders inside: the brand top bar and the
 * Site Health / Redirects / Settings tab row.
 *
 * Redirects shipped reachable from the menu but rendering a bare .wrap — it
 * worked and looked like a different plugin, and nothing failed while it drifted
 * out of the shell. These tests pin the tab set, its order, and which tab is lit,
 * so a screen cannot fall out of the chrome again in silence.
 */
final class AdminShellTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The store's constructor reaches for $wpdb->prefix.
		$wpdb            = new \stdClass();
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias( function ( $s ) {
			return rtrim( (string) $s, '/' );
		} );
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $value;
		} );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://site.test/wp-admin/' . $path;
		} );
		Functions\when( 'plugins_url' )->alias( function ( $path = '' ) {
			return 'https://site.test/wp-content/plugins/seonix/' . $path;
		} );
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
		} );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
		} );
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'get_option' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function shell( int $active_redirects = 0 ): Seonix_Admin_Shell {
		return new Seonix_Admin_Shell( new FakeRedirectsStore( $active_redirects ) );
	}

	/** Render open() and return the markup. */
	private function render( string $active ): string {
		ob_start();
		$this->shell( 3 )->open( $active );
		return (string) ob_get_clean();
	}

	// ─── Tabs ─────────────────────────────────────────────────────────────

	public function test_tabs_are_site_health_redirects_settings_in_design_order(): void {
		$tabs = $this->shell()->tabs();

		$this->assertSame( array( 'dashboard', 'redirects', 'settings' ), array_keys( $tabs ) );
		$this->assertSame( 'Site Health', $tabs['dashboard']['label'] );
		$this->assertSame( 'Redirects', $tabs['redirects']['label'] );
		$this->assertSame( 'Settings', $tabs['settings']['label'] );
	}

	public function test_each_tab_links_to_its_admin_screen(): void {
		$tabs = $this->shell()->tabs();

		$this->assertSame( 'https://site.test/wp-admin/admin.php?page=seonix', $tabs['dashboard']['url'] );
		$this->assertSame( 'https://site.test/wp-admin/admin.php?page=seonix-redirects', $tabs['redirects']['url'] );
		$this->assertSame( 'https://site.test/wp-admin/admin.php?page=seonix-settings', $tabs['settings']['url'] );
	}

	public function test_redirects_tab_carries_the_number_of_rules_being_served(): void {
		$tabs = $this->shell( 6 )->tabs();

		$this->assertSame( 6, $tabs['redirects']['count'] );
	}

	/** A badge reading "0" is noise on a site that never needed a redirect. */
	public function test_redirects_tab_has_no_badge_when_there_are_no_rules(): void {
		$tabs = $this->shell( 0 )->tabs();

		$this->assertNull( $tabs['redirects']['count'] );
	}

	/** The shell must still draw without a store — it just carries no badge. */
	public function test_tabs_render_without_a_store(): void {
		$tabs = ( new Seonix_Admin_Shell() )->tabs();

		$this->assertCount( 3, $tabs );
		$this->assertNull( $tabs['redirects']['count'] );
	}

	// ─── Screens ──────────────────────────────────────────────────────────

	/**
	 * The tab row and the hook list are two views of one list of screens. If
	 * they can drift, a screen gets a tab but no stylesheet and no background —
	 * which is exactly how Redirects shipped looking like a different plugin.
	 */
	public function test_every_tab_has_a_screen_hook(): void {
		$this->assertSame(
			count( $this->shell()->tabs() ),
			count( Seonix_Admin_Shell::screen_hooks() ),
			'a tab without a hook is a screen with no styles'
		);
	}

	public function test_screen_hooks_are_the_hooks_core_derives(): void {
		$this->assertSame(
			array( 'toplevel_page_seonix', 'seonix_page_seonix-redirects', 'seonix_page_seonix-settings' ),
			Seonix_Admin_Shell::screen_hooks()
		);
	}

	public function test_is_seonix_screen_recognises_our_screens_only(): void {
		foreach ( Seonix_Admin_Shell::screen_hooks() as $hook ) {
			$this->assertTrue( Seonix_Admin_Shell::is_seonix_screen( $hook ), "$hook is ours" );
		}
		foreach ( array( 'edit.php', 'plugins.php', 'toplevel_page_other', '', null ) as $foreign ) {
			$this->assertFalse( Seonix_Admin_Shell::is_seonix_screen( $foreign ) );
		}
	}

	/**
	 * The body class is what lets admin.css reach wp-admin's own wrappers and
	 * replace the grey background and left gutter. Without it a screen renders
	 * the chrome inside a plain wp-admin page — brand bar floating on grey.
	 */
	public function test_body_class_is_added_on_every_seonix_screen(): void {
		foreach ( Seonix_Admin_Shell::screen_hooks() as $hook ) {
			$this->assertStringContainsString(
				'seonix-screen',
				$this->body_class_for( $hook ),
				"$hook must be tagged for the app background"
			);
		}
	}

	public function test_body_class_stays_off_other_admin_pages(): void {
		foreach ( array( 'edit.php', 'post.php', 'plugins.php' ) as $foreign ) {
			$this->assertStringNotContainsString( 'seonix-screen', $this->body_class_for( $foreign ) );
		}
	}

	/** @param string $hook Admin page hook to pretend we're on. */
	private function body_class_for( string $hook ): string {
		Functions\when( 'get_current_screen' )->alias( function () use ( $hook ) {
			$screen     = new \stdClass();
			$screen->id = $hook;
			return $screen;
		} );

		return $this->shell()->body_class( 'wp-admin ' );
	}

	// ─── Chrome ───────────────────────────────────────────────────────────

	public function test_open_renders_the_whole_nav_row_on_every_screen(): void {
		foreach ( array( 'dashboard', 'redirects', 'settings' ) as $screen ) {
			$html = $this->render( $screen );

			$this->assertStringContainsString( 'seonix-navrow', $html, "$screen must render the nav row" );
			$this->assertStringContainsString( 'admin.php?page=seonix-redirects', $html, "$screen must link to Redirects" );
			$this->assertStringContainsString( 'admin.php?page=seonix-settings', $html, "$screen must link to Settings" );
			// Anchored on the <a> so the tabs' own icon/count spans don't count.
			$this->assertSame( 3, substr_count( $html, '<a class="seonix-navtab' ), "$screen must show exactly three tabs" );
		}
	}

	public function test_open_lights_the_current_tab_and_only_that_one(): void {
		$html = $this->render( 'redirects' );

		$this->assertSame( 1, substr_count( $html, 'seonix-navtab is-active' ), 'exactly one tab is active' );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );

		// The active class sits on the Redirects link, not on a neighbour.
		$this->assertMatchesRegularExpression(
			'/class="seonix-navtab is-active"\s+href="[^"]*page=seonix-redirects"/',
			$html
		);
	}

	public function test_open_renders_the_badge_only_where_there_is_a_count(): void {
		$html = $this->render( 'dashboard' );

		$this->assertSame( 1, substr_count( $html, 'seonix-navtab__count' ) );
		$this->assertStringContainsString( '>3</span>', $html );
	}

	public function test_close_shuts_the_wrappers_open_left(): void {
		ob_start();
		$shell = $this->shell();
		$shell->open( 'redirects' );
		$shell->close();
		$html = (string) ob_get_clean();

		$this->assertSame(
			substr_count( $html, '<div' ) + substr_count( $html, '<nav' ) + substr_count( $html, '<header' ),
			substr_count( $html, '</div>' ) + substr_count( $html, '</nav>' ) + substr_count( $html, '</header>' ),
			'every element open() opens must be closed by close()'
		);
	}
}
