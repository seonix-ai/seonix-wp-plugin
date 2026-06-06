<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\TransientStub;
use Seonix_REST_API;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers the one-click connect handoff sink, POST /connect/exchange.
 *
 * Contract (locked against the Seonix backend connector — see
 * backend/internal/connector/wordpress.go::Exchange and
 * _workspace/connect-and-tasks-plan.md §4):
 *
 *   request body { nonce, engine_url, project_id, project_name }
 *   - valid nonce  → delete the one-time transient, self-configure
 *                    engine_url/project_id/project_name, mark connected,
 *                    return { api_key, site_name, site_url } with 200.
 *   - missing/expired nonce → 403 (no self-configuration).
 *   - unsafe engine_url     → 400 (the nonce IS consumed first, by design —
 *                    it's one-time regardless of the rest of the payload).
 *
 * The nonce is matched against a SHA-256-keyed transient so the raw secret
 * never persists on disk. Transient stubs live in tests/bootstrap.php and are
 * backed by TransientStub::$store, so we drive that store directly instead of
 * mocking get/set/delete_transient (which Brain Monkey can't redefine — they're
 * defined before Patchwork loads).
 *
 * The handler consumes the nonce atomically via Seonix_REST_API::consume_connect_nonce().
 * Production prefers a single atomic DELETE on the options table (so concurrent
 * replays can't both win), and only falls back to get+delete when an external
 * object cache is present (transients then live in cache, not the options table).
 * We force wp_using_ext_object_cache() => true in these unit tests so the helper
 * takes the get+delete branch, which is exactly what the in-memory TransientStub
 * models — the atomic-DELETE branch is a $wpdb path covered separately.
 */
final class ConnectExchangeTest extends TestCase {

	private Seonix_REST_API $api;

	/** Captured update_option() writes, keyed by option name. */
	private array $updated = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->updated      = [];
		TransientStub::$store = [];

		$captured =& $this->updated;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : ''
		);
		Functions\when( 'esc_url_raw' )->alias(
			static fn ( $value ) => is_string( $value ) ? filter_var( $value, FILTER_SANITIZE_URL ) : ''
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Studio' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias(
			static fn ( $name, $default = '' ) => $name === 'seonix_api_key' ? 'sx_abc123' : $default
		);
		// Force the cache-backed consume path so the in-memory TransientStub
		// models exactly what the helper touches (get_transient + delete_transient).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

		$this->api = new Seonix_REST_API();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		TransientStub::$store = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_valid_nonce_consumes_transient_and_self_configures(): void {
		$nonce = 'one-time-secret-nonce';
		$key   = 'seonix_connect_' . hash( 'sha256', $nonce );
		TransientStub::$store[ $key ] = 1;

		$request = new WP_REST_Request( array(
			'nonce'        => $nonce,
			'engine_url'   => 'https://example.com', // resolves to a public IP → passes SSRF guard.
			'project_id'   => 'c48ddd33-c2fa-45bc-b3b0-956fe1234567',
			'project_name' => 'example-studio',
		) );

		$response = $this->api->handle_connect_exchange( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'sx_abc123', $data['api_key'] );
		$this->assertSame( 'Example Studio', $data['site_name'] );
		$this->assertSame( 'https://example.com', $data['site_url'] );

		// One-time: the transient was deleted.
		$this->assertArrayNotHasKey( $key, TransientStub::$store );

		// Self-configured.
		$this->assertSame( 'https://example.com', $this->updated['seonix_engine_url'] ?? null );
		$this->assertTrue( $this->updated['seonix_connected'] ?? false );
		$this->assertSame( 'c48ddd33-c2fa-45bc-b3b0-956fe1234567', $this->updated['seonix_project_id'] ?? null );
		$this->assertSame( 'example-studio', $this->updated['seonix_project_name'] ?? null );
		$this->assertArrayHasKey( 'seonix_connected_at', $this->updated );
	}

	public function test_missing_nonce_returns_403(): void {
		$request = new WP_REST_Request( array(
			'engine_url' => 'https://example.com',
		) );

		$response = $this->api->handle_connect_exchange( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayNotHasKey( 'seonix_engine_url', $this->updated );
		$this->assertArrayNotHasKey( 'seonix_connected', $this->updated );
	}

	public function test_unknown_nonce_returns_403_without_configuring(): void {
		// Transient store is empty → get_transient returns false → 403.
		$request = new WP_REST_Request( array(
			'nonce'      => 'never-minted',
			'engine_url' => 'https://example.com',
		) );

		$response = $this->api->handle_connect_exchange( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayNotHasKey( 'seonix_engine_url', $this->updated );
	}

	public function test_unsafe_engine_url_returns_400_but_consumes_nonce(): void {
		$nonce = 'valid-but-bad-engine';
		$key   = 'seonix_connect_' . hash( 'sha256', $nonce );
		TransientStub::$store[ $key ] = 1;

		$request = new WP_REST_Request( array(
			'nonce'      => $nonce,
			'engine_url' => 'http://localhost/evil', // blocked by name in is_safe_url.
		) );

		$response = $this->api->handle_connect_exchange( $request );

		$this->assertSame( 400, $response->get_status() );
		// The nonce is one-time regardless: it was deleted before the engine check.
		$this->assertArrayNotHasKey( $key, TransientStub::$store );
		// No engine URL was stored.
		$this->assertArrayNotHasKey( 'seonix_engine_url', $this->updated );
	}

	// ─── Atomic-DELETE branch (no external object cache) ──────────────────────

	/**
	 * Without an external object cache the helper must consume the nonce with a
	 * single atomic DELETE on the options table and succeed only when that DELETE
	 * affected a row. This is the path that actually closes the TOCTOU window
	 * (the loser of a concurrent race gets 0 affected rows → 403).
	 */
	public function test_atomic_delete_branch_consumes_nonce_when_row_deleted(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$nonce        = 'atomic-winner';
		$expected_opt = '_transient_seonix_connect_' . hash( 'sha256', $nonce );

		// $wpdb whose value-row DELETE reports 1 affected row (this caller won).
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( $query, ...$args ) => str_replace( '%s', "'" . $args[0] . "'", $query )
		);
		// First query = value row (1 affected → won); subsequent = timeout cleanup.
		$wpdb->shouldReceive( 'query' )
			->with( Mockery::pattern( '/' . preg_quote( $expected_opt, '/' ) . "'/" ) )
			->andReturn( 1 );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 ); // timeout companion cleanup.
		$GLOBALS['wpdb'] = $wpdb;

		$request = new WP_REST_Request( array(
			'nonce'      => $nonce,
			'engine_url' => 'https://example.com',
		) );

		$response = $this->api->handle_connect_exchange( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://example.com', $this->updated['seonix_engine_url'] ?? null );
		$this->assertTrue( $this->updated['seonix_connected'] ?? false );
	}

	/**
	 * If the atomic DELETE affects no rows (already consumed by a concurrent
	 * caller, or expired), this caller loses the race → 403, no self-config.
	 */
	public function test_atomic_delete_branch_rejects_when_no_row_deleted(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( $query, ...$args ) => str_replace( '%s', "'" . $args[0] . "'", $query )
		);
		// 0 affected rows → the nonce was already gone; this caller must lose.
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$GLOBALS['wpdb'] = $wpdb;

		$request = new WP_REST_Request( array(
			'nonce'      => 'already-consumed',
			'engine_url' => 'https://example.com',
		) );

		$response = $this->api->handle_connect_exchange( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayNotHasKey( 'seonix_engine_url', $this->updated );
		$this->assertArrayNotHasKey( 'seonix_connected', $this->updated );
	}
}
