<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Watcher;

/**
 * Automatic redirects for renamed and trashed posts.
 *
 * The value here is entirely in the restraint: a redirect nobody asked for is
 * worse than none, because it silently sends traffic somewhere the owner never
 * chose and then sits in the table forever. So every test below is about NOT
 * creating a rule — the one happy path is almost incidental.
 */
final class WatcherTest extends TestCase {

    /** @var array<int,array<string,mixed>> Rules the watcher asked the store to create. */
    private array $created = array();

    /** @var object Store double. */
    private $store;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->created = array();

        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );
        Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value ) => $value );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'get_post_type_object' )->alias(
            static fn () => (object) array( 'public' => true )
        );
        Functions\when( 'get_permalink' )->alias(
            static fn ( $post ) => 'https://example.com/' . ( is_object( $post ) ? $post->post_name : $post ) . '/'
        );
        // wp_parse_url() is NOT mocked here: the bootstrap defines it before
        // Patchwork loads, so Brain\Monkey cannot redefine it (DefinedTooEarly).
        // Its bootstrap version already delegates to parse_url(), which is what
        // these tests want anyway.

        // Subclasses the real store (the watcher type-hints it) but overrides
        // every method it touches, so no DB is involved and the constructor's
        // $wpdb stays null.
        $created     = &$this->created;
        $this->store = new class( $created ) extends \Seonix_Redirects_Store {
            /** @var array<string,mixed>|null */
            public $conflict = null;
            /** @var array<int,array<string,mixed>> */
            private $created;

            public function __construct( &$created ) {
                $this->created = &$created;
                parent::__construct( null );
            }
            public function find_active_conflict( string $from_path, int $exclude_id = 0 ): ?array {
                return $this->conflict;
            }
            public function create( array $data ) {
                $this->created[] = $data;
                return count( $this->created );
            }
        };
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function watcher(): Seonix_Redirects_Watcher {
        // @phpstan-ignore-next-line — anonymous store double stands in for the real class.
        return new Seonix_Redirects_Watcher( $this->store );
    }

    private function post( array $props ): \WP_Post {
        $post = new \WP_Post();
        foreach ( array_merge(
            array( 'ID' => 10, 'post_name' => 'slug', 'post_status' => 'publish', 'post_type' => 'post', 'post_parent' => 0 ),
            $props
        ) as $k => $v ) {
            $post->$k = $v;
        }
        return $post;
    }

    // ─── rename ──────────────────────────────────────────────────────────

    public function test_renaming_a_published_post_creates_a_301(): void {
        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'new-slug' ) ),
            $this->post( array( 'post_name' => 'old-slug' ) )
        );

        $this->assertCount( 1, $this->created );
        $this->assertSame( '/old-slug/', $this->created[0]['from_path'] );
        $this->assertSame( '/new-slug/', $this->created[0]['to_url'] );
        $this->assertSame( 301, $this->created[0]['status_code'] );
    }

    /** A draft's URL was never public — there is no link to save. */
    public function test_draft_rename_is_ignored(): void {
        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'new-slug', 'post_status' => 'draft' ) ),
            $this->post( array( 'post_name' => 'old-slug', 'post_status' => 'draft' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    /** Publishing a draft rewrites its slug constantly; nothing broke. */
    public function test_publishing_a_draft_is_not_a_rename(): void {
        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'final-slug', 'post_status' => 'publish' ) ),
            $this->post( array( 'post_name' => 'auto-draft-slug', 'post_status' => 'draft' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    /** Every other save must not add a rule. */
    public function test_saving_without_a_slug_change_creates_nothing(): void {
        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'same', 'post_title' => 'New title' ) ),
            $this->post( array( 'post_name' => 'same', 'post_title' => 'Old title' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    /** Moving a page under a different parent changes its URL too. */
    public function test_reparenting_a_page_creates_a_redirect(): void {
        Functions\when( 'get_permalink' )->alias(
            static fn ( $post ) => 'https://example.com/' . ( $post->post_parent ? 'parent/' : '' ) . $post->post_name . '/'
        );

        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'child', 'post_parent' => 5 ) ),
            $this->post( array( 'post_name' => 'child', 'post_parent' => 0 ) )
        );

        $this->assertCount( 1, $this->created );
        $this->assertSame( '/child/', $this->created[0]['from_path'] );
        $this->assertSame( '/parent/child/', $this->created[0]['to_url'] );
    }

    /** An existing rule is the operator's deliberate choice; never clobber it. */
    public function test_existing_rule_for_the_old_path_wins(): void {
        $this->store->conflict = array( 'id' => 1 );

        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'new-slug' ) ),
            $this->post( array( 'post_name' => 'old-slug' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    public function test_revisions_are_ignored(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( true );

        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'new-slug' ) ),
            $this->post( array( 'post_name' => 'old-slug' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    /** Private post types (and attachments) have no public URL worth keeping. */
    public function test_non_public_post_types_are_ignored(): void {
        Functions\when( 'get_post_type_object' )->alias( static fn () => (object) array( 'public' => false ) );

        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'new-slug' ) ),
            $this->post( array( 'post_name' => 'old-slug' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    public function test_filter_can_switch_the_feature_off(): void {
        Functions\when( 'apply_filters' )->alias(
            static fn ( $tag, $value ) => 'seonix_auto_redirect_enabled' === $tag ? false : $value
        );

        $this->watcher()->maybe_redirect_renamed(
            10,
            $this->post( array( 'post_name' => 'new-slug' ) ),
            $this->post( array( 'post_name' => 'old-slug' ) )
        );

        $this->assertSame( array(), $this->created );
    }

    // ─── trash ───────────────────────────────────────────────────────────

    /**
     * Deleted content has no honest destination. Sending visitors to the
     * homepage would be a soft-404: it tells crawlers the URL still resolves and
     * leaves the human wondering what happened.
     */
    public function test_trashing_a_top_level_post_serves_410(): void {
        Functions\when( 'get_post' )->alias( fn () => $this->post( array( 'post_name' => 'dead' ) ) );

        $this->watcher()->maybe_redirect_trashed( 10 );

        $this->assertCount( 1, $this->created );
        $this->assertSame( '/dead/', $this->created[0]['from_path'] );
        $this->assertNull( $this->created[0]['to_url'] );
        $this->assertSame( 410, $this->created[0]['status_code'] );
    }

    /** A child page's parent is a genuinely relevant destination. */
    public function test_trashing_a_child_redirects_to_its_parent(): void {
        $child  = $this->post( array( 'ID' => 10, 'post_name' => 'child', 'post_parent' => 5 ) );
        $parent = $this->post( array( 'ID' => 5, 'post_name' => 'parent', 'post_parent' => 0 ) );

        Functions\when( 'get_post' )->alias(
            static fn ( $id ) => 5 === (int) $id ? $parent : $child
        );

        $this->watcher()->maybe_redirect_trashed( 10 );

        $this->assertCount( 1, $this->created );
        $this->assertSame( '/child/', $this->created[0]['from_path'] );
        $this->assertSame( '/parent/', $this->created[0]['to_url'] );
        $this->assertSame( 301, $this->created[0]['status_code'] );
    }

    /** An unpublished parent is no better a destination than none. */
    public function test_trashing_a_child_of_an_unpublished_parent_serves_410(): void {
        $child  = $this->post( array( 'ID' => 10, 'post_name' => 'child', 'post_parent' => 5 ) );
        $parent = $this->post( array( 'ID' => 5, 'post_name' => 'parent', 'post_status' => 'draft' ) );

        Functions\when( 'get_post' )->alias(
            static fn ( $id ) => 5 === (int) $id ? $parent : $child
        );

        $this->watcher()->maybe_redirect_trashed( 10 );

        $this->assertSame( 410, $this->created[0]['status_code'] );
    }

    public function test_trashing_a_draft_creates_nothing(): void {
        Functions\when( 'get_post' )->alias( fn () => $this->post( array( 'post_status' => 'draft' ) ) );

        $this->watcher()->maybe_redirect_trashed( 10 );

        $this->assertSame( array(), $this->created );
    }
}
