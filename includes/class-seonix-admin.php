<?php
/**
 * Admin UI for Seonix.
 *
 * Registers a top-level "Seonix" menu with two screens:
 *   - Dashboard (first submenu, slug `seonix`)   — site-health tasks, rendered
 *     entirely from the LOCAL tasks table (no API call per page view) plus a
 *     one-click "Connect to Seonix" handoff and a manual "Refresh tasks" pull.
 *   - Settings (submenu, slug `seonix-settings`)  — API key, post author, site
 *     data sync, connection info (the previous Settings → Seonix page).
 *
 * Handles AJAX actions for saving the author preference, regenerating the API
 * key, triggering a content sync, and refreshing the task list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Admin {

	/** Top-level + Dashboard menu slug. */
	const MENU_SLUG = 'seonix';

	/** Settings submenu slug. */
	const SETTINGS_SLUG = 'seonix-settings';

	/** @var Seonix_Sync|null */
	private $sync;

	/** @var Seonix_Tasks */
	private $tasks;

	public function __construct( Seonix_Sync $sync = null, Seonix_Tasks $tasks = null ) {
		$this->sync  = $sync;
		$this->tasks = $tasks ?? new Seonix_Tasks();
	}

	/**
	 * Register the top-level Seonix menu and its submenus.
	 *
	 * The Dashboard reuses the parent slug so it is the first (default) submenu;
	 * Settings is a second submenu. Position 58 drops the menu just under the
	 * Comments group, above the Appearance block.
	 */
	public function add_menu_page() {
		add_menu_page(
			'Seonix',
			'Seonix',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			$this->menu_icon(),
			58
		);

		// Problems — same slug as the parent so it becomes the default submenu
		// and the parent label doesn't duplicate as "Seonix → Seonix".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Problems', 'seonix' ),
			__( 'Problems', 'seonix' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		// Settings — the former Settings → Seonix screen.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'seonix' ),
			__( 'Settings', 'seonix' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Base64 data-URI for the menu icon: the REAL Seonix favicon, pixel for pixel.
	 *
	 * assets/menu-icon.png is the brand favicon (frontend favicon-512.png) scaled
	 * to 64px — enough for the 20px CSS slot at 3x DPR. It is embedded as an
	 * <image> inside a tiny SVG wrapper rather than passed to add_menu_page()
	 * directly, for two reasons:
	 *
	 * 1. WordPress only treats `data:image/svg+xml;base64,` icons as inline
	 *    background images; any other data URI is piped through esc_url(),
	 *    which strips the `data:` scheme entirely.
	 * 2. Core's svg-painter.js repaints every base64 SVG menu icon to the admin
	 *    colour scheme by regex-replacing fill="..." / style="..." attributes —
	 *    that is what previously flattened the hand-drawn vector version into a
	 *    solid white square. The <image> wrapper carries no paint attributes at
	 *    all, so the painter has nothing to repaint and the brand colours
	 *    survive in every menu state.
	 *
	 * @return string The data URI (or a dashicon name if the asset is unreadable).
	 */
	private function menu_icon(): string {
		$png = file_get_contents( SEONIX_DIR . 'assets/menu-icon.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset, not a remote request.
		if ( false === $png ) {
			return 'dashicons-admin-site-alt3';
		}
		// base64 here assembles a data: URI for the bundled admin-menu icon
		// (a PNG embedded in an SVG wrapper) — not code obfuscation.
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 64 64">'
			. '<image width="64" height="64" href="data:image/png;base64,' . base64_encode( $png ) . '"/>'
			. '</svg>';
		$data_uri = 'data:image/svg+xml;base64,' . base64_encode( $svg );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $data_uri;
	}

	/**
	 * Enqueue admin assets on the Seonix Dashboard and Settings screens only.
	 *
	 * Top-level page hook = `toplevel_page_seonix`; the Settings submenu hook is
	 * `seonix_page_seonix-settings` (WordPress derives subpage hooks from the
	 * sanitised parent slug). Loading on both keeps every Seonix screen styled
	 * and scripted while staying off every other admin page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$seonix_hooks = array(
			'toplevel_page_' . self::MENU_SLUG,
			'seonix_page_' . self::SETTINGS_SLUG,
		);
		if ( ! in_array( $hook, $seonix_hooks, true ) ) {
			return;
		}

		// Brand webfonts (Inter Tight + JetBrains Mono). Admin-only; the CSS
		// falls back to the system stack if these fail to load, so the panel is
		// never blocked on a remote request.
		wp_enqueue_style(
			'seonix-admin-fonts',
			SEONIX_URL . 'assets/fonts/fonts.css',
			array(),
			SEONIX_VERSION
		);

		wp_enqueue_style(
			'seonix-admin',
			SEONIX_URL . 'assets/admin.css',
			array( 'seonix-admin-fonts' ),
			SEONIX_VERSION
		);

		wp_enqueue_script(
			'seonix-admin',
			SEONIX_URL . 'assets/admin.js',
			array(),
			SEONIX_VERSION,
			true
		);

		wp_localize_script( 'seonix-admin', 'seonixConnector', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'seonix' ),
			'refreshNonce'  => wp_create_nonce( 'seonix_refresh_tasks' ),
			'connectNonce'  => wp_create_nonce( 'seonix_connect' ),
			'accountNonce'  => wp_create_nonce( 'seonix_account' ),
			'fixNonce'      => wp_create_nonce( 'seonix_seo_fix' ),
			'i18n'          => array(
				'refreshing'     => __( 'Refreshing…', 'seonix' ),
				'tasksRefreshed' => __( 'Tasks refreshed.', 'seonix' ),
				'refreshFailed'  => __( 'Failed to refresh tasks.', 'seonix' ),
				'networkError'   => __( 'Network error.', 'seonix' ),
				'connecting'     => __( 'Connecting…', 'seonix' ),
				'connectFailed'  => __( 'Could not start the connection. Please try again.', 'seonix' ),
				// Modal status-pill labels (Feature B). The JS picks one by the
				// clicked row's data-status; page rows use the neutral "Page" pill.
				'activeTask'     => __( 'Active task', 'seonix' ),
				'cameBack'       => __( 'Came back', 'seonix' ),
				'fixed'          => __( 'Fixed', 'seonix' ),
				'pageLabel'      => __( 'Page', 'seonix' ),
				// Plan card (filled from GET /api/plugin/account).
				'planActiveSub'  => __( 'AI features are active — generate, refine and auto-publish from Seonix or right here.', 'seonix' ),
				'planFreeSub'    => __( 'This project is on the Free plan. Upgrade to unlock AI generation, refinement and one-click SEO fixes.', 'seonix' ),
				'planError'      => __( 'Could not load your plan.', 'seonix' ),
				'planFree'       => __( 'Free', 'seonix' ),
				// One-click SEO fix (task modal). The fix runs through the backend,
				// which gates it on a paid subscription (402 → upgrade popup).
				'fixing'         => __( 'Fixing…', 'seonix' ),
				'fixApplied'     => __( 'Fix applied. It will clear on the next scan.', 'seonix' ),
				'fixPartial'     => __( 'Some items were fixed; the rest need attention. It will refine on the next scan.', 'seonix' ),
				'fixNothingApplied' => __( 'Nothing could be applied automatically — this one needs a manual look.', 'seonix' ),
				'fixFailed'      => __( 'Could not apply the fix.', 'seonix' ),
				'fixPaywall'     => __( 'An active subscription is required to apply fixes.', 'seonix' ),
				// IndexNow toggle on the standalone feature card.
				'indexnowSaved'    => __( 'IndexNow setting saved.', 'seonix' ),
				'indexnowEnabled'  => __( 'Enabled', 'seonix' ),
				'indexnowDisabled' => __( 'Disabled', 'seonix' ),
				'saveFailed'       => __( 'Failed to save setting.', 'seonix' ),
			),
		) );
	}

	// ─── AJAX Handlers ───────────────────────────────────────────

	/**
	 * AJAX: Save the post author setting.
	 */
	public function ajax_save_author() {
		check_ajax_referer( 'seonix', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$author_id = isset( $_POST['author_id'] ) ? absint( $_POST['author_id'] ) : 0;

		if ( $author_id > 0 ) {
			$user = get_user_by( 'id', $author_id );
			if ( ! $user || ! $user->has_cap( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid user selected.', 'seonix' ) ) );
			}
		}

		update_option( 'seonix_post_author', $author_id );

		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * AJAX: Save the JSON-LD structured-data output mode (auto|on|off).
	 */
	public function ajax_save_schema_mode() {
		check_ajax_referer( 'seonix', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'auto';
		if ( ! in_array( $mode, array( 'auto', 'on', 'off' ), true ) ) {
			$mode = 'auto';
		}

		update_option( Seonix_Schema::OPTION_MODE, $mode );

		wp_send_json_success( array( 'saved' => true, 'mode' => $mode ) );
	}

	/**
	 * AJAX: Save the standalone SEO meta output mode (auto|on|off).
	 */
	public function ajax_save_meta_mode() {
		check_ajax_referer( 'seonix', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'auto';
		if ( ! in_array( $mode, array( 'auto', 'on', 'off' ), true ) ) {
			$mode = 'auto';
		}

		update_option( Seonix_Meta_Renderer::OPTION_MODE, $mode );

		wp_send_json_success( array( 'saved' => true, 'mode' => $mode ) );
	}

	/**
	 * AJAX: Regenerate the API key.
	 */
	public function ajax_regenerate_key() {
		check_ajax_referer( 'seonix', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$key = Seonix_Auth::generate_key();

		// Reset connected state since the old key is now invalid.
		update_option( 'seonix_connected', false );
		delete_option( 'seonix_connected_at' );

		wp_send_json_success( array(
			'key'         => $key,
			'key_preview' => substr( $key, 0, 11 ) . str_repeat( '*', 20 ),
		) );
	}

	/**
	 * AJAX: Return the full API key on demand for the Copy button.
	 *
	 * Replaces the previous flow that emitted the key into a hidden DOM input,
	 * which exposed it to any browser extension or third-party admin script
	 * that scrapes the page. The key now lives only in PHP until the operator
	 * explicitly clicks "Copy"; the JS clears it from memory once it lands in
	 * the clipboard.
	 */
	public function ajax_get_api_key() {
		check_ajax_referer( 'seonix_get_api_key', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$key = Seonix_Auth::get_key();

		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key has not been configured.', 'seonix' ) ), 404 );
		}

		wp_send_json_success( array( 'key' => $key ) );
	}

	/**
	 * AJAX: Trigger a full site data sync.
	 */
	public function ajax_sync_now() {
		check_ajax_referer( 'seonix', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		if ( ! $this->sync ) {
			wp_send_json_error( array( 'message' => __( 'Sync not available.', 'seonix' ) ) );
		}

		$result = $this->sync->push_full_sync();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$counts = get_option( 'seonix_sync_counts', array() );

		wp_send_json_success( array(
			'synced'         => true,
			'last_synced_at' => get_option( 'seonix_last_synced_at', '' ),
			'counts'         => $counts,
		) );
	}

	/**
	 * AJAX: Pull the latest tasks from the connected Seonix backend.
	 *
	 * This is the on-demand fallback to the push-on-scan path: it server-side
	 * GETs {engine}/api/plugin/tasks with the plugin's Bearer key and replaces
	 * the local copy. Capability + nonce gated like every other admin action.
	 */
	public function ajax_refresh_tasks() {
		check_ajax_referer( 'seonix_refresh_tasks', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$result = $this->tasks->pull_from_engine();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'synced_at' => $this->tasks->synced_at(),
		) );
	}

	/**
	 * AJAX: Mint a fresh one-click connect URL on demand.
	 *
	 * The connect URL carries a one-time nonce as a fragment. Baking it into the
	 * rendered Dashboard HTML would (a) emit a short-lived secret into the page
	 * on every view and (b) double-escape via esc_url(). Minting it just-in-time
	 * from this capability + nonce gated handler keeps the secret out of the
	 * page and lets build_connect_url() escape the base URL exactly once.
	 */
	public function ajax_connect_url() {
		check_ajax_referer( 'seonix_connect', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		if ( ! Seonix_Sync::is_safe_url( home_url() ) ) {
			wp_send_json_error( array( 'message' => __( 'Your site URL is not publicly reachable.', 'seonix' ) ) );
		}

		wp_send_json_success( array( 'url' => $this->build_connect_url() ) );
	}

	// ─── Connect handoff ─────────────────────────────────────────

	/**
	 * Build the one-click "Connect to Seonix" URL and mint its one-time nonce.
	 *
	 * Called only from ajax_connect_url() (just-in-time), never from a render
	 * method — so the one-time secret is never baked into the Dashboard HTML.
	 * Mints a fresh 48-char nonce, stores its SHA-256 in a 15-minute transient
	 * (so the raw secret never lands on disk), and appends
	 * the raw nonce as a URL FRAGMENT (#nonce=…). The fragment never reaches the
	 * server or a Referer header, keeping the one-time secret out of access
	 * logs. The Seonix backend later replays it to /connect/exchange to finish
	 * the handshake.
	 *
	 * @return string The fully escaped connect URL with the nonce fragment.
	 */
	private function build_connect_url(): string {
		$nonce = wp_generate_password( 48, false, false );
		set_transient(
			'seonix_connect_' . hash( 'sha256', $nonce ),
			1,
			15 * MINUTE_IN_SECONDS
		);

		$url = add_query_arg(
			array(
				'provider' => 'wordpress',
				'site'     => home_url(),
			),
			'https://app.seonix.ai/connect'
		);

		// Use esc_url_raw (NOT esc_url): this URL is returned as JSON and assigned
		// to window.location.href, so it must stay a real URL. esc_url's display
		// context entity-encodes '&' to '&#038;', which breaks the query string in
		// a navigation context. esc_url_raw sanitizes for non-display use without
		// entity-encoding. It also drops the fragment, so append the (URL-encoded)
		// one-time nonce fragment after escaping the base.
		return esc_url_raw( $url ) . '#nonce=' . rawurlencode( $nonce );
	}

	// ─── Render: shared header ───────────────────────────────────

	// ─── Deep links into the Seonix app + plan ───────────────────

	/**
	 * Base origin of the Seonix dashboard SPA (e.g. https://app.seonix.ai).
	 * The account endpoint records the exact origin in `seonix_app_url`; until
	 * then we fall back to the production app (the origin the connect handoff
	 * also targets). A filter lets self-hosted installs override it.
	 */
	private function app_base_url(): string {
		$saved = get_option( 'seonix_app_url', '' );
		$base  = ! empty( $saved ) ? $saved : 'https://app.seonix.ai';
		$base  = apply_filters( 'seonix_app_base_url', $base );
		return untrailingslashit( $base );
	}

	/** Deep link to this project's overview in the Seonix dashboard. */
	private function dashboard_url(): string {
		$pid = get_option( 'seonix_project_id', '' );
		if ( '' === $pid ) {
			return $this->app_base_url();
		}
		return $this->app_base_url() . '/projects/' . rawurlencode( (string) $pid ) . '/overview';
	}

	/** Deep link to this project's billing/upgrade page. */
	private function billing_url(): string {
		$pid = get_option( 'seonix_project_id', '' );
		if ( '' === $pid ) {
			return $this->app_base_url();
		}
		return $this->app_base_url() . '/projects/' . rawurlencode( (string) $pid ) . '/billing';
	}

	/** Scheme+host(+port) of a URL, or '' when it can't be parsed. */
	private function origin_of( string $url ): string {
		$p = wp_parse_url( $url );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$origin = $p['scheme'] . '://' . $p['host'];
		if ( ! empty( $p['port'] ) ) {
			$origin .= ':' . (int) $p['port'];
		}
		return $origin;
	}

	/**
	 * AJAX: Pull the connected project's plan + deep links from the Seonix
	 * backend (GET /api/plugin/account). Server-side so the plugin Bearer key
	 * never reaches the browser. Self-contained (only depends on Seonix_Auth)
	 * so it works regardless of which optional subsystems the build ships.
	 */
	public function ajax_account(): void {
		check_ajax_referer( 'seonix_account', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();
		if ( empty( $engine_url ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Connect this site to Seonix first.', 'seonix' ) ), 400 );
		}
		if ( ! Seonix_Auth::is_safe_url( $engine_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Configured engine URL is not allowed.', 'seonix' ) ), 400 );
		}

		$response = wp_remote_get(
			trailingslashit( $engine_url ) . 'api/plugin/account',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$data    = ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) ? $decoded['data'] : array();
		if ( $status < 200 || $status >= 300 ) {
			$http = ( $status >= 400 && $status < 600 ) ? $status : 502;
			wp_send_json_error( array( 'message' => sprintf( /* translators: %d: HTTP status */ __( 'Seonix returned HTTP %d.', 'seonix' ), $status ) ), $http );
		}

		// Cache the SPA origin so server-side links are correct on the next
		// page view (matters for dev / self-hosted origins).
		if ( isset( $data['dashboard_url'] ) ) {
			$origin = $this->origin_of( (string) $data['dashboard_url'] );
			if ( '' !== $origin && $origin !== get_option( 'seonix_app_url', '' ) ) {
				update_option( 'seonix_app_url', $origin, false );
			}
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Save the IndexNow auto-submit toggle (standalone feature — works
	 * without a Seonix account, so this handler has no connection requirement).
	 */
	public function ajax_save_indexnow(): void {
		check_ajax_referer( 'seonix', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		update_option( Seonix_IndexNow::AUTO_OPTION, $enabled ? '1' : '0' );

		wp_send_json_success( array(
			'saved'   => true,
			'enabled' => $enabled,
		) );
	}

	/**
	 * Run a one-click SEO fix through the Seonix backend.
	 *
	 * This is the plugin half of the dashboard's one-click fix: the browser only
	 * asks WordPress to proxy the call, and WordPress forwards it to the backend
	 * with the Bearer key (which never reaches the browser). The backend gates the
	 * fix on an active paid subscription and runs the SAME orchestration the
	 * dashboard uses (preview → AI suggestion → apply). We surface the upstream
	 * HTTP status verbatim so the JS can open the upgrade popup on 402.
	 */
	public function ajax_seo_fix(): void {
		check_ajax_referer( 'seonix_seo_fix', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}

		$code = isset( $_POST['issue_code'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_code'] ) ) : '';
		if ( '' === $code || ! $this->is_auto_fixable( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'This issue cannot be fixed automatically.', 'seonix' ) ), 400 );
		}

		$engine_url = get_option( 'seonix_engine_url', '' );
		$api_key    = Seonix_Auth::get_key();
		if ( empty( $engine_url ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Connect this site to Seonix first.', 'seonix' ) ), 400 );
		}
		if ( ! Seonix_Auth::is_safe_url( $engine_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Configured engine URL is not allowed.', 'seonix' ) ), 400 );
		}

		// The backend runs preview → AI suggestion → apply synchronously, so allow
		// a generous timeout (the AI suggestion step is the slow part).
		$response = wp_remote_post(
			trailingslashit( $engine_url ) . 'api/plugin/seo-fix',
			array(
				'timeout' => 60,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( array( 'issue_code' => $code ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$data    = ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) ? $decoded['data'] : array();
		if ( $status < 200 || $status >= 300 ) {
			// Surface the upstream status so the JS can branch — 402 means "no paid
			// subscription" and opens the upgrade popup (mirrors the AI paywall).
			$http = ( $status >= 400 && $status < 600 ) ? $status : 502;
			$msg  = ( 402 === $status )
				? __( 'An active subscription is required to apply fixes.', 'seonix' )
				: __( 'Could not apply the fix.', 'seonix' );
			wp_send_json_error( array( 'message' => $msg ), $http );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Whether an issue code has a server-side auto-fix. Mirror of the backend
	 * seofix.issueCodeToMethod map (internal/seofix/issue_to_method.go) — keep in
	 * sync: the backend rejects non-fixable codes with 400, so the Fix button must
	 * only appear for codes that are actually fixable.
	 *
	 * @param string $code Scanner issue code.
	 * @return bool
	 */
	private function is_auto_fixable( string $code ): bool {
		static $fixable = array(
			'ssl_mixed_content',
			'broken_internal_link',
			'http_4xx',
			'title_too_long',
			'title_too_short',
			'duplicate_title',
			'meta_description_missing',
			'meta_desc_too_long',
			'meta_desc_too_short',
			'duplicate_meta_desc',
			'images_missing_alt',
			'pagination_noindex_recommended',
		);
		return in_array( $code, $fixable, true );
	}

	/**
	 * Render the shared chrome: a white top bar (brand lockup + version on the
	 * left, connection pill on the right) and a white nav row with Site Health /
	 * Settings tabs — mirroring the Seonix app shell ("Seonix SEO.html"). The
	 * tabs link to the two WordPress admin screens (Problems / Settings); the
	 * active one is underlined in brand purple. Both render methods call this so
	 * the chrome is identical across pages.
	 *
	 * @param string $active       Which tab is active: 'dashboard' | 'settings'.
	 * @param bool   $is_connected Whether the site is linked to Seonix.
	 * @param string $project_name Linked project name (for the connection pill).
	 */
	private function render_header( string $active = 'dashboard', bool $is_connected = false, string $project_name = '' ) {
		$tabs = array(
			'dashboard' => array(
				'label' => __( 'Site Health', 'seonix' ),
				'url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
				'icon'  => 'grid',
			),
			'settings'  => array(
				'label' => __( 'Settings', 'seonix' ),
				'url'   => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'icon'  => 'sliders',
			),
		);
		?>
		<div class="seonix-app">
			<header class="seonix-topbar">
				<div class="seonix-shellbar seonix-topbar__inner">
					<div class="seonix-brandlock">
						<img class="seonix-brandlock__logo" src="<?php echo esc_url( plugins_url( 'assets/seonix-logo.png', SEONIX_FILE ) ); ?>" alt="<?php esc_attr_e( 'Seonix', 'seonix' ); ?>" width="34" height="34" />
						<span class="seonix-brandtext"><?php esc_html_e( 'Seonix', 'seonix' ); ?></span>
						<span class="seonix-ver"><?php echo esc_html( 'v' . SEONIX_VERSION ); ?></span>
					</div>
					<?php if ( $is_connected ) : ?>
						<div class="seonix-topbar__right">
							<span class="seonix-connpill">
								<span class="seonix-status__dot seonix-status__dot--green"></span>
								<?php
								if ( '' !== $project_name ) {
									echo wp_kses(
										sprintf(
											/* translators: %s: Seonix project name */
											__( 'Connected · %s', 'seonix' ),
											'<b>' . esc_html( $project_name ) . '</b>'
										),
										array( 'b' => array() )
									);
								} else {
									esc_html_e( 'Connected', 'seonix' );
								}
								?>
							</span>
							<!-- Jump into the Seonix dashboard for this project. PHP-side
							     fallback href; admin.js swaps it for the exact backend URL. -->
							<a class="seonix-btn seonix-btn--brand seonix-btn--sm seonix-openapp"
								id="seonix-open-app"
								href="<?php echo esc_url( $this->dashboard_url() ); ?>"
								target="_blank" rel="noopener">
								<span><?php esc_html_e( 'Open in Seonix', 'seonix' ); ?></span>
								<span class="seonix-openapp__ico" aria-hidden="true">&#8599;</span>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</header>
			<nav class="seonix-navrow" aria-label="<?php esc_attr_e( 'Seonix', 'seonix' ); ?>">
				<div class="seonix-shellbar seonix-navrow__inner">
					<?php foreach ( $tabs as $key => $tab ) : ?>
						<a class="seonix-navtab<?php echo $key === $active ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( $tab['url'] ); ?>"
							<?php echo $key === $active ? 'aria-current="page"' : ''; ?>>
							<?php $this->nav_icon( $tab['icon'] ); ?>
							<span><?php echo esc_html( $tab['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</nav>
			<div class="seonix-content">
				<div id="seonix-notices"></div>
		<?php
	}

	/**
	 * Close the app shell opened by render_header() (the .seonix-content and
	 * .seonix-app wrappers). Every render method pairs render_header() with this.
	 */
	private function render_footer(): void {
		?>
			</div><!-- .seonix-content -->
		</div><!-- .seonix-app -->
		<?php
	}

	/**
	 * Echo a small inline nav-tab icon (trusted static SVG, no user data).
	 *
	 * @param string $name Icon key: 'grid' | 'sliders'.
	 */
	private function nav_icon( string $name ): void {
		$paths = array(
			'grid'    => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
			'sliders' => '<path d="M4 8h9"/><path d="M17 8h3"/><circle cx="15" cy="8" r="2.2"/><path d="M4 16h3"/><path d="M11 16h9"/><circle cx="9" cy="16" r="2.2"/>',
		);
		$inner = isset( $paths[ $name ] ) ? $paths[ $name ] : '';
		// Static, developer-authored SVG — safe to emit verbatim.
		echo '<svg class="seonix-navtab__icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static markup.
	}

	// ─── Render: standalone feature cards ────────────────────────

	/**
	 * Render the two STANDALONE feature cards — AI Search (llms.txt) and
	 * IndexNow. Both features work without a Seonix account (llms.txt serving
	 * registers unconditionally; the IndexNow ping is explicitly independent of
	 * the backend connection — see Seonix_Sync), so these cards are shown in
	 * every connection state. They give a plugin installed "just to look"
	 * immediate, visible value instead of a bare connect button.
	 */
	private function render_standalone_cards(): void {
		$permalinks_ok = '' !== (string) get_option( 'permalink_structure', '' );
		$llms_url      = home_url( '/llms.txt' );
		$llms_full_url = home_url( '/llms-full.txt' );

		$indexnow_on   = Seonix_IndexNow::is_auto_enabled();
		$indexnow_last = get_option( Seonix_IndexNow::LAST_OPTION, array() );
		$last_at       = is_array( $indexnow_last ) && ! empty( $indexnow_last['at'] ) ? (int) $indexnow_last['at'] : 0;
		$last_count    = is_array( $indexnow_last ) && ! empty( $indexnow_last['count'] ) ? (int) $indexnow_last['count'] : 0;
		?>
		<!-- AI Search (llms.txt) — standalone, no account needed. -->
		<div class="seonix-card">
			<div class="seonix-featcard__head">
				<h2><?php esc_html_e( 'AI Search (llms.txt)', 'seonix' ); ?></h2>
				<?php if ( $permalinks_ok ) : ?>
					<span class="seonix-featstatus seonix-featstatus--on">
						<span class="seonix-status__dot seonix-status__dot--green"></span>
						<span class="seonix-featstatus__txt"><?php esc_html_e( 'Active', 'seonix' ); ?></span>
					</span>
				<?php else : ?>
					<span class="seonix-featstatus">
						<span class="seonix-status__dot"></span>
						<span class="seonix-featstatus__txt"><?php esc_html_e( 'Needs pretty permalinks', 'seonix' ); ?></span>
					</span>
				<?php endif; ?>
			</div>
			<p class="seonix-subtitle"><?php esc_html_e( 'Your site serves llms.txt and llms-full.txt — a machine-readable index of your published content that AI assistants (ChatGPT, Perplexity and others) use to discover and cite your pages. Generated live from your content, always up to date. Works without a Seonix account.', 'seonix' ); ?></p>
			<?php if ( $permalinks_ok ) : ?>
				<div class="seonix-featlinks">
					<a class="seonix-btn seonix-btn--secondary seonix-btn--sm" href="<?php echo esc_url( $llms_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View /llms.txt', 'seonix' ); ?></a>
					<a class="seonix-btn seonix-btn--secondary seonix-btn--sm" href="<?php echo esc_url( $llms_full_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View /llms-full.txt', 'seonix' ); ?></a>
				</div>
			<?php else : ?>
				<p class="seonix-featnote">
					<?php
					printf(
						/* translators: %1$s / %2$s: opening and closing <a> tag linking to the WordPress permalink settings. */
						esc_html__( 'Serving /llms.txt needs pretty permalinks. Enable any structure other than "Plain" under %1$sSettings → Permalinks%2$s.', 'seonix' ),
						'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">',
						'</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<!-- IndexNow — standalone, no account needed. -->
		<div class="seonix-card">
			<div class="seonix-featcard__head">
				<h2><?php esc_html_e( 'IndexNow', 'seonix' ); ?></h2>
				<span class="seonix-featstatus<?php echo $indexnow_on ? ' seonix-featstatus--on' : ''; ?>" id="seonix-indexnow-status">
					<span class="seonix-status__dot<?php echo $indexnow_on ? ' seonix-status__dot--green' : ''; ?>"></span>
					<span class="seonix-featstatus__txt"><?php $indexnow_on ? esc_html_e( 'Enabled', 'seonix' ) : esc_html_e( 'Disabled', 'seonix' ); ?></span>
				</span>
			</div>
			<p class="seonix-subtitle"><?php esc_html_e( 'When you publish or update a public post or page, the plugin pings IndexNow so participating search engines (Bing, Yandex, Seznam, Naver) re-crawl the URL within minutes. The verification key is set up automatically. Works without a Seonix account. Google does not participate in IndexNow.', 'seonix' ); ?></p>
			<label class="seonix-checkrow" for="seonix-indexnow-auto">
				<input type="checkbox" id="seonix-indexnow-auto" <?php checked( $indexnow_on ); ?> />
				<span><?php esc_html_e( 'Submit new and updated content automatically', 'seonix' ); ?></span>
			</label>
			<p class="seonix-sync-time">
				<?php if ( $last_at > 0 ) : ?>
					<?php
					printf(
						/* translators: 1: number of URLs submitted, 2: formatted date/time of the last IndexNow submission. */
						esc_html( _n( 'Last submission: %1$s URL on %2$s.', 'Last submission: %1$s URLs on %2$s.', $last_count, 'seonix' ) ),
						esc_html( number_format_i18n( $last_count ) ),
						esc_html( wp_date( 'F j, Y \a\t g:i A', $last_at ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'No submissions yet — publish or update a post to trigger the first one.', 'seonix' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	// ─── Render: Dashboard ───────────────────────────────────────

	/**
	 * Render the Dashboard: site-health tasks from the LOCAL table + connect
	 * handoff. No live API call happens on a page view — a soft auto-pull only
	 * fires (best-effort, single-flight) when the local copy is older than 24h.
	 */
	public function render_dashboard() {
		// Soft refresh if the local copy is stale (>24h). Best-effort and
		// single-flight (transient lock) so concurrent admin views don't
		// stampede the backend; never blocks or errors the page.
		$this->tasks->maybe_auto_pull();

		$is_connected = Seonix_Auth::is_connected();
		$project_name = get_option( 'seonix_project_name', '' );
		$summary      = $this->tasks->summary();
		$rows         = $this->tasks->all();
		$synced_at    = $this->tasks->synced_at();

		$open      = isset( $summary['open'] ) ? (int) $summary['open'] : 0;
		$solved    = isset( $summary['solved'] ) ? (int) $summary['solved'] : 0;
		$regressed = isset( $summary['regressed'] ) ? (int) $summary['regressed'] : 0;
		$score     = isset( $summary['score'] ) ? (int) $summary['score'] : 0;
		$cats      = isset( $summary['categories'] ) && is_array( $summary['categories'] ) ? $summary['categories'] : array();

		// "Active issues" headline — matches the dashboard's activeProblemPageTotal:
		// affected pages across ACTIVE issue rows (open|regressed) whose severity is
		// error/warning. Notice-level findings are "Recommendations" and are
		// excluded from the headline — that exclusion is the ONLY reason the old
		// plugin "open issues" number (raw open threads from the synced summary)
		// differed from the dashboard's "active issues". Fixed / Came back / All
		// keep using the synced lifecycle summary, which already matches the app.
		$active_issues = 0;
		foreach ( $rows as $r ) {
			$st = isset( $r['status'] ) ? (string) $r['status'] : 'open';
			if ( 'solved' === $st ) {
				continue;
			}
			$sev = isset( $r['severity'] ) ? (string) $r['severity'] : 'notice';
			if ( 'error' !== $sev && 'warning' !== $sev ) {
				continue;
			}
			$active_issues += isset( $r['affected_count'] ) ? (int) $r['affected_count'] : 0;
		}
		// Backend is the single source of truth: when it sends summary.active (the
		// app's canonical activeProblemPageTotal), render it verbatim so the
		// plugin's "Active issues" ALWAYS equals the dashboard's. The loop above is
		// only a fallback for an older backend (-1 = field absent).
		if ( isset( $summary['active'] ) && (int) $summary['active'] >= 0 ) {
			$active_issues = (int) $summary['active'];
		}

		$cat_labels = array(
			'seo'       => __( 'SEO', 'seonix' ),
			'technical' => __( 'Technical', 'seonix' ),
			'speed'     => __( 'Speed', 'seonix' ),
			'ai'        => __( 'AI Search', 'seonix' ),
		);

		?>
		<?php $this->render_header( 'dashboard', $is_connected, $project_name ); ?>

				<?php if ( ! $is_connected ) : ?>
					<!-- Connect CTA — shown until the site is linked. Once connected
					     the status pill + Reconnect live in the header (see render_header). -->
					<div class="seonix-connbar">
						<div class="seonix-connbar__info">
							<h2><?php esc_html_e( 'Connect to Seonix', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'Link this site to Seonix in one click. We will analyze your site and start surfacing SEO tasks here.', 'seonix' ); ?></p>
						</div>
						<div class="seonix-connbar__actions">
							<button type="button" class="seonix-btn seonix-btn--primary" id="seonix-connect-btn">
								<?php esc_html_e( 'Connect to Seonix', 'seonix' ); ?>
							</button>
						</div>
					</div>

					<!-- Standalone value: the two features that already run on this
					     site without any account, so the pre-connect screen is not
					     an empty shell with a single button. -->
					<div class="seonix-standalone">
						<h2 class="seonix-standalone__title"><?php esc_html_e( 'Already working on your site', 'seonix' ); ?></h2>
						<p class="seonix-subtitle seonix-standalone__sub"><?php esc_html_e( 'These features run locally in the plugin and need no account.', 'seonix' ); ?></p>
						<?php $this->render_standalone_cards(); ?>
					</div>
				<?php endif; ?>

				<?php if ( $is_connected ) : ?>
					<?php
					// Dark site-health hero: a gradient score ring on the left, a plain-
					// language headline + sync line in the middle, and the per-category
					// "pillars" on the right. The pillars ARE the .seonix-bar category-
					// filter buttons (data-category + aria-pressed preserved) so clicking
					// one still narrows the By-issue task list via admin.js — only their
					// styling changed for the dark surface.
					$ring_size   = 132;
					$ring_stroke = 9;
					$ring_r      = ( $ring_size - 14 ) / 2;
					$ring_c      = 2 * M_PI * $ring_r;
					$ring_off    = $ring_c * ( 1 - max( 0, min( 100, $score ) ) / 100 );

					// Plain-language headline + subline, derived from real numbers only.
					if ( $score >= 90 ) {
						$hero_title = __( 'Great shape — keep it up', 'seonix' );
					} elseif ( $score >= 50 ) {
						$hero_title = __( 'Good shape — room to improve', 'seonix' );
					} else {
						$hero_title = __( 'Needs attention', 'seonix' );
					}
					if ( $active_issues > 0 ) {
						$hero_sub = sprintf(
							/* translators: %s: number of active issues */
							_n( '%s active issue to clear across your pages.', '%s active issues to clear across your pages.', $active_issues, 'seonix' ),
							number_format_i18n( $active_issues )
						);
					} else {
						$hero_sub = __( 'No active issues — your site is in great shape.', 'seonix' );
					}
					?>
					<!-- Site Health: dark hero (score ring + pillars) -->
					<div class="seonix-hero">
						<div class="seonix-hero__ring" style="width: <?php echo esc_attr( (string) $ring_size ); ?>px; height: <?php echo esc_attr( (string) $ring_size ); ?>px;">
							<svg width="<?php echo esc_attr( (string) $ring_size ); ?>" height="<?php echo esc_attr( (string) $ring_size ); ?>" viewBox="0 0 <?php echo esc_attr( (string) $ring_size ); ?> <?php echo esc_attr( (string) $ring_size ); ?>" style="transform: rotate(-90deg);" aria-hidden="true">
								<defs>
									<linearGradient id="seonixRingGrad" x1="0" y1="0" x2="1" y2="1">
										<stop offset="0%" stop-color="#A265FF" />
										<stop offset="100%" stop-color="#5FC2FF" />
									</linearGradient>
								</defs>
								<circle cx="<?php echo esc_attr( (string) ( $ring_size / 2 ) ); ?>" cy="<?php echo esc_attr( (string) ( $ring_size / 2 ) ); ?>" r="<?php echo esc_attr( (string) $ring_r ); ?>" fill="none" stroke="rgba(255,255,255,0.13)" stroke-width="<?php echo esc_attr( (string) $ring_stroke ); ?>" />
								<circle cx="<?php echo esc_attr( (string) ( $ring_size / 2 ) ); ?>" cy="<?php echo esc_attr( (string) ( $ring_size / 2 ) ); ?>" r="<?php echo esc_attr( (string) $ring_r ); ?>" fill="none" stroke="url(#seonixRingGrad)" stroke-width="<?php echo esc_attr( (string) $ring_stroke ); ?>" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr( (string) round( $ring_c, 2 ) ); ?>" stroke-dashoffset="<?php echo esc_attr( (string) round( $ring_off, 2 ) ); ?>" />
							</svg>
							<div class="seonix-hero__ring-num">
								<div class="seonix-hero__ring-v"><?php echo esc_html( $score ); ?></div>
								<div class="seonix-hero__ring-l"><?php esc_html_e( '/ 100', 'seonix' ); ?></div>
							</div>
						</div>

						<div class="seonix-hero__mid">
							<div class="seonix-hero__eyebrow"><?php esc_html_e( 'Overall site health', 'seonix' ); ?></div>
							<h2 class="seonix-hero__title"><?php echo esc_html( $hero_title ); ?></h2>
							<p class="seonix-hero__sub"><?php echo esc_html( $hero_sub ); ?></p>
							<div class="seonix-hero__meta">
								<span class="seonix-sync-time__text">
									<?php if ( $synced_at > 0 ) : ?>
										<?php
										printf(
											/* translators: %s: formatted date/time of last task sync */
											esc_html__( 'Synced %s', 'seonix' ),
											esc_html( wp_date( 'F j, Y \a\t g:i A', $synced_at ) )
										);
										?>
									<?php else : ?>
										<?php esc_html_e( 'Tasks not synced yet', 'seonix' ); ?>
									<?php endif; ?>
								</span>
								<button type="button" id="seonix-refresh-tasks-btn" class="seonix-refresh-link">
									<span class="seonix-refresh-link__icon" aria-hidden="true">&#8635;</span>
									<?php esc_html_e( 'Refresh', 'seonix' ); ?>
								</button>
							</div>
						</div>

						<?php if ( ! empty( $cats ) ) : ?>
							<!-- Pillars = category-filter buttons (data-category preserved). -->
							<div class="seonix-hero__pillars">
								<div class="seonix-bars">
									<?php foreach ( $cats as $cat ) : ?>
										<?php
										$cat_key   = isset( $cat['key'] ) ? (string) $cat['key'] : '';
										if ( ! in_array( $cat_key, array( 'seo', 'technical', 'speed', 'ai' ), true ) ) {
											$cat_key = 'seo';
										}
										// Speed's score is null until the first per-page speed pass. A null
										// pillar renders "—" with an empty meter and is NOT a category-filter
										// button (clicking it would only ever show an empty list) — it is a
										// read-only "measure me" placeholder.
										$cat_has_score = array_key_exists( 'score', $cat ) && null !== $cat['score'];
										$cat_score     = $cat_has_score ? (int) $cat['score'] : null;
										$cat_label     = isset( $cat_labels[ $cat_key ] ) ? $cat_labels[ $cat_key ] : $cat_key;
										$cat_fill_col  = null === $cat_score ? 'transparent' : ( $cat_score >= 90 ? '#22C08A' : ( $cat_score >= 50 ? '#E89A1C' : '#EF4D5E' ) );
										$cat_fill_pct  = null === $cat_score ? 0 : $cat_score;
										?>
										<?php if ( null === $cat_score ) : ?>
											<div class="seonix-bar seonix-bar--static" data-category-display="<?php echo esc_attr( $cat_key ); ?>">
												<span class="seonix-bar__label"><?php echo esc_html( $cat_label ); ?></span>
												<span class="seonix-bar__value seonix-bar__value--empty">&mdash;</span>
												<span class="seonix-bar__track">
													<span class="seonix-bar__fill" style="width: 0%;"></span>
												</span>
											</div>
										<?php else : ?>
											<button type="button" class="seonix-bar" data-category="<?php echo esc_attr( $cat_key ); ?>" aria-pressed="false">
												<span class="seonix-bar__label"><?php echo esc_html( $cat_label ); ?></span>
												<span class="seonix-bar__value"><?php echo esc_html( (string) $cat_score ); ?></span>
												<span class="seonix-bar__track">
													<span class="seonix-bar__fill" style="width: <?php echo esc_attr( (string) $cat_fill_pct ); ?>%; background: <?php echo esc_attr( $cat_fill_col ); ?>;"></span>
												</span>
											</button>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<!-- KPI cards: Open issues / Resolved / Came back (real counts only). -->
					<div class="seonix-kpi-grid">
						<div class="seonix-kpi">
							<div class="seonix-kpi__top">
								<div class="seonix-kpi__v"><?php echo esc_html( number_format_i18n( $active_issues ) ); ?></div>
								<span class="seonix-kpi__ic seonix-kpi__ic--amb">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.2"/><path d="M12 8v5"/><circle cx="12" cy="16" r=".4"/></svg>
								</span>
							</div>
							<div class="seonix-kpi__l"><?php esc_html_e( 'Active issues', 'seonix' ); ?></div>
							<div class="seonix-kpi__foot"><?php esc_html_e( 'Needs attention', 'seonix' ); ?></div>
						</div>
						<div class="seonix-kpi">
							<div class="seonix-kpi__top">
								<div class="seonix-kpi__v"><?php echo esc_html( number_format_i18n( $solved ) ); ?></div>
								<span class="seonix-kpi__ic seonix-kpi__ic--grn">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.2"/><path d="M8.4 12.2l2.6 2.6 4.6-5.2"/></svg>
								</span>
							</div>
							<div class="seonix-kpi__l"><?php esc_html_e( 'Resolved', 'seonix' ); ?></div>
							<div class="seonix-kpi__foot"><?php esc_html_e( 'Fixed by Seonix', 'seonix' ); ?></div>
						</div>
						<div class="seonix-kpi">
							<div class="seonix-kpi__top">
								<div class="seonix-kpi__v"><?php echo esc_html( number_format_i18n( $regressed ) ); ?></div>
								<span class="seonix-kpi__ic seonix-kpi__ic--acc">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12a8.5 8.5 0 0 1 14.4-6.1L20.5 8"/><path d="M20.5 3.8V8h-4.2"/><path d="M20.5 12a8.5 8.5 0 0 1-14.4 6.1L3.5 16"/><path d="M3.5 20.2V16h4.2"/></svg>
								</span>
							</div>
							<div class="seonix-kpi__l"><?php esc_html_e( 'Came back', 'seonix' ); ?></div>
							<div class="seonix-kpi__foot"><?php esc_html_e( 'Re-opened after a fix', 'seonix' ); ?></div>
						</div>
					</div>

					<!-- Task list — By issue / By page views.
					     Mirrors the Seonix web app's IssueTaskListPanel: a view toggle
					     (By issue / By page) over two views. By issue = lifecycle tabs +
					     a Task/Pages/Priority table whose rows drill into every affected
					     page; By page = the same data inverted into a per-URL list. Every
					     row is rendered once (server-side); the toggle, lifecycle tabs,
					     accordions, and page search all run client-side without a reload. -->
					<div class="seonix-card">
						<div class="seonix-tasklist__headrow">
							<div class="seonix-tasklist__head">
								<h2><?php esc_html_e( 'Issues', 'seonix' ); ?></h2>
								<p class="seonix-subtitle seonix-tasklist__subtitle">
									<?php esc_html_e( 'Each row is one thing to fix. Resolved tasks move to "Fixed"; if a fix breaks again it appears in "Came back".', 'seonix' ); ?>
								</p>
							</div>
							<?php if ( ! empty( $rows ) ) : ?>
								<!-- By issue / By page toggle (segmented control). JS shows the
								     matching .seonix-view and hides the other; default = issues. -->
								<div class="seonix-viewtoggle" role="tablist">
									<button type="button" class="seonix-viewtoggle__btn is-active" data-view="issues">
										<?php esc_html_e( 'By issue', 'seonix' ); ?>
									</button>
									<button type="button" class="seonix-viewtoggle__btn" data-view="pages">
										<?php esc_html_e( 'By page', 'seonix' ); ?>
									</button>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( empty( $rows ) ) : ?>
							<p class="seonix-subtitle seonix-empty"><?php esc_html_e( 'No tasks yet. Run a scan from your Seonix dashboard to see tasks here.', 'seonix' ); ?></p>
						<?php else : ?>
							<?php
							// Tab counts, computed in PHP from $rows. Rows are grouped by
							// issue type (one row = many affected pages), so each tab badge
							// shows the SUM of affected_count within its status bucket — the
							// affected-page total, not the number of rows. Active = open +
							// regressed, Fixed = solved, Came back = regressed, All =
							// everything. Mirrors the web app's tab badge math.
							// Tab badges mirror the dashboard's numbers exactly: Active =
							// $active_issues (error/warning affected pages, notices excluded),
							// while Fixed / Came back / All come from the synced lifecycle
							// summary (the same source the dashboard uses), so the plugin's
							// badges equal the web app's instead of summing affected pages.
							$tab_counts = array(
								'active'    => $active_issues,
								'solved'    => $solved,
								'regressed' => $regressed,
								'all'       => $open + $solved + $regressed,
							);
							$tab_labels = array(
								'active'    => __( 'Active issues', 'seonix' ),
								'solved'    => __( 'Fixed', 'seonix' ),
								'regressed' => __( 'Came back', 'seonix' ),
								'all'       => __( 'All', 'seonix' ),
							);
							?>
							<!-- ── By issue view ───────────────────────────────────── -->
							<div class="seonix-view" data-view="issues">
								<!-- Lifecycle tabs (pills). Default = Active. JS reads
								     data-tab and shows/hides rows by their data-status. -->
								<div class="seonix-lifetabs" role="tablist">
									<?php foreach ( $tab_labels as $tab_key => $tab_label ) : ?>
										<button
											type="button"
											class="seonix-lifetab<?php echo 'active' === $tab_key ? ' is-active' : ''; ?>"
											data-tab="<?php echo esc_attr( $tab_key ); ?>"
											aria-pressed="<?php echo 'active' === $tab_key ? 'true' : 'false'; ?>"
										>
											<?php echo esc_html( $tab_label ); ?>
											<span class="seonix-lifetab__count"><?php echo esc_html( (string) $tab_counts[ $tab_key ] ); ?></span>
										</button>
									<?php endforeach; ?>
								</div>

								<!-- Active category-filter chip. Hidden until a Site Health
								     category bar is clicked; JS reveals it, fills its label from
								     the matching data-label-* attr, and clicking the ✕ clears the
								     filter (returning the bars to neutral). Mirrors the web app's
								     indigo clear chip. The localized category labels are stamped
								     here as data attrs so the JS never hard-codes English. -->
								<div
									class="seonix-catfilter-bar"
									data-label-seo="<?php echo esc_attr( $cat_labels['seo'] ); ?>"
									data-label-technical="<?php echo esc_attr( $cat_labels['technical'] ); ?>"
									data-label-speed="<?php echo esc_attr( $cat_labels['speed'] ); ?>"
									data-label-ai="<?php echo esc_attr( $cat_labels['ai'] ); ?>"
									hidden
								>
									<button type="button" class="seonix-catfilter" aria-label="<?php esc_attr_e( 'Clear filter', 'seonix' ); ?>">
										<span class="seonix-catfilter__label"></span>
										<span class="seonix-catfilter__x" aria-hidden="true">&times;</span>
									</button>
								</div>

								<div class="seonix-tasktable" data-default-tab="active">
									<div class="seonix-tasktable__head" aria-hidden="true">
										<span class="seonix-tasktable__col-task"><?php esc_html_e( 'Task', 'seonix' ); ?></span>
										<span class="seonix-tasktable__col-pages"><?php esc_html_e( 'Pages', 'seonix' ); ?></span>
										<span class="seonix-tasktable__col-priority"><?php esc_html_e( 'Priority', 'seonix' ); ?></span>
									</div>
									<div class="seonix-tasktable__body">
										<?php foreach ( $rows as $row ) : ?>
											<?php $this->render_task_row( $row ); ?>
										<?php endforeach; ?>
									</div>
									<!-- Per-tab empty state, toggled by JS when a tab has no matching rows. -->
									<p class="seonix-tasktable__empty" hidden><?php esc_html_e( 'Nothing here for this tab.', 'seonix' ); ?></p>
								</div>
							</div>

							<!-- ── By page view ────────────────────────────────────── -->
							<?php
							// Invert $rows into a pages map: one entry per affected URL,
							// carrying the issues on it + traffic-light severity counts.
							// Only pages that appear in at least one task exist here (we
							// don't sync clean pages) — that's the expected shape.
							$pages_map = array();
							foreach ( $rows as $row ) {
								$r_title    = isset( $row['title'] ) ? (string) $row['title'] : '';
								$r_category = isset( $row['category'] ) ? (string) $row['category'] : 'seo';
								if ( ! in_array( $r_category, array( 'seo', 'technical', 'speed', 'ai' ), true ) ) {
									$r_category = 'seo';
								}
								$r_severity = isset( $row['severity'] ) ? (string) $row['severity'] : 'notice';
								if ( ! in_array( $r_severity, array( 'error', 'warning', 'notice' ), true ) ) {
									$r_severity = 'notice';
								}
								$r_priority = isset( $row['priority'] ) ? (string) $row['priority'] : 'low';
								if ( ! in_array( $r_priority, array( 'high', 'medium', 'low' ), true ) ) {
									$r_priority = 'low';
								}
								foreach ( Seonix_Tasks::decode_pages( isset( $row['affected_pages'] ) ? $row['affected_pages'] : '' ) as $pg ) {
									$url = $pg['url'];
									if ( ! isset( $pages_map[ $url ] ) ) {
										$pages_map[ $url ] = array(
											'title'    => $r_title, // First-seen representative title.
											'errors'   => 0,
											'warnings' => 0,
											'notices'  => 0,
											'issues'   => array(),
										);
									}
									$pages_map[ $url ]['issues'][] = array(
										'title'    => $r_title,
										'category' => $r_category,
										'severity' => $r_severity,
										'priority' => $r_priority,
										'status'   => $pg['status'],
									);
									// Only active (open/regressed) issues count toward the
									// page's traffic-light totals — solved ones are shown in
									// the drilldown but don't colour the row.
									if ( 'open' === $pg['status'] || 'regressed' === $pg['status'] ) {
										if ( 'error' === $r_severity ) {
											$pages_map[ $url ]['errors']++;
										} elseif ( 'warning' === $r_severity ) {
											$pages_map[ $url ]['warnings']++;
										} else {
											$pages_map[ $url ]['notices']++;
										}
									}
								}
							}
							// Sort pages: errors desc, then warnings, then notices, then
							// URL asc (mirrors PagesView's ordering for visual stability).
							uksort(
								$pages_map,
								static function ( $url_a, $url_b ) use ( $pages_map ) {
									$a = $pages_map[ $url_a ];
									$b = $pages_map[ $url_b ];
									if ( $a['errors'] !== $b['errors'] ) {
										return $b['errors'] - $a['errors'];
									}
									if ( $a['warnings'] !== $b['warnings'] ) {
										return $b['warnings'] - $a['warnings'];
									}
									if ( $a['notices'] !== $b['notices'] ) {
										return $b['notices'] - $a['notices'];
									}
									return strcmp( (string) $url_a, (string) $url_b );
								}
							);
							?>
							<div class="seonix-view" data-view="pages" hidden>
								<?php if ( empty( $pages_map ) ) : ?>
									<p class="seonix-subtitle seonix-empty"><?php esc_html_e( 'No affected pages yet. Run a scan from your Seonix dashboard to see pages here.', 'seonix' ); ?></p>
								<?php else : ?>
									<div class="seonix-pagesearch">
										<input type="search" id="seonix-page-search" placeholder="<?php esc_attr_e( 'Search by URL…', 'seonix' ); ?>" />
									</div>
									<div class="seonix-pagetable">
										<div class="seonix-pagetable__head" aria-hidden="true">
											<span class="seonix-pagetable__col-page"><?php esc_html_e( 'Page', 'seonix' ); ?></span>
											<span class="seonix-pagetable__col-num"><?php esc_html_e( 'Errors', 'seonix' ); ?></span>
											<span class="seonix-pagetable__col-num"><?php esc_html_e( 'Warn.', 'seonix' ); ?></span>
											<span class="seonix-pagetable__col-num"><?php esc_html_e( 'Notice.', 'seonix' ); ?></span>
										</div>
										<div class="seonix-pagetable__body">
											<?php
											$page_idx = 0;
											foreach ( $pages_map as $url => $page ) {
												$this->render_page_row( $url, $page, $page_idx );
												$page_idx++;
											}
											?>
										</div>
										<!-- Search empty state, toggled by JS when no page URL matches. -->
										<p class="seonix-pagetable__empty" hidden><?php esc_html_e( 'No pages match your search.', 'seonix' ); ?></p>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>

					<!-- Shared problem/page detail modal (the web app's IssueDetailModal).
					     ONE reusable shell, hidden by default. admin.js fills the header
					     (status pill + title + code line) from the clicked row's data attrs
					     and clones the matching hidden detail block into the body, then
					     reveals it. Closed via the ×, the footer Close, a backdrop click, or
					     Esc. Reused for both task rows and page rows. -->
					<div class="seonix-modal" id="seonix-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="seonix-modal-title">
						<div class="seonix-modal__backdrop" data-seonix-modal-close></div>
						<div class="seonix-modal__panel" role="document">
							<div class="seonix-modal__head">
								<div class="seonix-modal__heading">
									<span class="seonix-modal__pill"></span>
									<h2 class="seonix-modal__title" id="seonix-modal-title"></h2>
									<p class="seonix-modal__code"></p>
								</div>
								<button type="button" class="seonix-modal__close" data-seonix-modal-close aria-label="<?php esc_attr_e( 'Close', 'seonix' ); ?>">&times;</button>
							</div>
							<div class="seonix-modal__body"></div>
							<div class="seonix-modal__foot">
								<button type="button" class="seonix-btn seonix-btn--secondary seonix-btn--sm" data-seonix-modal-close>
									<?php esc_html_e( 'Close', 'seonix' ); ?>
								</button>
							</div>
						</div>
					</div>
				<?php endif; ?>
		<?php $this->render_footer(); ?>
		<?php
	}

	/**
	 * Render one task row as a Task/Pages/Priority table row PLUS a HIDDEN detail
	 * block that serves as the modal's body source. Mirrors the Seonix web app's
	 * IssueTaskListPanel: a clickable row (status dot + "Fix: <title>" + category
	 * badge + one-line description, the page count, and a priority pill + chevron)
	 * that OPENS A MODAL (the web app's IssueDetailModal) showing the full
	 * description, the "How to fix:" recommendation, and the full list of every
	 * affected page (decoded from the affected_pages column). When that list is
	 * empty the modal body falls back to the single affected_url line.
	 *
	 * The hidden .seonix-task-detail block is NOT shown inline (no accordion) —
	 * admin.js clones its innerHTML into the shared modal on click. The wrapper
	 * carries data-status / data-category (so the lifecycle tabs + category
	 * filter can show/hide it on the client without a reload) plus data-title /
	 * data-code so the modal header can be populated without re-deriving them.
	 *
	 * Every field is escaped on emit — the task data is fetched DATA, never
	 * trusted markup.
	 *
	 * @param array<string,mixed> $row Raw task row from the local table.
	 */
	private function render_task_row( array $row ) {
		$title          = isset( $row['title'] ) ? (string) $row['title'] : '';
		$description    = isset( $row['description'] ) ? (string) $row['description'] : '';
		$recommendation = isset( $row['recommendation'] ) ? (string) $row['recommendation'] : '';
		// Rich remediation detail mirrored from the dashboard (synced from the
		// backend TaskView). Shown when present; otherwise the description /
		// recommendation above remain the fallback.
		$why_it_matters = isset( $row['why_it_matters'] ) ? (string) $row['why_it_matters'] : '';
		$bad_code       = isset( $row['bad_example_code'] ) ? (string) $row['bad_example_code'] : '';
		$bad_caption    = isset( $row['bad_example_caption'] ) ? (string) $row['bad_example_caption'] : '';
		$good_code      = isset( $row['good_example_code'] ) ? (string) $row['good_example_code'] : '';
		$good_caption   = isset( $row['good_example_caption'] ) ? (string) $row['good_example_caption'] : '';
		$fix_steps      = json_decode( (string) ( isset( $row['how_to_fix_steps'] ) ? $row['how_to_fix_steps'] : '' ), true );
		if ( ! is_array( $fix_steps ) ) {
			$fix_steps = array();
		}
		$warns = json_decode( (string) ( isset( $row['warnings'] ) ? $row['warnings'] : '' ), true );
		if ( ! is_array( $warns ) ) {
			$warns = array();
		}
		$affected_url   = isset( $row['affected_url'] ) ? (string) $row['affected_url'] : '';
		$code           = isset( $row['code'] ) ? (string) $row['code'] : '';
		$priority       = isset( $row['priority'] ) ? (string) $row['priority'] : 'low';
		$status         = isset( $row['status'] ) ? (string) $row['status'] : 'open';
		$category       = isset( $row['category'] ) ? (string) $row['category'] : 'seo';
		$informational  = ! empty( $row['informational'] );
		$affected_count = isset( $row['affected_count'] ) ? (int) $row['affected_count'] : 1;

		// Clamp the vocab fields to known values so the row only ever emits a
		// class/badge it has styling for (the store already clamps, but this keeps
		// the renderer self-contained and defensive).
		if ( ! in_array( $priority, array( 'high', 'medium', 'low' ), true ) ) {
			$priority = 'low';
		}
		if ( ! in_array( $status, array( 'open', 'solved', 'regressed' ), true ) ) {
			$status = 'open';
		}
		if ( ! in_array( $category, array( 'seo', 'technical', 'speed', 'ai' ), true ) ) {
			$category = 'seo';
		}

		// Map priority → priority-pill colour class (same .seonix-badge--* pills
		// the previous design used).
		$badge_class = 'seonix-badge--' . $priority;

		$priority_label = array(
			'high'   => __( 'High', 'seonix' ),
			'medium' => __( 'Medium', 'seonix' ),
			'low'    => __( 'Low', 'seonix' ),
		);

		// Category → label + tone class. SEO (blue/indigo), Technical (slate),
		// AI Search (purple) — mirrors the web app's CATEGORY_* maps.
		$category_label = array(
			'seo'       => __( 'SEO', 'seonix' ),
			'technical' => __( 'Technical', 'seonix' ),
			'speed'     => __( 'Speed', 'seonix' ),
			'ai'        => __( 'AI Search', 'seonix' ),
		);
		$category_class = 'seonix-cat--' . $category;

		// "Fix: <title>" for active rows; already-fixed rows read as the plain
		// title (struck through via CSS) — matches the web app's row title.
		if ( 'solved' === $status ) {
			$row_title = $title;
		} else {
			/* translators: %s: the issue title, e.g. "Broken Internal Links". */
			$row_title = sprintf( __( 'Fix: %s', 'seonix' ), $title );
		}

		$row_class = 'seonix-trow seonix-trow--' . $priority . ' seonix-trow--' . $status;

		// A stable-ish id so the button can point its aria-controls at the panel.
		$panel_id = 'seonix-task-detail-' . ( isset( $row['id'] ) ? (string) absint( $row['id'] ) : md5( $title . $affected_url ) );
		?>
		<div
			class="seonix-task-item"
			data-status="<?php echo esc_attr( $status ); ?>"
			data-category="<?php echo esc_attr( $category ); ?>"
			data-title="<?php echo esc_attr( $row_title ); ?>"
			data-code="<?php echo esc_attr( $code ); ?>"
		>
			<button type="button" class="<?php echo esc_attr( $row_class ); ?>" aria-haspopup="dialog" aria-controls="seonix-modal">
				<span class="seonix-trow__task">
					<span class="seonix-dot seonix-dot--<?php echo esc_attr( $priority ); ?>" aria-hidden="true"></span>
					<span class="seonix-trow__main">
						<span class="seonix-trow__titleline">
							<span class="seonix-trow__title"><?php echo esc_html( $row_title ); ?></span>
							<span class="seonix-cat <?php echo esc_attr( $category_class ); ?>"><?php echo esc_html( isset( $category_label[ $category ] ) ? $category_label[ $category ] : $category_label['seo'] ); ?></span>
							<?php if ( $informational ) : ?>
								<span class="seonix-task__info"><?php esc_html_e( 'Optional', 'seonix' ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( '' !== $description ) : ?>
							<span class="seonix-trow__desc"><?php echo esc_html( $description ); ?></span>
						<?php endif; ?>
					</span>
				</span>

				<span class="seonix-trow__pages">
					<span class="seonix-trow__pages-num"><?php echo esc_html( (string) $affected_count ); ?></span>
					<span class="seonix-trow__pages-unit">
						<?php
						// _n() handles the English singular/plural; the count is the
						// affected page count for this task.
						echo esc_html( _n( 'page', 'pages', $affected_count, 'seonix' ) );
						?>
					</span>
				</span>

				<span class="seonix-trow__priority">
					<span class="seonix-badge <?php echo esc_attr( $badge_class ); ?>">
						<?php echo esc_html( isset( $priority_label[ $priority ] ) ? $priority_label[ $priority ] : $priority_label['low'] ); ?>
					</span>
					<span class="seonix-trow__chevron" aria-hidden="true">&rsaquo;</span>
				</span>
			</button>

			<div class="seonix-task-detail" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
				<?php if ( $this->is_auto_fixable( $code ) ) : ?>
					<div class="seonix-task-detail__sec seonix-fixcta">
						<button type="button" class="seonix-btn seonix-btn--brand seonix-fix-btn" data-code="<?php echo esc_attr( $code ); ?>">
							<?php esc_html_e( 'Fix it for me', 'seonix' ); ?>
						</button>
						<p class="seonix-fixcta__note"><?php esc_html_e( 'Seonix applies this fix to your site automatically. Available on a paid plan.', 'seonix' ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $why_it_matters ) : ?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label"><?php esc_html_e( 'Why this matters', 'seonix' ); ?></div>
						<p class="seonix-task-detail__desc"><?php echo esc_html( $why_it_matters ); ?></p>
					</div>
				<?php elseif ( '' !== $description ) : ?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label"><?php esc_html_e( 'What it means', 'seonix' ); ?></div>
						<p class="seonix-task-detail__desc"><?php echo esc_html( $description ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $bad_code && '' !== $good_code ) : ?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label"><?php esc_html_e( 'Bad vs good', 'seonix' ); ?></div>
						<div class="seonix-bg-grid">
							<div class="seonix-bg-col seonix-bg-bad">
								<div class="seonix-bg-tag"><?php esc_html_e( 'Now', 'seonix' ); ?></div>
								<pre class="seonix-bg-code"><code><?php echo esc_html( $bad_code ); ?></code></pre>
								<?php if ( '' !== $bad_caption ) : ?>
									<div class="seonix-bg-cap"><?php echo esc_html( $bad_caption ); ?></div>
								<?php endif; ?>
							</div>
							<div class="seonix-bg-col seonix-bg-good">
								<div class="seonix-bg-tag"><?php esc_html_e( 'How it should be', 'seonix' ); ?></div>
								<pre class="seonix-bg-code"><code><?php echo esc_html( $good_code ); ?></code></pre>
								<?php if ( '' !== $good_caption ) : ?>
									<div class="seonix-bg-cap"><?php echo esc_html( $good_caption ); ?></div>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $fix_steps ) ) : ?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label"><?php esc_html_e( 'How to fix', 'seonix' ); ?></div>
						<ol class="seonix-fix-steps">
							<?php foreach ( $fix_steps as $step ) : ?>
								<li><?php echo esc_html( (string) $step ); ?></li>
							<?php endforeach; ?>
						</ol>
					</div>
				<?php elseif ( '' !== $recommendation ) : ?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label"><?php esc_html_e( 'How to fix', 'seonix' ); ?></div>
						<p class="seonix-task-detail__rec"><?php echo esc_html( $recommendation ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $warns ) ) : ?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label"><?php esc_html_e( 'Heads up', 'seonix' ); ?></div>
						<?php foreach ( $warns as $w ) : ?>
							<div class="seonix-task-warn"><?php echo esc_html( (string) $w ); ?></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php
				// Render the full list of every affected page for this task, decoded
				// from the affected_pages column. Each entry is a small status dot +
				// a link showing the page's RELATIVE path (full URL in the title).
				$pages = Seonix_Tasks::decode_pages( isset( $row['affected_pages'] ) ? $row['affected_pages'] : '' );
				if ( ! empty( $pages ) || '' !== $affected_url ) :
					?>
					<div class="seonix-task-detail__sec">
						<div class="seonix-msec-label">
							<?php
							printf(
								/* translators: %d: number of affected pages */
								esc_html__( 'Affected pages · %d', 'seonix' ),
								(int) $affected_count
							);
							?>
						</div>
						<?php
						if ( ! empty( $pages ) ) :
							$visible_cap = 100;
							$shown       = array_slice( $pages, 0, $visible_cap );
							$overflow    = count( $pages ) - count( $shown );
							?>
							<ul class="seonix-pagelist">
								<?php foreach ( $shown as $pg ) : ?>
									<?php
									$pg_url    = $pg['url'];
									$pg_status = $pg['status'];
									$rel       = $this->relative_path( $pg_url );
									?>
									<li>
										<span class="seonix-pagedot seonix-pagedot--<?php echo esc_attr( $pg_status ); ?>" aria-hidden="true"></span>
										<a href="<?php echo esc_url( $pg_url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $pg_url ); ?>"><?php echo esc_html( $rel ); ?></a>
									</li>
								<?php endforeach; ?>
								<?php if ( $overflow > 0 ) : ?>
									<li class="seonix-pagelist__more">
										<?php
										printf(
											/* translators: %d: number of additional affected pages not shown. */
											esc_html( _n( '+%d more page', '+%d more pages', $overflow, 'seonix' ) ),
											(int) $overflow
										);
										?>
									</li>
								<?php endif; ?>
							</ul>
						<?php else : ?>
							<p class="seonix-task-detail__meta">
								<a href="<?php echo esc_url( $affected_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $affected_url ); ?></a>
								<?php if ( $affected_count > 1 ) : ?>
									<span class="seonix-task-detail__count">
										<?php
										printf(
											/* translators: %d: number of affected pages */
											esc_html( _n( '+%d more page', '+%d more pages', $affected_count - 1, 'seonix' ) ),
											(int) ( $affected_count - 1 )
										);
										?>
									</span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Reduce a full URL to its display-friendly relative path (path + query).
	 * Mirrors the web app's PagesView.toRelative(): "/about?x=1". Falls back to
	 * the full URL when it can't be parsed. Returns a raw string — the caller
	 * escapes it on emit.
	 *
	 * @param string $url Full page URL.
	 * @return string Relative path, or the original URL on a parse failure.
	 */
	private function relative_path( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '' !== $url ? $url : '/';
		}
		$rel = $parts['path'];
		if ( ! empty( $parts['query'] ) ) {
			$rel .= '?' . $parts['query'];
		}
		return $rel;
	}

	/**
	 * Render one page row for the "By page" view: a clickable row (traffic-light
	 * status dot + relative URL + representative title + the three severity
	 * counts + chevron) PLUS a HIDDEN detail block listing every issue on that
	 * page. Clicking the row OPENS THE SHARED MODAL (the web app's modal) with
	 * that issue list as its body — admin.js clones the hidden .seonix-page-detail
	 * into the modal (no inline accordion). The wrapper carries data-url (for the
	 * client-side search) and data-title (the relative URL, for the modal header).
	 * Mirrors the web app's PagesView row. Every field is escaped on emit.
	 *
	 * @param string                                                         $url   The full page URL.
	 * @param array{title:string,errors:int,warnings:int,notices:int,issues:array<int,array<string,string>>} $page  Aggregated page data.
	 * @param int                                                            $index Stable index for the panel id.
	 */
	private function render_page_row( string $url, array $page, int $index ) {
		$errors   = isset( $page['errors'] ) ? (int) $page['errors'] : 0;
		$warnings = isset( $page['warnings'] ) ? (int) $page['warnings'] : 0;
		$notices  = isset( $page['notices'] ) ? (int) $page['notices'] : 0;
		$rep      = isset( $page['title'] ) ? (string) $page['title'] : '';
		$issues   = isset( $page['issues'] ) && is_array( $page['issues'] ) ? $page['issues'] : array();
		$rel      = $this->relative_path( $url );

		// Status dot tone: red if any error, amber elif warning, slate elif
		// notice, else a green check (mirrors PagesView's StatusDot).
		if ( $errors > 0 ) {
			$dot_class = 'seonix-pagedot--error';
		} elseif ( $warnings > 0 ) {
			$dot_class = 'seonix-pagedot--warning';
		} elseif ( $notices > 0 ) {
			$dot_class = 'seonix-pagedot--notice';
		} else {
			$dot_class = 'seonix-pagedot--clean';
		}

		$category_label = array(
			'seo'       => __( 'SEO', 'seonix' ),
			'technical' => __( 'Technical', 'seonix' ),
			'speed'     => __( 'Speed', 'seonix' ),
			'ai'        => __( 'AI Search', 'seonix' ),
		);

		$panel_id = 'seonix-page-detail-' . (int) $index;
		// Lowercased URL for the client-side search filter.
		$data_url = function_exists( 'mb_strtolower' ) ? mb_strtolower( $url ) : strtolower( $url );
		?>
		<div
			class="seonix-page-item"
			data-url="<?php echo esc_attr( $data_url ); ?>"
			data-title="<?php echo esc_attr( $rel ); ?>"
		>
			<button type="button" class="seonix-prow" aria-haspopup="dialog" aria-controls="seonix-modal">
				<span class="seonix-prow__page">
					<span class="seonix-pagedot <?php echo esc_attr( $dot_class ); ?>" aria-hidden="true"></span>
					<span class="seonix-prow__main">
						<span class="seonix-prow__url" title="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $rel ); ?></span>
						<?php if ( '' !== $rep ) : ?>
							<span class="seonix-prow__title"><?php echo esc_html( $rep ); ?></span>
						<?php endif; ?>
					</span>
				</span>

				<span class="seonix-prow__num seonix-prow__num--error"><?php echo $errors > 0 ? esc_html( (string) $errors ) : '&mdash;'; ?></span>
				<span class="seonix-prow__num seonix-prow__num--warning"><?php echo $warnings > 0 ? esc_html( (string) $warnings ) : '&mdash;'; ?></span>
				<span class="seonix-prow__num seonix-prow__num--notice"><?php echo $notices > 0 ? esc_html( (string) $notices ) : '&mdash;'; ?></span>

				<span class="seonix-prow__chevron" aria-hidden="true">&rsaquo;</span>
			</button>

			<div class="seonix-page-detail" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
				<ul class="seonix-issuelist">
					<?php foreach ( $issues as $issue ) : ?>
						<?php
						$i_title    = isset( $issue['title'] ) ? (string) $issue['title'] : '';
						$i_severity = isset( $issue['severity'] ) ? (string) $issue['severity'] : 'notice';
						if ( ! in_array( $i_severity, array( 'error', 'warning', 'notice' ), true ) ) {
							$i_severity = 'notice';
						}
						$i_category = isset( $issue['category'] ) ? (string) $issue['category'] : 'seo';
						if ( ! in_array( $i_category, array( 'seo', 'technical', 'speed', 'ai' ), true ) ) {
							$i_category = 'seo';
						}
						?>
						<li class="seonix-issuerow">
							<span class="seonix-pagedot seonix-pagedot--<?php echo esc_attr( $i_severity ); ?>" aria-hidden="true"></span>
							<span class="seonix-issuerow__title"><?php echo esc_html( $i_title ); ?></span>
							<span class="seonix-cat seonix-cat--<?php echo esc_attr( $i_category ); ?>"><?php echo esc_html( isset( $category_label[ $i_category ] ) ? $category_label[ $i_category ] : $category_label['seo'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	// ─── Render: Settings ────────────────────────────────────────

	/**
	 * Render the settings page (API key, post author, site data, connection).
	 */
	public function render_page() {
		$api_key      = Seonix_Auth::get_key();
		$is_connected = Seonix_Auth::is_connected();
		$project_name = get_option( 'seonix_project_name', '' );
		$engine_url   = get_option( 'seonix_engine_url', '' );
		$connected_at = get_option( 'seonix_connected_at', '' );
		$author_id    = (int) get_option( 'seonix_post_author', 0 );
		$schema_mode  = Seonix_Schema::mode();
		$meta_mode    = Seonix_Meta_Renderer::mode();
		$seo_engines  = Seonix_SEO_Engine::detect_all();
		$key_preview  = ! empty( $api_key ) ? substr( $api_key, 0, 11 ) . str_repeat( '*', 20 ) : '';

		// Get sync data.
		$last_synced    = get_option( 'seonix_last_synced_at', '' );
		$sync_counts    = get_option( 'seonix_sync_counts', array() );
		$pages_count    = isset( $sync_counts['pages'] ) ? (int) $sync_counts['pages'] : 0;
		$posts_count    = isset( $sync_counts['posts'] ) ? (int) $sync_counts['posts'] : 0;
		$products_count = isset( $sync_counts['products'] ) ? (int) $sync_counts['products'] : 0;

		// Get users who can edit posts (for author dropdown).
		$authors = get_users( array(
			'capability' => 'edit_posts',
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'fields'     => array( 'ID', 'display_name' ),
		) );

		?>
		<?php $this->render_header( 'settings', $is_connected, $project_name ); ?>

				<?php if ( $is_connected ) : ?>
					<!-- Status card -->
					<div class="seonix-card seonix-card--success">
						<div class="seonix-statusrow">
							<div class="seonix-status seonix-status--connected">
								<span class="seonix-status__dot seonix-status__dot--green"></span>
								<?php esc_html_e( 'Connected', 'seonix' ); ?>
							</div>
							<button type="button" class="seonix-btn seonix-btn--secondary seonix-btn--sm" id="seonix-reconnect-btn">
								<?php esc_html_e( 'Reconnect', 'seonix' ); ?>
							</button>
						</div>

						<table class="seonix-info-table">
							<tr>
								<th><?php esc_html_e( 'Site', 'seonix' ); ?></th>
								<td><?php echo esc_html( get_bloginfo( 'name' ) ); ?></td>
							</tr>
							<?php if ( ! empty( $project_name ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Project', 'seonix' ); ?></th>
								<td><?php echo esc_html( $project_name ); ?></td>
							</tr>
							<?php endif; ?>
							<?php if ( ! empty( $connected_at ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Connected', 'seonix' ); ?></th>
								<td><?php echo esc_html( wp_date( 'F j, Y \a\t g:i A', strtotime( $connected_at ) ) ); ?></td>
							</tr>
							<?php endif; ?>
							<tr>
								<th><?php esc_html_e( 'Version', 'seonix' ); ?></th>
								<td><?php echo esc_html( SEONIX_VERSION ); ?></td>
							</tr>
						</table>
					</div>
				<?php endif; ?>

				<div class="seonix-cols">
					<div class="seonix-cols__main">
						<?php if ( ! $is_connected ) : ?>
							<?php
							// Not connected → lead with what already works without an
							// account (llms.txt + IndexNow) so Settings is useful on
							// day zero, before any Seonix project exists.
							$this->render_standalone_cards();
							?>
						<?php endif; ?>

						<!-- API Key card -->
						<div class="seonix-card">
							<h2><?php esc_html_e( 'API Key', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'Use this key to connect Seonix to your WordPress site.', 'seonix' ); ?></p>

							<div class="seonix-key-display">
								<code class="seonix-key-value" id="seonix-key-preview"><?php echo esc_html( $key_preview ); ?></code>
								<button type="button" id="seonix-copy-key-btn" class="seonix-btn seonix-btn--secondary seonix-btn--sm"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'seonix_get_api_key' ) ); ?>">
									<?php esc_html_e( 'Copy API key', 'seonix' ); ?>
								</button>
							</div>

							<div class="seonix-actions">
								<button type="button" id="seonix-regenerate-key-btn" class="seonix-btn seonix-btn--danger seonix-btn--sm">
									<?php esc_html_e( 'Regenerate Key', 'seonix' ); ?>
								</button>
							</div>
						</div>

						<!-- Post Author card -->
						<div class="seonix-card">
							<h2><?php esc_html_e( 'Post Author', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'Select the default author for posts published via Seonix.', 'seonix' ); ?></p>

							<div class="seonix-field">
								<select id="seonix-author-select" class="seonix-select">
									<option value="0"><?php esc_html_e( 'Default (site admin)', 'seonix' ); ?></option>
									<?php foreach ( $authors as $author ) : ?>
										<option value="<?php echo esc_attr( $author->ID ); ?>" <?php selected( $author_id, $author->ID ); ?>>
											<?php echo esc_html( $author->display_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="seonix-actions">
								<button type="button" id="seonix-save-author-btn" class="seonix-btn seonix-btn--primary seonix-btn--sm">
									<?php esc_html_e( 'Save', 'seonix' ); ?>
								</button>
							</div>
						</div>

						<!-- Structured Data (JSON-LD) card -->
						<div class="seonix-card">
							<h2><?php esc_html_e( 'Structured Data (JSON-LD)', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'Output schema.org structured data for Seonix articles. "Auto" stays silent when another SEO plugin is active, so schema is never duplicated.', 'seonix' ); ?></p>

							<div class="seonix-field">
								<select id="seonix-schema-mode-select" class="seonix-select">
									<option value="auto" <?php selected( $schema_mode, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended) — only when no SEO plugin is active', 'seonix' ); ?></option>
									<option value="on" <?php selected( $schema_mode, 'on' ); ?>><?php esc_html_e( 'On — always output Seonix schema', 'seonix' ); ?></option>
									<option value="off" <?php selected( $schema_mode, 'off' ); ?>><?php esc_html_e( 'Off — never output', 'seonix' ); ?></option>
								</select>
							</div>

							<div class="seonix-actions">
								<button type="button" id="seonix-save-schema-mode-btn" class="seonix-btn seonix-btn--primary seonix-btn--sm">
									<?php esc_html_e( 'Save', 'seonix' ); ?>
								</button>
							</div>
						</div>

						<!-- SEO Meta Tags card -->
						<div class="seonix-card">
							<h2><?php esc_html_e( 'SEO Meta Tags', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'SEO title, meta description and social tags for Seonix articles. Seonix always syncs these into your SEO plugin (Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework). "Auto" additionally renders the tags itself only when no SEO plugin is active, so tags are never duplicated.', 'seonix' ); ?></p>

							<?php if ( count( $seo_engines ) >= 2 ) : ?>
								<p class="seonix-subtitle" style="color:#b45309;">
									<?php
									printf(
										/* translators: %s: comma-separated SEO plugin slugs */
										esc_html__( 'Heads up: multiple SEO plugins are active (%s). They will emit duplicate meta tags — consider keeping only one.', 'seonix' ),
										esc_html( implode( ', ', $seo_engines ) )
									);
									?>
								</p>
							<?php elseif ( ! empty( $seo_engines ) ) : ?>
								<p class="seonix-subtitle">
									<?php
									printf(
										/* translators: %s: SEO plugin slug */
										esc_html__( 'Detected SEO plugin: %s — Seonix writes titles and descriptions straight into it.', 'seonix' ),
										esc_html( $seo_engines[0] )
									);
									?>
								</p>
							<?php else : ?>
								<p class="seonix-subtitle"><?php esc_html_e( 'No SEO plugin detected — Seonix serves the meta tags for its articles.', 'seonix' ); ?></p>
							<?php endif; ?>

							<div class="seonix-field">
								<select id="seonix-meta-mode-select" class="seonix-select">
									<option value="auto" <?php selected( $meta_mode, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended) — render only when no SEO plugin is active', 'seonix' ); ?></option>
									<option value="on" <?php selected( $meta_mode, 'on' ); ?>><?php esc_html_e( 'On — always render Seonix meta tags', 'seonix' ); ?></option>
									<option value="off" <?php selected( $meta_mode, 'off' ); ?>><?php esc_html_e( 'Off — never render (sync into SEO plugins still works)', 'seonix' ); ?></option>
								</select>
							</div>

							<div class="seonix-actions">
								<button type="button" id="seonix-save-meta-mode-btn" class="seonix-btn seonix-btn--primary seonix-btn--sm">
									<?php esc_html_e( 'Save', 'seonix' ); ?>
								</button>
							</div>
						</div>

						<!-- Site Data card -->
						<div class="seonix-card">
							<h2><?php esc_html_e( 'Site Data', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'Pages, posts, and products synced for AI context and internal linking.', 'seonix' ); ?></p>

							<div class="seonix-stats">
								<div class="seonix-stat">
									<div class="seonix-stat__value" id="seonix-sync-pages"><?php echo esc_html( $pages_count ); ?></div>
									<div class="seonix-stat__label"><?php esc_html_e( 'Pages', 'seonix' ); ?></div>
								</div>
								<div class="seonix-stat">
									<div class="seonix-stat__value" id="seonix-sync-posts"><?php echo esc_html( $posts_count ); ?></div>
									<div class="seonix-stat__label"><?php esc_html_e( 'Posts', 'seonix' ); ?></div>
								</div>
								<div class="seonix-stat">
									<div class="seonix-stat__value" id="seonix-sync-products"><?php echo esc_html( $products_count ); ?></div>
									<div class="seonix-stat__label"><?php esc_html_e( 'Products', 'seonix' ); ?></div>
								</div>
							</div>

							<p class="seonix-sync-time" id="seonix-last-synced">
								<?php if ( $last_synced ) : ?>
									<?php
									printf(
										/* translators: %s: formatted date/time of last sync */
										esc_html__( 'Last synced %s', 'seonix' ),
										esc_html( wp_date( 'F j, Y \a\t g:i A', strtotime( $last_synced ) ) )
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Not synced yet', 'seonix' ); ?>
								<?php endif; ?>
							</p>

							<button type="button" id="seonix-sync-btn" class="seonix-btn seonix-btn--secondary seonix-btn--sm">
								<?php esc_html_e( 'Sync Now', 'seonix' ); ?>
							</button>
						</div>

						<?php if ( $is_connected ) : ?>
							<?php
							// Connected → the standalone cards (llms.txt + IndexNow)
							// still matter (status + toggle live here), they just sit
							// below the connection-specific cards.
							$this->render_standalone_cards();
							?>
						<?php endif; ?>
					</div>

					<div class="seonix-cols__side">
						<?php if ( $is_connected ) : ?>
							<!-- Plan & AI features card. Filled by admin.js from
							     GET /api/plugin/account. Badge starts neutral; the
							     buttons carry PHP-side fallback hrefs. -->
							<div class="seonix-card seonix-plancard" id="seonix-plan-card">
								<div class="seonix-plancard__head">
									<h2><?php esc_html_e( 'Your plan', 'seonix' ); ?></h2>
									<span class="seonix-planbadge" id="seonix-plan-badge" data-tier="">
										<span class="seonix-planbadge__txt">&hellip;</span>
									</span>
								</div>
								<p class="seonix-subtitle" id="seonix-plan-sub"><?php esc_html_e( 'Checking your plan&hellip;', 'seonix' ); ?></p>

								<div class="seonix-aifeat">
									<span class="seonix-aifeat__ico" aria-hidden="true">&#10022;</span>
									<p class="seonix-aifeat__txt">
										<?php esc_html_e( 'AI generation, refinement and one-click SEO fixes run from the Seonix dashboard and right here in the plugin.', 'seonix' ); ?>
										<button type="button" class="seonix-link" id="seonix-aifeat-more"><?php esc_html_e( "What's included?", 'seonix' ); ?></button>
									</p>
								</div>

								<div class="seonix-plancard__actions">
									<a class="seonix-btn seonix-btn--brand seonix-btn--sm" id="seonix-plan-upgrade"
										href="<?php echo esc_url( $this->billing_url() ); ?>"
										target="_blank" rel="noopener" hidden>
										<?php esc_html_e( 'Upgrade this project', 'seonix' ); ?>
									</a>
									<a class="seonix-btn seonix-btn--secondary seonix-btn--sm" id="seonix-plan-open"
										href="<?php echo esc_url( $this->dashboard_url() ); ?>"
										target="_blank" rel="noopener">
										<?php esc_html_e( 'Open in Seonix', 'seonix' ); ?>
									</a>
								</div>
							</div>

							<!-- Paid-AI popup — mirrors the web dashboard's AI_PAYWALL modal.
							     Opened by "What's included?"; CTA goes to this project's
							     billing page inside Seonix (admin.js swaps the exact URL). -->
							<div class="seonix-modal seonix-modal--paywall" id="seonix-paywall-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="seonix-paywall-title">
								<div class="seonix-modal__backdrop" data-seonix-paywall-close></div>
								<div class="seonix-modal__panel" role="document">
									<div class="seonix-modal__head">
										<div class="seonix-modal__heading">
											<h2 class="seonix-modal__title" id="seonix-paywall-title">
												<span class="seonix-paywall__spark" aria-hidden="true">&#10022;</span>
												<?php esc_html_e( 'AI features are part of a paid plan', 'seonix' ); ?>
											</h2>
										</div>
										<button type="button" class="seonix-modal__close" data-seonix-paywall-close aria-label="<?php esc_attr_e( 'Close', 'seonix' ); ?>">&times;</button>
									</div>
									<div class="seonix-modal__body">
										<p class="seonix-paywall__body"><?php esc_html_e( 'Upgrade this project to unlock AI generation, refinement, and one-click SEO fixes. These run from the Seonix dashboard and from this plugin. Other projects keep their current plan.', 'seonix' ); ?></p>
										<ul class="seonix-paywall__list">
											<li><?php esc_html_e( 'AI article generation & refinement', 'seonix' ); ?></li>
											<li><?php esc_html_e( 'One-click SEO auto-fixes', 'seonix' ); ?></li>
											<li><?php esc_html_e( 'Auto-publishing on a schedule', 'seonix' ); ?></li>
										</ul>
									</div>
									<div class="seonix-modal__foot">
										<button type="button" class="seonix-btn seonix-btn--secondary seonix-btn--sm" data-seonix-paywall-close>
											<?php esc_html_e( 'Close', 'seonix' ); ?>
										</button>
										<a class="seonix-btn seonix-btn--brand seonix-btn--sm" id="seonix-paywall-cta"
											href="<?php echo esc_url( $this->billing_url() ); ?>"
											target="_blank" rel="noopener">
											<?php esc_html_e( 'Upgrade this project', 'seonix' ); ?>
										</a>
									</div>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $is_connected && ! empty( $project_name ) ) : ?>
							<!-- Connection card -->
							<div class="seonix-card">
								<h2><?php esc_html_e( 'Connection', 'seonix' ); ?></h2>
								<table class="seonix-info-table">
									<tr>
										<th><?php esc_html_e( 'Project', 'seonix' ); ?></th>
										<td><?php echo esc_html( $project_name ); ?></td>
									</tr>
								</table>
							</div>
						<?php endif; ?>

						<!-- Setup instructions card -->
						<div class="seonix-card">
							<h2><?php esc_html_e( 'Setup Instructions', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'The fastest way to connect is the one-click button on the Problems tab. To connect manually instead, follow these steps.', 'seonix' ); ?></p>

							<ol class="seonix-steps">
								<li><?php esc_html_e( 'Copy the API key above using the Copy button.', 'seonix' ); ?></li>
								<li><?php esc_html_e( 'Open your project in Seonix and go to Channels.', 'seonix' ); ?></li>
								<li><?php esc_html_e( 'Create a new WordPress channel and paste the API key.', 'seonix' ); ?></li>
							</ol>
						</div>
					</div>
				</div>
		<?php $this->render_footer(); ?>
		<?php
	}
}
