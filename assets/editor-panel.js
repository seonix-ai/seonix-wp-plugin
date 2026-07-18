/**
 * Seonix — per-page audit panel in the block-editor document sidebar.
 *
 * Renders window.seonixAudit (localized by Seonix_Metabox::enqueue) as a
 * SEO-plugin-style panel in the editor's Document sidebar, reusing the .seonix-metabox
 * styles from admin.css. Pure wp.element (no JSX / build step). Fully defensive:
 * if any required editor API is missing it no-ops and never breaks the editor.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	// PluginDocumentSettingPanel / PluginSidebar / PluginSidebarMoreMenuItem all
	// moved from wp.editPost to wp.editor in WP 6.6, so look in both namespaces.
	var Panel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel ) ||
		null;
	var Sidebar =
		( wp.editor && wp.editor.PluginSidebar ) ||
		( wp.editPost && wp.editPost.PluginSidebar ) ||
		null;
	var SidebarMoreMenuItem =
		( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) ||
		( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem ) ||
		null;

	// Need at least one render target. The Sidebar is the primary, discoverable
	// entry point (its icon sits in the top toolbar, SEO-plugin-style); the document
	// Panel is the fallback / secondary location.
	if ( ! registerPlugin || ( ! Sidebar && ! Panel ) ) {
		return;
	}

	var data = window.seonixAudit || null;
	if ( ! data ) {
		return;
	}

	// Brand header: the Seonix mark + wordmark + one-line verdict. Mirrors the
	// the header row at the top of the SEO plugin's panel.
	//
	// The verdict line describes the last SITE SCAN ("6 issues on this page",
	// "Not scanned yet"), not the live score — the two answer different
	// questions and the gauges below already carry the score.
	function brandHeader() {
		return el(
			'div',
			{ className: 'sx-ph-head' },
			el( 'span', { className: 'sx-ph-logo' }, makeIcon( BRAND_EYES ) ),
			el(
				'div',
				{ className: 'sx-ph-headtext' },
				el( 'div', { className: 'sx-ph-brand' }, 'Seonix' ),
				data.verdict ? el( 'div', { className: 'sx-ph-sub' }, data.verdict ) : null
			)
		);
	}

	// Avoid → Better code snippets (mirrors the dashboard issue modal).
	function exampleBlock( iss ) {
		var rows = [];
		if ( iss.bad_example_code ) {
			rows.push( el( 'div', { className: 'sx-ex sx-ex--bad', key: 'bad' },
				el( 'div', { className: 'sx-ex-cap' }, iss.bad_example_caption || ( ( data.i18n && data.i18n.avoid ) || 'Avoid' ) ),
				el( 'pre', { className: 'sx-ex-code' }, iss.bad_example_code )
			) );
		}
		if ( iss.good_example_code ) {
			rows.push( el( 'div', { className: 'sx-ex sx-ex--good', key: 'good' },
				el( 'div', { className: 'sx-ex-cap' }, iss.good_example_caption || ( ( data.i18n && data.i18n.better ) || 'Better' ) ),
				el( 'pre', { className: 'sx-ex-code' }, iss.good_example_code )
			) );
		}
		return rows.length ? el( 'div', { className: 'sx-ex-wrap' }, rows ) : null;
	}

	// One issue: a clickable title row that expands to the full per-issue detail
	// (what / why it matters / avoid-vs-better example / how to fix / warnings) —
	// the same drill-down the dashboard shows in its issue modal.
	function Issue( props ) {
		var iss = props.iss;
		var steps = ( iss.how_to_fix_steps && iss.how_to_fix_steps.length ) ? iss.how_to_fix_steps : [];
		var warns = ( iss.warnings && iss.warnings.length ) ? iss.warnings : [];
		var hasDetail = !! ( iss.description || iss.why_it_matters || iss.recommendation || iss.bad_example_code || iss.good_example_code || steps.length || warns.length );
		var st = useState( false );
		var open = st[0];
		var setOpen = st[1];
		var head = el(
			'button',
			{
				type: 'button',
				className: 'sx-iss-head' + ( open ? ' is-open' : '' ),
				'aria-expanded': open ? 'true' : 'false',
				disabled: ! hasDetail,
				onClick: function () { if ( hasDetail ) { setOpen( ! open ); } }
			},
			el( 'span', { className: 'seonix-pagedot seonix-pagedot--' + iss.severity, 'aria-hidden': 'true' } ),
			el(
				'span',
				{ className: 'sx-iss-title' },
				iss.title,
				iss.informational ? el( 'span', { className: 'seonix-task__info', style: { marginLeft: '6px' } }, ( data.i18n && data.i18n.optional ) || 'Optional' ) : null
			),
			hasDetail
				? el( 'svg', { className: 'sx-iss-chev' + ( open ? ' is-open' : '' ), width: 14, height: 14, viewBox: '0 0 24 24', 'aria-hidden': 'true' }, el( 'path', { d: 'M7 10l5 5 5-5', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' } ) )
				: null
		);
		if ( ! open || ! hasDetail ) {
			return el( 'div', { className: 'sx-iss' }, head );
		}
		var detail = [];
		var lead = iss.description || iss.recommendation;
		if ( lead ) { detail.push( el( 'p', { className: 'sx-iss-lead', key: 'lead' }, lead ) ); }
		if ( iss.why_it_matters ) {
			detail.push( el( 'div', { className: 'sx-iss-sec', key: 'why' },
				el( 'div', { className: 'sx-iss-h' }, ( data.i18n && data.i18n.why ) || 'Why it matters' ),
				el( 'p', null, iss.why_it_matters )
			) );
		}
		var ex = exampleBlock( iss );
		if ( ex ) { detail.push( el( 'div', { key: 'ex' }, ex ) ); }
		if ( steps.length ) {
			detail.push( el( 'div', { className: 'sx-iss-sec', key: 'how' },
				el( 'div', { className: 'sx-iss-h' }, ( data.i18n && data.i18n.howToFix ) || 'How to fix' ),
				el( 'ol', { className: 'sx-iss-steps' }, steps.map( function ( s, i ) { return el( 'li', { key: i }, s ); } ) )
			) );
		}
		if ( warns.length ) {
			detail.push( el( 'div', { className: 'sx-iss-warn', key: 'warn' },
				warns.map( function ( w, i ) { return el( 'div', { className: 'sx-iss-warn-row', key: i }, '⚠ ' + w ); } )
			) );
		}
		// One-click action when the fix lives on a screen the plugin hosts (the
		// Redirects manager). Only shown when the payload carries fix_action —
		// most issues have no local tool and just show the steps above.
		if ( iss.fix_action && iss.fix_action.url ) {
			detail.push( el( 'a', {
				className: 'sx-iss-action',
				key: 'act',
				href: iss.fix_action.url
			}, ( iss.fix_action.label || 'Open' ) + ' →' ) );
		}
		return el( 'div', { className: 'sx-iss is-open' }, head, el( 'div', { className: 'sx-iss-detail' }, detail ) );
	}

	// Collapsible analysis section. Header = leading indicator + label + chevron;
	// body (when open) = the track's issue rows.
	//
	// The indicator is a count pill rather than a score: this section lists what
	// the site scan found on the page, which has no score to show. It sits in the
	// same 34px slot the scored sections put their gauge in, so every row's label
	// starts at the same place.
	function Section( props ) {
		var st = useState( false );
		var open = st[0];
		var setOpen = st[1];
		var items = props.items || [];
		var tone = items.length ? ( hasSeverity( items, 'error' ) ? 'bad' : 'warn' ) : 'good';
		return el(
			'div',
			{ className: 'sx-acc' },
			el(
				'button',
				{
					type: 'button',
					className: 'sx-acc-head',
					'aria-expanded': open ? 'true' : 'false',
					onClick: function () { setOpen( ! open ); }
				},
				el( 'span', { className: 'sx-acc-lead' },
					el( 'span', { className: 'sx-ind-soft sx-ind-soft--' + tone }, items.length ? String( items.length ) : '✓' )
				),
				el( 'span', { className: 'sx-acc-label' }, props.label ),
				el(
					'svg',
					{ className: 'sx-acc-chev' + ( open ? ' is-open' : '' ), width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': 'true' },
					el( 'path', { d: 'M7 10l5 5 5-5', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' } )
				)
			),
			open
				? el(
					'div',
					{ className: 'sx-acc-body' },
					items.length
						? items.map( function ( iss, i ) { return el( Issue, { iss: iss, key: 'r' + i } ); } )
						: el( 'div', { className: 'sx-acc-empty' }, ( data.i18n && data.i18n.noIssues ) || 'No issues found' )
				)
				: null
		);
	}

	function foot() {
		return el(
			'div',
			{ className: 'seonix-mb-foot' },
			el( 'a', { className: 'seonix-mb-link', href: data.allUrl || '#' }, ( ( data.i18n && data.i18n.viewAll ) || 'View all issues' ) + ' →' ),
			data.syncedLabel ? el( 'span', { className: 'seonix-mb-synced' }, data.syncedLabel ) : null
		);
	}

	// --- The mark ------------------------------------------------------------
	// The two sparkle "eyes" used to be a traffic light: left = SEO, right =
	// Readability, recoloured on every keystroke. They are brand-coloured now and
	// stay that way. A logo that changes colour with the text is a logo people
	// stop recognising, and it could only ever say "amber" — the badge says "6",
	// and the gauges below say 92 and 100.
	//
	// The two signals behind those numbers are still distinct and still separate
	// in the panel: the SCORE comes from the Seonix engine (the same pass the
	// dashboard editor runs, via this plugin's /score route) and answers "how is
	// this text?", while SCAN ISSUES describe the PAGE — redirects, canonicals,
	// structured data — which no amount of editing the prose fixes.
	var EYE_BRAND = '#F4EEFF';
	var EYE_MUTE = '#8B8AA3';
	var BRAND_EYES = { left: EYE_BRAND, right: EYE_BRAND };

	/** @return {boolean} whether any item carries the given severity. */
	function hasSeverity( items, severity ) {
		return ( items || [] ).some( function ( it ) { return severity === it.severity; } );
	}

	/**
	 * Every scan issue found on this URL, flattened out of its category groups.
	 *
	 * The badge, the header verdict and the Page issues section all count THIS
	 * list — the counts are only trustworthy while they share one source.
	 */
	function scanIssues() {
		var out = [];
		( data.groups || [] ).forEach( function ( g ) {
			( g.items || [] ).forEach( function ( it ) { out.push( it ); } );
		} );
		return out;
	}

	/**
	 * The page's overall verdict, from the two SCORES.
	 *
	 * The worse of the two, because it is a summary and a summary that hides the
	 * weaker half is worth nothing: text that reads beautifully and ranks for
	 * nothing is not "green".
	 *
	 * A missing score is not a good one. While the first pass is still running,
	 * or after it failed, the answer is "don't know" — never a green the page
	 * hasn't earned.
	 *
	 * @return {string} 'good' | 'warn' | 'bad' | 'mute'
	 */
	function overallTone( score ) {
		var seo = scoreTone( score.seo );
		var rd = scoreTone( score.readability );
		if ( 'mute' === seo || 'mute' === rd ) { return 'mute'; }
		if ( 'bad' === seo || 'bad' === rd ) { return 'bad'; }
		if ( 'warn' === seo || 'warn' === rd ) { return 'warn'; }
		return 'good';
	}

	/**
	 * The badge on the mark: a green / amber / red status dot, NOT a number.
	 *
	 * The colour is the page's overall verdict from the two content SCORES (the
	 * worse of SEO / Readability), with a ✓ when green and a "·" while there is
	 * no score yet. A raw issue count read as the page's grade even though it is
	 * not one — eight speed notes do not make a page "worse" than one with a
	 * single real error — so the badge speaks in the same green/amber/red the
	 * gauges do, and the exact issue count lives in the Page issues section.
	 */
	function badgeState( score ) {
		var i18n = data.i18n || {};
		var tone = overallTone( score );
		var glyph = { good: '✓', warn: '!', bad: '!', mute: '·' };

		// Spell the numbers out for screen readers — the glyph alone says
		// "something", and the colour says nothing at all.
		var parts = [];
		if ( null !== score.seo && undefined !== score.seo ) {
			parts.push( ( i18n.seoLabel || 'SEO' ) + ' ' + score.seo );
		}
		if ( null !== score.readability && undefined !== score.readability ) {
			parts.push( ( i18n.readabilityLabel || 'Readability' ) + ' ' + score.readability );
		}

		return {
			tone: tone,
			text: glyph[ tone ],
			label: parts.length ? parts.join( ' · ' ) : ( i18n.analyzing || 'Analyzing…' )
		};
	}

	/** The Seonix mark wearing its status badge. */
	function MarkWithBadge( props ) {
		var b = props.badge;
		return el(
			'span',
			{ className: 'seonix-mark' },
			makeIcon( BRAND_EYES ),
			el( 'span', { className: 'seonix-mark__badge seonix-mark__badge--' + b.tone, 'aria-hidden': 'true' }, b.text ),
			// The glyph says "something" and the colour says nothing at all, so
			// the scores go in as text. screen-reader-text is core's own utility
			// class — visually hidden, read aloud.
			el( 'span', { className: 'screen-reader-text' }, b.label )
		);
	}

	// Score → traffic light. Thresholds mirror the dashboard editor's
	// SeoScorePanel (>=90 Excellent, >=70 Good, below that Needs Work) so the
	// same article never reads "green" in one surface and "red" in the other.
	function scoreTone( score ) {
		if ( null === score || undefined === score ) { return 'mute'; }
		if ( score >= 90 ) { return 'good'; }
		if ( score >= 70 ) { return 'warn'; }
		return 'bad';
	}

	// The EYE_* palette is tuned for the sparkles on the mark's DARK face and is
	// too light to read on the panel's white background — anything drawn on the
	// panel itself takes its colour from the CSS tokens via a tone class instead.
	// Severity ordering helper: pick the more alarming of two eye colours.
	// --- Live score store ---------------------------------------------------
	// One worker per page load, many subscribers (the panel, the document
	// panel, and each copy of the toolbar icon). The icon is rendered outside
	// React's tree by registerPlugin, and it has to show the live colours even
	// while the panel is closed — so the scoring pass cannot live in a
	// component hook, or every mounted copy would fire its own request.
	var scoreStore = {
		state: { status: 'idle', seo: null, readability: null, seoChecks: [], rdChecks: [], error: '' },
		listeners: [],
		set: function ( next ) {
			this.state = next;
			this.listeners.forEach( function ( fn ) { fn(); } );
		},
		subscribe: function ( fn ) {
			this.listeners.push( fn );
			var self = this;
			return function () {
				self.listeners = self.listeners.filter( function ( x ) { return x !== fn; } );
			};
		}
	};

	function useScore() {
		var st = useState( scoreStore.state );
		var setState = st[1];
		useEffect( function () {
			// Re-read on mount: the worker may have resolved between this
			// component's first render and its effect running.
			setState( scoreStore.state );
			return scoreStore.subscribe( function () { setState( scoreStore.state ); } );
		}, [] );
		return st[0];
	}

	// Rough "is there any prose here yet" test. Strips block comments and tags
	// so an empty paragraph block (which is a non-empty STRING) doesn't get
	// scored — the engine would reject it as empty content anyway.
	function plainTextLength( html ) {
		if ( ! html ) { return 0; }
		return html
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<[^>]*>/g, ' ' )
			.replace( /&nbsp;/g, ' ' )
			.replace( /&[a-z]+;/gi, ' ' )
			.trim().length;
	}

	// Reads a field as it is RIGHT NOW in the editor, including unsaved edits,
	// from whichever SEO plugin owns it. Each store is probed defensively — a
	// missing store, a renamed selector or a throwing getter must never take
	// the panel down; it just means we send nothing and the server falls back
	// to the last saved value.
	//
	// `probes` is a list of [storeName, selectorName] pairs, tried in order.
	function liveSeoField( probes ) {
		for ( var i = 0; i < probes.length; i++ ) {
			try {
				var store = wp.data.select( probes[ i ][0] );
				var selector = store && store[ probes[ i ][1] ];
				if ( selector ) {
					var value = store[ probes[ i ][1] ]();
					if ( value ) { return String( value ); }
				}
			} catch ( e ) {}
		}
		return '';
	}

	// Canonical key for Seonix's own keyphrase, passed from PHP so the bridge
	// stays its single definition; the literal is a last resort for a stale
	// localized payload.
	var OWN_KW_META = data.focusKeywordMetaKey || '_seonix_focus_keyword';

	// Does Seonix put its own keyphrase field on screen here?
	//
	// Only when nothing else does (hasNativeKeyphraseUi) AND the post type
	// actually carries meta over REST (keyphraseMetaInRest) — a field whose
	// value core would drop on save is worse than no field, because the author
	// watches it accept the text.
	//
	// Truthiness, NOT `false !== …`: wp_localize_script() casts every scalar it
	// ships with (string), so PHP true arrives as "1" and PHP false as "" — a
	// strict compare against the boolean can never match either, and the guard
	// would be dead code that always shows the field.
	var showKeyphraseField = ! data.hasNativeKeyphraseUi && !! data.keyphraseMetaInRest;

	// Seonix's own keyphrase field, read from the post's EDITED meta — the same
	// "live, including unsaved" contract as the the active SEO plugin store probes
	// above, since editPost() lands the value here long before a save does.
	function ownKeyphrase() {
		try {
			var editor = wp.data.select( 'core/editor' );
			if ( ! editor || ! editor.getEditedPostAttribute ) { return ''; }
			var meta = editor.getEditedPostAttribute( 'meta' );
			var value = meta && meta[ OWN_KW_META ];
			if ( value ) { return String( value ); }
		} catch ( e ) {}
		return '';
	}

	// Whichever field the author actually has in front of them owns the value:
	// the SEO plugin's when one is installed, ours when none is.
	//
	// The engine's EMPTY value is still its answer, so we must not fall through
	// to ours behind it: liveSeoField() can't tell "author cleared this field"
	// from "no such store", and our field is invisible while an engine is active.
	// A post that carried a Seonix keyphrase before an SEO plugin was installed would
	// otherwise keep scoring against that old value forever — the author clears
	// the SEO plugin's field, sees nothing on screen, and the panel silently keeps judging
	// against a phrase they cannot see or delete (read_effective() only copies
	// non-empty engine values back, so it never clears either).
	function liveKeyphrase() {
		var live = liveSeoField( [
			[ 'yoast-seo/editor', 'getFocusKeyphrase' ],
			[ 'rank-math', 'getKeyword' ]
		] );
		if ( live ) { return live; }
		return data.hasNativeKeyphraseUi ? '' : ownKeyphrase();
	}

	// The meta description matters more than it looks: it carries weight 10 in
	// the engine, so an author typing one in the the active SEO plugin sidebar would
	// otherwise watch the SEO gauge insist there isn't one until they saved —
	// the panel arguing with work they can see on screen.
	function liveMetaDescription() {
		return liveSeoField( [
			[ 'yoast-seo/editor', 'getDescription' ],
			[ 'rank-math', 'getDescription' ]
		] );
	}

	// The SEO title / description as the active engine has them right now, read
	// through a passed-in `select` so it can run INSIDE
	// a useSelect() — that is what makes the search-appearance preview re-render
	// live as the author edits the title / description in the active SEO plugin,
	// rather than freezing at whatever the value was on page load.
	function selectSeoField( select, probes ) {
		for ( var i = 0; i < probes.length; i++ ) {
			try {
				var store = select( probes[ i ][ 0 ] );
				var selector = store && store[ probes[ i ][ 1 ] ];
				if ( selector ) {
					var value = store[ probes[ i ][ 1 ] ]();
					if ( value ) { return String( value ); }
				}
			} catch ( e ) {}
		}
		return '';
	}

	// Everything the score depends on, in one place. Both the change detector
	// and the request payload read this, so a signal can never be sent without
	// also being watched (which is how the meta description ended up frozen at
	// its saved value while the panel claimed to be live).
	function editorSignals() {
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor || ! editor.getEditedPostAttribute ) { return null; }
		return {
			title: editor.getEditedPostAttribute( 'title' ) || '',
			slug: editor.getEditedPostAttribute( 'slug' ) || '',
			keyphrase: liveKeyphrase(),
			metaDescription: liveMetaDescription()
		};
	}

	// Comparable form of editorSignals(). The separator is a unit-separator
	// control char rather than a space so two different field splits can't
	// collide into the same key.
	function signalKey( signals ) {
		return [ signals.title, signals.slug, signals.keyphrase, signals.metaDescription ].join( '\u001F' );
	}

	function startScoreWorker() {
		if ( ! wp.data || ! wp.data.select || ! wp.data.subscribe || ! wp.apiFetch ) {
			return;
		}

		var DEBOUNCE_MS = 2000;
		var timer = null;
		var lastBlocks = null;   // identity check — cheap, no serialization
		var lastSignals = null;  // serialized editorSignals() of the last tick
		var lastSentContent = null;
		// Monotonic request id: a slow response for text the author has already
		// changed must never overwrite a fresher result.
		var seq = 0;

		function run() {
			var editor = wp.data.select( 'core/editor' );
			if ( ! editor || ! editor.getEditedPostContent ) { return; }

			var content = editor.getEditedPostContent();
			if ( plainTextLength( content ) < 1 ) {
				lastSentContent = null;
				scoreStore.set( { status: 'empty', seo: null, readability: null, seoChecks: [], rdChecks: [], error: '' } );
				return;
			}

			var signals = editorSignals();
			if ( ! signals ) { return; }

			// Identical payload to the one already scored → nothing to learn.
			var fingerprint = content + ' ' + signalKey( signals );
			if ( fingerprint === lastSentContent ) { return; }
			lastSentContent = fingerprint;

			var mine = ++seq;
			var prev = scoreStore.state;
			scoreStore.set( {
				status: 'loading',
				// Keep the previous numbers on screen while re-scoring so the
				// gauges don't flash empty on every pause in typing.
				seo: prev.seo, readability: prev.readability,
				seoChecks: prev.seoChecks, rdChecks: prev.rdChecks,
				error: ''
			} );

			wp.apiFetch( {
				path: '/seonix/v1/score',
				method: 'POST',
				data: {
					post_id: data.postId || 0,
					html_content: content,
					title: signals.title,
					slug: signals.slug,
					focus_keyphrase: signals.keyphrase,
					meta_description: signals.metaDescription
				}
			} ).then( function ( res ) {
				if ( mine !== seq ) { return; }
				scoreStore.set( {
					status: 'ready',
					seo: ( 'number' === typeof res.seo_score ) ? res.seo_score : null,
					readability: ( 'number' === typeof res.readability_score ) ? res.readability_score : null,
					seoChecks: res.seo_checks || [],
					rdChecks: res.readability_checks || [],
					error: ''
				} );
			} ).catch( function ( err ) {
				if ( mine !== seq ) { return; }
				// Let the next edit retry: a failed attempt must not be
				// remembered as "already scored".
				lastSentContent = null;
				scoreStore.set( {
					status: 'error',
					seo: null, readability: null, seoChecks: [], rdChecks: [],
					error: ( err && err.message ) ? err.message : ''
				} );
			} );
		}

		function schedule() {
			if ( timer ) { clearTimeout( timer ); }
			timer = setTimeout( run, DEBOUNCE_MS );
		}

		// Exposed so the error state can offer a manual retry: a failed request
		// otherwise sits there until the author happens to edit something.
		scoreStore.retry = function () {
			lastSentContent = null;
			schedule();
		};

		wp.data.subscribe( function () {
			var blockEditor = wp.data.select( 'core/block-editor' );
			if ( ! blockEditor ) { return; }
			var signals = editorSignals();
			if ( ! signals ) { return; }

			// Cheap change detection. getBlocks() returns a memoized array, so
			// an unchanged reference means the body is untouched — calling
			// getEditedPostContent() on every store tick would re-serialize
			// every block on every keystroke.
			var blocks = blockEditor.getBlocks();
			var key = signalKey( signals );
			if ( blocks === lastBlocks && key === lastSignals ) {
				return;
			}
			lastBlocks = blocks;
			lastSignals = key;
			schedule();
		} );

		// First pass for content that already exists when the editor opens.
		schedule();
	}

	// --- Score UI -----------------------------------------------------------

	// Circular gauge. Plain SVG (no wp-components dependency) so it renders the
	// same in the sidebar and the document panel.
	function Gauge( props ) {
		var score = props.score;
		var empty = ( null === score || undefined === score );
		var R = 15.9155; // circumference == 100, so dasharray reads as a percent
		var pct = empty ? 0 : Math.max( 0, Math.min( 100, score ) );
		var label = empty
			? ''
			: ( ( data.i18n && data.i18n.scoreOutOf ) || '%d out of 100' ).replace( '%d', String( score ) );
		return el(
			'div',
			{
				className: 'sx-gauge sx-gauge--' + scoreTone( score ),
				// The ring is decorative; the number inside is what carries the
				// meaning, and on its own ("72") it doesn't say out of what.
				role: 'img',
				'aria-label': label
			},
			el(
				'svg',
				{ width: 40, height: 40, viewBox: '0 0 40 40', 'aria-hidden': 'true', focusable: 'false' },
				el( 'circle', { className: 'sx-gauge-track', cx: 20, cy: 20, r: R, fill: 'none', strokeWidth: 3.4 } ),
				el( 'circle', {
					className: 'sx-gauge-arc',
					cx: 20, cy: 20, r: R, fill: 'none',
					strokeWidth: 3.4, strokeLinecap: 'round',
					strokeDasharray: pct + ' ' + ( 100 - pct ),
					// Start the arc at 12 o'clock instead of 3 o'clock.
					transform: 'rotate(-90 20 20)'
				} )
			),
			el( 'span', { className: 'sx-gauge-num', 'aria-hidden': 'true' }, empty ? '—' : String( score ) )
		);
	}

	// The engine returns each message as a TEMPLATE with its numbers in
	// `details`: "{count} words — too short", details.count = 148. (The
	// dashboard passes details to its translator as variables; there is no
	// translation layer here, so substitute directly.) A placeholder with no
	// matching detail is left alone rather than blanked — a visible "{count}"
	// is a bug report, an empty gap is a mystery.
	function interpolate( message, details ) {
		if ( ! message || ! details ) { return message || ''; }
		return message.replace( /\{(\w+)\}/g, function ( whole, key ) {
			return Object.prototype.hasOwnProperty.call( details, key ) ? String( details[ key ] ) : whole;
		} );
	}

	// One scored check: severity dot + label + the engine's message.
	function CheckRow( props ) {
		var c = props.check;
		var message = interpolate( c.message, c.details );
		return el(
			'div',
			{ className: 'sx-chk' },
			el( 'span', { className: 'seonix-pagedot seonix-pagedot--' + ( 'good' === c.severity ? 'clean' : c.severity ), 'aria-hidden': 'true' } ),
			el(
				'span',
				{ className: 'sx-chk-text' },
				el( 'span', { className: 'sx-chk-label' }, c.label || c.id ),
				message ? el( 'span', { className: 'sx-chk-msg' }, message ) : null
			)
		);
	}

	// Checks grouped the way the dashboard groups them: Problems first, then
	// Improvements, then Good results — worst-first, so the panel opens on what
	// needs doing.
	function checkGroups( checks ) {
		var out = [];
		var buckets = [
			{ sev: 'error', label: ( data.i18n && data.i18n.problems ) || 'Problems' },
			{ sev: 'warning', label: ( data.i18n && data.i18n.improvements ) || 'Improvements' },
			{ sev: 'good', label: ( data.i18n && data.i18n.goodResults ) || 'Good results' }
		];
		buckets.forEach( function ( b ) {
			var items = ( checks || [] ).filter( function ( c ) { return c.severity === b.sev; } );
			if ( items.length ) { out.push( { label: b.label, items: items } ); }
		} );
		return out;
	}

	function KeyphraseField() {
		var TextControl = wp.components && wp.components.TextControl;
		var useSelect = wp.data && wp.data.useSelect;
		var useDispatch = wp.data && wp.data.useDispatch;
		// Bail before any hook runs, on a condition that cannot change between
		// renders — the alternative is a conditional hook call.
		if ( ! TextControl || ! useSelect || ! useDispatch ) { return null; }

		var value = useSelect( function ( select ) {
			try {
				var editor = select( 'core/editor' );
				var meta = ( editor && editor.getEditedPostAttribute ) ? editor.getEditedPostAttribute( 'meta' ) : null;
				return ( meta && meta[ OWN_KW_META ] ) || '';
			} catch ( e ) {}
			return '';
		}, [] );
		var dispatcher = useDispatch( 'core/editor' );
		var i18n = data.i18n || {};

		return el(
			'div',
			{ className: 'seonix-mb-kw' },
			el( TextControl, {
				label: i18n.focusKeyphrase || 'Focus keyphrase',
				help: i18n.focusKeyphraseHelp || '',
				value: value,
				onChange: function ( next ) {
					if ( ! dispatcher || ! dispatcher.editPost ) { return; }
					var meta = {};
					meta[ OWN_KW_META ] = next;
					dispatcher.editPost( { meta: meta } );
				}
			} )
		);
	}

	// A live-scored track (SEO or Readability): gauge + grouped checks.
	function ScoreSection( props ) {
		var st = useState( false );
		var open = st[0];
		var setOpen = st[1];
		var score = props.score;
		var i18n = data.i18n || {};

		var bodyRows = [];
		if ( 'empty' === props.status ) {
			bodyRows.push( el( 'div', { className: 'sx-acc-empty', key: 'empty' }, i18n.writeToScore || 'Start writing and Seonix scores the text as you go.' ) );
		} else if ( 'error' === props.status ) {
			// Without a button the panel sits on the error until the author
			// happens to edit one of the watched fields — which, on a failure
			// they didn't cause, is not an obvious thing to go and do.
			bodyRows.push( el( 'div', { className: 'sx-acc-empty', key: 'err' },
				( i18n.scoreFailed || 'Could not analyze this text.' ) + ( props.error ? ' ' + props.error : '' ),
				scoreStore.retry
					? el( 'button', {
						type: 'button',
						className: 'sx-chk-retry',
						onClick: function () { scoreStore.retry(); }
					}, i18n.retry || 'Try again' )
					: null
			) );
		} else if ( 'idle' === props.status || ( 'loading' === props.status && null === score ) ) {
			bodyRows.push( el( 'div', { className: 'sx-acc-empty', key: 'load' }, i18n.analyzing || 'Analyzing…' ) );
		} else {
			if ( props.showKeyphraseHint ) {
				// Name the way out, where there is one. "Checks are skipped" and
				// nothing else is a dead end: it tells the author what they lost
				// without saying what to do, and the field it means may not even
				// be on this screen.
				var hint;
				if ( showKeyphraseField ) {
					hint = i18n.noKeyphraseOwn || 'No focus keyphrase set — fill in the field above to turn on keyphrase checks.';
				} else if ( data.hasNativeKeyphraseUi ) {
					hint = i18n.noKeyphrase || 'No focus keyphrase set — add one in your SEO plugin to turn on keyphrase checks.';
				} else {
					// No engine and no field of ours (a post type whose meta core
					// drops over REST) — nothing to point at, so the old wording is
					// still the honest one.
					hint = i18n.noKeyphraseSkipped || 'No focus keyphrase set — keyphrase checks are skipped.';
				}
				bodyRows.push( el( 'div', { className: 'sx-chk-hint', key: 'kw' }, hint ) );
			}
			checkGroups( props.checks ).forEach( function ( g, gi ) {
				bodyRows.push( el( 'div', { className: 'sx-chk-group', key: 'g' + gi },
					el( 'div', { className: 'sx-chk-group-h' }, g.label + ' (' + g.items.length + ')' ),
					g.items.map( function ( c, i ) { return el( CheckRow, { check: c, key: c.id || i } ); } )
				) );
			} );
			if ( ! bodyRows.length ) {
				bodyRows.push( el( 'div', { className: 'sx-acc-empty', key: 'none' }, i18n.noIssues || 'No issues found' ) );
			}
		}

		return el(
			'div',
			{ className: 'sx-acc' },
			el(
				'button',
				{
					type: 'button',
					className: 'sx-acc-head',
					'aria-expanded': open ? 'true' : 'false',
					onClick: function () { setOpen( ! open ); }
				},
				el( 'span', { className: 'sx-acc-lead' }, el( Gauge, { score: score } ) ),
				el( 'span', { className: 'sx-acc-label' }, props.label ),
				el(
					'svg',
					{ className: 'sx-acc-chev' + ( open ? ' is-open' : '' ), width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': 'true' },
					el( 'path', { d: 'M7 10l5 5 5-5', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' } )
				)
			),
			open ? el( 'div', { className: 'sx-acc-body' }, bodyRows ) : null
		);
	}

	// Classify the article's anchors per the editor-concepts spec, split into
	// the two display groups (internal = internal + #jump anchors, external =
	// external + mailto), de-duped by href. Each item carries kind
	// (internal | external | mail | anchor) + nofollow. tel / javascript /
	// data are skipped — not page links. Mirrors the PHP
	// Seonix_Metabox::extract_links so both editors agree.
	function classifyLinks( html, homeHost ) {
		var internal = [];
		var external = [];
		if ( ! html ) { return { internal: internal, external: external }; }
		var doc;
		try {
			doc = new DOMParser().parseFromString( html, 'text/html' );
		} catch ( e ) {
			return { internal: internal, external: external };
		}
		var host = ( homeHost || '' ).toLowerCase().replace( /^www\./, '' );
		var seenI = {};
		var seenE = {};
		var anchors = doc.querySelectorAll( 'a[href]' );
		for ( var i = 0; i < anchors.length; i++ ) {
			var href = ( anchors[ i ].getAttribute( 'href' ) || '' ).trim();
			if ( ! href ) { continue; }
			var lower = href.toLowerCase();
			if ( lower.indexOf( 'tel:' ) === 0 || lower.indexOf( 'javascript:' ) === 0 || lower.indexOf( 'data:' ) === 0 ) {
				continue;
			}
			// Skip non-navigational anchors that plugins use as click targets: a
			// bare "#" (a JS/button trigger) and popup openers (Popup Maker
			// #popmake-NN, Elementor #elementor-*). They open a modal, not a page,
			// so they are not links the author placed to navigate anywhere.
			if ( '#' === href || /^#(popmake|elementor)/i.test( href ) ) {
				continue;
			}
			var anchor = ( anchors[ i ].textContent || '' ).trim();
			var nofollow = /\bnofollow\b/i.test( anchors[ i ].getAttribute( 'rel' ) || '' );

			var kind;
			if ( href.charAt( 0 ) === '#' ) {
				kind = 'anchor';
			} else if ( lower.indexOf( 'mailto:' ) === 0 ) {
				kind = 'mail';
			} else if ( /^https?:\/\//i.test( href ) ) {
				var h = '';
				// hostname (no port) to match PHP's wp_parse_url(..., PHP_URL_HOST),
				// which the home host is derived from — .host would carry :8091 on
				// a dev site and wrongly mark same-site links as external.
				try { h = new URL( href ).hostname.toLowerCase().replace( /^www\./, '' ); } catch ( e2 ) { h = ''; }
				kind = ( host && h && h === host ) ? 'internal' : 'external';
			} else {
				kind = 'internal'; // relative → internal by definition
			}

			var item = { href: href, anchor: anchor, kind: kind, nofollow: nofollow };
			if ( 'internal' === kind || 'anchor' === kind ) {
				if ( ! seenI[ href ] ) { seenI[ href ] = 1; internal.push( item ); }
			} else if ( ! seenE[ href ] ) {
				seenE[ href ] = 1; external.push( item );
			}
		}
		return { internal: internal, external: external };
	}

	// Link-type icon (editor-concepts spec, 13×13 @ viewBox 24).
	function linkTypeIcon( kind, size ) {
		var svgProps = {
			width: size || 13, height: size || 13, viewBox: '0 0 24 24', fill: 'none',
			stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round',
			'aria-hidden': 'true'
		};
		if ( 'external' === kind ) {
			return el( 'svg', svgProps, el( 'path', { d: 'M7 17L17 7' } ), el( 'path', { d: 'M8 7h9v9' } ) );
		}
		if ( 'mail' === kind ) {
			return el( 'svg', svgProps, el( 'rect', { x: 3, y: 5, width: 18, height: 14, rx: 2 } ), el( 'path', { d: 'm3 7 9 6 9-6' } ) );
		}
		if ( 'anchor' === kind ) {
			return el( 'svg', svgProps, el( 'path', { d: 'M6 9h12M5 15h12M10 4L8 20M16 4l-2 16' } ) );
		}
		return el(
			'svg', svgProps,
			el( 'path', { d: 'M9.5 14.5l5-5' } ),
			el( 'path', { d: 'M11 6.5l1-1a3.5 3.5 0 0 1 5 5l-1 1' } ),
			el( 'path', { d: 'M13 17.5l-1 1a3.5 3.5 0 0 1-5-5l1-1' } )
		);
	}

	// Compact display path per the concept: internal → /path, external → host/path.
	function linkDisplayPath( it ) {
		if ( 'anchor' === it.kind || 'mail' === it.kind ) { return it.href; }
		if ( ! /^https?:\/\//i.test( it.href ) ) { return it.href; }
		try {
			var u = new URL( it.href );
			var path = ( '/' === u.pathname ? '' : u.pathname ) + ( u.search || '' );
			if ( 'internal' === it.kind ) { return path || '/'; }
			return u.hostname.replace( /^www\./, '' ) + path;
		} catch ( e ) {
			return it.href;
		}
	}

	// Jump to a link inside the article: select the block that contains this
	// href and scroll it into view, so the author can see WHERE the link sits.
	// The block-editor canvas is an iframe in WP 6.6+, so we look there too.
	function focusLinkInEditor( href ) {
		try {
			var be = ( wp.data && wp.data.select ) ? wp.data.select( 'core/block-editor' ) : null;
			var dispatch = ( wp.data && wp.data.dispatch ) ? wp.data.dispatch( 'core/block-editor' ) : null;
			if ( ! be || ! be.getBlocks || ! dispatch ) { return; }
			var found = null;
			( function walk( blocks ) {
				for ( var i = 0; i < blocks.length && ! found; i++ ) {
					var b = blocks[ i ];
					var hay = '';
					try { hay = JSON.stringify( b.attributes || {} ); } catch ( e ) {}
					if ( hay && hay.indexOf( href ) !== -1 ) { found = b.clientId; return; }
					if ( b.innerBlocks && b.innerBlocks.length ) { walk( b.innerBlocks ); }
				}
			} )( be.getBlocks() );
			if ( ! found ) { return; }
			if ( dispatch.selectBlock ) { dispatch.selectBlock( found ); }
			setTimeout( function () {
				var node = document.getElementById( 'block-' + found );
				if ( ! node ) {
					var frame = document.querySelector( 'iframe[name="editor-canvas"]' );
					if ( frame && frame.contentDocument ) { node = frame.contentDocument.getElementById( 'block-' + found ); }
				}
				if ( node && node.scrollIntoView ) { node.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
			}, 30 );
		} catch ( e ) {}
	}

	// Unwrap the first <a> that points at href, keeping its inner text.
	function unwrapAnchor( html, href ) {
		var esc = href.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		var re = new RegExp( '<a\\b[^>]*href="' + esc + '"[^>]*>([\\s\\S]*?)<\\/a>', 'i' );
		return html.replace( re, '$1' );
	}

	// Remove a link from the article, keeping its anchor text (unlink). Finds the
	// block that holds this href and unwraps the matching <a> in every string
	// attribute (paragraph / heading / list content). Undoable with editor Undo.
	function removeLinkInEditor( href ) {
		try {
			var be = ( wp.data && wp.data.select ) ? wp.data.select( 'core/block-editor' ) : null;
			var dispatch = ( wp.data && wp.data.dispatch ) ? wp.data.dispatch( 'core/block-editor' ) : null;
			if ( ! be || ! be.getBlocks || ! dispatch || ! dispatch.updateBlockAttributes ) { return; }
			var found = null;
			( function walk( blocks ) {
				for ( var i = 0; i < blocks.length && ! found; i++ ) {
					var b = blocks[ i ];
					var hay = '';
					try { hay = JSON.stringify( b.attributes || {} ); } catch ( e ) {}
					if ( hay && hay.indexOf( href ) !== -1 ) { found = b; return; }
					if ( b.innerBlocks && b.innerBlocks.length ) { walk( b.innerBlocks ); }
				}
			} )( be.getBlocks() );
			if ( ! found ) { return; }
			var attrs = found.attributes || {};
			var changed = {};
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				// content is a string on older cores and a RichTextData object on
				// WP 6.4+ — read both via toString(); a non-text object stringifies
				// to "[object Object]", which carries no href and is skipped.
				var str = ( 'string' === typeof v ) ? v : ( v && 'function' === typeof v.toString ? v.toString() : '' );
				if ( str && str.indexOf( '<a' ) !== -1 && str.indexOf( href ) !== -1 ) {
					var next = unwrapAnchor( str, href );
					if ( next !== str ) { changed[ k ] = next; }
				}
			} );
			if ( ! Object.keys( changed ).length ) { return; }
			if ( dispatch.selectBlock ) { dispatch.selectBlock( found.clientId ); }
			dispatch.updateBlockAttributes( found.clientId, changed );
		} catch ( e ) {}
	}

	// Small action icons for a link row (edit / delete), 14px @ viewBox 24.
	function actionIcon( kind ) {
		var p = { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true' };
		if ( 'edit' === kind ) {
			return el( 'svg', p, el( 'path', { d: 'M12 20h9' } ), el( 'path', { d: 'M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z' } ) );
		}
		return el( 'svg', p, el( 'path', { d: 'M3 6h18' } ), el( 'path', { d: 'M8 6V4h8v2' } ), el( 'path', { d: 'M19 6l-1 14H6L5 6' } ), el( 'path', { d: 'M10 11v6M14 11v6' } ) );
	}

	// One concept-style link group: hairline header + compact icon rows. Each row
	// shows the link, and on hover the three actions the dashboard link tools use:
	// edit (jump to it in the article), remove (unlink, keep the text), and open
	// in a new tab (real destinations only — an in-page #anchor has nothing to open).
	function linkGroup( items, label, empty, group ) {
		var i18n = data.i18n || {};
		var rows;
		if ( items.length ) {
			rows = items.map( function ( it, i ) {
				var icoCls = 'sx-lnk-ico' + ( 'external' === it.kind ? ' ext' : ( 'mail' === it.kind ? ' mail' : '' ) );
				var pathCls = 'sx-lnk-path' + ( 'external' === it.kind || 'mail' === it.kind ? ' sx-lnk-ext-path' : '' );
				var display = linkDisplayPath( it );
				// A real destination worth opening in a tab (an in-page #anchor is not).
				var canOpen = 'external' === it.kind || 'internal' === it.kind || 'mail' === it.kind;
				return el(
					'div',
					{ className: 'sx-lnk', key: 'l' + i },
					el(
						'button',
						{
							type: 'button',
							className: 'sx-lnk-main',
							title: i18n.jumpToLink || 'Find this link in the article',
							onClick: function () { focusLinkInEditor( it.href ); }
						},
						el( 'span', { className: icoCls }, linkTypeIcon( it.kind ) ),
						el(
							'span', { className: 'sx-lnk-text' },
							el( 'span', { className: 'sx-lnk-anchor' }, it.anchor || display ),
							el( 'span', { className: pathCls }, display )
						)
					),
					it.nofollow ? el( 'span', { className: 'sx-lnk-tag' }, 'nofollow' ) : null,
					el(
						'span',
						{ className: 'sx-lnk-actions' },
						// Edit — jump to the link in the article so the author can change it.
						el( 'button', {
							type: 'button', className: 'sx-lnk-act',
							title: i18n.editLink || 'Edit this link',
							'aria-label': i18n.editLink || 'Edit this link',
							onClick: function () { focusLinkInEditor( it.href ); }
						}, actionIcon( 'edit' ) ),
						// Remove — unlink, keeping the anchor text (undoable).
						el( 'button', {
							type: 'button', className: 'sx-lnk-act sx-lnk-act--del',
							title: i18n.removeLink || 'Remove this link',
							'aria-label': i18n.removeLink || 'Remove this link',
							onClick: function () { removeLinkInEditor( it.href ); }
						}, actionIcon( 'del' ) ),
						// Open in a new tab — real destinations only.
						canOpen ? el( 'a', {
							className: 'sx-lnk-act',
							href: it.href, target: '_blank', rel: 'noopener noreferrer',
							title: i18n.openLink || 'Open in a new tab',
							'aria-label': i18n.openLink || 'Open in a new tab'
						}, linkTypeIcon( 'external', 13 ) ) : null
					)
				);
			} );
		} else {
			rows = el(
				'div', { className: 'sx-lnk-empty' },
				linkTypeIcon( 'external' === group ? 'external' : 'internal', 14 ),
				el( 'span', null, empty )
			);
		}
		return el(
			'div',
			{ className: 'sx-lnk-group' },
			el(
				'div', { className: 'sx-lnk-group-h' },
				el( 'span', null, label ),
				el( 'span', { className: 'n' }, String( items.length ) ),
				el( 'span', { className: 'rule' } )
			),
			rows
		);
	}

	// Links inventory accordion: internal + external groups parsed live from
	// the editor content (concept-spec rows), so the counts track what the
	// author is actually writing.
	function LinksSection() {
		var st = useState( false );
		var open = st[0];
		var setOpen = st[1];
		var i18n = data.i18n || {};
		var useSelect = wp.data && wp.data.useSelect;
		var content = useSelect
			? useSelect( function ( select ) {
				var ed = select( 'core/editor' );
				return ed && ed.getEditedPostContent ? ed.getEditedPostContent() : '';
			}, [] )
			: '';
		var res = classifyLinks( content, data.homeHost );
		var isVoid = ! res.internal.length && ! res.external.length;

		var body;
		if ( isVoid ) {
			body = el(
				'div', { className: 'sx-lnk-void' },
				el( 'span', { className: 'ic' }, linkTypeIcon( 'internal', 19 ) ),
				el( 'span', { className: 'h' }, i18n.noLinksTitle || 'No links on this page' ),
				el( 'span', { className: 's' }, i18n.noLinksSub || 'Add a few internal links to help readers and search engines navigate your content.' )
			);
		} else {
			body = [
				linkGroup( res.internal, i18n.internalLinks || 'Internal', i18n.noInternal || 'No internal links yet.', 'internal' ),
				linkGroup( res.external, i18n.externalLinks || 'External', i18n.noExternal || 'No external links yet.', 'external' )
			];
		}

		return el(
			'div',
			{ className: 'sx-acc' },
			el(
				'button',
				{
					type: 'button',
					className: 'sx-acc-head',
					'aria-expanded': open ? 'true' : 'false',
					onClick: function () { setOpen( ! open ); }
				},
				el( 'span', { className: 'sx-acc-lead' },
					el( 'span', { className: 'lnk-split' },
						el( 'span', { className: 'in' + ( res.internal.length ? '' : ' is-dim' ) }, String( res.internal.length ) ),
						el( 'span', { className: 'sl' }, '/' ),
						el( 'span', { className: 'ex' }, String( res.external.length ) )
					)
				),
				el( 'span', { className: 'sx-acc-label' }, i18n.linksLabel || 'Links' ),
				el(
					'svg',
					{ className: 'sx-acc-chev' + ( open ? ' is-open' : '' ), width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': 'true' },
					el( 'path', { d: 'M7 10l5 5 5-5', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' } )
				)
			),
			open ? el( 'div', { className: 'sx-acc-body seonix-mb-links' }, body ) : null
		);
	}

	// --- Search appearance ---------------------------------------------------
	// SEO title + meta description with a live Google / social preview, mirroring
	// the "Search appearance" surface SEO plugins show. Values come from the bridge
	// (whichever engine owns them). When no SEO plugin is installed the fields are
	// editable and write our canonical meta — which the bridge fans out to any
	// active engine and syncs to Seonix; otherwise they show the engine's live
	// values and the author edits them there.
	var search = data.search || {};
	var TITLE_MIN = 30;
	var TITLE_MAX = 60;
	var DESC_MIN = 70;
	var DESC_MAX = 160;

	function truncate( s, n ) {
		s = String( s || '' );
		if ( s.length <= n ) { return s; }
		return s.slice( 0, n - 1 ).replace( /\s+\S*$/, '' ) + '…';
	}

	function siteInitial() {
		var n = ( search.siteName || search.host || 'S' ).trim();
		return n ? n.charAt( 0 ).toUpperCase() : 'S';
	}

	// Path part of the permalink, as Google renders the breadcrumb URL.
	function urlCrumb() {
		try {
			var u = new URL( search.permalink || '' );
			var parts = u.pathname.split( '/' ).filter( Boolean );
			return parts.length ? ' › ' + parts.join( ' › ' ) : '';
		} catch ( e ) { return ''; }
	}

	// Length meter: grey when empty, amber when too short, GREEN across the whole
	// good range up to the limit, red only once it overruns and would be clipped
	// in results. (At exactly the limit it is still green — that length is fine.)
	function lenMeter( len, min, max ) {
		var tone = 0 === len ? 'mute' : ( len > max ? 'bad' : ( len < min ? 'warn' : 'good' ) );
		var pct = Math.max( 3, Math.min( 100, ( len / max ) * 100 ) );
		return el( 'div', { className: 'sx-meter' },
			el( 'div', { className: 'sx-meter-track' }, el( 'div', { className: 'sx-meter-fill sx-meter-fill--' + tone, style: { width: pct + '%' } } ) ),
			el( 'span', { className: 'sx-meter-num sx-meter-num--' + tone }, len + ' / ' + max )
		);
	}

	function sglyph( paths ) {
		return el( 'svg', { width: 15, height: 15, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true' }, paths.map( function ( d, i ) { return el( 'path', { d: d, key: i } ); } ) );
	}

	function GooglePreview( props ) {
		var mobile = 'mobile' === props.mode;
		var title = props.title || search.fallbackTitle || ( ( data.i18n && data.i18n.untitledLabel ) || 'Untitled' );
		var desc = props.desc || '';
		var head = el( 'div', { className: 'sx-gp-head' },
			el( 'span', { className: 'sx-gp-fav' }, siteInitial() ),
			el( 'div', { className: 'sx-gp-id' },
				el( 'div', { className: 'sx-gp-site' }, search.siteName || search.host ),
				// Mobile shows just the host; desktop shows the full breadcrumb URL.
				el( 'div', { className: 'sx-gp-url' }, mobile ? ( search.host || '' ) : ( ( search.host || '' ) + urlCrumb() ) )
			),
			el( 'span', { className: 'sx-gp-dots', 'aria-hidden': 'true' }, '⋮' )
		);
		var titleEl = el( 'div', { className: 'sx-gp-title' }, truncate( title, mobile ? 58 : 66 ) );
		var descEl = el( 'div', { className: 'sx-gp-desc' },
			search.dateLabel ? el( 'span', { className: 'sx-gp-date' }, search.dateLabel + ' — ' ) : null,
			truncate( desc, mobile ? 150 : 175 )
		);
		// Mobile: a rounded card — title full width, then the description with a
		// small thumbnail tucked to its right (the title never gets squeezed).
		if ( mobile ) {
			return el( 'div', { className: 'sx-gp sx-gp--mobile' },
				head,
				titleEl,
				el( 'div', { className: 'sx-gp-descrow' },
					descEl,
					search.image ? el( 'div', { className: 'sx-gp-thumb', style: { backgroundImage: 'url("' + search.image + '")' } } ) : null
				)
			);
		}
		// Desktop: a plain result — breadcrumb URL row, blue title, description. No
		// card chrome, no thumbnail, full width.
		return el( 'div', { className: 'sx-gp sx-gp--desktop' }, head, titleEl, descEl );
	}

	function SocialPreview( props ) {
		var title = props.title || search.fallbackTitle || 'Untitled';
		var desc = props.desc || '';
		return el( 'div', { className: 'sx-sp' },
			search.image
				? el( 'div', { className: 'sx-sp-img', style: { backgroundImage: 'url("' + search.image + '")' } } )
				: el( 'div', { className: 'sx-sp-img sx-sp-img--empty' }, makeIcon( BRAND_EYES ) ),
			el( 'div', { className: 'sx-sp-meta' },
				el( 'div', { className: 'sx-sp-domain' }, ( search.host || '' ).toUpperCase() ),
				el( 'div', { className: 'sx-sp-title' }, truncate( title, 70 ) ),
				desc ? el( 'div', { className: 'sx-sp-desc' }, truncate( desc, 120 ) ) : null
			)
		);
	}

	function SearchAppearanceSection() {
		var oSt = useState( false ), open = oSt[0], setOpen = oSt[1];
		var tSt = useState( 'google' ), tab = tSt[0], setTab = tSt[1];
		var mSt = useState( 'mobile' ), mode = mSt[0], setMode = mSt[1];
		var i18n = data.i18n || {};
		var editable = !! search.editable;
		var useSelect = wp.data && wp.data.useSelect;
		var useDispatch = wp.data && wp.data.useDispatch;

		// Hooks run unconditionally (Rules of Hooks); the branch is in the values.
		// Read BOTH our own meta and the active SEO plugin's store inside one
		// useSelect, so the fields + preview stay in sync live — whether the author
		// types here or the value already lives in an active SEO plugin.
		var live = useSelect ? useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			var meta = ( ed && ed.getEditedPostAttribute ) ? ed.getEditedPostAttribute( 'meta' ) : null;
			return {
				ownT: ( meta && meta[ search.titleMetaKey ] ) || '',
				ownD: ( meta && meta[ search.descMetaKey ] ) || '',
				engT: selectSeoField( select, [
					[ 'yoast-seo/editor', 'getSeoTitle' ],
					[ 'yoast-seo/editor', 'getEditorDataTitle' ],
					[ 'rank-math', 'getTitle' ]
				] ),
				engD: selectSeoField( select, [
					[ 'yoast-seo/editor', 'getDescription' ],
					[ 'rank-math', 'getDescription' ]
				] )
			};
		}, [] ) : { ownT: '', ownD: '', engT: '', engD: '' };
		var dispatcher = useDispatch ? useDispatch( 'core/editor' ) : null;

		// Our own value first; else the SEO plugin's current value; else the
		// localized effective — so an editable field is pre-filled with whatever
		// title / description already exists, and a read-only field still shows it.
		var title = ( editable ? live.ownT : '' ) || live.engT || search.seoTitle || '';
		var desc = ( editable ? live.ownD : '' ) || live.engD || search.metaDescription || '';

		function writeMeta( key, val ) {
			if ( ! dispatcher || ! dispatcher.editPost ) { return; }
			var m = {}; m[ key ] = val; dispatcher.editPost( { meta: m } );
		}

		var previewArea = 'social' === tab
			? el( SocialPreview, { title: title, desc: desc } )
			: el( GooglePreview, { title: title, desc: desc, mode: mode } );

		var titleField = editable
			? el( 'input', { type: 'text', className: 'sx-sa-input', value: title, placeholder: i18n.seoTitlePlaceholder || '', onChange: function ( e ) { writeMeta( search.titleMetaKey, e.target.value ); } } )
			: el( 'div', { className: 'sx-sa-ro' }, title || el( 'span', { className: 'sx-sa-ph' }, i18n.seoTitlePlaceholder || '' ) );
		var descField = editable
			? el( 'textarea', { className: 'sx-sa-input sx-sa-textarea', rows: 3, value: desc, placeholder: i18n.metaDescPlaceholder || '', onChange: function ( e ) { writeMeta( search.descMetaKey, e.target.value ); } } )
			: el( 'div', { className: 'sx-sa-ro' }, desc || el( 'span', { className: 'sx-sa-ph' }, i18n.metaDescPlaceholder || '' ) );

		var body = el( 'div', { className: 'sx-acc-body sx-sa' },
			el( 'div', { className: 'sx-sa-tabs' },
				el( 'button', { type: 'button', className: 'sx-sa-tab' + ( 'google' === tab ? ' is-active' : '' ), onClick: function () { setTab( 'google' ); } }, i18n.googlePreview || 'Google' ),
				el( 'button', { type: 'button', className: 'sx-sa-tab' + ( 'social' === tab ? ' is-active' : '' ), onClick: function () { setTab( 'social' ); } }, i18n.socialPreview || 'Social' ),
				'google' === tab ? el( 'div', { className: 'sx-sa-modes' },
					el( 'button', { type: 'button', className: 'sx-sa-mode' + ( 'mobile' === mode ? ' is-active' : '' ), 'aria-label': i18n.mobileLabel || 'Mobile', title: i18n.mobileLabel || 'Mobile', onClick: function () { setMode( 'mobile' ); } }, sglyph( [ 'M7 4h10a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z', 'M11 18h2' ] ) ),
					el( 'button', { type: 'button', className: 'sx-sa-mode' + ( 'desktop' === mode ? ' is-active' : '' ), 'aria-label': i18n.desktopLabel || 'Desktop', title: i18n.desktopLabel || 'Desktop', onClick: function () { setMode( 'desktop' ); } }, sglyph( [ 'M3 5h18v11H3z', 'M8 20h8M12 16v4' ] ) )
				) : null
			),
			el( 'div', { className: 'sx-sa-preview' }, previewArea ),
			el( 'div', { className: 'sx-sa-field' },
				el( 'div', { className: 'sx-sa-flabel' }, el( 'span', null, i18n.seoTitleLabel || 'SEO title' ), lenMeter( ( title || '' ).length, TITLE_MIN, TITLE_MAX ) ),
				titleField
			),
			el( 'div', { className: 'sx-sa-field' },
				el( 'div', { className: 'sx-sa-flabel' }, el( 'span', null, i18n.metaDescLabel || 'Meta description' ), lenMeter( ( desc || '' ).length, DESC_MIN, DESC_MAX ) ),
				descField
			)
		);

		return el( 'div', { className: 'sx-acc' },
			el( 'button', { type: 'button', className: 'sx-acc-head', 'aria-expanded': open ? 'true' : 'false', onClick: function () { setOpen( ! open ); } },
				el( 'span', { className: 'sx-acc-lead' }, el( 'span', { className: 'sx-sa-ico' }, sglyph( [ 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z', 'M21 21l-4.35-4.35' ] ) ) ),
				el( 'span', { className: 'sx-acc-label' }, i18n.searchApp || 'Search appearance' ),
				el( 'svg', { className: 'sx-acc-chev' + ( open ? ' is-open' : '' ), width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': 'true' }, el( 'path', { d: 'M7 10l5 5 5-5', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' } ) )
			),
			open ? body : null
		);
	}

	function PanelBody() {
		var score = useScore();
		var i18n = data.i18n || {};

		// Every scan issue for this URL, in one section. They are grouped by
		// category server-side (SEO / Technical / AI Search), but that split is
		// about which part of the platform found them — for someone editing the
		// page they are one list: things wrong with the page itself.
		var scanItems = scanIssues();

		var hasKeyphrase = ( score.seoChecks || [] ).some( function ( c ) {
			return 'keyphraseInTitle' === c.id && 'good' !== c.severity;
		} );

		return el(
			'div',
			{ className: 'seonix-metabox seonix-metabox--panel' },
			brandHeader(),
			// Above the analysis, SEO-plugin-style: the keyphrase is the input the
			// SEO checks below are judging against, so it reads top-down — and
			// it is what the "fill in the field above" hint points at.
			showKeyphraseField ? el( KeyphraseField, { key: 'kw' } ) : null,
			// How this page looks in search + social, with editable title / meta.
			el( SearchAppearanceSection, { key: 'search' } ),
			el( ScoreSection, {
				key: 'seo',
				label: i18n.seoLabel || 'SEO',
				score: score.seo,
				checks: score.seoChecks,
				status: score.status,
				error: score.error,
				showKeyphraseHint: hasKeyphrase && ! liveKeyphrase()
			} ),
			el( ScoreSection, {
				key: 'rd',
				label: i18n.readabilityLabel || 'Readability',
				score: score.readability,
				checks: score.rdChecks,
				status: score.status,
				error: score.error
			} ),
			// Page issues only exist once the platform has scanned this URL.
			scanItems.length
				? el( Section, { key: 'page', label: i18n.pageIssuesLabel || 'Page issues', items: scanItems } )
				: null,
			el( LinksSection, { key: 'links' } ),
			foot()
		);
	}

	// The two sparkle "eyes" — same paths as the dashboard logo (viewBox 0 0 50).
	var EYE_LEFT = 'M13.6719 17.0898L15.3866 22.0738C15.5823 22.6427 16.0292 23.0896 16.598 23.2853L21.582 25L16.598 26.7147C16.0292 26.9104 15.5823 27.3573 15.3866 27.9262L13.6719 32.9102L11.9572 27.9262C11.7615 27.3573 11.3146 26.9104 10.7457 26.7147L5.76172 25L10.7457 23.2853C11.3146 23.0896 11.7615 22.6427 11.9572 22.0738L13.6719 17.0898Z';
	var EYE_RIGHT = 'M36.3281 17.0898L38.0428 22.0738C38.2385 22.6427 38.6854 23.0896 39.2543 23.2853L44.2383 25L39.2543 26.7147C38.6854 26.9104 38.2385 27.3573 38.0428 27.9262L36.3281 32.9102L34.6134 27.9262C34.4177 27.3573 33.9708 26.9104 33.402 26.7147L28.418 25L33.402 23.2853C33.9708 23.0896 34.4177 22.6427 34.6134 22.0738L36.3281 17.0898Z';

	// Build a fresh icon element that reproduces the Seonix favicon: a dark
	// rounded face with a deep, SATURATED purple→blue mesh anchored to the bottom
	// corners (vivid purple bottom-left, vivid blue bottom-right) fading up into
	// the dark navy at the top — exactly the favicon's gradient. On top sit the
	// two sparkle eyes, each tinted by its track's status (left = SEO, right =
	// Readability) on the green/amber/red traffic-light, with a soft dark halo so
	// the colour reads on the bright mesh. Each call mints its own ids so the
	// clip/filters keep working with several icon copies on screen at once.
	var iconSeq = 0;
	function makeIcon( colors ) {
		var eyes = colors || { left: EYE_MUTE, right: EYE_MUTE };
		var n = ++iconSeq;
		var cid = 'sx-clip-' + n;
		var mid = 'sx-mesh-' + n;
		var gid = 'sx-glow-' + n;
		return el(
			'svg',
			{ width: 24, height: 24, viewBox: '0 0 50 50', fill: 'none', xmlns: 'http://www.w3.org/2000/svg' },
			el(
				'defs',
				null,
				el( 'clipPath', { id: cid }, el( 'rect', { width: 50, height: 50, rx: 11.7188 } ) ),
				// Generous filter region so the blur isn't clipped at the edges.
				el( 'filter', { id: mid, x: '-60%', y: '-60%', width: '220%', height: '220%' }, el( 'feGaussianBlur', { stdDeviation: '8' } ) ),
				el( 'filter', { id: gid, x: '-50%', y: '-50%', width: '200%', height: '200%' }, el( 'feGaussianBlur', { stdDeviation: '1' } ) )
			),
			el(
				'g',
				{ clipPath: 'url(#' + cid + ')' },
				el( 'rect', { width: 50, height: 50, fill: '#191530' } ),
				el(
					'g',
					{ filter: 'url(#' + mid + ')' },
					el( 'ellipse', { cx: '2', cy: '52', rx: '30', ry: '40', fill: '#A931FB' } ),
					el( 'ellipse', { cx: '8', cy: '56', rx: '22', ry: '26', fill: '#C24DFF' } ),
					el( 'ellipse', { cx: '50', cy: '50', rx: '30', ry: '38', fill: '#27B5FA' } ),
					el( 'ellipse', { cx: '46', cy: '56', rx: '22', ry: '24', fill: '#46C5FF' } )
				),
				el(
					'g',
					null,
					el( 'g', { filter: 'url(#' + gid + ')', fill: '#0d0a1f', fillOpacity: '0.45' },
						el( 'path', { d: EYE_LEFT } ),
						el( 'path', { d: EYE_RIGHT } )
					),
					el( 'path', { d: EYE_LEFT, fill: eyes.left } ),
					el( 'path', { d: EYE_RIGHT, fill: eyes.right } )
				)
			)
		);
	}

	var panelTitle = data.title || 'Seonix — Page audit';

	// The sidebar header bar is narrow — a long "Seonix — 6 issues · 3 warnings ·
	// 3 notices" string wrapped to three clipped lines. Keep the header/tooltip to
	// just the brand (like the SEO plugin's own name); the count lives in the body header.
	var tipText = 'Seonix';

	// The icon the editor shows for this plugin (its toolbar button and the
	// Options → Plugins entry). Passed as a COMPONENT (not a rendered element)
	// wherever the editor accepts one — that is what lets it re-render on its
	// own; an element built once at registration time would be frozen at whatever
	// it had on page load.
	function LiveIcon() {
		// Subscribing is what makes the badge follow the score as the text is
		// edited. The mark itself stays brand — only the status dot moves.
		return el( MarkWithBadge, { badge: badgeState( useScore() ) } );
	}

	function SeonixAuditPanel() {
		var children = [];

		// The audit is a pinnable sidebar: its icon sits in the top editor toolbar
		// and clicking it opens the panel down the right-hand side, next to the
		// post's own settings. The status badge rides on that icon, so the count is
		// readable without opening anything.
		if ( Sidebar ) {
			if ( SidebarMoreMenuItem ) {
				children.push(
					el( SidebarMoreMenuItem, { key: 'more', target: 'seonix-page-audit', icon: LiveIcon }, panelTitle )
				);
			}
			children.push(
				el(
					Sidebar,
					{ key: 'sidebar', name: 'seonix-page-audit', title: tipText, icon: LiveIcon, className: 'seonix-audit-panel' },
					el( PanelBody )
				)
			);
		}

		// Secondary location: the same audit inside the Document settings sidebar,
		// so it is also discoverable next to the other post settings.
		if ( Panel ) {
			children.push(
				el(
					Panel,
					{ key: 'panel', name: 'seonix-page-audit-doc', title: panelTitle, className: 'seonix-audit-panel' },
					el( PanelBody )
				)
			);
		}

		return el( Fragment, null, children );
	}

	startScoreWorker();

	registerPlugin( 'seonix-page-audit', { render: SeonixAuditPanel, icon: LiveIcon } );
} )( window.wp );
