<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Image_Alt;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Covers Seonix_Fix_Image_Alt's deep pass (rewriting empty alt="" in
 * post_content across posts) and its SURGICAL rollback: undo reverts the
 * attachment meta AND puts the alt we wrote back to empty on the current
 * content of every post the fix touched — only where the alt is still exactly
 * our value, so unrelated edits survive. No page copies are stored or used.
 *
 * The attachment id is passed directly (resolve_attachment_id short-circuits on
 * post_id) so these tests don't need attachment_url_to_postid.
 */
final class ImageAltTest extends TestCase {

	private const IMG    = 'https://example.com/wp-content/uploads/cat.jpg';
	private const ALT    = 'A grey cat';
	private const ATT_ID = 42;

	/** @var \Mockery\MockInterface */
	private $history;

	private Seonix_Fix_Image_Alt $method;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
		$this->method  = new Seonix_Fix_Image_Alt( $this->history );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Wire a $wpdb that returns the given posts from the deep-scan SELECT. */
	private function wpdb_returning( array $rows ): void {
		$wpdb        = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( $s ) => $s );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_apply_sets_meta_rewrites_alt_and_records_affected_post_ids(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' ); // attachment alt currently empty
		Functions\when( 'update_post_meta' )->justReturn( true );

		$this->wpdb_returning( array(
			(object) array(
				'ID'           => 100,
				'post_content' => '<p>Hi</p><img src="' . self::IMG . '" alt="">',
			),
		) );

		$written = null;
		Functions\expect( 'wp_update_post' )
			->once()
			->with( Mockery::on( function ( $u ) use ( &$written ) {
				$written = $u['post_content'];
				return 100 === (int) $u['ID'];
			} ), true )
			->andReturn( 100 );

		$r = $this->method->apply( array(
			'post_id'         => self::ATT_ID,
			'image_url'       => self::IMG,
			'suggested_value' => self::ALT,
		) );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		// The <img> alt was filled with our value (quote-free needle: wp_slash
		// escapes the surrounding quotes in the written content).
		$this->assertStringContainsString( self::ALT, $written );
		// Affected post ids are persisted UNDER after_state so rollback can find
		// them — a top-level key would be dropped by the controller.
		$this->assertSame( array( 100 => 1 ), $r['after']['post_rewrites'] );
	}

	public function test_rollback_reverts_meta_and_surgically_reverts_alt_in_posts(): void {
		$this->history->shouldReceive( 'get' )
			->with( 7 )
			->andReturn( array(
				'id'          => 7,
				'method'      => 'image_alt',
				'params'      => array( 'image_url' => self::IMG, 'suggested_value' => self::ALT ),
				'target_id'   => self::ATT_ID,
				'before_state' => array( 'value' => '' ), // meta was empty before the fix
				'after_state' => array( 'value' => self::ALT, 'post_rewrites' => array( '100' => 1 ) ),
			) );

		// Meta revert (base rollback) writes the old empty value.
		Functions\expect( 'update_post_meta' )->once()->andReturn( true );

		// Current content still carries exactly the alt we wrote.
		Functions\when( 'get_post' )->justReturn( (object) array(
			'ID'           => 100,
			'post_content' => '<img src="' . self::IMG . '" alt="' . self::ALT . '">',
		) );

		$written = null;
		Functions\expect( 'wp_update_post' )
			->once()
			->with( Mockery::on( function ( $u ) use ( &$written ) {
				$written = $u['post_content'];
				return 100 === (int) $u['ID'];
			} ), true )
			->andReturn( 100 );

		$r = $this->method->rollback( 7 );

		$this->assertIsArray( $r );
		// alt reverted back to empty — our value is gone from the written content.
		$this->assertStringNotContainsString( self::ALT, $written );
		$this->assertSame( 1, $r['reverted_posts'] );
	}

	public function test_rollback_skips_post_whose_alt_was_changed_since(): void {
		$this->history->shouldReceive( 'get' )
			->with( 7 )
			->andReturn( array(
				'method'      => 'image_alt',
				'params'      => array( 'image_url' => self::IMG, 'suggested_value' => self::ALT ),
				'target_id'   => self::ATT_ID,
				'before_state' => array( 'value' => '' ),
				'after_state' => array( 'value' => self::ALT, 'post_rewrites' => array( '100' => 1 ) ),
			) );

		Functions\expect( 'update_post_meta' )->once()->andReturn( true ); // meta still reverts

		// An editor replaced our alt with their own caption — must NOT be clobbered.
		Functions\when( 'get_post' )->justReturn( (object) array(
			'ID'           => 100,
			'post_content' => '<img src="' . self::IMG . '" alt="Editor wrote a better caption">',
		) );
		Functions\expect( 'wp_update_post' )->never(); // content left untouched

		$r = $this->method->rollback( 7 );

		$this->assertIsArray( $r );
		$this->assertSame( 0, $r['reverted_posts'] );
	}

	public function test_apply_rewrites_block_json_alt(): void {
		// Spectra/UAGB store the image inside block-attribute JSON, not an <img>
		// tag. The shared walker must fill "alt":"" forward.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$block = '<!-- wp:uagb/image {"id":9,"url":"' . self::IMG . '","alt":""} /-->';
		$this->wpdb_returning( array( (object) array( 'ID' => 100, 'post_content' => $block ) ) );

		$written = null;
		Functions\expect( 'wp_update_post' )
			->once()
			->with( Mockery::on( function ( $u ) use ( &$written ) {
				$written = $u['post_content'];
				return 100 === (int) $u['ID'];
			} ), true )
			->andReturn( 100 );

		$r = $this->method->apply( array(
			'post_id'         => self::ATT_ID,
			'image_url'       => self::IMG,
			'suggested_value' => self::ALT,
		) );

		$this->assertSame( array( 100 => 1 ), $r['after']['post_rewrites'] );
		$this->assertStringContainsString( self::ALT, $written ); // alt written into the JSON
	}

	public function test_rollback_reverts_block_json_alt(): void {
		// The shared block-JSON walker must also run in reverse.
		$this->history->shouldReceive( 'get' )
			->with( 7 )
			->andReturn( array(
				'method'      => 'image_alt',
				'params'      => array( 'image_url' => self::IMG, 'suggested_value' => self::ALT ),
				'target_id'   => self::ATT_ID,
				'before_state' => array( 'value' => '' ),
				'after_state' => array( 'value' => self::ALT, 'post_rewrites' => array( '100' => 1 ) ),
			) );
		Functions\expect( 'update_post_meta' )->once()->andReturn( true );

		$block = '<!-- wp:uagb/image {"id":9,"url":"' . self::IMG . '","alt":"' . self::ALT . '"} /-->';
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 100, 'post_content' => $block ) );

		$written = null;
		Functions\expect( 'wp_update_post' )
			->once()
			->with( Mockery::on( function ( $u ) use ( &$written ) {
				$written = $u['post_content'];
				return 100 === (int) $u['ID'];
			} ), true )
			->andReturn( 100 );

		$r = $this->method->rollback( 7 );

		$this->assertStringNotContainsString( self::ALT, $written ); // our alt reverted out of the JSON
		$this->assertSame( 1, $r['reverted_posts'] );
	}

	public function test_rollback_old_row_without_post_rewrites_reverts_meta_only(): void {
		// Rows applied before this fix shipped never persisted post_rewrites;
		// rollback must still revert the attachment meta and touch no content.
		$this->history->shouldReceive( 'get' )
			->with( 7 )
			->andReturn( array(
				'method'      => 'image_alt',
				'params'      => array( 'image_url' => self::IMG, 'suggested_value' => self::ALT ),
				'target_id'   => self::ATT_ID,
				'before_state' => array( 'value' => '' ),
				'after_state' => array( 'value' => self::ALT ), // no post_rewrites
			) );

		Functions\expect( 'update_post_meta' )->once()->andReturn( true );
		Functions\expect( 'wp_update_post' )->never();

		$r = $this->method->rollback( 7 );

		$this->assertIsArray( $r );
		$this->assertSame( 0, $r['reverted_posts'] );
	}
}
