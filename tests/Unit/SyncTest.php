<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Seonix_Sync;

/**
 * Locks the sync payload contract: every field the backend's syncRequest
 * struct expects must be present in the right shape, particularly updated_at
 * which the Go decoder parses as RFC3339 (any other format = whole payload
 * rejected with BAD_REQUEST).
 */
final class SyncTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_permalink' )->alias( fn ( $id ) => "https://example.test/post/$id/" );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_format_item_emits_required_top_level_fields(): void {
        $post = $this->makePost( array(
            'ID'                => 42,
            'post_title'        => 'Hello',
            'post_name'         => 'hello',
            'post_status'       => 'publish',
            'post_modified_gmt' => '2026-04-15 10:30:00',
        ) );

        $item = $this->formatItem( $post, 'page' );

        $this->assertSame( 42, $item['wp_id'] );
        $this->assertSame( 'page', $item['content_type'] );
        $this->assertSame( 'Hello', $item['title'] );
        $this->assertSame( 'hello', $item['slug'] );
        $this->assertSame( 'https://example.test/post/42/', $item['url'] );
        $this->assertSame( 'publish', $item['status'] );
        $this->assertArrayHasKey( 'updated_at', $item );
    }

    public function test_updated_at_is_rfc3339_for_backend_decoder(): void {
        $post = $this->makePost( array(
            'ID'                => 7,
            'post_modified_gmt' => '2026-04-15 10:30:00',
        ) );

        $item = $this->formatItem( $post, 'post' );

        // Go's *time.Time JSON decode requires RFC3339: "2006-01-02T15:04:05Z07:00".
        // That means a 'T' separator, no space, and a UTC indicator.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
            $item['updated_at'],
            'updated_at must be RFC3339; backend syncRequest will reject anything else.'
        );
        // GMT timestamp must round-trip to the same point in time.
        $this->assertSame( '2026-04-15T10:30:00+00:00', $item['updated_at'] );
    }

    public function test_updated_at_handles_empty_modified_gmt(): void {
        // WP gives '0000-00-00 00:00:00' for unsaved posts; we shouldn't emit
        // an invalid RFC3339 the backend will choke on.
        $post = $this->makePost( array(
            'ID'                => 1,
            'post_modified_gmt' => '0000-00-00 00:00:00',
        ) );

        $item = $this->formatItem( $post, 'post' );

        $this->assertNull( $item['updated_at'], 'Zero-date should serialise to null, not an invalid RFC3339' );
    }

    // ─── SSRF guard (Seonix_Sync::is_safe_url delegates to Seonix_Auth) ───────
    //
    // Seonix_Sync::is_safe_url() is now a thin wrapper over the shared
    // Seonix_Auth::is_safe_url(). These cases lock the guard from the sync side
    // (the path that previously lacked the IPv6/AAAA check). The name-based and
    // IPv4 paths return before any real DNS lookup — gethostbynamel() echoes a
    // dotted-quad host back verbatim, so private/loopback IPv4 literals are
    // validated deterministically without network access.

    public function test_is_safe_url_rejects_loopback_name(): void {
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://localhost/evil' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'https://localhost.localdomain/' ) );
    }

    public function test_is_safe_url_rejects_local_and_localhost_suffixes(): void {
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://router.local/' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://foo.localhost/' ) );
    }

    public function test_is_safe_url_rejects_zero_host(): void {
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://0.0.0.0/' ) );
    }

    public function test_is_safe_url_rejects_non_http_scheme(): void {
        $this->assertFalse( Seonix_Sync::is_safe_url( 'file:///etc/passwd' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'gopher://example.com/' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'not a url' ) );
    }

    public function test_is_safe_url_rejects_private_and_loopback_ipv4(): void {
        // gethostbynamel() returns a dotted-quad host unchanged, so these exercise
        // the FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE rejection directly.
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://127.0.0.1/' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://10.0.0.5/' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://192.168.1.1/' ) );
        $this->assertFalse( Seonix_Sync::is_safe_url( 'http://169.254.169.254/latest/meta-data/' ) );
    }

    public function test_is_safe_url_validates_aaaa_records_branch_exists(): void {
        // Mocking real AAAA DNS is impractical in a unit test, so assert the guard
        // actually contains an AAAA (IPv6) resolution branch — this is the gap the
        // security fix closed in the sync path. We read the shared validator's
        // source via reflection and check for the dns_get_record(..., DNS_AAAA) call.
        $ref   = new \ReflectionMethod( \Seonix_Auth::class, 'is_safe_url' );
        $file  = $ref->getFileName();
        $lines = file( $file );
        $body  = implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
        $this->assertStringContainsString( 'DNS_AAAA', $body, 'Shared SSRF guard must resolve AAAA (IPv6) records.' );
        $this->assertStringContainsString( "filter_var( \$rec['ipv6']", $body, 'AAAA records must be validated against private/reserved ranges.' );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function makePost( array $overrides = array() ): object {
        $defaults = array(
            'ID'                => 1,
            'post_title'        => 'Title',
            'post_name'         => 'slug',
            'post_status'       => 'publish',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        );
        return (object) array_merge( $defaults, $overrides );
    }

    /**
     * Reach into Seonix_Sync::format_item — the method is private so we
     * exercise it via reflection rather than copy-pasting its formatting.
     */
    private function formatItem( object $post, string $content_type ): array {
        $sync = new Seonix_Sync();
        $ref  = new ReflectionClass( $sync );
        $m    = $ref->getMethod( 'format_item' );
        $m->setAccessible( true );
        return $m->invoke( $sync, $post, $content_type );
    }
}
