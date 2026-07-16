<?php
/**
 * The Seonix admin chrome: the top bar and the tab row every Seonix screen
 * renders inside.
 *
 * WordPress hands a plugin one menu and a stack of submenu links. That is
 * enough to reach a screen and not enough to make several screens read as one
 * product, which is what the Seonix design asks for: a white top bar carrying
 * the brand and the connection state, and a nav row — Site Health, Redirects,
 * Settings — repeated on every page with the current one underlined.
 *
 * Holding that chrome here rather than in each screen is what stops a screen
 * from quietly falling out of it. Redirects did exactly that: it rendered a
 * bare .wrap, so the one screen owners reach most often looked like a different
 * plugin. Every render method now pairs open() with close().
 *
 * The tabs are ordinary links to ordinary admin pages, deliberately — the
 * sidebar submenu and this row then describe the same three screens in the same
 * order, and each stays bookmarkable and survives a reload.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Admin_Shell {

	/**
	 * Store behind the Redirects tab badge.
	 *
	 * Optional: without it the shell simply renders no badge. A screen that has
	 * no reason to build a store should still be able to draw the chrome.
	 *
	 * @var Seonix_Redirects_Store|null
	 */
	private $redirects;

	/**
	 * @param Seonix_Redirects_Store|null $redirects Store for the tab badge.
	 */
	public function __construct( Seonix_Redirects_Store $redirects = null ) {
		$this->redirects = $redirects;
	}

	/**
	 * Attach the shell's own hooks. Called once from seonix_init().
	 */
	public function register(): void {
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	// ─── The screens ──────────────────────────────────────────────────────

	/**
	 * Every Seonix screen, declared once: menu slug, the admin page hook core
	 * derives from it, and how it appears in the nav row — in design order.
	 *
	 * Everything else is read off this list: the tabs, which hooks load the
	 * shell's assets, and which screens get the app background. That is the
	 * point. Those three used to be three hand-written lists of screen names,
	 * and Redirects was added to some of them: it appeared in the menu, drew no
	 * chrome, and kept wp-admin's grey background and left gutter while the
	 * other two read as an app. A screen is now either in this list or not.
	 *
	 * @return array<string,array{slug:string,hook:string,label:string,icon:string}>
	 */
	private static function screens(): array {
		return array(
			'dashboard' => array(
				'slug'  => Seonix_Admin::MENU_SLUG,
				'hook'  => 'toplevel_page_' . Seonix_Admin::MENU_SLUG,
				'label' => __( 'Site Health', 'seonix' ),
				'icon'  => 'grid',
			),
			'redirects' => array(
				'slug'  => Seonix_Redirects_Admin::PAGE_SLUG,
				'hook'  => 'seonix_page_' . Seonix_Redirects_Admin::PAGE_SLUG,
				'label' => __( 'Redirects', 'seonix' ),
				'icon'  => 'redirect',
			),
			'settings'  => array(
				'slug'  => Seonix_Admin::SETTINGS_SLUG,
				'hook'  => 'seonix_page_' . Seonix_Admin::SETTINGS_SLUG,
				'label' => __( 'Settings', 'seonix' ),
				'icon'  => 'sliders',
			),
		);
	}

	/**
	 * Admin page hooks of every Seonix screen — for enqueue callbacks.
	 *
	 * @return array<int,string>
	 */
	public static function screen_hooks(): array {
		return array_column( self::screens(), 'hook' );
	}

	/** Whether an admin page hook belongs to a Seonix screen. */
	public static function is_seonix_screen( $hook ): bool {
		return is_string( $hook ) && in_array( $hook, self::screen_hooks(), true );
	}

	/**
	 * Tag every Seonix screen with one body class.
	 *
	 * The stylesheet needs to reach outside .seonix-app — wp-admin's own #wpwrap
	 * / #wpcontent carry the grey background and the left gutter, and the shell
	 * only looks like an app once those are overridden. This is what lets it do
	 * that with a single selector instead of naming each screen.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::is_seonix_screen( $screen->id ) ) {
			$classes .= ' seonix-screen';
		}
		return $classes;
	}

	// ─── Tabs ─────────────────────────────────────────────────────────────

	/**
	 * The nav row, in design order: Site Health, Redirects, Settings.
	 *
	 * The same order the submenu is registered in, so the sidebar and the tab
	 * row never disagree about where Redirects sits.
	 *
	 * @return array<string,array{label:string,url:string,icon:string,count:int|null}>
	 */
	public function tabs(): array {
		$counts = array( 'redirects' => $this->redirect_badge() );
		$tabs   = array();

		foreach ( self::screens() as $key => $screen ) {
			$tabs[ $key ] = array(
				'label' => $screen['label'],
				'url'   => admin_url( 'admin.php?page=' . $screen['slug'] ),
				'icon'  => $screen['icon'],
				'count' => $counts[ $key ] ?? null,
			);
		}

		return $tabs;
	}

	/**
	 * How many redirects are actually being served, for the tab badge.
	 *
	 * Null (no badge) rather than 0 when there is nothing to count: a badge
	 * reading "0" is noise on a site that has never needed a redirect, and the
	 * design carries a count only where there is one.
	 *
	 * @return int|null
	 */
	private function redirect_badge(): ?int {
		if ( null === $this->redirects ) {
			return null;
		}
		$count = $this->redirects->count_active();
		return $count > 0 ? $count : null;
	}

	// ─── Chrome ───────────────────────────────────────────────────────────

	/**
	 * Open the shell: top bar, nav row, and the content wrapper the caller
	 * prints into. Always pair with close().
	 *
	 * The connection state is read here rather than passed in so a screen only
	 * has to say which tab it is — one argument no caller can get wrong.
	 *
	 * @param string $active Tab to underline: 'dashboard' | 'redirects' | 'settings'.
	 */
	public function open( string $active = 'dashboard' ): void {
		$is_connected = Seonix_Auth::is_connected();
		$project_name = (string) get_option( 'seonix_project_name', '' );
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
								href="<?php echo esc_url( self::dashboard_url() ); ?>"
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
					<?php foreach ( $this->tabs() as $key => $tab ) : ?>
						<a class="seonix-navtab<?php echo $key === $active ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( $tab['url'] ); ?>"
							<?php echo $key === $active ? 'aria-current="page"' : ''; ?>>
							<?php $this->nav_icon( $tab['icon'] ); ?>
							<span><?php echo esc_html( $tab['label'] ); ?></span>
							<?php if ( null !== $tab['count'] ) : ?>
								<span class="seonix-navtab__count"><?php echo esc_html( (string) $tab['count'] ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			</nav>
			<div class="seonix-content">
				<div id="seonix-notices"></div>
		<?php
	}

	/**
	 * Close the wrappers opened by open() (.seonix-content and .seonix-app).
	 */
	public function close(): void {
		?>
			</div><!-- .seonix-content -->
		</div><!-- .seonix-app -->
		<?php
	}

	/**
	 * Echo a small inline nav-tab icon (trusted static SVG, no user data).
	 *
	 * @param string $name Icon key: 'grid' | 'redirect' | 'sliders'.
	 */
	private function nav_icon( string $name ): void {
		$paths = array(
			'grid'     => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
			'redirect' => '<path d="M5 5v5.5A3.5 3.5 0 0 0 8.5 14H19"/><path d="m15 10 4 4-4 4"/>',
			'sliders'  => '<path d="M4 8h9"/><path d="M17 8h3"/><circle cx="15" cy="8" r="2.2"/><path d="M4 16h3"/><path d="M11 16h9"/><circle cx="9" cy="16" r="2.2"/>',
		);
		$inner = isset( $paths[ $name ] ) ? $paths[ $name ] : '';
		// Static, developer-authored SVG — safe to emit verbatim.
		echo '<svg class="seonix-navtab__icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static markup.
	}

	// ─── Links into the Seonix app ────────────────────────────────────────

	/**
	 * Base origin of the Seonix dashboard SPA (e.g. https://app.seonix.ai).
	 *
	 * The account endpoint records the exact origin in `seonix_app_url`; until
	 * then we fall back to the production app (the origin the connect handoff
	 * also targets). A filter lets self-hosted installs override it.
	 */
	public static function app_base_url(): string {
		$saved = get_option( 'seonix_app_url', '' );
		$base  = ! empty( $saved ) ? $saved : 'https://app.seonix.ai';
		$base  = apply_filters( 'seonix_app_base_url', $base );
		return untrailingslashit( $base );
	}

	/** Deep link to this project's overview in the Seonix dashboard. */
	public static function dashboard_url(): string {
		$pid = get_option( 'seonix_project_id', '' );
		if ( '' === $pid ) {
			return self::app_base_url();
		}
		return self::app_base_url() . '/projects/' . rawurlencode( (string) $pid ) . '/overview';
	}
}
