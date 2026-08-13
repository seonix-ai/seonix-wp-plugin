<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Revert_Post_Robots_Noindex;
use Seonix_Fix_Revert_Term_Robots_Noindex;
use Seonix_Robots_Noindex;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * revert_post_robots_noindex / revert_term_robots_noindex: the undo twins.
 *
 * Pinned contract:
 *  - the token's snapshot is restored EXACTLY (an absent Yoast post meta is
 *    deleted back to absent; an absent Yoast term override restores as
 *    'default');
 *  - a token that doesn't exist or points at a different target refuses;
 *  - a page the owner already re-indexed by hand is never clobbered — the
 *    revert reports the idempotent applied/no-op with note=already_reverted;
 *  - reverts self-verify that the live page no longer renders the noindex.
 *
 * Yoast-branch only, same reason as SetRobotsNoindexTest (bootstrap fakes pin
 * the detected engine); Rank Math is covered on the wp-meta-matrix stand.
 */
final class RevertRobotsNoindexTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var string HTML the verification fetch returns */
	private string $http_body = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history   = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->meta      = array();
		$this->options   = array();
		$this->http_body = '<html><head><meta name="robots" content="index, follow" /></head></html>';

		\WPSEO_Taxonomy_Meta::reset();
		unset( $GLOBALS['wpdb'] );

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
		Functions\when( 'get_option' )->alias(
			fn ( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
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
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $key, $value, $url ) => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value
		);
		Functions\when( 'wp_remote_get' )->alias(
			fn ( $url, $args = array() ) => array( 'body' => $this->http_body, 'code' => 200 )
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

	private function post_method(): Seonix_Fix_Revert_Post_Robots_Noindex {
		return new Seonix_Fix_Revert_Post_Robots_Noindex( $this->history );
	}

	private function term_method(): Seonix_Fix_Revert_Term_Robots_Noindex {
		return new Seonix_Fix_Revert_Term_Robots_Noindex( $this->history );
	}

	private function seed_post_snapshot( string $token, $meta_value, bool $noindexed = false ): void {
		$this->options[ Seonix_Robots_Noindex::SNAPSHOT_OPTION ][ $token ] = array(
			'kind'       => 'post',
			'post_id'    => 7,
			'engine'     => 'yoast',
			'noindexed'  => $noindexed,
			'meta_value' => $meta_value,
		);
	}

	private function seed_term_snapshot( string $token, $meta_value, bool $noindexed = false ): void {
		$this->options[ Seonix_Robots_Noindex::SNAPSHOT_OPTION ][ $token ] = array(
			'kind'       => 'term',
			'term_id'    => 5,
			'taxonomy'   => 'category',
			'engine'     => 'yoast',
			'noindexed'  => $noindexed,
			'meta_value' => $meta_value,
		);
	}

	// ─── Keys + guards ───────────────────────────────────────────────────

	public function test_keys_match_the_backend_contract_exactly(): void {
		$this->assertSame( 'revert_post_robots_noindex', $this->post_method()->key() );
		$this->assertSame( 'revert_term_robots_noindex', $this->term_method()->key() );
	}

	public function test_unknown_token_is_a_404(): void {
		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-nope' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'unknown_revert_token', $r->get_error_code() );
	}

	public function test_token_for_a_different_target_refuses(): void {
		$this->seed_post_snapshot( 'tok-1', '' );

		$r = $this->post_method()->apply( array( 'post_id' => 8, 'revert_token' => 'tok-1' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'revert_token_mismatch', $r->get_error_code() );

		// A post snapshot can't drive the term revert either.
		$r = $this->term_method()->apply( array( 'term_id' => 5, 'taxonomy' => 'category', 'revert_token' => 'tok-1' ) );
		$this->assertSame( 'revert_token_mismatch', $r->get_error_code() );
	}

	public function test_engine_change_since_apply_refuses(): void {
		$this->seed_post_snapshot( 'tok-1', array( 'noindex' ) );
		$this->options[ Seonix_Robots_Noindex::SNAPSHOT_OPTION ]['tok-1']['engine'] = 'rankmath';

		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'engine_changed', $r->get_error_code() );
	}

	// ─── Post revert ─────────────────────────────────────────────────────

	public function test_post_revert_restores_absent_meta_exactly_and_verifies(): void {
		$this->seed_post_snapshot( 'tok-1', '' ); // pre-fix: no robots override at all
		$this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] = '1';

		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertIsArray( $r );
		// '' snapshots restore by DELETING the key, not by writing ''.
		$this->assertArrayNotHasKey( Seonix_Robots_Noindex::YOAST_POST_META, $this->meta[7] ?? array() );
		$this->assertTrue( $r['applied'] );
		$this->assertTrue( $r['verified'] ); // fetch body renders index,follow
		$this->assertFalse( $r['no_op'] );
		$this->assertFalse( $r['after']['noindexed'] );
		$this->assertTrue( $r['before']['noindexed'] );
	}

	public function test_post_revert_restores_explicit_index_value(): void {
		$this->seed_post_snapshot( 'tok-1', '2' ); // pre-fix: owner had EXPLICIT index
		$this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] = '1';

		$this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertSame( '2', $this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] );
	}

	public function test_post_revert_never_clobbers_a_manual_reindex(): void {
		$this->seed_post_snapshot( 'tok-1', '' );
		// Owner already removed the noindex by hand; the page is live-indexable.
		$this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] = '';

		$r = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertIsArray( $r );
		$this->assertTrue( $r['no_op'] );
		$this->assertTrue( $r['applied'] );
		$this->assertSame( 'already_reverted', $r['note'] );
		$this->assertSame( '', $this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] ); // untouched
	}

	public function test_post_revert_retry_after_success_is_idempotent(): void {
		$this->seed_post_snapshot( 'tok-1', '' );
		$this->meta[7][ Seonix_Robots_Noindex::YOAST_POST_META ] = '1';

		$first  = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );
		$second = $this->post_method()->apply( array( 'post_id' => 7, 'revert_token' => 'tok-1' ) );

		$this->assertFalse( $first['no_op'] );
		$this->assertTrue( $second['no_op'] ); // snapshot survives; retry lands on the no-op path
		$this->assertSame( 'already_reverted', $second['note'] );
	}

	public function test_history_rollback_of_a_revert_is_not_supported(): void {
		$r = $this->post_method()->rollback( 42 );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'rollback_not_supported', $r->get_error_code() );
	}

	// ─── Term revert ─────────────────────────────────────────────────────

	public function test_term_revert_restores_default_via_public_yoast_api(): void {
		$this->seed_term_snapshot( 'tok-t1', '' ); // pre-fix: no override
		\WPSEO_Taxonomy_Meta::$store['category'][5]['wpseo_noindex'] = 'noindex';

		$r = $this->term_method()->apply( array( 'term_id' => 5, 'taxonomy' => 'category', 'revert_token' => 'tok-t1' ) );

		$this->assertIsArray( $r );
		// An empty snapshot restores as 'default' — Yoast's validator strips
		// default-valued keys, i.e. "remove our override".
		$this->assertSame( 'default', \WPSEO_Taxonomy_Meta::$store['category'][5]['wpseo_noindex'] );
		$this->assertTrue( $r['applied'] );
		$this->assertFalse( $r['after']['noindexed'] );
	}

	public function test_term_revert_restores_prior_explicit_noindex(): void {
		// Pre-fix the owner had ALREADY noindexed the archive themselves; the
		// apply was a no-op and revert restores that same noindexed state.
		$this->seed_term_snapshot( 'tok-t1', 'noindex', true );
		\WPSEO_Taxonomy_Meta::$store['category'][5]['wpseo_noindex'] = 'noindex';
		$this->http_body = '<html><head><meta name="robots" content="noindex, follow" /></head></html>';

		$r = $this->term_method()->apply( array( 'term_id' => 5, 'taxonomy' => 'category', 'revert_token' => 'tok-t1' ) );

		$this->assertSame( 'noindex', \WPSEO_Taxonomy_Meta::$store['category'][5]['wpseo_noindex'] );
		$this->assertTrue( $r['applied'] );
		$this->assertTrue( $r['after']['noindexed'] );
		// Verification expectation follows the restored state: noindex SHOULD render.
		$this->assertTrue( $r['verified'] );
	}

	public function test_term_revert_validates_target_like_the_set_method(): void {
		$this->seed_term_snapshot( 'tok-t1', '' );

		$r = $this->term_method()->apply( array( 'term_id' => 5, 'taxonomy' => 'Category!', 'revert_token' => 'tok-t1' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'revert_token_mismatch', $r->get_error_code() );
	}
}
