<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\TransientStub;
use Seonix_Meta_Bridge;
use Seonix_REST_API;
use WP_Error;
use WP_REST_Request;

/**
 * Covers POST seonix/v1/score — the editor panel's own route.
 *
 * This route runs the opposite way round from every other route in the plugin:
 * the others are called BY the backend and authenticate with the site's API
 * key, this one is called by the logged-in author's browser and authenticates
 * as WordPress does. Hence the permission tests: an author who cannot edit the
 * post must not be able to spend the site's engine quota scoring it.
 *
 * The fallback tests protect a subtler failure. The editor only knows the
 * keyphrase and description that live in ITS stores; when it sends none, the
 * post may still have them saved. Scoring without them would report a red "no
 * meta description" (weight 10) on a post that has one — the panel telling the
 * author their own work is missing something it is looking at.
 */
final class ScoreRouteTest extends TestCase {

	private Seonix_REST_API $api;

	/** Payload handed to Seonix_Content_Score::score(), captured off the wire. */
	private array $sent = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sent = array();
		$this->api  = new Seonix_REST_API();

		Functions\when( '__' )->returnArg();
		Functions\when( 'trailingslashit' )->alias(
			static fn ( $value ) => rtrim( (string) $value, '/\\' ) . '/'
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $value ) => strip_tags( (string) $value )
		);
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		// The route reads the post type off the server so the engine can tell a
		// page from an article. Tests that don't care about it still hit it.
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		// The score route caches its result in post meta; a no-op is enough here.
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		TransientStub::$store = array();
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $value ) => strtolower( str_replace( ' ', '-', trim( (string) $value ) ) )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) {
				if ( 'seonix_engine_url' === $name ) {
					return 'https://example.com';
				}
				if ( 'seonix_api_key' === $name ) {
					return 'sx_test_secret';
				}
				return $default;
			}
		);

		$captured =& $this->sent;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'data' => array( 'seo_score' => 70, 'readability_score' => 80 ) ) ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => $response['response']['code']
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => $response['body']
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $meta Saved postmeta, keyed by meta key.
	 */
	private function stubMeta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single = false ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);
	}

	// ─── Permission ───────────────────────────────────────────────

	public function test_denies_a_user_who_cannot_edit_the_post(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = new WP_REST_Request( array( 'post_id' => 42 ) );

		$this->assertFalse( $this->api->score_permission( $request ) );
	}

	public function test_checks_edit_post_against_the_post_being_scored(): void {
		$seen = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap, $id = null ) use ( &$seen ) {
				$seen[] = array( $cap, $id );
				return true;
			}
		);

		$request = new WP_REST_Request( array( 'post_id' => 42 ) );

		$this->assertTrue( $this->api->score_permission( $request ) );
		$this->assertSame( array( array( 'edit_post', 42 ) ), $seen );
	}

	public function test_falls_back_to_edit_posts_for_an_unsaved_draft(): void {
		// A brand-new draft has no id yet, so there is nothing to check
		// edit_post against — the generic authoring capability is the gate.
		$seen = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap, $id = null ) use ( &$seen ) {
				$seen[] = array( $cap, $id );
				return true;
			}
		);

		$request = new WP_REST_Request( array( 'post_id' => 0 ) );

		$this->assertTrue( $this->api->score_permission( $request ) );
		$this->assertSame( array( array( 'edit_posts', null ) ), $seen );
	}

	// ─── Rate limiting ────────────────────────────────────────────

	/**
	 * Drives handle_score() without letting it reach the network.
	 *
	 * Disconnecting the site makes every call bail at `not_connected` — which
	 * happens AFTER the rate-limit check, so the limiter still ticks. That is
	 * the point: it proves the limiter runs before any expensive work, and it
	 * keeps these tests off the real DNS resolver that the SSRF guard uses
	 * (30 sequential lookups per test turned the suite from 0.4s into 34s).
	 *
	 * @return WP_Error|mixed The handler's return value.
	 */
	private function callScoreOffline() {
		Functions\when( 'get_option' )->justReturn( '' );
		return $this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 42,
			'html_content' => '<p>Body copy.</p>',
			'slug'         => 'x',
		) ) );
	}

	/**
	 * @return string Error code of an offline /score call, '' when it succeeded.
	 */
	private function scoreErrorCode(): string {
		$result = $this->callScoreOffline();
		return $result instanceof WP_Error ? $result->get_error_code() : '';
	}

	public function test_rate_limits_a_single_user(): void {
		// /score makes a blocking outbound call per request, so one account in
		// a loop can pin PHP workers and eat the site's shared backend budget.
		$this->stubMeta( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		TransientStub::$store = array();

		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame(
				'not_connected',
				$this->scoreErrorCode(),
				"call #$i tripped the limiter but is within the allowance"
			);
		}

		$blocked = $this->callScoreOffline();
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'rate_limited', $blocked->get_error_code() );
		// 429, not a generic 500: the client must be able to tell "slow down"
		// apart from "something broke".
		$this->assertSame( 429, $blocked->get_error_data()['status'] );
	}

	public function test_rate_limit_buckets_are_per_user(): void {
		// The inherited check_rate_limit() keys off the API-key header, which a
		// cookie-authenticated browser call never sends — every author would
		// hash to the same empty-string bucket and throttle each other.
		$this->stubMeta( array() );
		TransientStub::$store = array();

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		for ( $i = 0; $i < 30; $i++ ) {
			$this->callScoreOffline();
		}
		$this->assertSame( 'rate_limited', $this->scoreErrorCode() );

		// A different author must still be able to work.
		Functions\when( 'get_current_user_id' )->justReturn( 8 );
		$this->assertSame( 'not_connected', $this->scoreErrorCode() );
	}

	// ─── Content type ─────────────────────────────────────────────

	/**
	 * The engine scores a page differently from an article, and it can only do
	 * that if the route tells it which one this is.
	 *
	 * Read server-side from the post rather than taken from the editor: the post
	 * type is WordPress's own fact, and a payload field the editor controls is a
	 * field the editor can get wrong.
	 */
	public function test_sends_the_post_type_so_pages_are_scored_as_pages(): void {
		$this->stubMeta( array() );
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 42,
			'html_content' => '<p>Unsere Leistungen.</p>',
			'slug'         => 'leistungen',
		) ) );

		$this->assertSame( 'page', $this->sent['content_type'] );
	}

	public function test_sends_post_for_an_article(): void {
		$this->stubMeta( array() );
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 42,
			'html_content' => '<p>Body copy.</p>',
			'slug'         => 'x',
		) ) );

		$this->assertSame( 'post', $this->sent['content_type'] );
	}

	/** An unsaved draft has no post yet — the engine falls back to article rules. */
	public function test_sends_no_type_for_an_unsaved_draft(): void {
		$this->stubMeta( array() );
		$this->api->handle_score( new WP_REST_Request( array(
			'html_content' => '<p>Draft.</p>',
			'title'        => 'Draft',
		) ) );

		$this->assertSame( '', $this->sent['content_type'] );
	}

	// ─── Caching ──────────────────────────────────────────────────

	/**
	 * A saved post scored once, then re-opened unchanged, must not round-trip to
	 * the backend again — the whole point of the cache. An edit changes the hash,
	 * so the next score is a real request.
	 */
	public function test_unchanged_repeat_is_served_from_cache_without_the_backend(): void {
		// A real in-memory post-meta store, so the cache genuinely round-trips
		// through storage (write on the miss, read on the repeat).
		$meta = array();
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single = false ) use ( &$meta ) {
				return $meta[ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
				return true;
			}
		);

		$calls = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$calls ) {
				$calls++;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'data' => array( 'seo_score' => 70, 'readability_score' => 80 ) ) ),
				);
			}
		);

		$args = array(
			'post_id'      => 42,
			'html_content' => '<p>Body copy about desks.</p>',
			'title'        => 'Desks',
			'slug'         => 'desks',
		);

		// First score: cache miss → backend called once → result cached to meta.
		$this->api->handle_score( new WP_REST_Request( $args ) );
		$this->assertSame( 1, $calls, 'the first score must hit the backend' );
		$this->assertArrayHasKey( '_seonix_score_cache', $meta, 'the result must be cached' );

		// Same content again: cache hit → backend NOT called a second time.
		$this->api->handle_score( new WP_REST_Request( $args ) );
		$this->assertSame( 1, $calls, 'an unchanged repeat must be served from cache, not the backend' );

		// Change the text: hash changes → cache miss → backend called again.
		$args['html_content'] = '<p>Body copy about desks, now edited.</p>';
		$this->api->handle_score( new WP_REST_Request( $args ) );
		$this->assertSame( 2, $calls, 'an edit must re-score against the backend' );
	}

	// ─── Fallbacks ────────────────────────────────────────────────

	public function test_falls_back_to_the_saved_keyphrase_and_description(): void {
		$this->stubMeta( array(
			Seonix_Meta_Bridge::META_FOCUS_KW => 'standing desk',
			Seonix_Meta_Bridge::META_DESC     => 'A saved description.',
		) );

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 42,
			'html_content' => '<p>Body copy about desks.</p>',
			'title'        => 'Desks',
			'slug'         => 'desks',
		) ) );

		$this->assertSame( 'standing desk', $this->sent['focus_keyphrase'] );
		$this->assertSame( 'A saved description.', $this->sent['meta_description'] );
	}

	public function test_unsaved_editor_values_win_over_the_saved_ones(): void {
		// The author just typed a new keyphrase; scoring the stale saved one
		// would grade work they can see they've already changed.
		$this->stubMeta( array(
			Seonix_Meta_Bridge::META_FOCUS_KW => 'stale phrase',
			Seonix_Meta_Bridge::META_DESC     => 'Stale description.',
		) );

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'          => 42,
			'html_content'     => '<p>Body copy.</p>',
			'focus_keyphrase'  => 'fresh phrase',
			'meta_description' => 'Fresh description.',
			'slug'             => 'desks',
		) ) );

		$this->assertSame( 'fresh phrase', $this->sent['focus_keyphrase'] );
		$this->assertSame( 'Fresh description.', $this->sent['meta_description'] );
	}

	public function test_derives_the_slug_from_the_title_for_an_unsaved_draft(): void {
		// The editor holds no slug until the first save, but the permalink
		// WordPress shows is derived from the title — score that, not "".
		$this->stubMeta( array() );

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 0,
			'html_content' => '<p>Body copy.</p>',
			'title'        => 'Best Standing Desks',
		) ) );

		$this->assertSame( 'best-standing-desks', $this->sent['slug'] );
	}

	public function test_falls_back_to_the_saved_post_name(): void {
		$this->stubMeta( array() );
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'post_name' => 'saved-slug' ) ) );

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 42,
			'html_content' => '<p>Body copy.</p>',
			'title'        => 'A Different Title',
		) ) );

		$this->assertSame( 'saved-slug', $this->sent['slug'] );
	}

	public function test_passes_the_site_locale_through_a_filter(): void {
		// Multilingual sites vary language per post; the engine's language
		// check would otherwise grade every translation against one locale.
		$this->stubMeta( array() );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return 'seonix_score_language' === $tag ? 'uk_UA' : $value;
			}
		);

		$this->api->handle_score( new WP_REST_Request( array(
			'post_id'      => 42,
			'html_content' => '<p>Body copy.</p>',
			'slug'         => 'x',
		) ) );

		$this->assertSame( 'uk_UA', $this->sent['language'] );
	}
}
