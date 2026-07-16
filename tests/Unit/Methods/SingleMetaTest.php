<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Image_Alt;
use Seonix_Fix_Meta_Description;
use Seonix_Fix_Meta_Title;
use Seonix_Fix_Single_Meta;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Exercises the abstract Seonix_Fix_Single_Meta via its concrete subclasses.
 * The behaviour is identical across meta_title / meta_description / image_alt;
 * only the meta key and target type differ, which we verify in the per-subclass
 * smoke checks at the bottom.
 */
final class SingleMetaTest extends TestCase {

    /** @var \Mockery\MockInterface */
    private $history;

    private Seonix_Fix_Meta_Title $method;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // apply()/dry_run() now route through the SEO-engine detection and the
        // post-write cache purge; stub the WP-admin / cache helpers those paths
        // touch so the meta-write behaviour under test runs in isolation.
        Functions\when( 'is_plugin_active' )->justReturn( false );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'rocket_clean_domain' )->justReturn( null );
        // Meta_Bridge::sanitize_value() strips markup before a value reaches any
        // engine's postmeta; mirror core's strip-then-collapse rather than
        // passing through, so these writes are asserted on realistic values.
        Functions\when( 'sanitize_text_field' )->alias(
            static function ( $value ) {
                $value = strip_tags( (string) $value );
                $value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );
                return trim( $value );
            }
        );
        $this->history = Mockery::mock( Seonix_SEO_Fix_History::class );
        $this->method  = new Seonix_Fix_Meta_Title( $this->history );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_validate_requires_post_id_and_string_suggested_value(): void {
        $r = $this->method->validate_params( array() );
        $this->assertSame( 'missing_post_id', $r->get_error_code() );

        $r = $this->method->validate_params( array( 'post_id' => 5 ) );
        $this->assertSame( 'missing_suggested_value', $r->get_error_code() );

        $r = $this->method->validate_params( array( 'post_id' => 5, 'suggested_value' => 12345 ) );
        $this->assertSame( 'missing_suggested_value', $r->get_error_code() );
    }

    public function test_validate_accepts_empty_string_suggested_value(): void {
        $r = $this->method->validate_params( array( 'post_id' => 5, 'suggested_value' => '' ) );
        $this->assertTrue( $r );
    }

    public function test_dry_run_returns_diff_when_value_changes(): void {
        Functions\when( 'get_post_meta' )->justReturn( 'Old very long title' );

        $r = $this->method->dry_run( array(
            'post_id'         => 5,
            'suggested_value' => 'New shorter title',
        ) );

        $this->assertFalse( $r['no_op'] );
        $this->assertSame( 'Old very long title', $r['before']['value'] );
        $this->assertSame( 'New shorter title', $r['after']['value'] );
        $this->assertSame( 'post', $r['target']['type'] );
        $this->assertSame( 5, $r['target']['id'] );
    }

    public function test_dry_run_returns_no_op_when_already_matches(): void {
        Functions\when( 'get_post_meta' )->justReturn( 'Same title' );

        $r = $this->method->dry_run( array(
            'post_id'         => 5,
            'suggested_value' => 'Same title',
        ) );

        $this->assertTrue( $r['no_op'] );
    }

    public function test_apply_writes_bridge_keys_when_changed(): void {
        // 2.6.0: meta_title applies through the meta bridge — the Seonix
        // canonical key AND the active engine's key (FakeYoast makes Yoast the
        // active engine in the test env) get the value, plus a fingerprint so
        // the reverse-sync watcher recognises the write as Seonix's own.
        Functions\when( 'get_post_meta' )->justReturn( 'old' );
        $writes = array();
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$writes ) {
            $writes[ $key ] = array( $post_id, $value );
            return true;
        } );

        $r = $this->method->apply( array(
            'post_id'         => 5,
            'suggested_value' => 'new',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
        $this->assertSame( 'new', $r['after']['value'] );

        $this->assertArrayHasKey( '_seonix_seo_title', $writes, 'canonical Seonix key must be written' );
        $this->assertSame( array( 5, 'new' ), $writes['_seonix_seo_title'] );
        $this->assertArrayHasKey( '_yoast_wpseo_title', $writes, 'active engine (Yoast) key must be written' );
        $this->assertSame( array( 5, 'new' ), $writes['_yoast_wpseo_title'] );
        $this->assertArrayHasKey( '_seonix_meta_fingerprint', $writes, 'fingerprint must be refreshed' );
    }

    public function test_apply_skips_update_when_value_unchanged(): void {
        Functions\when( 'get_post_meta' )->justReturn( 'same' );
        Functions\expect( 'update_post_meta' )->never();

        $r = $this->method->apply( array(
            'post_id'         => 5,
            'suggested_value' => 'same',
        ) );

        $this->assertTrue( $r['no_op'] );
    }

    /**
     * Safety: a fix item whose suggested_value is empty (NeedsAI=true on the
     * backend, never filled with an actual AI suggestion) must NOT wipe the
     * existing Yoast title/description on apply. Live regression: an early
     * apply pass overwrote 14 Yoast titles to "" before this guard existed.
     */
    public function test_apply_refuses_to_wipe_existing_value_with_empty_suggestion(): void {
        Functions\when( 'get_post_meta' )->justReturn( 'Existing meta title' );
        Functions\expect( 'update_post_meta' )->never();

        $r = $this->method->apply( array(
            'post_id'         => 5,
            'suggested_value' => '',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'refuse_overwrite_empty', $r->get_error_code() );
    }

    public function test_apply_returns_error_when_update_fails(): void {
        // The update_failed contract survives on the single-key (non-bridge)
        // path — image_alt. Bridge-backed methods (meta_title/description)
        // treat update_post_meta's false as benign (same-value write).
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
        Functions\when( 'update_post_meta' )->justReturn( false );

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->apply( array(
            'post_id'         => 5,
            'suggested_value' => 'new alt',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'update_failed', $r->get_error_code() );
    }

    public function test_rollback_restores_previous_value(): void {
        $this->history->shouldReceive( 'get' )
            ->with( 11 )
            ->andReturn( array(
                'id'           => 11,
                'method'       => 'meta_title',
                'target_type'  => 'post',
                'target_id'    => 5,
                'before_state' => array( 'value' => 'old' ),
                'after_state'  => array( 'value' => 'new' ),
            ) );

        // Bridge path: the restore is written to the canonical Seonix key AND
        // the active engine's key, exactly like an apply.
        Functions\when( 'get_post_meta' )->justReturn( '' );
        $writes = array();
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$writes ) {
            $writes[ $key ] = array( $post_id, $value );
            return true;
        } );

        $r = $this->method->rollback( 11 );

        $this->assertSame( 'old', $r['after']['value'] );
        $this->assertSame( 'new', $r['before']['value'] );
        $this->assertSame( array( 5, 'old' ), $writes['_seonix_seo_title'] );
        $this->assertSame( array( 5, 'old' ), $writes['_yoast_wpseo_title'] );
    }

    public function test_rollback_unknown_history_returns_error(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );
        $r = $this->method->rollback( 99 );
        $this->assertInstanceOf( WP_Error::class, $r );
    }

    // ─── Per-subclass identity smoke checks ──────────────────────────────

    public function test_meta_title_subclass_identity(): void {
        $m = new Seonix_Fix_Meta_Title( $this->history );
        $this->assertSame( 'meta_title', $m->key() );
        $this->assertInstanceOf( Seonix_Fix_Single_Meta::class, $m );
    }

    public function test_meta_description_subclass_identity(): void {
        $m = new Seonix_Fix_Meta_Description( $this->history );
        $this->assertSame( 'meta_description', $m->key() );
    }

    public function test_image_alt_subclass_uses_attachment_target(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $this->assertSame( 'image_alt', $m->key() );

        $r = $m->dry_run( array(
            'post_id'         => 100,
            'suggested_value' => 'A photo of furniture',
        ) );
        $this->assertSame( 'attachment', $r['target']['type'] );
    }

    public function test_image_alt_resolves_image_url_to_attachment_id(): void {
        // image_url is the scanner-emitted form. The fix method must call
        // attachment_url_to_postid() and write meta on the resolved attachment,
        // not on whatever post_id the caller might have stuffed in.
        \Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( '' );
        \Brain\Monkey\Functions\when( 'attachment_url_to_postid' )
            ->alias( fn ( $url ) => $url === 'https://x.test/wp-content/uploads/2025/05/photo.jpg' ? 42 : 0 );
        \Brain\Monkey\Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 42, '_wp_attachment_image_alt', 'A photo' )
            ->andReturn( true );

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->apply( array(
            'image_url'       => 'https://x.test/wp-content/uploads/2025/05/photo.jpg',
            'suggested_value' => 'A photo',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
    }

    public function test_image_alt_strips_size_suffix_to_find_original(): void {
        // WP serves scaled variants like photo-300x200.jpg in HTML; the original
        // attachment lives at photo.jpg. The fix method must retry with the suffix
        // stripped before declaring the image unknown.
        \Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( '' );
        $calls = array();
        \Brain\Monkey\Functions\when( 'attachment_url_to_postid' )
            ->alias( function ( $url ) use ( &$calls ) {
                $calls[] = $url;
                return $url === 'https://x.test/wp-content/uploads/2025/05/photo.jpg' ? 42 : 0;
            } );
        \Brain\Monkey\Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 42, '_wp_attachment_image_alt', 'Alt' )
            ->andReturn( true );

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->apply( array(
            'image_url'       => 'https://x.test/wp-content/uploads/2025/05/photo-300x200.jpg',
            'suggested_value' => 'Alt',
        ) );

        $this->assertCount( 2, $calls, 'should retry without size suffix' );
        $this->assertEmpty( $r['no_op'] ?? false );
    }

    public function test_image_alt_handles_url_encoded_filenames(): void {
        // Regression: filenames with Cyrillic come URL-encoded
        // in scan results (%D0%BC%D0%B0%D1%8F = мая) but live in WP's DB
        // decoded. attachment_url_to_postid fails on the encoded form.
        \Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( '' );
        \Brain\Monkey\Functions\when( 'attachment_url_to_postid' )
            ->alias( fn ( $url ) => $url === 'https://x.test/uploads/18-мая-2025.png' ? 77 : 0 );
        \Brain\Monkey\Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 77, '_wp_attachment_image_alt', 'Alt' )
            ->andReturn( true );

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->apply( array(
            'image_url'       => 'https://x.test/uploads/18-%D0%BC%D0%B0%D1%8F-2025.png',
            'suggested_value' => 'Alt',
        ) );
        $this->assertEmpty( $r['no_op'] ?? false );
    }

    public function test_image_alt_returns_error_when_attachment_unknown(): void {
        \Brain\Monkey\Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
        \Brain\Monkey\Functions\expect( 'update_post_meta' )->never();

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->apply( array(
            'image_url'       => 'https://x.test/wp-content/uploads/missing.jpg',
            'suggested_value' => 'Alt',
        ) );

        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'attachment_not_found', $r->get_error_code() );
    }

    /**
     * Reflect into the private rewrite_alt_in_html so we lock the regex
     * behaviour without spinning up WP. Live coverage: Gutenberg/UAGB blocks
     * render <img alt=""> hardcoded; updating attachment meta alone leaves
     * the scanner reporting the same issue every time.
     */
    public function test_rewrite_alt_in_html_replaces_empty_alt_only(): void {
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        $html = '<figure><img src="https://x.test/wp-content/uploads/2025/06/photo-682x1024.jpeg" alt="" class="x"/></figure>';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/wp-content/uploads/2025/06/photo.jpeg', 'A photo', &$count ) );

        $this->assertSame( 1, $count );
        $this->assertStringContainsString( 'alt="A photo"', $out );
        $this->assertStringNotContainsString( 'alt=""', $out );
    }

    public function test_rewrite_alt_in_html_skips_non_empty_alt(): void {
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        $html = '<img src="https://x.test/p.jpg" alt="Editor wrote this">';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/p.jpg', 'AI alt', &$count ) );

        $this->assertSame( 0, $count, 'editor-supplied alt must be preserved' );
        $this->assertSame( $html, $out );
    }

    public function test_rewrite_alt_in_html_handles_size_variants_via_basename_match(): void {
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        // The page uses a scaled URL (-300x200) but the suggester knows the
        // canonical attachment URL. Both must match.
        $html = '<img src="https://x.test/uploads/photo-300x200.jpg" alt="" />';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/uploads/photo.jpg', 'Alt', &$count ) );

        $this->assertSame( 1, $count );
        $this->assertStringContainsString( 'alt="Alt"', $out );
    }

    public function test_rewrite_alt_in_html_handles_data_src_lazyload(): void {
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        // LiteSpeed and many lazyload plugins move the real URL to data-src
        // and put a placeholder in src. We must still find the image.
        $html = '<img data-src="https://x.test/uploads/photo.jpg" src="data:image/svg+xml;base64,xxx" alt="" />';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/uploads/photo.jpg', 'Alt', &$count ) );

        $this->assertSame( 1, $count, 'must match data-src for lazy-loaded images' );
    }

    public function test_rewrite_alt_in_html_handles_block_json_image_objects(): void {
        // Spectra/UAGB image gallery + swiper carousel store images as a JSON
        // array inside the block's HTML comment attributes:
        //   <!-- wp:uagb/image-gallery {"images":[{"id":N,"url":"...","alt":""}]} -->
        // Live regression: 28 images on each of 28 similar archive pages
        // came from one shared swiper wp_block. The <img> regex never matched
        // because the raw post_content has no <img> tag — Spectra renders
        // <img> at output time from the JSON.
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        $html = '<!-- wp:uagb/image-gallery {"images":[' .
                '{"id":1412,"url":"https://x.test/uploads/photo.jpg","alt":""},' .
                '{"id":1413,"url":"https://x.test/uploads/other.jpg","alt":""}' .
                ']} /-->';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/uploads/photo.jpg', 'A photo', &$count ) );

        $this->assertSame( 1, $count, 'should rewrite ONLY the matching image, not all images in the gallery' );
        $this->assertStringContainsString( '"alt":"A photo"', $out );
        // The non-matching image keeps its empty alt.
        $this->assertStringContainsString( '"id":1413,"url":"https://x.test/uploads/other.jpg","alt":""', $out );
    }

    public function test_rewrite_alt_in_html_handles_block_json_with_size_variant(): void {
        // Block JSON references the original upload URL but the rendered <img>
        // uses a -WIDTHxHEIGHT variant. Our basename match must hit both.
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        // Caller was given the variant URL by the scanner.
        $html = '{"id":1,"url":"https://x.test/uploads/photo.jpg","alt":""}';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/uploads/photo-300x200.jpg', 'A photo', &$count ) );

        $this->assertSame( 1, $count );
        $this->assertStringContainsString( '"alt":"A photo"', $out );
    }

    public function test_rewrite_alt_in_html_block_json_preserves_non_empty_alt(): void {
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        $html = '{"id":1,"url":"https://x.test/p.jpg","alt":"Editor wrote this"}';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/p.jpg', 'AI alt', &$count ) );

        $this->assertSame( 0, $count );
        $this->assertStringContainsString( 'Editor wrote this', $out );
    }

    public function test_rewrite_alt_in_html_block_json_escapes_quotes_in_suggestion(): void {
        // AI sometimes returns alt text with quotes / unicode; json_encode
        // must produce valid JSON inside the block.
        $m  = new Seonix_Fix_Image_Alt( $this->history );
        $rc = new \ReflectionClass( $m );
        $rm = $rc->getMethod( 'rewrite_alt_in_html' );
        $rm->setAccessible( true );

        $html = '{"id":1,"url":"https://x.test/p.jpg","alt":""}';
        $count = 0;
        $out = $rm->invokeArgs( $m, array( $html, 'https://x.test/p.jpg', 'Möbel "Klassik" Set', &$count ) );

        $this->assertSame( 1, $count );
        // Raw inner double-quotes must be backslash-escaped so the block JSON stays parseable.
        $this->assertStringContainsString( '"alt":"Möbel \"Klassik\" Set"', $out );
    }

    public function test_image_alt_validates_either_url_or_post_id_required(): void {
        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->validate_params( array( 'suggested_value' => 'x' ) );
        $this->assertInstanceOf( WP_Error::class, $r );
        $this->assertSame( 'missing_target', $r->get_error_code() );
    }

    public function test_image_alt_writes_to_correct_meta_key(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 100, '_wp_attachment_image_alt', 'New alt' )
            ->andReturn( true );

        $m = new Seonix_Fix_Image_Alt( $this->history );
        $r = $m->apply( array(
            'post_id'         => 100,
            'suggested_value' => 'New alt',
        ) );

        $this->assertEmpty( $r['no_op'] ?? false );
    }

    public function test_meta_description_writes_to_yoast_metadesc_key(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 5, '_yoast_wpseo_metadesc', 'A description.' )
            ->andReturn( true );

        $m = new Seonix_Fix_Meta_Description( $this->history );
        $m->apply( array(
            'post_id'         => 5,
            'suggested_value' => 'A description.',
        ) );

        $this->assertTrue( true );
    }
}
