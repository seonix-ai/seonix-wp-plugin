<?php
namespace Seonix\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the shipped assets against stray control bytes.
 *
 * A single NUL that replaced a space inside a string literal survived code
 * review, a passing test suite and a working browser E2E: the code ran fine,
 * because the byte sat in an opaque separator. What it did break was the
 * tooling around it — `file` reported the JS as binary data and grep silently
 * matched nothing in it, which is exactly the kind of thing that surfaces as
 * an unexplained Plugin Check failure or an SVN mangling the file on the way
 * to the wordpress.org catalog.
 *
 * These are assets, so a byte-level assertion is the honest test: there is no
 * behaviour to exercise, only a file that must be plain text.
 */
final class AssetIntegrityTest extends TestCase {

	/**
	 * Every asset we ship and author by hand.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function assetProvider(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			glob( $root . '/assets/*.js' ) ?: array(),
			glob( $root . '/assets/*.css' ) ?: array()
		);

		$cases = array();
		foreach ( $files as $file ) {
			$cases[ basename( $file ) ] = array( $file );
		}
		return $cases;
	}

	/**
	 * @dataProvider assetProvider
	 * @param string $file Absolute path to the asset.
	 */
	public function test_asset_has_no_control_bytes( string $file ): void {
		$bytes = (string) file_get_contents( $file );

		// Tab, LF and CR are the only control characters legitimate source
		// carries. Anything else (NUL, unit separator, vertical tab, …) means a
		// literal control byte reached a string literal instead of an escape.
		$offenders = array();
		$length    = strlen( $bytes );
		for ( $i = 0; $i < $length; $i++ ) {
			$ord = ord( $bytes[ $i ] );
			if ( $ord < 0x20 && ! in_array( $ord, array( 0x09, 0x0A, 0x0D ), true ) ) {
				$line        = substr_count( $bytes, "\n", 0, $i ) + 1;
				$offenders[] = sprintf( '0x%02X at line %d', $ord, $line );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			basename( $file ) . ' contains literal control bytes — write them as escapes ("") instead: '
				. implode( ', ', $offenders )
		);
	}

	/**
	 * @dataProvider assetProvider
	 * @param string $file Absolute path to the asset.
	 */
	public function test_asset_is_valid_utf8( string $file ): void {
		$bytes = (string) file_get_contents( $file );

		$this->assertTrue(
			mb_check_encoding( $bytes, 'UTF-8' ),
			basename( $file ) . ' is not valid UTF-8'
		);
	}
}
