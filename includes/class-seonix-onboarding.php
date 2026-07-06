<?php
/**
 * Post-activation onboarding for Seonix.
 *
 * Before this class existed, activating the plugin was completely silent: the
 * operator stayed on the Plugins screen with no pointer to the Seonix menu.
 * This class implements the two standard WordPress onboarding affordances:
 *
 *  1. Activation redirect — seonix_activate() sets a one-shot option flag;
 *     on the next admin page load maybe_redirect() consumes it and sends the
 *     operator to the Seonix dashboard screen. Skipped for bulk activation
 *     (`activate-multi`), AJAX requests, the multisite network admin, and
 *     users without `manage_options` — the same guards Woo/Yoast-style
 *     activation redirects use. The flag is deleted BEFORE any bail-out so a
 *     skipped redirect can never fire later or loop.
 *
 *  2. Dismissible admin notice — shown on wp-admin screens (except the
 *     Seonix screens themselves, which already carry the connect CTA) until
 *     the site is connected or the notice is dismissed. Dismissal via WP
 *     core's `.notice-dismiss` button is persisted through a nonce-checked
 *     AJAX action, so the notice never comes back.
 *
 * Both flags are cleaned up in seonix_uninstall().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Onboarding {

	/** One-shot flag set by the activation hook; consumed by maybe_redirect(). */
	const REDIRECT_OPTION = 'seonix_activation_redirect';

	/** Persistent flag: show the connect notice until dismissed / connected. */
	const NOTICE_OPTION = 'seonix_activation_notice';

	/**
	 * Wire the admin-side hooks. Called from seonix_init() on admin requests.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_init' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'wp_ajax_seonix_dismiss_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );
	}

	/**
	 * admin_init callback: performs the one-time post-activation redirect.
	 * The exit lives here (not in maybe_redirect) so the decision logic stays
	 * unit-testable.
	 */
	public static function handle_admin_init(): void {
		if ( self::maybe_redirect() ) {
			exit;
		}
	}

	/**
	 * Decide + issue the post-activation redirect.
	 *
	 * @return bool True when a redirect was issued (caller must exit).
	 */
	public static function maybe_redirect(): bool {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return false;
		}
		// One-shot: clear the flag before any bail-out so it can never linger
		// and redirect an unrelated later request.
		delete_option( self::REDIRECT_OPTION );

		// Bulk activation — the operator is activating several plugins at
		// once; hijacking that flow is hostile (and every major plugin skips
		// it the same way). Presence check only, no state change.
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check on a core query arg.
			return false;
		}
		if ( wp_doing_ajax() ) {
			return false;
		}
		if ( is_network_admin() ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seonix' ) );
		return true;
	}

	/**
	 * Render the dismissible "connect your site" notice.
	 *
	 * Hidden on the Seonix screens themselves (they already show the connect
	 * CTA) and auto-cleared once the site is connected — at that point the
	 * nudge has done its job.
	 */
	public static function render_notice(): void {
		if ( ! get_option( self::NOTICE_OPTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( class_exists( 'Seonix_Auth' ) && Seonix_Auth::is_connected() ) {
			delete_option( self::NOTICE_OPTION );
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'seonix' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'seonix_dismiss_notice' );
		?>
		<div class="notice notice-info is-dismissible seonix-activation-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'Seonix SEO is active.', 'seonix' ); ?></strong>
				<?php esc_html_e( 'llms.txt and IndexNow are already working on your site. Connect it to Seonix to see your full site audit inside WordPress.', 'seonix' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seonix' ) ); ?>">
					<?php esc_html_e( 'Open Seonix', 'seonix' ); ?>
				</a>
			</p>
		</div>
		<?php
		// Persist the dismissal when WP core's × button is clicked. Core adds
		// .notice-dismiss via JS after DOM ready, so a delegated document
		// listener is the reliable way to catch it. Best-effort fire-and-forget.
		$js = sprintf(
			'document.addEventListener("click",function(e){' .
				'var t=e.target&&e.target.closest?e.target.closest(".seonix-activation-notice .notice-dismiss"):null;' .
				'if(!t){return;}' .
				'var n=t.closest(".seonix-activation-notice");' .
				'var b=new URLSearchParams();' .
				'b.append("action","seonix_dismiss_notice");' .
				'b.append("nonce",n?n.getAttribute("data-nonce")||"":"");' .
				'fetch(%s,{method:"POST",credentials:"same-origin",body:b});' .
			'});',
			wp_json_encode( admin_url( 'admin-ajax.php' ) )
		);
		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $js );
		}
	}

	/**
	 * AJAX: persist the notice dismissal (nonce + capability gated).
	 */
	public static function ajax_dismiss_notice(): void {
		check_ajax_referer( 'seonix_dismiss_notice', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'seonix' ) ), 403 );
		}
		delete_option( self::NOTICE_OPTION );
		wp_send_json_success( array( 'dismissed' => true ) );
	}
}
