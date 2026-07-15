<?php
/**
 * Fix method: agent_webmcp.
 *
 * Flips the site-wide `seonix_webmcp_enabled` option to true, which turns on
 * Seonix_WebMCP's render-time filters. Those add the declarative WebMCP
 * attributes (`toolname` / `tooldescription` on a form, `toolparamdescription` on
 * its fields) that let an AI agent use the site's forms as tools instead of
 * reverse-engineering the DOM.
 *
 * WHY AN OPTION FLAG RATHER THAN A CONTENT REWRITE
 * -----------------------------------------------
 * Same structural reason as the agent_accessibility fix: the markup being
 * annotated is generated at render time (Contact Form 7 expands a shortcode; the
 * core search block renders from block attributes), so it is not present in
 * post_content and the per-post rewrite model cannot reach it. The option is the
 * whole fix; the filters do the work on each request.
 *
 * WHY THIS IS SAFE TO SHIP
 * ------------------------
 * The attributes are inert. A browser with no WebMCP implementation ignores
 * unknown attributes entirely — nothing renders, nothing validates differently,
 * submission is unchanged. Only the declarative half of the proposal is emitted;
 * we register nothing via `navigator.modelContext` and add no JavaScript to the
 * page. Rollback is total for the same reason as the sibling fix: nothing is
 * persisted into content, so clearing the option removes every attribute.
 *
 * Idempotent: re-apply on a site where the option is already true returns no_op.
 * dry_run never mutates state.
 *
 * No AI involvement — params are empty by design.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Agent_WebMCP implements Seonix_Fix_Method {

	protected Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'agent_webmcp';
	}

	/**
	 * Params shape is intentionally minimal: { site_url?: string }. site_url is
	 * informational only and is not required, so an empty array validates.
	 */
	public function validate_params( array $params ) {
		if ( isset( $params['site_url'] ) && ! is_string( $params['site_url'] ) ) {
			return new WP_Error( 'invalid_site_url', 'site_url, when provided, must be a string.', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		unset( $params );
		return $this->describe_result( $this->read_setting(), true );
	}

	/**
	 * Always available: the option is ours and the filters are ours. With no form
	 * plugin installed the filters simply never fire.
	 */
	public function is_available(): bool {
		return true;
	}

	public function apply( array $params ) {
		unset( $params );

		$current = $this->read_setting();
		$result  = $this->describe_result( $current, true );

		if ( $result['no_op'] ) {
			return $result;
		}

		$written = $this->write_setting( true );
		if ( $written instanceof WP_Error ) {
			return $written;
		}

		return $result;
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

		return array(
			'before' => array( 'value' => $after ),
			'after'  => array( 'value' => $before ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function read_setting(): bool {
		return (bool) get_option( Seonix_WebMCP::OPTION, false );
	}

	/**
	 * Persist the flag and verify by re-reading — update_option() returns false
	 * for both "failed" and "value unchanged", so its return value cannot be
	 * trusted as a success signal.
	 *
	 * @return true|\WP_Error
	 */
	private function write_setting( bool $value ) {
		update_option( Seonix_WebMCP::OPTION, $value ? 1 : 0 );

		if ( (bool) get_option( Seonix_WebMCP::OPTION, false ) !== $value ) {
			return new WP_Error(
				'update_failed',
				'Could not persist ' . Seonix_WebMCP::OPTION . '.',
				array( 'status' => 500 )
			);
		}
		return true;
	}

	private function describe_result( bool $current, bool $target ): array {
		return array(
			'before' => array( 'value' => $current ),
			'after'  => array( 'value' => $target ),
			'no_op'  => ( $current === $target ),
			'target' => array(
				'type' => 'option',
				'key'  => Seonix_WebMCP::OPTION,
			),
		);
	}
}
