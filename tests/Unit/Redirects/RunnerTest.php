<?php
namespace Seonix\Tests\Unit\Redirects;

use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Runner;

/**
 * Pure matching logic of the front-end redirect runner: map compilation,
 * trailing-slash / case / url-encoding insensitivity, query passthrough,
 * one-hop flattening and cycle detection.
 */
final class RunnerTest extends TestCase {

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array{id:int,target:string,status:int}>
     */
    private function map( array $rows ): array {
        return Seonix_Redirects_Runner::build_map( $rows );
    }

    private function row( int $id, string $from, string $to, int $status = 301 ): array {
        return array(
            'id'          => $id,
            'from_path'   => $from,
            'to_url'      => $to,
            'status_code' => $status,
        );
    }

    // ─── build_map ───────────────────────────────────────────────────────

    public function test_build_map_keys_by_match_key(): void {
        $map = $this->map( array( $this->row( 1, '/Old-Page/', '/new' ) ) );

        $this->assertArrayHasKey( '/old-page', $map );
        $this->assertSame( 1, $map['/old-page']['id'] );
        $this->assertSame( '/new', $map['/old-page']['target'] );
        $this->assertSame( 301, $map['/old-page']['status'] );
    }

    public function test_build_map_first_row_wins_on_key_collision(): void {
        $map = $this->map( array(
            $this->row( 1, '/a', '/first' ),
            $this->row( 2, '/a/', '/second' ),
        ) );

        $this->assertSame( '/first', $map['/a']['target'] );
    }

    public function test_build_map_skips_incomplete_rows_and_bad_status(): void {
        $map = $this->map( array(
            $this->row( 1, '', '/x' ),
            $this->row( 2, '/y', '' ),
            $this->row( 3, '/z', '/ok', 307 ),
            $this->row( 4, '/w', '/ok', 418 ),
        ) );

        $this->assertArrayNotHasKey( '', $map );
        $this->assertArrayNotHasKey( '/y', $map, 'a redirect with no target cannot be served' );
        $this->assertSame( 307, $map['/z']['status'], '307 is a supported code, not a typo to be corrected' );
        $this->assertSame( 301, $map['/w']['status'], 'an unsupported code still falls back to a safe permanent redirect' );
    }

    /** 410 says "gone" — it is the one code that legitimately has no target. */
    public function test_build_map_keeps_targetless_410(): void {
        $map = $this->map( array( $this->row( 1, '/dead', '', 410 ) ) );

        $this->assertArrayHasKey( '/dead', $map );
        $this->assertSame( 410, $map['/dead']['status'] );
        $this->assertSame( '', $map['/dead']['target'] );
    }

    /** Regex rules are matched in their own ordered pass, never via the map. */
    public function test_build_map_excludes_regex_rows(): void {
        $row            = $this->row( 1, '^/blog/(\d+)$', '/archive/$1' );
        $row['is_regex'] = 1;

        $this->assertSame( array(), $this->map( array( $row ) ) );
    }


    /**
     * Rename a post, then trash it: A→B (301) exists, and B becomes a 410 with
     * no target. Flattening A onto B's empty target would send the visitor
     * nowhere — the request 404s and the redirect looks broken. Caught on a live
     * site, not in review.
     */
    public function test_resolve_does_not_flatten_onto_a_targetless_410(): void {
        $map = $this->map( array(
            $this->row( 1, '/old', '/new' ),
            $this->row( 2, '/new', '', 410 ),
        ) );

        $hit = Seonix_Redirects_Runner::resolve( $map, '/old' );

        $this->assertSame( '/new', $hit['target'], 'A must still point at B; B answers 410 on arrival' );
        $this->assertSame( 301, $hit['status'] );
    }

    // ─── resolve: basic matching ─────────────────────────────────────────

    public function test_resolve_matches_exact_path(): void {
        $map = $this->map( array( $this->row( 1, '/old', '/new', 302 ) ) );

        $hit = Seonix_Redirects_Runner::resolve( $map, '/old' );

        $this->assertNotNull( $hit );
        $this->assertSame( 1, $hit['id'] );
        $this->assertSame( '/new', $hit['target'] );
        $this->assertSame( 302, $hit['status'] );
    }

    public function test_resolve_matches_with_and_without_trailing_slash(): void {
        $map = $this->map( array( $this->row( 1, '/old', '/new' ) ) );

        $this->assertNotNull( Seonix_Redirects_Runner::resolve( $map, '/old/' ) );
        $this->assertNotNull( Seonix_Redirects_Runner::resolve( $map, '/old' ) );

        $map_slashed = $this->map( array( $this->row( 1, '/old/', '/new' ) ) );

        $this->assertNotNull( Seonix_Redirects_Runner::resolve( $map_slashed, '/old' ) );
        $this->assertNotNull( Seonix_Redirects_Runner::resolve( $map_slashed, '/old/' ) );
    }

    public function test_resolve_is_case_insensitive_and_url_decoded(): void {
        $map = $this->map( array( $this->row( 1, '/über-uns/', '/about' ) ) );

        $this->assertNotNull( Seonix_Redirects_Runner::resolve( $map, '/%C3%9Cber-Uns' ) );
    }

    public function test_resolve_returns_null_when_no_match(): void {
        $map = $this->map( array( $this->row( 1, '/old', '/new' ) ) );

        $this->assertNull( Seonix_Redirects_Runner::resolve( $map, '/other' ) );
    }

    public function test_resolve_root_redirect_matches_only_root(): void {
        $map = $this->map( array( $this->row( 1, '/', '/home' ) ) );

        $this->assertNotNull( Seonix_Redirects_Runner::resolve( $map, '/' ) );
        $this->assertNull( Seonix_Redirects_Runner::resolve( $map, '/page' ) );
    }

    // ─── resolve: self-loops, flattening, cycles ─────────────────────────

    public function test_resolve_refuses_self_loop(): void {
        // Legacy/manual data could hold '/a/' → '/a' — same match key.
        $map = array(
            '/a' => array( 'id' => 1, 'target' => '/a/', 'status' => 301 ),
        );

        $this->assertNull( Seonix_Redirects_Runner::resolve( $map, '/a' ) );
    }

    public function test_resolve_flattens_one_hop(): void {
        $map = $this->map( array(
            $this->row( 1, '/a', '/b', 301 ),
            $this->row( 2, '/b', '/c', 302 ),
        ) );

        $hit = Seonix_Redirects_Runner::resolve( $map, '/a' );

        $this->assertSame( '/c', $hit['target'] );
        // Status and hit attribution stay with the rule that matched.
        $this->assertSame( 301, $hit['status'] );
        $this->assertSame( 1, $hit['id'] );
    }

    public function test_resolve_flattens_only_one_hop(): void {
        $map = $this->map( array(
            $this->row( 1, '/a', '/b' ),
            $this->row( 2, '/b', '/c' ),
            $this->row( 3, '/c', '/d' ),
        ) );

        $hit = Seonix_Redirects_Runner::resolve( $map, '/a' );

        // One hop: /a lands on /c; the /c → /d rule fires on the NEXT request.
        $this->assertSame( '/c', $hit['target'] );
    }

    public function test_resolve_detects_two_rule_cycle_and_aborts(): void {
        $map = $this->map( array(
            $this->row( 1, '/a', '/b' ),
            $this->row( 2, '/b', '/a' ),
        ) );

        $this->assertNull( Seonix_Redirects_Runner::resolve( $map, '/a' ) );
        $this->assertNull( Seonix_Redirects_Runner::resolve( $map, '/b' ) );
    }

    public function test_resolve_does_not_flatten_through_absolute_targets(): void {
        $map = $this->map( array(
            $this->row( 1, '/a', 'https://x.test/b' ),
            $this->row( 2, '/b', '/c' ),
        ) );

        $hit = Seonix_Redirects_Runner::resolve( $map, '/a' );

        $this->assertSame( 'https://x.test/b', $hit['target'] );
    }

    public function test_resolve_flattens_target_with_query_on_lookup(): void {
        // The hop lookup strips the target's own query, but the target string
        // itself is preserved when no hop applies.
        $map = $this->map( array(
            $this->row( 1, '/a', '/b?keep=1' ),
            $this->row( 2, '/b', '/c' ),
        ) );

        $hit = Seonix_Redirects_Runner::resolve( $map, '/a' );

        $this->assertSame( '/c', $hit['target'] );
    }

    // ─── local_target_key ────────────────────────────────────────────────

    public function test_local_target_key_variants(): void {
        $this->assertSame( '/b', Seonix_Redirects_Runner::local_target_key( '/b/' ) );
        $this->assertSame( '/b', Seonix_Redirects_Runner::local_target_key( '/b?x=1' ) );
        $this->assertNull( Seonix_Redirects_Runner::local_target_key( 'https://x.test/b' ) );
        $this->assertNull( Seonix_Redirects_Runner::local_target_key( '//x.test/b' ) );
        $this->assertNull( Seonix_Redirects_Runner::local_target_key( '' ) );
    }

    // ─── append_query ────────────────────────────────────────────────────

    public function test_append_query_passthrough(): void {
        $this->assertSame( '/new', Seonix_Redirects_Runner::append_query( '/new', '' ) );
        $this->assertSame( '/new?utm=x', Seonix_Redirects_Runner::append_query( '/new', 'utm=x' ) );
        $this->assertSame( '/new?a=1&utm=x', Seonix_Redirects_Runner::append_query( '/new?a=1', 'utm=x' ) );
        $this->assertSame(
            'https://x.test/p?utm=x&b=2',
            Seonix_Redirects_Runner::append_query( 'https://x.test/p?utm=x', 'b=2' )
        );
    }

    // ─── is_same_host_self_target ────────────────────────────────────────

    public function test_same_host_self_target_detection(): void {
        $this->assertTrue(
            Seonix_Redirects_Runner::is_same_host_self_target( 'https://x.test/a/', '/a', 'x.test' )
        );
        $this->assertTrue(
            Seonix_Redirects_Runner::is_same_host_self_target( 'https://X.TEST/a', '/a/', 'x.test' )
        );
        $this->assertFalse(
            Seonix_Redirects_Runner::is_same_host_self_target( 'https://other.test/a', '/a', 'x.test' )
        );
        $this->assertFalse(
            Seonix_Redirects_Runner::is_same_host_self_target( 'https://x.test/b', '/a', 'x.test' )
        );
        $this->assertFalse(
            Seonix_Redirects_Runner::is_same_host_self_target( '/a', '/a', 'x.test' ),
            'Relative targets are handled by resolve(), not the host check.'
        );
    }

    // ─── relativize_same_host ────────────────────────────────────────────

    public function test_relativize_folds_an_absolute_same_host_target(): void {
        $this->assertSame(
            '/moebelmontage/oberkraemer',
            Seonix_Redirects_Runner::relativize_same_host( 'https://wohnartstudio.de/moebelmontage/oberkraemer', 'wohnartstudio.de' ),
            'The fix applier writes absolute same-host targets — they must fold into local paths.'
        );
        $this->assertSame(
            '/a?x=1#f',
            Seonix_Redirects_Runner::relativize_same_host( 'https://x.test/a?x=1#f', 'x.test' ),
            'Query and fragment survive the fold.'
        );
    }

    public function test_relativize_leaves_external_and_relative_targets_alone(): void {
        $this->assertSame(
            'https://other.test/x',
            Seonix_Redirects_Runner::relativize_same_host( 'https://other.test/x', 'x.test' )
        );
        $this->assertSame( '/x', Seonix_Redirects_Runner::relativize_same_host( '/x', 'x.test' ) );
        $this->assertSame(
            'https://x.test/x',
            Seonix_Redirects_Runner::relativize_same_host( 'https://x.test/x', '' ),
            'Unknown home host: take the target at face value.'
        );
    }

    // ─── flatten_chain ───────────────────────────────────────────────────

    public function test_flatten_chain_follows_rules_to_the_final_target(): void {
        $map = array(
            '/mid'  => array( 'id' => 2, 'target' => '/mid2', 'status' => 301 ),
            '/mid2' => array( 'id' => 3, 'target' => '/final', 'status' => 301 ),
        );
        $this->assertSame(
            '/final',
            Seonix_Redirects_Runner::flatten_chain( '/mid', $map, '/old', 'x.test' ),
            'One request, one redirect: the emitted target is the END of our own rule chain.'
        );
    }

    public function test_flatten_chain_walks_through_absolute_same_host_links(): void {
        $map = array(
            '/mid' => array( 'id' => 2, 'target' => '/final', 'status' => 301 ),
        );
        $this->assertSame(
            '/final',
            Seonix_Redirects_Runner::flatten_chain( 'https://x.test/mid', $map, '/old', 'x.test' ),
            'An absolute same-host hop must not stop the walk — it folds and continues.'
        );
    }

    public function test_flatten_chain_returns_null_on_a_cycle(): void {
        $map = array(
            '/a' => array( 'id' => 1, 'target' => '/b', 'status' => 301 ),
            '/b' => array( 'id' => 2, 'target' => '/a', 'status' => 301 ),
        );
        $this->assertNull(
            Seonix_Redirects_Runner::flatten_chain( '/b', $map, '/a', 'x.test' ),
            'A chain that circles back to the requested page must serve, not bounce.'
        );
    }

    public function test_flatten_chain_stops_before_a_targetless_410_rule(): void {
        $map = array(
            '/mid' => array( 'id' => 2, 'target' => '', 'status' => 410 ),
        );
        $this->assertSame(
            '/mid',
            Seonix_Redirects_Runner::flatten_chain( '/mid', $map, '/old', 'x.test' ),
            'The honest chain lets the 410 answer itself on arrival (see resolve()).'
        );
    }

    public function test_flatten_chain_is_hop_capped(): void {
        $map = array(
            '/h1' => array( 'id' => 1, 'target' => '/h2', 'status' => 301 ),
            '/h2' => array( 'id' => 2, 'target' => '/h3', 'status' => 301 ),
            '/h3' => array( 'id' => 3, 'target' => '/h4', 'status' => 301 ),
            '/h4' => array( 'id' => 4, 'target' => '/h5', 'status' => 301 ),
        );
        $this->assertSame(
            '/h4',
            Seonix_Redirects_Runner::flatten_chain( '/h1', $map, '/old', 'x.test' ),
            'Three further hops at most — a pathological chain must not spin the request.'
        );
    }
}
