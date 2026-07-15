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
 * re-submits. Rendering uses WP core admin styles (.wrap + widefat) rather
 * than the connected-dashboard app shell — the screen must work identically
 * on unconnected sites where none of the Seonix shell context exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Admin {

	/** Submenu slug (parent is Seonix_Admin::MENU_SLUG). */
	const PAGE_SLUG = 'seonix-redirects';

	/** @var Seonix_Redirects_Store */
	private $store;

	public function __construct( Seonix_Redirects_Store $store ) {
		$this->store = $store;
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
	}

	public function add_menu(): void {
		add_submenu_page(
			Seonix_Admin::MENU_SLUG,
			__( 'Redirects', 'seonix' ),
			__( 'Redirects', 'seonix' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
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

		$result = $this->store->create( array(
			'seonix_id'   => null,
			'from_path'   => $from_path,
			'to_url'      => $to_url,
			'status_code' => $status_code,
			'enabled'     => true,
		) );

		if ( is_wp_error( $result ) ) {
			$this->back( 'error', $result->get_error_message() );
		}
		$this->back( 'added' );
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

		$items = $this->store->get_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redirects', 'seonix' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Redirects created by Seonix (from SEO fixes and the dashboard) and any you add here are served by this plugin — no extra redirect plugin needed. Matching ignores the query string and trailing slashes; the query string is carried over to the target.', 'seonix' ); ?>
			</p>

			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Add redirect', 'seonix' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
				<input type="hidden" name="action" value="seonix_redirects_add" />
				<?php wp_nonce_field( 'seonix_redirects_add' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="seonix-redirect-from"><?php esc_html_e( 'From path', 'seonix' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="seonix-redirect-from" name="from_path" placeholder="/old-page/" required />
							<p class="description"><?php esc_html_e( 'Site-relative path starting with “/”. No domain, no query string.', 'seonix' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seonix-redirect-to"><?php esc_html_e( 'To URL', 'seonix' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="seonix-redirect-to" name="to_url" placeholder="/new-page/" required />
							<p class="description"><?php esc_html_e( 'A path on this site or a full https:// URL.', 'seonix' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seonix-redirect-code"><?php esc_html_e( 'Type', 'seonix' ); ?></label></th>
						<td>
							<select id="seonix-redirect-code" name="status_code">
								<option value="301"><?php esc_html_e( '301 — permanent', 'seonix' ); ?></option>
								<option value="302"><?php esc_html_e( '302 — temporary', 'seonix' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add redirect', 'seonix' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Existing redirects', 'seonix' ); ?></h2>
			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No redirects yet.', 'seonix' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'From', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'To', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'Code', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'Source', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'Status', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'Hits', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'Created', 'seonix' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seonix' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $row ) : ?>
							<?php
							$id         = (int) $row['id'];
							$is_managed = ! empty( $row['seonix_id'] );
							$is_enabled = (bool) (int) $row['enabled'];
							$toggle_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=seonix_redirects_toggle&id=' . $id ),
								'seonix_redirects_toggle'
							);
							$delete_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=seonix_redirects_delete&id=' . $id ),
								'seonix_redirects_delete'
							);
							?>
							<tr>
								<td><code><?php echo esc_html( (string) $row['from_path'] ); ?></code></td>
								<td><code><?php echo esc_html( (string) $row['to_url'] ); ?></code></td>
								<td><?php echo esc_html( (string) $row['status_code'] ); ?></td>
								<td>
									<?php if ( $is_managed ) : ?>
										<span title="<?php echo esc_attr( (string) $row['seonix_id'] ); ?>"><?php esc_html_e( 'Seonix', 'seonix' ); ?></span>
									<?php else : ?>
										<?php esc_html_e( 'Local', 'seonix' ); ?>
									<?php endif; ?>
								</td>
								<td><?php $is_enabled ? esc_html_e( 'Enabled', 'seonix' ) : esc_html_e( 'Disabled', 'seonix' ); ?></td>
								<td><?php echo esc_html( (string) (int) $row['hits'] ); ?></td>
								<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( $toggle_url ); ?>">
										<?php $is_enabled ? esc_html_e( 'Disable', 'seonix' ) : esc_html_e( 'Enable', 'seonix' ); ?>
									</a>
									|
									<a href="<?php echo esc_url( $delete_url ); ?>" style="color:#b32d2e;"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'seonix' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'seonix' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
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
