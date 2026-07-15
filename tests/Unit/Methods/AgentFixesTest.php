<?php
namespace Seonix\Tests\Unit\Methods;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Fix_Agent_Accessibility;
use Seonix_Fix_Agent_WebMCP;
use Seonix_SEO_Fix_History;
use WP_Error;

/**
 * Covers the agent_accessibility and agent_webmcp fix methods.
 *
 * Both are option-flag fixes in the pagination-noindex mould: the entire fix is
 * flipping a site-wide option that gates a set of render-time filters. There is
 * no per-post mutation to assert — the point of the design is that the offending
 * markup is generated at render time (Spectra's render_block, CF7's shortcode)
 * and never exists in post_content, so it cannot be rewritten there.
 *
 * The two methods are structurally identical, so the shared behaviour is driven
 * from one data provider over both classes; the option key each one owns is
 * asserted separately.
 */
final class AgentFixesTest extends TestCase {

	/** @var array<string,mixed> In-memory option store backing the get/update stubs. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		// Minimal option store with WordPress's real semantics: update_option
		// returns false when the value is unchanged, and get_option falls back to
		// the supplied default.
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/** @return \Mockery\MockInterface&Seonix_SEO_Fix_History */
	private function history() {
		return Mockery::mock( Seonix_SEO_Fix_History::class );
	}

	private function make( string $class ) {
		return new $class( $this->history() );
	}

	/** @return array<string,array{0:string,1:string,2:string}> class, fix key, option key */
	public function methodProvider(): array {
		return array(
			'agent_accessibility' => array(
				Seonix_Fix_Agent_Accessibility::class,
				'agent_accessibility',
				'seonix_agent_a11y_enabled',
			),
			'agent_webmcp'        => array(
				Seonix_Fix_Agent_WebMCP::class,
				'agent_webmcp',
				'seonix_webmcp_enabled',
			),
		);
	}

	/**
	 * The key is the contract with the backend scanner's task routing — a typo
	 * here silently strands the fix.
	 *
	 * @dataProvider methodProvider
	 */
	public function test_key_matches_backend_contract( string $class, string $expected_key ): void {
		$this->assertSame( $expected_key, $this->make( $class )->key() );
	}

	/** @dataProvider methodProvider */
	public function test_validate_params_accepts_empty_payload( string $class ): void {
		$this->assertTrue( $this->make( $class )->validate_params( array() ) );
	}

	/** @dataProvider methodProvider */
	public function test_validate_params_accepts_site_url_string( string $class ): void {
		$this->assertTrue( $this->make( $class )->validate_params( array( 'site_url' => 'https://example.com/' ) ) );
	}

	/** @dataProvider methodProvider */
	public function test_validate_params_rejects_non_string_site_url( string $class ): void {
		$r = $this->make( $class )->validate_params( array( 'site_url' => 42 ) );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_site_url', $r->get_error_code() );
	}

	/**
	 * No third-party plugin dependency: the option is ours and so are the filters
	 * it gates, so the fix is always offered to the /capabilities handshake.
	 *
	 * @dataProvider methodProvider
	 */
	public function test_is_available_is_always_true( string $class ): void {
		$this->assertTrue( $this->make( $class )->is_available() );
	}

	/** @dataProvider methodProvider */
	public function test_dry_run_reports_false_to_true_when_option_unset( string $class, string $key, string $option ): void {
		$r = $this->make( $class )->dry_run( array() );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertFalse( $r['before']['value'] );
		$this->assertTrue( $r['after']['value'] );
		$this->assertSame( 'option', $r['target']['type'] );
		$this->assertSame( $option, $r['target']['key'] );
	}

	/** @dataProvider methodProvider */
	public function test_dry_run_does_not_persist( string $class, string $key, string $option ): void {
		$this->make( $class )->dry_run( array() );

		$this->assertArrayNotHasKey( $option, $this->options );
	}

	/** @dataProvider methodProvider */
	public function test_dry_run_is_no_op_when_already_enabled( string $class, string $key, string $option ): void {
		$this->options[ $option ] = 1;

		$r = $this->make( $class )->dry_run( array() );

		$this->assertTrue( $r['no_op'] );
		$this->assertTrue( $r['before']['value'] );
		$this->assertTrue( $r['after']['value'] );
	}

	/** @dataProvider methodProvider */
	public function test_apply_enables_the_option( string $class, string $key, string $option ): void {
		$r = $this->make( $class )->apply( array() );

		$this->assertIsArray( $r );
		$this->assertFalse( $r['no_op'] );
		$this->assertFalse( $r['before']['value'] );
		$this->assertTrue( $r['after']['value'] );
		$this->assertTrue( (bool) $this->options[ $option ] );
	}

	/**
	 * Each fix owns exactly one option and must not touch its sibling's.
	 *
	 * @dataProvider methodProvider
	 */
	public function test_apply_touches_only_its_own_option( string $class, string $key, string $option ): void {
		$this->make( $class )->apply( array() );

		$this->assertSame( array( $option ), array_keys( $this->options ) );
	}

	/**
	 * The dashboard's "already applied" badge depends on this.
	 *
	 * @dataProvider methodProvider
	 */
	public function test_apply_is_idempotent_when_already_enabled( string $class, string $key, string $option ): void {
		$this->options[ $option ] = 1;

		$r = $this->make( $class )->apply( array() );

		$this->assertTrue( $r['no_op'] );
		$this->assertTrue( $r['before']['value'] );
		$this->assertTrue( (bool) $this->options[ $option ] );
	}

	/** @dataProvider methodProvider */
	public function test_apply_twice_leaves_option_enabled( string $class, string $key, string $option ): void {
		$method = $this->make( $class );

		$first  = $method->apply( array() );
		$second = $method->apply( array() );

		$this->assertFalse( $first['no_op'] );
		$this->assertTrue( $second['no_op'] );
		$this->assertTrue( (bool) $this->options[ $option ] );
	}

	/** @dataProvider methodProvider */
	public function test_rollback_restores_previous_value( string $class, string $key, string $option ): void {
		$history = $this->history();
		$history->shouldReceive( 'get' )
			->with( 77 )
			->andReturn(
				array(
					'id'           => 77,
					'method'       => $key,
					'target_type'  => 'option',
					'target_id'    => 0,
					'before_state' => array( 'value' => false ),
					'after_state'  => array( 'value' => true ),
				)
			);

		$this->options[ $option ] = 1;

		$method = new $class( $history );
		$r      = $method->rollback( 77 );

		$this->assertIsArray( $r );
		$this->assertTrue( $r['before']['value'] );
		$this->assertFalse( $r['after']['value'] );
		$this->assertFalse( (bool) $this->options[ $option ] );
	}

	/**
	 * A site that already had the feature on before Seonix ever applied the fix
	 * must keep it on after a rollback.
	 *
	 * @dataProvider methodProvider
	 */
	public function test_rollback_to_previously_enabled_state_keeps_it_enabled( string $class, string $key, string $option ): void {
		$history = $this->history();
		$history->shouldReceive( 'get' )
			->with( 88 )
			->andReturn(
				array(
					'id'           => 88,
					'method'       => $key,
					'before_state' => array( 'value' => true ),
					'after_state'  => array( 'value' => true ),
				)
			);

		$this->options[ $option ] = 1;

		$method = new $class( $history );
		$r      = $method->rollback( 88 );

		$this->assertTrue( $r['after']['value'] );
		$this->assertTrue( (bool) $this->options[ $option ] );
	}

	/** @dataProvider methodProvider */
	public function test_rollback_returns_error_for_unknown_history_id( string $class ): void {
		$history = $this->history();
		$history->shouldReceive( 'get' )->with( 999 )->andReturn( null );

		$method = new $class( $history );
		$r      = $method->rollback( 999 );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'unknown_history_entry', $r->get_error_code() );
	}
}
