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
	function brandHeader() {
		return el(
			'div',
			{ className: 'sx-ph-head' },
			el( 'span', { className: 'sx-ph-logo' }, makeIcon() ),
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

	function body() {
		// Split this page's issues into the two tracks shown as sections.
		var seoItems = [];
		var contentItems = [];
		( data.groups || [] ).forEach( function ( g ) {
			var bucket = ( 'content' === g.key ) ? contentItems : seoItems;
			( g.items || [] ).forEach( function ( it ) { bucket.push( it ); } );
		} );
		var seoLabel = ( data.i18n && data.i18n.seoLabel ) || 'SEO';
		var rdLabel = ( data.i18n && data.i18n.readabilityLabel ) || 'Readability';
		return el(
			'div',
			{ className: 'seonix-metabox seonix-metabox--panel' },
			brandHeader(),
			el( Section, { key: 'seo', label: seoLabel, color: seoEye, items: seoItems } ),
			// The Readability track only renders when the payload actually
			// carries a 'content' group. The platform does not ship one today
			// (categories are clamped to seo/technical/ai server-side), so
			// without this guard the section was a permanently empty
			// "No issues" block — dead UI. If the backend ever starts sending
			// 'content' issues, the section reappears with no plugin change.
			contentItems.length
				? el( Section, { key: 'rd', label: rdLabel, color: contentEye, items: contentItems } )
				: null,
			foot()
		);
	}

	// --- The two "eyes" of the Seonix mark = two Yoast-style tracks ----------
	// Left eye  = SEO track        (seo + technical + ai + speed)
	// Right eye = Readability track (content)
	// Each eye takes the colour of the WORST severity in its track on a
	// traffic-light model: red = errors, amber = needs work (warning OR
	// notice/improvement), green = no open issues. A track with nothing to flag
	// reads as green even on a not-yet-scanned page — the panel is only enqueued
	// for sites linked to a Seonix account, so "no issues shown" is a clean
	// signal, not an unknown one. Computed in JS from data.groups, no backend
	// change needed.
	var EYE_RED = '#FF5468';
	var EYE_AMBER = '#FBB024';
	var EYE_GREEN = '#27D49A';

	function worstColor( severities ) {
		if ( severities.indexOf( 'error' ) !== -1 ) { return EYE_RED; }
		if ( severities.indexOf( 'warning' ) !== -1 || severities.indexOf( 'notice' ) !== -1 ) { return EYE_AMBER; }
		return EYE_GREEN;
	}

	// Split this page's issues into the two tracks. Everything that is not in
	// the "content" group counts towards SEO (seo / technical / ai / speed).
	var seoSev = [];
	var contentSev = [];
	( data.groups || [] ).forEach( function ( g ) {
		var bucket = ( 'content' === g.key ) ? contentSev : seoSev;
		( g.items || [] ).forEach( function ( it ) { bucket.push( it.severity ); } );
	} );

	var seoEye = worstColor( seoSev );
	// When the payload has no 'content' track (the platform doesn't ship one
	// today), the right eye mirrors the SEO track instead of reading as a
	// fake-green "readability is fine" signal next to a red SEO eye.
	var contentEye = contentSev.length ? worstColor( contentSev ) : seoEye;

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
	function makeIcon() {
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
					el( 'path', { d: EYE_LEFT, fill: seoEye } ),
					el( 'path', { d: EYE_RIGHT, fill: contentEye } )
				)
			)
		);
	}

	var panelTitle = data.title || 'Seonix — Page audit';

	// The sidebar header bar is narrow — a long "Seonix — 6 issues · 3 warnings ·
	// 3 notices" string wrapped to three clipped lines. Keep the header/tooltip to
	// just the brand (like Yoast's "Yoast SEO"); the count lives in the body header.
	var tipText = 'Seonix';

	function SeonixAuditPanel() {
		var children = [];

		// Primary entry point: a dedicated, pinnable sidebar whose icon sits in the
		// top editor toolbar (exactly like Yoast / Rank Math), plus its entry in the
		// editor's "Options → Plugins" menu. This is what makes the audit visible —
		// without it the panel only lived inside the (often closed) Document
		// settings sidebar, with no toolbar icon, so users never found it.
		if ( Sidebar ) {
			if ( SidebarMoreMenuItem ) {
				children.push(
					el( SidebarMoreMenuItem, { key: 'more', target: 'seonix-page-audit', icon: makeIcon() }, panelTitle )
				);
			}
			children.push(
				el(
					Sidebar,
					{ key: 'sidebar', name: 'seonix-page-audit', title: tipText, icon: makeIcon(), className: 'seonix-audit-panel' },
					body()
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
					body()
				)
			);
		}

		return el( Fragment, null, children );
	}

	registerPlugin( 'seonix-page-audit', { render: SeonixAuditPanel, icon: makeIcon() } );
} )( window.wp );
