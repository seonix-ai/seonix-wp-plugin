<?php
/**
 * Declarative WebMCP annotations for forms.
 *
 * WebMCP (the W3C "Web Model Context Protocol" explainer) lets a page describe
 * its own interactive affordances to an AI agent, so the agent can use a form as
 * a tool instead of guessing at the DOM. The declarative half of the proposal is
 * pure markup — no script, no registration:
 *
 *   <form toolname="search-cars" tooldescription="Perform a car make/model search">
 *     <input type="text" name="make" toolparamdescription="The vehicle's make (e.g. BMW)" required>
 *   </form>
 *
 * We emit ONLY those attributes. There is deliberately no JavaScript
 * `navigator.modelContext` registration: that is the imperative half of the
 * proposal, it ships behind a flag, its API is still moving, and it would mean
 * putting a script on every page with a form. Unknown attributes are inert in
 * every browser that does not implement WebMCP — they do not render, do not
 * validate, and do not affect submission — so the risk of shipping this is zero
 * while the upside (agents can drive the site's forms) arrives the moment a
 * browser supports it.
 *
 * WHERE THE ATTRIBUTES GO, AND WHY IT TAKES TWO FILTERS
 * ----------------------------------------------------
 * Contact Form 7 builds `<form …>` and its inner controls in two separate
 * places, exposed by two separate filters:
 *
 *   - `wpcf7_form_additional_atts` → attributes ON the <form> tag. CF7 merges the
 *     returned array into its own atts (`$atts += (array) apply_filters(…)`), then
 *     renders them through wpcf7_format_atts(), which applies esc_attr() to every
 *     value. Values returned from this filter must therefore be RAW — escaping
 *     them here would double-encode.
 *   - `wpcf7_form_elements` → the inner HTML only; the <form> tag is NOT in it.
 *     This is a raw HTML string we edit directly, so values inserted here MUST be
 *     escaped by us (Seonix_Agent_Accessibility::insert_attr does it).
 *
 * Those two escaping contexts are opposite. Getting them backwards yields either
 * `toolname="search &amp;amp; filter"` or an attribute-injection hole, so each
 * call site below states which rule it is under.
 *
 * `core/search` is handled through `render_block` instead: its <form> IS part of
 * the rendered block, so one filter covers both the form and its input.
 *
 * HTML parsing primitives (attribute reads, label association, deterministic
 * field-label derivation) are shared with Seonix_Agent_Accessibility rather than
 * duplicated — see the static helpers on that class. Both files are always
 * loaded by the bootstrap, so the dependency is safe even when only one of the
 * two features is toggled on.
 *
 * Gated site-wide on the `seonix_webmcp_enabled` option, which the
 * `agent_webmcp` SEO-fix method flips. Off by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seonix_WebMCP {

	/** Site-wide toggle written by Seonix_Fix_Agent_WebMCP. */
	const OPTION = 'seonix_webmcp_enabled';

	/** Fallback tool name when the form has no usable title. */
	const DEFAULT_CF7_TOOL_NAME = 'contact-form';

	/**
	 * Hook the form annotators.
	 *
	 * Priorities sit above Seonix_Agent_Accessibility's (20) so that, when both
	 * features are on, the a11y pass has already added its aria-labels. The two
	 * passes touch disjoint attributes, so ordering is a determinism nicety
	 * rather than a correctness requirement.
	 */
	public function register(): void {
		add_filter( 'wpcf7_form_additional_atts', array( $this, 'filter_cf7_form_atts' ), 20 );
		add_filter( 'wpcf7_form_elements', array( $this, 'filter_cf7_form_elements' ), 30 );
		add_filter( 'render_block', array( $this, 'filter_render_block' ), 30, 2 );
	}

	// ─── Contact Form 7: the <form> tag ──────────────────────────────────

	/**
	 * Describe the CF7 form as a tool.
	 *
	 * ESCAPING: values returned here are RAW. CF7 runs them through
	 * wpcf7_format_atts(), which esc_attr()s every value.
	 *
	 * CF7 merges with `+=` (array union), so pre-existing keys always win and we
	 * can only ever ADD attributes — we cannot clobber CF7's own action/method/
	 * class/aria-label. The isset() guards keep us off any key another plugin
	 * filtered in ahead of us.
	 *
	 * @param mixed $atts Attribute map (CF7 passes an empty array).
	 * @return mixed
	 */
	public function filter_cf7_form_atts( $atts ) {
		if ( ! is_array( $atts ) ) {
			return $atts;
		}

		$title = $this->current_cf7_title();

		if ( ! isset( $atts['toolname'] ) ) {
			$atts['toolname'] = $this->cf7_tool_name( $title );
		}
		if ( ! isset( $atts['tooldescription'] ) ) {
			$atts['tooldescription'] = $this->cf7_tool_description( $title );
		}

		return $atts;
	}

	/**
	 * Title of the CF7 form currently rendering, or ''.
	 *
	 * WPCF7_ContactForm::get_current() is CF7's public accessor for the form in
	 * flight; the filter itself carries no form context.
	 */
	private function current_cf7_title(): string {
		if ( ! class_exists( 'WPCF7_ContactForm' ) || ! is_callable( array( 'WPCF7_ContactForm', 'get_current' ) ) ) {
			return '';
		}

		$form = call_user_func( array( 'WPCF7_ContactForm', 'get_current' ) );
		if ( ! $form || ! is_callable( array( $form, 'title' ) ) ) {
			return '';
		}

		return Seonix_Agent_Accessibility::normalize_text( (string) $form->title() );
	}

	/**
	 * Slug of the form's title ("Kontaktformular 1" → "kontaktformular-1"), or
	 * the generic fallback.
	 *
	 * A tool name is an identifier an agent matches on, so it must be a clean
	 * ASCII slug. sanitize_title() transliterates accents for us, but for a title
	 * with no latin characters at all it returns percent-encoded bytes
	 * ("%e3%81%8a…") — useless as a tool name. The final shape check catches that
	 * and falls back rather than emitting garbage.
	 */
	private function cf7_tool_name( string $title ): string {
		if ( '' === $title ) {
			return self::DEFAULT_CF7_TOOL_NAME;
		}

		$slug = '';
		if ( function_exists( 'sanitize_title' ) ) {
			$slug = (string) sanitize_title( $title );
		}
		if ( '' === $slug ) {
			$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
		}

		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return self::DEFAULT_CF7_TOOL_NAME;
		}

		return $slug;
	}

	/** Human sentence telling an agent what submitting this form does. */
	private function cf7_tool_description( string $title ): string {
		if ( '' === $title ) {
			return 'Submit the contact form on this site.';
		}
		return sprintf( 'Submit the "%s" contact form on this site.', $title );
	}

	// ─── Contact Form 7: the fields ──────────────────────────────────────

	/**
	 * Annotate each CF7 control with a description of what it expects.
	 *
	 * ESCAPING: this is a raw HTML string, so insert_attr() esc_attr()s the value.
	 *
	 * @param string $elements Rendered CF7 form controls (inner HTML of <form>).
	 * @return string
	 */
	public function filter_cf7_form_elements( $elements ) {
		if ( ! is_string( $elements ) || '' === $elements ) {
			return $elements;
		}

		$elements = $this->describe_selects( $elements );
		$elements = $this->describe_open_tag( $elements, 'input' );
		$elements = $this->describe_open_tag( $elements, 'textarea' );

		return $elements;
	}

	/** <select> pass — needs the options to derive a description. */
	private function describe_selects( string $html ): string {
		$found = preg_match_all(
			'#<select\b([^>]*?)(/?)>(.*?)</select\s*>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return $html;
		}

		$labels = Seonix_Agent_Accessibility::label_index( $html );

		// Right-to-left so each insertion cannot invalidate a pending offset.
		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$attrs      = $matches[ $i ][1][0];
			$inner      = $matches[ $i ][3][0];
			$tag_offset = $matches[ $i ][0][1];

			if ( ! self::field_needs_description( $attrs ) ) {
				continue;
			}

			$description = self::derive_field_description( 'select', $attrs, $inner, $tag_offset, $labels );
			if ( '' === $description ) {
				continue;
			}

			$html = Seonix_Agent_Accessibility::insert_attr(
				$html,
				$tag_offset,
				'select',
				$attrs,
				'toolparamdescription',
				$description
			);
		}

		return $html;
	}

	/** Shared pass for <input …> and <textarea …>. */
	private function describe_open_tag( string $html, string $tag ): string {
		$found = preg_match_all(
			'#<' . $tag . '\b([^>]*?)(/?)>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return $html;
		}

		$labels = Seonix_Agent_Accessibility::label_index( $html );

		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$attrs      = $matches[ $i ][1][0];
			$tag_offset = $matches[ $i ][0][1];

			if ( ! self::field_needs_description( $attrs ) ) {
				continue;
			}

			$description = self::derive_field_description( $tag, $attrs, '', $tag_offset, $labels );
			if ( '' === $description ) {
				continue;
			}

			$html = Seonix_Agent_Accessibility::insert_attr(
				$html,
				$tag_offset,
				$tag,
				$attrs,
				'toolparamdescription',
				$description
			);
		}

		return $html;
	}

	/**
	 * A field wants a param description unless it is not a parameter at all
	 * (hidden/submit/button/reset/image) or something already described it.
	 *
	 * Unlike the a11y pass, a field that HAS a label still gets annotated — a
	 * label names the field for a human, `toolparamdescription` explains it to an
	 * agent. Only the presence of the attribute itself makes this a no-op, which
	 * is what keeps the filter idempotent.
	 */
	private static function field_needs_description( string $attrs ): bool {
		// Truncated attribute list (an unescaped `>` inside a quoted value) —
		// inserting would land inside that value. Skip, never corrupt.
		if ( ! Seonix_Agent_Accessibility::attrs_are_complete( $attrs ) ) {
			return false;
		}
		if ( null !== Seonix_Agent_Accessibility::get_attr( $attrs, 'toolparamdescription' ) ) {
			return false;
		}

		$type = strtolower( trim( (string) Seonix_Agent_Accessibility::get_attr( $attrs, 'type' ) ) );

		return ! in_array( $type, Seonix_Agent_Accessibility::SKIP_INPUT_TYPES, true );
	}

	/**
	 * Deterministic param description, first match wins:
	 *
	 *   1. the field's own <label> text — the site owner's words, the best
	 *      description available;
	 *   2. …otherwise whatever names the field visually: a <select>'s first
	 *      option, the placeholder, or a human-authored name attribute
	 *      (Seonix_Agent_Accessibility::derive_field_label covers all three and
	 *      refuses CF7's auto-generated "select-796" style names).
	 *
	 * @param array $labels Index from Seonix_Agent_Accessibility::label_index().
	 */
	private static function derive_field_description( string $tag, string $attrs, string $inner, int $offset, array $labels ): string {
		$label = Seonix_Agent_Accessibility::associated_label_text( $attrs, $offset, $labels );
		if ( '' !== $label ) {
			return $label;
		}

		return Seonix_Agent_Accessibility::derive_field_label( $tag, $attrs, $inner );
	}

	// ─── core/search ─────────────────────────────────────────────────────

	/**
	 * Annotate the core search block. Its <form> is part of the block's rendered
	 * output, so one filter covers the form tag and its input.
	 *
	 * ESCAPING: raw HTML string → insert_attr() escapes.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public function filter_render_block( $block_content, $block = array() ) {
		if ( ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}

		$block_name = isset( $block['blockName'] ) ? $block['blockName'] : '';
		if ( 'core/search' !== $block_name ) {
			return $block_content;
		}
		if ( false === stripos( $block_content, '<form' ) ) {
			return $block_content;
		}

		$block_content = $this->describe_search_form( $block_content );
		$block_content = $this->describe_search_input( $block_content );

		return $block_content;
	}

	/** Add toolname/tooldescription to the search block's <form>. */
	private function describe_search_form( string $html ): string {
		if ( ! preg_match( '#<form\b([^>]*?)(/?)>#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$attrs      = $m[1][0];
		$tag_offset = $m[0][1];

		if ( ! Seonix_Agent_Accessibility::attrs_are_complete( $attrs ) ) {
			return $html;
		}
		// Idempotency: already annotated (by us on a previous pass, or by the theme).
		if ( null !== Seonix_Agent_Accessibility::get_attr( $attrs, 'toolname' ) ) {
			return $html;
		}

		// tooldescription first: inserting it does not move the tag's start
		// offset, but it does change $attrs' length — so re-match for the second
		// insertion rather than reusing a stale offset.
		$html = Seonix_Agent_Accessibility::insert_attr(
			$html,
			$tag_offset,
			'form',
			$attrs,
			'toolname',
			'site-search'
		);

		if ( ! preg_match( '#<form\b([^>]*?)(/?)>#is', $html, $m2, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		return Seonix_Agent_Accessibility::insert_attr(
			$html,
			$m2[0][1],
			'form',
			$m2[1][0],
			'tooldescription',
			"Search this site's content and return matching pages."
		);
	}

	/** Add toolparamdescription to the search block's query input. */
	private function describe_search_input( string $html ): string {
		$found = preg_match_all(
			'#<input\b([^>]*?)(/?)>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return $html;
		}

		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$attrs      = $matches[ $i ][1][0];
			$tag_offset = $matches[ $i ][0][1];

			if ( ! Seonix_Agent_Accessibility::attrs_are_complete( $attrs ) ) {
				continue;
			}
			$type = strtolower( trim( (string) Seonix_Agent_Accessibility::get_attr( $attrs, 'type' ) ) );
			if ( 'search' !== $type ) {
				continue;
			}
			if ( null !== Seonix_Agent_Accessibility::get_attr( $attrs, 'toolparamdescription' ) ) {
				continue;
			}

			$html = Seonix_Agent_Accessibility::insert_attr(
				$html,
				$tag_offset,
				'input',
				$attrs,
				'toolparamdescription',
				'The words to search this site for.'
			);
		}

		return $html;
	}
}
