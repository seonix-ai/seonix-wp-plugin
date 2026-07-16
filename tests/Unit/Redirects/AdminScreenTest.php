<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Unit\Doubles\FakeRedirectsStore;
use Seonix_Redirects_Admin;

/**
 * The Redirects screen renders inside the Seonix shell.
 *
 * It shipped without it: reachable from the menu, styled from its own
 * stylesheet, and visibly a different plugin the moment you landed on it — no
 * brand bar, no tabs, no way back to Site Health except the sidebar. Nothing
 * failed, because nothing asserted the chrome was there. This does.
 */
final class AdminScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$wpdb            = new \stdClass();
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;
		$_GET            = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->alias( function ( $url ) {
			return $url;
		} );
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
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url = '' ) {
			return $url . '?' . http_build_query( (array) $args );
		} );
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
		} );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
		} );
		Functions\when( 'wp_kses' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		$screen = new Seonix_Redirects_Admin( new FakeRedirectsStore() );
		ob_start();
		$screen->render_page();
		return (string) ob_get_clean();
	}

	public function test_screen_renders_the_seonix_chrome(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'seonix-topbar', $html, 'the brand bar must be there' );
		$this->assertStringContainsString( 'seonix-navrow', $html, 'the tab row must be there' );
		$this->assertStringContainsString( 'seonix-content', $html );
	}

	/** Landing on Redirects, the Redirects tab is the lit one. */
	public function test_screen_marks_itself_as_the_active_tab(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/class="seonix-navtab is-active"\s+href="[^"]*page=seonix-redirects"/',
			$html
		);
		$this->assertSame( 1, substr_count( $html, 'seonix-navtab is-active' ) );
	}

	/** Every other Seonix screen stays one click away. */
	public function test_screen_keeps_the_other_screens_reachable(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'href="https://site.test/wp-admin/admin.php?page=seonix"', $html );
		$this->assertStringContainsString( 'href="https://site.test/wp-admin/admin.php?page=seonix-settings"', $html );
	}

	/**
	 * The shell owns the page width and gutters; a .wrap inside it would add
	 * core's own margins on top and knock the column out of line with the bar.
	 */
	public function test_screen_does_not_reintroduce_the_core_wrap(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'class="wrap', $html );
		$this->assertStringContainsString( 'class="sx-rdr"', $html );
	}

	public function test_screen_still_renders_its_own_content(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Add redirect', $html );
		$this->assertStringContainsString( 'Existing redirects', $html );
	}
}
