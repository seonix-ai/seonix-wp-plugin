<?php
/**
 * Sitewide business-entity enrichment from the Seonix business profile.
 *
 * The Seonix backend ships the project's LocalBusiness NAP (name/address/
 * phone, service area — entered by the operator in Project DNA, never
 * AI-invented) inside every WordPress publish payload as `business_profile`.
 * This class stores it and uses it in two places:
 *
 *  1. Yoast's sitewide Organization node (`wpseo_schema_organization`) is
 *     enriched into the site's ONE business entity — multi-typed with the
 *     LocalBusiness subtype and given telephone/address/areaServed. Without
 *     this, NAP data only lives on per-post nodes while the sitewide entity
 *     stays a bare Organization — and sites accumulate parallel business
 *     entities that Google and AI assistants read as different businesses
 *     (wohnartstudio.de audit 2026-07-29, P0-2: three unconnected entities).
 *     Enrichment is ADDITIVE: Yoast's own name/logo/sameAs are never touched.
 *
 *  2. llms.txt gets a "## Business" facts block (NAP + service area), so AI
 *     crawlers can quote the business's identity without parsing HTML.
 *
 * Sync contract: a publish payload with a COMPLETE profile (phone AND a
 * street or city — same gate as the backend's per-post LocalBusiness node)
 * refreshes the stored copy; an explicitly EMPTY profile ({}) means the
 * project no longer carries usable NAP and deletes the stored copy; an ABSENT
 * key (older backend) changes nothing.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the backend-provided business profile and enriches sitewide schema.
 */
class Seonix_Business_Profile {

	const OPTION = 'seonix_business_profile';

	/**
	 * schema.org LocalBusiness subtypes this plugin will multi-type the
	 * Organization node with. Mirrors the backend allowlist — anything else
	 * clamps to the generic "LocalBusiness" so a hostile payload can never
	 * inject an arbitrary @type.
	 */
	const ALLOWED_TYPES = array(
		'LocalBusiness',
		'HomeAndConstructionBusiness',
		'MovingCompany',
		'GeneralContractor',
		'ProfessionalService',
		'Store',
	);

	/**
	 * Upper bound on areaServed entries — mirrors the backend cap.
	 */
	const MAX_AREAS = 20;

	/**
	 * Register the schema enrichment hooks. The stored profile is only read
	 * inside the callbacks, so registration is free when no profile exists.
	 *
	 * Engine coverage: Yoast (per-node filter) and Rank Math (whole-graph
	 * filter). AIOSEO builds its graph through its own models with no
	 * comparable public node filter, so AIOSEO sites keep per-post nodes only.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wpseo_schema_organization', array( $this, 'enrich_organization' ) );
		add_filter( 'rank_math/json_ld', array( $this, 'enrich_rank_math' ), 20 );
	}

	/**
	 * Validate and normalize a raw `business_profile` payload value.
	 *
	 * Returns the sanitized profile array when it passes the completeness
	 * gate (phone AND a street or city), or null for anything else — callers
	 * decide what null means (store_from_request treats an empty/incomplete
	 * ARRAY as "delete the stored copy").
	 *
	 * @param mixed $raw Raw request value.
	 * @return array|null Sanitized profile or null.
	 */
	public static function sanitize( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$text = static function ( $key ) use ( $raw ): string {
			if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
				return '';
			}
			$value = sanitize_text_field( $raw[ $key ] );
			// NAP fields are short human strings; 200 chars is generous and
			// caps a hostile payload.
			return mb_substr( trim( $value ), 0, 200 );
		};

		$profile = array(
			'business_type'  => $text( 'business_type' ),
			'phone'          => $text( 'phone' ),
			'email'          => $text( 'email' ),
			'street_address' => $text( 'street_address' ),
			'city'           => $text( 'city' ),
			'postal_code'    => $text( 'postal_code' ),
			'region'         => $text( 'region' ),
			'country'        => $text( 'country' ),
			'area_served'    => array(),
		);

		if ( ! in_array( $profile['business_type'], self::ALLOWED_TYPES, true ) ) {
			$profile['business_type'] = 'LocalBusiness';
		}

		if ( isset( $raw['area_served'] ) && is_array( $raw['area_served'] ) ) {
			foreach ( $raw['area_served'] as $area ) {
				if ( ! is_string( $area ) ) {
					continue;
				}
				$area = mb_substr( trim( sanitize_text_field( $area ) ), 0, 100 );
				if ( '' === $area || in_array( $area, $profile['area_served'], true ) ) {
					continue;
				}
				$profile['area_served'][] = $area;
				if ( count( $profile['area_served'] ) >= self::MAX_AREAS ) {
					break;
				}
			}
		}

		// Completeness gate — mirrors the backend's per-post LocalBusiness
		// gate so the sitewide entity and post nodes always agree.
		if ( '' === $profile['phone'] || ( '' === $profile['street_address'] && '' === $profile['city'] ) ) {
			return null;
		}

		return $profile;
	}

	/**
	 * Apply a publish payload's `business_profile` value to the stored copy.
	 *
	 * - null / not provided (older backend): keep whatever is stored.
	 * - array that sanitizes to a complete profile: store it.
	 * - array that does NOT pass the gate (including {}): delete the stored
	 *   copy — the backend sends {} precisely to say "no usable NAP anymore".
	 *
	 * @param mixed $raw Raw request value.
	 * @return void
	 */
	public static function store_from_request( $raw ): void {
		if ( null === $raw ) {
			return;
		}
		if ( ! is_array( $raw ) ) {
			return;
		}
		$profile = self::sanitize( $raw );
		if ( null === $profile ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $profile, false );
	}

	/**
	 * Read the stored profile, re-validated through the same gate so a
	 * hand-edited or corrupted option can never emit incomplete markup.
	 *
	 * @return array|null
	 */
	public static function stored(): ?array {
		$raw = get_option( self::OPTION );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		return self::sanitize( $raw );
	}

	/**
	 * wpseo_schema_organization filter: upgrade the Organization node into the
	 * business entity. Additive only — existing keys Yoast owns (name, logo,
	 * sameAs, url, @id) are never overwritten, so the node keeps its identity
	 * and merges cleanly with the per-post LocalBusiness nodes that reuse the
	 * same @id.
	 *
	 * @param array $data Yoast's Organization graph piece.
	 * @return array
	 */
	public function enrich_organization( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		// Kill switch for operators/support: add_filter( 'seonix_business_entity_enabled', '__return_false' );
		if ( ! apply_filters( 'seonix_business_entity_enabled', true ) ) {
			return $data;
		}
		$profile = self::stored();
		if ( null === $profile ) {
			return $data;
		}
		return self::apply_profile( $data, $profile );
	}

	/**
	 * rank_math/json_ld filter: same enrichment for Rank Math sites. Rank
	 * Math hands the WHOLE graph (an array of entities); the sitewide
	 * business node is the entity typed Organization whose @id carries the
	 * `#organization` fragment (Rank Math's Knowledge-Graph "Organization"
	 * mode). Person-mode sites have no such node and pass through untouched.
	 * Enrichment stays strictly additive, same as the Yoast path.
	 *
	 * @param mixed $data Rank Math's entity array (entity-key => node).
	 * @return mixed
	 */
	public function enrich_rank_math( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( ! apply_filters( 'seonix_business_entity_enabled', true ) ) {
			return $data;
		}
		$profile = self::stored();
		if ( null === $profile ) {
			return $data;
		}

		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( ! in_array( 'Organization', $types, true ) ) {
				continue;
			}
			$id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
			// Only the SITEWIDE entity — never per-post publisher copies or
			// unrelated Organization mentions.
			if ( '' === $id || false === strpos( $id, '#organization' ) ) {
				continue;
			}
			$data[ $key ] = self::apply_profile( $node, $profile );
			break;
		}
		return $data;
	}

	/**
	 * The shared, strictly-additive enrichment: multi-type the node with the
	 * LocalBusiness subtype and fill telephone/email/address/areaServed —
	 * ONLY where the engine left the key empty. Keys the SEO plugin owns
	 * (name, logo, sameAs, url, @id) are never overwritten, so the node
	 * keeps its identity and merges cleanly with per-post nodes reusing the
	 * same @id.
	 *
	 * @param array $data    Schema node to enrich.
	 * @param array $profile Sanitized business profile.
	 * @return array
	 */
	private static function apply_profile( array $data, array $profile ): array {
		$types = isset( $data['@type'] ) ? (array) $data['@type'] : array( 'Organization' );
		if ( ! in_array( $profile['business_type'], $types, true ) ) {
			$types[] = $profile['business_type'];
		}
		$data['@type'] = array_values( $types );

		if ( empty( $data['telephone'] ) ) {
			$data['telephone'] = $profile['phone'];
		}
		if ( empty( $data['email'] ) && '' !== $profile['email'] ) {
			$data['email'] = $profile['email'];
		}

		if ( empty( $data['address'] ) ) {
			$address = array( '@type' => 'PostalAddress' );
			foreach ( array(
				'street_address' => 'streetAddress',
				'city'           => 'addressLocality',
				'postal_code'    => 'postalCode',
				'region'         => 'addressRegion',
			) as $key => $schema_key ) {
				if ( '' !== $profile[ $key ] ) {
					$address[ $schema_key ] = $profile[ $key ];
				}
			}
			if ( '' !== $profile['country'] ) {
				$address['addressCountry'] = strtoupper( $profile['country'] );
			}
			$data['address'] = $address;
		}

		if ( empty( $data['areaServed'] ) && ! empty( $profile['area_served'] ) ) {
			$served = array();
			foreach ( $profile['area_served'] as $area ) {
				$served[] = array(
					'@type' => 'AdministrativeArea',
					'name'  => $area,
				);
			}
			$data['areaServed'] = $served;
		}

		return $data;
	}

	/**
	 * "## Business" facts block for llms.txt — the business's identity in a
	 * form AI crawlers can quote directly. Empty string when no profile.
	 *
	 * @return string
	 */
	public static function llms_block(): string {
		$profile = self::stored();
		if ( null === $profile ) {
			return '';
		}

		$address_parts = array_filter( array(
			$profile['street_address'],
			trim( $profile['postal_code'] . ' ' . $profile['city'] ),
			$profile['region'],
			strtoupper( $profile['country'] ),
		) );

		$block = "## Business\n\n";
		$block .= '- Name: ' . get_bloginfo( 'name' ) . "\n";
		if ( ! empty( $address_parts ) ) {
			$block .= '- Address: ' . implode( ', ', $address_parts ) . "\n";
		}
		$block .= '- Phone: ' . $profile['phone'] . "\n";
		if ( '' !== $profile['email'] ) {
			$block .= '- Email: ' . $profile['email'] . "\n";
		}
		if ( ! empty( $profile['area_served'] ) ) {
			$block .= '- Service area: ' . implode( ', ', $profile['area_served'] ) . "\n";
		}
		return $block . "\n";
	}
}
