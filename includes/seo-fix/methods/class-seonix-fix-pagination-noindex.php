<?php
/**
 * Fix method: yoast_setting_pagination_noindex.
 *
 * Flips the SEO plugin's site-wide `noindex-subpages-wpseo` option (stored under
 * `wpseo_titles`) to `true`, so paginated archive subpages (/category/x/page/2,
 * /page/3, …) render with a `noindex, follow` robots tag. Industry-standard
 * SEO advice for paginated archives — they duplicate the canonical archive
 * page's content and shouldn't compete with it in search results.
 *
 * Why this needs its own method on top of the option flip:
 *
 *   The SEO indexable layer (v14+) renders robots tags from the
 *   `wp_yoast_indexable` table, not the options API. Flipping the option alone
 *   leaves every existing term/archive indexable row with its old per-row
 *   `is_robots_noindex` value, so the live /page/2 HTML keeps rendering
 *   `index, follow` until the SEO plugin's cron rebuild touches each row. We
 *   can't wait for that. So after writing the option we null out
 *   `is_robots_noindex` on term-type indexables, which makes the indexable
 *   layer fall through to the global default we just set. (Setting to `1`
 *   directly would also work, but `NULL` is the "inherit from global" sentinel
 *   and future UI changes won't surprise the site owner.)
 *
 * Idempotent: re-apply on a site where the option is already true and no
 * stray indexable rows remain returns no_op. dry_run never mutates state.
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
		$current = $this->read_setting();
		return $this->describe_result( $current, true );
	}

	public function apply( array $params ) {
		if ( ! $this->is_target_seo_plugin_active() ) {
			return new WP_Error(
				'seo_plugin_inactive',
				'This fix requires the Yoast SEO plugin to be active (it edits a Yoast-owned option / indexable row).',
				array( 'status' => 412 )
			);
		}

		$current = $this->read_setting();
		$result  = $this->describe_result( $current, true );

		if ( $result['no_op'] ) {
			return $result;
		}

		$written = $this->write_setting( true );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		$this->force_rebuild_term_indexables();

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

		// On rollback we don't try to restore per-row is_robots_noindex —
		// the SEO plugin's cron reconciliation will recompute them from the
		// option. Surfacing partial restores would be misleading.
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

	/**
	 * The SEO indexable layer (v14+) renders the robots meta from
	 * `wp_yoast_indexable`. After flipping the global `noindex-subpages-wpseo`,
	 * existing term/archive indexable rows still carry whatever
	 * `is_robots_noindex` value was computed when the row was last touched —
	 * usually NULL or 0 — so the live /page/2 HTML keeps shipping
	 * `index, follow` until cron catches up.
	 *
	 * We nullify the column on term-type rows so the renderer falls through
	 * to the global default on the next request. This is intentionally cheap
	 * (one UPDATE, no per-row PHP) and idempotent: re-running on a clean table
	 * affects 0 rows. Cache flush ensures any object-cache layers in front
	 * of the indexable table re-fetch.
	 *
	 * Silent best-effort: the table is optional on installs without the
	 * Indexables module, in which case the UPDATE no-ops and we don't surface
	 * an error.
	 */
	private function force_rebuild_term_indexables(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . 'yoast_indexable';

		$prev_suppress_errors = method_exists( $wpdb, 'suppress_errors' )
			? $wpdb->suppress_errors( true )
			: false;

		// Null out is_robots_noindex for term archive indexables only.
		// Post indexables and home/static-page indexables are untouched —
		// their robots state is governed by other options.
		// $table is internal ($wpdb->prefix . 'yoast_indexable') so direct
		// interpolation is safe; placeholders do not support identifiers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET is_robots_noindex = NULL WHERE object_type = %s",
				'term'
			)
		);

		if ( method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( $prev_suppress_errors );
		}

		// Flush the per-indexable object cache so the next request reads
		// the cleared rows from DB. Group name matches the indexable layer's
		// convention.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'yoast_indexables' );
		} elseif ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
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
