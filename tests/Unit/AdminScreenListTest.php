<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Admin_Shell;

/**
 * There is ONE list of Seonix admin screens, and it lives in
 * Seonix_Admin_Shell::screens().
 *
 * It used to be three: the tab row, the enqueue hook list, and a pile of
 * per-screen body selectors in admin.css. Adding Redirects to the menu but not
 * to all three gave a screen that was reachable, unstyled, and sitting on
 * wp-admin's grey — a bug you cannot see in any unit test that only reads PHP,
 * and one nobody thinks to re-check when adding screen number four.
 *
 * So this reads the stylesheet as TEXT and refuses the pattern outright. The
 * body class the CSS keys off is minted from screens(), which means a new screen
 * gets the background, the gutter and the notice inset for free — or not at all,
 * never half.
 */
final class AdminScreenListTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// screens() carries translated labels.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function admin_css(): string {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin.css' );
		$this->assertNotFalse( $css, 'admin.css must be readable' );
		return (string) $css;
	}

	/** The class the wp-admin overrides hang off must be the one PHP emits. */
	public function test_admin_css_targets_the_body_class_the_shell_adds(): void {
		$this->assertStringContainsString( 'body.seonix-screen', $this->admin_css() );
	}

	public function test_admin_css_does_not_name_individual_screens(): void {
		$css = $this->admin_css();

		foreach ( Seonix_Admin_Shell::screen_hooks() as $hook ) {
			// Hooks are body classes too (body.toplevel_page_seonix). Naming one
			// here means the next screen has to be added by hand — and won't be.
			$this->assertStringNotContainsString(
				'body.' . $hook,
				$css,
				"admin.css must not target $hook directly — use body.seonix-screen"
			);
		}
	}

	/**
	 * Guards the same pattern for any screen NOT in the list — the exact case
	 * that broke, where a selector exists for two screens and the third is
	 * simply absent.
	 */
	public function test_admin_css_has_no_per_screen_selectors_at_all(): void {
		$this->assertSame(
			0,
			preg_match_all( '/body\.(?:toplevel_page_|seonix_page_)[\w-]+/', $this->admin_css() ),
			'per-screen body selectors are how Redirects lost its background; use body.seonix-screen'
		);
	}

	/**
	 * Every root the stylesheet paints must be able to see the design tokens.
	 *
	 * CSS variables inherit, so a root that isn't in the token block resolves
	 * `var(--sx-green)` to nothing and the rule quietly evaporates — no error,
	 * no fallback, just a missing colour. That shipped: the status badge renders
	 * inside CORE's toolbar button, nowhere under .seonix-metabox, so the green
	 * "clean" dot came out transparent with a white tick floating on it.
	 *
	 * @return array<int,array<int,string>>
	 */
	public function surfaces(): array {
		return array(
			array( '.seonix-app' ),      // admin screens
			array( '.seonix-metabox' ),  // classic meta box + editor sidebar body
			array( '.seonix-mark' ),     // status badge, inside core's toolbar button
		);
	}

	/**
	 * .seonix-badge belongs to the dashboard's priority pills (HIGH / MED / LOW).
	 *
	 * This is one stylesheet shared by the admin screens, the meta box and the
	 * editor, so a second rule for the same class silently wins by being later in
	 * the file. Reusing this name for the editor's count badge turned every
	 * priority pill in the task list into an absolutely-positioned dot — a screen
	 * away from the change, which is exactly the kind of break nobody re-checks.
	 */
	public function test_seonix_badge_still_belongs_to_the_priority_pills(): void {
		$css = $this->admin_css();

		// The pill's own rules, and nothing else, may define .seonix-badge.
		preg_match_all( '/^\.seonix-badge\s*\{/m', $css, $m );
		$this->assertCount(
			1,
			$m[0],
			'.seonix-badge is the dashboard priority pill — a second rule for it overrides the pill everywhere'
		);

		$this->assertStringContainsString( '.seonix-badge--high', $css, 'the priority pill modifiers must survive' );
	}

	/** @dataProvider surfaces */
	public function test_every_styled_root_can_see_the_design_tokens( string $selector ): void {
		$css = $this->admin_css();
		$block = substr( $css, 0, strpos( $css, '--sx-ac-grad' ) );

		// Either another selector follows (",") or the block opens ("{") — the
		// last one in the list carries no comma.
		$this->assertMatchesRegularExpression(
			'/' . preg_quote( $selector, '/' ) . '\s*[,{]/',
			$block,
			$selector . ' must be in the token block — without it every var(--sx-*) rule on it resolves to nothing'
		);
	}
}
