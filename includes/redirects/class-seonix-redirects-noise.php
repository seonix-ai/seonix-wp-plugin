<?php
/**
 * Tells a real dead page apart from internet background noise.
 *
 * Every public site is scanned around the clock by bots probing for exposed
 * secrets (/.env, /.git/config), dropped shells (/000.php, /shell.php) and
 * vulnerable plugin files (/wp-content/plugins/x/y.php). Those requests 404 —
 * which is exactly the right answer — but they land in the 404 log next to the
 * genuinely broken URLs a human should fix, and to anyone who has never read a
 * raw access log they look like an attack in progress. The Redirects screen
 * uses this classifier to park them in a collapsed "scanner noise" section:
 * still visible, never mixed into the actionable list.
 *
 * Classification happens at render time, not at record time: the pattern list
 * improves with plugin updates and re-classifies everything already logged,
 * and a false positive costs nothing — the row stays visible one disclosure
 * away, with the same Create-redirect and Dismiss actions.
 *
 * @package Seonix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Redirects_Noise {

	/**
	 * File extensions nobody links to but scanners ask for: credential and
	 * config files, database dumps, archives, the backup copies editors and
	 * deploys leave behind, and server-side sources that are not WordPress.
	 * (Asset extensions never reach the log — Seonix_Redirects_Log drops them.)
	 *
	 * 'json' belongs here despite looking legitimate: the constant wave of
	 * /credentials.json, /firebase-service-account.json, /gcp-credentials.json,
	 * /appsettings.json probes is bots hunting leaked cloud keys, and no page a
	 * visitor could mean ends in .json — the WP REST API answers /wp-json/... with
	 * no extension, and a real manifest.json is an asset, never a redirect target.
	 */
	const PROBE_EXTENSIONS = array(
		'sql', 'bak', 'old', 'orig', 'save', 'dist', 'swp', 'log',
		'ini', 'conf', 'cfg', 'yml', 'yaml', 'lock', 'env', 'json',
		'sh', 'bat', 'exe', 'dll',
		'asp', 'aspx', 'jsp', 'cgi',
		'tar', 'gz', 'tgz', 'bz2', 'rar', '7z',
	);

	/** Extensions that get the PHP probe rules below. */
	const PHP_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar' );

	/**
	 * PHP basenames (extension stripped) that are shells and exploit probes,
	 * never pages a site linked to. Deliberately short: the generic rules —
	 * digit-only names, wp-* entry points, anything under /wp-content/ — catch
	 * most probes; this list only names the famous ones seen at the site root.
	 */
	const SHELL_BASENAMES = array(
		'shell', 'wso', 'alfa', 'adminer', 'phpinfo',
		'eval-stdin', 'filemanager', 'file-manager', 'wp-file-manager',
		'bypass', 'gel4y',
	);

	/**
	 * WordPress entry points. When one of these 404s it was requested where it
	 * does not exist (/blog/wp-login.php on a root install) or it has been
	 * disabled (xmlrpc.php) — a probe either way, never a redirect candidate.
	 */
	const WP_ENTRY_BASENAMES = array(
		'wp-login', 'xmlrpc', 'wp-signup', 'wp-activate', 'wp-cron',
		'wp-mail', 'wp-trackback', 'wp-comments-post', 'wp-load',
		'wp-blog-header', 'wp-settings',
	);

	/**
	 * First path segments that only exist on other stacks or in dev checkouts,
	 * requested exclusively by scanners fingerprinting the server. Matched as
	 * whole segments: '/vendor' and '/vendor/x' hit, '/vendors' does not.
	 */
	const PROBE_PREFIXES = array(
		'/vendor', '/node_modules', '/cgi-bin',
		'/phpmyadmin', '/pma', '/autodiscover',
		'/_profiler', '/_ignition', '/actuator',
	);

	/** Exact file names probed at any depth (the wlwmanifest.xml sweep, Exchange). */
	const PROBE_BASENAMES = array( 'wlwmanifest.xml', 'autodiscover.xml' );

	/**
	 * Whether a logged 404 path is scanner/bot noise rather than a page a
	 * visitor could have meant. Expects a path already normalized by
	 * Seonix_Redirects_Log::normalize() — lower-case, decoded, no query
	 * string, no trailing slash.
	 *
	 * @param string $path Normalized dead path, e.g. "/.env".
	 */
	public static function is_noise( string $path ): bool {
		$noise = self::classify( $path );

		/**
		 * Overrule the classifier for one path — keep /backup.old visible as a
		 * real 404, or park a probe pattern Seonix does not know yet.
		 *
		 * @param bool   $noise Whether Seonix considers the path bot noise.
		 * @param string $path  The normalized dead path.
		 */
		return (bool) apply_filters( 'seonix_404_is_noise', $noise, $path );
	}

	/**
	 * Split logged rows into the real list and the noise list, order kept.
	 *
	 * @param array<int,array<string,mixed>> $entries Rows from Seonix_Redirects_Log::get_top().
	 * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>} [real, noise]
	 */
	public static function split( array $entries ): array {
		$real  = array();
		$noise = array();
		foreach ( $entries as $entry ) {
			if ( self::is_noise( (string) ( $entry['path'] ?? '' ) ) ) {
				$noise[] = $entry;
			} else {
				$real[] = $entry;
			}
		}
		return array( $real, $noise );
	}

	/** The rules themselves, unfiltered. */
	private static function classify( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}

		// Hidden files and directories: /.env, /.git/config, /.aws/credentials,
		// /.well-known/traffic-advice. No page URL has a dot-segment; these are
		// bots hunting exposed files (and Chrome's prefetch proxy checking
		// /.well-known/ — legitimate, but still not a page to redirect).
		foreach ( explode( '/', ltrim( $path, '/' ) ) as $segment ) {
			if ( '' !== $segment && '.' === $segment[0] ) {
				return true;
			}
		}

		if ( in_array( basename( $path ), self::PROBE_BASENAMES, true ) ) {
			return true;
		}

		foreach ( self::PROBE_PREFIXES as $prefix ) {
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return true;
			}
		}

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && in_array( $ext, self::PROBE_EXTENSIONS, true ) ) {
			return true;
		}
		if ( in_array( $ext, self::PHP_EXTENSIONS, true ) ) {
			return self::is_php_probe( $path, strtolower( (string) pathinfo( $path, PATHINFO_FILENAME ) ) );
		}

		return false;
	}

	/**
	 * A .php URL that 404s is almost always a probe — but not always: a site
	 * migrated off a hand-written PHP stack has real legacy URLs (/about.php,
	 * /kontakt.php) that deserve redirects. So: word-like names at word-like
	 * places stay real, and everything scanners actually ask for is parked.
	 *
	 * @param string $path Normalized full path.
	 * @param string $name Basename without extension, lower-case.
	 */
	private static function is_php_probe( string $path, string $name ): bool {
		// Real permalinks never live under the WP system directories; a 404ing
		// .php file there is a scanner testing plugin and theme exploits.
		foreach ( array( '/wp-content/', '/wp-includes/', '/wp-admin/' ) as $dir ) {
			if ( false !== strpos( $path, $dir ) ) {
				return true;
			}
		}
		if ( in_array( $name, self::WP_ENTRY_BASENAMES, true ) ) {
			return true;
		}
		// wp-config.php and the copies editors leave next to it (wp-config2.php,
		// wp-config-sample.php; wp-config.php.bak is caught by extension).
		if ( 0 === strpos( $name, 'wp-config' ) ) {
			return true;
		}
		// Dropped shells hide behind meaningless names: digits (/000.php), one
		// or two characters (/x.php, /up.php, /ws.php). Real legacy URLs are
		// words and pass through.
		if ( preg_match( '/^[0-9]+$/', $name ) || preg_match( '/^[a-z0-9_\-]{1,2}$/', $name ) ) {
			return true;
		}
		return in_array( $name, self::SHELL_BASENAMES, true );
	}
}
