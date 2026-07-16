<?php
namespace Seonix\Tests\Unit\Redirects;

use PHPUnit\Framework\TestCase;
use Seonix_Redirects_Log;

/**
 * The 404 log's path normalization — what it records and, more importantly,
 * what it refuses to. Aggregation, storage bounds and the whole feature hang
 * off this: it decides which raw request URIs become one logged path and which
 * are dropped as noise (assets, the front page, scanner probes).
 */
final class LogTest extends TestCase {

	/**
	 * @dataProvider normalizeCases
	 */
	public function test_normalize( string $raw, string $expected, string $why ): void {
		$this->assertSame( $expected, Seonix_Redirects_Log::normalize( $raw ), $why );
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public function normalizeCases(): array {
		return array(
			'plain path'            => array( '/alte-seite', '/alte-seite', 'a normal dead page is logged as-is' ),
			'query stripped'        => array( '/alte-seite?utm=x&y=1', '/alte-seite', 'the same dead page regardless of tracking params' ),
			'trailing slash folded' => array( '/alte-seite/', '/alte-seite', '/x and /x/ are one dead page, one row' ),
			'lowercased'            => array( '/Alte-Seite', '/alte-seite', 'case folded so it aggregates' ),
			'decoded'               => array( '/%C3%BCber-uns', '/über-uns', 'percent-encoding decoded before storing' ),
			'front page dropped'    => array( '/', '', 'the home page is never a 404' ),
			'empty dropped'         => array( '', '', 'nothing to log' ),
			'css asset dropped'     => array( '/wp-content/x.css', '', 'a missing stylesheet is not a page redirect' ),
			'js asset dropped'      => array( '/app.js?ver=2', '', 'a missing script is not a page redirect' ),
			'image asset dropped'   => array( '/uploads/pic.PNG', '', 'asset extensions dropped case-insensitively' ),
			'font dropped'          => array( '/fonts/x.woff2', '', 'font 404s are noise' ),
			'html page kept'        => array( '/guide.html', '/guide.html', '.html is a page, not an asset' ),
			'overlong dropped'      => array( '/' . str_repeat( 'a', 200 ), '', 'a 200-char path is a scanner probe, and would overflow the index' ),
		);
	}
}
