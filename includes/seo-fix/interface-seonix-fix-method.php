<?php
/**
 * Contract every SEO-fix method must implement.
 *
 * Methods are stateless executors: the registry holds one instance per fix type
 * and the controller calls dry_run / apply / rollback on demand. Anything that
 * needs persistence (history, backups) is the controller's concern.
 *
 * Return shapes:
 *   validate_params : true | WP_Error
 *   dry_run         : array{ before:mixed, after:mixed, diff:string, target:array, no_op?:bool }
 *                     | WP_Error
 *   apply           : array{ history_id:int, before:mixed, after:mixed, status:string }
 *                     | WP_Error
 *                     where status ∈ { 'applied', 'already_applied' }
 *   rollback        : array{ before:mixed, after:mixed }
 *                     | WP_Error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Seonix_Fix_Method {

	/**
	 * Stable identifier for this fix method (e.g. 'broken_link', 'ssl_mixed_content').
	 * Must match the SEO issue code emitted by the Seonix scanner.
	 */
	public function key(): string;

	/**
	 * Validate the params payload.
	 *
	 * @param array $params
	 * @return true|\WP_Error
	 */
	public function validate_params( array $params );

	/**
	 * Compute what would change without applying anything.
	 * Pure function — must NOT mutate WP state.
	 *
	 * @param array $params
	 * @return array|\WP_Error
	 */
	public function dry_run( array $params );

	/**
	 * Apply the fix to the WP site.
	 * MUST be idempotent: re-applying the same params after success is a no-op
	 * and returns status='already_applied'.
	 *
	 * @param array $params
	 * @return array|\WP_Error
	 */
	public function apply( array $params );

	/**
	 * Restore the previous state recorded in the history entry.
	 *
	 * @param int $history_id
	 * @return array|\WP_Error
	 */
	public function rollback( int $history_id );
}
