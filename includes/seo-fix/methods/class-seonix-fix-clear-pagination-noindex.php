<?php
/**
 * Fix method: clear_pagination_noindex.
 *
 * Switches off the active SEO plugin's site-wide settings that noindex
 * paginated pages, so /blog/page/2/, /category/x/page/3/ … are indexable again.
 * Triggered by the scanner's pagination_not_indexable issue (reason noindex),
 * which follows Google's pagination guidance: page 2 and beyond is how crawlers
 * reach older posts, and a noindex kept for long ends up treated as nofollow,
 * so the links to those posts stop counting.
 *
 * WHICH SETTINGS
 * --------------
 * Rank Math has two live toggles of this kind in the `rank-math-options-titles`
 * option ('on' / 'off'), both applied on every is_paged() request:
 *   - `noindex_archive_subpages` ("Noindex Subpages") — every paginated archive,
 *     the blog index included;
 *   - `noindex_paginated_pages` ("Noindex Paginated Single Pages") — the
 *     paginated blog page, independently of the first, plus multi-page posts.
 * Either one alone keeps /page/2/ out of search, so the method switches off
 * whichever of them is on. They are the only settings it writes.
 *
 * Yoast SEO has had no such setting since 6.3 (2018): its upgrade routine dropped
 * `noindex-subpages-wpseo` and no Yoast code reads the key anymore, which is why
 * the legacy yoast_setting_pagination_noindex method never changed a rendered
 * page. A noindexed page 2 on a Yoast site comes from a per-archive setting, the
 * theme or another plugin — nothing one switch can undo. Rank Math's Yoast
 * importer does carry that dead key over into `noindex_archive_subpages`, which
 * is how the old Seonix fix can turn live after a migration.
 *
 * AVAILABILITY
 * ------------
 * is_available() — what /capabilities advertises — holds only while one of the
 * toggles is on, so the dashboard offers the one-click only where it changes the
 * rendered robots and falls back to the manual steps everywhere else. apply() on
 * a site where both are already off is a no_op: the scanner reports one issue
 * per paginated URL, so a run carries many tasks for this one switch.
 *
 * WRITES
 * ------
 * Through Rank Math's public settings writer (RankMath\Helper::update_all_settings),
 * never update_option() on its option — SEO-plugin settings are changed through
 * their own APIs only. The titles array is read raw and written back whole with
 * only the switched keys changed, so every other title / robots setting survives
 * as stored. Every real write ends with a full page-cache purge: the toggles
 * change the robots tag of every paginated page at once. Rollback writes back the
 * exact raw values apply() replaced.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Fix_Clear_Pagination_Noindex implements Seonix_Fix_Method {

	/** Rank Math's "Titles & Meta" settings option. */
	const OPTION = 'rank-math-options-titles';

	/** Rank Math's paginated-noindex toggles inside OPTION (see the class docblock). */
	const KEYS = array( 'noindex_archive_subpages', 'noindex_paginated_pages' );

	/** Class that owns the settings writer (a seam for tests). */
	const WRITER_CLASS = 'RankMath\\Helper';

	/** Engine tag stored in the snapshot; rollback refuses a snapshot of another engine. */
	const ENGINE = 'rankmath';

	protected Seonix_SEO_Fix_History $history;

	public function __construct( Seonix_SEO_Fix_History $history ) {
		$this->history = $history;
	}

	public function key(): string {
		return 'clear_pagination_noindex';
	}

	/**
	 * Params shape: { page_url?: string } — the paginated URL the scan flagged.
	 * Informational only (the settings are site-wide), so an empty array validates.
	 */
	public function validate_params( array $params ) {
		if ( isset( $params['page_url'] ) && ! is_string( $params['page_url'] ) ) {
			return new WP_Error( 'invalid_page_url', 'page_url, when provided, must be a string.', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Advertised to the /capabilities handshake only while a toggle is on:
	 * anywhere else the one-click would change nothing on the rendered pages.
	 */
	public function is_available(): bool {
		return self::is_applicable();
	}

	/**
	 * Same gate as is_available(), for callers without an instance — the admin
	 * task list shows its "Fix it for me" button on it.
	 */
	public static function is_applicable(): bool {
		return null !== self::writer() && array() !== self::on_keys( self::read_settings() );
	}

	public function dry_run( array $params ) {
		unset( $params );
		if ( null === self::writer() ) {
			return self::unsupported_error();
		}
		return $this->describe_result( self::read_settings() );
	}

	public function apply( array $params ) {
		unset( $params );
		if ( null === self::writer() ) {
			return self::unsupported_error();
		}

		$settings = self::read_settings();
		$result   = $this->describe_result( $settings );
		if ( $result['no_op'] ) {
			return $result;
		}

		$written = $this->write_settings( array_fill_keys( self::on_keys( $settings ), 'off' ) );
		if ( $written instanceof WP_Error ) {
			return $written;
		}
		return $result;
	}

	public function rollback( int $history_id ) {
		$entry = $this->history->get( $history_id );
		if ( ! $entry ) {
			return new WP_Error( 'unknown_history_entry', 'No history entry with that id.', array( 'status' => 404 ) );
		}

		$before = $entry['before_state'] ?? null;
		if ( ! is_array( $before ) || ! isset( $before['settings'] ) || ! is_array( $before['settings'] ) ) {
			return new WP_Error( 'invalid_history_entry', 'History entry is missing snapshot.', array( 'status' => 422 ) );
		}
		if ( ( isset( $before['engine'] ) && self::ENGINE !== $before['engine'] ) || null === self::writer() ) {
			return new WP_Error(
				'engine_changed',
				'The SEO plugin whose setting this fix changed is no longer active — restore the setting manually.',
				array( 'status' => 422 )
			);
		}

		// apply() only ever switched off the toggles that were on, so only those
		// are restored — a no-op entry restores nothing, and cannot switch off a
		// toggle the owner turned on since. A toggle already back at its pre-fix
		// value is left alone: there is no later change to clobber.
		$current = self::read_settings();
		$restore = array();
		foreach ( self::on_keys( array_intersect_key( $before['settings'], $current ) ) as $key ) {
			if ( $current[ $key ] !== $before['settings'][ $key ] ) {
				$restore[ $key ] = $before['settings'][ $key ];
			}
		}
		if ( array() !== $restore ) {
			$written = $this->write_settings( $restore );
			if ( $written instanceof WP_Error ) {
				return $written;
			}
		}

		return array(
			'before' => array( 'value' => array() !== self::on_keys( $current ) ),
			'after'  => array( 'value' => array() !== self::on_keys( self::read_settings() ) ),
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Rank Math's public settings writer, or null when Rank Math is not loaded.
	 *
	 * The call is positional — update_all_settings( $general, $titles, $sitemap )
	 * — so the signature is checked first: were the parameters ever reordered, our
	 * titles array would land in another settings group, and a new required
	 * parameter would make the call fatal. Refusing beats corrupting the site's
	 * settings.
	 *
	 * @return callable|null
	 */
	private static function writer(): ?callable {
		$writer = array( static::WRITER_CLASS, 'update_all_settings' );
		if ( ! is_callable( $writer ) ) {
			return null;
		}
		try {
			$method = new ReflectionMethod( static::WRITER_CLASS, 'update_all_settings' );
		} catch ( ReflectionException $e ) {
			return null;
		}
		$params = $method->getParameters();
		if ( ! isset( $params[1] ) || 'titles' !== $params[1]->getName() || $method->getNumberOfRequiredParameters() > 3 ) {
			return null;
		}
		return $writer;
	}

	/**
	 * Raw stored value of each toggle, null when the option or the key is absent.
	 * Read straight from the option: Rank Math's own getter normalizes values
	 * ('on' → true), and a normalized array cannot be written back as is.
	 *
	 * @return array<string,mixed>
	 */
	private static function read_settings(): array {
		$titles   = get_option( self::OPTION );
		$settings = array();
		foreach ( self::KEYS as $key ) {
			$settings[ $key ] = is_array( $titles ) && array_key_exists( $key, $titles ) ? $titles[ $key ] : null;
		}
		return $settings;
	}

	/**
	 * Keys of the toggles that are on.
	 *
	 * @param array<string,mixed> $settings Raw values by key.
	 * @return string[]
	 */
	private static function on_keys( array $settings ): array {
		$on = array();
		foreach ( $settings as $key => $raw ) {
			if ( self::is_on( $raw ) ) {
				$on[] = $key;
			}
		}
		return $on;
	}

	/**
	 * Rank Math's own reading of a toggle value: Helper::normalize_data() maps
	 * 'on' / 'true' to true and 'off' / 'false' to false, and the robots check
	 * then tests plain truthiness.
	 *
	 * @param mixed $raw Stored value.
	 * @return bool
	 */
	private static function is_on( $raw ): bool {
		if ( 'off' === $raw || 'false' === $raw ) {
			return false;
		}
		return (bool) $raw;
	}

	/**
	 * Store $values (key => raw value) through the settings writer, verify they
	 * landed and purge the page caches. The rest of the titles array goes back
	 * exactly as it was read.
	 *
	 * @param array<string,mixed> $values Keys from KEYS.
	 * @return true|\WP_Error
	 */
	private function write_settings( array $values ) {
		$writer = self::writer();
		if ( null === $writer ) {
			return self::unsupported_error();
		}

		$titles = get_option( self::OPTION );
		if ( ! is_array( $titles ) ) {
			// Rank Math seeds this option on install. Writing a fresh array in its
			// place would wipe every title template it holds.
			return new WP_Error( 'settings_unreadable', 'The SEO plugin settings could not be read.', array( 'status' => 409 ) );
		}
		foreach ( $values as $key => $raw ) {
			$titles[ $key ] = $raw;
		}
		call_user_func( $writer, null, $titles, null );

		// The writer returns nothing; re-read the option to confirm the values.
		$stored = self::read_settings();
		foreach ( $values as $key => $raw ) {
			if ( $stored[ $key ] !== $raw ) {
				return new WP_Error( 'update_failed', 'The paginated-page noindex setting did not persist.', array( 'status' => 500 ) );
			}
		}

		// Page caches (LiteSpeed, WP Rocket, Cloudflare…) would keep serving the
		// old robots tag on every paginated page until they expire, and a re-scan
		// would report the fix as not working. Best-effort, the same full purge
		// /seo-fix/cache/purge runs.
		Seonix_Cache_Purger::purge_all();
		return true;
	}

	/**
	 * No engine with a supported paginated-page noindex setting is active. 422
	 * with the code the backend maps to a cleanly skipped item, the same contract
	 * the robots-noindex methods use.
	 */
	private static function unsupported_error(): WP_Error {
		return new WP_Error(
			Seonix_Robots_Noindex::UNSUPPORTED_REASON,
			'No active SEO plugin exposes a supported site-wide noindex setting for paginated pages.',
			array(
				'status'             => 422,
				'applied'            => false,
				'unsupported_reason' => Seonix_Robots_Noindex::UNSUPPORTED_REASON,
			)
		);
	}

	/**
	 * @param array<string,mixed> $settings Raw stored toggle values by key.
	 */
	private function describe_result( array $settings ): array {
		$after = $settings;
		foreach ( self::on_keys( $settings ) as $key ) {
			$after[ $key ] = 'off';
		}
		return array(
			'before' => array(
				'engine'   => self::ENGINE,
				'value'    => $after !== $settings,
				'settings' => $settings,
			),
			'after'  => array(
				'engine'   => self::ENGINE,
				'value'    => false,
				'settings' => $after,
			),
			'no_op'  => $after === $settings,
			'target' => array(
				'type' => 'option',
				'key'  => self::OPTION,
			),
		);
	}
}
