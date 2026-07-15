<?php
/**
 * Fix method: agent_accessibility.
 *
 * Flips the site-wide `seonix_agent_a11y_enabled` option to true, which turns on
 * Seonix_Agent_Accessibility's render-time filters. Those give an accessible name
 * to interactive elements that reach the accessibility tree without one — the
 * `link-name` and `select-name` failures behind Chrome Lighthouse's
 * "Agentic Browsing" → `agent-accessibility-tree` audit.
 *
 * WHY AN OPTION FLAG RATHER THAN A CONTENT REWRITE
 * -----------------------------------------------
 * The offending elements do not exist in post_content and so cannot be rewritten
 * there. Spectra's `<a class="spectra-container-link-overlay">` is emitted by the
 * block's render callback from block attributes; Contact Form 7's `<select>` is
 * expanded from a shortcode at request time. post_content holds only the block
 * delimiter and the `[contact-form-7]` shortcode. The per-post regex model used
 * by `image_alt` / `broken_link` has nothing to bite on — the fix has to happen
 * as the page renders, so the option is the whole fix. See the class docblock on
 * Seonix_Agent_Accessibility for the filter design and its safety contract.
 *
 * The option is the single source of truth and is read on every request, so the
 * flip takes effect on the very next page load with no per-post mutation, no
 * stored-content migration, and nothing to re-run when new pages are added. That
 * also makes rollback total: clearing the option removes every injected
 * attribute everywhere, because none of them were ever persisted.
 *
 * Idempotent: re-apply on a site where the option is already true returns no_op.
 * dry_run never mutates state.
 *
 * No AI involvement — params are empty by design. The Seonix backend emits one
 * task per scan with a single `site_url` for attribution; the executor does the
 * rest.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Agent_Accessibility implements Seonix_Fix_Method {

	protected Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'agent_accessibility';
	}

	/**
	 * Params shape is intentionally minimal: { site_url?: string }. site_url is
	 * informational only (the dashboard attributes the apply to the right host)
	 * and is not required, so accepting an empty array still validates.
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
	 * Always available: the fix writes an option this plugin owns and is consumed
	 * by filters this plugin registers. Unlike the Yoast-backed fixes there is no
	 * third-party plugin whose absence would leave the value orphaned — the
	 * anchor half works on any block theme, and the Contact Form 7 half simply
	 * never fires when CF7 is not installed.
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

		// Restoring the option is the whole rollback: apply() only ever wrote the
		// option, and the filters it gates never persisted anything.
		return array(
			'before' => array( 'value' => $after ),
			'after'  => array( 'value' => $before ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function read_setting(): bool {
		return (bool) get_option( Seonix_Agent_Accessibility::OPTION, false );
	}

	/**
	 * Persist the flag and verify it landed.
	 *
	 * update_option() returns false both when the write fails AND when the value
	 * was already identical, so its return value cannot distinguish the two. We
	 * re-read instead — the same approach the pagination-noindex fix takes. The
	 * caller has already ruled out the no-op case before we get here.
	 *
	 * @return true|\WP_Error
	 */
	private function write_setting( bool $value ) {
		update_option( Seonix_Agent_Accessibility::OPTION, $value ? 1 : 0 );

		if ( (bool) get_option( Seonix_Agent_Accessibility::OPTION, false ) !== $value ) {
			return new WP_Error(
				'update_failed',
				'Could not persist ' . Seonix_Agent_Accessibility::OPTION . '.',
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
				'key'  => Seonix_Agent_Accessibility::OPTION,
			),
		);
	}
}
