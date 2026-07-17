<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Store;

/**
 * Pure normalization / validation rules of the redirects store.
 */
final class StoreValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Target canonicalization reads the permalink convention + site host.
		// Default to "no convention" so the pure fixtures keep their literal
		// expectations; the canonicalization tests below override per case.
		Functions\when( 'get_option' )->alias( static function ( $key, $default = '' ) {
			return $default;
		} );
		Functions\when( 'home_url' )->justReturn( 'http://example.org' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

    // ─── normalize_from_path ─────────────────────────────────────────────

    /**
     * @dataProvider provide_from_paths
     */
    public function test_normalize_from_path( $raw, ?string $expected ): void {
        $this->assertSame( $expected, Seonix_Redirects_Store::normalize_from_path( $raw ) );
    }

    public static function provide_from_paths(): array {
        return array(
            'plain path'                 => array( '/old-page', '/old-page' ),
            'trailing slash preserved'   => array( '/old-page/', '/old-page/' ),
            'root'                       => array( '/', '/' ),
            'whitespace trimmed'         => array( "  /a  \n", '/a' ),
            'query stripped'             => array( '/a?x=1', '/a' ),
            'fragment stripped'          => array( '/a#top', '/a' ),
            'query and fragment'         => array( '/a?x=1#top', '/a' ),
            'empty string'               => array( '', null ),
            'not a string'               => array( array( '/a' ), null ),
            'missing leading slash'      => array( 'old-page', null ),
            'absolute url rejected'      => array( 'https://x.test/a', null ),
            'protocol-relative rejected' => array( '//x.test/a', null ),
            'bare query rejected'        => array( '?p=1', null ),
            'too long'                   => array( '/' . str_repeat( 'a', 191 ), null ),
            'exactly 191 ok'             => array( '/' . str_repeat( 'a', 190 ), '/' . str_repeat( 'a', 190 ) ),
        );
    }

    // ─── match_key ───────────────────────────────────────────────────────

    /**
     * @dataProvider provide_match_keys
     */
    public function test_match_key( string $path, string $expected ): void {
        $this->assertSame( $expected, Seonix_Redirects_Store::match_key( $path ) );
    }

    public static function provide_match_keys(): array {
        return array(
            'trailing slash stripped' => array( '/a/', '/a' ),
            'no trailing slash'       => array( '/a', '/a' ),
            'root stays root'         => array( '/', '/' ),
            'lowercased'              => array( '/Über-Uns/', '/über-uns' ),
            'ascii lowercased'        => array( '/About-US', '/about-us' ),
            'url-decoded'             => array( '/caf%C3%A9', '/café' ),
            'encoded + slash + case'  => array( '/CaF%C3%A9/', '/café' ),
        );
    }

    // ─── is_valid_to_url ─────────────────────────────────────────────────

    /**
     * @dataProvider provide_to_urls
     */
    public function test_is_valid_to_url( $to_url, bool $expected ): void {
        $this->assertSame( $expected, Seonix_Redirects_Store::is_valid_to_url( $to_url ) );
    }

    public static function provide_to_urls(): array {
        return array(
            'relative path'           => array( '/new', true ),
            'relative with query'     => array( '/new?x=1', true ),
            'absolute http'           => array( 'http://x.test/new', true ),
            'absolute https'          => array( 'https://x.test/new', true ),
            'scheme case-insensitive' => array( 'HTTPS://x.test/new', true ),
            'empty'                   => array( '', false ),
            'whitespace only'         => array( '   ', false ),
            'not a string'            => array( 301, false ),
            'protocol-relative'       => array( '//x.test/new', false ),
            'javascript scheme'       => array( 'javascript:alert(1)', false ),
            'bare word'               => array( 'new-page', false ),
        );
    }

    // ─── validate_rule ───────────────────────────────────────────────────

    public function test_validate_rule_accepts_valid_rule_and_normalizes(): void {
        $r = Seonix_Redirects_Store::validate_rule( '/old?utm=x', '/new', '301' );

        $this->assertTrue( $r['ok'] );
        $this->assertSame( '/old', $r['from_path'] );
        $this->assertSame( '/new', $r['to_url'] );
        $this->assertSame( 301, $r['status_code'] );
    }

    public function test_validate_rule_accepts_302(): void {
        $r = Seonix_Redirects_Store::validate_rule( '/old', '/new', 302 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 302, $r['status_code'] );
    }

    /**
     * @dataProvider provide_invalid_rules
     */
    public function test_validate_rule_rejects( $from, $to, $code ): void {
        $r = Seonix_Redirects_Store::validate_rule( $from, $to, $code );
        $this->assertFalse( $r['ok'] );
        $this->assertNotSame( '', $r['error'] );
    }

    public static function provide_invalid_rules(): array {
        return array(
            'bad from_path'             => array( 'no-slash', '/new', 301 ),
            'empty to_url'              => array( '/old', '', 301 ),
            'status 303'                => array( '/old', '/new', 303 ),
            'status 0'                  => array( '/old', '/new', 0 ),
            'self target'               => array( '/old', '/old', 301 ),
            'self target slash-variant' => array( '/old', '/old/', 301 ),
            'self target case-variant'  => array( '/Old', '/old', 301 ),
        );
    }

    public function test_validate_rule_allows_same_prefix_different_path(): void {
        $r = Seonix_Redirects_Store::validate_rule( '/old', '/older', 301 );
        $this->assertTrue( $r['ok'] );
    }

    public function test_validate_rule_absolute_target_is_not_self_checked(): void {
        // Absolute targets are validated for shape only — host-aware self-loop
        // detection happens at runtime where home_url() is known.
        $r = Seonix_Redirects_Store::validate_rule( '/old', 'https://x.test/old', 301 );
        $this->assertTrue( $r['ok'] );
    }

    // ─── canonicalize_target ─────────────────────────────────────────────

    /** Point get_option at a concrete permalink structure for one test. */
    private function with_permalink_structure( string $structure ): void {
        Functions\when( 'get_option' )->alias( static function ( $key, $default = '' ) use ( $structure ) {
            return 'permalink_structure' === $key ? $structure : $default;
        } );
    }

    /**
     * @dataProvider provide_canonical_targets
     */
    public function test_canonicalize_target( string $structure, string $to_url, string $expected ): void {
        $this->with_permalink_structure( $structure );
        $this->assertSame( $expected, Seonix_Redirects_Store::canonicalize_target( $to_url ) );
    }

    public static function provide_canonical_targets(): array {
        return array(
            // Slashed permalink convention (the WordPress default).
            'slashed: adds the slash'       => array( '/%postname%/', '/new-page', '/new-page/' ),
            'slashed: keeps the slash'      => array( '/%postname%/', '/new-page/', '/new-page/' ),
            'slashed: query survives'       => array( '/%postname%/', '/new?x=1#f', '/new/?x=1#f' ),
            'slashed: file untouched'       => array( '/%postname%/', '/brochure.pdf', '/brochure.pdf' ),
            'slashed: root untouched'       => array( '/%postname%/', '/', '/' ),
            'slashed: same-host absolute'   => array( '/%postname%/', 'http://example.org/a/b', 'http://example.org/a/b/' ),
            'slashed: external untouched'   => array( '/%postname%/', 'https://other.test/a', 'https://other.test/a' ),
            // Unslashed convention strips; plain permalinks have no convention.
            'unslashed: strips the slash'   => array( '/%postname%', '/new-page/', '/new-page' ),
            'plain permalinks: untouched'   => array( '', '/new-page', '/new-page' ),
        );
    }

    /**
     * The save path lands every literal fix on the page's canonical form — this
     * is what stops an AI-applied redirect from parking one 301 short of the
     * page (the wohnart chain: our 301 → theme's canonical slash 301 → 200).
     */
    public function test_validate_rule_canonicalizes_the_literal_target(): void {
        $this->with_permalink_structure( '/%postname%/' );

        $r = Seonix_Redirects_Store::validate_rule( '/old', '/new-page', 301 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '/new-page/', $r['to_url'] );

        $abs = Seonix_Redirects_Store::validate_rule( '/old', 'http://example.org/new-page', 301 );
        $this->assertTrue( $abs['ok'] );
        $this->assertSame( 'http://example.org/new-page/', $abs['to_url'] );
    }
}
