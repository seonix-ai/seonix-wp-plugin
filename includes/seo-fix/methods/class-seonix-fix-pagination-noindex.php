<?php
/**
 * Fix method: yoast_setting_pagination_noindex.
 *
 * Flips Yoast SEO's site-wide `noindex-subpages-wpseo` option (stored under
 * `wpseo_titles`) to `true`, so paginated archive subpages (/category/x/page/2,
 * /page/3, …) render with a `noindex, follow` robots tag. Industry-standard
 * SEO advice for paginated archives — they duplicate the canonical archive
 * page's content and shouldn't compete with it in search results.
 *
 * The option is the single source of truth. Yoast's robots presenter applies
 * the subpage noindex at request time — it checks `noindex-subpages-wpseo`
 * together with `is_paged()` when it renders the robots meta — so flipping the
 * option takes effect on the very next page load, with no per-row indexable
 * mutation required.
 *
 * We deliberately do NOT touch `wp_yoast_indexable.is_robots_noindex`. That
 * column holds each term's OWN (page-1) noindex state; nulling it across all
 * term rows would erase deliberate per-term noindex overrides the owner set by
 * hand — and it wouldn't affect subpage robots anyway, since those are computed
 * from the option at render time, not read from the indexable row. An earlier
 * version ran that UPDATE (plus a full object-cache flush); both were removed as
 * destructive and unnecessary.
 *
 * Idempotent: re-apply on a site where the option is already true returns
 * no_op. dry_run never mutates state.
 *
 * No AI involvement — params are empty by design. The Seonix backend emits
 * one task per scan with a single `site_url` for attribution; the executor
 * does the rest.
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
		if ( ! $this->is_target_seo_plugin_active() ) {
			return new WP_Error(
				'seo_plugin_inactive',
				'This fix requires the Yoast SEO plugin to be active (it edits a Yoast-owned option).',
				array( 'status' => 412 )
			);
		}
		$current = $this->read_setting();
		return $this->describe_result( $current, true );
	}

	/**
	 * Advertised to the /capabilities handshake: only offer this fix when Yoast
	 * (whose option it writes) is active, so the dashboard never surfaces a fix
	 * that apply()/dry_run() would refuse with 412.
	 */
	public function is_available(): bool {
		return $this->is_target_seo_plugin_active();
	}

	public function apply( array $params ) {
		if ( ! $this->is_target_seo_plugin_active() ) {
			return new WP_Error(
				'seo_plugin_inactive',
				'This fix requires the Yoast SEO plugin to be active (it edits a Yoast-owned option).',
				array( 'status' => 412 )
			);
		}

		$current = $this->read_setting();
		$result  = $this->describe_result( $current, true );

		if ( $result['no_op'] ) {
			return $result;
		}

		// The option flip is the entire fix: Yoast's robots presenter reads
		// `noindex-subpages-wpseo` at render time (gated on is_paged()), so the
		// change is live on the next request. We intentionally do not mutate
		// wp_yoast_indexable — see the class docblock.
		$written = $this->write_setting( true );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		return $result;
	}

	/**
	 * Guard: this fix writes to `wpseo_titles`, which is an option that is
	 * created, validated and consumed by the Yoast SEO plugin. We only touch
	 * it when Yoast SEO is actually installed and active — otherwise the
	 * value has no consumer and would just be orphan data in the DB.
	 */
	private function is_target_seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' );
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
	 * Read the current `noindex-subpages-wpseo` value from Yoast SEO's public
	 * option API (`WPSEO_Options::get`). Returns false when Yoast is inactive
	 * or the key hasn't been set yet — both equivalent to "subpages are still
	 * indexed" semantically.
	 *
	 * Using the Yoast getter (rather than `get_option('wpseo_titles')` and
	 * manually picking the key out of the array) means our read goes through
	 * Yoast's own option-group routing and validation cache, and lets us
	 * avoid referencing the `wpseo_titles` option name directly from outside
	 * Yoast.
	 */
	private function read_setting(): bool {
		if ( ! class_exists( 'WPSEO_Options' ) || ! is_callable( array( 'WPSEO_Options', 'get' ) ) ) {
			return false;
		}
		return (bool) call_user_func( array( 'WPSEO_Options', 'get' ), 'noindex-subpages-wpseo', false );
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

	private function describe_result( bool $current, bool $target ): array {
		$is_no_op = ( $current === $target );
		return array(
			'before' => array( 'value' => $current ),
			'after'  => array( 'value' => $target ),
			'no_op'  => $is_no_op,
			'target' => array(
				'type' => 'option',
				'key'  => 'wpseo_titles.noindex-subpages-wpseo',
			),
		);
	}
}
