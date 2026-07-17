<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Tasks;
use Seonix_REST_API;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers the local task store, Seonix_Tasks, and the POST /tasks sink that
 * feeds it.
 *
 * Contract (locked against the Seonix backend — docs/TASKS_CONTRACT.md):
 * the backend POSTs the canonical TaskView after each scan; the plugin
 * replaces its local copy (truncate-then-insert), stores the summary +
 * categories as a JSON option, and stamps synced_at. Every field is
 * sanitized on store and the vocab enums are clamped.
 */
final class TasksTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $wpdb;

	private Seonix_Tasks $tasks;

	/** Captured update_option() writes, keyed by option name. */
	private array $updated = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->updated         = [];
		$this->wpdb            = Mockery::mock();
		$this->wpdb->prefix    = 'wp_';
		$this->wpdb->insert_id = 0;
		$this->tasks           = new Seonix_Tasks( $this->wpdb );

		$captured =& $this->updated;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured[ $name ] = $value;
				return true;
			}
		);
		// Sanitizers used by upsert_view's accessors.
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : ''
		);
		Functions\when( 'esc_url_raw' )->alias(
			static fn ( $value ) => is_string( $value ) ? filter_var( $value, FILTER_SANITIZE_URL ) : ''
		);
		Functions\when( 'absint' )->alias(
			static fn ( $value ) => abs( (int) $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_table_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_seonix_tasks', $this->tasks->table_name() );
	}

	public function test_upsert_view_truncates_then_inserts_rows_and_stores_summary(): void {
		// Truncate (DELETE) first, then one insert per task.
		$this->wpdb->shouldReceive( 'query' )
			->once()
			->with( Mockery::on( fn ( $sql ) => str_contains( $sql, 'DELETE FROM wp_seonix_tasks' ) ) )
			->andReturn( 1 );

		$captured_rows = [];
		$this->wpdb->shouldReceive( 'insert' )
			->twice()
			->with( 'wp_seonix_tasks', Mockery::on( function ( $row ) use ( &$captured_rows ) {
				$captured_rows[] = $row;
				return true;
			} ), Mockery::type( 'array' ) )
			->andReturn( 1 );

		$view = array(
			'schema_version' => 1,
			'generated_at'   => '2026-05-30T12:00:00Z',
			'project_id'     => 'proj-1',
			'site_url'       => 'https://example.com',
			'summary'        => array( 'open' => 2, 'solved' => 5, 'regressed' => 1, 'active' => 3, 'fixed' => 4, 'came_back' => 1, 'score' => 78 ),
			'categories'     => array(
				array( 'key' => 'seo', 'score' => 80, 'open' => 1 ),
				array( 'key' => 'technical', 'score' => 74, 'open' => 1 ),
				array( 'key' => 'ai', 'score' => 90, 'open' => 0 ),
			),
			'tasks'          => array(
				array(
					'id'             => 'thread-1',
					'code'           => 'broken_internal_link',
					'title'          => 'Broken Internal Links',
					'description'    => 'Some links are broken.',
					'recommendation' => 'Fix or remove them.',
					'severity'       => 'error',
					'priority'       => 'high',
					'category'       => 'technical',
					'status'         => 'open',
					'affected_url'   => 'https://example.com/page',
					'affected_count' => 5,
					'pages'          => array(
						array( 'url' => 'https://example.com/page', 'status' => 'open' ),
						array( 'url' => 'https://example.com/about', 'status' => 'regressed' ),
						array( 'url' => 'https://example.com/old', 'status' => 'solved' ),
						// Junk entries that must be dropped on store.
						'not-an-array',
						array( 'status' => 'open' ), // no url → skipped
						array( 'url' => 'https://example.com/weird', 'status' => 'bogus' ), // status clamped to open
					),
					'first_seen_at'  => '2026-05-01T00:00:00Z',
					'last_seen_at'   => '2026-05-30T00:00:00Z',
					'solved_at'      => null,
					'regression_count' => 0,
					'informational'  => false,
				),
				array(
					'id'             => 'thread-2',
					'code'           => 'llms_txt_missing',
					'title'          => 'llms.txt missing',
					'severity'       => 'notice',
					'priority'       => 'low',
					'category'       => 'ai',
					'status'         => 'open',
					'affected_count' => 1,
					'informational'  => true,
				),
			),
		);

		$result = $this->tasks->upsert_view( $view );
		$this->assertTrue( $result );

		// Rows landed with the right shape.
		$this->assertCount( 2, $captured_rows );
		$this->assertSame( 'thread-1', $captured_rows[0]['task_id'] );
		$this->assertSame( 'broken_internal_link', $captured_rows[0]['code'] );
		$this->assertSame( 'error', $captured_rows[0]['severity'] );
		$this->assertSame( 'high', $captured_rows[0]['priority'] );
		$this->assertSame( 'technical', $captured_rows[0]['category'] );
		$this->assertSame( 5, $captured_rows[0]['affected_count'] );
		$this->assertSame( 0, $captured_rows[0]['informational'] );
		// RFC3339 → MySQL DATETIME (UTC).
		$this->assertSame( '2026-05-01 00:00:00', $captured_rows[0]['first_seen_at'] );
		$this->assertNull( $captured_rows[0]['solved_at'] );
		// Informational task stored as 1.
		$this->assertSame( 1, $captured_rows[1]['informational'] );

		// affected_pages stored as a JSON string of the clean, indexed list:
		// non-arrays and url-less entries dropped, status clamped to the enum.
		$this->assertArrayHasKey( 'affected_pages', $captured_rows[0] );
		$stored_pages = json_decode( $captured_rows[0]['affected_pages'], true );
		$this->assertIsArray( $stored_pages );
		$this->assertCount( 4, $stored_pages, '3 valid + 1 bogus-status (kept, clamped); 2 junk dropped' );
		$this->assertSame( 'https://example.com/page', $stored_pages[0]['url'] );
		$this->assertSame( 'open', $stored_pages[0]['status'] );
		$this->assertSame( 'regressed', $stored_pages[1]['status'] );
		$this->assertSame( 'solved', $stored_pages[2]['status'] );
		// The "bogus" status was clamped back to the safe default.
		$this->assertSame( 'https://example.com/weird', $stored_pages[3]['url'] );
		$this->assertSame( 'open', $stored_pages[3]['status'] );
		// A task with no `pages` key stores an empty JSON array, not null.
		$this->assertSame( '[]', $captured_rows[1]['affected_pages'] );

		// synced_at stamped (unix int).
		$this->assertArrayHasKey( 'seonix_tasks_synced_at', $this->updated );
		$this->assertIsInt( $this->updated['seonix_tasks_synced_at'] );

		// Summary stored as JSON with the categories nested.
		$this->assertArrayHasKey( 'seonix_tasks_summary', $this->updated );
		$summary = json_decode( $this->updated['seonix_tasks_summary'], true );
		$this->assertSame( 2, $summary['open'] );
		$this->assertSame( 78, $summary['score'] );
		// Canonical page-count headlines round-trip so the plugin renders the SAME
		// Active / Fixed / Came back numbers as the app.seonix.ai dashboard.
		$this->assertSame( 3, $summary['active'] );
		$this->assertSame( 4, $summary['fixed'] );
		$this->assertSame( 1, $summary['came_back'] );
		$this->assertCount( 3, $summary['categories'] );
		$this->assertSame( 'seo', $summary['categories'][0]['key'] );
	}

	/**
	 * An older backend that predates the canonical page counts omits
	 * active/fixed/came_back. They must persist as -1 (the "field absent"
	 * sentinel) so the Dashboard falls back to a local task-row count rather
	 * than rendering a bare 0 or -1.
	 */
	public function test_upsert_view_marks_absent_page_counts_as_sentinel(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$view = array(
			'schema_version' => 1,
			'summary'        => array( 'open' => 1, 'solved' => 0, 'regressed' => 0, 'score' => 90 ),
			'categories'     => array(),
			'tasks'          => array(),
		);

		$result = $this->tasks->upsert_view( $view );
		$this->assertTrue( $result );

		$summary = json_decode( $this->updated['seonix_tasks_summary'], true );
		$this->assertSame( -1, $summary['active'], 'absent active → -1 sentinel' );
		$this->assertSame( -1, $summary['fixed'], 'absent fixed → -1 sentinel' );
		$this->assertSame( -1, $summary['came_back'], 'absent came_back → -1 sentinel' );
	}

	public function test_upsert_view_clamps_unknown_vocab_to_safe_defaults(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$captured = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wp_seonix_tasks', Mockery::on( function ( $row ) use ( &$captured ) {
				$captured = $row;
				return true;
			} ), Mockery::type( 'array' ) )
			->andReturn( 1 );

		$view = array(
			'schema_version' => 1,
			'tasks'          => array(
				array(
					'id'             => 'x',
					'code'           => 'whatever',
					'severity'       => 'catastrophic',  // not in enum
					'priority'       => 'urgent',        // not in enum
					'category'       => 'marketing',     // not in enum
					'status'         => 'pending',       // not in enum
					'affected_count' => 0,               // floored to 1
				),
			),
		);

		$this->tasks->upsert_view( $view );

		$this->assertSame( 'notice', $captured['severity'] );
		$this->assertSame( 'low', $captured['priority'] );
		$this->assertSame( 'seo', $captured['category'] );
		$this->assertSame( 'open', $captured['status'] );
		$this->assertSame( 1, $captured['affected_count'] );
	}

	public function test_upsert_view_rejects_newer_schema_version(): void {
		// Newer schema → WP_Error, and NOTHING is written (no DELETE, no insert).
		// Version-relative so the test doesn't go stale when the supported
		// schema version is bumped (it previously hard-coded 2 and broke when
		// SUPPORTED_SCHEMA_VERSION reached 3).
		$this->wpdb->shouldNotReceive( 'query' );
		$this->wpdb->shouldNotReceive( 'insert' );

		$result = $this->tasks->upsert_view( array(
			'schema_version' => Seonix_Tasks::SUPPORTED_SCHEMA_VERSION + 1,
			'tasks'          => array(),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_schema', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'seonix_tasks_summary', $this->updated );
	}

	public function test_upsert_view_handles_empty_task_list(): void {
		// DELETE still runs (clears stale rows), no inserts, summary stored.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$this->wpdb->shouldNotReceive( 'insert' );

		$result = $this->tasks->upsert_view( array(
			'schema_version' => 1,
			'summary'        => array( 'open' => 0, 'solved' => 0, 'regressed' => 0, 'score' => 100 ),
			'categories'     => array(),
			'tasks'          => array(),
		) );

		$this->assertTrue( $result );
		$summary = json_decode( $this->updated['seonix_tasks_summary'], true );
		$this->assertSame( 100, $summary['score'] );
	}

	public function test_decode_pages_returns_safe_list_or_empty(): void {
		// Garbage in → empty array out.
		$this->assertSame( array(), Seonix_Tasks::decode_pages( '' ) );
		$this->assertSame( array(), Seonix_Tasks::decode_pages( null ) );
		$this->assertSame( array(), Seonix_Tasks::decode_pages( 'not json' ) );
		$this->assertSame( array(), Seonix_Tasks::decode_pages( '{"not":"a list"}' ) ); // object with no url entries
		$this->assertSame( array(), Seonix_Tasks::decode_pages( 42 ) );

		// Valid JSON → clean, indexed list; url-less / bad-status entries handled.
		$json = json_encode( array(
			array( 'url' => 'https://x.test/a', 'status' => 'open' ),
			array( 'url' => 'https://x.test/b', 'status' => 'solved' ),
			array( 'url' => 'https://x.test/c', 'status' => 'nope' ),   // clamped → open
			array( 'status' => 'open' ),                                 // no url → dropped
			'junk',                                                      // non-array → dropped
		) );
		$pages = Seonix_Tasks::decode_pages( $json );

		$this->assertCount( 3, $pages );
		$this->assertSame( 'https://x.test/a', $pages[0]['url'] );
		$this->assertSame( 'open', $pages[0]['status'] );
		$this->assertSame( 'solved', $pages[1]['status'] );
		$this->assertSame( 'https://x.test/c', $pages[2]['url'] );
		$this->assertSame( 'open', $pages[2]['status'] );
	}

	public function test_handle_tasks_route_upserts_with_valid_bearer(): void {
		// Wire a REST API whose Tasks dependency is our mock-backed instance.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );

		// The handler's rate-limiter calls get/set_transient — those are stubbed
		// in tests/bootstrap.php (backed by TransientStub::$store). An empty
		// store means get_transient() returns 0/false → "not rate limited",
		// which is exactly what we want here.
		Functions\when( 'rest_ensure_response' )->returnArg();

		$api = new Seonix_REST_API( null, $this->tasks );

		$request = new WP_REST_Request(
			array(
				'schema_version' => 1,
				'summary'        => array( 'open' => 1, 'solved' => 0, 'regressed' => 0, 'score' => 75 ),
				'tasks'          => array(
					array( 'id' => 't', 'code' => 'c', 'severity' => 'warning', 'status' => 'open', 'affected_count' => 1 ),
				),
			),
			array( 'authorization' => 'Bearer sx_abc' )
		);

		$response = $api->handle_tasks( $request );

		$this->assertSame( array( 'success' => true ), $response );
		$this->assertArrayHasKey( 'seonix_tasks_synced_at', $this->updated );
	}
}
