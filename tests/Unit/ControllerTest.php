<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Fakes\FakeFixMethod;
use Seonix_SEO_Fix_Controller;
use Seonix_SEO_Fix_Registry;
use Seonix_SEO_Fix_History;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ControllerTest extends TestCase {

    private Seonix_SEO_Fix_Registry $registry;

    /** @var \Mockery\MockInterface */
    private $history;

    private Seonix_SEO_Fix_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->registry   = new Seonix_SEO_Fix_Registry();
        $this->history    = Mockery::mock( Seonix_SEO_Fix_History::class );
        $this->controller = new Seonix_SEO_Fix_Controller( $this->registry, $this->history );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ─── /capabilities ───────────────────────────────────────────────────

    public function test_capabilities_returns_registry_capabilities(): void {
        $this->registry->register( new FakeFixMethod( 'broken_link' ) );
        $this->registry->register( new FakeFixMethod( 'ssl_mixed_content' ) );

        $response = $this->controller->handle_capabilities( new WP_REST_Request() );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'methods', $data );
        $this->assertArrayHasKey( 'broken_link', $data['methods'] );
        $this->assertArrayHasKey( 'ssl_mixed_content', $data['methods'] );
    }

    // ─── /dry-run ────────────────────────────────────────────────────────

    public function test_dry_run_unknown_method_returns_404_error(): void {
        $request = new WP_REST_Request( array(
            'method'  => 'no_such_method',
            'fix_id'  => 'fix-1',
            'params'  => array(),
        ) );

        $result = $this->controller->handle_dry_run( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'unknown_method', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_dry_run_missing_fix_id_returns_400_error(): void {
        $this->registry->register( new FakeFixMethod( 'broken_link' ) );

        $request = new WP_REST_Request( array(
            'method' => 'broken_link',
            'params' => array(),
        ) );

        $result = $this->controller->handle_dry_run( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'missing_fix_id', $result->get_error_code() );
    }

    public function test_dry_run_validation_failure_returns_error(): void {
        $this->registry->register( new FakeFixMethod(
            'broken_link',
            null, null, null,
            new WP_Error( 'invalid_params', 'bad input', array( 'status' => 422 ) )
        ) );

        $request = new WP_REST_Request( array(
            'method' => 'broken_link',
            'fix_id' => 'fix-1',
            'params' => array(),
        ) );

        $result = $this->controller->handle_dry_run( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_params', $result->get_error_code() );
    }

    public function test_dry_run_records_to_history_and_returns_before_after(): void {
        $this->registry->register( new FakeFixMethod(
            'broken_link',
            array(
                'before' => 'http://old',
                'after'  => 'https://new',
                'target' => array( 'type' => 'post', 'id' => 5 ),
            )
        ) );

        $this->history->shouldReceive( 'record_dry_run' )
            ->once()
            ->with(
                'fix-1',
                'broken_link',
                Mockery::type( 'array' ),
                'post',
                5,
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn( 100 );

        $request = new WP_REST_Request( array(
            'method' => 'broken_link',
            'fix_id' => 'fix-1',
            'params' => array( 'k' => 'v' ),
        ) );

        $response = $this->controller->handle_dry_run( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertSame( 100, $data['history_id'] );
        $this->assertSame( 'http://old', $data['before'] );
        $this->assertSame( 'https://new', $data['after'] );
        // `diff` and `target` dropped in 2.2.5 — backend never decoded either.
        $this->assertArrayNotHasKey( 'diff', $data );
        $this->assertArrayNotHasKey( 'target', $data );
    }

    // ─── /apply ──────────────────────────────────────────────────────────

    public function test_apply_short_circuits_when_fix_id_already_applied(): void {
        $this->registry->register( new FakeFixMethod( 'broken_link' ) );

        $this->history->shouldReceive( 'find_by_fix_id' )
            ->once()
            ->with( 'fix-already' )
            ->andReturn( array(
                'id'           => 77,
                'fix_id'       => 'fix-already',
                'status'       => Seonix_SEO_Fix_History::STATUS_APPLIED,
                'before_state' => '{"x":1}',
                'after_state'  => '{"x":2}',
            ) );

        // Apply MUST NOT be called again — FakeFixMethod's apply would record the call,
        // and we MUST NOT touch history.record_apply either.
        $this->history->shouldNotReceive( 'record_apply' );

        $request = new WP_REST_Request( array(
            'method' => 'broken_link',
            'fix_id' => 'fix-already',
            'params' => array(),
        ) );

        $response = $this->controller->handle_apply( $request );

        $data = $response->get_data();
        $this->assertSame( 'already_applied', $data['status'] );
        $this->assertSame( 77, $data['history_id'] );
    }

    public function test_apply_calls_method_and_records_to_history(): void {
        $this->registry->register( new FakeFixMethod(
            'ssl_mixed_content',
            null,
            array(
                'before' => 'http',
                'after'  => 'https',
                'target' => array( 'type' => 'post', 'id' => 9 ),
            )
        ) );

        $this->history->shouldReceive( 'find_by_fix_id' )->once()->andReturn( null );
        $this->history->shouldReceive( 'record_apply' )
            ->once()
            ->with(
                'fix-new',
                'ssl_mixed_content',
                Mockery::type( 'array' ),
                'post',
                9,
                'http',
                'https'
            )
            ->andReturn( 555 );

        $request = new WP_REST_Request( array(
            'method' => 'ssl_mixed_content',
            'fix_id' => 'fix-new',
            'params' => array(),
        ) );

        $response = $this->controller->handle_apply( $request );

        $data = $response->get_data();
        $this->assertSame( 'applied', $data['status'] );
        $this->assertSame( 555, $data['history_id'] );
    }

    /**
     * Regression: WordPress runs wp_slash() over REST params before they reach
     * our handler, turning "/foo/" into "\\/foo\\/". Without an unslash step
     * the broken_link method's str_replace can't match the URL it just stored
     * in before_state, so apply silently no-ops on every fix.
     */
    public function test_prepare_method_call_unslashes_params_before_validation(): void {
        $this->registry->register( new class implements \Seonix_Fix_Method {
            public array $received = array();
            public function key(): string { return 'broken_link'; }
            public function validate_params( array $params ) { $this->received = $params; return true; }
            public function dry_run( array $params ) { $this->received = $params; return array( 'before' => null, 'after' => null, 'no_op' => true ); }
            public function apply( array $params ) { return array( 'no_op' => true ); }
            public function rollback( int $id ) { return array(); }
        } );

        // wp_unslash is stubbed in tests/bootstrap.php as stripslashes (matches production).
        $this->history->shouldReceive( 'record_dry_run' )->andReturn( 1 );

        $request = new WP_REST_Request( array(
            'method' => 'broken_link',
            'fix_id' => 'fx',
            'params' => array(
                'old_url' => '\/services-north\/', // wp_slash form
                'new_url' => '\/services\/north\/',
                'post_id' => 5,
            ),
        ) );

        $this->controller->handle_dry_run( $request );

        $method = $this->registry->get( 'broken_link' );
        $this->assertSame( '/services-north/', $method->received['old_url'] );
        $this->assertSame( '/services/north/', $method->received['new_url'] );
    }

    public function test_apply_method_returning_no_op_records_as_already_applied(): void {
        $this->registry->register( new FakeFixMethod(
            'ssl_mixed_content',
            null,
            array(
                'before' => 'unchanged',
                'after'  => 'unchanged',
                'no_op'  => true,
                'target' => array( 'type' => 'post', 'id' => 3 ),
            )
        ) );

        $this->history->shouldReceive( 'find_by_fix_id' )->andReturn( null );
        $this->history->shouldReceive( 'record_no_op' )
            ->once()
            ->with( 'fix-noop', 'ssl_mixed_content', Mockery::type( 'array' ), 'post', 3, 'unchanged' )
            ->andReturn( 321 );
        $this->history->shouldNotReceive( 'record_apply' );

        $request = new WP_REST_Request( array(
            'method' => 'ssl_mixed_content',
            'fix_id' => 'fix-noop',
            'params' => array(),
        ) );

        $response = $this->controller->handle_apply( $request );

        $data = $response->get_data();
        $this->assertSame( 'already_applied', $data['status'] );
        $this->assertSame( 321, $data['history_id'] );
    }

    public function test_apply_method_returning_wp_error_propagates(): void {
        $this->registry->register( new FakeFixMethod(
            'broken_link',
            null,
            new WP_Error( 'apply_failed', 'database write failed', array( 'status' => 500 ) )
        ) );

        $this->history->shouldReceive( 'find_by_fix_id' )->andReturn( null );
        $this->history->shouldNotReceive( 'record_apply' );

        $request = new WP_REST_Request( array(
            'method' => 'broken_link',
            'fix_id' => 'fix-x',
            'params' => array(),
        ) );

        $result = $this->controller->handle_apply( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'apply_failed', $result->get_error_code() );
    }

    // ─── /rollback ───────────────────────────────────────────────────────

    public function test_rollback_unknown_history_id_returns_404(): void {
        $this->history->shouldReceive( 'get' )->with( 99 )->andReturn( null );

        $request = new WP_REST_Request( array( 'history_id' => 99 ) );

        $result = $this->controller->handle_rollback( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'unknown_history_entry', $result->get_error_code() );
    }

    public function test_rollback_calls_method_and_marks_history(): void {
        $method = new FakeFixMethod(
            'broken_link',
            null, null,
            array( 'before' => 'new', 'after' => 'old' )
        );
        $this->registry->register( $method );

        $this->history->shouldReceive( 'get' )
            ->with( 42 )
            ->andReturn( array(
                'id'     => 42,
                'method' => 'broken_link',
                'status' => Seonix_SEO_Fix_History::STATUS_APPLIED,
            ) );

        $this->history->shouldReceive( 'mark_rolled_back' )->with( 42 )->once()->andReturn( true );

        $request  = new WP_REST_Request( array( 'history_id' => 42 ) );
        $response = $this->controller->handle_rollback( $request );

        $data = $response->get_data();
        // `status` dropped in 2.2.5 — backend hardcodes
        // model.SeoFixItemStatusRolledBack after every successful rollback.
        $this->assertArrayNotHasKey( 'status', $data );
        $this->assertSame( 42, $data['history_id'] );
        $this->assertSame( 'new', $data['before'] );
        $this->assertSame( 'old', $data['after'] );
    }

    public function test_rollback_for_already_rolled_back_entry_is_noop(): void {
        $this->registry->register( new FakeFixMethod( 'broken_link' ) );

        $this->history->shouldReceive( 'get' )
            ->with( 42 )
            ->andReturn( array(
                'id'     => 42,
                'method' => 'broken_link',
                'status' => Seonix_SEO_Fix_History::STATUS_ROLLED_BACK,
            ) );

        $this->history->shouldNotReceive( 'mark_rolled_back' );

        $request  = new WP_REST_Request( array( 'history_id' => 42 ) );
        $response = $this->controller->handle_rollback( $request );

        $data = $response->get_data();
        // `status` dropped in 2.2.5 — backend hardcodes the rolled-back
        // status itself and doesn't distinguish the already-rolled-back case
        // off the wire. The presence of history_id is enough for success.
        $this->assertArrayNotHasKey( 'status', $data );
        $this->assertSame( 42, $data['history_id'] );
    }
}
