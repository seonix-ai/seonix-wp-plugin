<?php
/**
 * Fix method: yoast_setting_pagination_noindex. LEGACY — retired, rollback only.
 *
 * Flipped Yoast SEO's site-wide `noindex-subpages-wpseo` option (stored under
 * `wpseo_titles`) to `true`, meant to render paginated archive subpages
 * (/category/x/page/2, /page/3, …) with a `noindex, follow` robots tag.
 *
 * It never did: Yoast dropped that setting in 6.3 (2018) — its upgrade routine
 * removed the key and no Yoast code reads it anymore. WPSEO_Options::set() still
 * stores it (the `noindex-` prefix is one of `wpseo_titles`' variable-key
 * patterns, which validation keeps), so apply() reported success while nothing
 * on the rendered page changed. The advice itself is outdated too: Google wants
 * paginated pages indexable, and the scanner now reports the opposite case as
 * pagination_not_indexable (fixed by clear_pagination_noindex).
 *
 * So the method no longer runs: dry_run() and apply() answer 422
 * no_supported_seo_plugin — the code the backend records as a cleanly skipped
 * item — for runs queued before the retirement or sent by an older backend, and
 * is_available() keeps it out of the /capabilities handshake. It stays
 * registered only so history entries recorded by earlier versions can still be
 * rolled back, which writes the previous value back through Yoast's public
 * option API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Pagination_Noindex implements Seonix_Fix_Method {

	protected Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'yoast_setting_pagination_noindex';
	}

	/**
	 * Params shape is intentionally minimal: { site_url?: string }. site_url
	 * is informational only (the dashboard attributes the apply to the right
	 * host) and is not required, so accepting an empty array still validates.
	 */
	public function validate_params( array $params ) {
		if ( isset( $params['site_url'] ) && ! is_string( $params['site_url'] ) ) {
			return new WP_Error( 'invalid_site_url', 'site_url, when provided, must be a string.', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		unset( $params );
		return self::retired_error();
	}

	/**
	 * Never advertised to the /capabilities handshake: the key it writes has no
	 * effect (see the class docblock). Registered for rollback only.
	 */
	public function is_available(): bool {
		return false;
	}

	public function apply( array $params ) {
		unset( $params );
		return self::retired_error();
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$before = isset( $entry['before_state']['value'] ) ? (bool) $entry['before_state']['value'] : false;
		$after  = isset( $entry['after_state']['value'] ) ? (bool) $entry['after_state']['value'] : true;

		$written = $this->write_setting( $before );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		// Restoring the option is the whole rollback — apply() only ever wrote
		// the option, never per-row indexable state, so there is nothing else
		// to undo.
		return array(
			'before' => array( 'value' => $after ),
			'after'  => array( 'value' => $before ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * 422 with the code the backend maps to a cleanly skipped item (the same
	 * contract the robots-noindex methods use for an unsupported stack).
	 */
	private static function retired_error(): WP_Error {
		return new WP_Error(
			Seonix_Robots_Noindex::UNSUPPORTED_REASON,
			'yoast_setting_pagination_noindex is retired: the setting it writes has had no effect since Yoast SEO 6.3.',
			array(
				'status'             => 422,
				'applied'            => false,
				'unsupported_reason' => Seonix_Robots_Noindex::UNSUPPORTED_REASON,
			)
		);
	}

	/**
	 * Write `noindex-subpages-wpseo` via Yoast SEO's public setter
	 * `WPSEO_Options::set( $key, $value )`. The setter routes the write to
	 * the correct option group, preserves every other key in the option,
	 * and runs Yoast's own validation. We never touch `wpseo_titles`
	 * directly with `update_option` — the option is owned by Yoast and we
	 * use its API.
	 *
	 * @return true|\WP_Error
	 */
	private function write_setting( bool $value ) {
		if ( ! class_exists( 'WPSEO_Options' ) || ! is_callable( array( 'WPSEO_Options', 'set' ) ) ) {
			return new WP_Error(
				'yoast_api_unavailable',
				'Yoast SEO public option API (WPSEO_Options::set) is unavailable.',
				array( 'status' => 412 )
			);
		}

		call_user_func( array( 'WPSEO_Options', 'set' ), 'noindex-subpages-wpseo', $value );

		// Verify the value landed. Yoast's setter is void; re-read through the
		// same public getter so the check honours their cache + validation.
		$reread = (bool) call_user_func( array( 'WPSEO_Options', 'get' ), 'noindex-subpages-wpseo', false );
		if ( $reread !== $value ) {
			return new WP_Error( 'update_failed', 'WPSEO_Options::set did not persist noindex-subpages-wpseo.', array( 'status' => 500 ) );
		}
		return true;
	}
}
