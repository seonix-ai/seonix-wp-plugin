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

	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6.
	var Panel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel ) ||
		null;

	if ( ! registerPlugin || ! Panel ) {
		return;
	}

	var data = window.seonixAudit || null;
	if ( ! data ) {
		return;
	}

	function head() {
		return el(
			'div',
			{ className: 'seonix-mb-head' },
			el( 'span', { className: 'seonix-mb-light seonix-mb-light--' + ( data.light || 'mute' ) } ),
			el(
				'div',
				{ className: 'seonix-mb-headtext' },
				el( 'div', { className: 'seonix-mb-verdict' }, data.verdict || '' ),
				data.sub ? el( 'div', { className: 'seonix-mb-sub' }, data.sub ) : null
			)
		);
	}

	function issue( iss, key ) {
		return el(
			'div',
			{ className: 'seonix-mb-issue', key: key },
			el( 'span', { className: 'seonix-pagedot seonix-pagedot--' + iss.severity, 'aria-hidden': 'true' } ),
			el(
				'div',
				{ className: 'seonix-mb-issuebody' },
				el(
					'div',
					{ className: 'seonix-mb-issuetitle' },
					iss.title,
					iss.informational
						? el( 'span', { className: 'seonix-task__info', style: { marginLeft: '6px' } }, ( data.i18n && data.i18n.optional ) || 'Optional' )
						: null
				),
				iss.recommendation ? el( 'div', { className: 'seonix-mb-issuerec' }, iss.recommendation ) : null
			)
		);
	}

	function group( g, gi ) {
		var items = ( g.items || [] ).map( function ( iss, i ) {
			return issue( iss, 'i' + gi + '-' + i );
		} );
		return el(
			'div',
			{ className: 'seonix-mb-group', key: 'g' + gi },
			el(
				'div',
				{ className: 'seonix-mb-grouphead' },
				el( 'span', { className: 'seonix-cat seonix-cat--' + g.key }, g.label ),
				el( 'span', { className: 'seonix-mb-groupcount' }, String( ( g.items || [] ).length ) )
			),
			items
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
		var children = [ head() ];
		( data.groups || [] ).forEach( function ( g, gi ) {
			children.push( group( g, gi ) );
		} );
		children.push( foot() );
		return el( 'div', { className: 'seonix-metabox seonix-metabox--panel' }, children );
	}

	var brandIcon = el(
		'svg',
		{ width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none' },
		el( 'path', {
			d: 'M12 3.2 19 6v5.2c0 4.2-3 7.4-7 9.2-4-1.8-7-5-7-9.2V6z',
			fill: 'none',
			stroke: '#8B3DFF',
			strokeWidth: '1.7',
			strokeLinejoin: 'round',
		} ),
		el( 'path', { d: 'M9 12l2 2 4-4.4', fill: 'none', stroke: '#8B3DFF', strokeWidth: '1.7', strokeLinecap: 'round', strokeLinejoin: 'round' } )
	);

	function SeonixAuditPanel() {
		return el(
			Fragment,
			null,
			el(
				Panel,
				{ name: 'seonix-page-audit', title: data.title || 'Seonix — Page audit', className: 'seonix-audit-panel' },
				body()
			)
		);
	}

	registerPlugin( 'seonix-page-audit', { render: SeonixAuditPanel, icon: brandIcon } );
} )( window.wp );
