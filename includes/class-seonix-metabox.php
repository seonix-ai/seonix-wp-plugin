<?php
/**
 * Per-page audit for the post editor (Yoast-style).
 *
 * Surfaces the current page's Seonix audit where the user edits:
 *   - in the BLOCK editor (Gutenberg) → a panel in the document sidebar
 *     (assets/editor-panel.js), like Yoast, so it is immediately visible;
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
				'noKeyphrase'      => __( 'No focus keyphrase set — keyphrase checks are skipped.', 'seonix' ),
				/* translators: %d: score out of 100. */
				'scoreOutOf'       => __( '%d out of 100', 'seonix' ),
			),
			'groups'  => array(),
			'light'   => 'green',
			'verdict' => '',
			'sub'     => '',
			'syncedLabel' => '',
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
	 * Render the CLASSIC meta box body (classic editor only). Uses the same
	 * audit_data() payload the sidebar panel renders, so they match exactly.
	 *
	 * @param WP_Post $post The post being edited.
	 */
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
				echo '</div></div>';
			}
			echo '</div>';
		}

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
