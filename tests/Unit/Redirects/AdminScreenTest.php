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

	/**
	 * The empty rules table used to return without closing its card, so the 404
	 * section rendered inside that card: heading flush against its border, no
	 * gap, the card's bottom edge gone, and the shell's own markup left unclosed.
	 */
	public function test_empty_rules_table_closes_its_card_before_the_404_log(): void {
		$html = $this->renderWithLog( array(
			self::logRow( 1, '/sitemap_index.xml' ),
		) );

		$this->assertSame(
			substr_count( $html, '<div' ),
			substr_count( $html, '</div>' ),
			'every <div> on the screen is closed'
		);

		$table = strpos( $html, '<div class="rdrtable">' );
		$log   = strpos( $html, 'Not found (404s)' );
		$this->assertNotFalse( $table );
		$this->assertNotFalse( $log );

		$between = substr( $html, $table, $log - $table );
		$this->assertSame(
			1,
			substr_count( $between, '<div class="issues-head">' ),
			'the 404 header opens after the card has closed'
		);
		$this->assertSame(
			substr_count( substr( $between, 0, (int) strpos( $between, '<div class="issues-head">' ) ), '<div' ),
			substr_count( substr( $between, 0, (int) strpos( $between, '<div class="issues-head">' ) ), '</div>' ),
			'the rules card is fully closed before the 404 header starts'
		);
	}

	/** A Cyrillic dead URL or rule reads as text; the link it drives stays encoded. */
	public function test_percent_encoded_paths_read_as_text(): void {
		$store = new FakeRedirectsStore();
		$row   = array(
			'id'         => 1,
			'from_path'  => '/%D0%B1%D0%BB%D0%BE%D0%B3/',
			'to_url'     => '/%D0%BD%D0%BE%D0%B2%D1%8B%D0%B9/',
			'status_code' => 301,
			'is_regex'   => 0,
			'enabled'    => 1,
			'seonix_id'  => null,
			'hits'       => 0,
			'created_at' => '2026-07-17 10:00:00',
			'last_hit_at' => null,
		);
		$html = $this->renderRow( $store, $row );

		$this->assertStringContainsString( '<span class="rdr-url">/блог/</span>', $html );
		$this->assertStringContainsString( '>/новый/</code>', $html );
		$this->assertStringNotContainsString( '<span class="rdr-url">/%D0', $html );
	}

	/**
	 * Which fixes get the blind one-click in the plugin. The noindex ones must
	 * stay out: hiding pages from search needs the dashboard's preview and rollback.
	 */
	public function test_one_click_covers_the_safe_fixes_and_not_the_noindex_ones(): void {
		$options = array();
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( &$options ) {
			return $options[ $key ] ?? $default;
		} );
		$admin = ( new \ReflectionClass( \Seonix_Admin::class ) )->newInstanceWithoutConstructor();
		$m     = new \ReflectionMethod( \Seonix_Admin::class, 'is_auto_fixable' );
		$m->setAccessible( true );

		foreach ( array( 'published_url_not_redirected', 'link_text_missing', 'webmcp_not_implemented' ) as $code ) {
			$this->assertTrue( $m->invoke( $admin, $code ), $code );
		}
		foreach ( array( 'stub_page', 'taxonomy_archive_duplicate_title', 'internal_link_to_redirect', 'robots_blocks_all', 'a11y_low_contrast_text' ) as $code ) {
			$this->assertFalse( $m->invoke( $admin, $code ), $code );
		}
		$this->assertFalse( defined( 'WPCF7_VERSION' ) );
		$this->assertFalse( $m->invoke( $admin, 'form_control_no_accessible_name' ), 'without Contact Form 7 the switch names no form field' );

		// A module that is already on leaves the button nothing to do.
		$options[ \Seonix_Agent_Accessibility::OPTION ] = 1;
		$options[ \Seonix_WebMCP::OPTION ]              = 1;
		$this->assertFalse( $m->invoke( $admin, 'link_text_missing' ) );
		$this->assertFalse( $m->invoke( $admin, 'webmcp_not_implemented' ) );
	}

	/** A regex is shown exactly as written. */
	public function test_regex_rules_are_not_decoded(): void {
		$row  = array(
			'id'         => 1,
			'from_path'  => '^/%D0%B1/(.*)$',
			'to_url'     => '/x/$1',
			'status_code' => 301,
			'is_regex'   => 1,
			'enabled'    => 1,
			'seonix_id'  => null,
			'hits'       => 0,
			'created_at' => '2026-07-17 10:00:00',
			'last_hit_at' => null,
		);
		$html = $this->renderRow( new FakeRedirectsStore(), $row );

		$this->assertStringContainsString( '<span class="rdr-url">^/%D0%B1/(.*)$</span>', $html );
	}

	private function renderRow( FakeRedirectsStore $store, array $row ): string {
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'human_time_diff' )->justReturn( '2 hours' );
		Functions\when( 'number_format_i18n' )->alias( function ( $n ) {
			return (string) $n;
		} );
		Functions\when( 'esc_html_x' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'mysql2date' )->justReturn( '17 Jul' );
		Functions\when( 'get_date_from_gmt' )->justReturn( '2026-07-17 10:00:00' );
		Functions\when( 'date_i18n' )->justReturn( '17 Jul' );
		Functions\when( 'wp_date' )->justReturn( '17 Jul' );
		Functions\when( 'current_time' )->justReturn( time() );
		$screen = new Seonix_Redirects_Admin( $store );
		$m      = new \ReflectionMethod( Seonix_Redirects_Admin::class, 'render_row' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $screen, $row );
		return (string) ob_get_clean();
	}
}
