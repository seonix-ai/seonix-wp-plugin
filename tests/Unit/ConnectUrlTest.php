<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\TransientStub;
use Seonix_Admin;

/**
 * Covers the just-in-time connect URL minter, AJAX action seonix_connect_url.
 *
 * Security fix (2.5.0): the one-time connect nonce is no longer baked into the
 * Dashboard HTML. Instead the "Connect"/"Reconnect" buttons POST to this
 * capability + nonce gated handler, which mints a fresh connect URL on demand
 * (Seonix_Admin::build_connect_url) and returns it for a client-side redirect.
 *
 * Contract:
 *   - capability (manage_options) + a valid nonce  → wp_send_json_success with
 *     a 'url' that carries the one-time nonce fragment.
 *   - missing manage_options                        → wp_send_json_error (403).
 *
 * wp_send_json_success / wp_send_json_error die() in real WordPress. We stub
 * them to throw a typed exception carrying the payload + HTTP status so the
 * test can assert on the outcome without terminating the process.
 */
final class ConnectUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Seonix_Admin lazily news up Seonix_Tasks(), whose constructor reads
		// $GLOBALS['wpdb']->prefix. Provide a minimal stand-in so constructing
		// the admin object (the only thing under test here) doesn't trip on it.
		$wpdb            = new \stdClass();
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		// set_transient/get_transient/delete_transient are defined in
		// tests/bootstrap.php before Patchwork loads, so Brain Monkey cannot
		// redefine them. build_connect_url() writes the one-time nonce via
		// set_transient → the bootstrap stub backs it with TransientStub::$store,
		// which we reset here instead of mocking the function.
		TransientStub::$store = [];

		Functions\when( '__' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		// wp_send_json_success / _error normally die(); throw instead so we can
		// assert on the outcome. The exception carries the data + status.
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = null ) {
				throw new SeonixJsonResponse( true, $data, $status );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new SeonixJsonResponse( false, $data, $status );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TransientStub::$store = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_with_capability_and_valid_nonce_returns_url(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// home_url() must pass Seonix_Sync::is_safe_url (resolves to a public IP).
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// build_connect_url() helpers. set_transient is the bootstrap stub
		// (TransientStub-backed), so it is intentionally not mocked here.
		Functions\when( 'wp_generate_password' )->justReturn( 'one-time-secret-nonce' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $args, $url ) => $url . '?provider=wordpress&site=https://example.com'
		);
		// Mimic real WordPress: esc_url() (display context) HTML-entity-encodes
		// '&' to '&#038;'; esc_url_raw() (non-display) does not. build_connect_url
		// MUST use esc_url_raw because the URL is consumed by window.location.href.
		Functions\when( 'esc_url' )->alias( static fn ( $u ) => str_replace( '&', '&#038;', $u ) );
		Functions\when( 'esc_url_raw' )->returnArg();

		try {
			( new Seonix_Admin() )->ajax_connect_url();
			$this->fail( 'Expected wp_send_json_success to be invoked.' );
		} catch ( SeonixJsonResponse $r ) {
			$this->assertTrue( $r->success, 'expected a success response' );
			$this->assertIsArray( $r->data );
			$this->assertArrayHasKey( 'url', $r->data );
			$this->assertStringContainsString( 'https://app.seonix.ai/connect', $r->data['url'] );
			// The one-time nonce is appended as a URL fragment.
			$this->assertStringContainsString( '#nonce=one-time-secret-nonce', $r->data['url'] );
			// Regression guard (2.5.1): the query separator must be a real '&',
			// NOT the HTML entity '&#038;' that esc_url() would emit — that broke
			// the handoff because the URL is assigned to window.location.href.
			$this->assertStringContainsString( '&site=', $r->data['url'] );
			$this->assertStringNotContainsString( '&#038;', $r->data['url'] );
		}
	}

	public function test_without_manage_options_returns_error_403(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		// is_safe_url / build_connect_url must NOT be reached, but stub home_url
		// defensively so a regression that calls it early doesn't fatal.
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		try {
			( new Seonix_Admin() )->ajax_connect_url();
			$this->fail( 'Expected wp_send_json_error to be invoked.' );
		} catch ( SeonixJsonResponse $r ) {
			$this->assertFalse( $r->success, 'expected an error response' );
			$this->assertSame( 403, $r->status );
			$this->assertIsArray( $r->data );
			$this->assertArrayHasKey( 'message', $r->data );
		}
	}
}

/**
 * Test-only carrier for a wp_send_json_* call so the handler's terminal
 * response can be asserted without die().
 */
final class SeonixJsonResponse extends \Exception {
	public bool $success;
	/** @var mixed */
	public $data;
	/** @var int|null */
	public $status;

	public function __construct( bool $success, $data, $status ) {
		parent::__construct( 'seonix json response' );
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}
