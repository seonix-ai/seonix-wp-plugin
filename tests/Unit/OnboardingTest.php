<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Onboarding;

/**
 * Covers the post-activation redirect decision logic (Seonix_Onboarding).
 *
 * Contract: seonix_activate() sets the REDIRECT_OPTION flag; on the next
 * admin_init maybe_redirect() consumes it exactly once and redirects to the
 * Seonix dashboard screen — EXCEPT for bulk activation, AJAX requests, the
 * network admin, and users without manage_options. The flag must be cleared
 * on every path (one-shot, never loops), and wp_safe_redirect must never be
 * called on a bail-out path.
 */
final class OnboardingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		unset( $_GET['activate-multi'] );
	}

	protected function tearDown(): void {
		unset( $_GET['activate-multi'] );
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/** Stub the WP context helpers around the redirect decision. */
	private function stubContext( bool $ajax = false, bool $network = false, bool $can = true ): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'is_network_admin' )->justReturn( $network );
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'admin_url' )->returnArg();
	}

	public function test_no_flag_means_no_redirect_and_no_state_change(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Seonix_Onboarding::REDIRECT_OPTION )
			->andReturn( false );
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse( Seonix_Onboarding::maybe_redirect() );
	}

	public function test_flag_is_cleared_even_when_redirect_is_skipped(): void {
		// Bulk activation: flag consumed, no redirect — and because the flag is
		// gone, a later unrelated request can never redirect either.
		$_GET['activate-multi'] = '1';
		Functions\expect( 'get_option' )
			->once()
			->with( Seonix_Onboarding::REDIRECT_OPTION )
			->andReturn( 1 );
		Functions\expect( 'delete_option' )
			->once()
			->with( Seonix_Onboarding::REDIRECT_OPTION );
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse( Seonix_Onboarding::maybe_redirect() );
	}

	public function test_no_redirect_during_ajax(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'delete_option' )->justReturn( true );
		$this->stubContext( true );
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse( Seonix_Onboarding::maybe_redirect() );
	}

	public function test_no_redirect_in_network_admin(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'delete_option' )->justReturn( true );
		$this->stubContext( false, true );
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse( Seonix_Onboarding::maybe_redirect() );
	}

	public function test_no_redirect_without_manage_options(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'delete_option' )->justReturn( true );
		$this->stubContext( false, false, false );
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse( Seonix_Onboarding::maybe_redirect() );
	}

	public function test_single_activation_redirects_to_seonix_screen_once(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Seonix_Onboarding::REDIRECT_OPTION )
			->andReturn( 1 );
		Functions\expect( 'delete_option' )
			->once()
			->with( Seonix_Onboarding::REDIRECT_OPTION );
		$this->stubContext();
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'admin.php?page=seonix' );

		$this->assertTrue( Seonix_Onboarding::maybe_redirect() );
	}
}
