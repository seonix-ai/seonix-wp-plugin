<?php
/**
 * Per-page audit for the post editor (SEO-plugin-style).
 *
 * Surfaces the current page's Seonix audit where the user edits:
 *   - in the BLOCK editor (Gutenberg) → a panel in the document sidebar
 *     (assets/editor-panel.js), like the SEO plugin, so it is immediately visible;
 *   - in the CLASSIC editor → a normal meta box.
 *
 * The data comes straight from the local tasks table
 * (Seonix_Tasks::issues_for_url) — no extra API call on render. Read-only by
 * design: the heavy analysis runs on the Seonix platform; this is a window onto
 * its last result. Pages published or changed AFTER the last scan are shown as
 * "not scanned yet" rather than a misleading "all clear".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Metabox {

	/** @var Seonix_Tasks */
	private $tasks;

	public function __construct( Seonix_Tasks $tasks ) {
		$this->tasks = $tasks;
	}

	/**
	 * Hook the meta box, the editor sidebar panel, and their assets. Only does
	 * anything once the site is linked to Seonix.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// Late: let the content settle (other plugins' save_post work included)
		// before deciding the saved revision's scores are the ones to keep.
		add_action( 'save_post', array( __CLASS__, 'persist_scores_on_save' ), 100, 3 );

		// Focus keyphrase field (see render_focus_keyword_field). Registered on
		// `init` rather than here on plugins_loaded because register_post_meta
		// needs the post types to exist, and at 20 so custom types registered at
		// the default priority are covered. Deliberately NOT gated on
		// Seonix_Auth::is_connected(): the registration is what makes the key
		// readable and writable over REST, and a connection that lapses
		// mid-session must not turn the author's next save into a 400. It is
		// inert on its own — the field itself only renders where the meta box
		// and panel do, which IS gated on the connection.
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'save_post', array( $this, 'save_focus_keyword' ), 10, 1 );
		// Block editor: WordPress's own REST meta handler writes the canonical
		// key straight from the panel's field, never touching the bridge. Watch
		// the key itself so the value still fans out to the active engines from
		// every path (REST, quick edit, WP-CLI), not just the classic form.
		add_action( 'added_post_meta', array( $this, 'on_focus_keyword_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_focus_keyword_change' ), 10, 4 );
	}

	// ─── Toolbar scores ──────────────────────────────────────────────────

	/** Transient prefix holding the last /score result per post. */
	const SCORE_STASH_PREFIX = 'seonix_score_';

	/** How long an unsaved score stays interesting. */
	const SCORE_STASH_TTL = HOUR_IN_SECONDS;

	/**
	 * Remember the last live score for a post without committing it.
	 *
	 * The editor scores the text as it is typed, so most of these results
	 * describe a revision that will never exist. Keeping them in a transient
	 * (instead of meta) means an abandoned draft leaves no trace on the post,
	 * and the toolbar can't quote a score for prose nobody published.
	 *
	 * @param int   $post_id Post being scored; 0 for an unsaved draft.
	 * @param mixed $result  Engine result: seo_score / readability_score.
	 */
	public static function stash_scores( int $post_id, $result ): void {
		if ( $post_id <= 0 || ! is_array( $result ) ) {
			return;
		}
		$seo  = isset( $result['seo_score'] ) ? (int) $result['seo_score'] : null;
		$read = isset( $result['readability_score'] ) ? (int) $result['readability_score'] : null;
		if ( null === $seo && null === $read ) {
			return;
		}
		set_transient(
			self::SCORE_STASH_PREFIX . $post_id,
			array( 'seo' => $seo, 'readability' => $read ),
			self::SCORE_STASH_TTL
		);
	}

	/**
	 * Promote the stashed score to post meta when the post is saved.
	 *
	 * Runs on the real save only: autosaves and revisions describe a different
	 * row, and a bulk/quick edit never went through the editor, so there is
	 * nothing fresh to promote — in that case the previous meta stays, which is
	 * still the last thing that was actually scored.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 */
	public static function persist_scores_on_save( $post_id, $post = null, $update = false ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$stash = get_transient( self::SCORE_STASH_PREFIX . $post_id );
		if ( ! is_array( $stash ) ) {
			return;
		}

		if ( isset( $stash['seo'] ) && null !== $stash['seo'] ) {
			update_post_meta( $post_id, Seonix_Admin_Bar::META_SEO, max( 0, min( 100, (int) $stash['seo'] ) ) );
		}
		if ( isset( $stash['readability'] ) && null !== $stash['readability'] ) {
			update_post_meta( $post_id, Seonix_Admin_Bar::META_READABILITY, max( 0, min( 100, (int) $stash['readability'] ) ) );
		}
		delete_transient( self::SCORE_STASH_PREFIX . $post_id );
	}

	// ─── Focus keyphrase field ────────────────────────────────────

	/**
	 * Register Seonix's canonical focus-keyphrase meta on the same post types
	 * the meta box appears on, so the block editor can read and write it through
	 * core's REST meta handling (and the value rides along with the post's own
	 * save instead of needing a route of our own).
	 *
	 * `_seonix_focus_keyword` is protected meta (leading underscore), whose
	 * auth_callback defaults to __return_false — REST would refuse every write
	 * without the explicit callback below.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( $this->editor_post_types() as $type ) {
			register_post_meta(
				$type,
				Seonix_Meta_Bridge::META_FOCUS_KW,
				array(
					// Restricted to the `edit` context on purpose. auth_callback
					// gates WRITES only — core wires it onto the edit/add/delete
					// _post_meta caps, which WP_REST_Meta_Fields consults on the
					// update path and nowhere else; get_value() runs no capability
					// check at all. With a bare `show_in_rest => true`, the key
					// would ride along in the default `view` context of
					// GET /wp/v2/<type>/<id>, which check_read_permission() allows
					// unauthenticated for any PUBLISHED post — handing the page's
					// keyphrase to anonymous visitors. Requesting `context=edit`
					// costs edit_post, and the block editor already asks for it.
					'show_in_rest'      => array(
						'schema' => array( 'context' => array( 'edit' ) ),
					),
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_focus_keyword' ),
					'auth_callback'     => array( __CLASS__, 'auth_focus_keyword' ),
				)
			);

			// SEO title + meta description — same edit-context REST exposure so the
			// search-appearance fields can read and write them via editPost(). Only
			// authoritative on sites with no SEO plugin (then Seonix owns them); with
			// an engine present the panel shows the engine's values read-only. The
			// bridge sanitizer + per-post auth callback apply the same way.
			foreach ( array( Seonix_Meta_Bridge::META_TITLE, Seonix_Meta_Bridge::META_DESC ) as $seo_key ) {
				register_post_meta(
					$type,
					$seo_key,
					array(
						'show_in_rest'      => array(
							'schema' => array( 'context' => array( 'edit' ) ),
						),
						'single'            => true,
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_focus_keyword' ),
						'auth_callback'     => array( __CLASS__, 'auth_focus_keyword' ),
					)
				);
			}
		}
	}

	/**
	 * sanitize_callback for the focus keyphrase.
	 *
	 * A type-safe adapter over the bridge's sanitizer, which stays the single
	 * source of truth for stripping engine template variables (%%title%% and
	 * friends) out of a stored value. The adapter earns its keep on the type:
	 * sanitize_meta() hands the callback whatever was passed to
	 * update_post_meta(), so a null or an array from some other plugin's stray
	 * write would be a fatal TypeError against sanitize_value()'s string
	 * signature — a white screen on save, from a value we don't even want.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string
	 */
	public static function sanitize_focus_keyword( $value ): string {
		return Seonix_Meta_Bridge::sanitize_value( is_string( $value ) ? $value : '' );
	}

	/**
	 * auth_callback for the focus keyphrase: only someone who can edit THIS post
	 * may WRITE its keyphrase — not anyone who can edit posts in general.
	 *
	 * Writes only. Core hangs this callback off the auth_{type}_meta_{key} filter,
	 * which map_meta_cap() consults for edit/add/delete_post_meta — all of them
	 * write-path caps. Reads are gated instead by the `edit` context on the
	 * registration above; this callback never runs for a GET.
	 *
	 * @param bool   $allowed  Core's default for the key (recomputed here).
	 * @param string $meta_key Meta key (unused — one key registers this).
	 * @param int    $post_id  Post the meta belongs to.
	 * @return bool
	 */
	public static function auth_focus_keyword( $allowed, $meta_key, $post_id ): bool {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * CLASSIC editor: persist the focus keyphrase input from the meta box.
	 *
	 * Writes through the bridge rather than update_post_meta() so one save puts
	 * the value in the canonical key AND every active engine's storage, with the
	 * fingerprint refreshed — and with the reverse-sync watcher's guard raised
	 * for the duration, so our own write is never mistaken for the site owner
	 * editing their SEO plugin.
	 *
	 * @param int $post_id Post being saved.
	 * @return void
	 */
	public function save_focus_keyword( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// Both are absent on every save that is not our classic form (block
		// editor REST saves, quick edit, WP-CLI). Bailing out is what keeps
		// those from reading as "the author cleared the field".
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce IS what is being fetched here; it is verified two lines down.
		if ( ! isset( $_POST['seonix_focus_kw_nonce'], $_POST['seonix_focus_keyword'] ) ) {
			return;
		}
		$nonce = sanitize_key( wp_unslash( $_POST['seonix_focus_kw_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'seonix_focus_kw_' . (int) $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$value = sanitize_text_field( wp_unslash( $_POST['seonix_focus_keyword'] ) );
		// An empty value is a deliberate clear, which write() honours.
		Seonix_Meta_Bridge::write( (int) $post_id, array( 'focus_keyword' => $value ) );
	}

	/**
	 * The canonical focus keyphrase changed on a path that bypasses the bridge —
	 * the block editor's REST meta write, quick edit, WP-CLI — so mirror it into
	 * whatever SEO engines are active (AIOSEO today; the active SEO plugin
	 * should one of them be switched on while the field is on screen).
	 *
	 * Loop safety, both directions:
	 *   • write() raises Seonix_Meta_Bridge::$writing around its own writes, so
	 *     the canonical write it makes cannot re-enter this hook, and the
	 *     reverse-sync watcher ignores the engine keys we fan out to instead of
	 *     reading them back as a site-owner edit;
	 *   • the guard is checked here too, so a bridge write that started
	 *     elsewhere (publish, SEO fix, backfill) is never fanned out twice.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key that changed.
	 * @param mixed  $meta_value New value — already unslashed and sanitized by
	 *                           update_metadata() before the hook fires.
	 * @return void
	 */
	public function on_focus_keyword_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( Seonix_Meta_Bridge::META_FOCUS_KW !== (string) $meta_key ) {
			return;
		}
		if ( Seonix_Meta_Bridge::$writing ) {
			return; // Our own write — the bridge has already fanned it out.
		}
		Seonix_Meta_Bridge::write(
			(int) $post_id,
			array( 'focus_keyword' => is_string( $meta_value ) ? $meta_value : '' )
		);
	}

	/**
	 * Register the CLASSIC meta box — only on the classic editor. In the block
	 * editor the sidebar panel (JS) takes over, so we skip the meta box there to
	 * avoid showing the audit twice.
	 */
	public function add(): void {
		if ( ! Seonix_Auth::is_connected() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return; // Block editor → use the sidebar panel instead.
		}
		foreach ( $this->editor_post_types() as $type ) {
			add_meta_box(
				'seonix-page-audit',
				__( 'Seonix — Page audit', 'seonix' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Public, UI-visible post types (post, page, product, …) minus attachment.
	 *
	 * @return array<int,string>
	 */
	private function editor_post_types(): array {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Load the shared admin stylesheet on the post-edit screens (so both the
	 * meta box and the sidebar panel pick up the design tokens + styles), and —
	 * in the block editor — the sidebar-panel script with the current page's
	 * audit data localized for it.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		if ( ! Seonix_Auth::is_connected() ) {
			return;
		}

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

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_block = $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
		if ( ! $is_block ) {
			return; // Classic editor uses the PHP meta box; no script needed.
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		wp_enqueue_script(
			'seonix-editor-panel',
			SEONIX_URL . 'assets/editor-panel.js',
			// wp-data + wp-api-fetch back the live scoring pass: wp-data to watch
			// the edited content, wp-api-fetch to call our own /score route (it
			// attaches the REST nonce the route's capability check depends on).
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-i18n', 'wp-data', 'wp-api-fetch' ),
			SEONIX_VERSION,
			true
		);
		wp_localize_script( 'seonix-editor-panel', 'seonixAudit', $this->audit_data( $post ) );
	}

	/**
	 * Build the normalized per-page audit payload used by BOTH the classic meta
	 * box and the editor sidebar panel, so the two stay identical.
	 *
	 * State machine:
	 *   - unpublished : draft / pending / new — no stable scanned URL yet.
	 *   - unscanned   : published, but added/changed AFTER the last scan, OR not
	 *                   present in the last scan results → no data for it.
	 *   - clean       : published, in the scan window, zero active issues.
	 *   - issues      : has active issues.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return array<string,mixed>
	 */
	public function audit_data( WP_Post $post ): array {
		$labels = array(
			'seo'       => __( 'SEO', 'seonix' ),
			'technical' => __( 'Technical', 'seonix' ),
			'ai'        => __( 'AI Search', 'seonix' ),
		);

		$base = array(
			'title'   => __( 'Seonix — Page audit', 'seonix' ),
			'allUrl'  => admin_url( 'admin.php?page=' . Seonix_Admin::MENU_SLUG ),
			// Identifies the post to the /score route, which checks edit_post
			// against it. 0 for a draft that has never been saved.
			'postId'  => (int) $post->ID,
			// Home host so the editor-panel JS can split the article's links
			// into internal vs external without another round-trip.
			'homeHost' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			// Whether an SEO plugin already gives the author a keyphrase field.
			// When it does, Seonix shows none of its own — see
			// Seonix_SEO_Engine::has_native_focus_kw_ui.
			'hasNativeKeyphraseUi' => Seonix_SEO_Engine::has_native_focus_kw_ui(),
			// Whether this post type carries meta over REST at all. Core drops
			// the `meta` field from a post type's REST schema unless it supports
			// `custom-fields` (post and page always do; a CPT declaring only
			// title+editor does not), and editPost({meta}) against a post type
			// without it accepts the author's typing and silently discards it on
			// save. The block editor field stands down rather than lie; the
			// classic form posts straight to save_post and is unaffected.
			'keyphraseMetaInRest'  => post_type_supports( $post->post_type, 'custom-fields' ),
			// Passed rather than hard-coded in the JS so the canonical key has
			// exactly one definition (the bridge's).
			'focusKeywordMetaKey'  => Seonix_Meta_Bridge::META_FOCUS_KW,
			'i18n'    => array(
				'viewAll'  => __( 'View all issues', 'seonix' ),
				'optional' => __( 'Optional', 'seonix' ),
				/* translators: %s: human-readable age, e.g. "2 hours" — how long ago the page was last scanned. */
				'scanned'  => __( 'scanned %s ago', 'seonix' ),
				// Editor sidebar panel (editor-panel.js). These cover every string
				// the JS previously fell back to in hard-coded English.
				'seoLabel'         => __( 'SEO', 'seonix' ),
				'readabilityLabel' => __( 'Readability', 'seonix' ),
				'noIssues'         => __( 'No issues found', 'seonix' ),
				'why'              => __( 'Why it matters', 'seonix' ),
				'howToFix'         => __( 'How to fix', 'seonix' ),
				'avoid'            => __( 'Avoid', 'seonix' ),
				'better'           => __( 'Better', 'seonix' ),
				// Live scoring track.
				'pageIssuesLabel'  => __( 'Page issues', 'seonix' ),
				'analyzing'        => __( 'Analyzing…', 'seonix' ),
				'scoreFailed'      => __( 'Could not analyze this text.', 'seonix' ),
				'retry'            => __( 'Try again', 'seonix' ),
				'writeToScore'     => __( 'Start writing and Seonix scores the text as you go.', 'seonix' ),
				'problems'         => __( 'Problems', 'seonix' ),
				'improvements'     => __( 'Improvements', 'seonix' ),
				'goodResults'      => __( 'Good results', 'seonix' ),
				'focusKeyphrase'     => __( 'Focus keyphrase', 'seonix' ),
				'focusKeyphraseHelp' => __( 'The search term this page should rank for. Seonix checks the title, URL, intro and headings against it.', 'seonix' ),
				// Three, because "skipped" alone is only honest in one of the three
				// states: when an engine owns the field, when Seonix's own field is
				// on screen, the author has somewhere to go — telling them the
				// checks are skipped and stopping there reads as a dead end.
				'noKeyphrase'        => __( 'No focus keyphrase set — add one in your SEO plugin to turn on keyphrase checks.', 'seonix' ),
				'noKeyphraseOwn'     => __( 'No focus keyphrase set — fill in the field above to turn on keyphrase checks.', 'seonix' ),
				'noKeyphraseSkipped' => __( 'No focus keyphrase set — keyphrase checks are skipped.', 'seonix' ),
				/* translators: %d: score out of 100. */
				'scoreOutOf'       => __( '%d out of 100', 'seonix' ),
				// Links section (block-editor panel + classic metabox).
				'linksLabel'    => __( 'Links', 'seonix' ),
				'internalLinks' => __( 'Internal', 'seonix' ),
				'externalLinks' => __( 'External', 'seonix' ),
				'noInternal'    => __( 'No internal links yet.', 'seonix' ),
				'noExternal'    => __( 'No external links yet.', 'seonix' ),
				'noLinksTitle'  => __( 'No links on this page', 'seonix' ),
				'noLinksSub'    => __( 'Add a few internal links to help readers and search engines navigate your content.', 'seonix' ),
				'jumpToLink'    => __( 'Find this link in the article', 'seonix' ),
				'editLink'      => __( 'Edit this link', 'seonix' ),
				'removeLink'    => __( 'Remove this link', 'seonix' ),
				'openLink'      => __( 'Open in a new tab', 'seonix' ),
				// Search appearance section.
				'searchApp'          => __( 'Search appearance', 'seonix' ),
				'seoTitleLabel'      => __( 'SEO title', 'seonix' ),
				'metaDescLabel'      => __( 'Meta description', 'seonix' ),
				'googlePreview'      => __( 'Google preview', 'seonix' ),
				'socialPreview'      => __( 'Social preview', 'seonix' ),
				'mobileLabel'        => __( 'Mobile', 'seonix' ),
				'desktopLabel'       => __( 'Desktop', 'seonix' ),
				'seoTitlePlaceholder'=> __( 'Write a title for search results…', 'seonix' ),
				'metaDescPlaceholder'=> __( 'Write a description for search results…', 'seonix' ),
				'untitledLabel'      => __( 'Untitled', 'seonix' ),
			),
			'groups'  => array(),
			'light'   => 'green',
			'verdict' => '',
			'sub'     => '',
			'syncedLabel' => '',
			'search'  => $this->search_appearance( $post ),
		);

		$synced = $this->tasks->synced_at();
		if ( $synced > 0 ) {
			$base['syncedLabel'] = sprintf(
				/* translators: %s: human time-diff like "2 hours" */
				__( 'scanned %s ago', 'seonix' ),
				human_time_diff( $synced, time() )
			);
		}

		// Not published → no stable URL in the scan.
		if ( 'publish' !== $post->post_status ) {
			$base['state']   = 'unpublished';
			$base['light']   = 'mute';
			$base['verdict'] = __( 'Not published yet', 'seonix' );
			$base['sub']     = __( 'Publish this page and Seonix audits it on the next scan.', 'seonix' );
			return $base;
		}

		$issues = $this->tasks->issues_for_url( (string) get_permalink( $post ) );

		// Published but no data for this URL. Distinguish "added/changed after the
		// last scan" (genuinely not audited) from a clean page.
		if ( empty( $issues ) ) {
			$modified = strtotime( (string) $post->post_modified_gmt . ' UTC' );
			$newer    = $synced > 0 && $modified && $modified > $synced;
			if ( $newer || 0 === $synced ) {
				$base['state']   = 'unscanned';
				$base['light']   = 'mute';
				$base['verdict'] = __( 'Not scanned yet', 'seonix' );
				$base['sub']     = __( 'This page was added or changed after the last scan. Run a scan in Seonix to audit it.', 'seonix' );
			} else {
				$base['state']   = 'clean';
				$base['light']   = 'green';
				$base['verdict'] = __( 'No open issues on this page', 'seonix' );
				$base['sub']     = __( 'Based on the last Seonix scan.', 'seonix' );
			}
			return $base;
		}

		// Has issues.
		$errors = 0; $warnings = 0; $notices = 0;
		$grouped = array( 'seo' => array(), 'technical' => array(), 'ai' => array() );
		foreach ( $issues as $iss ) {
			if ( 'error' === $iss['severity'] ) {
				$errors++;
			} elseif ( 'warning' === $iss['severity'] ) {
				$warnings++;
			} else {
				$notices++;
			}
			$grouped[ $iss['category'] ][] = array(
				'title'                => $iss['title'],
				'severity'             => $iss['severity'],
				'recommendation'       => $iss['recommendation'],
				'informational'        => ! empty( $iss['informational'] ),
				'description'          => isset( $iss['description'] ) ? $iss['description'] : '',
				'why_it_matters'       => isset( $iss['why_it_matters'] ) ? $iss['why_it_matters'] : '',
				'how_to_fix_steps'     => isset( $iss['how_to_fix_steps'] ) && is_array( $iss['how_to_fix_steps'] ) ? $iss['how_to_fix_steps'] : array(),
				'bad_example_code'     => isset( $iss['bad_example_code'] ) ? $iss['bad_example_code'] : '',
				'bad_example_caption'  => isset( $iss['bad_example_caption'] ) ? $iss['bad_example_caption'] : '',
				'good_example_code'    => isset( $iss['good_example_code'] ) ? $iss['good_example_code'] : '',
				'good_example_caption' => isset( $iss['good_example_caption'] ) ? $iss['good_example_caption'] : '',
				'warnings'             => isset( $iss['warnings'] ) && is_array( $iss['warnings'] ) ? $iss['warnings'] : array(),
				// A one-click action when the fix lives on a screen THIS plugin
				// hosts (currently the Redirects manager). null when there is no
				// local tool — the how-to-fix steps stand on their own.
				'fix_action'           => $this->fix_action_for( isset( $iss['code'] ) ? (string) $iss['code'] : '' ),
			);
		}
		$total = count( $issues );

		$base['state'] = 'issues';
		$base['light'] = $errors > 0 ? 'red' : ( $warnings > 0 ? 'amber' : 'blue' );
		$base['verdict'] = sprintf(
			/* translators: %d: number of issues found on this page */
			_n( '%d issue on this page', '%d issues on this page', $total, 'seonix' ),
			$total
		);
		$bits = array();
		if ( $errors > 0 ) {
			/* translators: %d: number of errors */
			$bits[] = sprintf( _n( '%d error', '%d errors', $errors, 'seonix' ), $errors );
		}
		if ( $warnings > 0 ) {
			/* translators: %d: number of warnings */
			$bits[] = sprintf( _n( '%d warning', '%d warnings', $warnings, 'seonix' ), $warnings );
		}
		if ( $notices > 0 ) {
			/* translators: %d: number of notices */
			$bits[] = sprintf( _n( '%d notice', '%d notices', $notices, 'seonix' ), $notices );
		}
		$base['sub'] = implode( ' · ', $bits );

		foreach ( $grouped as $cat => $list ) {
			if ( empty( $list ) ) {
				continue;
			}
			$base['groups'][] = array(
				'key'   => $cat,
				'label' => $labels[ $cat ],
				'items' => $list,
			);
		}

		return $base;
	}

	/**
	 * Data for the "Search appearance" section: the effective SEO title / meta
	 * description (from whichever engine owns them, via the bridge) plus the
	 * context the previews need — site name, host, permalink, date, OG image.
	 *
	 * Fields are editable in our panel only when NO SEO plugin owns them and the
	 * post type carries meta over REST (same guard as the focus-keyphrase field);
	 * otherwise the engine's values show as the live source and the author edits
	 * them there. Either way Seonix keeps its own copy and syncs it upstream.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return array<string,mixed>
	 */
	private function search_appearance( WP_Post $post ): array {
		$eff   = Seonix_Meta_Bridge::read_effective( (int) $post->ID );
		$image = get_the_post_thumbnail_url( $post, 'large' );
		return array(
			'seoTitle'        => (string) $eff['seo_title'],
			'metaDescription' => (string) $eff['meta_description'],
			'fallbackTitle'   => (string) get_the_title( $post ),
			'siteName'        => (string) get_bloginfo( 'name' ),
			'host'            => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'permalink'       => (string) get_permalink( $post ),
			'dateLabel'       => (string) get_the_date( '', $post ),
			'image'           => $image ? (string) $image : '',
			'titleMetaKey'    => Seonix_Meta_Bridge::META_TITLE,
			'descMetaKey'     => Seonix_Meta_Bridge::META_DESC,
			// Editable whenever the post type carries meta over REST. Writing here
			// updates Seonix's canonical title/description meta, which the bridge
			// mirrors into any active SEO plugin on save and syncs to Seonix. The
			// fields are pre-filled with the current effective values.
			'editable'        => post_type_supports( $post->post_type, 'custom-fields' ),
		);
	}

	/**
	 * A one-click "fix it" action for a scan issue whose fix lives on a screen
	 * this plugin hosts, so the panel can send the author straight there instead
	 * of only describing the fix.
	 *
	 * Right now that is the Redirects manager: every redirect-family scan code
	 * (broken_redirect, redirect_loop, too_many_redirects, internal_link_to_redirect,
	 * https_redirect_missing, speed_redirects) is resolved there, and they all
	 * carry "redirect" in the code — so one substring test covers the family and
	 * any future redirect check without a hand-maintained list. Issues with no
	 * local tool return null and render no button.
	 *
	 * @param string $code Machine code of the scan issue (may be empty).
	 * @return array{label:string,url:string}|null
	 */
	private function fix_action_for( string $code ) {
		if ( '' !== $code && false !== strpos( $code, 'redirect' ) ) {
			return array(
				'label' => __( 'Open redirect manager', 'seonix' ),
				// Same slug as Seonix_Redirects_Admin::PAGE_SLUG; hard-coded so this
				// payload builder does not depend on the redirects module loading.
				'url'   => admin_url( 'admin.php?page=seonix-redirects' ),
			);
		}
		return null;
	}

	/**
	 * The focus keyphrase input for the CLASSIC meta box — Seonix's answer to
	 * "there is nowhere on this site to type a keyphrase" (its block-editor twin
	 * is the TextControl in assets/editor-panel.js).
	 *
	 * Suppressed the moment an SEO plugin offers its own field: Seonix reads
	 * theirs, and a second input over the same value only invites the two to
	 * disagree.
	 *
	 * @param WP_Post $post The post being edited.
	 * @param array   $d    audit_data() payload — the native-UI flag + strings.
	 * @return void
	 */
	private function render_focus_keyword_field( WP_Post $post, array $d ): void {
		if ( ! empty( $d['hasNativeKeyphraseUi'] ) ) {
			return;
		}
		$value = (string) get_post_meta( $post->ID, Seonix_Meta_Bridge::META_FOCUS_KW, true );

		echo '<div class="seonix-mb-kw">';
		wp_nonce_field( 'seonix_focus_kw_' . (int) $post->ID, 'seonix_focus_kw_nonce' );
		echo '<label class="seonix-mb-kw-label" for="seonix-focus-keyword">' . esc_html( $d['i18n']['focusKeyphrase'] ) . '</label>';
		echo '<input type="text" class="widefat" id="seonix-focus-keyword" name="seonix_focus_keyword" value="' . esc_attr( $value ) . '" />';
		echo '<p class="seonix-mb-kw-help">' . esc_html( $d['i18n']['focusKeyphraseHelp'] ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the CLASSIC meta box body (classic editor only). Uses the same
	 * audit_data() payload the sidebar panel renders, so they match exactly.
	 *
	 * @param WP_Post $post The post being edited.
	 */
	/**
	 * Extract the links in the post body, classified per the editor-concepts
	 * spec and split into the two display groups. Each item carries:
	 *   kind     — internal | external | mail | anchor
	 *   nofollow — whether rel contains nofollow
	 * Internal group = internal + #jump anchors; external group = external +
	 * mailto. De-duplicated by href within each group. tel / javascript / data
	 * hrefs are not page links and are skipped.
	 *
	 * @return array{internal:array<int,array{href:string,anchor:string,kind:string,nofollow:bool}>,external:array<int,array{href:string,anchor:string,kind:string,nofollow:bool}>}
	 */
	private function extract_links( string $html ): array {
		$internal = array();
		$external = array();
		if ( '' === trim( $html ) ) {
			return array( 'internal' => $internal, 'external' => $external );
		}

		$home_host = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : null;
		$home_host = $home_host ? preg_replace( '/^www\./i', '', $home_host ) : null;

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// Wrap in a UTF-8 fragment so loadHTML keeps encoding; the block editor
		// stores hand-edited HTML that may be a fragment, not a full document.
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$seen_internal = array();
		$seen_external = array();
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}
			$lower = strtolower( $href );
			if ( 0 === strpos( $lower, 'tel:' )
				|| 0 === strpos( $lower, 'javascript:' )
				|| 0 === strpos( $lower, 'data:' ) ) {
				continue;
			}
			// Skip non-navigational anchors plugins use as click targets: a bare
			// "#" (JS/button trigger) and popup openers (Popup Maker #popmake-NN,
			// Elementor #elementor-*) — they open a modal, not a page.
			if ( '#' === $href || preg_match( '/^#(popmake|elementor)/i', $href ) ) {
				continue;
			}
			$anchor   = trim( (string) wp_strip_all_tags( $a->textContent ) );
			$nofollow = (bool) preg_match( '/\bnofollow\b/i', (string) $a->getAttribute( 'rel' ) );

			if ( 0 === strpos( $href, '#' ) ) {
				$kind = 'anchor';
			} elseif ( 0 === strpos( $lower, 'mailto:' ) ) {
				$kind = 'mail';
			} elseif ( preg_match( '#^https?://#i', $href ) ) {
				$host = wp_parse_url( $href, PHP_URL_HOST );
				$host = $host ? preg_replace( '/^www\./i', '', $host ) : null;
				$kind = ( $home_host && $host && 0 === strcasecmp( $host, $home_host ) ) ? 'internal' : 'external';
			} else {
				$kind = 'internal'; // relative → internal by definition
			}

			$item = array(
				'href'     => $href,
				'anchor'   => $anchor,
				'kind'     => $kind,
				'nofollow' => $nofollow,
			);
			if ( 'internal' === $kind || 'anchor' === $kind ) {
				if ( ! isset( $seen_internal[ $href ] ) ) {
					$seen_internal[ $href ] = true;
					$internal[]             = $item;
				}
			} elseif ( ! isset( $seen_external[ $href ] ) ) {
				$seen_external[ $href ] = true;
				$external[]             = $item;
			}
		}

		return array( 'internal' => $internal, 'external' => $external );
	}

	/** Inline SVG for a link-type icon (editor-concepts spec, 13×13 @ viewBox 24). */
	private function link_type_svg( string $kind ): string {
		$open = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
		switch ( $kind ) {
			case 'external':
				return $open . '<path d="M7 17L17 7"></path><path d="M8 7h9v9"></path></svg>';
			case 'mail':
				return $open . '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path></svg>';
			case 'anchor':
				return $open . '<path d="M6 9h12M5 15h12M10 4L8 20M16 4l-2 16"></path></svg>';
			default: // internal chain
				return $open . '<path d="M9.5 14.5l5-5"></path><path d="M11 6.5l1-1a3.5 3.5 0 0 1 5 5l-1 1"></path><path d="M13 17.5l-1 1a3.5 3.5 0 0 1-5-5l1-1"></path></svg>';
		}
	}

	/** Compact display path per the concept: internal → /path, external → host/path. */
	private function link_display_path( array $it ): string {
		if ( 'anchor' === $it['kind'] || 'mail' === $it['kind'] ) {
			return $it['href'];
		}
		if ( ! preg_match( '#^https?://#i', $it['href'] ) ) {
			return $it['href']; // relative internal — already a path
		}
		$parts = wp_parse_url( $it['href'] );
		$path  = ( isset( $parts['path'] ) && '/' !== $parts['path'] ) ? $parts['path'] : '';
		$path .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		if ( 'internal' === $it['kind'] ) {
			return '' !== $path ? $path : '/';
		}
		$host = isset( $parts['host'] ) ? preg_replace( '/^www\./i', '', $parts['host'] ) : '';
		return $host . $path;
	}

	/**
	 * Render the "Links" section of the classic-editor metabox, styled per the
	 * editor-concepts spec: an internal/external split count in the head, two
	 * groups with hairline headers, compact icon rows and typed tags. The
	 * block-editor panel renders the same thing client-side (editor-panel.js).
	 */
	private function render_links_section( WP_Post $post, array $d ): void {
		$links     = $this->extract_links( (string) $post->post_content );
		$int_count = count( $links['internal'] );
		$ext_count = count( $links['external'] );

		echo '<div class="seonix-mb-group seonix-mb-links">';
		echo '<div class="seonix-mb-grouphead">';
		echo '<span class="seonix-cat seonix-cat--links">' . esc_html( $d['i18n']['linksLabel'] ) . '</span>';
		echo '<span class="lnk-split"><span class="in">' . esc_html( (string) $int_count ) . '</span><span class="sl">/</span><span class="ex">' . esc_html( (string) $ext_count ) . '</span></span>';
		echo '</div>';

		if ( 0 === $int_count && 0 === $ext_count ) {
			// Whole-section empty state (concept: sx-lnk-void).
			echo '<div class="sx-lnk-void">';
			echo '<span class="ic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 14.5l5-5"></path><path d="M11 6.5l1-1a3.5 3.5 0 0 1 5 5l-1 1"></path><path d="M13 17.5l-1 1a3.5 3.5 0 0 1-5-5l1-1"></path></svg></span>';
			echo '<span class="h">' . esc_html( $d['i18n']['noLinksTitle'] ) . '</span>';
			echo '<span class="s">' . esc_html( $d['i18n']['noLinksSub'] ) . '</span>';
			echo '</div></div>';
			return;
		}

		$this->render_link_group( $links['internal'], $d['i18n']['internalLinks'], $d['i18n']['noInternal'], 'internal' );
		$this->render_link_group( $links['external'], $d['i18n']['externalLinks'], $d['i18n']['noExternal'], 'external' );

		echo '</div>';
	}

	/** Render one concept-style link group: hairline header + icon rows. */
	private function render_link_group( array $items, string $label, string $empty, string $group ): void {
		echo '<div class="sx-lnk-group">';
		echo '<div class="sx-lnk-group-h"><span>' . esc_html( $label ) . '</span><span class="n">' . esc_html( (string) count( $items ) ) . '</span><span class="rule"></span></div>';
		if ( empty( $items ) ) {
			$svg = 'external' === $group
				? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7"></path><path d="M8 7h9v9"></path></svg>'
				: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 14.5l5-5"></path><path d="M11 6.5l1-1a3.5 3.5 0 0 1 5 5l-1 1"></path><path d="M13 17.5l-1 1a3.5 3.5 0 0 1-5-5l1-1"></path></svg>';
			echo '<div class="sx-lnk-empty">' . $svg . '<span>' . esc_html( $empty ) . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG above.
			return;
		}
		foreach ( $items as $it ) {
			$ico_cls = 'sx-lnk-ico';
			if ( 'external' === $it['kind'] ) {
				$ico_cls .= ' ext';
			} elseif ( 'mail' === $it['kind'] ) {
				$ico_cls .= ' mail';
			}
			$path_cls = 'sx-lnk-path' . ( 'external' === $it['kind'] || 'mail' === $it['kind'] ? ' sx-lnk-ext-path' : '' );
			$display  = $this->link_display_path( $it );
			$anchor   = '' !== $it['anchor'] ? $it['anchor'] : $display;

			echo '<a class="sx-lnk" href="' . esc_url( $it['href'] ) . '" target="_blank" rel="noopener noreferrer">';
			echo '<span class="' . esc_attr( $ico_cls ) . '">' . $this->link_type_svg( $it['kind'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			echo '<span class="sx-lnk-text"><span class="sx-lnk-anchor">' . esc_html( $anchor ) . '</span><span class="' . esc_attr( $path_cls ) . '">' . esc_html( $display ) . '</span></span>';
			if ( ! empty( $it['nofollow'] ) ) {
				echo '<span class="sx-lnk-tag">nofollow</span>';
			}
			echo '</a>';
		}
		echo '</div>';
	}

	public function render( $post ): void {
		echo '<div class="seonix-metabox">';

		if ( ! ( $post instanceof WP_Post ) ) {
			echo '<div class="seonix-mb-note">' . esc_html__( 'Seonix audits published pages.', 'seonix' ) . '</div></div>';
			return;
		}

		$d = $this->audit_data( $post );

		// Header: light + verdict + sub.
		echo '<div class="seonix-mb-head">';
		echo '<span class="seonix-mb-light seonix-mb-light--' . esc_attr( $d['light'] ) . '"></span>';
		echo '<div class="seonix-mb-headtext">';
		echo '<div class="seonix-mb-verdict">' . esc_html( $d['verdict'] ) . '</div>';
		if ( '' !== $d['sub'] ) {
			echo '<div class="seonix-mb-sub">' . esc_html( $d['sub'] ) . '</div>';
		}
		echo '</div></div>';

		// Authoring input, so it renders in every audit state — including the
		// unpublished one, where setting the keyphrase before writing is exactly
		// the point.
		$this->render_focus_keyword_field( $post, $d );

		// Issue groups.
		foreach ( $d['groups'] as $g ) {
			echo '<div class="seonix-mb-group">';
			echo '<div class="seonix-mb-grouphead">';
			echo '<span class="seonix-cat seonix-cat--' . esc_attr( $g['key'] ) . '">' . esc_html( $g['label'] ) . '</span>';
			echo '<span class="seonix-mb-groupcount">' . esc_html( (string) count( $g['items'] ) ) . '</span>';
			echo '</div>';
			foreach ( $g['items'] as $iss ) {
				echo '<div class="seonix-mb-issue">';
				echo '<span class="seonix-pagedot seonix-pagedot--' . esc_attr( $iss['severity'] ) . '" aria-hidden="true"></span>';
				echo '<div class="seonix-mb-issuebody">';
				echo '<div class="seonix-mb-issuetitle">' . esc_html( $iss['title'] );
				if ( ! empty( $iss['informational'] ) ) {
					echo ' <span class="seonix-task__info">' . esc_html( $d['i18n']['optional'] ) . '</span>';
				}
				echo '</div>';
				if ( '' !== $iss['recommendation'] ) {
					echo '<div class="seonix-mb-issuerec">' . esc_html( $iss['recommendation'] ) . '</div>';
				}
				// One-click action when the fix lives on a screen this plugin hosts
				// (the Redirects manager) — same button as the block-editor panel.
				if ( ! empty( $iss['fix_action']['url'] ) ) {
					echo '<a class="sx-iss-action" href="' . esc_url( $iss['fix_action']['url'] ) . '">'
						. esc_html( $iss['fix_action']['label'] ) . ' &rarr;</a>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}

		// Links inventory (internal / external) parsed from the post body.
		$this->render_links_section( $post, $d );

		// Footer.
		echo '<div class="seonix-mb-foot">';
		echo '<a class="seonix-mb-link" href="' . esc_url( $d['allUrl'] ) . '">' . esc_html( $d['i18n']['viewAll'] ) . ' &rarr;</a>';
		if ( '' !== $d['syncedLabel'] ) {
			echo '<span class="seonix-mb-synced">' . esc_html( $d['syncedLabel'] ) . '</span>';
		}
		echo '</div>';

		echo '</div>';
	}
}
