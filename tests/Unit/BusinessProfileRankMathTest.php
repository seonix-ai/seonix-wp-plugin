<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Business_Profile;

/**
 * Rank Math adapter for the sitewide business entity: the whole-graph
 * `rank_math/json_ld` filter must enrich ONLY the sitewide Organization node
 * (the entity whose @id carries `#organization`), stay strictly additive,
 * and pass Person-mode graphs through untouched. Mirrors the Yoast-path
 * guarantees pinned in BusinessProfileTest.
 */
final class BusinessProfileRankMathTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	private Seonix_Business_Profile $profile;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->profile = new Seonix_Business_Profile();

		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => is_string( $value ) ? trim( strip_tags( $value ) ) : ''
		);
		Functions\when( 'get_option' )->alias(
			fn ( $key, $default = false ) => $this->options[ $key ] ?? $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_profile(): void {
		$this->options[ Seonix_Business_Profile::OPTION ] = array(
			'business_type'  => 'LocalBusiness',
			'phone'          => '+31201234567',
			'email'          => 'info@example.nl',
			'street_address' => 'Keizersgracht 1',
			'city'           => 'Amsterdam',
			'postal_code'    => '1015 CC',
			'region'         => '',
			'country'        => 'nl',
			'area_served'    => array( 'Amsterdam', 'Haarlem' ),
		);
	}

	private function rank_math_graph(): array {
		return array(
			'WebSite'   => array(
				'@type' => 'WebSite',
				'@id'   => 'https://example.nl/#website',
				'name'  => 'Example',
			),
			'publisher' => array(
				'@type' => 'Organization',
				'@id'   => 'https://example.nl/#organization',
				'name'  => 'Example',
				'logo'  => array( '@type' => 'ImageObject', 'url' => 'https://example.nl/logo.png' ),
				'url'   => 'https://example.nl/',
			),
		);
	}

	public function test_enriches_sitewide_organization_only(): void {
		$this->seed_profile();

		$out = $this->profile->enrich_rank_math( $this->rank_math_graph() );

		$org = $out['publisher'];
		$this->assertContains( 'LocalBusiness', $org['@type'] );
		$this->assertContains( 'Organization', $org['@type'] );
		$this->assertSame( '+31201234567', $org['telephone'] );
		$this->assertSame( 'Keizersgracht 1', $org['address']['streetAddress'] );
		$this->assertSame( 'NL', $org['address']['addressCountry'] );
		$this->assertCount( 2, $org['areaServed'] );
		// Engine-owned keys stay untouched.
		$this->assertSame( 'Example', $org['name'] );
		$this->assertSame( 'https://example.nl/logo.png', $org['logo']['url'] );
		// Other entities pass through byte-identical.
		$this->assertSame( $this->rank_math_graph()['WebSite'], $out['WebSite'] );
	}

	public function test_additive_never_overwrites_existing_values(): void {
		$this->seed_profile();
		$graph = $this->rank_math_graph();
		$graph['publisher']['telephone'] = '+31 999 already-set';
		$graph['publisher']['address']   = array( '@type' => 'PostalAddress', 'addressLocality' => 'Rotterdam' );

		$out = $this->profile->enrich_rank_math( $graph );

		$this->assertSame( '+31 999 already-set', $out['publisher']['telephone'] );
		$this->assertSame( 'Rotterdam', $out['publisher']['address']['addressLocality'] );
	}

	public function test_person_mode_graph_untouched(): void {
		$this->seed_profile();
		$graph = array(
			'publisher' => array(
				'@type' => 'Person',
				'@id'   => 'https://example.nl/#person',
				'name'  => 'Jane Doe',
			),
		);

		$this->assertSame( $graph, $this->profile->enrich_rank_math( $graph ) );
	}

	public function test_per_post_organization_without_sitewide_id_untouched(): void {
		$this->seed_profile();
		$graph = array(
			'mention' => array(
				'@type' => 'Organization',
				'@id'   => 'https://partner.example.com/#org',
				'name'  => 'Some partner',
			),
		);

		$this->assertSame( $graph, $this->profile->enrich_rank_math( $graph ) );
	}

	public function test_no_stored_profile_is_a_noop(): void {
		$graph = $this->rank_math_graph();
		$this->assertSame( $graph, $this->profile->enrich_rank_math( $graph ) );
	}

	public function test_kill_switch_disables_enrichment(): void {
		$this->seed_profile();
		Monkey\Filters\expectApplied( 'seonix_business_entity_enabled' )
			->andReturn( false );

		$graph = $this->rank_math_graph();
		$this->assertSame( $graph, $this->profile->enrich_rank_math( $graph ) );
	}

	public function test_non_array_input_passes_through(): void {
		$this->assertNull( $this->profile->enrich_rank_math( null ) );
	}
}
