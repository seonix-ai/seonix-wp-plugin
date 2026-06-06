<?php
/**
 * Fix method: ssl_mixed_content.
 *
 * Rewrites http:// references to https:// for the site's own host inside a
 * single post's post_content. Third-party http:// URLs are intentionally left
 * alone — we don't know whether the external host supports HTTPS, and silently
 * breaking embedded resources is worse than the warning we're trying to fix.
 *
 * Idempotent: re-applying when no own-host http URLs remain returns no_op.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_SSL_Mixed_Content implements Seonix_Fix_Method {

	private Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'ssl_mixed_content';
	}

	public function validate_params( array $params ) {
		if ( empty( $params['post_id'] ) || ! is_numeric( $params['post_id'] ) ) {
			return new WP_Error( 'missing_post_id', 'post_id is required.', array( 'status' => 400 ) );
		}
		return true;
	}

	public function dry_run( array $params ) {
		$loaded = $this->load_post( (int) $params['post_id'] );
		if ( $loaded instanceof WP_Error ) {
			return $loaded;
		}
		[ $post, $rewritten ] = $this->compute_rewrite( $loaded );

		return $this->describe_result( $post, $rewritten );
	}

	public function apply( array $params ) {
		$loaded = $this->load_post( (int) $params['post_id'] );
		if ( $loaded instanceof WP_Error ) {
			return $loaded;
		}
		[ $post, $rewritten ] = $this->compute_rewrite( $loaded );

		if ( $rewritten === $post->post_content ) {
			return $this->describe_result( $post, $rewritten );
		}

		$update = wp_update_post( array(
			'ID'           => (int) $post->ID,
			'post_content' => $rewritten,
		), true );

		if ( $update instanceof WP_Error || 0 === (int) $update ) {
			return new WP_Error(
				'update_failed',
				'wp_update_post returned an error or zero id.',
				array( 'status' => 500 )
			);
		}

		return $this->describe_result( $post, $rewritten );
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$before = $entry['before_state'] ?? array();
		$post_id = (int) ( $entry['target_id'] ?? 0 );
		$old_content = $before['post_content'] ?? null;

		if ( $post_id <= 0 || ! is_string( $old_content ) ) {
			return new WP_Error( 'invalid_history_entry', 'History entry is missing post snapshot.', array( 'status' => 422 ) );
		}

		$update = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $old_content,
		), true );

		if ( $update instanceof WP_Error || 0 === (int) $update ) {
			return new WP_Error( 'rollback_failed', 'wp_update_post failed during rollback.', array( 'status' => 500 ) );
		}

		return array(
			'before' => array( 'post_content' => $entry['after_state']['post_content'] ?? '' ),
			'after'  => array( 'post_content' => $old_content ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * @return object|\WP_Error
	 */
	private function load_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * Build the rewritten post_content with own-host http URLs upgraded to https.
	 *
	 * @return array{0: object, 1: string} Original post and rewritten content.
	 */
	private function compute_rewrite( $post ): array {
		$home = wp_parse_url( home_url() );
		$host = isset( $home['host'] ) ? $home['host'] : '';

		if ( '' === $host ) {
			return array( $post, $post->post_content );
		}

		// Match http:// followed by the exact host, then any URL-boundary character.
		// Use ~ as the regex delimiter so the # inside the character class doesn't terminate the pattern early.
		$pattern = '~http://(' . preg_quote( $host, '~' ) . ')(?=[/:?#"\'\s]|$)~i';

		$rewritten = preg_replace( $pattern, 'https://$1', $post->post_content );

		return array( $post, is_string( $rewritten ) ? $rewritten : $post->post_content );
	}

	private function describe_result( $post, string $rewritten ): array {
		$is_no_op = $rewritten === $post->post_content;

		return array(
			'before' => array( 'post_content' => $post->post_content ),
			'after'  => array( 'post_content' => $rewritten ),
			'no_op'  => $is_no_op,
			'target' => array(
				'type' => 'post',
				'id'   => (int) $post->ID,
			),
		);
	}
}
