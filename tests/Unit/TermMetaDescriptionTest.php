<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Term_Meta_Description;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Unit coverage for the term_meta_description fix method.
 *
 * Pinned scenarios:
 *  1. unresolved category URL → term_not_found error (no SEO-plugin write)
 *  2. resolved category URL + Yoast active → writes wpseo_taxonomy_meta option
 *  3. no-op when current value already matches the suggestion
 *  4. refuse-overwrite-empty safety guard
 */
final class TermMetaDescriptionTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Term_Meta_Description $method;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Term_Meta_Description( $this->history );

		// Default: sanitize_text_field is identity for unit tests so we can
		// assert the suggested value reaches the writer unchanged.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_plugin_active' )->justReturn( false );

		// Fresh Yoast taxonomy-meta store per test (the bootstrap defines the
		// fake + WPSEO_VERSION process-wide). engine_sync_indexable touches the
		// $wpdb global on write; unset it so a leaked mock from another test
		// can't turn the silent best-effort sync into a hard failure.
		\WPSEO_Taxonomy_Meta::reset();
		unset( $GLOBALS['wpdb'] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_key_is_term_meta_description(): void {
		$this->assertSame( 'term_meta_description', $this->method->key() );
	}

	public function test_validate_requires_term_url(): void {
		$r = $this->method->validate_params( array( 'suggested_value' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'missing_term_url', $r->get_error_code() );
	}

	public function test_validate_requires_absolute_url(): void {
		$r = $this->method->validate_params( array(
			'term_url'        => '/category/x',
			'suggested_value' => 'desc',
		) );
		$this->assertSame( 'invalid_term_url', $r->get_error_code() );
	}

	/**
	 * Scenario 1: URL points at a slug that doesn't exist in the term tables.
	 * The fix must surface term_not_found instead of silently writing.
	 */
	public function test_url_without_matching_term_returns_term_not_found(): void {
		// No Yoast or other plugin active. Term lookup returns null for every slug.
		Functions\when( 'get_term_by' )->justReturn( false );

		$r = $this->method->dry_run( array(
			'term_url'        => 'https://example.com/category/ghost-category/',
			'suggested_value' => 'A category description.',
		) );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'term_not_found', $r->get_error_code() );
	}

	/**
	 * Scenario 2: resolved category URL on a Yoast site. Since 2.4.2 the write
	 * goes through Yoast's public class API (`WPSEO_Taxonomy_Meta::set_value`)
	 * — we never poke the `wpseo_taxonomy_meta` option directly. Assert the
	 * value lands where Yoast's own getter (`get_term_meta`) reads it back.
	 */
	public function test_yoast_apply_writes_via_yoast_taxonomy_meta_api(): void {
		Functions\when( 'is_plugin_active' )
			->alias( fn ( $p ) => $p === 'wordpress-seo/wp-seo.php' );
		Functions\when( 'get_term_by' )->alias( function ( $field, $slug, $tax ) {
			if ( 'slug' === $field && 'general' === $slug && 'category' === $tax ) {
				return (object) array( 'term_id' => 7, 'taxonomy' => 'category' );
			}
			return false;
		} );

		$r = $this->method->apply( array(
			'term_url'        => 'https://example.com/category/general/',
			'suggested_value' => 'Overview of the General category at example.',
		) );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertSame( 'term', $r['target']['type'] );
		$this->assertSame( 7, $r['target']['id'] );
		$this->assertSame( 'category', $r['target']['taxonomy'] );

		// The description was persisted through Yoast's public taxonomy-meta API.
		$this->assertSame( 1, \WPSEO_Taxonomy_Meta::$set_calls );
		$this->assertSame(
			'Overview of the General category at example.',
			\WPSEO_Taxonomy_Meta::get_term_meta( 7, 'category', 'desc' )
		);
	}

	public function test_no_op_when_existing_value_matches_suggestion(): void {
		Functions\when( 'is_plugin_active' )
			->alias( fn ( $p ) => $p === 'wordpress-seo/wp-seo.php' );
		Functions\when( 'get_term_by' )->alias( fn () =>
			(object) array( 'term_id' => 7, 'taxonomy' => 'category' )
		);
		// Seed Yoast's store so the public getter reports an identical value.
		\WPSEO_Taxonomy_Meta::seed( 7, 'category', 'Same description' );

		$r = $this->method->apply( array(
			'term_url'        => 'https://example.com/category/general/',
			'suggested_value' => 'Same description',
		) );

		$this->assertIsArray( $r );
		$this->assertTrue( $r['no_op'] );
		// A no-op apply must not call the Yoast setter at all.
		$this->assertSame( 0, \WPSEO_Taxonomy_Meta::$set_calls );
	}

	public function test_refuses_to_overwrite_existing_value_with_empty_suggestion(): void {
		Functions\when( 'is_plugin_active' )
			->alias( fn ( $p ) => $p === 'wordpress-seo/wp-seo.php' );
		Functions\when( 'get_term_by' )->alias( fn () =>
			(object) array( 'term_id' => 7, 'taxonomy' => 'category' )
		);
		// Existing non-empty description (read back via Yoast's public getter).
		\WPSEO_Taxonomy_Meta::seed( 7, 'category', 'Existing copy' );

		$r = $this->method->apply( array(
			'term_url'        => 'https://example.com/category/general/',
			'suggested_value' => '',
		) );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'refuse_overwrite_empty', $r->get_error_code() );
		// The guard must trip before any write reaches Yoast.
		$this->assertSame( 0, \WPSEO_Taxonomy_Meta::$set_calls );
	}
}
