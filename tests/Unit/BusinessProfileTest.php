<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Business_Profile;

/**
 * Covers the sitewide business-entity contract:
 *   • sanitize() enforces the completeness gate (phone AND street|city),
 *     clamps unknown @types and caps areaServed;
 *   • store_from_request() distinguishes "absent key" (keep stored copy)
 *     from "empty/incomplete profile" (delete it) from "complete" (refresh);
 *   • enrich_organization() is strictly additive on Yoast's node;
 *   • llms_block() renders quotable NAP facts.
 */
final class BusinessProfileTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : ''
		);
		Functions\when( 'get_option' )->alias(
			fn ( $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'WohnArt Studio' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function complete_profile(): array {
		return array(
			'business_type'  => 'HomeAndConstructionBusiness',
			'phone'          => '+491628907726',
			'email'          => 'info@example.de',
			'street_address' => 'Birkenwäldchen 6',
			'city'           => 'Oranienburg',
			'postal_code'    => '16515',
			'region'         => 'Brandenburg',
			'country'        => 'de',
			'area_served'    => array( 'Berlin', 'Oranienburg', 'Berlin' ),
		);
	}

	// ─── sanitize ─────────────────────────────────────────────────────────

	public function test_sanitize_rejects_non_array_and_incomplete(): void {
		$this->assertNull( Seonix_Business_Profile::sanitize( 'nope' ) );
		$this->assertNull( Seonix_Business_Profile::sanitize( array() ) );
		$this->assertNull( Seonix_Business_Profile::sanitize( array( 'phone' => '+49123' ) ) );
		$this->assertNull( Seonix_Business_Profile::sanitize( array( 'street_address' => 'Somewhere 1', 'city' => 'Berlin' ) ) );
	}

	public function test_sanitize_accepts_complete_profile_and_dedupes_areas(): void {
		$profile = Seonix_Business_Profile::sanitize( $this->complete_profile() );
		$this->assertIsArray( $profile );
		$this->assertSame( 'HomeAndConstructionBusiness', $profile['business_type'] );
		$this->assertSame( array( 'Berlin', 'Oranienburg' ), $profile['area_served'] );
	}

	public function test_sanitize_clamps_unknown_type(): void {
		$raw                  = $this->complete_profile();
		$raw['business_type'] = 'EvilType<script>';
		$profile              = Seonix_Business_Profile::sanitize( $raw );
		$this->assertSame( 'LocalBusiness', $profile['business_type'] );
	}

	public function test_sanitize_caps_area_served(): void {
		$raw                = $this->complete_profile();
		$raw['area_served'] = array_map( static fn ( $i ) => "City {$i}", range( 1, 40 ) );
		$profile            = Seonix_Business_Profile::sanitize( $raw );
		$this->assertCount( 20, $profile['area_served'] );
	}

	// ─── store_from_request ───────────────────────────────────────────────

	public function test_store_absent_key_keeps_stored_copy(): void {
		$this->options[ Seonix_Business_Profile::OPTION ] = $this->complete_profile();
		Seonix_Business_Profile::store_from_request( null );
		$this->assertArrayHasKey( Seonix_Business_Profile::OPTION, $this->options );
	}

	public function test_store_empty_object_deletes_stored_copy(): void {
		$this->options[ Seonix_Business_Profile::OPTION ] = $this->complete_profile();
		// json {} decodes to an empty PHP array in the REST layer.
		Seonix_Business_Profile::store_from_request( array() );
		$this->assertArrayNotHasKey( Seonix_Business_Profile::OPTION, $this->options );
	}

	public function test_store_complete_profile_refreshes(): void {
		Seonix_Business_Profile::store_from_request( $this->complete_profile() );
		$this->assertSame( 'Oranienburg', $this->options[ Seonix_Business_Profile::OPTION ]['city'] );
	}

	// ─── enrich_organization ─────────────────────────────────────────────

	public function test_enrich_is_additive_and_multi_types_the_node(): void {
		Seonix_Business_Profile::store_from_request( $this->complete_profile() );

		$node = array(
			'@type' => 'Organization',
			'@id'   => 'https://example.de/#organization',
			'name'  => 'Yoast Owns This Name',
			'logo'  => array( '@id' => 'https://example.de/#logo' ),
		);
		$out = ( new Seonix_Business_Profile() )->enrich_organization( $node );

		$this->assertSame( array( 'Organization', 'HomeAndConstructionBusiness' ), $out['@type'] );
		$this->assertSame( 'Yoast Owns This Name', $out['name'] );
		$this->assertSame( '+491628907726', $out['telephone'] );
		$this->assertSame( 'Birkenwäldchen 6', $out['address']['streetAddress'] );
		$this->assertSame( 'DE', $out['address']['addressCountry'] );
		$this->assertSame( 'AdministrativeArea', $out['areaServed'][0]['@type'] );
	}

	public function test_enrich_never_overwrites_existing_address_or_phone(): void {
		Seonix_Business_Profile::store_from_request( $this->complete_profile() );
		$node = array(
			'@type'     => 'Organization',
			'telephone' => '+49000000',
			'address'   => array( '@type' => 'PostalAddress', 'addressLocality' => 'Elsewhere' ),
		);
		$out = ( new Seonix_Business_Profile() )->enrich_organization( $node );
		$this->assertSame( '+49000000', $out['telephone'] );
		$this->assertSame( 'Elsewhere', $out['address']['addressLocality'] );
	}

	public function test_enrich_noop_without_stored_profile(): void {
		$node = array( '@type' => 'Organization', 'name' => 'X' );
		$this->assertSame( $node, ( new Seonix_Business_Profile() )->enrich_organization( $node ) );
	}

	// ─── llms block ──────────────────────────────────────────────────────

	public function test_llms_block_renders_quotable_facts(): void {
		Seonix_Business_Profile::store_from_request( $this->complete_profile() );
		$block = Seonix_Business_Profile::llms_block();
		$this->assertStringContainsString( '## Business', $block );
		$this->assertStringContainsString( 'WohnArt Studio', $block );
		$this->assertStringContainsString( 'Birkenwäldchen 6, 16515 Oranienburg, Brandenburg, DE', $block );
		$this->assertStringContainsString( '+491628907726', $block );
		$this->assertStringContainsString( 'Service area: Berlin, Oranienburg', $block );
	}

	public function test_llms_block_empty_without_profile(): void {
		$this->assertSame( '', Seonix_Business_Profile::llms_block() );
	}
}
