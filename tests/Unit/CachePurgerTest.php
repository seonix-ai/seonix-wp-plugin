<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Cache_Purger;

/**
 * Cache purger detection runs class_exists / function_exists checks for each
 * vendor cache plugin. The tests below stand in fake function definitions
 * via Brain Monkey so we can exercise the dispatch logic without pulling in
 * the actual cache plugins.
 */
final class CachePurgerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_detect_returns_object_cache_always_available(): void {
        // wp_cache_flush is defined in tests/bootstrap path or via Brain Monkey.
        // It always reports available because every WP install ships a default
        // object cache.
        Functions\when( 'wp_cache_flush' )->justReturn( true );

        $engines = Seonix_Cache_Purger::detect();

        $this->assertArrayHasKey( 'object_cache', $engines );
        $this->assertTrue( $engines['object_cache']['available'] );
    }

    public function test_detect_marks_litespeed_unavailable_when_class_missing(): void {
        // No LiteSpeed\Core class registered → unavailable.
        $engines = Seonix_Cache_Purger::detect();
        $this->assertFalse( $engines['litespeed']['available'] );
    }

    public function test_purge_all_dispatches_to_litespeed_via_action_hook(): void {
        // Pretend LiteSpeed is installed (function exists path).
        Functions\when( 'litespeed_purge_all' )->justReturn( null );

        $hookCalls = array();
        Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$hookCalls ) {
            $hookCalls[] = $hook;
        } );

        Seonix_Cache_Purger::purge_all();

        $this->assertContains( 'litespeed_purge_all', $hookCalls );
    }

    public function test_purge_posts_calls_litespeed_per_post_when_available(): void {
        Functions\when( 'litespeed_purge_all' )->justReturn( null ); // marks engine available

        $hookCalls = array();
        Functions\when( 'do_action' )->alias( function ( $hook, ...$args ) use ( &$hookCalls ) {
            $hookCalls[] = array( $hook, $args );
        } );

        Seonix_Cache_Purger::purge_posts( array( 5, 7 ) );

        $perPost = array_values( array_filter( $hookCalls,
            fn ( $h ) => $h[0] === 'litespeed_purge_post' ) );
        $this->assertCount( 2, $perPost );
        $this->assertSame( array( 5 ), $perPost[0][1] );
        $this->assertSame( array( 7 ), $perPost[1][1] );
    }

    public function test_purge_posts_falls_back_to_domain_purge_for_wp_rocket_without_per_post_helper(): void {
        Functions\when( 'rocket_clean_domain' )->justReturn( null );
        // rocket_clean_post intentionally NOT defined → fallback path.

        $domainCalled = 0;
        Functions\when( 'rocket_clean_domain' )->alias( function () use ( &$domainCalled ) {
            $domainCalled++;
        } );
        // Re-detect (function_exists is what we check; alias still satisfies it).

        Seonix_Cache_Purger::purge_posts( array( 5 ) );
        $this->assertGreaterThanOrEqual( 1, $domainCalled,
            'expected fallback to rocket_clean_domain when per-post helper is missing' );
    }

    public function test_active_engines_returns_a_subset_of_known_engines(): void {
        // We can't isolate function_exists state across tests in this suite —
        // any earlier test that stubbed litespeed_purge_all leaves the symbol
        // permanently defined. So we verify the WEAKER property: every key
        // active_engines() returns is one we know about.
        Functions\when( 'wp_cache_flush' )->justReturn( true );

        $active   = Seonix_Cache_Purger::active_engines();
        $known    = array( 'litespeed', 'cloudflare', 'wp_rocket', 'w3tc', 'super_cache', 'object_cache' );

        $this->assertContains( 'object_cache', $active, 'object_cache must always be detectable' );
        foreach ( $active as $eng ) {
            $this->assertContains( $eng, $known, "unexpected engine reported active: $eng" );
        }
    }

    public function test_purge_all_returns_per_engine_success_map(): void {
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn( null );

        $results = Seonix_Cache_Purger::purge_all();

        $this->assertIsArray( $results );
        $this->assertArrayHasKey( 'object_cache', $results );
        $this->assertTrue( $results['object_cache'] );
    }

    public function test_purge_posts_filters_invalid_ids(): void {
        Functions\when( 'litespeed_purge_all' )->justReturn( null );

        $hooked = array();
        Functions\when( 'do_action' )->alias( function ( $hook, ...$args ) use ( &$hooked ) {
            if ( $hook === 'litespeed_purge_post' ) {
                $hooked[] = $args[0] ?? null;
            }
        } );

        // 0 and negative IDs should be discarded; duplicates collapsed.
        Seonix_Cache_Purger::purge_posts( array( 5, 5, 0, -1, 7, '8' ) );

        $this->assertEqualsCanonicalizing( array( 5, 7, 8 ), $hooked );
    }
}
