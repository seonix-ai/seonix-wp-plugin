<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Robots_Txt_Fix;
use WP_Error;

/** The fix with its network and disk replaced by plain values. */
final class RobotsTxtFixUnderTest extends Seonix_Robots_Txt_Fix {

	/** @var string|null What the site serves; null means it could not be fetched. */
	public $live = null;

	/** Serve what WordPress and the disk produce, like a site with nothing in front. */
	public bool $live_follows_site = true;

	/** @var string|null robots.txt in the site root. */
	public $file = null;

	public bool $writable = true;

	/** @var string[] Everything written to the file, in order. */
	public array $writes = array();

	/** Pretend the disk ignores writes. */
	public bool $writes_are_lost = false;

	protected function fetch_live(): ?string {
		if ( ! $this->live_follows_site ) {
			return $this->live;
		}
		return $this->file ?? $this->wp_output_for_test();
	}

	private function wp_output_for_test(): string {
		$m = new \ReflectionMethod( Seonix_Robots_Txt_Fix::class, 'wp_output' );
		$m->setAccessible( true );
		return (string) $m->invoke( $this, (bool) get_option( 'blog_public', '1' ) );
	}

	protected function file_path(): string {
		return '/site/robots.txt';
	}

	protected function read_file(): ?string {
		return $this->file;
	}

	protected function file_is_writable(): bool {
		return $this->writable;
	}

	protected function write_file( string $contents ): bool {
		$this->writes[] = $contents;
		if ( ! $this->writes_are_lost ) {
			$this->file = $contents;
		}
		return true;
	}
}

/**
 * The robots.txt fix decides whether a site can be found at all, in either
 * direction, so these pin the cases where it must refuse as hard as the ones
 * where it must work.
 */
final class RobotsTxtFixTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	private string $environment = 'production';

	private string $home = 'https://site.test';

	private bool $multisite = false;

	/** @var callable|null robots_txt filter other plugins would add. */
	private $filter = null;

	private int $purges = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array( 'blog_public' => '1' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'home_url' )->alias( fn() => $this->home );
		Functions\when( 'site_url' )->alias( fn() => $this->home );
		Functions\when( 'wp_get_environment_type' )->alias( fn() => $this->environment );
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, ...$args ) {
				return ( 'robots_txt' === $tag && $this->filter ) ? ( $this->filter )( $value, ...$args ) : $value;
			}
		);
		Functions\when( 'wp_cache_flush' )->alias(
			function () {
				++$this->purges;
				return true;
			}
		);
		Functions\when( 'rocket_clean_domain' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Reading the file ─────────────────────────────────────────────────

	/** @dataProvider robotsFiles */
	public function test_blocks_site( string $text, bool $expected ): void {
		$this->assertSame( $expected, Seonix_Robots_Txt_Fix::blocks_site( $text ) );
	}

	public function robotsFiles(): array {
		return array(
			'all crawlers blocked'          => array( "User-agent: *\nDisallow: /\n", true ),
			'wildcard form'                 => array( "User-agent: *\nDisallow: /*\n", true ),
			'case and spacing'              => array( "USER-AGENT:*\n  disallow :  /  \n", true ),
			'trailing comment'              => array( "User-agent: *\nDisallow: / # staging\n", true ),
			'windows line endings'          => array( "User-agent: *\r\nDisallow: /\r\n", true ),
			'byte order mark'               => array( "\xEF\xBB\xBFUser-agent: *\nDisallow: /\n", true ),
			'shared group'                  => array( "User-agent: Bingbot\nUser-agent: *\nDisallow: /\n", true ),
			'googlebot own group blocked'   => array( "User-agent: *\nAllow: /\n\nUser-agent: Googlebot\nDisallow: /\n", true ),
			'googlebot own group is open'   => array( "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow:\n", false ),
			'explicit allow wins the tie'   => array( "User-agent: *\nDisallow: /\nAllow: /\n", false ),
			'longer disallow beats allow'   => array( "User-agent: *\nDisallow: /*\nAllow: /\n", true ),
			'only the admin blocked'        => array( "User-agent: *\nDisallow: /wp-admin/\n", false ),
			'empty disallow allows all'     => array( "User-agent: *\nDisallow:\n", false ),
			'commented out rule'            => array( "User-agent: *\n# Disallow: /\n", false ),
			'only an AI crawler blocked'    => array( "User-agent: GPTBot\nDisallow: /\n", false ),
			'empty file'                    => array( '', false ),
		);
	}

	public function test_unblock_changes_only_the_blocking_lines(): void {
		$before = "# Our rules\r\nUser-agent: GPTBot\r\nDisallow: /\r\n\r\nUser-agent: *\r\nDisallow: / # staging\r\nDisallow: /private/\r\n\r\nUser-agent: Googlebot\r\nDisallow: /*\r\n\r\nSitemap: https://site.test/sitemap.xml\r\n";
		$after  = "# Our rules\r\nUser-agent: GPTBot\r\nDisallow: /\r\n\r\nUser-agent: *\r\nDisallow: # staging\r\nDisallow: /private/\r\n\r\nUser-agent: Googlebot\r\nDisallow:\r\n\r\nSitemap: https://site.test/sitemap.xml\r\n";

		$this->assertSame( $after, Seonix_Robots_Txt_Fix::unblock( $before ) );
		$this->assertFalse( Seonix_Robots_Txt_Fix::blocks_site( $after ) );
	}

	// ── Cause: a plugin that blocks while the site is discouraged ────────

	/** The site state that reaches the setting path: filter blocks only when not public. */
	private function discouragedSiteWithReactingPlugin(): void {
		$this->options['blog_public'] = '0';
		$this->filter                 = static function ( $output, $public ) {
			return $public ? $output : "User-agent: *\nDisallow: /\n";
		};
	}

	public function test_core_alone_never_blocks_for_the_discourage_box(): void {
		$this->options['blog_public'] = '0';

		$r = ( new RobotsTxtFixUnderTest() )->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_CLEAR, $r['state'] );
		$this->assertSame( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", $r['current'] );
		$this->assertTrue( $r['discouraged'], 'the noindex still keeps this site out of search, so the owner must hear it' );
	}

	public function test_discourage_setting_is_offered_with_the_exact_result(): void {
		$this->discouragedSiteWithReactingPlugin();

		$r = ( new RobotsTxtFixUnderTest() )->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_FIXABLE, $r['state'] );
		$this->assertSame( Seonix_Robots_Txt_Fix::SOURCE_SETTING, $r['source'] );
		$this->assertSame( "User-agent: *\nDisallow: /\n", $r['current'] );
		$this->assertSame( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", $r['proposed'] );
		$this->assertNotSame( '', $r['token'] );
	}

	public function test_apply_flips_the_setting_and_undo_restores_it(): void {
		$this->discouragedSiteWithReactingPlugin();
		$fix = new RobotsTxtFixUnderTest();
		$out = $fix->apply( $fix->inspect()['token'] );

		$this->assertIsArray( $out );
		$this->assertSame( '1', $this->options['blog_public'] );
		$this->assertTrue( $out['verified'] );
		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_CLEAR, $out['inspection']['state'] );
		$this->assertTrue( $out['inspection']['can_undo'] );
		$this->assertSame( 1, $this->purges, 'a cached robots.txt must not outlive the change' );

		$undone = $fix->undo();
		$this->assertIsArray( $undone );
		$this->assertSame( '0', $this->options['blog_public'] );
		$this->assertArrayNotHasKey( Seonix_Robots_Txt_Fix::OPTION_SNAPSHOT, $this->options );
	}

	public function test_apply_reports_when_the_live_file_still_blocks(): void {
		$this->discouragedSiteWithReactingPlugin();
		$fix                    = new RobotsTxtFixUnderTest();
		$token                  = $fix->inspect()['token'];
		$fix->live_follows_site = false;
		$fix->live              = "User-agent: *\nDisallow: /\n";

		$out = $fix->apply( $token );

		$this->assertFalse( is_a( $out, WP_Error::class ) );
		$this->assertFalse( $out['verified'], 'saved, but never reported as working while crawlers are still blocked' );
	}

	public function test_a_site_that_cannot_fetch_itself_is_still_fixable_but_unverified(): void {
		$this->discouragedSiteWithReactingPlugin();
		$fix                    = new RobotsTxtFixUnderTest();
		$fix->live_follows_site = false;
		$fix->live              = null;

		$r = $fix->inspect();
		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_FIXABLE, $r['state'] );
		$this->assertFalse( $r['live_checked'] );

		$out = $fix->apply( $r['token'] );
		$this->assertNull( $out['verified'], 'no claim either way without a look at the live file' );
	}

	// ── Cause: a file on disk ────────────────────────────────────────────

	public function test_file_is_offered_with_its_own_content_kept(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\nCrawl-delay: 5\n";

		$r = $fix->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_FIXABLE, $r['state'] );
		$this->assertSame( Seonix_Robots_Txt_Fix::SOURCE_FILE, $r['source'] );
		$this->assertSame( "User-agent: *\nDisallow:\nCrawl-delay: 5\n", $r['proposed'] );
	}

	public function test_apply_edits_the_file_and_undo_restores_the_bytes(): void {
		$original                     = "# keep\r\nUser-agent: *\r\nDisallow: /\r\n";
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = $original;

		$out = $fix->apply( $fix->inspect()['token'] );
		$this->assertTrue( $out['verified'] );
		$this->assertSame( "# keep\r\nUser-agent: *\r\nDisallow:\r\n", $fix->file );

		$this->assertIsArray( $fix->undo() );
		$this->assertSame( $original, $fix->file );
	}

	public function test_undo_never_overwrites_a_file_edited_after_the_fix(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n";
		$fix->apply( $fix->inspect()['token'] );

		$fix->file = "User-agent: *\nDisallow: /secret/\n";
		$out       = $fix->undo();

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'changed_since', $out->get_error_code() );
		$this->assertSame( "User-agent: *\nDisallow: /secret/\n", $fix->file );
	}

	public function test_a_write_that_does_not_stick_is_rolled_back_and_reported(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n";
		$token                        = $fix->inspect()['token'];
		$fix->writes_are_lost         = true;

		$out = $fix->apply( $token );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertArrayNotHasKey( Seonix_Robots_Txt_Fix::OPTION_SNAPSHOT, $this->options );
		$this->assertSame( "User-agent: *\nDisallow: /\n", end( $fix->writes ), 'the original goes back on disk' );
		$this->assertCount( 2, $fix->writes );
	}

	public function test_an_unwritable_file_shows_the_text_but_offers_no_button(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n";
		$fix->writable                = false;

		$r = $fix->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_MANUAL, $r['state'] );
		$this->assertSame( 'file_not_writable', $r['reason'] );
		$this->assertSame( "User-agent: *\nDisallow:\n", $r['proposed'], 'the owner can paste it by hand' );
		$this->assertSame( '', $r['token'] );
	}

	// ── Where it must refuse ─────────────────────────────────────────────

	/** "Close everything except these" is deliberate: unblocking would open it all. */
	public function test_a_block_with_allow_exceptions_gets_no_button(): void {
		$fix       = new RobotsTxtFixUnderTest();
		$fix->file = "User-agent: *\nDisallow: /\nAllow: /public/\n";

		$r = $fix->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_MANUAL, $r['state'] );
		$this->assertSame( 'allow_exceptions', $r['reason'] );
		$this->assertSame( '', $r['token'] );
	}

	public function test_a_token_for_an_older_state_is_refused(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n";
		$token                        = $fix->inspect()['token'];

		$fix->file = "User-agent: *\nDisallow: /\nDisallow: /new/\n";
		$out       = $fix->apply( $token );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'stale_review', $out->get_error_code() );
		$this->assertSame( array(), $fix->writes );
	}

	public function test_a_block_that_is_gone_changes_nothing(): void {
		$fix                          = new RobotsTxtFixUnderTest();

		$r = $fix->inspect();
		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_CLEAR, $r['state'] );

		$out = $fix->apply( 'anything' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'not_fixable', $out->get_error_code() );
	}

	public function test_staging_is_left_blocked(): void {
		$this->environment = 'staging';
		$fix               = new RobotsTxtFixUnderTest();
		$fix->file         = "User-agent: *\nDisallow: /\n";
		$r                 = $fix->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_MANUAL, $r['state'] );
		$this->assertSame( 'non_production', $r['reason'] );
	}

	public function test_a_site_in_a_subfolder_is_left_alone(): void {
		$this->home = 'https://site.test/shop';
		$fix        = new RobotsTxtFixUnderTest();
		$fix->file  = "User-agent: *\nDisallow: /\n";
		$r          = $fix->inspect();

		$this->assertSame( 'not_at_root', $r['reason'] );
		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_MANUAL, $r['state'] );
	}

	public function test_a_cdn_answering_for_the_site_gets_no_button(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->live_follows_site       = false;
		$fix->live                    = "User-agent: *\nDisallow: /\n";

		$r = $fix->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_MANUAL, $r['state'] );
		$this->assertSame( Seonix_Robots_Txt_Fix::SOURCE_REMOTE, $r['source'] );
		$this->assertSame( "User-agent: *\nDisallow: /\n", $r['current'], 'the owner still sees what crawlers get' );
	}

	public function test_a_file_shadowed_by_a_cdn_is_not_edited(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n";
		$fix->live_follows_site       = false;
		$fix->live                    = "User-agent: *\nDisallow: /\n\nUser-agent: Foo\nDisallow: /x/\n";

		$this->assertSame( 'upstream', $fix->inspect()['reason'] );
	}

	public function test_another_plugins_block_gets_no_button(): void {
		$this->filter                 = static function () {
			return "User-agent: *\nDisallow: /\n";
		};

		$r = ( new RobotsTxtFixUnderTest() )->inspect();

		$this->assertSame( Seonix_Robots_Txt_Fix::STATE_MANUAL, $r['state'] );
		$this->assertSame( Seonix_Robots_Txt_Fix::SOURCE_FILTER, $r['source'] );
	}

	public function test_the_shared_file_of_a_network_is_not_edited(): void {
		$this->multisite              = true;
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n";

		$this->assertSame( 'multisite_file', $fix->inspect()['reason'] );
	}

	public function test_a_file_that_is_not_utf8_is_not_edited(): void {
		$fix                          = new RobotsTxtFixUnderTest();
		$fix->file                    = "User-agent: *\nDisallow: /\n# caf\xE9\n";

		$this->assertSame( 'file_unusable', $fix->inspect()['reason'] );
	}

	public function test_every_reason_has_copy(): void {
		$reasons = array( 'not_blocked', 'setting', 'file', 'file_not_writable', 'allow_exceptions', 'file_unusable', 'plugin_filter', 'upstream', 'non_production', 'not_at_root', 'multisite_file' );
		foreach ( $reasons as $reason ) {
			$copy = Seonix_Robots_Txt_Fix::describe( array( 'reason' => $reason ), 'robots_blocks_all' );
			$this->assertNotSame( '', $copy['headline'], $reason );
			$this->assertNotSame( '', $copy['detail'], $reason );
		}
	}
}
