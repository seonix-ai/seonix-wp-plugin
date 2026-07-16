<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Unit\Doubles\FakeRedirectsStore;
use Seonix_Admin;
use Seonix_Admin_Shell;
use Seonix_Redirects_Admin;

/**
 * Which screens get the shell's stylesheet and script.
 *
 * This is load-bearing in a way that is easy to miss: redirects.css declares
 * `seonix-admin` as a dependency, and WordPress does not fall back on a missing
 * dependency — it drops the dependent stylesheet too. So if this hook list stops
 * covering the Redirects screen, that screen loses BOTH stylesheets and renders
 * as raw HTML. The hook names are derived by core from the parent slug, so they
 * are asserted against the real slugs rather than hard-coded twice.
 */
final class AdminAssetsTest extends TestCase {

	/** @var array<int,string> */
	private $styles = array();

	/** @var array<string,array<int,string>> */
	private $deps = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$wpdb            = new \stdClass();
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		$this->styles = array();
		$this->deps   = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src = '', $deps = array() ) {
				$this->styles[]        = $handle;
				$this->deps[ $handle ] = (array) $deps;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** @return array<int,string> Handles enqueued for a given admin page hook. */
	private function styles_for( string $hook ): array {
		$this->styles = array();
		$this->deps   = array();

		$store = new FakeRedirectsStore();
		( new Seonix_Admin( null, null, new Seonix_Admin_Shell( $store ) ) )->enqueue_assets( $hook );
		( new Seonix_Redirects_Admin( $store ) )->enqueue( $hook );

		return $this->styles;
	}

	public function test_every_seonix_screen_gets_the_shell_stylesheet(): void {
		$hooks = array(
			'toplevel_page_' . Seonix_Admin::MENU_SLUG,
			'seonix_page_' . Seonix_Admin::SETTINGS_SLUG,
			'seonix_page_' . Seonix_Redirects_Admin::PAGE_SLUG,
		);

		foreach ( $hooks as $hook ) {
			$this->assertContains( 'seonix-admin', $this->styles_for( $hook ), "$hook must load the shell stylesheet" );
		}
	}

	/**
	 * The dependency is what pins redirects.css after admin.css. It only holds
	 * if the handle it names is actually enqueued on the same screen.
	 */
	public function test_redirects_stylesheet_loads_after_the_shell_it_refines(): void {
		$styles = $this->styles_for( 'seonix_page_' . Seonix_Redirects_Admin::PAGE_SLUG );

		$this->assertContains( 'seonix-redirects', $styles );
		$this->assertSame( array( 'seonix-admin' ), $this->deps['seonix-redirects'] );
		$this->assertContains(
			'seonix-admin',
			$styles,
			'redirects.css depends on seonix-admin; if it is not enqueued here, WordPress silently drops both'
		);
	}

	/** The Redirects table styles have no business on Site Health or Settings. */
	public function test_redirects_stylesheet_stays_on_its_own_screen(): void {
		$this->assertNotContains( 'seonix-redirects', $this->styles_for( 'toplevel_page_' . Seonix_Admin::MENU_SLUG ) );
		$this->assertNotContains( 'seonix-redirects', $this->styles_for( 'seonix_page_' . Seonix_Admin::SETTINGS_SLUG ) );
	}

	public function test_nothing_loads_on_unrelated_admin_pages(): void {
		$this->assertSame( array(), $this->styles_for( 'edit.php' ) );
		$this->assertSame( array(), $this->styles_for( 'plugins.php' ) );
	}
}
