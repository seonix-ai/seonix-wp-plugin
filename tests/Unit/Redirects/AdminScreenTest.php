<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Unit\Doubles\FakeRedirectsLog;
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

	// ── The 404 log's noise split ─────────────────────────────────────────

	private function renderWithLog( array $rows ): string {
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'human_time_diff' )->justReturn( '2 hours' );
		Functions\when( 'number_format_i18n' )->alias( function ( $n ) {
			return (string) $n;
		} );
		$screen = new Seonix_Redirects_Admin( new FakeRedirectsStore(), null, new FakeRedirectsLog( $rows ) );
		ob_start();
		$screen->render_page();
		return (string) ob_get_clean();
	}

	private static function logRow( int $id, string $path, int $hits = 1 ): array {
		return array(
			'id'           => $id,
			'path'         => $path,
			'hits'         => $hits,
			'last_seen_at' => '2026-07-17 10:00:00',
		);
	}

	/**
	 * A site owner opening this screen must see their genuinely broken URLs,
	 * not a threat feed: bot probes are parked in the collapsed noise
	 * disclosure — still visible and dismissable, never mixed into the list.
	 */
	public function test_bot_probes_are_parked_in_the_noise_disclosure(): void {
		$html = $this->renderWithLog( array(
			self::logRow( 1, '/alte-seite', 12 ),
			self::logRow( 2, '/.env', 40 ),
			self::logRow( 3, '/000.php', 3 ),
		) );

		$cut = strpos( $html, 'rdr-noise' );
		$this->assertNotFalse( $cut, 'the noise disclosure renders' );
		$main = substr( $html, 0, $cut );
		$rest = substr( $html, $cut );

		$this->assertStringContainsString( '/alte-seite', $main, 'the real dead page stays in the main list' );
		$this->assertStringNotContainsString( '/.env', $main, 'probes never sit in the actionable list' );
		$this->assertStringNotContainsString( '/000.php', $main );
		$this->assertStringContainsString( '/.env', $rest, 'probes are parked, not hidden' );
		$this->assertStringContainsString( '/000.php', $rest );
		$this->assertStringContainsString( 'Scanner & bot noise (2)', $rest, 'the summary counts what is parked' );
		$this->assertStringContainsString( 'seonix_redirects_log_dismiss_noise', $rest, 'one click dismisses all of it' );
	}

	/**
	 * When every logged 404 is bot traffic — the normal state of a healthy
	 * site — the screen says so instead of rendering an empty table or, worse,
	 * the probes themselves as the main list.
	 */
	public function test_all_noise_log_says_nothing_needs_fixing(): void {
		$html = $this->renderWithLog( array(
			self::logRow( 1, '/.env', 40 ),
			self::logRow( 2, '/wp-content/plugins/fix/up.php', 1 ),
		) );

		$cut = strpos( $html, 'rdr-noise' );
		$this->assertNotFalse( $cut );

		$this->assertStringContainsString( 'rdr-404-allclear', $html, 'the operator hears "nothing needs fixing", not silence' );
		$this->assertStringContainsString( 'Scanner & bot noise (2)', $html );
		$this->assertStringNotContainsString( 'rdrtable rdr-404', substr( $html, 0, $cut ), 'no empty main table is rendered' );
	}

	/** A log with only real dead pages renders no disclosure at all. */
	public function test_clean_log_renders_no_noise_disclosure(): void {
		$html = $this->renderWithLog( array(
			self::logRow( 1, '/alte-seite', 12 ),
		) );

		$this->assertStringContainsString( '/alte-seite', $html );
		$this->assertStringNotContainsString( 'rdr-noise', $html, 'no probes, no disclosure' );
	}
}
