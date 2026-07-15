<?php
/**
 * Creates redirects for the two things that silently break links: renaming a
 * published post, and deleting one.
 *
 * WordPress ships a partial answer for the rename case (wp_old_slug_redirect),
 * but it only survives while the old slug stays in post meta, only covers posts
 * reached by slug, and leaves nothing the owner can see, edit, or audit. Trashing
 * a post has no answer at all — the URL just starts 404ing.
 *
 * So the rule lands in our own table: visible on the Redirects screen, editable,
 * countable (hits), and removable. Both cases are opt-out via filter, because a
 * site that renames drafts constantly may not want the noise.
 *
 * Deliberately narrow, to avoid inventing redirects nobody asked for:
 *   - only published posts (a draft's URL was never public)
 *   - only a real slug/parent change (not every save)
 *   - never overwrites an existing rule for that path
 *   - trashed posts get a rule ONLY if a target is obvious (the parent), else
 *     410 Gone, which is the honest answer for content that isn't coming back
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Watcher {

	/** @var Seonix_Redirects_Store */
	private $store;

	public function __construct( Seonix_Redirects_Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		// post_updated carries both the before and after rows — the only hook that
		// can tell a rename from any other save.
		add_action( 'post_updated', array( $this, 'maybe_redirect_renamed' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'maybe_redirect_trashed' ), 10, 1 );
	}

	/**
	 * A published post's permalink changed → point the old URL at the new one.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $after
	 * @param \WP_Post $before
	 */
	public function maybe_redirect_renamed( $post_id, $after = null, $before = null ): void {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post ) {
			return;
		}
		if ( ! $this->is_watchable( $after ) || ! $this->enabled( 'rename', $post_id ) ) {
			return;
		}

		// Only a post that WAS public can have a link worth saving. Publishing a
		// draft changes its slug all the time and breaks nothing.
		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		if ( $before->post_name === $after->post_name && (int) $before->post_parent === (int) $after->post_parent ) {
			return;
		}
		// A post with no slug yet (auto-draft becoming public) has no old URL.
		if ( '' === (string) $before->post_name ) {
			return;
		}

		$old = $this->permalink_for( $before );
		$new = $this->permalink_for( $after );
		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}

		$this->add_rule( $old, $new, 301, $post_id );
	}

	/**
	 * A published post went to the trash → its URL is about to 404.
	 *
	 * Sends visitors to the parent when there is one (a child page's parent is a
	 * genuinely relevant destination). Otherwise 410 Gone: guessing a target for
	 * deleted content — the homepage, the blog index — is a soft-404 that annoys
	 * visitors and confuses crawlers. "Gone" is true and gets the URL dropped.
	 *
	 * @param int $post_id
	 */
	public function maybe_redirect_trashed( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->is_watchable( $post ) || ! $this->enabled( 'trash', $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$old = $this->permalink_for( $post );
		if ( '' === $old ) {
			return;
		}

		$parent = (int) $post->post_parent;
		if ( $parent > 0 ) {
			$parent_post = get_post( $parent );
			if ( $parent_post instanceof WP_Post && 'publish' === $parent_post->post_status ) {
				$target = $this->permalink_for( $parent_post );
				if ( '' !== $target && $target !== $old ) {
					$this->add_rule( $old, $target, 301, $post_id );
					return;
				}
			}
		}

		$this->add_rule( $old, null, 410, $post_id );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Public, non-revision, non-attachment content only.
	 *
	 * @param \WP_Post $post
	 */
	private function is_watchable( WP_Post $post ): bool {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return false;
		}
		if ( in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) {
			return false;
		}
		// Duck-typed rather than `instanceof WP_Post_Type`: what matters is that
		// the type is public, and register_post_type_object filters may hand back
		// a decorated object.
		$type = get_post_type_object( $post->post_type );
		return is_object( $type ) && ! empty( $type->public );
	}

	/**
	 * Site-relative path of a post's permalink, '' when it has none.
	 *
	 * @param \WP_Post $post
	 */
	private function permalink_for( WP_Post $post ): string {
		// get_permalink() on a trashed post appends "__trashed" to the slug; ask
		// for the link the post had while it was live.
		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}
		$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		return '' === $path ? '' : $path;
	}

	/**
	 * @param string      $from
	 * @param string|null $to
	 * @param int         $code
	 * @param int         $post_id
	 */
	private function add_rule( string $from, ?string $to, int $code, int $post_id ): void {
		// Never clobber a rule someone already has for this path — theirs is the
		// deliberate one.
		if ( null !== $this->store->find_active_conflict( $from ) ) {
			return;
		}

		$result = $this->store->create( array(
			'from_path'   => $from,
			'to_url'      => $to,
			'status_code' => $code,
			'enabled'     => true,
		) );

		/**
		 * Fires after an automatic redirect is created for a renamed or trashed
		 * post (or attempted and rejected — $result is a WP_Error then).
		 *
		 * @param int|\WP_Error $result  New row id, or the validation error.
		 * @param string        $from    Old path.
		 * @param string|null   $to      New target, null for 410.
		 * @param int           $post_id Post that moved.
		 */
		do_action( 'seonix_auto_redirect_created', $result, $from, $to, $post_id );
	}

	/**
	 * @param string $kind 'rename'|'trash'
	 * @param int    $post_id
	 */
	private function enabled( string $kind, int $post_id ): bool {
		/**
		 * Filters whether Seonix auto-creates a redirect when a published post is
		 * renamed or trashed. Return false to keep the table manual-only.
		 *
		 * @param bool   $enabled
		 * @param string $kind    'rename' or 'trash'.
		 * @param int    $post_id
		 */
		return (bool) apply_filters( 'seonix_auto_redirect_enabled', true, $kind, $post_id );
	}
}
