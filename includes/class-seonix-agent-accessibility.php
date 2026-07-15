<?php
/**
 * Agent-accessibility repairs for Chrome's Lighthouse "Agentic Browsing" audit.
 *
 * Chrome's agentic-browsing category fails a page on `agent-accessibility-tree`
 * when an interactive element reaches the accessibility tree with no discernible
 * accessible name — an AI agent (or a screen reader) then sees "link" / "combo
 * box" and cannot tell what it does. Two real offenders on client sites:
 *
 *   1. `link-name`   — Spectra/UAGB's container link overlay:
 *                      `<a class="spectra-container-link-overlay" href="/wohnkonzept/"></a>`
 *                      an empty anchor stretched over a whole container. Zero text,
 *                      no aria-label, no image inside it.
 *   2. `select-name` — Contact Form 7's `<select class="wpcf7-select" name="select-796">`.
 *                      The visible "Leistungsart auswählen*" cue is the first
 *                      `<option>` — a placeholder, which the accname spec does NOT
 *                      count as an accessible name.
 *
 * WHY A RENDER-TIME FILTER AND NOT A post_content REWRITE
 * ------------------------------------------------------
 * Neither element exists in `post_content`. The Spectra overlay is emitted by the
 * block's `render_block` callback from block attributes; the CF7 control is
 * expanded from a `[select …]` shortcode at request time. `post_content` holds
 * `<!-- wp:uagb/container {…} -->` and `[contact-form-7 id="…"]` respectively.
 * The stored-data fix model used by `image_alt` / `broken_link` (regex over
 * post_content, write back with wp_update_post) therefore CANNOT reach these —
 * there is nothing to rewrite. The fix is a site-wide option flag plus the two
 * targeted output filters below, which name the elements as they are rendered.
 *
 * WHY REGEX AND NOT DOMDocument
 * -----------------------------
 * Same reason the broken-link fix documents at length: DOMDocument mangles the
 * hand-authored HTML WordPress ships (UTF-8 without an explicit encoding hint,
 * self-closing tags, HTML comments — including the block delimiters themselves).
 * Boundary-safe regex that only ever *inserts an attribute before the closing
 * `>` of an opening tag* is the established house style and cannot restructure
 * the document. Whole-page output buffering (`ob_start`) is deliberately not used
 * either: it would put this plugin in the path of every byte the site emits.
 *
 * SAFETY CONTRACT
 * ---------------
 * This class only ever ADDS an `aria-label` attribute to an element that has no
 * accessible name at all. It never rewrites, reorders, or removes markup, never
 * touches visible text, and never adds an empty label (an `aria-label=""` is
 * worse than none). Rendered output is byte-identical apart from the inserted
 * attributes. Because an element that already has `aria-label` is skipped, the
 * filters are naturally idempotent — running them twice adds nothing the second
 * time.
 *
 * Known regex limitation, shared with the rest of the plugin's HTML handling: an
 * attribute value containing a literal `>` (`<a title="a>b">`) ends the tag match
 * early. Such markup is vanishingly rare and the failure mode is "we skip the
 * element", never "we corrupt it".
 *
 * Gated site-wide on the `seonix_agent_a11y_enabled` option, which the
 * `agent_accessibility` SEO-fix method flips. Off by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_Agent_Accessibility {

	/** Site-wide toggle written by Seonix_Fix_Agent_Accessibility. */
	const OPTION = 'seonix_agent_a11y_enabled';

	/**
	 * Input types that are exempt from `select-name` / `input-name`: they either
	 * never reach the a11y tree (hidden) or take their name from their own value
	 * / alt attribute (submit, button, reset, image).
	 */
	const SKIP_INPUT_TYPES = array( 'hidden', 'submit', 'button', 'reset', 'image' );

	/**
	 * Hook the two render-time filters.
	 *
	 * `render_block` covers block-generated anchors (Spectra overlays and any
	 * other empty anchor a theme/plugin block emits). `wpcf7_form_elements` is
	 * Contact Form 7's filter over the expanded form controls — the only place
	 * the `<select>` exists as HTML before it reaches the browser.
	 */
	public function register(): void {
		add_filter( 'render_block', array( $this, 'filter_render_block' ), 20, 2 );
		add_filter( 'wpcf7_form_elements', array( $this, 'filter_cf7_form_elements' ), 20 );
	}

	// ─── render_block: anchors ───────────────────────────────────────────

	/**
	 * Give every unnamed anchor in a rendered block an aria-label.
	 *
	 * Scope note: the label is derived from the WHOLE block's rendered content,
	 * not just the anchor's own inner HTML. That is the entire point for the
	 * Spectra overlay — the anchor is empty precisely because it is a sibling of
	 * the container's heading and image, and those are what it points at. Inner
	 * blocks render before their parent, so by the time the container block
	 * reaches this filter its heading is present in $block_content.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block (unused; kept for the filter signature).
	 * @return string
	 */
	public function filter_render_block( $block_content, $block = array() ) {
		unset( $block );

		if ( ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}

		// Cheap bail: the overwhelming majority of blocks have no anchor at all.
		if ( false === stripos( $block_content, '<a' ) ) {
			return $block_content;
		}

		return $this->name_anchors( $block_content );
	}

	/**
	 * Insert aria-label into each anchor that has no accessible name.
	 *
	 * Every capture group is re-emitted verbatim, so the closing tag's original
	 * spelling (`</a>` vs `</a >`) and the attribute string survive untouched;
	 * the only edit is the inserted attribute at the end of the attribute list.
	 * The attrs group is split on its trailing whitespace for the same reason
	 * insert_attr() rtrim()s — so `<a href="x" >` gains ` aria-label="y"` before
	 * that space rather than after it, and never doubles it.
	 */
	private function name_anchors( string $html ): string {
		$anchors = $this->scan_anchors( $html );
		if ( 0 === $anchors['unnamed'] ) {
			return $html;
		}

		// A heading/image may only name an anchor when it is the block's ONLY
		// anchor. That is the condition under which the association is certain:
		// with one link in the fragment, any heading in it unambiguously belongs
		// to that link. The moment a second anchor exists — named or not — "the
		// first heading in the block" is a guess about which link it describes.
		//
		// This must key off the TOTAL anchor count, not the unnamed count. Blocks
		// are filtered inner-first and a parent receives its children's already
		// filtered HTML, so by the time a card grid is filtered its cards are
		// named and only the ones we declined to label (href="/", "#", …) are
		// still unnamed. Counting only those would leave exactly one "unnamed"
		// anchor in a grid full of cards and hand it the FIRST card's heading —
		// an actively wrong name on the wrong destination, which is worse than
		// the missing name we set out to fix.
		//
		// The common case still takes the rich path: a Spectra card container is
		// filtered on its own, holding one overlay and its own heading, long
		// before the grid wrapping it is.
		$borrow_block_context = ( 1 === $anchors['total'] );

		$out = preg_replace_callback(
			'#(<a\b)([^>]*?)(/?>)(.*?)(</a\s*>)#is',
			function ( $m ) use ( $html, $borrow_block_context ) {
				$attrs = $m[2];
				$inner = $m[4];

				if ( ! $this->anchor_needs_name( $attrs, $inner ) ) {
					return $m[0];
				}

				$label = $this->derive_anchor_label( $attrs, $html, $borrow_block_context );
				if ( '' === $label ) {
					// Nothing meaningful to say — leave the markup alone rather
					// than inventing a label or emitting an empty one.
					return $m[0];
				}

				$kept     = rtrim( $attrs );
				$trailing = substr( $attrs, strlen( $kept ) );
				$addition = ' aria-label="' . esc_attr( $label ) . '"';

				return $m[1] . $kept . $addition . $trailing . $m[3] . $m[4] . $m[5];
			},
			$html
		);

		// preg_replace_callback returns null on backtrack-limit exhaustion (a
		// pathologically large block); fall back to the untouched input.
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Count the anchors in a block, and how many of them would reach the a11y
	 * tree unnamed.
	 *
	 * @return array{total:int,unnamed:int}
	 */
	private function scan_anchors( string $html ): array {
		if ( ! preg_match_all( '#<a\b([^>]*?)(/?>)(.*?)</a\s*>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return array(
				'total'   => 0,
				'unnamed' => 0,
			);
		}

		$unnamed = 0;
		foreach ( $matches as $m ) {
			if ( $this->anchor_needs_name( $m[1], $m[3] ) ) {
				$unnamed++;
			}
		}

		return array(
			'total'   => count( $matches ),
			'unnamed' => $unnamed,
		);
	}

	/**
	 * True when the anchor would land in the a11y tree with no name.
	 *
	 * Mirrors the accname resolution order Lighthouse applies: aria-labelledby,
	 * aria-label, then text content (which includes a nested image's alt).
	 */
	private function anchor_needs_name( string $attrs, string $inner ): bool {
		if ( ! self::attrs_are_complete( $attrs ) ) {
			return false;
		}
		// aria-hidden elements are pruned from the a11y tree; naming them is noise.
		if ( 'true' === strtolower( trim( (string) self::get_attr( $attrs, 'aria-hidden' ) ) ) ) {
			return false;
		}
		if ( '' !== self::normalize_text( (string) self::get_attr( $attrs, 'aria-labelledby' ) ) ) {
			return false;
		}
		// Presence, not emptiness: we can only ADD attributes, so an element that
		// already carries aria-label (even aria-label="") must be left alone —
		// appending a second one would emit a duplicate attribute, which browsers
		// resolve to the FIRST occurrence, silently undoing the fix and producing
		// invalid HTML. An explicit aria-label="" is an authoring choice anyway.
		if ( null !== self::get_attr( $attrs, 'aria-label' ) ) {
			return false;
		}
		if ( '' !== self::normalize_text( (string) self::get_attr( $attrs, 'title' ) ) ) {
			return false;
		}
		// Visible text content names the link.
		if ( '' !== self::normalize_text( $inner ) ) {
			return false;
		}
		// So does an image INSIDE the anchor with a non-empty alt.
		if ( '' !== self::img_alt( $inner ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Deterministic label derivation, first match wins:
	 *
	 *   1. text of the first heading (h1–h6) in the same block — for a Spectra
	 *      container overlay this is the card's own title, i.e. exactly what a
	 *      human reads as the link's name;
	 *   2. non-empty alt of an image in the same block — image cards with no
	 *      heading;
	 *   3. humanized last path segment of href ("/wohnkonzept/" → "Wohnkonzept").
	 *
	 * Note that (2) reads the whole block, whereas the nested-image check in
	 * anchor_needs_name() reads only the anchor's own inner HTML. That is not a
	 * contradiction: an image inside the anchor already NAMES it (so we skip), an
	 * image beside the anchor merely DESCRIBES what the overlay covers (so we
	 * borrow its alt).
	 *
	 * @param bool $borrow_block_context Whether (1) and (2) — which read the whole
	 *                                   block and would therefore name every
	 *                                   anchor in it identically — are safe to use.
	 *                                   See name_anchors().
	 */
	private function derive_anchor_label( string $attrs, string $block_html, bool $borrow_block_context = true ): string {
		if ( $borrow_block_context ) {
			$heading = self::first_heading_text( $block_html );
			if ( '' !== $heading ) {
				return $heading;
			}

			$alt = self::img_alt( $block_html );
			if ( '' !== $alt ) {
				return $alt;
			}
		}

		return self::humanize_href( (string) self::get_attr( $attrs, 'href' ) );
	}

	// ─── wpcf7_form_elements: form controls ──────────────────────────────

	/**
	 * Give every unnamed CF7 control an aria-label.
	 *
	 * CF7 hands this filter the expanded form controls (the inner HTML of the
	 * <form>, not the <form> tag itself).
	 *
	 * @param string $elements Rendered CF7 form controls.
	 * @return string
	 */
	public function filter_cf7_form_elements( $elements ) {
		if ( ! is_string( $elements ) || '' === $elements ) {
			return $elements;
		}

		$elements = $this->name_selects( $elements );
		$elements = $this->name_void_or_open_tag( $elements, 'input' );
		$elements = $this->name_void_or_open_tag( $elements, 'textarea' );

		return $elements;
	}

	/**
	 * <select> needs its options to derive a label, so it gets its own pass over
	 * the full element (open tag … </select>).
	 */
	private function name_selects( string $html ): string {
		$found = preg_match_all(
			'#<select\b([^>]*?)(/?)>(.*?)</select\s*>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return $html;
		}

		$labels = self::label_index( $html );

		// Right-to-left: each insertion shifts every offset to its right, so
		// walking backwards keeps the not-yet-used offsets valid.
		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$attrs      = $matches[ $i ][1][0];
			$inner      = $matches[ $i ][3][0];
			$tag_offset = $matches[ $i ][0][1];

			if ( ! self::control_needs_name( $attrs, $tag_offset, $labels ) ) {
				continue;
			}

			$label = self::derive_field_label( 'select', $attrs, $inner );
			if ( '' === $label ) {
				continue;
			}

			$html = self::insert_attr( $html, $tag_offset, 'select', $attrs, 'aria-label', $label );
		}

		return $html;
	}

	/**
	 * Shared pass for <input …> (void, may be self-closing) and <textarea …>.
	 * Only the opening tag matters — neither derives its label from its content.
	 */
	private function name_void_or_open_tag( string $html, string $tag ): string {
		$found = preg_match_all(
			'#<' . $tag . '\b([^>]*?)(/?)>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return $html;
		}

		$labels = self::label_index( $html );

		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$attrs      = $matches[ $i ][1][0];
			$tag_offset = $matches[ $i ][0][1];

			if ( ! self::control_needs_name( $attrs, $tag_offset, $labels ) ) {
				continue;
			}

			$label = self::derive_field_label( $tag, $attrs );
			if ( '' === $label ) {
				continue;
			}

			$html = self::insert_attr( $html, $tag_offset, $tag, $attrs, 'aria-label', $label );
		}

		return $html;
	}

	/**
	 * True when a form control would reach the a11y tree unnamed.
	 *
	 * `placeholder` is deliberately NOT consulted here: per the accname spec it
	 * is a last-resort fallback that Lighthouse's select-name/input-name audits
	 * do not accept, so a placeholder-only field still fails and still needs us.
	 * (Its TEXT is a fine source for the label we synthesise — see
	 * derive_field_label() — it just isn't a name on its own.)
	 */
	private static function control_needs_name( string $attrs, int $offset, array $labels ): bool {
		if ( ! self::attrs_are_complete( $attrs ) ) {
			return false;
		}
		$type = strtolower( trim( (string) self::get_attr( $attrs, 'type' ) ) );
		if ( in_array( $type, self::SKIP_INPUT_TYPES, true ) ) {
			return false;
		}
		if ( 'true' === strtolower( trim( (string) self::get_attr( $attrs, 'aria-hidden' ) ) ) ) {
			return false;
		}
		if ( '' !== self::normalize_text( (string) self::get_attr( $attrs, 'aria-labelledby' ) ) ) {
			return false;
		}
		// Presence, not emptiness — see anchor_needs_name().
		if ( null !== self::get_attr( $attrs, 'aria-label' ) ) {
			return false;
		}
		if ( '' !== self::normalize_text( (string) self::get_attr( $attrs, 'title' ) ) ) {
			return false;
		}

		return '' === self::associated_label_text( $attrs, $offset, $labels );
	}

	// ─── Label derivation ────────────────────────────────────────────────

	/**
	 * Text of the <label> that names this control, or '' when there is none.
	 *
	 * Both association forms count: a wrapping `<label> Ihr Name* [input] </label>`
	 * (CF7's default markup) and an explicit `<label for="id">`. A label with no
	 * text of its own names nothing, so it does not count.
	 *
	 * @param array $labels Index from label_index().
	 */
	public static function associated_label_text( string $attrs, int $offset, array $labels ): string {
		foreach ( $labels['spans'] as $span ) {
			if ( $offset > $span['start'] && $offset < $span['end'] && '' !== $span['text'] ) {
				return $span['text'];
			}
		}

		$id = trim( (string) self::get_attr( $attrs, 'id' ) );
		if ( '' !== $id && isset( $labels['for'][ $id ] ) ) {
			return $labels['for'][ $id ];
		}

		return '';
	}

	/**
	 * Map the <label> elements in a form once, so each control can be tested
	 * against them without re-scanning.
	 *
	 * Returns:
	 *   spans => list of { start, end, text } byte offsets of each label element
	 *   for   => map of for="…" target id → that label's text
	 *
	 * Labels are not nested in practice, so the non-greedy match to the first
	 * `</label>` is correct.
	 */
	public static function label_index( string $html ): array {
		$spans = array();
		$for   = array();

		$found = preg_match_all(
			'#<label\b([^>]*?)(/?)>(.*?)</label\s*>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return array(
				'spans' => $spans,
				'for'   => $for,
			);
		}

		foreach ( $matches as $m ) {
			$start = $m[0][1];
			$text  = self::strip_required_marker( self::normalize_text( self::label_own_text( $m[3][0] ) ) );

			$spans[] = array(
				'start' => $start,
				'end'   => $start + strlen( $m[0][0] ),
				'text'  => $text,
			);

			$target = trim( (string) self::get_attr( $m[1][0], 'for' ) );
			if ( '' !== $target && '' !== $text ) {
				$for[ $target ] = $text;
			}
		}

		return array(
			'spans' => $spans,
			'for'   => $for,
		);
	}

	/**
	 * A wrapping label's OWN text, with the content of any control it encloses
	 * removed.
	 *
	 * normalize_text() strips tags but keeps the text between them, so a
	 * `<label>Leistungsart<select><option>Montage</option></select></label>`
	 * would otherwise read as "Leistungsart Montage" — the label's words plus
	 * whichever option happened to come first. Dropping the enclosed controls
	 * wholesale leaves just the author's label text.
	 */
	private static function label_own_text( string $inner ): string {
		$stripped = preg_replace(
			array(
				'#<select\b[^>]*>.*?</select\s*>#is',
				'#<textarea\b[^>]*>.*?</textarea\s*>#is',
				'#<button\b[^>]*>.*?</button\s*>#is',
			),
			' ',
			$inner
		);

		return is_string( $stripped ) ? $stripped : $inner;
	}

	/**
	 * Deterministic label for a form control, first match wins:
	 *
	 *   1. a <select>'s first non-empty <option> — CF7 authors put the visible
	 *      cue there ("Leistungsart auswählen*"), which is what a sighted user
	 *      reads as the field's name;
	 *   2. the placeholder's text;
	 *   3. the humanized name attribute, but only when the site owner chose it
	 *      ("your-name" → "Your Name").
	 *
	 * CF7 auto-generates names like `select-796` / `text-12` when the author does
	 * not supply one. Humanizing those yields "Select 796" — literally worse than
	 * silence for a screen-reader user, and a false pass for the audit. We return
	 * '' instead and leave the control untouched.
	 *
	 * The trailing required-marker asterisk is stripped: "Leistungsart auswählen*"
	 * is a visual convention, not part of the field's name (the control carries
	 * `aria-required` / `required` for that).
	 */
	public static function derive_field_label( string $tag, string $attrs, string $inner = '' ): string {
		if ( 'select' === $tag && '' !== $inner ) {
			$option = self::first_option_text( $inner );
			if ( '' !== $option ) {
				return $option;
			}
		}

		$placeholder = self::strip_required_marker(
			self::normalize_text( (string) self::get_attr( $attrs, 'placeholder' ) )
		);
		if ( '' !== $placeholder ) {
			return $placeholder;
		}

		$name = trim( (string) self::get_attr( $attrs, 'name' ) );
		if ( '' === $name || self::is_auto_generated_name( $name ) ) {
			return '';
		}

		return self::humanize_slug( $name );
	}

	/**
	 * Text of the first <option> that actually says something. CF7's
	 * `include_blank` emits an empty leading option; skip past it.
	 */
	public static function first_option_text( string $inner ): string {
		if ( ! preg_match_all( '#<option\b[^>]*>(.*?)</option\s*>#is', $inner, $matches ) ) {
			return '';
		}
		foreach ( $matches[1] as $raw ) {
			$text = self::strip_required_marker( self::normalize_text( $raw ) );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * CF7's auto-generated field names: the tag type followed by a number.
	 * See wpcf7_get_unique_field_name() — "select-796", "text-12", "menu-3".
	 */
	private static function is_auto_generated_name( string $name ): bool {
		return (bool) preg_match(
			'/^(text|email|url|tel|number|range|date|textarea|menu|select|checkbox|radio|acceptance|quiz|file|submit)-\d+$/i',
			$name
		);
	}

	// ─── Primitives (also reused by Seonix_WebMCP) ───────────────────────

	/**
	 * True when $attrs is a complete attribute list rather than one truncated
	 * mid-value.
	 *
	 * `[^>]*?` stops at the FIRST `>`, so markup with an unescaped `>` inside a
	 * quoted value — `<input title="a>b">` — matches with $attrs cut to
	 * ` title="a`. Left unchecked, the insertion offset then lands inside the
	 * still-open `title` value and rewrites the tag into nonsense. That would
	 * break this class's "we skip the element, never corrupt it" contract, which
	 * the anchor path upholds only incidentally (the truncated remainder reads as
	 * visible text, so the anchor looks named and is skipped).
	 *
	 * Detection: drop every properly quoted value, then look for a leftover
	 * quote. Nothing survives a well-formed list; a truncated one leaves the
	 * opening quote of the value it was cut inside. Apostrophes in double-quoted
	 * values ("Nom d'utilisateur") are removed with their value and so do not
	 * produce a false positive.
	 */
	public static function attrs_are_complete( string $attrs ): bool {
		$without_values = preg_replace( '#"[^"]*"|\'[^\']*\'#', '', $attrs );
		if ( ! is_string( $without_values ) ) {
			return false;
		}

		return false === strpos( $without_values, '"' ) && false === strpos( $without_values, "'" );
	}

	/**
	 * Read one attribute out of a raw attribute string.
	 *
	 * The `(?:^|\s)` prefix is load-bearing: it stops a lookup for `label` from
	 * matching inside `aria-label`, and `title` from matching `data-title`.
	 * Returns null when absent, '' when present but empty.
	 */
	public static function get_attr( string $attrs, string $name ): ?string {
		$pattern = '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';
		if ( ! preg_match( $pattern, $attrs, $m ) ) {
			return null;
		}
		if ( isset( $m[1] ) && '' !== $m[1] ) {
			return $m[1];
		}
		if ( isset( $m[2] ) && '' !== $m[2] ) {
			return $m[2];
		}
		if ( isset( $m[3] ) && '' !== $m[3] ) {
			return $m[3];
		}
		return '';
	}

	/**
	 * Insert an attribute at the end of an opening tag's attribute list.
	 *
	 * The insertion point is the last non-whitespace character of $attrs, NOT the
	 * end of $attrs. That detail is what keeps the rest of the tag byte-identical:
	 * `<input … placeholder="x" />` matches with a trailing space in $attrs, so
	 * appending at strlen($attrs) would yield `placeholder="x"  aria-label="y"/>`
	 * — a doubled space and a self-closing slash shoved against the new attribute.
	 * Anchoring to rtrim() instead gives `placeholder="x" aria-label="y" />`,
	 * leaving the author's trailing whitespace and the ` />` intact.
	 *
	 * @param int    $tag_offset Byte offset of the tag's `<`.
	 * @param string $tag_name   Tag name, used to compute the attribute region.
	 * @param string $attrs      The tag's raw attribute string, as matched.
	 */
	public static function insert_attr( string $html, int $tag_offset, string $tag_name, string $attrs, string $attr_name, string $value ): string {
		$insert_at = $tag_offset + 1 + strlen( $tag_name ) + strlen( rtrim( $attrs ) );
		$addition  = ' ' . $attr_name . '="' . esc_attr( $value ) . '"';

		return substr( $html, 0, $insert_at ) . $addition . substr( $html, $insert_at );
	}

	/**
	 * Flatten markup to the text a user would actually perceive: tags out,
	 * entities decoded, invisible characters dropped, whitespace collapsed.
	 * Mirrors Seonix_LLMTxt::clean_text — an anchor holding only `&nbsp;` or a
	 * zero-width space is empty as far as the a11y tree is concerned, and must
	 * not be mistaken for a named link.
	 */
	public static function normalize_text( string $html ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() is what actually runs on a WordPress site; the strip_tags() branch exists only for the unit tests, which load this class without WordPress.
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Zero-width space / non-joiner / joiner / BOM / soft hyphen.
		$text = str_replace(
			array( "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF", "\xC2\xAD" ),
			'',
			$text
		);
		// Non-breaking space behaves as whitespace for naming purposes.
		$text = str_replace( "\xC2\xA0", ' ', $text );

		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $collapsed ) ? $collapsed : $text );
	}

	/** Alt text of the first image carrying a non-empty alt, or ''. */
	public static function img_alt( string $html ): string {
		if ( ! preg_match_all( '#<img\b([^>]*)>#is', $html, $matches ) ) {
			return '';
		}
		foreach ( $matches[1] as $attrs ) {
			$alt = self::normalize_text( (string) self::get_attr( $attrs, 'alt' ) );
			if ( '' !== $alt ) {
				return $alt;
			}
		}
		return '';
	}

	/** Text of the first h1–h6 in the markup, or ''. */
	public static function first_heading_text( string $html ): string {
		if ( ! preg_match( '#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is', $html, $m ) ) {
			return '';
		}
		return self::normalize_text( $m[2] );
	}

	/**
	 * Humanize an href into a link name: last path segment, decoded and
	 * title-cased. "/wohnkonzept/" → "Wohnkonzept".
	 *
	 * Returns '' — meaning "we have nothing honest to say" — for in-page anchors,
	 * script/mail/tel schemes, the site root, and purely numeric segments
	 * (/page/2), rather than emitting a meaningless label.
	 */
	public static function humanize_href( string $href ): string {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] ) {
			return '';
		}
		if ( preg_match( '#^(javascript|mailto|tel|data):#i', $href ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $href, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}

		$segments = array_values(
			array_filter(
				explode( '/', $path ),
				static function ( $segment ) {
					return '' !== $segment;
				}
			)
		);
		if ( empty( $segments ) ) {
			return '';
		}

		$slug = urldecode( (string) end( $segments ) );
		// Drop a file extension: "/kontakt.html" → "kontakt".
		$slug = (string) preg_replace( '/\.(html?|php|aspx?)$/i', '', $slug );

		if ( preg_match( '/^\d+$/', $slug ) ) {
			return '';
		}

		return self::humanize_slug( $slug );
	}

	/** "unsere-leistungen" → "Unsere Leistungen". */
	public static function humanize_slug( string $slug ): string {
		$words = str_replace( array( '-', '_', '+', '.' ), ' ', $slug );
		$words = (string) preg_replace( '/\s+/u', ' ', $words );
		$words = trim( $words );
		if ( '' === $words ) {
			return '';
		}
		return self::title_case( $words );
	}

	/**
	 * Title-case that survives non-ASCII. ucwords() is byte-based and would
	 * mangle a leading multibyte character, so prefer mbstring when present.
	 */
	private static function title_case( string $text ): string {
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $text, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $text );
	}

	/**
	 * Strip a trailing required-marker asterisk ("Ihr Name*" → "Ihr Name").
	 * The /u flag returns null on malformed UTF-8; fall back to the input.
	 */
	public static function strip_required_marker( string $text ): string {
		$out = preg_replace( '/\s*\*+\s*$/u', '', $text );
		return trim( is_string( $out ) ? $out : $text );
	}
}
