<?php
/**
 * Backward-compatibility layer for sites upgrading from the legacy
 * "Content Engine Connector" plugin (ce_* options, content-engine/v1 namespace,
 * X-CE-Key header, ce_weekly_sync cron).
 *
 * Migration runs once on activation and once on plugins_loaded as a safety net
 * for sites where new code lands without re-activation (e.g. file overwrite).
 * Idempotent: gated by the MIGRATION_FLAG option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Compat {

	const MIGRATION_FLAG = 'seonix_migrated_from_ce';

	/**
	 * Map of legacy option name → new option name.
	 */
	const OPTION_MAP = array(
		'ce_api_key'                => 'seonix_api_key',
		'ce_post_author'            => 'seonix_post_author',
		'ce_engine_url'             => 'seonix_engine_url',
		'ce_project_id'             => 'seonix_project_id',
		'ce_project_name'           => 'seonix_project_name',
		'ce_connected'              => 'seonix_connected',
		'ce_connected_at'           => 'seonix_connected_at',
		'ce_last_synced_at'         => 'seonix_last_synced_at',
		'ce_sync_counts'            => 'seonix_sync_counts',
		'ce_sync_pages'             => 'seonix_sync_pages',
		'ce_sync_posts'             => 'seonix_sync_posts',
		'ce_sync_products'          => 'seonix_sync_products',
		'ce_indexnow_key'           => 'seonix_indexnow_key',
		'ce_connector_version'      => 'seonix_version',
		'ce_llmstxt_last_generated' => 'seonix_llmstxt_last_generated',
		'ce_llmstxt_content_hash'   => 'seonix_llmstxt_content_hash',
	);

	/**
	 * Migrate legacy ce_* options to their seonix_* equivalents.
	 *
	 * Behavior:
	 *   - Skips entirely if already migrated (MIGRATION_FLAG set).
	 *   - Never overwrites a new option that already has a value.
	 *   - Deletes the legacy option after successful copy.
	 *   - Reschedules the weekly cron under the new hook name.
	 *
	 * @return int Number of options migrated.
	 */
	public static function migrate_legacy_options() {
		if ( get_option( self::MIGRATION_FLAG ) ) {
			return 0;
		}

		// Sensitive options that must never sit in the autoload cache. Mirrors
		// the autoload=false guarantees provided by Seonix_Auth::generate_key()
		// and the IndexNow setup flow so a sideways migration doesn't downgrade
		// the security posture.
		$sensitive_keys = array( 'seonix_api_key', 'seonix_indexnow_key' );

		$migrated = 0;
		foreach ( self::OPTION_MAP as $old_key => $new_key ) {
			// Defensive: do not overwrite an already-set new value.
			$existing_new = get_option( $new_key, null );
			if ( $existing_new !== null ) {
				// New value present — drop legacy if it exists, preserve new.
				delete_option( $old_key );
				continue;
			}

			$value = get_option( $old_key, null );
			if ( $value === null ) {
				continue;
			}

			$autoload = in_array( $new_key, $sensitive_keys, true ) ? false : null;
			update_option( $new_key, $value, $autoload );
			delete_option( $old_key );
			$migrated++;
		}

		// Reschedule weekly cron under the new hook name.
		$legacy_ts = wp_next_scheduled( 'ce_weekly_sync' );
		if ( $legacy_ts ) {
			wp_unschedule_event( $legacy_ts, 'ce_weekly_sync' );
		}
		if ( ! wp_next_scheduled( 'seonix_weekly_sync' ) ) {
			wp_schedule_event( time(), 'weekly', 'seonix_weekly_sync' );
		}

		update_option( self::MIGRATION_FLAG, gmdate( 'c' ) );

		return $migrated;
	}
}
