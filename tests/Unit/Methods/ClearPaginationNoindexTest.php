<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use RankMath\Helper as RankMathHelper;
use Seonix\Tests\Fakes\DriftedRankMathHelper;
use Seonix\Tests\Fakes\ExtraParamRankMathHelper;
use Seonix_Admin;
use Seonix_Fix_Clear_Pagination_Noindex;
use Seonix_SEO_Fix_History;
use Seonix_SEO_Fix_Registry;
use WP_Error;

/**
 * Covers the clear_pagination_noindex fix method.
 *
 * The method switches off Rank Math's two paginated-noindex toggles
 * (`rank-math-options-titles` → `noindex_archive_subpages` and
 * `noindex_paginated_pages`) through Rank Math's public settings writer, then
 * purges the page caches. The option store below is an in-memory
 * get_option/update_option pair with WordPress's real semantics, and the fake
 * RankMath\Helper (tests/Fakes/FakeRankMath.php) writes through it the way the
 * real helper does — so every assertion reads the stored option, the value Rank
 * Math's robots presenter reads on the next request.
 */
final class ClearPaginationNoindexTest extends TestCase {

	private const OPTION    = 'rank-math-options-titles';
	private const ARCHIVE   = 'noindex_archive_subpages';
	private const PAGINATED = 'noindex_paginated_pages';

	/** @var array<string,mixed> In-memory option store backing the get/update stubs. */
	private array $options = array();

	/** Full cache purges seen (Seonix_Cache_Purger always flushes the object cache). */
	private int $purges = 0;

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Clear_Pagination_Noindex $method;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$existing = array_key_exists( $key, $this->options ) ? $this->options[ $key ] : null;
				if ( $existing === $value ) {
					return false;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);

		// Seonix_Cache_Purger::purge_all() dispatches to every cache it detects;
		// the object cache is always among them, so its flush counts the purges.
		// WP Rocket's entry point is stubbed because earlier tests may have
		// defined it, which makes the purger call it.
		$this->purges = 0;
		Functions\when( 'wp_cache_flush' )->alias(
			function () {
				++$this->purges;
				return true;
			}
		);
		Functions\when( 'rocket_clean_domain' )->justReturn( null );

		RankMathHelper::reset();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Clear_Pagination_Noindex( $this->history );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Seed Rank Math's titles settings: the given toggles next to unrelated keys
	 * that must survive every write untouched.
	 *
	 * @param array<string,mixed> $toggles Raw toggle values by key.
	 */
	private function seed( array $toggles ): void {
		$this->options[ self::OPTION ] = array_merge(
			array(
				'title_separator'          => '-',
				'pt_post_title'            => '%title% %sep% %sitename%',
				'noindex_empty_taxonomies' => 'on',
			),
			$toggles
		);
	}

	/** The seeded titles array with $toggles replaced — what a write must leave behind. */
	private function titles_with( array $toggles ): array {
		return array_merge( $this->options[ self::OPTION ], $toggles );
	}

	/** The same method on a site where Rank Math is not loaded. */
	private function without_rank_math(): Seonix_Fix_Clear_Pagination_Noindex {
		return new class( $this->history ) extends Seonix_Fix_Clear_Pagination_Noindex {
			const WRITER_CLASS = 'Seonix\\Tests\\Fakes\\NoSuchRankMathHelper';
		};
	}

	/** The same method against a settings writer whose parameters were reordered. */
	private function with_drifted_writer(): Seonix_Fix_Clear_Pagination_Noindex {
		return new class( $this->history ) extends Seonix_Fix_Clear_Pagination_Noindex {
			const WRITER_CLASS = DriftedRankMathHelper::class;
		};
	}

	/** A history entry recorded by apply() with $settings as the pre-fix snapshot. */
	private function history_entry( array $settings, string $engine = 'rankmath' ): array {
		return array(
			'id'           => 31,
			'method'       => 'clear_pagination_noindex',
			'target_type'  => 'option',
			'target_id'    => 0,
			'before_state' => array(
				'engine'   => $engine,
				'value'    => true,
				'settings' => $settings,
			),
		);
	}

	public function test_key_is_clear_pagination_noindex(): void {
		$this->assertSame( 'clear_pagination_noindex', $this->method->key() );
	}

	public function test_validate_params_accepts_empty_payload_and_page_url(): void {
		$this->assertTrue( $this->method->validate_params( array() ) );
		$this->assertTrue( $this->method->validate_params( array( 'page_url' => 'https://example.com/blog/page/2/' ) ) );
	}

	public function test_validate_params_rejects_non_string_page_url(): void {
		$r = $this->method->validate_params( array( 'page_url' => array( 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_page_url', $r->get_error_code() );
	}

	/**
	 * Availability follows Rank Math's own reading of a toggle
	 * (Helper::normalize_data, then plain truthiness).
	 *
	 * @dataProvider toggle_values
	 */
	public function test_is_available_only_while_a_toggle_is_on( $raw, bool $expected ): void {
		$this->seed( array( self::ARCHIVE => $raw, self::PAGINATED => 'off' ) );

		$this->assertSame( $expected, $this->method->is_available() );
		$this->assertSame( $expected, Seonix_Fix_Clear_Pagination_Noindex::is_applicable() );
	}

	public function toggle_values(): array {
		return array(
			'on'           => array( 'on', true ),
			'off'          => array( 'off', false ),
			'string true'  => array( 'true', true ),
			'string false' => array( 'false', false ),
			'string 1'     => array( '1', true ),
			'string 0'     => array( '0', false ),
			'bool true'    => array( true, true ),
			'empty'        => array( '', false ),
		);
	}

	public function test_the_paginated_blog_toggle_alone_makes_it_available(): void {
		// "Noindex Paginated Single Pages" noindexes /page/2/ of the blog index
		// on its own, with "Noindex Subpages" off.
		$this->seed( array( self::ARCHIVE => 'off', self::PAGINATED => 'on' ) );

		$this->assertTrue( $this->method->is_available() );
	}

	public function test_is_available_false_when_the_toggles_or_option_are_absent(): void {
		$this->assertFalse( $this->method->is_available(), 'no titles option at all' );

		$this->options[ self::OPTION ] = 'garbage';
		$this->assertFalse( $this->method->is_available(), 'option is not a settings array' );

		$this->seed( array() );
		$this->assertFalse( $this->method->is_available(), 'toggles never saved: Rank Math defaults them to off' );
	}

	public function test_is_available_false_without_a_usable_writer(): void {
		$this->seed( array( self::ARCHIVE => 'on' ) );

		$this->assertFalse( $this->without_rank_math()->is_available(), 'Rank Math not loaded' );
		$this->assertFalse( $this->with_drifted_writer()->is_available(), 'parameters reordered' );
		$extra = new class( $this->history ) extends Seonix_Fix_Clear_Pagination_Noindex {
			const WRITER_CLASS = ExtraParamRankMathHelper::class;
		};
		$this->assertFalse( $extra->is_available(), 'a fourth required parameter' );
	}

	public function test_capabilities_advertise_the_method_only_while_a_toggle_is_on(): void {
		$registry = new Seonix_SEO_Fix_Registry();
		$registry->register( $this->method );

		$this->seed( array( self::ARCHIVE => 'on' ) );
		$this->assertSame( array( 'available' => true ), $registry->capabilities()['clear_pagination_noindex'] );

		$this->seed( array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ) );
		$this->assertSame( array( 'available' => false ), $registry->capabilities()['clear_pagination_noindex'] );
	}

	public function test_admin_fix_button_follows_the_same_gate(): void {
		$ref   = new \ReflectionClass( Seonix_Admin::class );
		$admin = $ref->newInstanceWithoutConstructor();
		$gate  = $ref->getMethod( 'is_auto_fixable' );
		$gate->setAccessible( true );

		$this->seed( array( self::ARCHIVE => 'on' ) );
		$this->assertTrue( $gate->invoke( $admin, 'pagination_not_indexable' ) );

		$this->seed( array( self::ARCHIVE => 'off' ) );
		$this->assertFalse( $gate->invoke( $admin, 'pagination_not_indexable' ) );

		$this->assertFalse( $gate->invoke( $admin, 'pagination_noindex_recommended' ), 'the retired code offers no fix' );
		$this->assertTrue( $gate->invoke( $admin, 'images_missing_alt' ), 'other codes unchanged' );
	}

	public function test_dry_run_reports_the_switch_without_writing(): void {
		$this->seed( array( self::ARCHIVE => 'on', self::PAGINATED => 'on' ) );

		$r = $this->method->dry_run( array( 'page_url' => 'https://example.com/blog/page/2/' ) );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertTrue( $r['before']['value'] );
		$this->assertSame( array( self::ARCHIVE => 'on', self::PAGINATED => 'on' ), $r['before']['settings'] );
		$this->assertSame( array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ), $r['after']['settings'] );
		$this->assertSame( 'option', $r['target']['type'] );
		$this->assertSame( 0, RankMathHelper::$update_calls );
		$this->assertSame( 0, $this->purges );
	}

	public function test_dry_run_without_rank_math_is_a_clean_unsupported_error(): void {
		$this->seed( array( self::ARCHIVE => 'on' ) );

		$r = $this->without_rank_math()->dry_run( array() );

		$this->assertInstanceOf( WP_Error::class, $r );
		// The code the backend maps to a skipped item, not a retryable failure.
		$this->assertSame( 'no_supported_seo_plugin', $r->get_error_code() );
		$this->assertSame( 422, $r->get_error_data()['status'] );
	}

	/**
	 * @dataProvider toggle_states
	 */
	public function test_apply_switches_off_whichever_toggle_is_on( array $seeded, array $expected ): void {
		$this->seed( $seeded );
		$want = $this->titles_with( $expected );

		$r = $this->method->apply( array( 'page_url' => 'https://example.com/blog/page/2/' ) );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertSame( 'rankmath', $r['before']['engine'] );
		// One write, through Rank Math's writer, that leaves every other title /
		// robots setting stored exactly as it was and no other settings group
		// touched — then one full page-cache purge.
		$this->assertSame( 1, RankMathHelper::$update_calls );
		$this->assertSame( $want, $this->options[ self::OPTION ] );
		$this->assertArrayNotHasKey( 'rank-math-options-general', $this->options );
		$this->assertArrayNotHasKey( 'rank-math-options-sitemap', $this->options );
		$this->assertSame( 1, $this->purges );
	}

	public function toggle_states(): array {
		return array(
			'archive subpages only' => array(
				array( self::ARCHIVE => 'on', self::PAGINATED => 'off' ),
				array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ),
			),
			'paginated blog only'   => array(
				array( self::ARCHIVE => 'off', self::PAGINATED => 'on' ),
				array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ),
			),
			'both'                  => array(
				array( self::ARCHIVE => 'on', self::PAGINATED => 'on' ),
				array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ),
			),
			'second toggle unset'   => array(
				array( self::ARCHIVE => 'on' ),
				array( self::ARCHIVE => 'off' ),
			),
		);
	}

	public function test_apply_is_no_op_when_both_toggles_are_already_off(): void {
		// A scan reports one issue per paginated URL, so after the first task
		// switched the toggles off the rest of the run must not write again.
		$this->seed( array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ) );

		$r = $this->method->apply( array() );

		$this->assertTrue( $r['no_op'] );
		$this->assertSame( 0, RankMathHelper::$update_calls );
		$this->assertSame( 0, $this->purges );
	}

	public function test_apply_never_creates_the_titles_option(): void {
		$r = $this->method->apply( array() );

		$this->assertTrue( $r['no_op'] );
		$this->assertSame( 0, RankMathHelper::$update_calls );
		$this->assertArrayNotHasKey( self::OPTION, $this->options );
	}

	public function test_apply_refuses_a_writer_with_a_reordered_signature(): void {
		$this->seed( array( self::ARCHIVE => 'on' ) );

		$r = $this->with_drifted_writer()->apply( array() );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'no_supported_seo_plugin', $r->get_error_code() );
		$this->assertSame( 'on', $this->options[ self::OPTION ][ self::ARCHIVE ] );
	}

	public function test_apply_reports_a_write_that_did_not_persist(): void {
		$this->seed( array( self::ARCHIVE => 'on' ) );
		// The option refuses the write (a filter, a read-only object cache…).
		Functions\when( 'update_option' )->justReturn( false );

		$r = $this->method->apply( array() );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'update_failed', $r->get_error_code() );
		$this->assertSame( 0, $this->purges );
	}

	public function test_rollback_restores_the_exact_previous_values(): void {
		$this->seed( array( self::ARCHIVE => 'off', self::PAGINATED => 'off' ) );
		$want = $this->titles_with( array( self::ARCHIVE => 'on', self::PAGINATED => '1' ) );
		$this->history->shouldReceive( 'get' )->with( 31 )->andReturn(
			$this->history_entry( array( self::ARCHIVE => 'on', self::PAGINATED => '1' ) )
		);

		$r = $this->method->rollback( 31 );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['before']['value'] );
		$this->assertTrue( $r['after']['value'] );
		$this->assertSame( 1, RankMathHelper::$update_calls );
		$this->assertSame( $want, $this->options[ self::OPTION ] );
		$this->assertSame( 1, $this->purges );
	}

	public function test_rollback_writes_nothing_when_the_toggle_is_already_back_on(): void {
		// Switched back on by hand since the fix: the pre-fix state is already
		// there, so there is nothing to restore and nothing to clobber.
		$this->seed( array( self::ARCHIVE => 'on', self::PAGINATED => 'off' ) );
		$this->history->shouldReceive( 'get' )->with( 31 )->andReturn(
			$this->history_entry( array( self::ARCHIVE => 'on', self::PAGINATED => 'off' ) )
		);

		$r = $this->method->rollback( 31 );

		$this->assertTrue( $r['after']['value'] );
		$this->assertSame( 0, RankMathHelper::$update_calls );
		$this->assertSame( 0, $this->purges );
	}

	public function test_rollback_of_a_no_op_entry_restores_nothing(): void {
		// The entry recorded both toggles off (apply was a no-op). The owner has
		// turned one on since: rolling the entry back must not switch it off.
		$this->seed( array( self::ARCHIVE => 'on', self::PAGINATED => 'off' ) );
		$this->history->shouldReceive( 'get' )->with( 31 )->andReturn(
			$this->history_entry( array( self::ARCHIVE => 'off', self::PAGINATED => null ) )
		);

		$this->method->rollback( 31 );

		$this->assertSame( 'on', $this->options[ self::OPTION ][ self::ARCHIVE ] );
		$this->assertSame( 0, RankMathHelper::$update_calls );
	}

	public function test_rollback_never_writes_into_a_missing_titles_option(): void {
		// Rank Math's settings were reset (option deleted) since the fix. A write
		// would create an array holding nothing but the toggles and wipe every
		// title template Rank Math falls back to.
		$this->history->shouldReceive( 'get' )->with( 31 )->andReturn(
			$this->history_entry( array( self::ARCHIVE => 'on' ) )
		);

		$r = $this->method->rollback( 31 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'settings_unreadable', $r->get_error_code() );
		$this->assertSame( 0, RankMathHelper::$update_calls );
		$this->assertArrayNotHasKey( self::OPTION, $this->options );
	}

	public function test_rollback_returns_error_for_unknown_history_id(): void {
		$this->history->shouldReceive( 'get' )->with( 999 )->andReturn( null );

		$r = $this->method->rollback( 999 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'unknown_history_entry', $r->get_error_code() );
	}

	public function test_rollback_refuses_an_entry_without_a_snapshot(): void {
		$this->seed( array( self::ARCHIVE => 'off' ) );
		$this->history->shouldReceive( 'get' )->with( 31 )->andReturn(
			array( 'id' => 31, 'before_state' => array( 'value' => true ) )
		);

		$r = $this->method->rollback( 31 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_history_entry', $r->get_error_code() );
		$this->assertSame( 0, RankMathHelper::$update_calls );
	}

	public function test_rollback_refuses_another_engines_snapshot_or_a_missing_engine(): void {
		$this->seed( array( self::ARCHIVE => 'off' ) );
		$this->history->shouldReceive( 'get' )->with( 31 )->andReturn(
			$this->history_entry( array( self::ARCHIVE => 'on' ), 'aioseo' )
		);
		$this->history->shouldReceive( 'get' )->with( 32 )->andReturn(
			$this->history_entry( array( self::ARCHIVE => 'on' ) )
		);

		$this->assertSame( 'engine_changed', $this->method->rollback( 31 )->get_error_code() );
		$this->assertSame( 'engine_changed', $this->without_rank_math()->rollback( 32 )->get_error_code() );
		$this->assertSame( 'off', $this->options[ self::OPTION ][ self::ARCHIVE ] );
		$this->assertSame( 0, RankMathHelper::$update_calls );
	}
}
