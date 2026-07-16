<?php
/**
 * A "Seonix" column in the Posts / Pages list tables, showing each post's last
 * saved SEO score at a glance — the same number the editor panel and the
 * toolbar show, read straight from post meta (no backend call to render a list).
 *
 * The score is whatever was live in the editor the last time the post was saved
 * (Seonix_Metabox::persist_scores_on_save). A post never scored shows a neutral
 * dash rather than a fake zero.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Post_Columns {

	/** Our column's id in the list table. */
	const COLUMN = 'seonix_score';

	/** Dot colours by score band — the same thresholds as the toolbar dot. */
	const COLOR_GOOD = '#22c55e';
	const COLOR_OK   = '#f59e0b';
	const COLOR_BAD  = '#ef4444';
	const COLOR_NONE = '#c7c9d1';

	/**
	 * Register the column on every editor list table. Gated on connection: the
	 * score comes from the Seonix engine, so an unconnected site has none to show
	 * and the column would be a wall of dashes.
	 */
	public function register(): void {
		if ( ! Seonix_Auth::is_connected() ) {
			return;
		}
		add_action( 'admin_init', array( $this, 'attach' ) );
		add_action( 'admin_print_styles-edit.php', array( $this, 'print_styles' ) );
	}

	/**
	 * Hook the column header + cell for each public post type. The
	 * post-type-specific hooks cover posts, pages and custom types uniformly.
	 */
	public function attach(): void {
		foreach ( $this->post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_cell' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'make_sortable' ) );
		}
		// Order the list by the SEO score meta when our column is the sort key.
		add_action( 'pre_get_posts', array( $this, 'apply_sort' ) );
	}

	/**
	 * Public, UI-visible post types minus attachment — matches where the editor
	 * audit runs.
	 *
	 * @return array<int,string>
	 */
	private function post_types(): array {
		$types = get_post_types( array( 'public' => true, 'show_ui' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Insert the Seonix column right before the Date column when there is one,
	 * otherwise append it.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$label = __( 'Seonix', 'seonix' );
		if ( isset( $columns['date'] ) ) {
			$out = array();
			foreach ( $columns as $key => $value ) {
				if ( 'date' === $key ) {
					$out[ self::COLUMN ] = $label;
				}
				$out[ $key ] = $value;
			}
			return $out;
		}
		$columns[ self::COLUMN ] = $label;
		return $columns;
	}

	/**
	 * Render the cell: a coloured dot + the SEO score, with a tooltip carrying
	 * both scores. A post that was never scored shows a dash and a nudge.
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public function render_cell( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post_id = (int) $post_id;
		$seo     = $this->score( $post_id, Seonix_Admin_Bar::META_SEO );
		$read    = $this->score( $post_id, Seonix_Admin_Bar::META_READABILITY );

		if ( null === $seo ) {
			printf(
				'<span class="seonix-col seonix-col--none" title="%s"><span class="seonix-col__dot" style="background:%s"></span><span class="seonix-col__score">&mdash;</span></span>',
				esc_attr__( 'Not scored yet — open this in the editor to score it.', 'seonix' ),
				esc_attr( self::COLOR_NONE )
			);
			return;
		}

		$tip = sprintf(
			/* translators: 1: SEO score, 2: readability score. */
			__( 'SEO %1$d · Readability %2$s', 'seonix' ),
			$seo,
			null === $read ? '—' : (string) $read
		);

		printf(
			'<span class="seonix-col" title="%s"><span class="seonix-col__dot" style="background:%s"></span><span class="seonix-col__score">%d</span></span>',
			esc_attr( $tip ),
			esc_attr( $this->color( $seo ) ),
			(int) $seo
		);
	}

	/** Mark the column sortable. */
	public function make_sortable( $columns ) {
		if ( is_array( $columns ) ) {
			$columns[ self::COLUMN ] = self::COLUMN;
		}
		return $columns;
	}

	/**
	 * When the list is sorted by our column, order by the SEO score meta.
	 * Posts with no score sort last (they have no meta row to compare).
	 *
	 * @param WP_Query $query
	 */
	public function apply_sort( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( self::COLUMN !== $query->get( 'orderby' ) ) {
			return;
		}
		// LEFT JOIN so unscored posts still appear (NULLs sort to the bottom on
		// DESC, the useful default: best scores first, unscored last).
		$query->set( 'meta_key', Seonix_Admin_Bar::META_SEO );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Tiny inline style for the dot — printed on edit.php only, so no stylesheet
	 * request just for a coloured circle.
	 */
	public function print_styles(): void {
		echo '<style>'
			. '.seonix-col{display:inline-flex;align-items:center;gap:7px;font-variant-numeric:tabular-nums}'
			. '.seonix-col__dot{width:9px;height:9px;border-radius:50%;flex:none;display:inline-block}'
			. '.seonix-col__score{font-weight:600}'
			. '.seonix-col--none .seonix-col__score{color:#8c8f94;font-weight:500}'
			. 'th.column-' . esc_attr( self::COLUMN ) . ',td.column-' . esc_attr( self::COLUMN ) . '{width:88px}'
			. '</style>';
	}

	/**
	 * Saved score for a meta key, clamped 0-100, or null when absent.
	 *
	 * @param int    $post_id
	 * @param string $key
	 * @return int|null
	 */
	private function score( int $post_id, string $key ) {
		if ( $post_id <= 0 ) {
			return null;
		}
		$raw = get_post_meta( $post_id, $key, true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return max( 0, min( 100, (int) $raw ) );
	}

	/** Score → dot colour. Same bands as the toolbar. */
	private function color( int $score ): string {
		if ( $score >= 80 ) {
			return self::COLOR_GOOD;
		}
		if ( $score >= 50 ) {
			return self::COLOR_OK;
		}
		return self::COLOR_BAD;
	}
}
