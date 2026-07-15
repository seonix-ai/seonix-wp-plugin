<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Content_Score;
use WP_Error;

/**
 * Covers the server-side hop behind the editor panel's live score:
 * Seonix_Content_Score::score() → POST {engine}/api/plugin/score-content.
 *
 * Why this class exists at all is the thing worth protecting: the engine call
 * needs the site's sx_ Bearer key, and the panel runs in the author's browser.
 * Scoring straight from JS would hand that key to every logged-in author (and
 * to anything running in their browser), so the request is made here, from PHP.
 * The header assertions below are what keep that property from regressing.
 *
 * The engine wraps success payloads as {"data": {...}} via pkg/response.JSON —
 * unlike /api/plugin/tasks, which emits its TaskView verbatim. Half of these
 * tests exist because that asymmetry is easy to get wrong in both directions.
 *
 * SSRF guard: Seonix_Auth::is_safe_url() resolves DNS for real, so (as in
 * ConnectExchangeTest) the happy path uses example.com — a public, always-
 * resolving host — rather than a mock.
 */
final class ContentScoreTest extends TestCase {

	/** Captured wp_remote_post() calls: [ url, args ]. */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->requests = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'trailingslashit' )->alias(
			static fn ( $value ) => rtrim( (string) $value, '/\\' ) . '/'
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $value ) => strip_tags( (string) $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $engineUrl Engine origin option value.
	 * @param string $apiKey    seonix_api_key option value.
	 */
	private function stubOptions( string $engineUrl = 'https://example.com', string $apiKey = 'sx_test_secret' ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = '' ) use ( $engineUrl, $apiKey ) {
				if ( 'seonix_engine_url' === $name ) {
					return $engineUrl;
				}
				if ( 'seonix_api_key' === $name ) {
					return $apiKey;
				}
				return $default;
			}
		);
	}

	/**
	 * Capture outbound calls and reply with a canned HTTP response.
	 *
	 * @param int    $status HTTP status to return.
	 * @param string $body   Response body.
	 */
	private function stubEngine( int $status = 200, string $body = '' ): void {
		if ( '' === $body ) {
			$body = wp_json_encode(
				array(
					'data' => array(
						'seo_score'          => 72,
						'readability_score'  => 85,
						'seo_checks'         => array(
							array( 'id' => 'keyphraseInTitle', 'label' => 'Keyphrase in title', 'message' => 'Not found.', 'severity' => 'error', 'status' => 'error', 'weight' => 12 ),
						),
						'readability_checks' => array(
							array( 'id' => 'sentenceLength', 'label' => 'Sentence length', 'message' => 'Fine.', 'severity' => 'good', 'status' => 'good', 'weight' => 20 ),
						),
						'summary'            => array( 'good_count' => 1, 'warning_count' => 0, 'error_count' => 1, 'top_suggestion' => 'Add the keyphrase to the title.' ),
					),
				)
			);
		}

		$captured =& $this->requests;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured, $status, $body ) {
				$captured[] = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => $status ), 'body' => $body );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => $response['response']['code']
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => $response['body']
		);
	}

	// ─── The security property this class exists for ──────────────

	public function test_sends_the_api_key_to_the_engine_as_a_bearer_token(): void {
		$this->stubOptions();
		$this->stubEngine();

		Seonix_Content_Score::score( array( 'html_content' => '<p>Some prose to score.</p>' ) );

		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			'https://example.com/api/plugin/score-content',
			$this->requests[0]['url']
		);
		$this->assertSame(
			'Bearer sx_test_secret',
			$this->requests[0]['args']['headers']['Authorization']
		);
	}

	public function test_posts_the_editor_fields_the_engine_scores_on(): void {
		$this->stubOptions();
		$this->stubEngine();

		Seonix_Content_Score::score(
			array(
				'html_content'     => '<p>Body copy.</p>',
				'focus_keyphrase'  => '  standing desk  ',
				'title'            => 'Best standing desks',
				'meta_description' => 'A guide.',
				'slug'             => 'best-standing-desks',
				'language'         => 'de_DE',
			)
		);

		$sent = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( '<p>Body copy.</p>', $sent['html_content'] );
		// Trimmed: a stray space would otherwise be scored as part of the phrase.
		$this->assertSame( 'standing desk', $sent['focus_keyphrase'] );
		$this->assertSame( 'Best standing desks', $sent['title'] );
		$this->assertSame( 'A guide.', $sent['meta_description'] );
		$this->assertSame( 'best-standing-desks', $sent['slug'] );
		// Passed through as the site locale; the engine normalizes de_DE → de.
		$this->assertSame( 'de_DE', $sent['language'] );
	}

	// ─── Response shape ───────────────────────────────────────────

	public function test_unwraps_the_data_envelope(): void {
		$this->stubOptions();
		$this->stubEngine();

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Some prose.</p>' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 72, $result['seo_score'] );
		$this->assertSame( 85, $result['readability_score'] );
		$this->assertCount( 1, $result['seo_checks'] );
		$this->assertSame( 'keyphraseInTitle', $result['seo_checks'][0]['id'] );
		$this->assertSame( 'error', $result['seo_checks'][0]['severity'] );
		$this->assertCount( 1, $result['readability_checks'] );
		$this->assertSame( 'Add the keyphrase to the title.', $result['summary']['top_suggestion'] );
	}

	public function test_accepts_an_unenveloped_payload_too(): void {
		// Defensive: if the engine ever emits this route verbatim (as
		// /api/plugin/tasks already does), the panel must not go blank.
		$this->stubOptions();
		$this->stubEngine( 200, wp_json_encode( array( 'seo_score' => 40, 'readability_score' => 50 ) ) );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertSame( 40, $result['seo_score'] );
		$this->assertSame( 50, $result['readability_score'] );
	}

	public function test_forwards_the_scalar_details_a_message_interpolates(): void {
		// The engine's `message` is a template — "{count} words — too short" —
		// with its numbers in `details`. Drop details and the panel prints the
		// braces to the author.
		$this->stubOptions();
		$this->stubEngine( 200, wp_json_encode( array(
			'data' => array(
				'seo_score'          => 50,
				'readability_checks' => array(
					array(
						'id'       => 'textLength',
						'message'  => '{count} words — too short. Aim for at least 1000.',
						'severity' => 'error',
						'details'  => array( 'count' => 148 ),
					),
				),
			),
		) ) );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertSame( 148, $result['readability_checks'][0]['details']['count'] );
	}

	public function test_drops_non_scalar_details(): void {
		// keywordNotUsed attaches a list of conflicting articles for the
		// dashboard to link. Nothing in the panel renders it, it can't be
		// substituted into a string, and it has no business in the browser.
		$this->stubOptions();
		$this->stubEngine( 200, wp_json_encode( array(
			'data' => array(
				'seo_score'  => 50,
				'seo_checks' => array(
					array(
						'id'      => 'keywordNotUsed',
						'message' => 'Used in {count} other articles.',
						'details' => array(
							'count'     => 2,
							'conflicts' => array(
								array( 'id' => 'a1b2', 'title' => 'Another article' ),
							),
						),
					),
				),
			),
		) ) );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$details = $result['seo_checks'][0]['details'];
		$this->assertSame( array( 'count' => 2 ), $details );
		$this->assertArrayNotHasKey( 'conflicts', $details );
	}

	public function test_unknown_severity_never_lands_in_problems(): void {
		// Mirrors the engine's own statusToSeverity: anything unrecognized
		// degrades to "good" rather than reddening the SEO eye on a guess.
		$this->stubOptions();
		$this->stubEngine( 200, wp_json_encode( array(
			'data' => array(
				'seo_score'  => 50,
				'seo_checks' => array(
					array( 'id' => 'a', 'severity' => 'catastrophe' ),
					array( 'id' => 'b', 'status' => 'warning' ),
					array( 'id' => 'c' ),
				),
			),
		) ) );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertSame( 'good', $result['seo_checks'][0]['severity'] );
		// Falls back to `status` when `severity` is absent.
		$this->assertSame( 'warning', $result['seo_checks'][1]['severity'] );
		$this->assertSame( 'good', $result['seo_checks'][2]['severity'] );
	}

	// ─── Guards ───────────────────────────────────────────────────

	public function test_requires_a_connected_site(): void {
		$this->stubOptions( '' );
		$this->stubEngine();

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_connected', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_requires_an_api_key(): void {
		$this->stubOptions( 'https://example.com', '' );
		$this->stubEngine();

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_api_key', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_rejects_an_engine_url_that_fails_the_ssrf_guard(): void {
		// A configured engine URL pointing at the loopback interface must not
		// become an outbound request carrying the site's key.
		$this->stubOptions( 'http://localhost:8080' );
		$this->stubEngine();

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_engine_url', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_does_not_call_the_engine_for_empty_content(): void {
		// An empty paragraph block is a non-empty STRING; the engine would
		// reject it as empty anyway, so don't spend the round trip.
		$this->stubOptions();
		$this->stubEngine();

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p></p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_rejects_content_over_the_engine_cap_locally(): void {
		$this->stubOptions();
		$this->stubEngine();

		$huge = '<p>' . str_repeat( 'a', Seonix_Content_Score::MAX_CONTENT_BYTES ) . '</p>';
		$result = Seonix_Content_Score::score( array( 'html_content' => $huge ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_too_large', $result->get_error_code() );
		// Rejected here rather than burning a round trip to be 400'd there.
		$this->assertCount( 0, $this->requests );
	}

	public function test_surfaces_an_engine_http_error(): void {
		$this->stubOptions();
		$this->stubEngine( 500, '{"error":{"code":"INTERNAL"}}' );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'score_failed', $result->get_error_code() );
	}

	public function test_rejects_an_unreadable_payload(): void {
		$this->stubOptions();
		$this->stubEngine( 200, 'not json at all' );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bad_payload', $result->get_error_code() );
	}

	public function test_rejects_a_json_payload_without_a_score(): void {
		// 200 + valid JSON is not the same as "this is a score".
		$this->stubOptions();
		$this->stubEngine( 200, wp_json_encode( array( 'data' => array( 'unrelated' => true ) ) ) );

		$result = Seonix_Content_Score::score( array( 'html_content' => '<p>Prose.</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bad_payload', $result->get_error_code() );
	}
}
