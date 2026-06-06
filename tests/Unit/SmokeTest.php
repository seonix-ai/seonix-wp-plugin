<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the test harness is wired up correctly:
 *   - composer autoload loads
 *   - Brain Monkey can mock WP functions
 *   - Mockery integrates without leaks across tests
 */
final class SmokeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_brain_monkey_can_mock_wp_function(): void {
        Monkey\Functions\when( 'get_option' )->justReturn( 'mocked-value' );

        $this->assertSame( 'mocked-value', \get_option( 'any_key' ) );
    }

    public function test_plugin_constants_are_defined(): void {
        $this->assertTrue( defined( 'ABSPATH' ) );
        $this->assertTrue( defined( 'SEONIX_DIR' ) );
        $this->assertTrue( defined( 'SEONIX_VERSION' ) );
    }
}
