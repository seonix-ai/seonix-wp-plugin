<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_WebMCP;

/**
 * Covers Seonix_WebMCP — the declarative WebMCP annotations that let an AI agent
 * use the site's forms as tools.
 *
 * The two escaping contexts are the thing most worth locking down here:
 *   - wpcf7_form_additional_atts → CF7 runs values through wpcf7_format_atts(),
 *     which esc_attr()s them, so values returned from our filter must be RAW;
 *   - wpcf7_form_elements / render_block → raw HTML strings we edit ourselves, so
 *     values inserted there MUST be escaped by us.
 * Getting those backwards yields either double-encoded text or an injection hole.
 */
final class WebMCPTest extends TestCase {

	private Seonix_WebMCP $module;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_attr' )->alias(
			static fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $s ) => trim( strip_tags( (string) $s ) )
		);
		// Stand-in for WP's slugifier: enough for the ASCII titles under test.
		Functions\when( 'sanitize_title' )->alias(
			static fn ( $s ) => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' )
		);

		$this->module = new Seonix_WebMCP();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── The <form> tag (wpcf7_form_additional_atts) ─────────────────────

	public function test_cf7_form_atts_add_toolname_and_description(): void {
		$atts = $this->module->filter_cf7_form_atts( array() );

		$this->assertIsArray( $atts );
		// No CF7 form is "current" in a unit test → the generic fallback.
		$this->assertSame( 'contact-form', $atts['toolname'] );
		$this->assertSame( 'Submit the contact form on this site.', $atts['tooldescription'] );
	}

	/**
	 * CF7 merges our return with `+=` (array union), so keys another plugin
	 * already set must win. Assert we never clobber.
	 */
	public function test_cf7_form_atts_do_not_clobber_existing_keys(): void {
		$atts = $this->module->filter_cf7_form_atts(
			array(
				'toolname'        => 'custom-tool',
				'tooldescription' => 'Custom description.',
			)
		);

		$this->assertSame( 'custom-tool', $atts['toolname'] );
		$this->assertSame( 'Custom description.', $atts['tooldescription'] );
	}

	/**
	 * Values handed back to CF7 must be RAW — wpcf7_format_atts() esc_attr()s
	 * them on the way out. Escaping here too would render "&amp;quot;" to users.
	 */
	public function test_cf7_form_atts_values_are_not_pre_escaped(): void {
		$atts = $this->module->filter_cf7_form_atts( array() );

		$this->assertStringNotContainsString( '&amp;', $atts['tooldescription'] );
		$this->assertStringNotContainsString( '&quot;', $atts['tooldescription'] );
	}

	public function test_cf7_form_atts_passes_through_non_array(): void {
		$this->assertNull( $this->module->filter_cf7_form_atts( null ) );
		$this->assertSame( 'unexpected', $this->module->filter_cf7_form_atts( 'unexpected' ) );
	}

	// ─── The fields (wpcf7_form_elements) ────────────────────────────────

	public function test_cf7_select_gets_toolparamdescription_from_placeholder_option(): void {
		$form = '<select class="wpcf7-form-control wpcf7-select" name="select-796">'
			. '<option value="Leistungsart auswählen*">Leistungsart auswählen*</option>'
			. '<option value="Montage">Montage</option></select>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString(
			'name="select-796" toolparamdescription="Leistungsart auswählen"',
			$out
		);
	}

	/**
	 * A labelled field still gets a param description: the label names the field
	 * for a human, toolparamdescription explains it to an agent. The label's own
	 * words are the best source available.
	 */
	public function test_labelled_field_is_described_from_its_label(): void {
		$form = '<label> Ihr Name*<span class="wpcf7-form-control-wrap" data-name="your-name">'
			. '<input type="text" name="your-name" /></span></label>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'toolparamdescription="Ihr Name"', $out );
	}

	/**
	 * A label wrapping a <select> must contribute only its OWN words — not the
	 * text of whichever option happens to come first.
	 */
	public function test_wrapping_label_text_excludes_enclosed_option_text(): void {
		$form = '<label>Leistungsart<select name="select-796">'
			. '<option>Montage</option><option>Wohnberatung</option></select></label>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'toolparamdescription="Leistungsart"', $out );
		$this->assertStringNotContainsString( 'Leistungsart Montage', $out );
	}

	public function test_field_described_from_label_for_association(): void {
		$form = '<label for="msg">Ihre Nachricht</label><textarea id="msg" name="your-message"></textarea>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'toolparamdescription="Ihre Nachricht"', $out );
	}

	public function test_field_falls_back_to_placeholder_then_name(): void {
		$form = '<input type="text" name="your-name" placeholder="Ihr Name*" />'
			. '<input type="email" name="your-email" />';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'placeholder="Ihr Name*" toolparamdescription="Ihr Name" />', $out );
		$this->assertStringContainsString( 'name="your-email" toolparamdescription="Your Email" />', $out );
	}

	public function test_non_parameter_inputs_are_skipped(): void {
		$form = '<input type="hidden" name="_wpcf7" value="796" />'
			. '<input type="submit" value="Senden" />';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_autogenerated_name_yields_no_description(): void {
		$form = '<input type="text" name="text-796" />';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_form_elements_is_idempotent(): void {
		$form = '<select name="select-796"><option>Leistungsart auswählen*</option></select>'
			. '<input type="text" name="your-name" placeholder="Ihr Name*" />';

		$once  = $this->module->filter_cf7_form_elements( $form );
		$twice = $this->module->filter_cf7_form_elements( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( 2, substr_count( $twice, 'toolparamdescription=' ) );
	}

	/** Values inserted into raw HTML must be escaped by us. */
	public function test_inserted_description_is_escaped(): void {
		$form = '<input type="text" name="your-name" placeholder="Say &quot;hi&quot; &amp; wave" />';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'toolparamdescription="Say &quot;hi&quot; &amp; wave"', $out );
	}

	// ─── core/search (render_block) ──────────────────────────────────────

	public function test_core_search_block_gets_form_and_input_annotations(): void {
		$block = '<form role="search" method="get" class="wp-block-search__button-outside" action="https://example.test/">'
			. '<label class="wp-block-search__label" for="wp-block-search__input-1">Suche</label>'
			. '<input class="wp-block-search__input" id="wp-block-search__input-1" name="s" type="search" required />'
			. '<button type="submit">Suchen</button></form>';

		$out = $this->module->filter_render_block( $block, array( 'blockName' => 'core/search' ) );

		$this->assertStringContainsString( 'toolname="site-search"', $out );
		$this->assertStringContainsString(
			'tooldescription="Search this site&#039;s content and return matching pages."',
			$out
		);
		$this->assertStringContainsString(
			'type="search" required toolparamdescription="The words to search this site for."',
			$out
		);
		// The form's own attributes survive untouched.
		$this->assertStringContainsString( 'role="search" method="get"', $out );
		$this->assertStringContainsString( 'action="https://example.test/"', $out );
	}

	public function test_non_search_blocks_are_untouched(): void {
		$block = '<form action="/subscribe"><input type="text" name="email" /></form>';

		$this->assertSame(
			$block,
			$this->module->filter_render_block( $block, array( 'blockName' => 'core/paragraph' ) )
		);
	}

	public function test_core_search_render_block_is_idempotent(): void {
		$block = '<form role="search" method="get" action="/">'
			. '<input class="wp-block-search__input" name="s" type="search" /></form>';

		$once  = $this->module->filter_render_block( $block, array( 'blockName' => 'core/search' ) );
		$twice = $this->module->filter_render_block( $once, array( 'blockName' => 'core/search' ) );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'toolname=' ) );
		$this->assertSame( 1, substr_count( $twice, 'tooldescription=' ) );
		$this->assertSame( 1, substr_count( $twice, 'toolparamdescription=' ) );
	}

	public function test_non_string_input_is_passed_through(): void {
		$this->assertNull( $this->module->filter_render_block( null, array( 'blockName' => 'core/search' ) ) );
		$this->assertNull( $this->module->filter_cf7_form_elements( null ) );
	}
}
