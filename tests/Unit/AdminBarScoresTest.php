<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\TransientStub;
use Seonix_Admin_Bar;
use Seonix_Metabox;

/**
 * The toolbar node's contract: it may only ever quote a score that belongs to
 * a revision a visitor can actually load.
 *
 * The editor scores every keystroke, so most results describe text that will
 * never be saved. Writing those straight to post meta would leave the toolbar
 * (and the live site) advertising the score of an abandoned draft. Hence the
 * two-step: /score stashes into a transient, save_post promotes it to meta.
 * These tests pin that boundary — it is invisible in the UI until it's wrong.
 *
 * Transients come from the bootstrap's in-process TransientStub rather than
 * Brain\Monkey: the stub defines set_transient() before Patchwork loads, so
 * Monkey cannot redefine it (DefinedTooEarly).
 */
final class AdminBarScoresTest extends TestCase {

	/** @var array<string,mixed> Fake post meta, keyed "postid:key". */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		TransientStub::$store = array();
		$this->meta           = array();

		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id . ':' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
	}

	protected function tearDown(): void {
		TransientStub::$store = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stashKey( int $postID ): string {
		return Seonix_Metabox::SCORE_STASH_PREFIX . $postID;
	}

	/** Typing must not touch the post: an abandoned draft leaves no meta. */
	public function test_scoring_stashes_without_writing_meta(): void {
		Seonix_Metabox::stash_scores( 42, array( 'seo_score' => 92, 'readability_score' => 100 ) );

		$this->assertSame(
			array( 'seo' => 92, 'readability' => 100 ),
			TransientStub::$store[ $this->stashKey( 42 ) ],
			'the live score should be parked in a transient'
		);
		$this->assertSame( array(), $this->meta, 'scoring alone must never write post meta' );
	}

	/** Saving the post is what makes a score public. */
	public function test_save_promotes_the_stash_to_meta_and_clears_it(): void {
		Seonix_Metabox::stash_scores( 42, array( 'seo_score' => 92, 'readability_score' => 100 ) );
		Seonix_Metabox::persist_scores_on_save( 42 );

		$this->assertSame( 92, $this->meta[ '42:' . Seonix_Admin_Bar::META_SEO ] );
		$this->assertSame( 100, $this->meta[ '42:' . Seonix_Admin_Bar::META_READABILITY ] );
		$this->assertArrayNotHasKey(
			$this->stashKey( 42 ),
			TransientStub::$store,
			'the stash is consumed on save, so a later save cannot resurrect a stale score'
		);
	}

	/** A save with nothing stashed keeps whatever was last actually scored. */
	public function test_save_without_a_stash_writes_nothing(): void {
		Seonix_Metabox::persist_scores_on_save( 42 );
		$this->assertSame( array(), $this->meta );
	}

	/**
	 * Autosaves describe a different row and fire constantly; promoting on one
	 * would publish the score of text the author is still typing.
	 */
	public function test_autosave_does_not_promote(): void {
		Seonix_Metabox::stash_scores( 42, array( 'seo_score' => 92, 'readability_score' => 100 ) );

		Functions\when( 'wp_is_post_autosave' )->justReturn( true );
		Seonix_Metabox::persist_scores_on_save( 42 );

		$this->assertSame( array(), $this->meta, 'an autosave must not publish a score' );
		$this->assertArrayHasKey(
			$this->stashKey( 42 ),
			TransientStub::$store,
			'the stash must survive an autosave so the real save can still use it'
		);
	}

	/** Revisions are separate rows; the score belongs to the parent post. */
	public function test_revision_does_not_promote(): void {
		Seonix_Metabox::stash_scores( 42, array( 'seo_score' => 92 ) );

		Functions\when( 'wp_is_post_revision' )->justReturn( true );
		Seonix_Metabox::persist_scores_on_save( 42 );

		$this->assertSame( array(), $this->meta );
	}

	/** An unsaved draft has no post to attach to. */
	public function test_draft_without_a_post_id_is_not_stashed(): void {
		Seonix_Metabox::stash_scores( 0, array( 'seo_score' => 92 ) );
		$this->assertSame( array(), TransientStub::$store );
	}

	/** A malformed engine reply must not park garbage for the toolbar to quote. */
	public function test_result_without_scores_is_ignored(): void {
		Seonix_Metabox::stash_scores( 42, array( 'seo_checks' => array() ) );
		$this->assertSame( array(), TransientStub::$store );
	}

	/** Scores are clamped: the toolbar renders them as a percentage. */
	public function test_out_of_range_scores_are_clamped_on_save(): void {
		Seonix_Metabox::stash_scores( 42, array( 'seo_score' => 140, 'readability_score' => -8 ) );
		Seonix_Metabox::persist_scores_on_save( 42 );

		$this->assertSame( 100, $this->meta[ '42:' . Seonix_Admin_Bar::META_SEO ] );
		$this->assertSame( 0, $this->meta[ '42:' . Seonix_Admin_Bar::META_READABILITY ] );
	}
}
