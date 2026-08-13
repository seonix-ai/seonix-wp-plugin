<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Set_Post_Robots_Noindex;
use Seonix_Fix_Set_Term_Robots_Noindex;
use Seonix_Robots_Noindex;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * set_post_robots_noindex / set_term_robots_noindex: the Site Health
 * "hide this stub / duplicate archive from search" one-click fixes.
 *
 * Pinned contract:
 *  - snapshot of the exact previous robots value is stored under the
 *    backend's revert_token BEFORE the write, put-if-absent (a retried apply
 *    must never replace the pre-fix state), FIFO-pruned at the cap;
 *  - apply writes the engine's native storage and self-verifies against the
 *    rendered page (robots meta / X-Robots-Tag), reporting top-level
 *    applied/verified without ever failing the apply on verify problems;
 *  - already-noindexed targets are applied=true no-ops with a note and no
 *    snapshot overwrite;
 *  - single scalar target per request — array IDs are rejected outright.
 *
 * The bootstrap defines the WPSEO fakes process-wide, so
 * Seonix_SEO_Engine::detect() always resolves to 'yoast' here — the Rank Math
 * branch mirrors the same contract and is exercised on the live
 * wp-meta-matrix stand instead (same convention as PostNoindexTest).
 */
final class SetRobotsNoindexTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	/** @var array<int,array<string,mixed>> post_id => meta map */
	private array $meta = array();

	/** @var array<string,mixed> in-memory wp_options */
	private array $options = array();

	/** @var array<int,string|false> canned wp_remote_get bodies, keyed by call order; false = WP_Error */
	private array $http_bodies = array();

	/** @var int */
	private int $http_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history     = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->meta        = array();
		$this->options     = array();
		$this->http_bodies = array();
		$this->http_calls  = 0;

		\WPSEO_Taxonomy_Meta::reset();
		unset( $GLOBALS['wpdb'] ); // Indexable sync degrades to a silent no-op.

		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_post' )->alias(
			static fn ( $id ) => $id > 0 && $id < 900 ? (object) array( 'ID' => (int) $id ) : null
		);
		Functions\when( 'get_post_meta' )->alias(
			fn ( $post_id, $key, $single ) => $this->meta[ $post_id ][ $key ] ?? ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ $post_id ][ $key ] );
				return true;
			}
		);

		// Snapshot store option plumbing.
		Functions\when( 'get_option' )->alias(
			fn ( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);

		// Term plumbing: taxonomy 'category' with term 5 ("news").
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
		Functions\when( 'taxonomy_exists' )->alias( static fn ( $tax ) => 'category' === $tax );
		Functions\when( 'get_term' )->alias(
			static function ( $term_id, $taxonomy = '' ) {
				if ( 5 === (int) $term_id && 'category' === $taxonomy ) {
					return (object) array( 'term_id' => 5, 'taxonomy' => 'category', 'slug' => 'news' );
				}
				return null;
			}
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://example.test/category/news/' );
		Functions\when( 'get_permalink' )->alias( static fn ( $id ) => "https://example.test/?p={$id}" );

		// Verification fetch plumbing.
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $key, $value, $url ) => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) {
				$body = $this->http_bodies[ $this->http_calls ] ?? $this->default_noindex_html();
				++$this->http_calls;
				if ( false === $body ) {
					return new WP_Error( 'http_request_failed', 'connection refused' );
				}
				return array( 'body' => $body, 'code' => 200 );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => is_array( $response ) ? ( $response['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => is_array( $response ) ? ( $response['body'] ?? '' ) : ''
		);
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function default_noindex_html(): string {
		return '<html><head><meta name="robots" content="noindex, follow" /></head><body></body></html>';
	}

	private function indexable_html(): string {
		return '<html><head><meta name="robots" content="index, follow, max-image-preview:large" /></head><body></body></html>';
	}

	private function post_method(): Seonix_Fix_Set_Post_Robots_Noindex {
		return new Seonix_Fix_Set_Post_Robots_Noindex( $this->history );
	}

	private function term_method(): Seonix_Fix_Set_Term_Robots_Noindex {
		return new Seonix_Fix_Set_Term_Robots_Noindex( $this->history );
	}

	private function snapshots(): array {
		return $this->options[ Seonix_Robots_Noindex::SNAPSHOT_OPTION ] ?? array();
	}

	// ─── Keys, availability, validation ─────────────────────────────────

	public function test_keys_match_the_backend_contract_exactly(): void {
		$this->assertSame( 'set_post_robots_noindex', $this->post_method()->key() );
		$this->assertSame( 'set_term_robots_noindex', $this->term_method()->key() );
	}

	public function test_available_while_a_supported_engine_is_active(): void {
		$this->assertTrue( $this->post_method()->is_available() );
		$this->assertTrue( $this->term_method()->is_available() );
	}

	public function test_post_validate_rejects_missing_or_batch_post_id(): void {
		$r = $this->post_method()->validate_params( array( 'revert_token' => 'tok-1' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'missing_post_id', $r->get_error_code() );

		// Single target per request by design — an ID array is not a batch API.
		$r = $this->post_method()->validate_params( array( 'post_id' => array( 1, 2, 3 ), 'revert_token' => 'tok-1' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'missing_post_id', $r->get_error_code() );
	}

	public function test_post_validate_requires_wellformed_revert_token(): void {
		$r = $this->post_method()->validate_params( array( 'post_id' => 7 ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'missing_revert_token', $r->get_error_code() );

		$r = $this->post_method()->validate_params( array( 'post_id' => 7, 'revert_token' => "bad token\n" ) );
		$this->assertSame( 'missing_revert_token', $r->get_error_code() );

		$this->assertTrue( $this->post_method()->validate_params( array( 'post_id' => 7, 'revert_token' => 'sfx_01HZX.9:a-b' ) ) );
	}

	public function test_term_validate_rejects_unsanitary_taxonomy(): void {
		$r = $this->term_method()->validate_params( array(
			'term_id'      => 5,
			'taxonomy'     => 'Category<script>',
			'revert_token' => 'tok-1',
		) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_taxonomy', $r->get_error_code() );
	}

	// ─── dry_run ─────────────────────────────────────────────────────────

	public function test_post_dry_run_is_pure_and_reports_transition(): void {
		$r = $this->post_method()->dry_run( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertFalse( $r['before']['noindexed'] );
		$this->assertTrue( $r['after']['noindexed'] );
		$this->assertTrue( $r['after']['sitemap_excluded'] );
		// Pure: nothing written, nothing snapshotted, nothing fetched.
		$this->assertArrayNotHasKey( 7, $this->meta );
		$this->assertSame( array(), $this->snapshots() );
		$this->assertSame( 0, $this->http_calls );
	}

	// ─── apply: post ─────────────────────────────────────────────────────

	public function test_post_apply_writes_yoast_meta_snapshots_and_verifies(): void {
		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertIsArray( $r );
		$this->assertSame( '1', $this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] );

		// Contract fields, top-level on the method result.
		$this->assertTrue( $r['applied'] );
		$this->assertTrue( $r['verified'] );
		$this->assertFalse( $r['no_op'] );
		// Mirrored into after so a history replay still serves them.
		$this->assertTrue( $r['after']['applied'] );
		$this->assertTrue( $r['after']['verified'] );

		// Snapshot captured the pre-fix state under the token.
		$snap = $this->snapshots()['tok-1'];
		$this->assertSame( 'post', $snap['kind'] );
		$this->assertSame( 7, $snap['post_id'] );
		$this->assertSame( 'yoast', $snap['engine'] );
		$this->assertFalse( $snap['noindexed'] );
		$this->assertSame( '', $snap['meta_value'] );
	}

	public function test_post_apply_reports_unverified_when_page_still_renders_index(): void {
		$this->http_bodies = array( $this->indexable_html() ); // e.g. a page cache still serving old HTML

		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertTrue( $r['applied'] );
		$this->assertFalse( $r['verified'] );
		$this->assertStringContainsString( 'page cache', $r['verify_details'] );
		// The write itself still landed.
		$this->assertSame( '1', $this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] );
	}

	public function test_post_apply_reports_unverified_when_fetch_fails(): void {
		$this->http_bodies = array( false ); // WP_Error from wp_remote_get

		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertTrue( $r['applied'] );
		$this->assertFalse( $r['verified'] );
		$this->assertStringContainsString( 'fetch failed', $r['verify_details'] );
	}

	public function test_post_apply_already_noindexed_is_noted_noop_without_snapshot_overwrite(): void {
		$this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] = '1';
		// A snapshot from the FIRST apply of this token already exists.
		$this->options[ Seonix_Robots_Noindex::SNAPSHOT_OPTION ] = array(
			'tok-1' => array( 'kind' => 'post', 'post_id' => 7, 'engine' => 'yoast', 'noindexed' => false, 'meta_value' => '' ),
		);

		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertTrue( $r['no_op'] );
		$this->assertTrue( $r['applied'] );
		$this->assertSame( 'already_noindexed', $r['note'] );
		// The retried apply must NOT replace the true pre-fix snapshot.
		$this->assertSame( '', $this->snapshots()['tok-1']['meta_value'] );
		$this->assertFalse( $this->snapshots()['tok-1']['noindexed'] );
	}

	public function test_post_apply_missing_post_errors(): void {
		$r = $this->post_method()->apply( array( 'post_id' => 999, 'revert_token' => 'tok-1' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'post_not_found', $r->get_error_code() );
		$this->assertSame( array(), $this->snapshots() );
	}

	// ─── apply: term ─────────────────────────────────────────────────────

	public function test_term_apply_writes_yoast_taxonomy_meta_snapshots_and_verifies(): void {
		$r = $this->term_method()->apply( array(
			'term_id'      => 5,
			'taxonomy'     => 'category',
			'revert_token' => 'tok-t1',
		) );

		$this->assertIsArray( $r );
		// Written through the public Yoast API, prefixed storage key.
		$this->assertSame( 'noindex', \WPSEO_Taxonomy_Meta::$store['category'][5]['wpseo_noindex'] );

		$this->assertTrue( $r['applied'] );
		$this->assertTrue( $r['verified'] );
		$this->assertTrue( $r['after']['sitemap_excluded'] );
		$this->assertSame( array( 'type' => 'term', 'id' => 5, 'taxonomy' => 'category' ), $r['target'] );

		$snap = $this->snapshots()['tok-t1'];
		$this->assertSame( 'term', $snap['kind'] );
		$this->assertSame( 5, $snap['term_id'] );
		$this->assertSame( 'category', $snap['taxonomy'] );
		$this->assertSame( '', $snap['meta_value'] ); // no prior override
	}

	public function test_term_apply_unknown_taxonomy_and_foreign_term_error(): void {
		$r = $this->term_method()->apply( array(
			'term_id'      => 5,
			'taxonomy'     => 'products',
			'revert_token' => 'tok-t1',
		) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'unknown_taxonomy', $r->get_error_code() );

		$r = $this->term_method()->apply( array(
			'term_id'      => 44, // not in 'category'
			'taxonomy'     => 'category',
			'revert_token' => 'tok-t1',
		) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'term_not_found', $r->get_error_code() );
		$this->assertSame( array(), $this->snapshots() );
	}

	public function test_term_apply_already_noindexed_is_noted_noop(): void {
		\WPSEO_Taxonomy_Meta::$store['category'][5]['wpseo_noindex'] = 'noindex';
		$writes_before = \WPSEO_Taxonomy_Meta::$set_calls;

		$r = $this->term_method()->apply( array(
			'term_id'      => 5,
			'taxonomy'     => 'category',
			'revert_token' => 'tok-t1',
		) );

		$this->assertTrue( $r['no_op'] );
		$this->assertTrue( $r['applied'] );
		$this->assertSame( 'already_noindexed', $r['note'] );
		$this->assertSame( $writes_before, \WPSEO_Taxonomy_Meta::$set_calls ); // no write on the no-op path
		// Snapshot still recorded (pre-apply state WAS noindexed) so the token stays revertible.
		$this->assertTrue( $this->snapshots()['tok-t1']['noindexed'] );
	}

	// ─── Snapshot store bounds ───────────────────────────────────────────

	public function test_snapshot_store_prunes_oldest_beyond_cap(): void {
		$store = array();
		for ( $i = 1; $i <= Seonix_Robots_Noindex::SNAPSHOT_CAP; $i++ ) {
			$store[ "tok-{$i}" ] = array( 'kind' => 'post', 'post_id' => $i );
		}
		$this->options[ Seonix_Robots_Noindex::SNAPSHOT_OPTION ] = $store;

		Seonix_Robots_Noindex::snapshot_put_if_absent( 'tok-new', array( 'kind' => 'post', 'post_id' => 777 ) );

		$after = $this->snapshots();
		$this->assertCount( Seonix_Robots_Noindex::SNAPSHOT_CAP, $after );
		$this->assertArrayNotHasKey( 'tok-1', $after );  // oldest pruned
		$this->assertArrayHasKey( 'tok-new', $after );   // newest kept
		$this->assertArrayHasKey( 'tok-2', $after );
	}

	public function test_snapshot_put_if_absent_never_overwrites(): void {
		Seonix_Robots_Noindex::snapshot_put_if_absent( 'tok-x', array( 'kind' => 'post', 'post_id' => 1, 'meta_value' => '' ) );
		Seonix_Robots_Noindex::snapshot_put_if_absent( 'tok-x', array( 'kind' => 'post', 'post_id' => 1, 'meta_value' => '1' ) );

		$this->assertSame( '', $this->snapshots()['tok-x']['meta_value'] );
	}

	// ─── Robots detection ────────────────────────────────────────────────

	public function test_noindex_detection_handles_attribute_order_and_header(): void {
		$this->assertTrue( Seonix_Robots_Noindex::noindex_present(
			'<meta content="noindex, follow" name="robots">',
			array()
		) );
		// WP core's wp_robots() (which Yoast delegates to) emits SINGLE quotes.
		$this->assertTrue( Seonix_Robots_Noindex::noindex_present(
			"<meta name='robots' content='noindex, follow' />",
			array()
		) );
		$this->assertFalse( Seonix_Robots_Noindex::noindex_present(
			'<meta name="robots" content="index, follow"><meta name="description" content="noindex is discussed here">',
			array()
		) );
		// X-Robots-Tag header alone is enough.
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'noindex, nofollow' );
		$this->assertTrue( Seonix_Robots_Noindex::noindex_present( '<html></html>', array() ) );
	}
}
