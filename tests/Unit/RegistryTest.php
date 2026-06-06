<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Seonix\Tests\Fakes\FakeFixMethod;
use Seonix_SEO_Fix_Registry;

final class RegistryTest extends TestCase {

    private Seonix_SEO_Fix_Registry $registry;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->registry = new Seonix_SEO_Fix_Registry();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_empty_registry_has_no_methods(): void {
        $this->assertSame( array(), $this->registry->list_keys() );
        $this->assertFalse( $this->registry->has( 'anything' ) );
        $this->assertNull( $this->registry->get( 'anything' ) );
    }

    public function test_register_adds_method(): void {
        $method = new FakeFixMethod( 'broken_link' );
        $this->registry->register( $method );

        $this->assertTrue( $this->registry->has( 'broken_link' ) );
        $this->assertSame( $method, $this->registry->get( 'broken_link' ) );
        $this->assertSame( array( 'broken_link' ), $this->registry->list_keys() );
    }

    public function test_register_multiple_methods(): void {
        $this->registry->register( new FakeFixMethod( 'a' ) );
        $this->registry->register( new FakeFixMethod( 'b' ) );
        $this->registry->register( new FakeFixMethod( 'c' ) );

        $keys = $this->registry->list_keys();
        sort( $keys );
        $this->assertSame( array( 'a', 'b', 'c' ), $keys );
    }

    public function test_re_registering_same_key_replaces_previous(): void {
        $first  = new FakeFixMethod( 'dup' );
        $second = new FakeFixMethod( 'dup' );

        $this->registry->register( $first );
        $this->registry->register( $second );

        $this->assertSame( $second, $this->registry->get( 'dup' ) );
        $this->assertCount( 1, $this->registry->list_keys() );
    }

    public function test_capabilities_marks_registered_methods_available(): void {
        $this->registry->register( new FakeFixMethod( 'alpha' ) );
        $this->registry->register( new FakeFixMethod( 'beta' ) );

        $caps = $this->registry->capabilities();

        $this->assertArrayHasKey( 'alpha', $caps );
        $this->assertArrayHasKey( 'beta', $caps );
        $this->assertTrue( $caps['alpha']['available'] );
        $this->assertTrue( $caps['beta']['available'] );
    }

    public function test_get_returns_null_for_unregistered_key(): void {
        $this->registry->register( new FakeFixMethod( 'real' ) );

        $this->assertNull( $this->registry->get( 'nonexistent' ) );
    }
}
