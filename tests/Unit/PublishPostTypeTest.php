<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_REST_API;
use WP_Post;
use WP_REST_Request;

/**
 * Covers the post type and taxonomy rules of the publish route
 * (POST seonix/v1/posts, content-engine/v1/posts).
 *
 * Why: the backend can publish into a post the site owner wrote himself — a
 * placeholder ("stub") page or custom-post-type entry rewritten in place with a
 * full article. The update path used to force post_type 'post', which turned a
 * page into a post (new URL, dropped out of its hierarchy), and it applied
 * categories/tags to types that have no such taxonomy (creating category terms
 * nobody could see on the page). Create keeps writing plain posts.
 */
final class PublishPostTypeTest extends TestCase {

	private Seonix_REST_API $api;

	/** Type each existing post id reports through get_post_type(). @var array<int,string> */
	private array $types = array();

	/** Taxonomies each post type carries. @var array<string,string[]> */
	private array $taxonomies = array(
		'post'      => array( 'category', 'post_tag' ),
		'page'      => array(),
		'portfolio' => array(),
		'product'   => array( 'product_cat' ),
	);

	/** Captured wp_update_post / wp_insert_post postarr. @var array<int,array{fn:string,data:array}> */
	private array $writes = array();

	/** Captured wp_insert_term calls. @var array<int,string> */
	private array $created_terms = array();

	/** Captured wp_set_post_tags calls. @var array<int,array> */
	private array $tag_calls = array();

	/** Captured update_post_meta keys. @var array<int,string> */
	private array $meta_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Seonix\Tests\TransientStub::$store = array();

		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_title' )->alias( static fn ( $v ) => strtolower( trim( (string) $v ) ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'rest_sanitize_boolean' )->alias( static fn ( $v ) => (bool) $v );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn ( $id ) => "https://example.test/?p=$id" );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array() );

		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				$id = (int) $id;
				if ( ! isset( $this->types[ $id ] ) ) {
					return null;
				}
				// Empty body: the inline-image sideload bails right away.
				return new WP_Post( array( 'ID' => $id, 'post_type' => $this->types[ $id ] ) );
			}
		);
		Functions\when( 'get_post_type' )->alias(
			fn ( $id ) => $this->types[ (int) $id ] ?? false
		);
		Functions\when( 'is_object_in_taxonomy' )->alias(
			fn ( $type, $tax ) => in_array( $tax, $this->taxonomies[ $type ] ?? array(), true )
		);
		Functions\when( 'wp_insert_term' )->alias(
			function ( $name ) {
				$this->created_terms[] = $name;
				return array( 'term_id' => 100 + count( $this->created_terms ) );
			}
		);
		Functions\when( 'wp_set_post_tags' )->alias(
			function ( $post_id, $tags ) {
				$this->tag_calls[] = array( $post_id, $tags );
				return array();
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key ) {
				$this->meta_writes[] = $key;
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr ) {
				$this->writes[] = array( 'fn' => 'wp_update_post', 'data' => $postarr );
				return (int) $postarr['ID'];
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			function ( $postarr ) {
				$this->writes[] = array( 'fn' => 'wp_insert_post', 'data' => $postarr );
				$this->types[77] = $postarr['post_type'] ?? 'post';
				return 77;
			}
		);

		$this->api = new Seonix_REST_API();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_update_of_a_page_keeps_its_page_type(): void {
		$this->types[42] = 'page';

		$response = $this->api->handle_publish( $this->request( array( 'wp_post_id' => 42 ) ) );

		$this->assertSame( 42, $response['post_id'] );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( 'wp_update_post', $this->writes[0]['fn'] );
		$this->assertSame( 42, $this->writes[0]['data']['ID'] );
		// No post_type at all: wp_update_post() merges the stored row, so the
		// page stays a page. Passing 'post' here converted it into a post.
		$this->assertArrayNotHasKey( 'post_type', $this->writes[0]['data'] );
	}

	public function test_update_of_a_custom_post_type_keeps_its_type(): void {
		$this->types[55] = 'portfolio';

		$this->api->handle_publish( $this->request( array( 'wp_post_id' => 55 ) ) );

		$this->assertSame( 'wp_update_post', $this->writes[0]['fn'] );
		$this->assertArrayNotHasKey( 'post_type', $this->writes[0]['data'] );
	}

	public function test_preserve_modified_update_of_a_page_keeps_its_type_too(): void {
		$this->types[42] = 'page';

		$this->api->handle_publish(
			$this->request( array( 'wp_post_id' => 42, 'preserve_modified' => true ) )
		);

		$this->assertSame( 'wp_update_post', $this->writes[0]['fn'] );
		$this->assertArrayNotHasKey( 'post_type', $this->writes[0]['data'] );
	}

	public function test_categories_and_tags_are_ignored_for_a_type_without_those_taxonomies(): void {
		$this->types[42] = 'page';

		$this->api->handle_publish( $this->request( array(
			'wp_post_id' => 42,
			'categories' => array( 'Guides', 'News' ),
			'tags'       => array( 'seo', 'stub' ),
		) ) );

		$data = $this->writes[0]['data'];
		$this->assertArrayNotHasKey( 'post_category', $data );
		$this->assertSame( array(), $this->created_terms, 'no category terms may be created for a page' );
		$this->assertSame( array(), $this->tag_calls, 'no post_tag terms may be attached to a page' );
		$this->assertNotContains( '_yoast_wpseo_primary_category', $this->meta_writes );
	}

	public function test_categories_ignored_for_a_cpt_with_a_different_taxonomy(): void {
		$this->types[60] = 'product';

		$this->api->handle_publish( $this->request( array(
			'wp_post_id' => 60,
			'categories' => array( 'Guides' ),
		) ) );

		$this->assertArrayNotHasKey( 'post_category', $this->writes[0]['data'] );
		$this->assertSame( array(), $this->created_terms );
	}

	public function test_update_of_a_post_still_applies_categories_and_tags(): void {
		$this->types[42] = 'post';

		$this->api->handle_publish( $this->request( array(
			'wp_post_id' => 42,
			'categories' => array( 'Guides' ),
			'tags'       => array( 'seo' ),
		) ) );

		$data = $this->writes[0]['data'];
		$this->assertArrayNotHasKey( 'post_type', $data );
		$this->assertSame( array( 101 ), $data['post_category'] );
		$this->assertSame( array( 'Guides' ), $this->created_terms );
		$this->assertSame( array( array( 42, array( 'seo' ) ) ), $this->tag_calls );
	}

	public function test_absent_slug_leaves_post_name_untouched_on_update(): void {
		$this->types[42] = 'page';

		$this->api->handle_publish( $this->request( array( 'wp_post_id' => 42 ) ) );

		$this->assertArrayNotHasKey( 'post_name', $this->writes[0]['data'] );
	}

	public function test_create_still_writes_a_post_with_categories_and_tags(): void {
		$response = $this->api->handle_publish( $this->request( array(
			'categories' => array( 'Guides' ),
			'tags'       => array( 'seo' ),
		) ) );

		$this->assertSame( 77, $response['post_id'] );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( 'wp_insert_post', $this->writes[0]['fn'] );
		$data = $this->writes[0]['data'];
		$this->assertSame( 'post', $data['post_type'] );
		$this->assertArrayNotHasKey( 'ID', $data );
		$this->assertSame( array( 101 ), $data['post_category'] );
		$this->assertSame( array( array( 77, array( 'seo' ) ) ), $this->tag_calls );
	}

	public function test_update_of_a_missing_post_returns_404(): void {
		$response = $this->api->handle_publish( $this->request( array( 'wp_post_id' => 999 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'post_not_found', $response->get_error_code() );
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request(
			array_merge(
				array(
					'title'   => 'Full article',
					// Already block markup: html_to_blocks() passes it through.
					'content' => "<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->",
					'status'  => 'publish',
				),
				$params
			),
			array( 'authorization' => 'Bearer sx_test' )
		);
	}
}
