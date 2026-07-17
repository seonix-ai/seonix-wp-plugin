<?php
namespace Seonix\Tests\Unit\Redirects;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Noise;

/**
 * The line between a dead page worth fixing and internet background noise.
 *
 * Everything here rides on one asymmetry: a probe misclassified as real
 * clutters the actionable list and scares the site owner, while a real URL
 * misclassified as noise merely sits one disclosure lower with the same
 * actions. So the classifier may be moderately aggressive about known probe
 * shapes — but must never swallow the URLs that make the log valuable, above
 * all a migrated site's legacy .php pages.
 */
final class NoiseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider noiseCases
	 */
	public function test_is_noise( string $path, bool $expected, string $why ): void {
		$this->assertSame( $expected, Seonix_Redirects_Noise::is_noise( $path ), $why );
	}

	/** @return array<string,array{0:string,1:bool,2:string}> */
	public function noiseCases(): array {
		return array(
			// ── Real dead pages: the reason the log exists. Never park these.
			'plain dead page'          => array( '/alte-seite', false, 'a normal dead page is the actionable case' ),
			'nested dead page'         => array( '/blog/2021/old-post', false, 'depth does not make a page a probe' ),
			'unicode dead page'        => array( '/über-uns', false, 'decoded unicode paths are pages' ),
			'html page'                => array( '/guide.html', false, '.html is a page' ),
			'legacy php page'          => array( '/about.php', false, 'a site migrated off hand-written PHP has real .php URLs to redirect' ),
			'legacy php page, longer'  => array( '/kontakt.php', false, 'word-like legacy names stay actionable' ),
			'legacy php page, nested'  => array( '/de/produkte.php', false, 'legacy PHP URLs can be nested too' ),
			'hyphenless page'          => array( '/pricing', false, 'short word paths are pages' ),
			'prefix lookalike'         => array( '/vendors/list', false, 'segment matching: /vendors is not /vendor' ),
			'pma lookalike'            => array( '/pmalinks', false, 'segment matching: /pmalinks is not /pma' ),

			// ── Dotfile and hidden-path probes.
			'env file'                 => array( '/.env', true, 'the classic credentials probe' ),
			'env variant'              => array( '/.env.bak', true, 'suffixed .env probes too' ),
			'git config'               => array( '/.git/config', true, 'repo hunting' ),
			'nested dotfile'           => array( '/api/.env', true, 'dot-segments at any depth are probes' ),
			'well-known'               => array( '/.well-known/traffic-advice', true, 'Chrome prefetch-proxy check — legitimate, but never a redirect' ),
			'aws credentials'          => array( '/.aws/credentials', true, 'cloud key hunting' ),

			// ── Config / dump / backup extensions.
			'sql dump'                 => array( '/backup.sql', true, 'database dump hunting' ),
			'tarball'                  => array( '/site.tar.gz', true, 'site archive hunting' ),
			'debug log'                => array( '/wp-content/debug.log', true, 'the WP debug.log probe' ),
			'config yml'               => array( '/config.yml', true, 'config file hunting' ),
			'backup copy'              => array( '/index.php.bak', true, 'editor droppings' ),

			// ── PHP probes.
			'numeric shell'            => array( '/000.php', true, 'digit-named shells' ),
			'single char shell'        => array( '/x.php', true, 'one-letter shells' ),
			'two char shell'           => array( '/up.php', true, 'two-letter shells (the wp-content/plugins/fix/up.php family)' ),
			'plugin file probe'        => array( '/wp-content/plugins/fix/up.php', true, 'no real permalink lives under /wp-content/' ),
			'theme file probe'         => array( '/wp-content/themes/x/header.php', true, 'theme exploit probing' ),
			'includes probe'           => array( '/wp-includes/wlwmanifest.xml', true, 'system dir + the wlwmanifest sweep' ),
			'wp-login at subpath'      => array( '/blog/wp-login.php', true, 'entry point probed where it does not exist' ),
			'xmlrpc'                   => array( '/xmlrpc.php', true, 'disabled xmlrpc is a probe magnet, not a page' ),
			'wp-config copy'           => array( '/wp-config-sample.php', true, 'wp-config and its copies' ),
			'named shell'              => array( '/shell.php', true, 'famous shell names' ),
			'adminer'                  => array( '/adminer.php', true, 'dropped DB consoles' ),

			// ── Foreign-stack fingerprinting.
			'phpunit rce'              => array( '/vendor/phpunit/phpunit/src/util/php/eval-stdin.php', true, 'the phpunit eval-stdin sweep' ),
			'cgi-bin'                  => array( '/cgi-bin/test', true, 'CGI probing' ),
			'phpmyadmin'               => array( '/phpmyadmin', true, 'DB console hunting, bare' ),
			'phpmyadmin nested'        => array( '/phpmyadmin/index', true, 'DB console hunting, nested' ),
			'wlwmanifest sweep'        => array( '/blog/wp-includes/wlwmanifest.xml', true, 'the classic multi-prefix wlwmanifest sweep' ),
			'ignition rce'             => array( '/_ignition/execute-solution', true, 'Laravel ignition RCE probe' ),
		);
	}

	/** The filter can overrule the verdict in both directions. */
	public function test_filter_overrules(): void {
		Monkey\Filters\expectApplied( 'seonix_404_is_noise' )
			->andReturnUsing( static function ( $noise, $path ) {
				return '/backup.sql' === $path ? false : $noise;
			} );

		$this->assertFalse( Seonix_Redirects_Noise::is_noise( '/backup.sql' ), 'a site can keep a parked path visible' );
		$this->assertTrue( Seonix_Redirects_Noise::is_noise( '/.env' ), 'other verdicts stay untouched' );
	}

	/** split() keeps order and loses nothing. */
	public function test_split_partitions_rows(): void {
		$rows = array(
			array( 'id' => 1, 'path' => '/.env' ),
			array( 'id' => 2, 'path' => '/alte-seite' ),
			array( 'id' => 3, 'path' => '/000.php' ),
			array( 'id' => 4, 'path' => '/guide.html' ),
		);

		list( $real, $noise ) = Seonix_Redirects_Noise::split( $rows );

		$this->assertSame( array( 2, 4 ), array_column( $real, 'id' ), 'real rows, original order' );
		$this->assertSame( array( 1, 3 ), array_column( $noise, 'id' ), 'noise rows, original order' );
	}
}
