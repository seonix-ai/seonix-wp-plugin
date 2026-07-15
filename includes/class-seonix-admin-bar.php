<?php
/**
 * Seonix node in the WordPress toolbar.
 *
 * Puts the page's standing on every screen the owner already looks at — the
 * live site included — instead of only inside the editor sidebar. Hovering the
 * node shows the last scored SEO / readability values for this page and how
 * many issues the last scan found on it, with a way through to the editor and
 * to the Seonix dashboard.
 *
 * Two independent sources, deliberately kept apart because they answer
 * different questions:
 *   - scores       → per-post meta, written when the post is saved from the
 *                    editor (see Seonix_Metabox::persist_scores_on_save). They
 *                    describe the SAVED revision, which is what a visitor sees.
 *   - issue counts → the last crawl (Seonix_Tasks::issues_for_url), which is
 *                    site-wide and may lag a fresh edit.
 * When a page has neither, the node stays quiet rather than inventing a zero.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Admin_Bar {

	/** Only users who can act on the result see the node. */
	const CAP = 'edit_posts';

	/** Post meta holding the last saved scores (ints 0-100). */
	const META_SEO         = '_seonix_seo_score';
	const META_READABILITY = '_seonix_readability_score';

	/** @var Seonix_Tasks */
	private $tasks;

	public function __construct( Seonix_Tasks $tasks ) {
		$this->tasks = $tasks;
	}

	public function register(): void {
		// Priority 100: after core's own nodes, next to the other plugin nodes.
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_bar_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_bar_styles' ) );
	}

	/**
	 * The toolbar CSS is tiny and needed on the FRONT END too, where none of the
	 * plugin's admin styles load. Inline it rather than shipping a stylesheet
	 * request for ~15 lines.
	 */
	public function enqueue_bar_styles(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( self::CAP ) ) {
			return;
		}
		$css = '
#wpadminbar .seonix-bar-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:baseline}
#wpadminbar .seonix-bar-dot--good{background:#22c55e}
#wpadminbar .seonix-bar-dot--ok{background:#f59e0b}
#wpadminbar .seonix-bar-dot--bad{background:#ef4444}
#wpadminbar .seonix-bar-dot--none{background:#8c8f94}
#wpadminbar #wp-admin-bar-seonix-bar .seonix-bar-logo{width:18px;height:18px;border-radius:4px;vertical-align:middle;margin:-2px 6px 0 0}
#wpadminbar #wp-admin-bar-seonix-bar-scores-seo .ab-item,
#wpadminbar #wp-admin-bar-seonix-bar-scores-read .ab-item{min-width:180px}
';
		// No stylesheet is registered on the front end, so hang it on the handle
		// core always prints when the bar shows.
		wp_add_inline_style( 'admin-bar', $css );
	}

	/**
	 * Build the node.
	 *
	 * @param WP_Admin_Bar $bar Toolbar instance.
	 */
	public function add_node( $bar ): void {
		if ( ! current_user_can( self::CAP ) || ! Seonix_Auth::is_connected() ) {
			return;
		}

		$post_id = $this->current_post_id();
		$url     = $this->current_url();

		$seo    = $this->saved_score( $post_id, self::META_SEO );
		$read   = $this->saved_score( $post_id, self::META_READABILITY );
		$issues = ( '' !== $url ) ? $this->tasks->issues_for_url( $url ) : array();

		$bar->add_node(
			array(
				'id'    => 'seonix-bar',
				'title' => $this->logo() . '<span class="ab-label">' . esc_html__( 'Seonix', 'seonix' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=seonix' ),
				'meta'  => array( 'class' => 'seonix-bar' ),
			)
		);

		$this->add_score_nodes( $bar, $seo, $read, $post_id );
		$this->add_issue_node( $bar, $issues, $url );
		$this->add_link_nodes( $bar, $post_id );
	}

	// ─── Nodes ───────────────────────────────────────────────────────────

	/**
	 * SEO / readability rows. Shown only where a score can exist at all (a
	 * singular post): on an archive or the home page the question has no answer,
	 * and a grey "—" there would read as a bad score rather than "not
	 * applicable".
	 *
	 * @param WP_Admin_Bar $bar
	 * @param int|null     $seo
	 * @param int|null     $read
	 * @param int          $post_id
	 */
	private function add_score_nodes( $bar, $seo, $read, int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$bar->add_node(
			array(
				'parent' => 'seonix-bar',
				'id'     => 'seonix-bar-scores-seo',
				'title'  => $this->dot( $seo ) . esc_html(
					null === $seo
						/* translators: shown when the post has never been scored. */
						? __( 'SEO: not scored yet', 'seonix' )
						/* translators: %d: SEO score out of 100. */
						: sprintf( __( 'SEO: %d', 'seonix' ), $seo )
				),
				'href'   => get_edit_post_link( $post_id, 'raw' ),
			)
		);

		$bar->add_node(
			array(
				'parent' => 'seonix-bar',
				'id'     => 'seonix-bar-scores-read',
				'title'  => $this->dot( $read ) . esc_html(
					null === $read
						? __( 'Readability: not scored yet', 'seonix' )
						/* translators: %d: readability score out of 100. */
						: sprintf( __( 'Readability: %d', 'seonix' ), $read )
				),
				'href'   => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Issue count from the last crawl.
	 *
	 * Counts EVERY issue, matching the editor panel's "N issues on this page"
	 * exactly. Filtering notices out here would be defensible on its own, but
	 * the two surfaces sit one click apart — a toolbar saying 4 next to a panel
	 * saying 6 reads as a bug in the product, not as a nuance of severity.
	 *
	 * The dot follows the worst severity present, so an advice-only page stays
	 * amber rather than red.
	 *
	 * @param WP_Admin_Bar     $bar
	 * @param array<int,array> $issues
	 * @param string           $url
	 */
	private function add_issue_node( $bar, array $issues, string $url ): void {
		if ( '' === $url ) {
			return;
		}

		$count = count( $issues );
		$worst = 100;
		foreach ( $issues as $issue ) {
			$severity = isset( $issue['severity'] ) ? (string) $issue['severity'] : 'notice';
			if ( 'error' === $severity ) {
				$worst = 0;
				break;
			}
			if ( 'warning' === $severity ) {
				$worst = 60;
			} elseif ( 100 === $worst ) {
				$worst = 60;
			}
		}

		$bar->add_node(
			array(
				'parent' => 'seonix-bar',
				'id'     => 'seonix-bar-issues',
				'title'  => $this->dot( 0 === $count ? 100 : $worst ) . esc_html(
					0 === $count
						? __( 'No issues on this page', 'seonix' )
						/* translators: %d: number of issues found on this page. */
						: sprintf( _n( '%d issue on this page', '%d issues on this page', $count, 'seonix' ), $count )
				),
				'href'   => admin_url( 'admin.php?page=seonix' ),
			)
		);
	}

	/**
	 * @param WP_Admin_Bar $bar
	 * @param int          $post_id
	 */
	private function add_link_nodes( $bar, int $post_id ): void {
		if ( $post_id > 0 && ! is_admin() ) {
			$bar->add_node(
				array(
					'parent' => 'seonix-bar',
					'id'     => 'seonix-bar-analyze',
					'title'  => esc_html__( 'Analyze this page', 'seonix' ),
					'href'   => get_edit_post_link( $post_id, 'raw' ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'seonix-bar',
				'id'     => 'seonix-bar-health',
				'title'  => esc_html__( 'Site Health', 'seonix' ),
				'href'   => admin_url( 'admin.php?page=seonix' ),
			)
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	/**
	 * The post this request is about: the queried post on the front end, the
	 * edited post in wp-admin. 0 when the screen isn't about one post.
	 */
	private function current_post_id(): int {
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base ) {
				$post = get_post();
				return $post instanceof WP_Post ? (int) $post->ID : 0;
			}
			return 0;
		}
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Canonical URL of the page being viewed, matched against the scan's
	 * affected URLs. Empty in wp-admin for anything but a post being edited.
	 */
	private function current_url(): string {
		$post_id = $this->current_post_id();
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			return is_string( $permalink ) ? $permalink : '';
		}
		if ( ! is_admin() && is_front_page() ) {
			return (string) home_url( '/' );
		}
		return '';
	}

	/**
	 * @param int    $post_id
	 * @param string $key
	 * @return int|null Null when never scored — distinct from a real 0.
	 */
	private function saved_score( int $post_id, string $key ) {
		if ( $post_id <= 0 ) {
			return null;
		}
		$raw = get_post_meta( $post_id, $key, true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return max( 0, min( 100, (int) $raw ) );
	}

	/**
	 * Severity dot. Thresholds mirror the editor panel's gauge colours so the
	 * two surfaces never disagree about what "green" means.
	 *
	 * @param int|null $score
	 */
	private function dot( $score ): string {
		if ( null === $score ) {
			$class = 'none';
		} elseif ( $score >= 80 ) {
			$class = 'good';
		} elseif ( $score >= 50 ) {
			$class = 'ok';
		} else {
			$class = 'bad';
		}
		return '<span class="seonix-bar-dot seonix-bar-dot--' . esc_attr( $class ) . '"></span>';
	}

	private function logo(): string {
		return '<img class="seonix-bar-logo" src="' . esc_url( SEONIX_URL . 'assets/seonix-logo-small.png' ) . '" alt="" />';
	}
}
