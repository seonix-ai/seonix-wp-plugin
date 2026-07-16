<?php
/**
 * wp-admin screen for the native Seonix redirect manager.
 *
 * Adds a "Redirects" submenu under the existing top-level Seonix menu with:
 *   - the rule list (from → to, status code, source badge, hits, created);
 *   - an "Add redirect" form creating Local rows (seonix_id NULL);
 *   - per-row Enable/Disable and Delete actions.
 *
 * Deleting follows the tombstone contract: Seonix-managed rows (seonix_id
 * present) are soft-deleted so the Seonix service learns about the deletion on
 * its next sync; Local rows are removed outright.
 *
 * Form handling goes through admin_post_* actions (nonce + manage_options on
 * every mutation) with a redirect back to the screen, so refresh never
 * re-submits. Rendering opens the shared Seonix_Admin_Shell, the same chrome
 * Site Health and Settings draw: this is a Seonix screen, and a screen that
 * renders a bare .wrap reads as a different plugin. The shell degrades to brand
 * + tabs when the site was never linked, so it works on unconnected sites too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Admin {

	/** Submenu slug (parent is Seonix_Admin::MENU_SLUG). */
	const PAGE_SLUG = 'seonix-redirects';

	/** @var Seonix_Redirects_Store */
	private $store;

	/** @var Seonix_Admin_Shell */
	private $shell;

	/** @var Seonix_Redirects_Log|null */
	private $log;

	public function __construct( Seonix_Redirects_Store $store, Seonix_Admin_Shell $shell = null, Seonix_Redirects_Log $log = null ) {
		$this->store = $store;
		$this->shell = $shell ?? new Seonix_Admin_Shell( $store );
		$this->log   = $log;
	}

	/**
	 * Attach menu + form-action hooks. Menu registration runs at priority 11
	 * so the parent Seonix menu (registered at the default 10 by
	 * Seonix_Admin) exists before the submenu is attached.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_seonix_redirects_add', array( $this, 'handle_add' ) );
		add_action( 'admin_post_seonix_redirects_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_seonix_redirects_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_seonix_redirects_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_seonix_redirects_log_dismiss', array( $this, 'handle_log_dismiss' ) );
		add_action( 'admin_post_seonix_redirects_log_clear', array( $this, 'handle_log_clear' ) );
		add_action( 'admin_post_seonix_redirects_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_seonix_redirects_import', array( $this, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * This screen's own stylesheet, on top of the shell's.
	 *
	 * The chrome (brand webfonts, admin.css, admin.js) is enqueued for every
	 * Seonix screen by Seonix_Admin::enqueue_assets — including this one — so
	 * only the table and form styles are added here. Declaring `seonix-admin` as
	 * a dependency is what pins the order: these rules refine the shell's and
	 * have to be printed after it, not whenever the hooks happen to fire.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'seonix-redirects',
			SEONIX_URL . 'assets/redirects.css',
			array( 'seonix-admin' ),
			SEONIX_VERSION
		);
	}

	/**
	 * The Redirects entry in the Seonix menu.
	 *
	 * Positioned explicitly (Seonix_Admin owns the order) so the sidebar reads
	 * Problems → Redirects → Settings, matching the nav row inside the page.
	 * Without it this lands last, because it registers on a later hook.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Seonix_Admin::MENU_SLUG,
			__( 'Redirects', 'seonix' ),
			__( 'Redirects', 'seonix' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			Seonix_Admin::POS_REDIRECTS
		);
	}

	// ─── Form handlers (admin_post_*) ─────────────────────────────────────

	/**
	 * Create a Local redirect from the add form.
	 */
	public function handle_add(): void {
		$this->guard( 'seonix_redirects_add' );

		$from_path   = isset( $_POST['from_path'] ) ? trim( (string) wp_unslash( $_POST['from_path'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Seonix_Redirects_Store::validate_rule below.
		$to_url      = isset( $_POST['to_url'] ) ? trim( (string) wp_unslash( $_POST['to_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Seonix_Redirects_Store::validate_rule below.
		$status_code = isset( $_POST['status_code'] ) ? (int) $_POST['status_code'] : 301;
		$is_regex    = ! empty( $_POST['is_regex'] );

		$result = $this->store->create( array(
			'seonix_id'   => null,
			'from_path'   => $from_path,
			'to_url'      => $to_url,
			'status_code' => $status_code,
			'is_regex'    => $is_regex,
			'enabled'     => true,
		) );

		if ( is_wp_error( $result ) ) {
			$this->back( 'error', $result->get_error_message() );
		}
		// The path now redirects, so it is no longer a dead end — drop it from
		// the 404 log (no-op if it was never logged, e.g. a regex rule).
		if ( null !== $this->log && ! $is_regex ) {
			$this->log->forget_path( $from_path );
		}
		$this->back( 'added' );
	}

	/**
	 * Forget one logged 404 (the operator judged it not worth a redirect).
	 */
	public function handle_log_dismiss(): void {
		$this->guard( 'seonix_redirects_log_dismiss' );
		if ( null !== $this->log ) {
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			if ( $id > 0 ) {
				$this->log->delete( $id );
			}
		}
		$this->back( 'log_dismissed' );
	}

	/**
	 * Empty the whole 404 log.
	 */
	public function handle_log_clear(): void {
		$this->guard( 'seonix_redirects_log_clear' );
		if ( null !== $this->log ) {
			$this->log->clear();
		}
		$this->back( 'log_cleared' );
	}

	/**
	 * Stream every rule as a CSV download — a backup, and the other half of a
	 * migration between sites.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage redirects.', 'seonix' ) );
		}
		check_admin_referer( 'seonix_redirects_export' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="seonix-redirects.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'from_path', 'to_url', 'status_code', 'is_regex', 'enabled' ) );
		foreach ( $this->store->get_items() as $row ) {
			fputcsv(
				$out,
				array(
					(string) ( $row['from_path'] ?? '' ),
					(string) ( $row['to_url'] ?? '' ),
					(string) (int) ( $row['status_code'] ?? 301 ),
					! empty( $row['is_regex'] ) ? '1' : '0',
					! empty( $row['enabled'] ) ? '1' : '0',
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream, not a file.
		exit;
	}

	/**
	 * Create rules from an uploaded CSV (the format handle_export writes).
	 *
	 * Every row goes through Seonix_Redirects_Store::create, so an import is
	 * validated exactly like a hand-typed rule — a bad line is counted and
	 * skipped, never inserted raw. A row whose From path already has a rule is
	 * skipped as a duplicate rather than doubling it.
	 */
	public function handle_import(): void {
		$this->guard( 'seonix_redirects_import' );

		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) ) ) ) {
			$this->back( 'error', __( 'No CSV file was uploaded.', 'seonix' ) );
		}
		$tmp = sanitize_text_field( wp_unslash( $_FILES['csv']['tmp_name'] ) );

		$handle = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the just-uploaded temp file, not the WP filesystem.
		if ( false === $handle ) {
			$this->back( 'error', __( 'Could not read the uploaded file.', 'seonix' ) );
		}

		$added   = 0;
		$skipped = 0;
		$line    = 0;
		while ( false !== ( $cols = fgetcsv( $handle ) ) ) {
			$line++;
			// Tolerate the header row this exporter writes.
			if ( 1 === $line && isset( $cols[0] ) && 'from_path' === strtolower( trim( (string) $cols[0] ) ) ) {
				continue;
			}
			if ( ! is_array( $cols ) || '' === trim( (string) ( $cols[0] ?? '' ) ) ) {
				continue; // blank line
			}
			$from   = trim( (string) ( $cols[0] ?? '' ) );
			$to     = trim( (string) ( $cols[1] ?? '' ) );
			$code   = isset( $cols[2] ) ? (int) $cols[2] : 301;
			$regex  = ! empty( $cols[3] ) && '0' !== trim( (string) $cols[3] );
			$result = $this->store->create( array(
				'seonix_id'   => null,
				'from_path'   => $from,
				'to_url'      => $to,
				'status_code' => $code,
				'is_regex'    => $regex,
				'enabled'     => ! isset( $cols[4] ) || ( '0' !== trim( (string) $cols[4] ) ),
			) );
			if ( is_wp_error( $result ) ) {
				$skipped++;
			} else {
				$added++;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- temp upload stream.

		$this->back( 'imported', $added . '/' . ( $added + $skipped ) );
	}

	/**
	 * Apply an action to several rules at once.
	 *
	 * A site that just migrated has dozens of stale rules; making the owner click
	 * Delete forty times is how a redirect table stops being maintained.
	 */
	public function handle_bulk(): void {
		$this->guard( 'seonix_redirects_bulk' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
			? array_map( 'absint', wp_unslash( $_POST['ids'] ) )
			: array();
		$ids    = array_values( array_filter( $ids ) );

		if ( '' === $action || array() === $ids ) {
			$this->back( 'error', __( 'Pick an action and at least one redirect.', 'seonix' ) );
		}

		$done = 0;
		foreach ( $ids as $id ) {
			$row = $this->store->get( $id );
			if ( ! $row || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			switch ( $action ) {
				case 'enable':
					$this->store->set_enabled( $id, true );
					$done++;
					break;
				case 'disable':
					$this->store->set_enabled( $id, false );
					$done++;
					break;
				case 'delete':
					// Same tombstone contract as the single-row delete.
					if ( ! empty( $row['seonix_id'] ) ) {
						$this->store->tombstone( $id );
					} else {
						$this->store->hard_delete( $id );
					}
					$done++;
					break;
			}
		}

		$this->back( 'bulk', (string) $done );
	}

	/**
	 * Delete a rule. Seonix-managed rows tombstone (the service must learn
	 * about the local deletion); Local rows hard-delete.
	 */
	public function handle_delete(): void {
		$this->guard( 'seonix_redirects_delete' );

		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row = $id > 0 ? $this->store->get( $id ) : null;
		if ( ! $row || ! empty( $row['deleted_at'] ) ) {
			$this->back( 'error', __( 'Redirect not found.', 'seonix' ) );
		}

		if ( ! empty( $row['seonix_id'] ) ) {
			$this->store->tombstone( $id );
		} else {
			$this->store->hard_delete( $id );
		}
		$this->back( 'deleted' );
	}

	/**
	 * Flip a rule's enabled flag.
	 */
	public function handle_toggle(): void {
		$this->guard( 'seonix_redirects_toggle' );

		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row = $id > 0 ? $this->store->get( $id ) : null;
		if ( ! $row || ! empty( $row['deleted_at'] ) ) {
			$this->back( 'error', __( 'Redirect not found.', 'seonix' ) );
		}

		$this->store->set_enabled( $id, ! (int) $row['enabled'] );
		$this->back( 'saved' );
	}

	// ─── Rendering ────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage redirects.', 'seonix' ) );
		}

		$all = $this->store->get_items();

		// Filter + search are read-only view state driven by our own links; no
		// action is taken from them, so no nonce is involved.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['sx_filter'] ) ? sanitize_key( (string) $_GET['sx_filter'] ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$counts = array(
			'all'      => count( $all ),
			'seonix'   => 0,
			'manual'   => 0,
			'disabled' => 0,
		);
		foreach ( $all as $row ) {
			if ( ! empty( $row['seonix_id'] ) ) {
				$counts['seonix']++;
			} else {
				$counts['manual']++;
			}
			if ( ! (int) $row['enabled'] ) {
				$counts['disabled']++;
			}
		}

		$items  = $this->filter_rows( $all, $filter, $search );
		$active = $counts['all'] - $counts['disabled'];

		$this->shell->open( 'redirects' );
		?>
		<div class="sx-rdr">
			<?php $this->render_notice(); ?>

			<div class="pagehead">
				<div class="pagehead-left">
					<span class="pagehead-ic"><?php echo self::icon( 'redirect', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG from self::icon(). ?></span>
					<div>
						<h1 class="page-title"><?php esc_html_e( 'Redirects', 'seonix' ); ?></h1>
						<p class="page-sub"><?php esc_html_e( 'Redirects from Seonix SEO fixes and any you add here are served by this plugin — no extra redirect plugin needed. Matching ignores query strings and trailing slashes.', 'seonix' ); ?></p>
					</div>
				</div>
				<div class="rdr-stat">
					<span><b><?php echo esc_html( (string) $active ); ?></b> <?php esc_html_e( 'active', 'seonix' ); ?></span>
					<i></i><span><b><?php echo esc_html( (string) $counts['seonix'] ); ?></b> <?php esc_html_e( 'by Seonix', 'seonix' ); ?></span>
					<?php if ( $counts['disabled'] > 0 ) : ?>
						<i></i><span class="mut"><b><?php echo esc_html( (string) $counts['disabled'] ); ?></b> <?php esc_html_e( 'disabled', 'seonix' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php $this->render_add_form(); ?>

			<div class="issues-head">
				<div>
					<h2 class="ih-title"><?php esc_html_e( 'Existing redirects', 'seonix' ); ?></h2>
					<p class="ih-sub"><?php esc_html_e( 'Served automatically. Disable to pause a rule without deleting it.', 'seonix' ); ?></p>
				</div>
				<div class="rdr-io">
					<a class="btn sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seonix_redirects_export' ), 'seonix_redirects_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'seonix' ); ?></a>
					<form class="rdr-import" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="seonix_redirects_import" />
						<?php wp_nonce_field( 'seonix_redirects_import' ); ?>
						<label class="btn sm">
							<?php esc_html_e( 'Import CSV', 'seonix' ); ?>
							<input type="file" name="csv" accept=".csv,text/csv" onchange="this.form.submit()" hidden />
						</label>
					</form>
				</div>
			</div>

			<?php $this->render_list_tools( $filter, $search, $counts ); ?>
			<?php $this->render_table( $items, '' !== $search || 'all' !== $filter ); ?>

			<?php $this->render_log(); ?>
		</div>
		<?php
		$this->shell->close();
	}

	/**
	 * The 404 log: dead URLs visitors hit, most-hit first, each a one-click
	 * "Create redirect" that prefills the add form above. Only shown once there
	 * is something to show — a clean site sees no empty panel.
	 */
	private function render_log(): void {
		if ( null === $this->log ) {
			return;
		}
		$entries = $this->log->get_top( 100 );
		if ( empty( $entries ) ) {
			return;
		}
		?>
		<div class="issues-head">
			<div>
				<h2 class="ih-title"><?php esc_html_e( "Not found (404s)", 'seonix' ); ?></h2>
				<p class="ih-sub"><?php esc_html_e( 'Dead URLs visitors actually landed on. Turn a real one into a redirect, or dismiss it if it is not worth keeping.', 'seonix' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire 404 log?', 'seonix' ) ); ?>');">
				<input type="hidden" name="action" value="seonix_redirects_log_clear" />
				<?php wp_nonce_field( 'seonix_redirects_log_clear' ); ?>
				<button class="btn sm" type="submit"><?php esc_html_e( 'Clear log', 'seonix' ); ?></button>
			</form>
		</div>

		<div class="rdrtable rdr-404">
			<div class="rdr-404-th">
				<span><?php esc_html_e( 'Dead URL', 'seonix' ); ?></span>
				<span class="th-hits"><?php esc_html_e( 'Hits', 'seonix' ); ?></span>
				<span class="th-seen"><?php esc_html_e( 'Last seen', 'seonix' ); ?></span>
				<span class="th-act"></span>
			</div>
			<?php foreach ( $entries as $e ) : ?>
				<?php
				$path     = (string) $e['path'];
				$hits     = (int) $e['hits'];
				$seen_ago = $this->time_ago( (string) $e['last_seen_at'] );
				$create   = add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'sx_from' => rawurlencode( $path ),
					),
					admin_url( 'admin.php' )
				) . '#sx-rdr-add';
				?>
				<div class="rdr-404-row">
					<code class="rdr-404-path" title="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $path ); ?></code>
					<span class="rdr-404-hits"><?php echo esc_html( number_format_i18n( $hits ) ); ?></span>
					<span class="rdr-404-seen"><?php echo esc_html( $seen_ago ); ?></span>
					<span class="rdr-404-act">
						<a class="rdr-link on" href="<?php echo esc_url( $create ); ?>"><?php esc_html_e( 'Create redirect', 'seonix' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="seonix_redirects_log_dismiss" />
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $e['id'] ); ?>" />
							<?php wp_nonce_field( 'seonix_redirects_log_dismiss' ); ?>
							<button class="rdr-link" type="submit"><?php esc_html_e( 'Dismiss', 'seonix' ); ?></button>
						</form>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Human "N minutes/hours/days ago" for a UTC datetime string.
	 */
	private function time_ago( string $mysql_utc ): string {
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human time difference, e.g. "2 hours". */
		return sprintf( __( '%s ago', 'seonix' ), human_time_diff( $ts, time() ) );
	}

	/**
	 * Add-redirect card.
	 */
	private function render_add_form(): void {
		// Prefill the From field when the operator clicked "Create redirect" on a
		// logged 404. Read-only view state from our own link, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$prefill_from = isset( $_GET['sx_from'] ) ? Seonix_Redirects_Store::normalize_from_path( wp_unslash( $_GET['sx_from'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$prefill_from = is_string( $prefill_from ) ? $prefill_from : '';
		?>
		<form id="sx-rdr-add" class="card formcard" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="seonix_redirects_add" />
			<?php wp_nonce_field( 'seonix_redirects_add' ); ?>
			<div class="card-head">
				<div>
					<div class="card-title"><?php esc_html_e( 'Add redirect', 'seonix' ); ?></div>
					<div class="card-sub"><?php esc_html_e( 'Point an old or changed URL to its new home.', 'seonix' ); ?></div>
				</div>
			</div>
			<div class="form-grid">
				<div class="field">
					<label class="field-label" for="sx-rdr-from"><?php esc_html_e( 'From path', 'seonix' ); ?></label>
					<input class="inp" id="sx-rdr-from" name="from_path" placeholder="/old-page/" value="<?php echo esc_attr( $prefill_from ); ?>"<?php echo '' !== $prefill_from ? ' autofocus' : ''; ?> required />
					<span class="field-hint"><?php esc_html_e( 'Site-relative path starting with “/”. No domain, no query string. With “Regular expression” on, this is a pattern instead.', 'seonix' ); ?></span>
				</div>
				<div class="field">
					<label class="field-label" for="sx-rdr-to"><?php esc_html_e( 'To URL', 'seonix' ); ?></label>
					<input class="inp" id="sx-rdr-to" name="to_url" placeholder="/new-page/" />
					<span class="field-hint"><?php esc_html_e( 'A path on this site or a full https:// URL. Leave empty for 410 — it says the page is gone for good.', 'seonix' ); ?></span>
				</div>
			</div>
			<div class="form-foot">
				<div class="field">
					<label class="field-label" for="sx-rdr-code"><?php esc_html_e( 'Type', 'seonix' ); ?></label>
					<select class="sel" id="sx-rdr-code" name="status_code">
						<?php foreach ( self::status_choices() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button class="btn primary" type="submit">
					<?php echo self::icon( 'plus', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
					<?php esc_html_e( 'Add redirect', 'seonix' ); ?>
				</button>
			</div>

			<?php // Regex is a power-user tool — almost every redirect is a plain path. Tucked behind a disclosure so the common case stays a two-field form, not an intimidating one. ?>
			<details class="rdr-advanced">
				<summary><?php esc_html_e( 'Advanced', 'seonix' ); ?></summary>
				<label class="regex-toggle">
					<input type="checkbox" name="is_regex" value="1" />
					<?php esc_html_e( 'Match “From” as a regular expression', 'seonix' ); ?>
					<code><?php echo esc_html( '^/blog/(\d+)$ → /archive/$1' ); ?></code>
				</label>
			</details>
		</form>
		<?php
	}

	/**
	 * Filter pills + search.
	 *
	 * @param string             $filter
	 * @param string             $search
	 * @param array<string,int>  $counts
	 */
	private function render_list_tools( string $filter, string $search, array $counts ): void {
		$pills = array(
			'all'      => __( 'All', 'seonix' ),
			'seonix'   => __( 'By Seonix', 'seonix' ),
			'manual'   => __( 'Manual', 'seonix' ),
			'disabled' => __( 'Disabled', 'seonix' ),
		);
		?>
		<div class="listtools">
			<?php foreach ( $pills as $id => $label ) : ?>
				<?php
				$url = add_query_arg(
					array_filter(
						array(
							'page'      => self::PAGE_SLUG,
							'sx_filter' => 'all' === $id ? null : $id,
							's'         => '' !== $search ? $search : null,
						),
						static function ( $v ) {
							return null !== $v;
						}
					),
					admin_url( 'admin.php' )
				);
				?>
				<a class="filter<?php echo $filter === $id ? ' active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $label ); ?><span class="fcount"><?php echo esc_html( (string) ( $counts[ $id ] ?? 0 ) ); ?></span>
				</a>
			<?php endforeach; ?>

			<form class="searchbox" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php if ( 'all' !== $filter ) : ?>
					<input type="hidden" name="sx_filter" value="<?php echo esc_attr( $filter ); ?>" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search paths…', 'seonix' ); ?>" />
				<button class="btn sm" type="submit"><?php esc_html_e( 'Search', 'seonix' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * The rule table.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param bool                           $filtered Whether a filter/search is narrowing the list.
	 */
	private function render_table( array $items, bool $filtered ): void {
		?>
		<div class="rdrtable">
			<?php if ( empty( $items ) ) : ?>
				<div class="empty">
					<div class="empty-ic"><?php echo self::icon( 'redirect', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></div>
					<?php if ( $filtered ) : ?>
						<div class="empty-t"><?php esc_html_e( 'Nothing matches this view', 'seonix' ); ?></div>
						<p class="empty-s"><?php esc_html_e( 'Try another filter or clear the search.', 'seonix' ); ?></p>
					<?php else : ?>
						<div class="empty-t"><?php esc_html_e( 'No redirects yet', 'seonix' ); ?></div>
						<p class="empty-s"><?php esc_html_e( 'Rules appear here when Seonix fixes a broken link, when you rename or trash a published post, or when you add one above.', 'seonix' ); ?></p>
					<?php endif; ?>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="seonix_redirects_bulk" />
				<?php wp_nonce_field( 'seonix_redirects_bulk' ); ?>

				<div class="bulkbar">
					<select class="sel" name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'seonix' ); ?></option>
						<option value="enable"><?php esc_html_e( 'Enable', 'seonix' ); ?></option>
						<option value="disable"><?php esc_html_e( 'Disable', 'seonix' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'seonix' ); ?></option>
					</select>
					<button class="btn sm" type="submit"><?php esc_html_e( 'Apply', 'seonix' ); ?></button>
					<span class="bulk-note">
						<?php
						printf(
							/* translators: %d: number of redirects shown. */
							esc_html( _n( '%d redirect', '%d redirects', count( $items ), 'seonix' ) ),
							(int) count( $items )
						);
						?>
					</span>
				</div>

				<div class="rdr-th">
					<span></span>
					<span><?php esc_html_e( 'From → To', 'seonix' ); ?></span>
					<span><?php esc_html_e( 'Type', 'seonix' ); ?></span>
					<span><?php esc_html_e( 'Source', 'seonix' ); ?></span>
					<span><?php esc_html_e( 'Status', 'seonix' ); ?></span>
					<span class="th-hits"><?php esc_html_e( 'Hits', 'seonix' ); ?></span>
					<span class="th-act"><?php esc_html_e( 'Actions', 'seonix' ); ?></span>
				</div>

				<?php foreach ( $items as $row ) : ?>
					<?php $this->render_row( $row ); ?>
				<?php endforeach; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function render_row( array $row ): void {
		$id         = (int) $row['id'];
		$is_managed = ! empty( $row['seonix_id'] );
		$is_enabled = (bool) (int) $row['enabled'];
		$is_regex   = ! empty( $row['is_regex'] );
		$code       = (int) $row['status_code'];
		$hits       = (int) $row['hits'];
		$gone       = in_array( $code, Seonix_Redirects_Store::TARGETLESS_STATUS_CODES, true );

		$toggle_url = wp_nonce_url( admin_url( 'admin-post.php?action=seonix_redirects_toggle&id=' . $id ), 'seonix_redirects_toggle' );
		$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=seonix_redirects_delete&id=' . $id ), 'seonix_redirects_delete' );
		?>
		<div class="rdr-row<?php echo $is_enabled ? '' : ' off'; ?>">
			<div><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $id ); ?>" aria-label="<?php esc_attr_e( 'Select redirect', 'seonix' ); ?>" /></div>

			<div class="rdr-path">
				<div class="rdr-from" title="<?php echo esc_attr( (string) $row['from_path'] ); ?>">
					<?php // Label each side. An arrow alone leaves the reader to infer direction from two URLs that both start with the same domain and may each be truncated — the one thing a redirect list must never leave ambiguous. ?>
					<span class="rdr-side"><?php esc_html_e( 'From', 'seonix' ); ?></span>
					<span class="rdr-url"><?php echo esc_html( (string) $row['from_path'] ); ?></span>
					<?php if ( $is_regex ) : ?>
						<span class="badge med" style="margin-left:6px;"><?php esc_html_e( 'Regex', 'seonix' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="rdr-to">
					<span class="rdr-side"><?php esc_html_e( 'To', 'seonix' ); ?></span>
					<?php if ( $gone ) : ?>
						<code class="gone-note"><?php esc_html_e( 'Gone — no target', 'seonix' ); ?></code>
					<?php else : ?>
						<code title="<?php echo esc_attr( (string) $row['to_url'] ); ?>"><?php echo esc_html( (string) $row['to_url'] ); ?></code>
					<?php endif; ?>
				</div>
				<div class="rdr-meta"><?php echo esc_html( $this->created_label( (string) ( $row['created_at'] ?? '' ) ) ); ?></div>
			</div>

			<div><span class="rdr-code <?php echo esc_attr( self::code_class( $code ) ); ?>"><?php echo esc_html( (string) $code ); ?></span></div>

			<div>
				<?php if ( $is_managed ) : ?>
					<span class="badge low" title="<?php echo esc_attr( (string) $row['seonix_id'] ); ?>">
						<?php echo self::icon( 'sparkles', 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
						<?php esc_html_e( 'Seonix', 'seonix' ); ?>
					</span>
				<?php else : ?>
					<span class="badge nodata"><?php esc_html_e( 'Manual', 'seonix' ); ?></span>
				<?php endif; ?>
			</div>

			<div>
				<?php if ( $is_enabled ) : ?>
					<span class="badge good"><?php esc_html_e( 'Enabled', 'seonix' ); ?></span>
				<?php else : ?>
					<span class="badge nodata"><?php esc_html_e( 'Disabled', 'seonix' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="rdr-hits<?php echo 0 === $hits ? ' zero' : ''; ?>">
				<?php echo esc_html( number_format_i18n( $hits ) ); ?>
				<?php if ( ! empty( $row['last_accessed_at'] ) ) : ?>
					<small><?php echo esc_html( $this->last_hit_label( (string) $row['last_accessed_at'] ) ); ?></small>
				<?php endif; ?>
			</div>

			<div class="rdr-actions">
				<a class="rdr-link<?php echo $is_enabled ? '' : ' on'; ?>" href="<?php echo esc_url( $toggle_url ); ?>">
					<?php $is_enabled ? esc_html_e( 'Disable', 'seonix' ) : esc_html_e( 'Enable', 'seonix' ); ?>
				</a>
				<a class="rdr-link del" href="<?php echo esc_url( $delete_url ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'seonix' ) ); ?>');">
					<?php esc_html_e( 'Delete', 'seonix' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	// ─── View helpers ─────────────────────────────────────────────────────

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows( array $rows, string $filter, string $search ): array {
		$needle = '' !== $search ? mb_strtolower( $search ) : '';

		return array_values( array_filter(
			$rows,
			static function ( $row ) use ( $filter, $needle ) {
				switch ( $filter ) {
					case 'seonix':
						if ( empty( $row['seonix_id'] ) ) {
							return false;
						}
						break;
					case 'manual':
						if ( ! empty( $row['seonix_id'] ) ) {
							return false;
						}
						break;
					case 'disabled':
						if ( (int) $row['enabled'] ) {
							return false;
						}
						break;
				}
				if ( '' === $needle ) {
					return true;
				}
				$haystack = mb_strtolower( (string) $row['from_path'] . ' ' . (string) ( $row['to_url'] ?? '' ) );
				return false !== mb_strpos( $haystack, $needle );
			}
		) );
	}

	/** @return array<int,string> */
	private static function status_choices(): array {
		return array(
			301 => __( '301 — permanent', 'seonix' ),
			302 => __( '302 — temporary', 'seonix' ),
			307 => __( '307 — temporary, keeps the method', 'seonix' ),
			308 => __( '308 — permanent, keeps the method', 'seonix' ),
			410 => __( '410 — gone, no target', 'seonix' ),
		);
	}

	private static function code_class( int $code ): string {
		if ( in_array( $code, array( 302, 307 ), true ) ) {
			return 'temp';
		}
		if ( 410 === $code ) {
			return 'gone';
		}
		return '';
	}

	private function created_label( string $created ): string {
		$ts = strtotime( $created );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: date a redirect was created. */
		return sprintf( __( 'Created %s', 'seonix' ), date_i18n( get_option( 'date_format' ), $ts ) );
	}

	private function last_hit_label( string $when ): string {
		$ts = strtotime( $when );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human time diff, e.g. "3 days". */
		return sprintf( __( 'last %s ago', 'seonix' ), human_time_diff( $ts, current_time( 'timestamp' ) ) );
	}

	/**
	 * Inline SVG from the Seonix icon set. Static markup only — never mixes in
	 * caller data, so output is safe without escaping.
	 */
	private static function icon( string $name, int $size ): string {
		$paths = array(
			'redirect'   => '<path d="M5 5v5.5A3.5 3.5 0 0 0 8.5 14H19"/><path d="m15 10 4 4-4 4"/>',
			'arrowRight' => '<path d="M4.5 12h14"/><path d="m13 6 6 6-6 6"/>',
			'plus'       => '<path d="M12 5v14M5 12h14"/>',
			'sparkles'   => '<path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 14l.8 2.2L21 17l-2.2.8L18 20l-.8-2.2L15 17l2.2-.8z"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
			$size,
			$paths[ $name ]
		);
	}

	/**
	 * Inline notice fed by the redirect-back query args.
	 */
	private function render_notice(): void {
		// Read-only feedback params set by our own wp_safe_redirect below —
		// no action is taken from them, so no nonce is involved.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['seonix_msg'] ) ? sanitize_key( (string) $_GET['seonix_msg'] ) : '';
		if ( '' === $msg ) {
			return;
		}
		$detail = isset( $_GET['seonix_detail'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['seonix_detail'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$map = array(
			'added'   => array( 'notice-success', __( 'Redirect added.', 'seonix' ) ),
			'deleted' => array( 'notice-success', __( 'Redirect deleted.', 'seonix' ) ),
			'saved'   => array( 'notice-success', __( 'Redirect updated.', 'seonix' ) ),
			'log_dismissed' => array( 'notice-success', __( 'Removed from the 404 log.', 'seonix' ) ),
			'log_cleared'   => array( 'notice-success', __( '404 log cleared.', 'seonix' ) ),
			'imported'      => array(
				'notice-success',
				/* translators: %s: "added/total", e.g. "12/14". */
				sprintf( __( 'Imported %s redirects from CSV.', 'seonix' ), '' !== $detail ? $detail : '0/0' ),
			),
			'bulk'    => array(
				'notice-success',
				sprintf(
					/* translators: %d: number of redirects the bulk action changed. */
					_n( '%d redirect updated.', '%d redirects updated.', (int) $detail, 'seonix' ),
					(int) $detail
				),
			),
			'error'   => array( 'notice-error', '' !== $detail ? $detail : __( 'Something went wrong.', 'seonix' ) ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	// ─── Internals ────────────────────────────────────────────────────────

	/**
	 * Capability + nonce gate shared by every mutating handler.
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage redirects.', 'seonix' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to the Redirects screen with a feedback flag and stop.
	 *
	 * @param string $msg    Feedback key (added|deleted|saved|error).
	 * @param string $detail Optional human-readable error detail.
	 */
	private function back( string $msg, string $detail = '' ): void {
		$url = add_query_arg(
			array(
				'page'       => self::PAGE_SLUG,
				'seonix_msg' => $msg,
			),
			admin_url( 'admin.php' )
		);
		if ( '' !== $detail ) {
			$url = add_query_arg( 'seonix_detail', rawurlencode( $detail ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
