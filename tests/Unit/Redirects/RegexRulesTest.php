<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Runner;
use Seonix_Redirects_Store;

/**
 * Regex rules — the feature that separates a redirect manager from a lookup
 * table: moving /blog/123 → /archive/123 for every id, in one rule.
 *
 * The risk profile is different from literal rules, and that is what these tests
 * pin: a pattern runs against every unmatched request, its captures come from
 * the visitor's own URL, and a bad pattern must degrade to "no match" rather
 * than to a fatal or a hang.
 */
final class RegexRulesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// validate_rule now canonicalizes literal targets against the permalink
		// convention + site host; "no convention" keeps these fixtures literal
		// (regex targets are exempt from canonicalization either way).
		Functions\when( 'get_option' )->alias( static function ( $key, $default = '' ) {
			return $default;
		} );
		Functions\when( 'home_url' )->justReturn( 'http://example.org' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

    /**
     * @param array<int,array<string,mixed>> $overrides
     */
    private function rule( string $pattern, string $to, array $overrides = array() ): array {
        return array_merge(
            array(
                'id'          => 1,
                'from_path'   => $pattern,
                'to_url'      => $to,
                'status_code' => 301,
                'is_regex'    => 1,
            ),
            $overrides
        );
    }

    /**
     * The runner separates literal rules from patterns by the is_regex flag, so
     * the query feeding it must actually select that column. It didn't at first:
     * every pattern was filed as a literal path, matched nothing, and regex rules
     * looked like they had never been saved — with the row sitting in the table.
     */
    public function test_active_rows_query_selects_the_regex_flag(): void {
        $source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/redirects/class-seonix-redirects-store.php' );
        $this->assertNotFalse( $source );

        preg_match( '/function get_active_rows.*?SELECT (.*?) FROM/s', $source, $m );
        $this->assertNotEmpty( $m, 'get_active_rows() should still be a plain SELECT' );
        $this->assertStringContainsString(
            'is_regex',
            $m[1],
            'get_active_rows() must select is_regex — without it the runner treats every pattern as a literal path'
        );
    }

    // ─── compile_regex ───────────────────────────────────────────────────

    public function test_compile_wraps_a_bare_pattern(): void {
        $this->assertSame( '#^/blog/(\d+)$#iu', Seonix_Redirects_Store::compile_regex( '^/blog/(\d+)$' ) );
    }

    /** A stray delimiter must not close the expression and let flags in. */
    public function test_compile_escapes_the_delimiter(): void {
        $compiled = Seonix_Redirects_Store::compile_regex( '^/tag/c#sharp$' );

        $this->assertSame( '#^/tag/c\#sharp$#iu', $compiled );
        $this->assertSame( 1, preg_match( $compiled, '/tag/c#sharp' ) );
    }

    public function test_compile_rejects_an_invalid_pattern(): void {
        $this->assertNull( Seonix_Redirects_Store::compile_regex( '^/blog/([0-9]+$' ) );
    }

    // ─── validation ──────────────────────────────────────────────────────

    public function test_validate_accepts_a_regex_rule(): void {
        $res = Seonix_Redirects_Store::validate_rule( '^/blog/(\d+)/?$', '/archive/$1', 301, true );

        $this->assertTrue( $res['ok'] );
        $this->assertTrue( $res['is_regex'] );
        $this->assertSame( '^/blog/(\d+)/?$', $res['from_path'], 'a pattern is stored verbatim, not path-normalised' );
    }

    public function test_validate_rejects_an_uncompilable_pattern(): void {
        $res = Seonix_Redirects_Store::validate_rule( '^/blog/([0-9]+$', '/x', 301, true );

        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'not valid', $res['error'] );
    }

    public function test_validate_rejects_an_overlong_pattern(): void {
        $res = Seonix_Redirects_Store::validate_rule( '^/' . str_repeat( 'a', 300 ) . '$', '/x', 301, true );

        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'too long', $res['error'] );
    }

    /**
     * A regex target normally carries $1, so comparing it literally to the
     * pattern (as the self-redirect guard does for literal rules) would reject
     * perfectly good rules.
     */
    public function test_validate_skips_the_self_redirect_guard_for_regex(): void {
        $res = Seonix_Redirects_Store::validate_rule( '^/(.*)$', '/$1', 301, true );

        $this->assertTrue( $res['ok'] );
    }

    // ─── 410 ─────────────────────────────────────────────────────────────

    public function test_validate_accepts_410_without_a_target(): void {
        $res = Seonix_Redirects_Store::validate_rule( '/dead-page', '', 410 );

        $this->assertTrue( $res['ok'] );
        $this->assertNull( $res['to_url'], '410 has nowhere to send anyone; store NULL, not ""' );
    }

    public function test_validate_still_requires_a_target_for_a_real_redirect(): void {
        $this->assertFalse( Seonix_Redirects_Store::validate_rule( '/x', '', 301 )['ok'] );
    }

    public function test_validate_accepts_the_new_codes(): void {
        foreach ( array( 301, 302, 307, 308 ) as $code ) {
            $this->assertTrue(
                Seonix_Redirects_Store::validate_rule( '/from', '/to', $code )['ok'],
                $code . ' should be accepted'
            );
        }
        $this->assertFalse( Seonix_Redirects_Store::validate_rule( '/from', '/to', 418 )['ok'] );
    }

    // ─── resolve_regex ───────────────────────────────────────────────────

    public function test_resolve_expands_captures(): void {
        $hit = Seonix_Redirects_Runner::resolve_regex(
            array( $this->rule( '^/blog/(\d+)/?$', '/archive/$1' ) ),
            '/blog/123/'
        );

        $this->assertSame( '/archive/123', $hit['target'] );
        $this->assertSame( 301, $hit['status'] );
    }

    public function test_resolve_matches_case_insensitively(): void {
        $hit = Seonix_Redirects_Runner::resolve_regex(
            array( $this->rule( '^/Blog/(\d+)$', '/archive/$1' ) ),
            '/BLOG/7'
        );

        $this->assertSame( '/archive/7', $hit['target'] );
    }

    public function test_resolve_returns_null_when_nothing_matches(): void {
        $this->assertNull(
            Seonix_Redirects_Runner::resolve_regex(
                array( $this->rule( '^/blog/(\d+)$', '/archive/$1' ) ),
                '/shop/123'
            )
        );
    }

    /** Creation order is the operator's priority — first match wins. */
    public function test_resolve_takes_the_first_matching_rule(): void {
        $hit = Seonix_Redirects_Runner::resolve_regex(
            array(
                $this->rule( '^/blog/(.*)$', '/first/$1', array( 'id' => 1 ) ),
                $this->rule( '^/blog/(.*)$', '/second/$1', array( 'id' => 2 ) ),
            ),
            '/blog/x'
        );

        $this->assertSame( 1, $hit['id'] );
    }

    /** A pattern that stopped compiling must not take the page down with it. */
    public function test_resolve_skips_a_broken_pattern_and_keeps_going(): void {
        $hit = Seonix_Redirects_Runner::resolve_regex(
            array(
                $this->rule( '^/blog/([0-9]+$', '/never', array( 'id' => 1 ) ),
                $this->rule( '^/blog/(\d+)$', '/archive/$1', array( 'id' => 2 ) ),
            ),
            '/blog/9'
        );

        $this->assertSame( 2, $hit['id'] );
        $this->assertSame( '/archive/9', $hit['target'] );
    }

    /** Expanding to the requested path would bounce the browser forever. */
    public function test_resolve_refuses_a_self_targeting_expansion(): void {
        $this->assertNull(
            Seonix_Redirects_Runner::resolve_regex(
                array( $this->rule( '^/(.*)$', '/$1' ) ),
                '/same-page'
            )
        );
    }

    public function test_resolve_handles_a_targetless_410_rule(): void {
        $hit = Seonix_Redirects_Runner::resolve_regex(
            array( $this->rule( '^/old-shop/.*$', '', array( 'status_code' => 410 ) ) ),
            '/old-shop/item-1'
        );

        $this->assertSame( 410, $hit['status'] );
        $this->assertSame( '', $hit['target'] );
    }

    public function test_resolve_skips_a_targetless_rule_that_is_not_410(): void {
        $this->assertNull(
            Seonix_Redirects_Runner::resolve_regex(
                array( $this->rule( '^/x$', '', array( 'status_code' => 301 ) ) ),
                '/x'
            )
        );
    }

    // ─── capture expansion / injection ───────────────────────────────────

    /**
     * Captures are attacker-controlled: they come from the requested URL and end
     * up in a Location header. A crafted path must not be able to break out.
     */
    public function test_expansion_neutralises_dangerous_characters(): void {
        $out = Seonix_Redirects_Runner::expand_captures( '/go/$1', array( '', 'a b"c<d>e#f?g%h' ) );

        $this->assertSame( '/go/a%20b%22c%3Cd%3Ee%23f%3Fg%25h', $out );
    }

    /** Slashes are legitimate inside a capture and must survive. */
    public function test_expansion_keeps_path_separators(): void {
        $this->assertSame( '/archive/a/b/c', Seonix_Redirects_Runner::expand_captures( '/archive/$1', array( '', 'a/b/c' ) ) );
    }

    /** mod_rewrite drops references with no group; so do we. */
    public function test_expansion_drops_unmatched_references(): void {
        $this->assertSame( '/x/', Seonix_Redirects_Runner::expand_captures( '/x/$2', array( '', 'only-one' ) ) );
    }

    public function test_expansion_leaves_a_target_without_references_alone(): void {
        $this->assertSame( '/plain', Seonix_Redirects_Runner::expand_captures( '/plain', array( '', 'x' ) ) );
    }
}
