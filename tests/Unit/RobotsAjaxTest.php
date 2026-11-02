<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Seonix_Admin;
use Seonix_Robots_Txt_Fix;
use WP_Error;

/**
 * The AJAX layer of the robots.txt fix: who may call it, and what the owner is told.
 * A wrong "Done" here sends someone away believing crawlers can reach a blocked site.
 */
final class RobotsAjaxTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	private bool $can_manage = true;

	/** @var mixed Fake answer of the live fetch, in the order the calls happen. */
	private array $live = array();

	/** @var array{success:bool,data:mixed,status:?int}|null */
	private ?array $sent = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// A plugin that blocks only while the site is discouraged: the one path that
		// needs no file on disk.
		$this->options = array( 'blog_public' => '0' );
		$_POST         = array( 'issue_code' => 'robots_blocks_all' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn() => $this->can_manage );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://site.test' );
		Functions\when( 'site_url' )->justReturn( 'https://site.test' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://site.test/robots.txt' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_home_path' )->justReturn( sys_get_temp_dir() . '/seonix-no-such-dir/' );
		Functions\when( 'trailingslashit' )->alias(
			function ( $s ) {
				return rtrim( $s, '/' ) . '/';
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, ...$args ) {
				if ( 'robots_txt' === $tag && empty( $args[0] ) ) {
					return "User-agent: *\nDisallow: /\n";
				}
				return $value;
			}
		);
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'rocket_clean_domain' )->justReturn( null );
		Functions\when( 'wp_remote_get' )->alias(
			function () {
				return array_shift( $this->live );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'text/plain' );
		Functions\when( 'wp_remote_retrieve_body' )->returnArg();
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				$this->sent = array(
					'success' => true,
					'data'    => $data,
					'status'  => null,
				);
				throw new RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data, $status = null ) {
				$this->sent = array(
					'success' => false,
					'data'    => $data,
					'status'  => $status,
				);
				throw new RuntimeException( 'sent' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = array();
		parent::tearDown();
	}

	private function call( string $handler ): array {
		$admin = ( new \ReflectionClass( Seonix_Admin::class ) )->newInstanceWithoutConstructor();
		try {
			$admin->$handler();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}
		$this->assertNotNull( $this->sent );
		return $this->sent;
	}

	private function currentToken(): string {
		$this->live = array( new WP_Error( 'loopback', 'blocked' ) );
		return ( new Seonix_Robots_Txt_Fix() )->inspect()['token'];
	}

	/** @dataProvider handlers */
	public function test_only_an_administrator_gets_in( string $handler ): void {
		$this->can_manage = false;

		$out = $this->call( $handler );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 403, $out['status'] );
		$this->assertSame( '0', $this->options['blog_public'] );
	}

	public function handlers(): array {
		return array(
			'inspect' => array( 'ajax_robots_inspect' ),
			'apply'   => array( 'ajax_robots_apply' ),
			'undo'    => array( 'ajax_robots_undo' ),
		);
	}

	public function test_inspect_sends_the_state_and_its_copy(): void {
		$this->live = array( new WP_Error( 'loopback', 'blocked' ) );

		$out = $this->call( 'ajax_robots_inspect' );

		$this->assertTrue( $out['success'] );
		$this->assertSame( 'fixable', $out['data']['state'] );
		$this->assertNotSame( '', $out['data']['headline'] );
		$this->assertNotEmpty( $out['data']['changes'] );
	}

	public function test_a_wrong_token_is_a_409_and_changes_nothing(): void {
		$this->live     = array( new WP_Error( 'loopback', 'blocked' ) );
		$_POST['token'] = 'an-old-token';

		$out = $this->call( 'ajax_robots_apply' );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 409, $out['status'], 'admin.js re-reads the file on exactly this status' );
		$this->assertSame( '0', $this->options['blog_public'] );
	}

	/** @dataProvider afterApply */
	public function test_the_message_matches_what_the_live_file_says( $live_after, $verified, string $wording ): void {
		$_POST['token'] = $this->currentToken();
		$this->live     = array( new WP_Error( 'loopback', 'blocked' ), $live_after );

		$out = $this->call( 'ajax_robots_apply' );

		$this->assertTrue( $out['success'] );
		$this->assertSame( $verified, $out['data']['verified'] );
		$this->assertStringContainsString( $wording, $out['data']['message'] );
	}

	public function afterApply(): array {
		return array(
			'block is gone'    => array( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", true, 'Crawlers can reach your site' ),
			'still blocked'    => array( "User-agent: *\nDisallow: /\n", false, 'still blocks' ),
			'cannot look'      => array( new WP_Error( 'loopback', 'blocked' ), null, 'could not re-check' ),
		);
	}
}
