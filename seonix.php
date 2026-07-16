<?php
/**
 * Plugin Name: Seonix SEO
 * Description: AI search visibility out of the box — llms.txt and IndexNow work without an account. Connect Seonix for site audits inside WordPress, AI-written articles, one-click technical fixes, and publishing on autopilot.
 * Version:     2.11.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author:      Seonix
 * Author URI:  https://seonix.ai
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seonix
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEONIX_VERSION', '2.11.0' );
define( 'SEONIX_FILE', __FILE__ );
define( 'SEONIX_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEONIX_URL', plugin_dir_url( __FILE__ ) );

require_once SEONIX_DIR . 'includes/class-seonix-compat.php';
require_once SEONIX_DIR . 'includes/class-seonix-auth.php';
require_once SEONIX_DIR . 'includes/class-seonix-indexnow.php';
require_once SEONIX_DIR . 'includes/class-seonix-sync.php';
require_once SEONIX_DIR . 'includes/class-seonix-tasks.php';
// Loaded before the REST API: the /score route calls Seonix_Content_Score
// statically, so a missing include is a fatal on the editor panel's every
// keystroke rather than a degraded score.
require_once SEONIX_DIR . 'includes/class-seonix-content-score.php';
require_once SEONIX_DIR . 'includes/class-seonix-rest-api.php';
require_once SEONIX_DIR . 'includes/class-seonix-admin.php';
require_once SEONIX_DIR . 'includes/class-seonix-admin-shell.php';
require_once SEONIX_DIR . 'includes/class-seonix-onboarding.php';
require_once SEONIX_DIR . 'includes/class-seonix-metabox.php';
require_once SEONIX_DIR . 'includes/class-seonix-admin-bar.php';
require_once SEONIX_DIR . 'includes/class-seonix-llmtxt.php';
require_once SEONIX_DIR . 'includes/class-seonix-schema.php';

// Agent-facing render-time annotators (Chrome Lighthouse "Agentic Browsing").
// Both are inert until their option is flipped by the matching SEO Fix method.
// Seonix_WebMCP reuses Seonix_Agent_Accessibility's static HTML primitives, so
// the a11y class is loaded first.
require_once SEONIX_DIR . 'includes/class-seonix-agent-accessibility.php';
require_once SEONIX_DIR . 'includes/class-seonix-webmcp.php';

// Native redirect manager: storage, front-end runner, REST sync, admin UI.
// Loaded before the SEO Fix methods because the redirect fix writes into the
// redirects store.
require_once SEONIX_DIR . 'includes/redirects/class-seonix-redirects-store.php';
require_once SEONIX_DIR . 'includes/redirects/class-seonix-redirects-runner.php';
require_once SEONIX_DIR . 'includes/redirects/class-seonix-redirects-watcher.php';
require_once SEONIX_DIR . 'includes/redirects/class-seonix-redirects-controller.php';
require_once SEONIX_DIR . 'includes/redirects/class-seonix-redirects-admin.php';

// SEO Fix subsystem.
require_once SEONIX_DIR . 'includes/seo-fix/interface-seonix-fix-method.php';
require_once SEONIX_DIR . 'includes/seo-fix/class-seonix-seo-engine.php';

// SEO meta bridge: canonical _seonix_* store + fan-out to the active SEO
// plugin(s), standalone head renderer, and reverse sync back to Seonix.
// Loaded right after the engine detector it builds on.
require_once SEONIX_DIR . 'includes/seo-meta/class-seonix-meta-bridge.php';
require_once SEONIX_DIR . 'includes/seo-meta/class-seonix-meta-renderer.php';
require_once SEONIX_DIR . 'includes/seo-meta/class-seonix-meta-watcher.php';
require_once SEONIX_DIR . 'includes/seo-fix/class-seonix-seo-fix-registry.php';
require_once SEONIX_DIR . 'includes/seo-fix/class-seonix-seo-fix-history.php';
require_once SEONIX_DIR . 'includes/seo-fix/class-seonix-cache-purger.php';
require_once SEONIX_DIR . 'includes/seo-fix/class-seonix-seo-fix-controller.php';

// SEO Fix methods.
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-ssl-mixed-content.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-redirect.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-broken-link.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-single-meta.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-meta-title.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-meta-description.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-term-meta-description.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-image-alt.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-pagination-noindex.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-agent-accessibility.php';
require_once SEONIX_DIR . 'includes/seo-fix/methods/class-seonix-fix-agent-webmcp.php';

/**
 * Initialize the plugin on plugins_loaded.
 */
function seonix_init() {
	// Note: load_plugin_textdomain() is intentionally NOT called here. Since
	// WordPress 4.6, translations for WordPress.org-hosted plugins are loaded
	// automatically (see Plugin Check warning
	// PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound).
	// The /languages/seonix.pot file still ships for translators.

	// Safety net: migrate legacy ce_* state for sites that received the new code
	// via file overwrite without running the activation hook. Idempotent.
	Seonix_Compat::migrate_legacy_options();

	$sync            = new Seonix_Sync();
	$tasks           = new Seonix_Tasks();
	$rest_api        = new Seonix_REST_API( $sync, $tasks );
	$redirects_store = new Seonix_Redirects_Store();
	$metabox         = new Seonix_Metabox( $tasks );
	$schema          = new Seonix_Schema();

	// One shell for every Seonix screen — the top bar and the Site Health /
	// Redirects / Settings tabs. It takes the store because the Redirects tab
	// carries a count of the rules being served.
	$admin_shell = new Seonix_Admin_Shell( $redirects_store );
	$admin_shell->register();
	$admin       = new Seonix_Admin( $sync, $tasks, $admin_shell );

	// Version-gated DB upgrade: when the stored db version differs from the
	// plugin version, (re)apply dbDelta for the plugin tables. dbDelta is
	// idempotent, so this safely covers both fresh installs that missed the
	// activation hook (file-overwrite updates) and future schema changes.
	$db_version = get_option( 'seonix_db_version', '' );
	if ( $db_version !== SEONIX_VERSION ) {
		$tasks->create_table();
		$redirects_store->create_table();
		update_option( 'seonix_db_version', SEONIX_VERSION );

		// Flush stale opcode cache for every plugin PHP file so updated code
		// (e.g. the menu icon / admin render) takes effect immediately after an
		// update on hosts with an aggressive opcache that would otherwise keep
		// serving the previous bytecode until the cached entry expires or PHP
		// restarts. Best-effort and never fatal — guarded with @ and a
		// function_exists() check (opcache may be disabled or the function
		// blacklisted via disable_functions).
		if ( function_exists( 'opcache_invalidate' ) ) {
			$plugin_root = plugin_dir_path( SEONIX_FILE );
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file ) {
					if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
						@opcache_invalidate( $file->getPathname(), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; opcache may refuse individual files.
					}
				}
			} catch ( \Exception $e ) {
				// Unreadable directory or similar — leave the cache as-is; the
				// next natural cache cycle / PHP restart picks up the new code.
				unset( $e );
			}
		}
	}

	// REST API routes.
	add_action( 'rest_api_init', array( $rest_api, 'register_routes' ) );

	// Admin menu and assets.
	add_action( 'admin_menu', array( $admin, 'add_menu_page' ) );
	add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );

	// Post-activation onboarding: one-time redirect to the Seonix screen and a
	// dismissible connect notice (admin requests only; admin-ajax is is_admin).
	if ( is_admin() ) {
		Seonix_Onboarding::register();
	}

	// Per-page audit meta box in the post editor (Yoast-style).
	$metabox->register();

	// Toolbar node: the page's scores + issue count wherever the owner already
	// is, the live site included. Reads what the editor and the scan already
	// produced; adds no work of its own.
	$admin_bar = new Seonix_Admin_Bar( $tasks );
	$admin_bar->register();

	// Front-end stylesheet for content blocks injected by the plugin
	// (currently the key-takeaways callout). Loaded on singular post pages
	// only — themes that override `the_content` for other surfaces can
	// pull the same file in manually.
	add_action( 'wp_enqueue_scripts', 'seonix_enqueue_content_styles' );

	// Emit the article's JSON-LD structured data in <head>. In the default
	// "auto" mode this self-suppresses when a dedicated SEO plugin is active,
	// so it never duplicates Yoast/Rank Math/AIOSEO schema.
	add_action( 'wp_head', array( $schema, 'render_head' ) );

	// Standalone SEO meta output (title / description / OG / Twitter). Same
	// auto/on/off contract as the schema emitter: in "auto" it renders ONLY
	// when no dedicated SEO plugin owns <head>, so tags are never duplicated.
	$meta_renderer = new Seonix_Meta_Renderer();
	$meta_renderer->register();

	// Reverse SEO-meta sync: site-owner edits in Yoast/Rank Math/AIOSEO/
	// SEOPress/TSF flow back into Seonix (managed posts only), plus the
	// one-time backfill when an SEO plugin is activated later.
	$meta_watcher = new Seonix_Meta_Watcher( $sync );
	$meta_watcher->register();

	// Admin AJAX handlers.
	add_action( 'wp_ajax_seonix_save_author', array( $admin, 'ajax_save_author' ) );
	add_action( 'wp_ajax_seonix_save_schema_mode', array( $admin, 'ajax_save_schema_mode' ) );
	add_action( 'wp_ajax_seonix_save_meta_mode', array( $admin, 'ajax_save_meta_mode' ) );
	add_action( 'wp_ajax_seonix_regenerate_key', array( $admin, 'ajax_regenerate_key' ) );
	add_action( 'wp_ajax_seonix_sync_now', array( $admin, 'ajax_sync_now' ) );
	add_action( 'wp_ajax_seonix_get_api_key', array( $admin, 'ajax_get_api_key' ) );
	add_action( 'wp_ajax_seonix_refresh_tasks', array( $admin, 'ajax_refresh_tasks' ) );
	add_action( 'wp_ajax_seonix_connect_url', array( $admin, 'ajax_connect_url' ) );
	// Plan + project deep links for the "Open in Seonix" buttons, the plan
	// badge, and the paid-AI upsell. Proxies GET /api/plugin/account with the
	// plugin Bearer key so the key never reaches the browser.
	add_action( 'wp_ajax_seonix_account', array( $admin, 'ajax_account' ) );
	// One-click SEO fix. Proxies POST /api/plugin/seo-fix with the plugin Bearer
	// key; the backend gates it on a paid subscription and runs the same fix
	// orchestration as the dashboard.
	add_action( 'wp_ajax_seonix_seo_fix', array( $admin, 'ajax_seo_fix' ) );
	// IndexNow auto-submit toggle (standalone feature card on Settings /
	// the unconnected Dashboard).
	add_action( 'wp_ajax_seonix_save_indexnow', array( $admin, 'ajax_save_indexnow' ) );

	// Content sync hooks.
	add_action( 'save_post', array( $sync, 'on_save_post' ), 10, 3 );
	add_action( 'before_delete_post', array( $sync, 'on_delete_post' ) );

	// Tell the backend which plugin version this site runs, on every outbound
	// Seonix API call. Registered globally because `http_request_args` is a
	// global filter; the callback itself only touches Seonix API URLs.
	add_filter( 'http_request_args', array( 'Seonix_Sync', 'stamp_plugin_version' ), 10, 2 );

	// Serve the IndexNow verification key at its root URL (/{key}.txt).
	add_action( 'template_redirect', array( 'Seonix_IndexNow', 'serve_key' ) );

	// Weekly full sync via WP cron.
	add_action( 'seonix_weekly_sync', array( $sync, 'push_full_sync' ) );

	// Flush rewrite rules on plugin version update (so new rules take effect without deactivate/reactivate).
	// Deferred to `init` because $wp_rewrite is not initialised during plugins_loaded
	// in WP-CLI's bootstrap order, which would crash the version migration there.
	$stored_version = get_option( 'seonix_version', '' );
	if ( $stored_version !== SEONIX_VERSION ) {
		add_action( 'init', function () {
			update_option( 'seonix_version', SEONIX_VERSION );
			$llmtxt_flush = new Seonix_LLMTxt();
			$llmtxt_flush->register_rewrites();
			flush_rewrite_rules();
		}, 99 );
	}

	// LLMs.txt: dynamic serving with proper headers. No static files are
	// written anywhere — rewrite rules + handle_request build the body on
	// every request from get_posts()/get_terms() (with ETag/304 support).
	$llmtxt = new Seonix_LLMTxt();
	add_action( 'init', array( $llmtxt, 'register_rewrites' ) );
	add_filter( 'query_vars', array( $llmtxt, 'register_query_vars' ) );
	// Priority 0 so the body is served BEFORE WordPress's redirect_canonical
	// (default priority 10) can 301 /llms.txt to /llms.txt/ — a slashed URL no
	// rewrite rule matches. The redirect_canonical filter is a second guard.
	add_action( 'template_redirect', array( $llmtxt, 'handle_request' ), 0 );
	add_filter( 'redirect_canonical', array( $llmtxt, 'prevent_canonical_redirect' ), 10, 2 );

	// Agent-accessibility repairs: name the interactive elements that reach the
	// a11y tree unnamed (Spectra container-link overlays, Contact Form 7 selects),
	// which is what Chrome Lighthouse's agent-accessibility-tree audit fails a
	// page on. Render-time filters only — the offending markup is generated by
	// block render callbacks / shortcodes and never exists in post_content, so
	// there is nothing to rewrite there. Gated on the option the
	// `agent_accessibility` fix method flips; off until then.
	if ( get_option( Seonix_Agent_Accessibility::OPTION, false ) ) {
		$agent_a11y = new Seonix_Agent_Accessibility();
		$agent_a11y->register();
	}

	// Declarative WebMCP annotations: describe forms to AI agents via toolname /
	// tooldescription / toolparamdescription attributes. Markup only — no
	// navigator.modelContext registration, no JavaScript — so the attributes are
	// inert in browsers without WebMCP support. Gated on the option the
	// `agent_webmcp` fix method flips; off until then.
	if ( get_option( Seonix_WebMCP::OPTION, false ) ) {
		$webmcp = new Seonix_WebMCP();
		$webmcp->register();
	}

	// SEO Fix subsystem: registry + controller + REST routes.
	$seo_fix_registry   = seonix_seo_fix_build_registry();
	$seo_fix_history    = new Seonix_SEO_Fix_History();
	$seo_fix_controller = new Seonix_SEO_Fix_Controller( $seo_fix_registry, $seo_fix_history );
	add_action( 'rest_api_init', array( $seo_fix_controller, 'register_routes' ) );

	// Native redirect manager: front-end execution, REST sync for the Seonix
	// service, and the wp-admin Redirects screen.
	$redirects_runner = new Seonix_Redirects_Runner( $redirects_store );
	$redirects_runner->register();

	$redirects_controller = new Seonix_Redirects_Controller( $redirects_store );
	add_action( 'rest_api_init', array( $redirects_controller, 'register_routes' ) );

	$redirects_admin = new Seonix_Redirects_Admin( $redirects_store, $admin_shell );
	$redirects_admin->register();

	// Renaming or trashing a published post silently breaks every link to it.
	// WordPress only half-answers the rename case and not the trash case at all,
	// so we record both as real, visible rules.
	$redirects_watcher = new Seonix_Redirects_Watcher( $redirects_store );
	$redirects_watcher->register();
}

/**
 * Build the registry of available SEO fix methods.
 * Each new method gets one line here.
 */
function seonix_seo_fix_build_registry(): Seonix_SEO_Fix_Registry {
	$registry = new Seonix_SEO_Fix_Registry();
	$history  = new Seonix_SEO_Fix_History();

	$registry->register( new Seonix_Fix_SSL_Mixed_Content( $history ) );
	$registry->register( new Seonix_Fix_Redirect( $history ) );
	$registry->register( new Seonix_Fix_Broken_Link( $history ) );
	$registry->register( new Seonix_Fix_Meta_Title( $history ) );
	$registry->register( new Seonix_Fix_Meta_Description( $history ) );
	$registry->register( new Seonix_Fix_Term_Meta_Description( $history ) );
	$registry->register( new Seonix_Fix_Image_Alt( $history ) );
	$registry->register( new Seonix_Fix_Pagination_Noindex( $history ) );
	$registry->register( new Seonix_Fix_Agent_Accessibility( $history ) );
	$registry->register( new Seonix_Fix_Agent_WebMCP( $history ) );

	return $registry;
}

add_action( 'plugins_loaded', 'seonix_init' );

/**
 * Enqueue front-end styles for Seonix-injected content blocks on singular
 * post pages. Cheap (~1 KB) so we don't gate it on content sniffing — that
 * would force a full post_content scan on every request.
 */
function seonix_enqueue_content_styles() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	wp_enqueue_style(
		'seonix-content',
		SEONIX_URL . 'assets/seonix-content.css',
		array(),
		SEONIX_VERSION
	);
}

/**
 * Add a "Dashboard" link on the Plugins list page.
 *
 * The menu moved from Settings → Seonix (options-general.php) to a top-level
 * menu (admin.php?page=seonix) in 2.5.0, so the action link points there.
 */
function seonix_action_links( $links ) {
	$dashboard_link = '<a href="' . esc_url( admin_url( 'admin.php?page=seonix' ) ) . '">'
		. esc_html__( 'Dashboard', 'seonix' ) . '</a>';
	array_unshift( $links, $dashboard_link );
	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'seonix_action_links' );

/**
 * Plugin activation: generate API key, schedule cron.
 */
function seonix_activate() {
	// Migrate any legacy ce_* options from a previous Content Engine Connector install.
	Seonix_Compat::migrate_legacy_options();

	// Generate API key if one does not exist yet.
	if ( ! get_option( Seonix_Auth::OPTION_API_KEY ) ) {
		Seonix_Auth::generate_key();
	}

	// Schedule weekly sync.
	if ( ! wp_next_scheduled( 'seonix_weekly_sync' ) ) {
		wp_schedule_event( time(), 'weekly', 'seonix_weekly_sync' );
	}

	// Register rewrite rules and flush so they take effect immediately.
	$llmtxt = new Seonix_LLMTxt();
	$llmtxt->register_rewrites();
	flush_rewrite_rules();

	// Clean up any leftover static llms.txt / llm.txt files from earlier
	// plugin versions (pre-2.4.1 used to write to the WP root). Safe no-op
	// on fresh installs and on hosts where get_home_path() is unavailable.
	// Uses the WP-documented `wp-admin/includes/file.php` import pattern to
	// pull in get_home_path() — same approach as Yoast SEO's WP_Filesystem
	// adapter.
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( function_exists( 'get_home_path' ) ) {
		$seonix_home = get_home_path();
		$legacy_files = array( 'llms.txt', 'llms-full.txt', 'llm.txt', 'llm-full.txt' );
		foreach ( $legacy_files as $legacy_file ) {
			$candidate = trailingslashit( $seonix_home ) . $legacy_file;
			if ( file_exists( $candidate ) ) {
				wp_delete_file( $candidate );
			}
		}
	}

	// Install SEO fix history table.
	$seo_fix_history = new Seonix_SEO_Fix_History();
	$seo_fix_history->create_table();

	// Install the redirects table (native redirect manager).
	$redirects_store = new Seonix_Redirects_Store();
	$redirects_store->create_table();

	// Install the local tasks table and stamp the schema version so the
	// version-gated upgrade in seonix_init() doesn't redundantly re-run dbDelta
	// on the next page load.
	$tasks = new Seonix_Tasks();
	$tasks->create_table();
	update_option( 'seonix_db_version', SEONIX_VERSION );

	// Onboarding: flag the one-time redirect to the Seonix screen (consumed on
	// the next admin page load; Seonix_Onboarding skips it for bulk activation)
	// and — only while the site is not yet connected — the dismissible connect
	// notice. Reactivating an already-connected site never nags.
	update_option( Seonix_Onboarding::REDIRECT_OPTION, 1 );
	if ( ! get_option( 'seonix_connected' ) ) {
		update_option( Seonix_Onboarding::NOTICE_OPTION, 1 );
	}
}

register_activation_hook( __FILE__, 'seonix_activate' );

/**
 * Plugin deactivation: clear cron.
 */
function seonix_deactivate() {
	wp_clear_scheduled_hook( 'seonix_weekly_sync' );
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'seonix_deactivate' );

/**
 * Plugin uninstall: remove all seonix_* options (and any leftover ce_* legacy keys).
 */
function seonix_uninstall() {
	$options = array(
		'seonix_api_key',
		'seonix_post_author',
		'seonix_engine_url',
		'seonix_project_id',
		'seonix_project_name',
		'seonix_connected',
		'seonix_connected_at',
		'seonix_last_synced_at',
		'seonix_sync_counts',
		'seonix_sync_pages',
		'seonix_sync_posts',
		'seonix_sync_products',
		'seonix_indexnow_key',
		'seonix_indexnow_auto',
		'seonix_indexnow_last',
		'seonix_version',
		'seonix_db_version',
		'seonix_llmstxt_last_generated',
		'seonix_llmstxt_content_hash',
		'seonix_tasks_summary',
		'seonix_tasks_synced_at',
		'seonix_migrated_from_ce',
		'seonix_activation_redirect',
		'seonix_activation_notice',
		'seonix_agent_a11y_enabled',
		'seonix_webmcp_enabled',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Best-effort cleanup of the auto-pull single-flight lock (a transient that
	// would expire on its own within minutes anyway) and the compiled redirect
	// map cache.
	delete_transient( 'seonix_tasks_pull_lock' );
	delete_transient( 'seonix_redirects_map' );

	// Drop the tables created on activation.
	// %i is the WordPress placeholder for SQL identifiers (table / column
	// names) introduced in WordPress 6.2. It correctly quotes the identifier
	// for whichever database engine is running and clears Plugin Check's
	// `PluginCheck.Security.DirectDB.UnescapedDBParameter` sniff.
	global $wpdb;
	foreach ( array( 'seonix_seo_fix_history', 'seonix_tasks', 'seonix_redirects' ) as $table_suffix ) {
		$table = $wpdb->prefix . $table_suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall-time schema drop; identifier is correctly escaped via the %i placeholder.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	// Clean up the IndexNow key file we wrote under wp-content/uploads/seonix/
	// (since 2.4.1) plus any leftover legacy file in the WP root from earlier
	// versions. The legacy cleanup uses get_home_path() — the WP-documented
	// way to resolve the WP root without hard-coding ABSPATH.
	$indexnow_key = get_option( 'seonix_indexnow_key', '' );
	$upload_dir   = wp_upload_dir();
	if ( is_string( $indexnow_key ) && '' !== $indexnow_key && empty( $upload_dir['error'] ) ) {
		$indexnow_path = trailingslashit( $upload_dir['basedir'] ) . 'seonix/' . $indexnow_key . '.txt';
		if ( file_exists( $indexnow_path ) ) {
			wp_delete_file( $indexnow_path );
		}
	}

	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( function_exists( 'get_home_path' ) ) {
		$seonix_home  = get_home_path();
		$static_files = array( 'llms.txt', 'llms-full.txt', 'llm.txt', 'llm-full.txt' );
		foreach ( $static_files as $file ) {
			$path = trailingslashit( $seonix_home ) . $file;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}

register_uninstall_hook( __FILE__, 'seonix_uninstall' );
