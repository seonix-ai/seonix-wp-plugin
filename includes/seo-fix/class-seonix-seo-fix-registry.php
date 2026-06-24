<?php
/**
 * Holds the catalog of available SEO-fix methods.
 *
 * The plugin bootstraps a single registry, wires the built-in methods into it,
 * and exposes it to the REST controller. The /capabilities endpoint reads from
 * here so the Seonix backend knows which fixes are runnable on this site
 * (e.g. the redirect method only becomes available once the Redirection plugin
 * is active).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_SEO_Fix_Registry {

	/**
	 * @var array<string, Seonix_Fix_Method>
	 */
	private $methods = array();

	/**
	 * Register a fix method. If a method with the same key is already registered
	 * it is replaced — last wins. The plugin bootstraps deterministically so this
	 * is intentional (no surprises in production), but useful in tests.
	 */
	public function register( Seonix_Fix_Method $method ): void {
		$this->methods[ $method->key() ] = $method;
	}

	public function get( string $key ): ?Seonix_Fix_Method {
		return $this->methods[ $key ] ?? null;
	}

	public function has( string $key ): bool {
		return isset( $this->methods[ $key ] );
	}

	/**
	 * @return string[]
	 */
	public function list_keys(): array {
		return array_keys( $this->methods );
	}

	/**
	 * Capability descriptor consumed by the /capabilities REST endpoint.
	 * Returns one entry per registered method. A method advertises an
	 * environmental requirement (e.g. meta_title/meta_description need an active
	 * SEO plugin that stores the field in postmeta) by implementing an optional
	 * `is_available(): bool` method; methods that don't are always available.
	 * The backend ANDs this with its own recommendation so the dashboard never
	 * shows a "Fix with AI" button for a fix this site can't actually run.
	 *
	 * @return array<string, array{available: bool}>
	 */
	public function capabilities(): array {
		$caps = array();
		foreach ( $this->methods as $key => $method ) {
			$available = true;
			if ( is_callable( array( $method, 'is_available' ) ) ) {
				$available = (bool) $method->is_available();
			}
			$caps[ $key ] = array(
				'available' => $available,
			);
		}
		return $caps;
	}
}
