/**
 * ShapeForms Integrations — Builder assign panel
 *
 * Only active (enabled) global connections appear here.
 * If none are active, a message + link to the integrations page is shown.
 * Each connection can be toggled on/off for this form.
 * Clicking the gear icon opens that connection's field mapping inline — others stay closed.
 */
( function ( $ ) {
	'use strict';

	var cfg      = window.shapeformsLiteBuilder || {};
	var adminUrl = cfg.integrationsAdminUrl || '';
	var labels   = cfg.labels || {};

	// Only active connections are relevant inside the builder.
	var globalConns = ( Array.isArray( cfg.globalConnections ) ? cfg.globalConnections : [] )
		.filter( function ( c ) { return c.status === 'active'; } );

	// The free plugin dispatches only these connection types. Any other assigned
	// connection is a leftover from when an add-on was active (a clean free install
	// cannot create one), so it renders as a locked upgrade teaser rather than a
	// working toggle that would silently never fire.
	//
	// showUpgradeCta is stringified by wp_localize_script, so it is '1' or '' here;
	// when an add-on turns the CTA off it dispatches every type and nothing locks.
	var showUpgradeCta = !! cfg.showUpgradeCta;
	var freeTypes      = Array.isArray( cfg.freeIntegrationTypes ) ? cfg.freeIntegrationTypes : [];

	function isLockedConn( conn ) {
		return showUpgradeCta && freeTypes.indexOf( String( conn.type || '' ) ) === -1;
	}

	// Per-form state — populated from formSettings on render.
	var assignedIds = [];   // string[]
	var fieldMap    = {};   // { [connId]: { email, fname, lname } }

	// =========================================================================
	// Helpers
	// =========================================================================

	// Escapes for HTML text AND attribute contexts: encodes < > & plus both quote
	// characters, so the result is safe inside quote-delimited attribute values.
	function escHtml( s ) {
		return $( '<div>' ).text( String( s || '' ) ).html()
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function connById( id ) {
		return globalConns.find( function ( c ) { return c.id === id; } ) || null;
	}

	function isAssigned( id ) {
		return assignedIds.indexOf( id ) !== -1;
	}

	// The number shown on the tab badge: assignments that are actually live on this
	// form. An assigned connection that no longer exists (deleted) or that is locked
	// (an add-on-only type the free plugin will not dispatch) is not counted, so the
	// badge reflects what will really fire rather than every id ever saved.
	function assignedCount() {
		return assignedIds.filter( function ( id ) {
			var conn = connById( id );
			return conn && ! isLockedConn( conn );
		} ).length;
	}

	function getFormFields() {
		var fields = [];
		var state  = window.shapeformsBuilderState;
		if ( ! state ) return fields;
		state.structure.rows.forEach( function ( row ) {
			row.columns.forEach( function ( col ) {
				col.fields.forEach( function ( f ) {
					var skip = [ 'submit', 'section_break', 'html_editor', 'paragraph' ];
					if ( skip.indexOf( f.type ) !== -1 ) return;
					fields.push( { id: f.id, label: f.label || f.type, type: f.type } );
				} );
			} );
		} );
		return fields;
	}

	// First email-type field on the form, used to pre-fill the Email mapping when a
	// connection is assigned — otherwise the mapping is required but left blank and
	// the dispatch silently no-ops.
	function defaultEmailFieldId() {
		var fields = getFormFields();
		for ( var i = 0; i < fields.length; i++ ) {
			if ( fields[ i ].type === 'email' ) return fields[ i ].id;
		}
		return '';
	}

	function fieldOptions( selectedId ) {
		var fields = getFormFields();
		var opts = '<option value="">— select field —</option>';
		fields.forEach( function ( f ) {
			opts += '<option value="' + escHtml( f.id ) + '"' +
				( f.id === selectedId ? ' selected' : '' ) + '>' +
				escHtml( f.label ) + '</option>';
		} );
		return opts;
	}

	// =========================================================================
	// Render
	// =========================================================================

	function renderPane( formSettings ) {
		if ( formSettings ) {
			assignedIds = Array.isArray( formSettings.assigned_connections )
				? formSettings.assigned_connections.slice() : [];
			fieldMap = ( formSettings.connection_field_map && typeof formSettings.connection_field_map === 'object' )
				? $.extend( true, {}, formSettings.connection_field_map ) : {};
		}

		var html = '<div class="bf-assign-pane">';

		// ── No active connections ───────────────────────────────────────────
		if ( ! globalConns.length ) {
			html +=
				'<div class="bf-assign-empty">' +
					'<span class="dashicons dashicons-randomize"></span>' +
					'<p>No active integrations found.</p>' +
					'<p style="font-size:12px;color:#94a3b8;margin-top:4px">Enable a connection on the Integrations page first, then come back to assign it to this form.</p>' +
					( adminUrl
						? '<a href="' + escHtml( adminUrl ) + '" target="_blank" class="button button-primary" style="margin-top:12px">Go to Integrations</a>'
						: '' ) +
				'</div>';
			html += '</div>';
			return html;
		}

		// ── Connection list ─────────────────────────────────────────────────
		html += '<div class="bf-assign-list">';

		globalConns.forEach( function ( conn ) {
			// Locked (add-on-only) type: a teaser row that opens the upgrade dialog.
			// It carries no functional toggle, so its saved assignment (if any) is
			// left untouched -- the free plugin would not dispatch it regardless.
			if ( isLockedConn( conn ) ) {
				html += lockedRow( conn );
				return;
			}

			var on  = isAssigned( conn.id );
			var map = fieldMap[ conn.id ] || {};

			html +=
				'<div class="bf-assign-item' + ( on ? ' is-on' : '' ) + '" data-conn-id="' + escHtml( conn.id ) + '">' +

					// Row: toggle + name/type + gear
					'<div class="bf-assign-item__row">' +
						'<label class="bf-int-toggle" title="' + ( on ? 'Disable' : 'Enable' ) + ' for this form">' +
							'<input type="checkbox" class="bf-assign-toggle" data-conn-id="' + escHtml( conn.id ) + '"' + ( on ? ' checked' : '' ) + '>' +
							'<span class="bf-int-toggle__track"></span>' +
						'</label>' +
						'<div class="bf-assign-item__info">' +
							'<span class="bf-assign-item__name">' + escHtml( conn.name ) + '</span>' +
							'<span class="bf-assign-item__type">' + escHtml( conn.type ) + '</span>' +
						'</div>' +
						( on
							? '<button type="button" class="bf-assign-gear" data-conn-id="' + escHtml( conn.id ) + '" title="Field mapping">' +
								'<span class="dashicons dashicons-admin-generic"></span>' +
							'</button>'
							: '<span class="bf-assign-gear-placeholder"></span>'
						) +
					'</div>' +

					// Field mapping panel — hidden by default, toggled by gear
					( on
						? '<div class="bf-assign-map-panel" id="bf-map-' + escHtml( conn.id ) + '" hidden>' +
							renderMapFields( conn.id, map ) +
						'</div>'
						: '' ) +

				'</div>';
		} );

		html += '</div>'; // .bf-assign-list

		// ── Manage link ─────────────────────────────────────────────────────
		if ( adminUrl ) {
			html +=
				'<div class="bf-assign-footer">' +
					'<a href="' + escHtml( adminUrl ) + '" target="_blank" class="bf-assign-manage-link">' +
						'<span class="dashicons dashicons-external"></span> Manage Integrations' +
					'</a>' +
				'</div>';
		}

		html += '</div>'; // .bf-assign-pane
		return html;
	}

	// A locked connection row: the same shape as a functional one, but a lock in
	// place of the toggle and an "Upgrade" pill in place of the gear. The whole row
	// opens the integration upgrade dialog; there is no field-mapping panel.
	function lockedRow( conn ) {
		return '<div class="bf-assign-item is-locked" data-conn-id="' + escHtml( conn.id ) + '">' +
			'<div class="bf-assign-item__row bf-assign-locked" role="button" tabindex="0"' +
				' aria-haspopup="dialog" title="' + escHtml( labels.integrationLocked || '' ) + '">' +
				'<span class="bf-assign-lock"><span class="dashicons dashicons-lock"></span></span>' +
				'<div class="bf-assign-item__info">' +
					'<span class="bf-assign-item__name">' + escHtml( conn.name ) + '</span>' +
					'<span class="bf-assign-item__type">' + escHtml( conn.type ) + '</span>' +
				'</div>' +
				'<span class="bf-assign-upgrade-pill">' + escHtml( labels.integrationUpgrade || 'Upgrade' ) + '</span>' +
			'</div>' +
		'</div>';
	}

	function renderMapFields( connId, map ) {
		return '<div class="bf-assign-map__rows">' +
			'<div class="bf-assign-map__row">' +
				'<label class="bf-assign-map__label">Email <span class="bf-assign-map__req">*</span></label>' +
				'<select class="bf-assign-map__select" data-conn-id="' + escHtml( connId ) + '" data-map-key="email">' +
					fieldOptions( map.email || '' ) +
				'</select>' +
			'</div>' +
			'<div class="bf-assign-map__row">' +
				'<label class="bf-assign-map__label">First Name</label>' +
				'<select class="bf-assign-map__select" data-conn-id="' + escHtml( connId ) + '" data-map-key="fname">' +
					fieldOptions( map.fname || '' ) +
				'</select>' +
			'</div>' +
			'<div class="bf-assign-map__row">' +
				'<label class="bf-assign-map__label">Last Name</label>' +
				'<select class="bf-assign-map__select" data-conn-id="' + escHtml( connId ) + '" data-map-key="lname">' +
					fieldOptions( map.lname || '' ) +
				'</select>' +
			'</div>' +
		'</div>';
	}

	// =========================================================================
	// Inject tab
	// =========================================================================

	function injectTab( formSettings ) {
		var $panel   = $( '#shapeforms-form-settings-panel' );
		var $navSlot = $panel.find( '.bfs-stab-nav-pro-slots' );
		var $content = $panel.find( '.bfs-stab-content' );
		if ( ! $navSlot.length || ! $content.length ) return;

		$panel.find( '.bfs-stab-nav-item[data-stab="integrations"]' ).remove();
		$panel.find( '.bfs-stab-pane[data-pane="integrations"]' ).remove();

		// Build the pane first: renderPane seeds assignedIds from this form's settings,
		// so the badge below counts THIS form's assignments. Reading the count before
		// this ran left the first render showing the previous form's count (or none).
		var $pane = $( '<div class="bfs-stab-pane" data-pane="integrations"></div>' );
		$pane.html( renderPane( formSettings ) );

		var count      = assignedCount();
		var countBadge = count ? ' <span class="bf-int-count-badge">' + count + '</span>' : '';

		$navSlot.append(
			'<button type="button" class="bfs-stab-nav-item" data-stab="integrations" data-stab-order="80">' +
				'<span class="bfs-stab-nav-icon"><span class="dashicons dashicons-randomize"></span></span>' +
				'<span class="bfs-stab-nav-text">' +
					'<span class="bfs-stab-nav-label">Integrations' + countBadge + '</span>' +
					'<span class="bfs-stab-nav-desc">Assign connections</span>' +
				'</span>' +
				'<span class="bfs-stab-nav-arrow">&#8250;</span>' +
			'</button>'
		);

		$content.append( $pane );
	}

	// =========================================================================
	// Events
	// =========================================================================

	$( document ).on( 'shapeforms:form_settings_rendered', function ( e, formSettings ) {
		injectTab( formSettings );
	} );

	// A locked row opens the shared integration upgrade dialog. The dialog's own
	// close controls (X, backdrop, Escape) are handled by the builder script, which
	// owns every .shapeforms-upgrade-modal on the page.
	$( document ).on( 'click keydown', '.bf-assign-locked', function ( e ) {
		if ( 'keydown' === e.type && 'Enter' !== e.key && ' ' !== e.key ) {
			return;
		}
		e.preventDefault();
		$( '#shapeforms-integration-upgrade-modal' ).removeAttr( 'hidden' );
		$( 'body' ).addClass( 'shapeforms-upgrade-modal-open' );
	} );

	// Toggle connection on/off for this form.
	$( document ).on( 'change', '.bf-assign-toggle', function () {
		var connId = String( $( this ).data( 'conn-id' ) );
		var on     = $( this ).is( ':checked' );
		var conn   = connById( connId );
		if ( ! conn ) return;

		if ( on ) {
			if ( assignedIds.indexOf( connId ) === -1 ) assignedIds.push( connId );
			if ( ! fieldMap[ connId ] ) fieldMap[ connId ] = { email: defaultEmailFieldId(), fname: '', lname: '' };
		} else {
			assignedIds = assignedIds.filter( function ( id ) { return id !== connId; } );
		}

		var $item = $( '.bf-assign-item[data-conn-id="' + connId + '"]' );
		$item.toggleClass( 'is-on', on );

		// Swap gear button in/out.
		var $row = $item.find( '.bf-assign-item__row' );
		$row.find( '.bf-assign-gear, .bf-assign-gear-placeholder' ).remove();
		if ( on ) {
			$row.append(
				'<button type="button" class="bf-assign-gear" data-conn-id="' + escHtml( connId ) + '" title="Field mapping">' +
					'<span class="dashicons dashicons-admin-generic"></span>' +
				'</button>'
			);
			// Add (hidden) map panel if not present.
			if ( ! $item.find( '.bf-assign-map-panel' ).length ) {
				$item.append(
					'<div class="bf-assign-map-panel" id="bf-map-' + escHtml( connId ) + '" hidden>' +
						renderMapFields( connId, fieldMap[ connId ] || {} ) +
					'</div>'
				);
			}
		} else {
			$row.append( '<span class="bf-assign-gear-placeholder"></span>' );
			$item.find( '.bf-assign-map-panel' ).remove();
		}

		// Update count badge.
		var $label = $( '.bfs-stab-nav-item[data-stab="integrations"] .bfs-stab-nav-label' );
		var count  = assignedCount();
		$label.find( '.bf-int-count-badge' ).remove();
		if ( count ) {
			$label.append( ' <span class="bf-int-count-badge">' + count + '</span>' );
		}
	} );

	// Gear click — toggle this connection's map panel; close all others.
	$( document ).on( 'click', '.bf-assign-gear', function () {
		var connId  = String( $( this ).data( 'conn-id' ) );
		var $panel  = $( '#bf-map-' + connId );
		var isOpen  = ! $panel.attr( 'hidden' );

		// Close all panels first.
		$( '.bf-assign-map-panel' ).attr( 'hidden', true );
		$( '.bf-assign-gear' ).removeClass( 'is-active' );

		if ( ! isOpen ) {
			$panel.removeAttr( 'hidden' );
			$( this ).addClass( 'is-active' );
		}
	} );

	// Field mapping select change.
	$( document ).on( 'change', '.bf-assign-map__select', function () {
		var connId = String( $( this ).data( 'conn-id' ) );
		var key    = String( $( this ).data( 'map-key' ) );
		if ( ! fieldMap[ connId ] ) fieldMap[ connId ] = {};
		fieldMap[ connId ][ key ] = $( this ).val() || '';
	} );

	// Before save — write back to formSettings.
	$( document ).on( 'shapeforms:before_save', function ( e, formSettings ) {
		formSettings.assigned_connections = assignedIds.slice();
		formSettings.connection_field_map = $.extend( true, {}, fieldMap );
	} );

}( jQuery ) );
