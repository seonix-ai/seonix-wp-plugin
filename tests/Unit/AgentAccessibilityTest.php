<?php
namespace Seonix\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Seonix_Agent_Accessibility;

/**
 * Covers Seonix_Agent_Accessibility — the render-time filters that give an
 * accessible name to elements Chrome Lighthouse's "Agentic Browsing" audit
 * (agent-accessibility-tree) fails a page on.
 *
 * Fixtures are the real markup from the client site wohnartstudio.de that
 * produced the two failures:
 *   link-name   → Spectra's empty <a class="spectra-container-link-overlay">
 *   select-name → Contact Form 7's <select name="select-796"> whose only visible
 *                 cue is a placeholder <option> ("Leistungsart auswählen*")
 *
 * The safety contract under test throughout: the filters only ever ADD an
 * aria-label to an element that has no accessible name, never touch visible
 * text, and are idempotent.
 */
final class AgentAccessibilityTest extends TestCase {

	private Seonix_Agent_Accessibility $module;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The module leans on three WP primitives. Alias them to their real
		// behaviour so the tests exercise the production code path.
		Functions\when( 'esc_attr' )->alias(
			static fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $s ) => trim( strip_tags( (string) $s ) )
		);

		$this->module = new Seonix_Agent_Accessibility();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── Spectra container-link overlay (link-name) ──────────────────────

	/**
	 * The real wohnartstudio.de Spectra container: an empty overlay anchor
	 * stretched over a card whose title lives in a sibling heading. The heading
	 * is what a human reads as the link's name, so it is what we borrow.
	 */
	public function test_spectra_overlay_takes_aria_label_from_container_heading(): void {
		$block = '<div class="wp-block-uagb-container uagb-block-abc123">'
			. '<a class="spectra-container-link-overlay" rel="noopener" href="/wohnkonzept/"></a>'
			. '<div class="uagb-container-inner-blocks-wrap">'
			. '<h3 class="uagb-heading-text">Wohnkonzept</h3>'
			. '<p>Individuelle Beratung fuer Ihr Zuhause.</p>'
			. '</div></div>';

		$out = $this->module->filter_render_block( $block, array( 'blockName' => 'uagb/container' ) );

		$this->assertStringContainsString(
			'<a class="spectra-container-link-overlay" rel="noopener" href="/wohnkonzept/" aria-label="Wohnkonzept">',
			$out
		);
		// Visible content is untouched — the heading and copy survive verbatim.
		$this->assertStringContainsString( '<h3 class="uagb-heading-text">Wohnkonzept</h3>', $out );
		$this->assertStringContainsString( '<p>Individuelle Beratung fuer Ihr Zuhause.</p>', $out );
	}

	/**
	 * The block's own heading wins over the href slug: it is the author's words,
	 * the slug is a guess.
	 */
	public function test_heading_beats_href_slug(): void {
		$block = '<div><a class="spectra-container-link-overlay" href="/leistungen-2/"></a>'
			. '<h2>Unsere Leistungen</h2></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'aria-label="Unsere Leistungen"', $out );
		$this->assertStringNotContainsString( 'aria-label="Leistungen 2"', $out );
	}

	/** No heading in the block → borrow a sibling image's alt. */
	public function test_falls_back_to_sibling_image_alt_when_no_heading(): void {
		$block = '<div><a class="spectra-container-link-overlay" href="/galerie/"></a>'
			. '<figure><img src="/wp-content/uploads/kueche.jpg" alt="Moderne Kueche in Eiche" /></figure></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'aria-label="Moderne Kueche in Eiche"', $out );
	}

	/** Last resort: humanize the href's final path segment. */
	public function test_falls_back_to_humanized_href_slug(): void {
		$block = '<div><a class="spectra-container-link-overlay" rel="noopener" href="/wohnkonzept/"></a></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'aria-label="Wohnkonzept"', $out );
	}

	public function test_humanized_slug_splits_hyphens_and_title_cases(): void {
		$block = '<div><a class="spectra-container-link-overlay" href="/unsere-leistungen/"></a></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'aria-label="Unsere Leistungen"', $out );
	}

	// ─── Anchors that must be left alone ─────────────────────────────────

	public function test_anchor_with_text_is_untouched(): void {
		$block = '<p><a href="/kontakt/">Kontakt aufnehmen</a></p>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_anchor_with_existing_aria_label_is_untouched(): void {
		$block = '<div><a class="spectra-container-link-overlay" aria-label="Bereits benannt" href="/x/"></a><h3>Titel</h3></div>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_anchor_with_title_attribute_is_untouched(): void {
		$block = '<div><a title="Zum Wohnkonzept" href="/wohnkonzept/"></a><h3>Titel</h3></div>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_anchor_with_aria_hidden_is_untouched(): void {
		// aria-hidden elements are pruned from the a11y tree; naming them is noise.
		$block = '<div><a aria-hidden="true" href="/x/"></a><h3>Titel</h3></div>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_anchor_wrapping_image_with_alt_is_untouched(): void {
		// A nested <img alt> already names the anchor per the accname spec.
		$block = '<div><a href="/galerie/"><img src="/a.jpg" alt="Galerie ansehen" /></a><h3>Titel</h3></div>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_anchor_wrapping_image_with_empty_alt_gets_named(): void {
		// alt="" is an explicit "decorative" marker: the image contributes no
		// name, so the anchor is still unnamed and does need us.
		$block = '<div><a href="/galerie/"><img src="/a.jpg" alt="" /></a><h3>Galerie</h3></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'aria-label="Galerie"', $out );
	}

	public function test_anchor_containing_only_whitespace_entities_is_named(): void {
		// &nbsp; / zero-width space are invisible — the a11y tree sees no name.
		$block = "<div><a href=\"/wohnkonzept/\">&nbsp;\xE2\x80\x8B</a><h3>Wohnkonzept</h3></div>";

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'aria-label="Wohnkonzept"', $out );
	}

	public function test_no_label_added_when_nothing_can_be_derived(): void {
		// Root href, no heading, no image → we have nothing honest to say, and an
		// empty aria-label would be worse than none.
		$block = '<div><a class="spectra-container-link-overlay" href="/"></a></div>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_in_page_and_script_hrefs_derive_nothing(): void {
		foreach ( array( '#main', 'javascript:void(0)', 'mailto:info@example.com', '/page/2/' ) as $href ) {
			$block = '<div><a href="' . $href . '"></a></div>';
			$this->assertSame(
				$block,
				$this->module->filter_render_block( $block, array() ),
				'href ' . $href . ' should not yield a label'
			);
		}
	}

	/**
	 * Two overlays sharing one heading must NOT both be named after it — that
	 * would give two different destinations the same name (WCAG 2.4.4). Each
	 * falls back to its own href instead.
	 */
	public function test_multiple_unnamed_anchors_do_not_share_the_block_heading(): void {
		$block = '<div class="wp-block-uagb-container"><h3>Leistungen</h3>'
			. '<a class="spectra-container-link-overlay" href="/wohnkonzept/"></a>'
			. '<a class="spectra-container-link-overlay" href="/montage/"></a></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'href="/wohnkonzept/" aria-label="Wohnkonzept"', $out );
		$this->assertStringContainsString( 'href="/montage/" aria-label="Montage"', $out );
		$this->assertStringNotContainsString( 'aria-label="Leistungen"', $out );
	}

	/**
	 * With a second anchor present the heading is ambiguous — it may describe
	 * either link — so the overlay falls back to its own href instead of
	 * borrowing. Here the href and the heading differ, which proves which path
	 * ran.
	 */
	public function test_second_anchor_in_block_blocks_heading_derivation(): void {
		$block = '<div><h3>Unser Wohnkonzept</h3>'
			. '<a class="spectra-container-link-overlay" href="/beratung/"></a>'
			. '<a href="/mehr/">Mehr erfahren</a></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertStringContainsString( 'href="/beratung/" aria-label="Beratung"', $out );
		$this->assertStringNotContainsString( 'aria-label="Unser Wohnkonzept"', $out );
		$this->assertStringContainsString( '<a href="/mehr/">Mehr erfahren</a>', $out );
	}

	/**
	 * Regression lock, reproduced against the real filter before the fix.
	 *
	 * Blocks are filtered inner-first and a parent receives its children's
	 * already-filtered HTML. A grid of cards therefore reaches the parent pass
	 * with every card named except the ones we declined to label (href="/"). If
	 * the "may I borrow the heading?" test counted only UNNAMED anchors, that
	 * lone leftover would be handed the FIRST card's heading — an actively wrong
	 * name pointing at the wrong destination. Keying off the TOTAL anchor count
	 * is what prevents it.
	 */
	public function test_parent_block_does_not_lend_one_cards_heading_to_another(): void {
		$card_a = $this->module->filter_render_block(
			'<div class="card"><a class="spectra-container-link-overlay" href="/card-a/"></a><h3>Card A Title</h3></div>',
			array()
		);
		$card_b = $this->module->filter_render_block(
			'<div class="card"><a class="spectra-container-link-overlay" href="/"></a></div>',
			array()
		);

		// Each card was filtered on its own first, exactly as render_block does.
		$this->assertStringContainsString( 'href="/card-a/" aria-label="Card A Title"', $card_a );
		$this->assertStringNotContainsString( 'aria-label', $card_b );

		$grid = $this->module->filter_render_block( '<div class="grid">' . $card_a . $card_b . '</div>', array() );

		// Card B's overlay points at "/" — nothing derivable — and must stay
		// unnamed rather than inherit card A's heading.
		$this->assertStringContainsString( '<a class="spectra-container-link-overlay" href="/"></a>', $grid );
		$this->assertSame( 1, substr_count( $grid, 'aria-label=' ) );
	}

	// ─── Malformed markup must be skipped, never corrupted ───────────────

	/**
	 * An unescaped `>` inside a quoted value truncates the tag match. Inserting
	 * at that point would land inside the still-open attribute value and rewrite
	 * the tag into nonsense. The contract is: skip the element, never corrupt it.
	 */
	public function test_anchor_with_unescaped_gt_in_attribute_is_left_untouched(): void {
		$block = '<div><a class="overlay" data-tip="a>b" href="/wohnkonzept/"></a><h3>Wohnkonzept</h3></div>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_control_with_unescaped_gt_in_attribute_is_left_untouched(): void {
		$form = '<input type="text" name="your-name" title="a>b" placeholder="Ihr Name*" />';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	/** An apostrophe inside a double-quoted value is not a truncation. */
	public function test_apostrophe_in_attribute_value_does_not_block_naming(): void {
		$form = '<input type="text" name="your-name" placeholder="Nom d\'utilisateur*" />';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'aria-label="Nom d&#039;utilisateur"', $out );
	}

	/**
	 * We can only ADD attributes, so an element already carrying aria-label — even
	 * an empty one — must be left alone. Appending a second would emit a duplicate
	 * attribute that browsers resolve to the first, silently undoing the fix.
	 */
	public function test_empty_aria_label_is_not_duplicated(): void {
		$block = '<div><a class="overlay" aria-label="" href="/wohnkonzept/"></a><h3>Wohnkonzept</h3></div>';

		$out = $this->module->filter_render_block( $block, array() );

		$this->assertSame( $block, $out );
		$this->assertSame( 1, substr_count( $out, 'aria-label=' ) );
	}

	public function test_control_with_empty_aria_label_is_not_duplicated(): void {
		$form = '<select name="select-796" aria-label=""><option>Leistungsart auswählen*</option></select>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertSame( $form, $out );
		$this->assertSame( 1, substr_count( $out, 'aria-label=' ) );
	}

	public function test_block_without_anchor_is_returned_verbatim(): void {
		$block = '<h3 class="uagb-heading-text">Wohnkonzept</h3>';

		$this->assertSame( $block, $this->module->filter_render_block( $block, array() ) );
	}

	public function test_label_value_is_escaped(): void {
		$block = '<div><a class="spectra-container-link-overlay" href="/x/"></a><h3>Tipps &amp; "Tricks"</h3></div>';

		$out = $this->module->filter_render_block( $block, array() );

		// Entities decode to their characters, then esc_attr re-encodes for the
		// attribute context — the quote must never break out of the attribute.
		$this->assertStringContainsString( 'aria-label="Tipps &amp; &quot;Tricks&quot;"', $out );
	}

	public function test_render_block_is_idempotent(): void {
		$block = '<div><a class="spectra-container-link-overlay" rel="noopener" href="/wohnkonzept/"></a><h3>Wohnkonzept</h3></div>';

		$once  = $this->module->filter_render_block( $block, array() );
		$twice = $this->module->filter_render_block( $once, array() );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'aria-label=' ) );
	}

	// ─── Contact Form 7 select (select-name) ─────────────────────────────

	/**
	 * The real wohnartstudio.de CF7 control. Its visible cue is the first
	 * <option>, which the accname spec does NOT count as a name — hence the
	 * Lighthouse failure. The trailing required-marker asterisk is a visual
	 * convention, not part of the field's name, so it is stripped.
	 */
	public function test_cf7_select_takes_aria_label_from_placeholder_option(): void {
		$form = '<p><span class="wpcf7-form-control-wrap" data-name="select-796">'
			. '<select class="wpcf7-form-control wpcf7-select" name="select-796">'
			. '<option value="Leistungsart auswählen*">Leistungsart auswählen*</option>'
			. '<option value="Wohnberatung">Wohnberatung</option>'
			. '<option value="Montage">Montage</option>'
			. '</select></span></p>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString(
			'<select class="wpcf7-form-control wpcf7-select" name="select-796" aria-label="Leistungsart auswählen">',
			$out
		);
		// The options themselves — including the asterisk a user sees — are untouched.
		$this->assertStringContainsString( '<option value="Leistungsart auswählen*">Leistungsart auswählen*</option>', $out );
		$this->assertStringContainsString( '<option value="Wohnberatung">Wohnberatung</option>', $out );
	}

	public function test_cf7_select_skips_blank_first_option(): void {
		// CF7's include_blank emits an empty leading option; skip to the real cue.
		$form = '<select name="select-796"><option value=""></option>'
			. '<option value="a">Leistungsart auswählen*</option></select>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'aria-label="Leistungsart auswählen"', $out );
	}

	public function test_cf7_text_input_takes_aria_label_from_placeholder(): void {
		// placeholder is not an accessible name (so the field still fails the
		// audit), but its text is a legitimate source for the name we synthesise.
		$form = '<input type="text" name="your-name" placeholder="Ihr Name*" />';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'aria-label="Ihr Name"', $out );
		// Self-closing slash must survive — never "<input … / aria-label=…>".
		$this->assertStringContainsString( 'aria-label="Ihr Name" />', $out );
	}

	public function test_cf7_input_falls_back_to_humanized_author_chosen_name(): void {
		$form = '<input type="email" name="your-email" />';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'aria-label="Your Email"', $out );
	}

	public function test_cf7_input_with_autogenerated_name_is_left_alone(): void {
		// "text-796" would humanize to "Text 796" — worse than silence for a
		// screen-reader user, and a false pass for the audit. Stay out.
		$form = '<input type="text" name="text-796" />';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_textarea_gets_named(): void {
		$form = '<textarea name="your-message" placeholder="Ihre Nachricht*"></textarea>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'aria-label="Ihre Nachricht"', $out );
	}

	// ─── CF7 controls that must be left alone ────────────────────────────

	public function test_cf7_hidden_and_submit_inputs_are_skipped(): void {
		$form = '<input type="hidden" name="_wpcf7" value="796" />'
			. '<input type="submit" value="Senden" class="wpcf7-submit" />'
			. '<input type="button" name="btn-1" value="Mehr" />'
			. '<input type="reset" name="reset-1" />'
			. '<input type="image" name="img-1" src="/go.png" />';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_field_wrapped_in_label_is_skipped(): void {
		// CF7's default markup: the label wraps the control and carries the text,
		// so the field already has an accessible name.
		$form = '<label> Ihr Name*<span class="wpcf7-form-control-wrap" data-name="your-name">'
			. '<input type="text" name="your-name" placeholder="Name" /></span></label>';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_field_with_label_for_is_skipped(): void {
		$form = '<label for="msg">Ihre Nachricht</label>'
			. '<textarea id="msg" name="your-message" placeholder="Nachricht"></textarea>';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_field_with_empty_label_still_gets_named(): void {
		// A label with no text of its own names nothing.
		$form = '<label for="msg"></label><textarea id="msg" name="your-message" placeholder="Nachricht"></textarea>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'aria-label="Nachricht"', $out );
	}

	public function test_cf7_field_with_existing_aria_label_is_skipped(): void {
		$form = '<select name="select-796" aria-label="Schon benannt"><option>Leistungsart auswählen*</option></select>';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_field_with_aria_labelledby_is_skipped(): void {
		$form = '<span id="lbl">Leistungsart</span>'
			. '<select name="select-796" aria-labelledby="lbl"><option>Leistungsart auswählen*</option></select>';

		$this->assertSame( $form, $this->module->filter_cf7_form_elements( $form ) );
	}

	public function test_cf7_form_elements_is_idempotent(): void {
		$form = '<select class="wpcf7-form-control wpcf7-select" name="select-796">'
			. '<option value="Leistungsart auswählen*">Leistungsart auswählen*</option>'
			. '</select><input type="text" name="your-name" placeholder="Ihr Name*" />';

		$once  = $this->module->filter_cf7_form_elements( $form );
		$twice = $this->module->filter_cf7_form_elements( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( 2, substr_count( $twice, 'aria-label=' ) );
	}

	/**
	 * Multiple unnamed controls in one form: the right-to-left insertion walk
	 * must not corrupt offsets. Each control gets its OWN label.
	 */
	public function test_multiple_controls_each_get_their_own_label(): void {
		$form = '<input type="text" name="your-name" placeholder="Ihr Name*" />'
			. '<input type="email" name="your-email" placeholder="Ihre E-Mail*" />'
			. '<select name="select-796"><option>Leistungsart auswählen*</option></select>'
			. '<textarea name="your-message" placeholder="Ihre Nachricht*"></textarea>';

		$out = $this->module->filter_cf7_form_elements( $form );

		$this->assertStringContainsString( 'name="your-name" placeholder="Ihr Name*" aria-label="Ihr Name"', $out );
		$this->assertStringContainsString( 'name="your-email" placeholder="Ihre E-Mail*" aria-label="Ihre E-Mail"', $out );
		$this->assertStringContainsString( 'name="select-796" aria-label="Leistungsart auswählen"', $out );
		$this->assertStringContainsString( 'name="your-message" placeholder="Ihre Nachricht*" aria-label="Ihre Nachricht"', $out );
		$this->assertSame( 4, substr_count( $out, 'aria-label=' ) );
	}

	public function test_non_string_input_is_passed_through(): void {
		$this->assertNull( $this->module->filter_cf7_form_elements( null ) );
		$this->assertNull( $this->module->filter_render_block( null, array() ) );
	}
}
