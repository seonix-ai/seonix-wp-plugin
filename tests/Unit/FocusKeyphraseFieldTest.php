<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Seonix_Meta_Bridge;
use Seonix_Meta_Watcher;
use Seonix_Metabox;
use Seonix_SEO_Engine;
use Seonix_Sync;
use Seonix_Tasks;

/**
 * Covers Seonix's own focus keyphrase field — the one an author gets when no
 * SEO plugin on the site offers one, so the panel's keyphrase checks stop being
 * a dead end ("keyphrase checks are skipped", with nowhere to set a keyphrase).
 *
 * The three properties that matter:
 *   • it NEVER appears next to an engine's own field (Yoast / Rank Math /
 *     SEOPress), because two inputs over one value silently drift apart — and it
 *     DOES appear when nothing owns the keyphrase in postmeta we can read
 *     (no SEO plugin at all, or AIOSEO / TSF / Squirrly);
 *   • whatever the author types fans out through the bridge to the active
 *     engines, from the classic form AND from the block editor's REST meta
 *     write, which never touches the bridge on its own;
 *   • that fan-out cannot loop — the reverse-sync watcher must read our write
 *     as ours, not as the site owner editing their SEO plugin.
 *
 * Note on the environment: FakeYoast defines WPSEO_Options in the bootstrap, so
 * detect_all() reports Yoast for the whole suite and cannot be mocked away
 * (class_exists is not stubbable). That is why has_native_focus_kw_ui() takes
 * the engine list as an argument — the pass-in-or-detect shape post_focus_kw_key()
 * already uses — and why the "no engine" render case is driven through the
 * renderer's own payload rather than through detection.
 */
final class FocusKeyphraseFieldTest extends TestCase {

	/** @var Seonix_Metabox */
	private $metabox;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( '__' )->returnArg();
		// audit_data() reads home_url() for the links section's home host.
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		// Default for the tests that reach sanitize_value() without caring about
		// sanitising. Mirrors core's strip-then-collapse; individual tests below
		// override it with returnArg() where the assertion is about pass-through.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				$value = strip_tags( (string) $value );
				$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );
				return trim( $value );
			}
		);

		// Seonix_Tasks reads $GLOBALS['wpdb'] in its constructor and talks to the
		// tasks table; the field under test never consults it.
		$this->metabox = new Seonix_Metabox( Mockery::mock( Seonix_Tasks::class ) );

		// Seonix_Meta_Watcher::$queued is a private static: a post left in it by
		// an earlier test would be deduped out of the queue here, quietly turning
		// the loop tests below into assertions about nothing.
		$queued = new \ReflectionProperty( Seonix_Meta_Watcher::class, 'queued' );
		$queued->setAccessible( true );
		$queued->setValue( null, array() );
	}

	protected function tearDown(): void {
		Seonix_Meta_Bridge::$writing = false;
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// ─── (a) Never duplicate an engine's own field ───────────────────────

	/**
	 * @dataProvider engineWithNativeFieldProvider
	 * @param string $engine Engine slug that ships its own keyphrase field.
	 */
	public function test_no_own_field_when_engine_owns_the_keyphrase( string $engine ): void {
		$this->assertTrue(
			Seonix_SEO_Engine::has_native_focus_kw_ui( array( $engine ) ),
			$engine . ' has its own keyphrase field — Seonix must not add a second one'
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function engineWithNativeFieldProvider(): array {
		return array(
			'yoast'    => array( Seonix_SEO_Engine::YOAST ),
			'rankmath' => array( Seonix_SEO_Engine::RANKMATH ),
			'seopress' => array( Seonix_SEO_Engine::SEOPRESS ),
		);
	}

	public function test_no_own_field_when_any_active_engine_owns_the_keyphrase(): void {
		// Two SEO plugins at once is a misconfigured site, but Yoast still shows
		// its field — one owner anywhere in the list is enough to stand down.
		$this->assertTrue( Seonix_SEO_Engine::has_native_focus_kw_ui(
			array( Seonix_SEO_Engine::AIOSEO, Seonix_SEO_Engine::YOAST )
		) );
	}

	public function test_detected_engines_are_used_when_none_are_passed(): void {
		// FakeYoast makes Yoast the active engine for the suite, so the
		// zero-argument call — the one production makes — must stand down.
		$this->assertTrue( Seonix_SEO_Engine::has_native_focus_kw_ui() );
	}

	// ─── (b) Offer the field when nothing else does ──────────────────────

	/**
	 * @dataProvider engineWithoutNativeFieldProvider
	 * @param string[] $engines Active engines.
	 * @param string   $why     Assertion message.
	 */
	public function test_own_field_when_nothing_owns_the_keyphrase( array $engines, string $why ): void {
		$this->assertFalse( Seonix_SEO_Engine::has_native_focus_kw_ui( $engines ), $why );
	}

	/**
	 * @return array<string,array{0:string[],1:string}>
	 */
	public static function engineWithoutNativeFieldProvider(): array {
		return array(
			'no SEO plugin at all' => array( array(), 'with no SEO plugin the author has nowhere else to set a keyphrase' ),
			'aioseo'               => array( array( Seonix_SEO_Engine::AIOSEO ), 'AIOSEO keeps its keyphrase in its own table — Seonix cannot read it' ),
			'tsf'                  => array( array( Seonix_SEO_Engine::TSF ), 'TSF has no keyphrase concept at all' ),
			'squirrly'             => array( array( Seonix_SEO_Engine::SQUIRRLY ), 'Squirrly keeps its keyword in wp_qss' ),
			'aioseo + tsf'         => array( array( Seonix_SEO_Engine::AIOSEO, Seonix_SEO_Engine::TSF ), 'neither engine exposes a readable keyphrase' ),
		);
	}

	// ─── The flag the block editor gates on ──────────────────────────────

	public function test_audit_payload_carries_the_native_ui_flag_and_canonical_key(): void {
		Functions\when( 'post_type_supports' )->justReturn( true );

		$data = $this->auditData();

		// FakeYoast → Yoast active → the JS must render no field of its own.
		$this->assertTrue( $data['hasNativeKeyphraseUi'] );
		// The JS writes this meta key through editPost(); it must come from the
		// bridge rather than be spelled out a second time in the JS.
		$this->assertSame( Seonix_Meta_Bridge::META_FOCUS_KW, $data['focusKeywordMetaKey'] );
		$this->assertSame( 'Focus keyphrase', $data['i18n']['focusKeyphrase'] );
		// The old copy dead-ended at "checks are skipped". It survives for the
		// one case where that is still true (no engine, no field of ours), and
		// the other two now name the way out.
		$this->assertStringContainsString( 'SEO plugin', $data['i18n']['noKeyphrase'] );
		$this->assertStringContainsString( 'field above', $data['i18n']['noKeyphraseOwn'] );
		$this->assertStringContainsString( 'skipped', $data['i18n']['noKeyphraseSkipped'] );
	}

	/**
	 * @dataProvider metaInRestProvider
	 * @param bool $supports_custom_fields Whether the post type supports custom-fields.
	 */
	public function test_audit_payload_reports_whether_the_post_type_stores_meta_over_rest( bool $supports_custom_fields ): void {
		// Core drops the `meta` field from a post type's REST schema unless it
		// supports custom-fields, and editPost({meta}) then swallows the value
		// on save. The panel needs to know, or it shows a field that lies.
		Functions\when( 'post_type_supports' )->alias(
			static fn ( $type, $feature ) => 'custom-fields' === $feature ? $supports_custom_fields : true
		);

		$data = $this->auditData();

		$this->assertSame( $supports_custom_fields, $data['keyphraseMetaInRest'] );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public static function metaInRestProvider(): array {
		return array(
			'post type carries meta'      => array( true ),
			'post type drops meta on save' => array( false ),
		);
	}

	/**
	 * The localized payload for a plain draft.
	 *
	 * @return array<string,mixed>
	 */
	private function auditData(): array {
		$tasks = Mockery::mock( Seonix_Tasks::class );
		$tasks->shouldReceive( 'synced_at' )->andReturn( 0 );
		$tasks->shouldReceive( 'issues_for_url' )->andReturn( array() );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php?page=seonix' );

		$metabox = new Seonix_Metabox( $tasks );
		return $metabox->audit_data( new \WP_Post( array( 'ID' => 42, 'post_status' => 'draft' ) ) );
	}

	// ─── Classic meta box rendering ──────────────────────────────────────

	public function test_classic_field_renders_input_and_nonce_when_no_native_ui(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'best espresso machine' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->alias(
			static fn ( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_nonce_field' )->alias( static function ( $action, $name ) {
			echo '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
		} );

		$html = $this->renderField( 42, false );

		$this->assertStringContainsString( 'name="seonix_focus_keyword"', $html );
		$this->assertStringContainsString( 'value="best espresso machine"', $html );
		$this->assertStringContainsString( 'Focus keyphrase', $html );
		// The nonce the save handler verifies, bound to this post.
		$this->assertStringContainsString( 'name="seonix_focus_kw_nonce"', $html );
		$this->assertStringContainsString( 'nonce-for-seonix_focus_kw_42', $html );
	}

	public function test_classic_field_renders_nothing_when_engine_owns_the_keyphrase(): void {
		$html = $this->renderField( 42, true );
		$this->assertSame( '', $html, 'Yoast et al. already show a field — ours must stay away' );
	}

	public function test_classic_field_escapes_the_stored_value(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'a" onfocus="alert(1)' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->alias(
			static fn ( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_nonce_field' )->justReturn( null );

		$html = $this->renderField( 42, false );

		$this->assertStringNotContainsString( 'onfocus="alert(1)"', $html );
		$this->assertStringContainsString( '&quot;', $html );
	}

	/**
	 * Drive the private renderer with a synthetic audit payload. The method is a
	 * pure function of that payload, and going through render() instead would
	 * pin the test to whatever engine detection reports in the test env (always
	 * Yoast — see the class docblock), which is exactly the branch that must be
	 * exercised from both sides here.
	 *
	 * @param int  $post_id  Post being edited.
	 * @param bool $native   Whether an engine owns the keyphrase.
	 * @return string Rendered HTML.
	 */
	private function renderField( int $post_id, bool $native ): string {
		$post = new \WP_Post( array( 'ID' => $post_id ) );

		$payload = array(
			'hasNativeKeyphraseUi' => $native,
			'i18n'                 => array(
				'focusKeyphrase'     => 'Focus keyphrase',
				'focusKeyphraseHelp' => 'The search term this page should rank for.',
			),
		);

		$method = new \ReflectionMethod( Seonix_Metabox::class, 'render_focus_keyword_field' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( $this->metabox, $post, $payload );
		return (string) ob_get_clean();
	}

	// ─── register_post_meta contract ─────────────────────────────────────

	public function test_registers_canonical_meta_for_public_post_types(): void {
		Functions\when( 'get_post_types' )->justReturn( array(
			'post'       => 'post',
			'page'       => 'page',
			'attachment' => 'attachment',
		) );
		$registered = array();
		Functions\when( 'register_post_meta' )->alias(
			function ( $type, $key, $args ) use ( &$registered ) {
				$registered[ $type ] = array( 'key' => $key, 'args' => $args );
				return true;
			}
		);

		$this->metabox->register_meta();

		// The editor post types, minus attachment — same set the meta box uses.
		$this->assertSame( array( 'post', 'page' ), array_keys( $registered ) );

		$args = $registered['post']['args'];
		$this->assertSame( Seonix_Meta_Bridge::META_FOCUS_KW, $registered['post']['key'] );
		// Without show_in_rest the block editor cannot read or write it at all —
		// but it MUST be edit-context only. auth_callback gates writes and nothing
		// else (core hangs it off the edit/add/delete_post_meta caps, which only
		// the update path consults; WP_REST_Meta_Fields::get_value() checks no
		// capability at all). A bare `true` here would ship the key in the default
		// `view` context of GET /wp/v2/posts/<id>, which is unauthenticated for any
		// published post — leaking the page's keyphrase to anonymous visitors.
		$this->assertIsArray( $args['show_in_rest'] );
		$this->assertSame( array( 'edit' ), $args['show_in_rest']['schema']['context'] );
		$this->assertTrue( $args['single'] );
		$this->assertSame( 'string', $args['type'] );
		$this->assertIsCallable( $args['sanitize_callback'] );
		// Protected meta (leading underscore) defaults to __return_false — an
		// explicit auth_callback is what makes the key writable at all.
		$this->assertIsCallable( $args['auth_callback'] );
	}

	public function test_auth_callback_requires_edit_on_that_very_post(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap, $id = 0 ) => 'edit_post' === $cap && 42 === $id
		);

		$this->assertTrue( Seonix_Metabox::auth_focus_keyword( false, Seonix_Meta_Bridge::META_FOCUS_KW, 42 ) );
		// Core's default is recomputed, never trusted: a user who cannot edit
		// post 99 must not write its keyphrase even when handed $allowed = true.
		$this->assertFalse( Seonix_Metabox::auth_focus_keyword( true, Seonix_Meta_Bridge::META_FOCUS_KW, 99 ) );
	}

	// ─── (d) Sanitizing ──────────────────────────────────────────────────

	public function test_sanitize_strips_engine_template_variables(): void {
		// A keyphrase carrying %%title%% would be expanded by the engine at
		// render time — the bridge's sanitizer is the single source of truth for
		// stripping every engine's syntax, and the callback must route through it.
		$this->assertSame( 'best coffee', Seonix_Metabox::sanitize_focus_keyword( 'best %%title%% coffee' ) );
		$this->assertSame( 'best coffee', Seonix_Metabox::sanitize_focus_keyword( 'best %sep% coffee' ) );
		$this->assertSame( 'best coffee', Seonix_Metabox::sanitize_focus_keyword( 'best #post_title coffee' ) );
	}

	public function test_sanitize_survives_non_string_values(): void {
		// sanitize_meta() hands the callback whatever update_post_meta() was
		// given. sanitize_value()'s signature is `string`, so an array or null
		// from some other plugin's stray write would be a fatal TypeError —
		// a white screen on save, over a value we do not even want.
		$this->assertSame( '', Seonix_Metabox::sanitize_focus_keyword( null ) );
		$this->assertSame( '', Seonix_Metabox::sanitize_focus_keyword( array( 'nope' ) ) );
		$this->assertSame( '', Seonix_Metabox::sanitize_focus_keyword( 42 ) );
	}

	// ─── (c) Forward sync ────────────────────────────────────────────────

	public function test_block_editor_meta_write_fans_out_to_active_engine_keys(): void {
		$writes = $this->captureWrites();

		// What core's REST meta handler does when the panel's TextControl value
		// rides out with the post: update_post_meta on the canonical key, which
		// never passes through the bridge on its own.
		$this->metabox->on_focus_keyword_change( 1, 42, Seonix_Meta_Bridge::META_FOCUS_KW, 'best coffee' );

		$this->assertSame( 'best coffee', $writes[ Seonix_Meta_Bridge::META_FOCUS_KW ] );
		// FakeYoast is the active engine in this suite.
		$this->assertSame( 'best coffee', $writes['_yoast_wpseo_focuskw'] );
		// Fan-out is keyphrase-only: a keyphrase edit must not touch the title
		// or description the author never went near.
		$this->assertArrayNotHasKey( '_yoast_wpseo_title', $writes );
		$this->assertArrayNotHasKey( '_yoast_wpseo_metadesc', $writes );
		// Re-fingerprinted, so the watcher's shutdown diff stays quiet.
		$this->assertArrayHasKey( Seonix_Meta_Bridge::META_FINGERPRINT, $writes );
	}

	public function test_fan_out_ignores_other_meta_keys(): void {
		$writes = $this->captureWrites();

		$this->metabox->on_focus_keyword_change( 1, 42, '_thumbnail_id', '7' );
		// The engine's own key is the watcher's business, not ours — fanning it
		// back out is the other half of a loop.
		$this->metabox->on_focus_keyword_change( 1, 42, '_yoast_wpseo_focuskw', 'theirs' );

		$this->assertCount( 0, $writes );
	}

	public function test_fan_out_stands_down_during_a_bridge_write(): void {
		// The bridge is mid-write (a publish, an SEO fix, the backfill): it has
		// already fanned the value out, and re-entering here would be the first
		// step of a loop.
		$writes                      = $this->captureWrites();
		Seonix_Meta_Bridge::$writing = true;

		$this->metabox->on_focus_keyword_change( 1, 42, Seonix_Meta_Bridge::META_FOCUS_KW, 'best coffee' );

		$this->assertCount( 0, $writes );
	}

	public function test_classic_save_writes_through_the_bridge(): void {
		$writes = $this->captureWrites();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['seonix_focus_kw_nonce'] = 'valid';
		$_POST['seonix_focus_keyword']  = 'best coffee';
		try {
			$this->metabox->save_focus_keyword( 42 );
		} finally {
			unset( $_POST['seonix_focus_kw_nonce'], $_POST['seonix_focus_keyword'] );
		}

		$this->assertSame( 'best coffee', $writes[ Seonix_Meta_Bridge::META_FOCUS_KW ] );
		$this->assertSame( 'best coffee', $writes['_yoast_wpseo_focuskw'] );
	}

	public function test_classic_save_rejects_a_bad_nonce(): void {
		$writes = $this->captureWrites();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->postSave( 42, 'forged', 'injected' );

		$this->assertCount( 0, $writes );
	}

	public function test_classic_save_rejects_a_user_who_cannot_edit_the_post(): void {
		$writes = $this->captureWrites();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->postSave( 42, 'valid', 'injected' );

		$this->assertCount( 0, $writes );
	}

	public function test_classic_save_ignores_saves_that_are_not_our_form(): void {
		// A block-editor REST save, a quick edit or WP-CLI carries no field of
		// ours. Reading that as "the author cleared the keyphrase" would wipe
		// the value on every save made anywhere else.
		$writes = $this->captureWrites();

		$this->metabox->save_focus_keyword( 42 );

		$this->assertCount( 0, $writes );
	}

	/**
	 * Run the classic save handler against a submitted form, leaving $_POST as
	 * clean as it was found.
	 *
	 * @param int    $post_id   Post being saved.
	 * @param string $nonce     Submitted nonce.
	 * @param string $keyphrase Submitted keyphrase.
	 * @return void
	 */
	private function postSave( int $post_id, string $nonce, string $keyphrase ): void {
		$_POST['seonix_focus_kw_nonce'] = $nonce;
		$_POST['seonix_focus_keyword']  = $keyphrase;
		try {
			$this->metabox->save_focus_keyword( $post_id );
		} finally {
			unset( $_POST['seonix_focus_kw_nonce'], $_POST['seonix_focus_keyword'] );
		}
	}

	// ─── (c) …and no loop ────────────────────────────────────────────────

	public function test_fan_out_is_invisible_to_the_reverse_sync_watcher(): void {
		// The real loop, wired the way production wires it: every write fires
		// updated_post_meta, which reaches BOTH the reverse-sync watcher (which
		// would read our engine write back as a site-owner edit and re-write it)
		// and this hook again (on our own canonical write). Without the guard
		// this recurses until the stack dies; the assertions below only get to
		// run because it does not.
		$watcher = new Seonix_Meta_Watcher( Mockery::mock( Seonix_Sync::class ) );
		$metabox = $this->metabox;
		$seen    = array();

		// A Seonix-managed post: without the guard the watcher WOULD queue this
		// one, which is what makes the shutdown expectation below mean something.
		Functions\when( 'get_post_meta' )->alias(
			static fn ( $post_id, $key ) => '_ce_article_id' === $key ? 'ce-article-1' : ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$seen, $watcher, $metabox ) {
				$seen[ $key ] = Seonix_Meta_Bridge::$writing;
				$watcher->on_meta_change( 1, $post_id, $key );
				$metabox->on_focus_keyword_change( 1, $post_id, $key, $value );
				return true;
			}
		);

		$metabox->on_focus_keyword_change( 1, 4242, Seonix_Meta_Bridge::META_FOCUS_KW, 'best coffee' );

		// Nothing queued means nothing to diff on shutdown, so no
		// seo_meta_updated event claiming the site owner edited their SEO plugin.
		$this->assertSame( array(), $this->queuedPosts(), 'our own fan-out must not queue a reverse sync' );
		// And the reason it did not: the guard was up for every single write.
		$this->assertNotEmpty( $seen );
		foreach ( $seen as $key => $guard_was_up ) {
			$this->assertTrue( $guard_was_up, $key . ' was written with the bridge guard down' );
		}
	}

	public function test_the_watcher_does_queue_when_the_guard_is_down(): void {
		// Control for the test above: the watcher really would have queued this
		// post — so "nothing queued" there is the guard's doing, not an artefact
		// of the watcher ignoring everything in this environment.
		Functions\when( 'get_post_meta' )->justReturn( 'ce-article-1' );

		$watcher = new Seonix_Meta_Watcher( Mockery::mock( Seonix_Sync::class ) );
		$watcher->on_meta_change( 1, 4343, '_yoast_wpseo_focuskw' );

		$this->assertSame( array( 4343 ), $this->queuedPosts() );
	}

	/**
	 * Post IDs the watcher has queued for its shutdown diff.
	 *
	 * @return int[]
	 */
	private function queuedPosts(): array {
		$queued = new \ReflectionProperty( Seonix_Meta_Watcher::class, 'queued' );
		$queued->setAccessible( true );
		return array_keys( (array) $queued->getValue() );
	}

	/**
	 * Record every update_post_meta() the bridge makes, with the canonical
	 * values readable back for its fingerprint refresh.
	 *
	 * An ArrayObject rather than an array: the caller needs to see writes made
	 * after this returns, and a returned array would be a copy taken before the
	 * first one landed.
	 *
	 * @return \ArrayObject<string,string> Filled as writes happen.
	 */
	private function captureWrites(): \ArrayObject {
		$writes = new \ArrayObject();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( $writes ) {
				$writes[ $key ] = $value;
				return true;
			}
		);
		return $writes;
	}
}
