<?php
/**
 * Every Seonix_* class the plugin calls at runtime must actually be require_once'd
 * by seonix.php.
 *
 * This exists because a release once shipped class-seonix-content-score.php inside
 * the zip while seonix.php had no include for it: the file was present, the unit
 * tests (which load classes directly) were green, Plugin Check was clean — and the
 * editor panel fataled on its first /score call, because
 * Seonix_REST_API::score() calls Seonix_Content_Score::score() statically.
 *
 * Nothing else in the suite can catch that class of bug: the tests never boot
 * seonix.php, so a missing include is invisible to them.
 */

namespace Seonix\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BootstrapRequiresTest extends TestCase {

	/** Absolute path to the plugin root. */
	private static function root(): string {
		return dirname( dirname( __DIR__ ) );
	}

	/** seonix.php source. */
	private static function bootstrap(): string {
		return (string) file_get_contents( self::root() . '/seonix.php' );
	}

	/**
	 * Class names referenced statically (Foo::bar) or via `new Foo` anywhere in
	 * includes/, mapped to the file that defines them.
	 *
	 * @return array<string,string> class name => defining file, relative to root
	 */
	private static function definedClasses(): array {
		$map = array();
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$src = (string) file_get_contents( $file->getPathname() );
			if ( preg_match( '/^\s*(?:final\s+)?class\s+(Seonix_[A-Za-z0-9_]+)/m', $src, $m ) ) {
				$map[ $m[1] ] = ltrim( str_replace( self::root(), '', $file->getPathname() ), '/' );
			}
		}
		return $map;
	}

	/**
	 * A class is reachable if seonix.php includes its file directly, or includes
	 * some file that (transitively) includes it. One hop is enough for the shapes
	 * this plugin uses; seo-fix methods are pulled in by their own controller.
	 */
	private static function isRequired( string $relPath, string $bootstrap ): bool {
		if ( false !== strpos( $bootstrap, $relPath ) ) {
			return true;
		}
		// Second hop: some other included file requires it.
		foreach ( glob( self::root() . '/includes/**/*.php' ) ?: array() as $candidate ) {
			$rel = ltrim( str_replace( self::root(), '', $candidate ), '/' );
			if ( false === strpos( $bootstrap, $rel ) ) {
				continue;
			}
			if ( false !== strpos( (string) file_get_contents( $candidate ), basename( $relPath ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The regression: a class used by another class, shipped in the zip, but never
	 * included. Seonix_Content_Score is the one that actually broke 2.8.0.
	 */
	public function test_every_class_used_by_the_rest_api_is_required_by_the_bootstrap(): void {
		$bootstrap = self::bootstrap();
		$defined   = self::definedClasses();
		$restSrc   = (string) file_get_contents( self::root() . '/includes/class-seonix-rest-api.php' );

		$missing = array();
		foreach ( $defined as $class => $relPath ) {
			// Only classes the REST API actually reaches for.
			if ( ! preg_match( '/\b' . preg_quote( $class, '/' ) . '\s*::/', $restSrc )
				&& ! preg_match( '/new\s+' . preg_quote( $class, '/' ) . '\b/', $restSrc ) ) {
				continue;
			}
			if ( ! self::isRequired( $relPath, $bootstrap ) ) {
				$missing[] = $class . ' (' . $relPath . ')';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"class-seonix-rest-api.php calls these classes, but seonix.php never require_once's them — "
				. "they will fatal at runtime even though the file ships in the zip:\n  "
				. implode( "\n  ", $missing )
		);
	}

	/**
	 * The same hole, one level up: seonix.php itself does `new Seonix_Foo()` in
	 * seonix_init(). A missing include there is worse than the REST case — it
	 * fatals on plugins_loaded, i.e. on every request, admin and front end
	 * alike. The REST-API check above cannot see it, because the class is never
	 * named in class-seonix-rest-api.php.
	 */
	public function test_every_class_the_bootstrap_instantiates_is_required_by_it(): void {
		$bootstrap = self::bootstrap();
		$defined   = self::definedClasses();

		$missing = array();
		foreach ( $defined as $class => $relPath ) {
			$usedHere = preg_match( '/new\s+' . preg_quote( $class, '/' ) . '\s*\(/', $bootstrap )
				|| preg_match( '/\b' . preg_quote( $class, '/' ) . '\s*::/', $bootstrap );
			if ( ! $usedHere ) {
				continue;
			}
			if ( ! self::isRequired( $relPath, $bootstrap ) ) {
				$missing[] = $class . ' (' . $relPath . ')';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"seonix.php uses these classes but never require_once's them — the plugin fatals on plugins_loaded:\n  "
				. implode( "\n  ", $missing )
		);
	}

	/** The specific include whose absence shipped a broken 2.8.0 to the directory. */
	public function test_content_score_is_wired_into_the_bootstrap(): void {
		$this->assertStringContainsString(
			'includes/class-seonix-content-score.php',
			self::bootstrap(),
			'seonix.php must require class-seonix-content-score.php: the /score route calls it statically.'
		);
	}

	/**
	 * Every $var->create_table() inside seonix_init() must be assigned before it
	 * is called. A create_table() on a variable defined LATER in the function is
	 * an undefined-variable fatal on the FIRST load after an update (the DB-upgrade
	 * block runs before the redirect wiring). That exact ordering shipped once and
	 * took a live site down: $redirects_log->create_table() ran above where
	 * $redirects_log was created.
	 */
	public function test_create_table_calls_are_assigned_before_use(): void {
		$src = self::bootstrap();
		$start = strpos( $src, 'function seonix_init()' );
		$this->assertNotFalse( $start, 'seonix_init() not found' );
		// Body runs until the next top-level function declaration.
		$end = strpos( $src, "\nfunction ", $start + 1 );
		$body = false === $end ? substr( $src, $start ) : substr( $src, $start, $end - $start );

		preg_match_all( '/\$(\w+)->create_table\(\)/', $body, $calls, PREG_OFFSET_CAPTURE );
		$this->assertNotEmpty( $calls[1], 'expected create_table() calls in seonix_init()' );

		foreach ( $calls[1] as $i => $m ) {
			$var        = $m[0];
			$callOffset = $calls[0][ $i ][1];
			$assigned   = preg_match( '/\$' . preg_quote( $var, '/' ) . '\s*=\s*new\b/', substr( $body, 0, $callOffset ) );
			$this->assertSame(
				1,
				$assigned,
				'$' . $var . '->create_table() is called before $' . $var . ' is assigned — undefined-variable fatal on the first load after an update'
			);
		}
	}

	/** It must be included before the REST API that calls it. */
	public function test_content_score_is_required_before_the_rest_api(): void {
		$bootstrap = self::bootstrap();
		$score     = strpos( $bootstrap, 'includes/class-seonix-content-score.php' );
		$rest      = strpos( $bootstrap, 'includes/class-seonix-rest-api.php' );

		$this->assertNotFalse( $score, 'content-score include missing entirely.' );
		$this->assertNotFalse( $rest, 'rest-api include missing entirely.' );
		$this->assertLessThan( $rest, $score, 'content-score must be required before rest-api.' );
	}
}
