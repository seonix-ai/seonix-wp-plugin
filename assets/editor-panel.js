/**
 * Seonix — per-page audit panel in the block-editor document sidebar.
 *
 * Renders window.seonixAudit (localized by Seonix_Metabox::enqueue) as a
 * Yoast-style panel in the editor's Document sidebar, reusing the .seonix-metabox
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
	// entry point (its icon sits in the top toolbar, Yoast-style); the document
	// Panel is the fallback / secondary location.
	if ( ! registerPlugin || ( ! Sidebar && ! Panel ) ) {
		return;
	}

	var data = window.seonixAudit || null;
	if ( ! data ) {
		return;
	}

	// Brand header: the Seonix mark + wordmark + one-line verdict. Mirrors the
	// "Yoast SEO" header row at the top of Yoast's panel.
	//
	// The verdict line describes the last SITE SCAN ("6 issues on this page",
	// "Not scanned yet"), not the live score — the two answer different
	// questions and the gauges below already carry the score.
	function brandHeader( score ) {
		return el(
			'div',
			{ className: 'sx-ph-head' },
			el( 'span', { className: 'sx-ph-logo' }, makeIcon( eyeColors( score ) ) ),
			el(
				'div',
				{ className: 'sx-ph-headtext' },
				el( 'div', { className: 'sx-ph-brand' }, 'Seonix' ),
				data.verdict ? el( 'div', { className: 'sx-ph-sub' }, data.verdict ) : null
			)
		);
	}

	// A single Seonix "eye" (one sparkle on the dark face) tinted to the track's
	// status colour — this replaces Yoast's smiley as the section indicator.
	function eyeGlyph( color ) {
		return el(
			'svg',
			{ className: 'sx-acc-eye', width: 20, height: 20, viewBox: '0 0 24 24', 'aria-hidden': 'true' },
			el( 'rect', { width: 24, height: 24, rx: 6, fill: '#191530' } ),
			el( 'path', { d: 'M12 3.4L13.7 10.3L20.6 12L13.7 13.7L12 20.6L10.3 13.7L3.4 12L10.3 10.3Z', fill: color } )
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
		return el( 'div', { className: 'sx-iss is-open' }, head, el( 'div', { className: 'sx-iss-detail' }, detail ) );
	}

	// Collapsible analysis section (Yoast-style). Header = coloured Seonix eye +
	// label + count + chevron; body (when open) = the track's issue rows.
	function Section( props ) {
		var st = useState( false );
		var open = st[0];
		var setOpen = st[1];
		var items = props.items || [];
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
				eyeGlyph( props.color ),
				el( 'span', { className: 'sx-acc-label' }, props.label ),
				el( 'span', { className: 'sx-acc-count' }, String( items.length ) ),
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

	// --- The two "eyes" of the Seonix mark = two Yoast-style tracks ----------
	// Left eye  = SEO         (live SEO score of the text, plus any SEO/technical/
	//                          AI issues the last site scan found on this URL)
	// Right eye = Readability (live readability score of the text)
	//
	// The scores come from the Seonix engine — the same scoring pass the
	// dashboard editor runs (EvaluateSEOWithContext), reached through this
	// plugin's own /score route. Scan issues are a second, independent signal:
	// they describe the PAGE (redirects, canonicals, structured data), which no
	// amount of editing the text fixes, so they colour the SEO eye but are
	// listed in their own section.
	var EYE_RED = '#FF5468';
	var EYE_AMBER = '#FBB024';
	var EYE_GREEN = '#27D49A';
	var EYE_MUTE = '#8B8AA3';

	function worstColor( severities ) {
		if ( severities.indexOf( 'error' ) !== -1 ) { return EYE_RED; }
		if ( severities.indexOf( 'warning' ) !== -1 || severities.indexOf( 'notice' ) !== -1 ) { return EYE_AMBER; }
		return EYE_GREEN;
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
	function scoreColor( score ) {
		var tone = scoreTone( score );
		if ( 'good' === tone ) { return EYE_GREEN; }
		if ( 'warn' === tone ) { return EYE_AMBER; }
		if ( 'bad' === tone ) { return EYE_RED; }
		return EYE_MUTE;
	}

	// Severity ordering helper: pick the more alarming of two eye colours.
	function worseOf( a, b ) {
		var rank = {};
		rank[ EYE_MUTE ] = 0;
		rank[ EYE_GREEN ] = 1;
		rank[ EYE_AMBER ] = 2;
		rank[ EYE_RED ] = 3;
		return ( rank[ b ] > rank[ a ] ) ? b : a;
	}

	// Scan-issue severities for this URL, split off the payload once.
	var scanSev = [];
	( data.groups || [] ).forEach( function ( g ) {
		( g.items || [] ).forEach( function ( it ) { scanSev.push( it.severity ); } );
	} );
	var scanEye = scanSev.length ? worstColor( scanSev ) : null;

	// The mark's two eyes for a given score state.
	//
	// Left (SEO) folds in the page's scan issues: both are things that hold the
	// page back in search, and a page with a broken canonical should not look
	// clean just because its text scores well. Right (Readability) is the text
	// alone — nothing a site scan reports says anything about how the prose
	// reads. Before the first score lands, the eyes fall back to the scan
	// signal, and to neutral grey when there is nothing to report at all —
	// never a green that hasn't been earned.
	function eyeColors( score ) {
		var left = ( null === score.seo || undefined === score.seo ) ? ( scanEye || EYE_MUTE ) : scoreColor( score.seo );
		if ( null !== score.seo && undefined !== score.seo && scanEye ) {
			left = worseOf( left, scanEye );
		}
		var right = ( null === score.readability || undefined === score.readability )
			? EYE_MUTE
			: scoreColor( score.readability );
		return { left: left, right: right };
	}

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
	// "live, including unsaved" contract as the Yoast / Rank Math store probes
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
	// A post that carried a Seonix keyphrase before Yoast was installed would
	// otherwise keep scoring against that old value forever — the author clears
	// Yoast's field, sees nothing on screen, and the panel silently keeps judging
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
	// the engine, so an author typing one in the Yoast/Rank Math sidebar would
	// otherwise watch the SEO gauge insist there isn't one until they saved —
	// the panel arguing with work they can see on screen.
	function liveMetaDescription() {
		return liveSeoField( [
			[ 'yoast-seo/editor', 'getDescription' ],
			[ 'rank-math', 'getDescription' ]
		] );
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
		var color = scoreColor( score );
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
				eyeGlyph( color ),
				el( 'span', { className: 'sx-acc-label' }, props.label ),
				el( Gauge, { score: score } ),
				el(
					'svg',
					{ className: 'sx-acc-chev' + ( open ? ' is-open' : '' ), width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': 'true' },
					el( 'path', { d: 'M7 10l5 5 5-5', fill: 'none', stroke: 'currentColor', strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' } )
				)
			),
			open ? el( 'div', { className: 'sx-acc-body' }, bodyRows ) : null
		);
	}

	function PanelBody() {
		var score = useScore();
		var i18n = data.i18n || {};

		// Every scan issue for this URL, in one section. They are grouped by
		// category server-side (SEO / Technical / AI Search), but that split is
		// about which part of the platform found them — for someone editing the
		// page they are one list: things wrong with the page itself.
		var scanItems = [];
		( data.groups || [] ).forEach( function ( g ) {
			( g.items || [] ).forEach( function ( it ) { scanItems.push( it ); } );
		} );

		var hasKeyphrase = ( score.seoChecks || [] ).some( function ( c ) {
			return 'keyphraseInTitle' === c.id && 'good' !== c.severity;
		} );

		return el(
			'div',
			{ className: 'seonix-metabox seonix-metabox--panel' },
			brandHeader( score ),
			// Above the analysis, Yoast-style: the keyphrase is the input the
			// SEO checks below are judging against, so it reads top-down — and
			// it is what the "fill in the field above" hint points at.
			showKeyphraseField ? el( KeyphraseField, { key: 'kw' } ) : null,
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
				? el( Section, { key: 'page', label: i18n.pageIssuesLabel || 'Page issues', color: scanEye || EYE_GREEN, items: scanItems } )
				: null,
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
	// just the brand (like Yoast's "Yoast SEO"); the count lives in the body header.
	var tipText = 'Seonix';

	// The toolbar icon, subscribed to the live score so its eyes track the text
	// as it is edited. Passed as a COMPONENT (not a rendered element) wherever
	// the editor accepts one — that is what lets it re-render on its own; an
	// element built once at registration time would be frozen at the colours it
	// had on page load.
	function LiveIcon() {
		return makeIcon( eyeColors( useScore() ) );
	}

	function SeonixAuditPanel() {
		var children = [];
		var eyes = eyeColors( useScore() );

		// Primary entry point: a dedicated, pinnable sidebar whose icon sits in the
		// top editor toolbar (exactly like Yoast / Rank Math), plus its entry in the
		// editor's "Options → Plugins" menu. This is what makes the audit visible —
		// without it the panel only lived inside the (often closed) Document
		// settings sidebar, with no toolbar icon, so users never found it.
		if ( Sidebar ) {
			if ( SidebarMoreMenuItem ) {
				children.push(
					el( SidebarMoreMenuItem, { key: 'more', target: 'seonix-page-audit', icon: makeIcon( eyes ) }, panelTitle )
				);
			}
			children.push(
				el(
					Sidebar,
					{ key: 'sidebar', name: 'seonix-page-audit', title: tipText, icon: makeIcon( eyes ), className: 'seonix-audit-panel' },
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
