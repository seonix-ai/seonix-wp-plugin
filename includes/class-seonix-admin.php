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
	 * Self-contained base64 data-URI for the menu icon: the Seonix brand mark (the
	 * two-sparkle favicon from seonix.ai) drawn as a crisp VECTOR — a rounded square
	 * with the dark-top → purple/blue gradient and two white four-point sparkles.
	 *
	 * Vector, not the raster favicon, because the admin menu renders the icon at
	 * just 20px: a downscaled raster frays at the rounded bottom corners against the
	 * dark menu, while an SVG stays razor-sharp at any size. WordPress renders an
	 * SVG-data-URI menu icon in full colour at `background-size: 20px` (it does NOT
	 * mask/recolour SVG icons — only dashicon fonts). Gradient ids are namespaced
	 * (sx-*) so they can't collide with another inline SVG on the page. Inlined so
	 * there's no extra HTTP request and no external asset (WordPress.org friendly).
	 *
	 * @return string The data URI.
	 */
	private function menu_icon(): string {
		// One four-point sparkle, unit-sized (centred on 0,0), placed twice.
		$sp = 'M0,-1C0.11,-0.29 0.29,-0.11 1,0C0.29,0.11 0.11,0.29 0,1C-0.11,0.29 -0.29,0.11 -1,0C-0.29,-0.11 -0.11,-0.29 0,-1Z';
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 100 100">'
			. '<defs>'
			. '<linearGradient id="sx-base" x1="10" y1="90" x2="90" y2="90" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#9D33EE"/><stop offset="1" stop-color="#27A8EA"/></linearGradient>'
			. '<linearGradient id="sx-dark" x1="50" y1="0" x2="50" y2="100" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#120F25"/><stop offset="0.66" stop-color="#120F25" stop-opacity="0"/></linearGradient>'
			. '</defs>'
			. '<rect width="100" height="100" rx="24" fill="url(#sx-base)"/>'
			. '<rect width="100" height="100" rx="24" fill="url(#sx-dark)"/>'
			. '<path transform="translate(33 48) scale(15.5)" fill="#fff" d="' . $sp . '"/>'
			. '<path transform="translate(67 48) scale(15.5)" fill="#fff" d="' . $sp . '"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
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

		wp_enqueue_style(
			'seonix-admin',
			SEONIX_URL . 'assets/admin.css',
			array(),
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

	/**
	 * Render the shared page header (logo + title). Both render methods call
	 * this so the chrome stays identical across the two pages.
	 *
	 * The in-page nav-tab bar that used to live here was removed: it duplicated
	 * the WordPress submenu (Seonix → Problems / Settings already appears in the
	 * left admin menu), so the tabs were redundant chrome.
	 */
	private function render_header() {
		?>
		<div class="seonix-header">
			<img class="seonix-header__logo" src="<?php echo esc_url( plugins_url( 'assets/seonix-logo.png', SEONIX_FILE ) ); ?>" alt="<?php esc_attr_e( 'Seonix', 'seonix' ); ?>" width="40" height="40" />
			<div class="seonix-header__text">
				<h1><?php esc_html_e( 'Seonix', 'seonix' ); ?></h1>
				<p><?php esc_html_e( 'Your site health and SEO tasks, kept in sync with Seonix.', 'seonix' ); ?></p>
			</div>
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

		$cat_labels = array(
			'seo'       => __( 'SEO', 'seonix' ),
			'technical' => __( 'Technical', 'seonix' ),
			'ai'        => __( 'AI Search', 'seonix' ),
		);

		// Overall score colour zone — mirrors the web app's CircularGauge
		// thresholds (green >=90, amber >=50, red <50). Drives the ring colour.
		$ring_color = $score >= 90 ? '#22c55e' : ( $score >= 50 ? '#f59e0b' : '#ef4444' );

		?>
		<div class="wrap">
			<div class="seonix-wrap">
				<?php $this->render_header(); ?>

				<div id="seonix-notices"></div>

				<!-- Slim connection bar -->
				<div class="seonix-connbar<?php echo $is_connected ? ' seonix-connbar--connected' : ''; ?>">
					<?php if ( $is_connected ) : ?>
						<div class="seonix-connbar__info">
							<span class="seonix-status seonix-status--connected">
								<span class="seonix-status__dot seonix-status__dot--green"></span>
								<?php esc_html_e( 'Connected', 'seonix' ); ?>
							</span>
							<?php if ( ! empty( $project_name ) ) : ?>
								<span class="seonix-connbar__project">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: Seonix project name */
											__( 'Linked to project: %s', 'seonix' ),
											'<strong>' . esc_html( $project_name ) . '</strong>'
										),
										array( 'strong' => array() )
									);
									?>
								</span>
							<?php endif; ?>
						</div>
						<div class="seonix-connbar__actions">
							<button type="button" class="seonix-btn seonix-btn--secondary seonix-btn--sm" id="seonix-reconnect-btn">
								<?php esc_html_e( 'Reconnect', 'seonix' ); ?>
							</button>
						</div>
					<?php else : ?>
						<div class="seonix-connbar__info">
							<h2><?php esc_html_e( 'Connect to Seonix', 'seonix' ); ?></h2>
							<p class="seonix-subtitle"><?php esc_html_e( 'Link this site to Seonix in one click. We will analyze your site and start surfacing SEO tasks here.', 'seonix' ); ?></p>
						</div>
						<div class="seonix-connbar__actions">
							<button type="button" class="seonix-btn seonix-btn--primary" id="seonix-connect-btn">
								<?php esc_html_e( 'Connect to Seonix', 'seonix' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $is_connected ) : ?>
					<!-- Site Health: overall score ring + category bars -->
					<div class="seonix-card">
						<h2><?php esc_html_e( 'Site Health', 'seonix' ); ?></h2>
						<div class="seonix-health">
							<div class="seonix-health__score">
								<div class="seonix-ring" style="background: conic-gradient(<?php echo esc_attr( $ring_color ); ?> calc(<?php echo esc_attr( (string) $score ); ?> * 1%), #e2e8f0 0);">
									<div class="seonix-ring__inner">
										<span class="seonix-ring__value" style="color: <?php echo esc_attr( $ring_color ); ?>;"><?php echo esc_html( $score ); ?></span>
									</div>
								</div>
								<span class="seonix-health__label"><?php esc_html_e( 'Overall site health', 'seonix' ); ?></span>
							</div>

							<div class="seonix-health__body">
								<div class="seonix-stats seonix-stats--health">
									<div class="seonix-stat">
										<div class="seonix-stat__value"><?php echo esc_html( $open ); ?></div>
										<div class="seonix-stat__label"><?php esc_html_e( 'Open', 'seonix' ); ?></div>
									</div>
									<div class="seonix-stat">
										<div class="seonix-stat__value"><?php echo esc_html( $solved ); ?></div>
										<div class="seonix-stat__label"><?php esc_html_e( 'Solved', 'seonix' ); ?></div>
									</div>
									<div class="seonix-stat">
										<div class="seonix-stat__value"><?php echo esc_html( $regressed ); ?></div>
										<div class="seonix-stat__label"><?php esc_html_e( 'Came back', 'seonix' ); ?></div>
									</div>
								</div>

								<?php if ( ! empty( $cats ) ) : ?>
									<!-- Category bars double as a filter for the By-issue task list:
									     each bar is a button carrying data-category; clicking it (in
									     admin.js) narrows the issue rows to that Site Health bucket and
									     flips the lifecycle tab to "All". Mirrors the web app's hero
									     score bars driving IssueTaskListPanel's category filter. -->
									<div class="seonix-bars">
										<?php foreach ( $cats as $cat ) : ?>
											<?php
											$cat_key      = isset( $cat['key'] ) ? (string) $cat['key'] : '';
											if ( ! in_array( $cat_key, array( 'seo', 'technical', 'ai' ), true ) ) {
												$cat_key = 'seo';
											}
											$cat_score    = isset( $cat['score'] ) ? (int) $cat['score'] : 0;
											$cat_label    = isset( $cat_labels[ $cat_key ] ) ? $cat_labels[ $cat_key ] : $cat_key;
											$cat_fill_col = $cat_score >= 90 ? '#22c55e' : ( $cat_score >= 50 ? '#f59e0b' : '#ef4444' );
											?>
											<button type="button" class="seonix-bar" data-category="<?php echo esc_attr( $cat_key ); ?>" aria-pressed="false">
												<span class="seonix-bar__label"><?php echo esc_html( $cat_label ); ?></span>
												<span class="seonix-bar__track">
													<span class="seonix-bar__fill" style="width: <?php echo esc_attr( (string) $cat_score ); ?>%; background: <?php echo esc_attr( $cat_fill_col ); ?>;"></span>
												</span>
												<span class="seonix-bar__value" style="color: <?php echo esc_attr( $cat_fill_col ); ?>;"><?php echo esc_html( $cat_score ); ?></span>
											</button>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

								<p class="seonix-sync-time seonix-sync-time--row">
									<span class="seonix-sync-time__text">
										<?php if ( $synced_at > 0 ) : ?>
											<?php
											printf(
												/* translators: %s: formatted date/time of last task sync */
												esc_html__( 'Tasks last updated %s', 'seonix' ),
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
								</p>
							</div>
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
								<h2><?php esc_html_e( 'Task list', 'seonix' ); ?></h2>
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
							$count_open      = 0;
							$count_solved    = 0;
							$count_regressed = 0;
							foreach ( $rows as $r ) {
								$st       = isset( $r['status'] ) ? (string) $r['status'] : 'open';
								$affected = isset( $r['affected_count'] ) ? (int) $r['affected_count'] : 0;
								if ( 'solved' === $st ) {
									$count_solved += $affected;
								} elseif ( 'regressed' === $st ) {
									$count_regressed += $affected;
								} else {
									$count_open += $affected;
								}
							}
							$tab_counts = array(
								'active' => $count_open + $count_regressed,
								'solved' => $count_solved,
								'regressed' => $count_regressed,
								'all'    => $count_open + $count_solved + $count_regressed,
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
								if ( ! in_array( $r_category, array( 'seo', 'technical', 'ai' ), true ) ) {
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
			</div>
		</div>
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
		if ( ! in_array( $category, array( 'seo', 'technical', 'ai' ), true ) ) {
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
				<?php if ( '' !== $description ) : ?>
					<p class="seonix-task-detail__desc"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $recommendation ) : ?>
					<p class="seonix-task-detail__rec"><strong><?php esc_html_e( 'How to fix:', 'seonix' ); ?></strong> <?php echo esc_html( $recommendation ); ?></p>
				<?php endif; ?>
				<?php
				// Render the full list of every affected page for this task, decoded
				// from the affected_pages column. Each entry is a small status dot +
				// a link showing the page's RELATIVE path (full URL in the title).
				$pages = Seonix_Tasks::decode_pages( isset( $row['affected_pages'] ) ? $row['affected_pages'] : '' );
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
				<?php elseif ( '' !== $affected_url ) : ?>
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
						if ( ! in_array( $i_category, array( 'seo', 'technical', 'ai' ), true ) ) {
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
		<div class="wrap">
			<div class="seonix-wrap">
				<?php $this->render_header(); ?>

				<div id="seonix-notices"></div>

				<?php if ( $is_connected ) : ?>
					<!-- Status card -->
					<div class="seonix-card seonix-card--success">
						<div class="seonix-status seonix-status--connected">
							<span class="seonix-status__dot seonix-status__dot--green"></span>
							<?php esc_html_e( 'Connected', 'seonix' ); ?>
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
							<p class="seonix-subtitle"><?php esc_html_e( 'Output schema.org structured data for Seonix articles. "Auto" stays silent when an SEO plugin (Yoast, Rank Math, AIOSEO) is active, so it never duplicates their schema.', 'seonix' ); ?></p>

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
					</div>

					<div class="seonix-cols__side">
						<?php if ( $is_connected && ! empty( $engine_url ) ) : ?>
							<!-- Connection card -->
							<div class="seonix-card">
								<h2><?php esc_html_e( 'Connection', 'seonix' ); ?></h2>
								<table class="seonix-info-table">
									<tr>
										<th><?php esc_html_e( 'Engine URL', 'seonix' ); ?></th>
										<td><code><?php echo esc_html( $engine_url ); ?></code></td>
									</tr>
									<?php if ( ! empty( $project_name ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Project', 'seonix' ); ?></th>
										<td><?php echo esc_html( $project_name ); ?></td>
									</tr>
									<?php endif; ?>
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
			</div>
		</div>
		<?php
	}
}
