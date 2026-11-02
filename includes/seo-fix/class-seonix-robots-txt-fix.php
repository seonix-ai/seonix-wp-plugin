<?php
/**
 * Reviewable fix for a robots.txt that blocks the whole site.
 *
 * A wrong answer here takes a site out of search, and a deliberate block on a
 * staging or private site looks exactly like an accidental one. So this is not
 * a Seonix_Fix_Method: it is not in the registry, the backend cannot trigger it,
 * and it runs only when an administrator has read the real file and the real
 * change in the task modal and confirmed it. It also runs entirely on this site,
 * which is why it has no plan gate.
 *
 * WHAT IS OFFERED
 * ---------------
 * inspect() reads what the site serves right now and works out who produces it.
 * A button appears only for the two causes this plugin can change and verify:
 *   - A robots.txt file in the site root, by far the usual one. Fix: the blocking
 *     value is cleared from the groups for all crawlers and for Googlebot, one
 *     line each, so "Disallow: /" becomes the valid allow-all "Disallow:". Every
 *     other line stays byte for byte, including rules for AI crawlers: those are
 *     often deliberate and are not what this task reports.
 *   - A plugin whose robots_txt filter blocks only while "Discourage search
 *     engines" (blog_public = 0) is ticked. Fix: blog_public = 1. Core itself has
 *     not put Disallow: / in robots.txt for that box since WordPress 5.3: it prints
 *     a noindex instead, so that box alone never reaches this path. inspect()
 *     reports it separately as `discouraged`, because lifting a robots block
 *     does not get a noindexed site into search.
 *
 * Everything else stays manual, with the file shown: another plugin's
 * robots_txt filter, a CDN or host answering before WordPress (what is served
 * differs from what WordPress produces), a non-production environment, a site
 * below the host root (crawlers never read that file), a multisite root file.
 *
 * SAFETY
 * ------
 * apply() takes a token of the exact state the administrator reviewed and refuses
 * if anything changed since. It stores what it replaced, re-reads what it wrote,
 * purges the page caches and re-fetches the live file to report honestly whether
 * the block is gone. undo() restores the snapshot and refuses to overwrite a file
 * someone edited after the fix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Robots_Txt_Fix {

	/** Task codes the modal shows this panel for. */
	const CODES = array( 'robots_blocks_all', 'robots_blocks_sitemap_path' );

	const STATE_FIXABLE = 'fixable';
	const STATE_CLEAR   = 'clear';
	const STATE_MANUAL  = 'manual';

	const SOURCE_SETTING = 'wp_setting';
	const SOURCE_FILE    = 'file';
	const SOURCE_FILTER  = 'plugin_filter';
	const SOURCE_REMOTE  = 'upstream';

	/** What the last fix replaced, for undo. Not autoloaded. */
	const OPTION_SNAPSHOT = 'seonix_robots_fix_snapshot';

	/** Google reads the first 500 KiB; anything larger is not a file to edit blind. */
	const MAX_FILE_BYTES = 512000;

	public static function handles( string $code ): bool {
		return in_array( $code, self::CODES, true );
	}

	/**
	 * Whether a robots.txt keeps Googlebot off the home page: the rule the scanner
	 * reports. Googlebot obeys its own group when one exists and the group for all
	 * crawlers otherwise; groups naming the same agent merge.
	 */
	public static function blocks_site( string $text ): bool {
		$disallow = 0;
		$allow    = 0;
		foreach ( self::effective_rules( $text ) as $rule ) {
			if ( ! self::is_site_wide_value( $rule['value'] ) ) {
				continue;
			}
			$length = strlen( $rule['value'] );
			if ( 'allow' === $rule['type'] ) {
				$allow = max( $allow, $length );
			} else {
				$disallow = max( $disallow, $length );
			}
		}
		// The longest pattern wins and a tie goes to Allow, so "Disallow: /*" beats
		// "Allow: /" while "Disallow: /" does not.
		return $disallow > $allow;
	}

	/**
	 * Whether the blocking rules come with Allow exceptions: "close everything
	 * except these paths" is a decision someone made, and clearing the Disallow
	 * would open the whole site, not just what they listed.
	 */
	public static function has_allow_exceptions( string $text ): bool {
		foreach ( self::rules_by_line( $text ) as $rule ) {
			if ( 'allow' === $rule['type'] && array() !== array_intersect( $rule['agents'], array( '*', 'googlebot' ) ) && '' !== $rule['value'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The file with every site-wide Disallow cleared from the groups for all
	 * crawlers and for Googlebot. Only the value of those lines changes; line
	 * endings, comments and every other rule survive untouched. The caller rules
	 * out files with Allow exceptions first (has_allow_exceptions()).
	 */
	public static function unblock( string $text ): string {
		$by_line = array();
		foreach ( self::rules_by_line( $text ) as $rule ) {
			$by_line[ $rule['line'] ] = $rule;
		}

		$parts = preg_split( '/(\r\n|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $text;
		}
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				continue; // A captured line ending.
			}
			$line = (int) ( $i / 2 );
			if ( ! isset( $by_line[ $line ] ) ) {
				continue;
			}
			$rule = $by_line[ $line ];
			if (
				'disallow' === $rule['type']
				&& self::is_site_wide_value( $rule['value'] )
				&& array() !== array_intersect( $rule['agents'], array( '*', 'googlebot' ) )
			) {
				$hash        = strpos( $part, '#' );
				$comment     = false === $hash ? '' : ' ' . substr( $part, $hash );
				$parts[ $i ] = substr( $part, 0, (int) strpos( $part, ':' ) + 1 ) . $comment;
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Read-only: what the site serves now, who produces it, what the fix would be.
	 *
	 * @return array{state:string,reason:string,source:string,live_checked:bool,current:string,proposed:?string,token:string,can_undo:bool,discouraged:bool}
	 */
	public function inspect(): array {
		$live   = $this->fetch_live();
		$public = (string) get_option( 'blog_public', '1' );
		$file   = $this->read_file();
		$wp_now = $this->wp_output( (bool) $public );

		if ( null !== $live ) {
			$served = $live;
		} else {
			$served = null !== $file ? $file : $wp_now;
		}

		$result = array(
			'state'        => self::STATE_MANUAL,
			'reason'       => '',
			'source'       => '',
			'live_checked' => null !== $live,
			'current'      => $served,
			'proposed'     => null,
			'token'        => '',
			'can_undo'     => $this->can_undo( $public, $file ),
			'discouraged'  => ! $public,
		);

		if ( ! self::blocks_site( $served ) ) {
			$result['state']  = self::STATE_CLEAR;
			$result['reason'] = 'not_blocked';
			return $result;
		}

		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( is_string( $path ) && '' !== trim( $path, '/' ) ) {
			$result['reason'] = 'not_at_root';
			return $result;
		}

		if ( 'production' !== wp_get_environment_type() ) {
			$result['reason'] = 'non_production';
			return $result;
		}

		if ( null !== $file ) {
			return $this->inspect_file( $result, $file, $live );
		}
		return $this->inspect_generated( $result, $public, $wp_now, $live );
	}

	/**
	 * @param string $token The token inspect() returned for the state that was reviewed.
	 * @return array{verified:?bool,inspection:array}|\WP_Error
	 */
	public function apply( string $token ) {
		$inspection = $this->inspect();
		if ( self::STATE_FIXABLE !== $inspection['state'] ) {
			return new WP_Error( 'not_fixable', __( 'This can no longer be fixed from here. Reopen the task to see the current state.', 'seonix' ), array( 'status' => 409 ) );
		}
		if ( ! hash_equals( $inspection['token'], $token ) ) {
			return new WP_Error( 'stale_review', __( 'robots.txt changed after you reviewed it. Review the new version before applying.', 'seonix' ), array( 'status' => 409 ) );
		}

		if ( self::SOURCE_SETTING === $inspection['source'] ) {
			$before = (string) get_option( 'blog_public', '1' );
			update_option( 'blog_public', '1' );
			if ( '1' !== (string) get_option( 'blog_public' ) ) {
				return new WP_Error( 'update_failed', __( 'WordPress did not save the setting.', 'seonix' ), array( 'status' => 500 ) );
			}
			$after = '1';
		} else {
			$before = (string) $this->read_file();
			$after  = (string) $inspection['proposed'];
			if ( ! $this->write_file( $after ) || $this->read_file() !== $after ) {
				$this->write_file( $before );
				return new WP_Error( 'write_failed', __( 'The robots.txt file could not be written. Nothing was changed.', 'seonix' ), array( 'status' => 500 ) );
			}
		}

		update_option(
			self::OPTION_SNAPSHOT,
			array(
				'source'     => $inspection['source'],
				'before'     => $before,
				'after_hash' => hash( 'sha256', $after ),
				'applied_at' => time(),
				'user_id'    => get_current_user_id(),
			),
			false
		);
		Seonix_Cache_Purger::purge_all();

		$after_fix = $this->inspect();
		return array(
			'verified'   => $after_fix['live_checked'] ? ! self::blocks_site( $after_fix['current'] ) : null,
			'inspection' => $after_fix,
		);
	}

	/**
	 * Put back what the last fix replaced.
	 *
	 * @return array{inspection:array}|\WP_Error
	 */
	public function undo() {
		$snapshot = get_option( self::OPTION_SNAPSHOT );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['source'], $snapshot['before'] ) ) {
			return new WP_Error( 'nothing_to_undo', __( 'There is no Seonix change to undo.', 'seonix' ), array( 'status' => 409 ) );
		}
		if ( ! $this->can_undo( (string) get_option( 'blog_public', '1' ), $this->read_file() ) ) {
			return new WP_Error( 'changed_since', __( 'This was changed after the Seonix fix, so it was left as it is.', 'seonix' ), array( 'status' => 409 ) );
		}

		if ( self::SOURCE_SETTING === $snapshot['source'] ) {
			update_option( 'blog_public', (string) $snapshot['before'] );
		} elseif ( ! $this->write_file( (string) $snapshot['before'] ) ) {
			return new WP_Error( 'write_failed', __( 'The robots.txt file could not be written. Nothing was changed.', 'seonix' ), array( 'status' => 500 ) );
		}

		delete_option( self::OPTION_SNAPSHOT );
		Seonix_Cache_Purger::purge_all();
		return array( 'inspection' => $this->inspect() );
	}

	/**
	 * Plain-language copy for an inspection, on the server so the JS only renders.
	 *
	 * @param array  $inspection inspect() result.
	 * @param string $code       Task the modal is showing.
	 * @return array{headline:string,detail:string,changes:string[]}
	 */
	public static function describe( array $inspection, string $code ): array {
		$headline = '';
		$detail   = '';
		$changes  = array();

		switch ( $inspection['reason'] ) {
			case 'not_blocked':
				if ( 'robots_blocks_sitemap_path' === $code ) {
					$headline = __( 'The whole site is not blocked', 'seonix' );
					$detail   = __( 'Your robots.txt has no site-wide block, so Seonix has nothing safe to change here. If a narrower rule covers your sitemap, it is in the file below: remove that rule by hand.', 'seonix' );
				} else {
					$headline = __( 'robots.txt no longer blocks the site', 'seonix' );
					$detail   = __( 'Crawlers are allowed in right now. This task will clear on the next scan.', 'seonix' );
				}
				break;
			case 'setting':
				$headline  = __( 'A plugin blocks crawlers because of a WordPress setting', 'seonix' );
				$detail    = __( 'The "Discourage search engines from indexing this site" box is ticked under Settings → Reading, and a plugin on this site turns that into a block in robots.txt. There is no file on disk.', 'seonix' );
				$changes[] = __( 'Unticks "Discourage search engines from indexing this site".', 'seonix' );
				$changes[] = __( 'WordPress also stops printing a noindex tag on every page, so your pages can appear in search.', 'seonix' );
				break;
			case 'file':
				$headline  = __( 'A robots.txt file in your site root blocks the site', 'seonix' );
				$detail    = __( 'Only the highlighted line changes. Every other line in the file stays exactly as it is.', 'seonix' );
				$changes[] = __( 'Clears the site-wide Disallow for all crawlers and Googlebot. An empty "Disallow:" means nothing is blocked.', 'seonix' );
				break;
			case 'file_not_writable':
				$headline = __( 'A robots.txt file blocks the site, and WordPress cannot edit it', 'seonix' );
				$detail   = __( 'The file is not writable from here. Replace its contents with the version below using your hosting file manager.', 'seonix' );
				break;
			case 'allow_exceptions':
				$headline = __( 'This robots.txt looks deliberate', 'seonix' );
				$detail   = __( 'It closes the site but allows specific paths, which someone chose on purpose. Clearing the block would open everything, so decide by hand which rules to keep.', 'seonix' );
				break;
			case 'file_unusable':
				$headline = __( 'This robots.txt file needs a manual edit', 'seonix' );
				$detail   = __( 'Seonix could not edit this file safely because of its size or encoding. Remove the "Disallow: /" line by hand.', 'seonix' );
				break;
			case 'plugin_filter':
				$headline = __( 'Another plugin writes this robots.txt', 'seonix' );
				$detail   = __( 'The blocking rule is added by a plugin through WordPress, so changing it from here would not last. Check the robots.txt editor of your SEO plugin (Rank Math, All in One SEO) and any maintenance or "coming soon" plugin.', 'seonix' );
				break;
			case 'upstream':
				$headline = __( 'Something in front of WordPress serves this robots.txt', 'seonix' );
				$detail   = __( 'What crawlers get differs from what this site produces, so the block comes from your CDN or host (for example Cloudflare managed robots.txt, or a temporary or staging domain). Nothing Seonix changes here would reach it. Check your hosting and CDN settings.', 'seonix' );
				break;
			case 'non_production':
				$headline = __( 'This site is not marked as production', 'seonix' );
				$detail   = __( 'Blocking search engines is normal on a staging or development copy, so Seonix will not lift it here.', 'seonix' );
				break;
			case 'not_at_root':
				$headline = __( 'This site lives in a subfolder', 'seonix' );
				$detail   = __( 'Crawlers read robots.txt only from the root of the domain, so the file to change is outside this WordPress install.', 'seonix' );
				break;
			case 'multisite_file':
				$headline = __( 'This robots.txt is shared by the whole network', 'seonix' );
				$detail   = __( 'On a multisite network the file affects every site, so it has to be edited by hand.', 'seonix' );
				break;
		}

		return array(
			'headline' => $headline,
			'detail'   => $detail,
			'changes'  => $changes,
		);
	}

	// ─── Inspection branches ─────────────────────────────────────────────

	/**
	 * @param array       $result Base result.
	 * @param string      $file   robots.txt in the site root.
	 * @param string|null $live   What the site serves, null when it could not be read.
	 */
	private function inspect_file( array $result, string $file, ?string $live ): array {
		if ( null !== $live && ! self::same_rules( $live, $file ) ) {
			$result['source'] = self::SOURCE_REMOTE;
			$result['reason'] = 'upstream';
			return $result;
		}

		$result['source'] = self::SOURCE_FILE;
		if ( is_multisite() ) {
			$result['reason'] = 'multisite_file';
			return $result;
		}
		if ( strlen( $file ) > self::MAX_FILE_BYTES || ! self::is_utf8( $file ) ) {
			$result['reason'] = 'file_unusable';
			return $result;
		}

		if ( self::has_allow_exceptions( $file ) ) {
			$result['reason'] = 'allow_exceptions';
			return $result;
		}

		$proposed = self::unblock( $file );
		if ( $proposed === $file || self::blocks_site( $proposed ) ) {
			$result['reason'] = 'file_unusable';
			return $result;
		}

		$result['current']  = $file;
		$result['proposed'] = $proposed;
		if ( ! $this->file_is_writable() ) {
			$result['reason'] = 'file_not_writable';
			return $result;
		}
		return self::as_fixable( $result, 'file', $file );
	}

	/**
	 * No file on disk: WordPress generates robots.txt.
	 *
	 * @param array       $result Base result.
	 * @param string      $public Stored blog_public.
	 * @param string      $wp_now What WordPress produces right now.
	 * @param string|null $live   What the site serves, null when it could not be read.
	 */
	private function inspect_generated( array $result, string $public, string $wp_now, ?string $live ): array {
		if ( null !== $live && ! self::same_rules( $live, $wp_now ) ) {
			$result['source'] = self::SOURCE_REMOTE;
			$result['reason'] = 'upstream';
			return $result;
		}

		$wp_public = $this->wp_output( true );
		if ( $public || self::blocks_site( $wp_public ) ) {
			$result['source'] = self::SOURCE_FILTER;
			$result['reason'] = 'plugin_filter';
			return $result;
		}

		$result['source']   = self::SOURCE_SETTING;
		$result['current']  = $wp_now;
		$result['proposed'] = $wp_public;
		return self::as_fixable( $result, 'setting', $wp_now );
	}

	private static function as_fixable( array $result, string $reason, string $local ): array {
		$result['state']  = self::STATE_FIXABLE;
		$result['reason'] = $reason;
		$result['token']  = hash( 'sha256', $result['source'] . "\0" . $local . "\0" . $result['proposed'] );
		return $result;
	}

	private function can_undo( string $public, ?string $file ): bool {
		$snapshot = get_option( self::OPTION_SNAPSHOT );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['source'], $snapshot['after_hash'] ) ) {
			return false;
		}
		$now = self::SOURCE_SETTING === $snapshot['source'] ? $public : (string) $file;
		return hash_equals( (string) $snapshot['after_hash'], hash( 'sha256', $now ) );
	}

	// ─── Reading robots.txt ──────────────────────────────────────────────

	/**
	 * Every Allow / Disallow rule with its line number and the agents of its group.
	 *
	 * @return array<int,array{line:int,type:string,value:string,agents:string[]}>
	 */
	private static function rules_by_line( string $text ): array {
		$rules     = array();
		$agents    = array();
		$in_agents = false;
		foreach ( self::directives( $text ) as $directive ) {
			if ( 'user-agent' === $directive['name'] ) {
				if ( ! $in_agents ) {
					$agents = array();
				}
				$agents[]  = strtolower( $directive['value'] );
				$in_agents = true;
				continue;
			}
			$in_agents = false;
			if ( 'allow' === $directive['name'] || 'disallow' === $directive['name'] ) {
				$rules[] = array(
					'line'   => $directive['line'],
					'type'   => $directive['name'],
					'value'  => $directive['value'],
					'agents' => $agents,
				);
			}
		}
		return $rules;
	}

	/**
	 * The rules Googlebot obeys: its own groups when any exist, otherwise the groups
	 * for all crawlers. Groups naming the same agent merge.
	 *
	 * @return array<int,array{line:int,type:string,value:string,agents:string[]}>
	 */
	private static function effective_rules( string $text ): array {
		$own_group = false;
		foreach ( self::directives( $text ) as $directive ) {
			if ( 'user-agent' === $directive['name'] && 'googlebot' === strtolower( $directive['value'] ) ) {
				$own_group = true;
			}
		}
		$agent = $own_group ? 'googlebot' : '*';
		return array_values(
			array_filter(
				self::rules_by_line( $text ),
				static function ( $rule ) use ( $agent ) {
					return in_array( $agent, $rule['agents'], true );
				}
			)
		);
	}

	/** @return array<int,array{line:int,name:string,value:string}> */
	private static function directives( string $text ): array {
		$out   = array();
		$lines = preg_split( '/\r\n|\n|\r/', $text );
		if ( ! is_array( $lines ) ) {
			return $out;
		}
		foreach ( $lines as $number => $line ) {
			if ( 0 === $number && 0 === strpos( $line, "\xEF\xBB\xBF" ) ) {
				$line = substr( $line, 3 );
			}
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$out[] = array(
				'line'  => $number,
				'name'  => strtolower( trim( substr( $line, 0, $colon ) ) ),
				'value' => trim( substr( $line, $colon + 1 ) ),
			);
		}
		return $out;
	}

	private static function is_site_wide_value( string $value ): bool {
		return '/' === $value || '/*' === $value;
	}

	/** Same directives in the same order, ignoring blank lines, comments and spacing. */
	private static function same_rules( string $a, string $b ): bool {
		$strip = static function ( string $text ): array {
			$out = array();
			foreach ( self::directives( $text ) as $directive ) {
				$out[] = $directive['name'] . ':' . $directive['value'];
			}
			return $out;
		};
		return $strip( $a ) === $strip( $b );
	}

	private static function is_utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}

	/**
	 * What WordPress answers for /robots.txt at a given visibility, including every
	 * plugin's robots_txt filter. Mirrors do_robots(), whose own rules do not depend
	 * on the setting; only filters can make them differ.
	 */
	private function wp_output( bool $public ): string {
		$path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output = "User-agent: *\nDisallow: {$path}/wp-admin/\nAllow: {$path}/wp-admin/admin-ajax.php\n";
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, run exactly as do_robots() does.
		return (string) apply_filters( 'robots_txt', $output, $public );
	}

	// ─── I/O (seams for tests) ───────────────────────────────────────────

	/**
	 * What the site serves at /robots.txt: the text, '' for a clean 404 (no file
	 * means nothing is blocked), or null when this site cannot tell. Hosts often
	 * block loopback requests and some answer with an HTML page, and neither says
	 * anything about the rules crawlers get.
	 */
	protected function fetch_live(): ?string {
		$response = wp_remote_get(
			add_query_arg( 'seonix_check', (string) time(), home_url( '/robots.txt' ) ),
			array(
				'timeout'             => 5,
				'limit_response_size' => self::MAX_FILE_BYTES,
				'user-agent'          => 'SeonixBot/1.0 (+https://seonix.ai)',
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter for requests to the site itself.
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status || 410 === $status ) {
			return '';
		}
		if ( 200 !== $status ) {
			return null;
		}
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( false !== stripos( $type, 'html' ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return self::is_utf8( $body ) ? $body : null;
	}

	protected function file_path(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() ) . 'robots.txt';
	}

	/** @return string|null robots.txt in the site root; null when there is none. */
	protected function read_file(): ?string {
		$path = $this->file_path();
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$fs       = $this->filesystem();
		$contents = $fs ? $fs->get_contents( $path ) : false;
		return false === $contents ? null : (string) $contents;
	}

	protected function file_is_writable(): bool {
		// A site locked against file edits has said no to exactly this.
		if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return false;
		}
		$fs = $this->filesystem();
		return $fs && $fs->is_writable( $this->file_path() );
	}

	protected function write_file( string $contents ): bool {
		$fs = $this->filesystem();
		if ( ! $fs ) {
			return false;
		}
		$path = $this->file_path();
		// WP_Filesystem resets the mode when given none; the file keeps its own.
		$perms = fileperms( $path );
		return (bool) $fs->put_contents( $path, $contents, false === $perms ? false : $perms & 0777 );
	}

	/** @return \WP_Filesystem_Base|null */
	private function filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return null;
		}
		global $wp_filesystem;
		return $wp_filesystem;
	}
}
