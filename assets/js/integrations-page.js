/**
 * ShapeForms Integrations Page JS
 *
 * Unified list: each integration = row with toggle + settings gear.
 * Toggle enables/disables via AJAX. Settings gear opens config modal.
 */
( function ( $ ) {
	'use strict';

	var cfg       = window.shapeformsIntegrationsPage || {};
	var ajaxUrl   = cfg.ajaxUrl   || '';
	var nonce     = cfg.nonce     || '';
	var i18n      = cfg.i18n      || {};
	var typeDefs  = Array.isArray( cfg.typeDefs ) ? cfg.typeDefs : [];

	// Live connections state — object keyed by conn_id.
	var connections = ( cfg.connections && typeof cfg.connections === 'object' ) ? $.extend( true, {}, cfg.connections ) : {};

	// Currently editing.
	var editingType = null;
	var editingConn = null;
	var fetchedLists = [];

	// Set to a type slug when the admin clicked the Enable toggle on an
	// unconfigured integration: the toggle was refused (needs_setup) and we
	// opened the settings modal instead. After a successful Save we consume
	// this intent and flip the integration on automatically, so a single
	// Enable click doesn't require a second click after saving.
	var pendingEnableType = null;

	// =====================================================================
	// Helpers
	// =====================================================================

	// Escapes for HTML text AND attribute contexts: encodes < > & plus both quote
	// characters, so the result is safe inside quote-delimited attribute values.
	function escHtml( s ) {
		return $( '<div>' ).text( String( s || '' ) ).html()
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function typeDefBySlug( type ) {
		for ( var i = 0; i < typeDefs.length; i++ ) {
			if ( typeDefs[ i ].type === type ) return typeDefs[ i ];
		}
		return null;
	}

	/** True when a type def declares a field with the given key. */
	function defHasField( def, key ) {
		return !! ( def && Array.isArray( def.fields ) && def.fields.some( function ( f ) {
			return f && f.key === key;
		} ) );
	}

	/** True when a type def has a list_select (dropdown) field. */
	function defHasListSelect( def ) {
		return !! ( def && Array.isArray( def.fields ) && def.fields.some( function ( f ) {
			return f && f.type === 'list_select';
		} ) );
	}

	/**
	 * Keyless integrations have no api_key field — e.g. FluentCRM, which runs
	 * on the same site and reads lists through its local API. These connect
	 * (and load lists) without the admin entering any key.
	 */
	function isKeylessType( def ) {
		return !! ( def && ! defHasField( def, 'api_key' ) );
	}

	/**
	 * True when a type's lists can load the moment the modal opens — i.e. a
	 * keyless type with a list dropdown (FluentCRM). Keyless types WITHOUT a
	 * dropdown (e.g. Google Sheets) still need the admin to fill other required
	 * fields first, so they wait for an explicit Test Connection click.
	 */
	function canAutoLoadOnOpen( def ) {
		return isKeylessType( def ) && defHasListSelect( def );
	}

	/** Find connection object for a given type. */
	function connByType( type ) {
		for ( var k in connections ) {
			if ( connections.hasOwnProperty( k ) && connections[ k ].type === type ) {
				return connections[ k ];
			}
		}
		return null;
	}

	// =====================================================================
	// Toggle (enable/disable)
	// =====================================================================

	$( document ).on( 'change', '.bf-toggle-input', function () {
		var $cb   = $( this );
		var type  = String( $cb.data( 'type' ) );
		var on    = $cb.is( ':checked' );
		var $row  = $cb.closest( '.bf-int-card' );

		$cb.prop( 'disabled', true );

		$.post( ajaxUrl, {
			action: 'shapeforms_connection_toggle',
			nonce:  nonce,
			type:   type,
			enable: on ? 'true' : 'false',
		} )
		.done( function ( res ) {
			if ( res && res.success ) {
				$row.toggleClass( 'is-on', on );
				if ( res.data && res.data.connection ) {
					connections[ res.data.connection.id ] = res.data.connection;
					$row.attr( 'data-conn-id', res.data.connection.id );
				}
			} else {
				// Revert the toggle.
				$cb.prop( 'checked', ! on );
				// Enabling was refused because no API key is stored yet — open the
				// settings modal so the admin can add credentials first.
				if ( res && res.data && 'needs_setup' === res.data.code ) {
					// Remember the admin wanted this ON so Save can enable it for them.
					pendingEnableType = type;
					$( '.bf-settings-btn[data-type="' + type + '"]' ).trigger( 'click' );
				}
			}
		} )
		.fail( function () {
			$cb.prop( 'checked', ! on );
		} )
		.always( function () {
			$cb.prop( 'disabled', false );
		} );
	} );

	// =====================================================================
	// Settings button → open modal
	// =====================================================================

	$( document ).on( 'click', '.bf-settings-btn', function () {
		var type = String( $( this ).data( 'type' ) );
		var def  = typeDefBySlug( type );
		if ( ! def ) return;

		editingType  = type;
		editingConn  = connByType( type );
		fetchedLists = [];

		var title = escHtml( def.label ) + ' Settings';
		$( '#bf-conn-modal-title' ).html( title );
		$( '#bf-conn-modal-status' ).text( '' ).removeClass( 'is-error is-ok' );
		$( '#bf-conn-modal-body' ).html( buildModalBody( def, editingConn ) );

		// Pre-load lists if a key is already stored (fetched server-side by conn
		// ID), or for keyless dropdown integrations (e.g. FluentCRM) that need no
		// input. Keyless types requiring fields first (Google Sheets) wait for a
		// manual Test Connection.
		if ( ( editingConn && editingConn.has_key ) || canAutoLoadOnOpen( def ) ) {
			doFetchLists( false );
		}

		$( '#bf-conn-modal' ).removeAttr( 'hidden' );
		$( '#bf-conn-modal-body input' ).first().trigger( 'focus' );
	} );

	// =====================================================================
	// Modal
	// =====================================================================

	function closeModal() {
		$( '#bf-conn-modal' ).attr( 'hidden', true );
		editingType       = null;
		editingConn       = null;
		fetchedLists      = [];
		// Drop any pending enable intent; doSave reads it before calling this,
		// so a Cancel/Escape close correctly abandons the auto-enable.
		pendingEnableType = null;
	}

	function buildModalBody( def, conn ) {
		conn = conn || {};
		var html = '';

		// Type-specific fields.
		if ( Array.isArray( def.fields ) && def.fields.length ) {
			def.fields.forEach( function ( field ) {
				if ( field.type === 'list_select' ) {
					html += buildListSelectRow( def, conn );
					return;
				}

				if ( field.type === 'checkbox' ) {
					html +=
						'<div class="bf-modal-row bf-modal-row--inline">' +
							'<label class="bf-modal-check-label">' +
								'<input type="checkbox" id="bf-conn-' + escHtml( field.key ) + '" data-field-key="' + escHtml( field.key ) + '"' + ( conn[ field.key ] ? ' checked' : '' ) + '>' +
								' ' + escHtml( field.label ) +
							'</label>' +
						'</div>';
					return;
				}

				var inputType  = field.type === 'password' ? 'password' : 'text';
				var isPassword = 'password' === inputType;
				// Secrets are never sent to the browser. A password field stays empty;
				// when a key is already stored, the placeholder tells the admin they can
				// leave it blank to keep it. All other fields prefill their saved value.
				var fieldValue  = isPassword ? '' : ( conn[ field.key ] || '' );
				var placeholder = ( isPassword && conn.has_key )
					? ( i18n.keepKey || 'Saved — leave blank to keep current key' )
					: ( field.placeholder || '' );
				html +=
					'<div class="bf-modal-row">' +
						'<label class="bf-modal-label" for="bf-conn-' + escHtml( field.key ) + '">' +
							escHtml( field.label ) +
							( field.required ? ' <span class="bf-modal-req">*</span>' : '' ) +
						'</label>' +
						( field.type === 'textarea'
							? '<textarea id="bf-conn-' + escHtml( field.key ) + '" data-field-key="' + escHtml( field.key ) + '" class="large-text" rows="4" placeholder="' + escHtml( placeholder ) + '">' + escHtml( fieldValue ) + '</textarea>'
							: '<input type="' + inputType + '" id="bf-conn-' + escHtml( field.key ) + '" data-field-key="' + escHtml( field.key ) + '" class="regular-text" value="' + escHtml( fieldValue ) + '" placeholder="' + escHtml( placeholder ) + '" autocomplete="' + ( isPassword ? 'new-password' : 'off' ) + '">'
						) +
					'</div>';
			} );
		} else {
			html += '<p class="bf-modal-hint-text">No configuration fields available for this integration.</p>';
		}

		// Field mapping note.
		html +=
			'<div class="bf-modal-section-title">Field Mapping</div>' +
			'<p class="bf-modal-hint-text">Email, first name, and last name are mapped per-form in the form builder\'s Integrations panel.</p>';

		return html;
	}

	function buildListSelectRow( def, conn ) {
		conn = conn || {};
		var listLabel = def.list_label || 'List';
		var keyless   = isKeylessType( def );

		// Keyless types (e.g. FluentCRM) auto-load on open, so show the spinner.
		if ( ! conn.has_key && ! keyless ) {
			return (
				'<div class="bf-modal-row" id="bf-list-row">' +
					'<label class="bf-modal-label">' + escHtml( listLabel ) + '</label>' +
					'<p class="bf-modal-hint-text">Enter your API key and click <strong>Test Connection</strong> to load lists.</p>' +
				'</div>'
			);
		}

		return (
			'<div class="bf-modal-row" id="bf-list-row">' +
				'<label class="bf-modal-label">' + escHtml( listLabel ) + ' <span class="bf-modal-req">*</span></label>' +
				'<span class="bf-loading-msg"><span class="bf-spinner"></span> Loading…</span>' +
			'</div>'
		);
	}

	function renderListSelect( lists, savedId ) {
		var def = typeDefBySlug( editingType );
		var listLabel = def ? ( def.list_label || 'List' ) : 'List';

		if ( ! lists || ! lists.length ) {
			$( '#bf-list-row' ).html(
				'<label class="bf-modal-label">' + escHtml( listLabel ) + '</label>' +
				'<p class="bf-modal-hint-text is-error">No lists found.</p>'
			);
			return;
		}

		var opts = '<option value="">— ' + escHtml( i18n.selectList || 'select' ) + ' —</option>' +
			lists.map( function ( l ) {
				return '<option value="' + escHtml( l.id ) + '"' + ( String( l.id ) === String( savedId || '' ) ? ' selected' : '' ) + '>' + escHtml( l.name ) + '</option>';
			} ).join( '' );

		$( '#bf-list-row' ).html(
			'<label class="bf-modal-label">' + escHtml( listLabel ) + ' <span class="bf-modal-req">*</span></label>' +
			'<select id="bf-conn-list_id" data-field-key="list_id">' + opts + '</select>'
		);
	}

	// =====================================================================
	// AJAX
	// =====================================================================

	function doFetchLists( fromTestBtn ) {
		if ( fromTestBtn ) {
			setStatus( i18n.testing || 'Testing…', '' );
			$( '#bf-conn-test-btn' ).prop( 'disabled', true ).text( i18n.testing || 'Testing…' );
		}

		var typedKey = $.trim( $( '#bf-conn-api_key' ).val() || '' );
		var keyless  = isKeylessType( typeDefBySlug( editingType ) );

		var onDone = function ( res ) {
			if ( res && res.success ) {
				fetchedLists = res.data.lists || [];
				renderListSelect( fetchedLists, editingConn ? editingConn.list_id : '' );
				if ( fromTestBtn ) setStatus( i18n.testOk || 'Connected!', 'is-ok' );
			} else {
				var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Failed.';
				setStatus( ( i18n.testFail || 'Failed: ' ) + msg, 'is-error' );
				renderListSelect( [], editingConn ? editingConn.list_id : '' ); // clear the loading spinner
			}
		};
		var onFail   = function () { setStatus( 'Request failed.', 'is-error' ); };
		var onAlways = function () {
			$( '#bf-conn-test-btn' ).prop( 'disabled', false ).text( i18n.test || 'Test Connection' );
		};

		if ( typedKey || keyless ) {
			// Validate the freshly-typed key, or — for keyless integrations such
			// as FluentCRM — connect with no key at all (server ignores api_key).
			$.post( ajaxUrl, {
				action:  'shapeforms_connection_test',
				nonce:   nonce,
				type:    editingType,
				api_key: typedKey,
				extra:   collectExtraFields(),
			} ).done( onDone ).fail( onFail ).always( onAlways );
		} else if ( editingConn && editingConn.has_key && editingConn.id ) {
			// No new key typed — fetch lists using the key stored server-side.
			$.post( ajaxUrl, {
				action:  'shapeforms_connection_fetch_lists',
				nonce:   nonce,
				conn_id: editingConn.id,
			} ).done( onDone ).fail( onFail ).always( onAlways );
		} else {
			if ( fromTestBtn ) setStatus( i18n.errRequired || 'API Key is required.', 'is-error' );
			onAlways();
		}
	}

	function doSave() {
		var data = collectFormData();

		$( '#bf-conn-save-btn' ).prop( 'disabled', true ).text( i18n.saving || 'Saving…' );
		setStatus( '', '' );

		$.post( ajaxUrl, {
			action:     'shapeforms_connection_save',
			nonce:      nonce,
			connection: data,
		} )
		.done( function ( res ) {
			if ( res && res.success && res.data.connection ) {
				var saved = res.data.connection;
				connections[ saved.id ] = saved;

				// Update row attr.
				var $row = $( '.bf-int-card[data-type="' + saved.type + '"]' );
				$row.attr( 'data-conn-id', saved.id );

				// If the admin reached this modal by clicking Enable on an
				// unconfigured integration, honour that intent now: flip the
				// toggle on so they don't have to click it a second time.
				var enableNow = ( pendingEnableType === saved.type );

				closeModal();

				if ( enableNow ) {
					var $cb = $row.find( '.bf-toggle-input' );
					if ( $cb.length && ! $cb.is( ':checked' ) ) {
						$cb.prop( 'checked', true ).trigger( 'change' );
					}
				}
			} else {
				setStatus( ( res && res.data && res.data.message ) || 'Save failed.', 'is-error' );
			}
		} )
		.fail( function () { setStatus( 'Request failed.', 'is-error' ); } )
		.always( function () {
			$( '#bf-conn-save-btn' ).prop( 'disabled', false ).text( i18n.save || 'Save' );
		} );
	}

	function collectFormData() {
		var data = {
			id:      editingConn ? editingConn.id : '',
			type:    editingType,
			name:    '', // auto from PHP
			status:  editingConn ? editingConn.status : 'inactive',
			api_key: '',
			list_id: '',
		};

		$( '#bf-conn-modal-body [data-field-key]' ).each( function () {
			var key = $( this ).data( 'field-key' );
			if ( ! key ) return;
			data[ key ] = $( this ).is( ':checkbox' ) ? $( this ).is( ':checked' ) : ( $( this ).val() || '' );
		} );

		return data;
	}

	function collectExtraFields() {
		var extra = {};
		$( '#bf-conn-modal-body [data-field-key]' ).each( function () {
			var key = $( this ).data( 'field-key' );
			if ( key && key !== 'api_key' && key !== 'list_id' ) {
				extra[ key ] = $( this ).val() || '';
			}
		} );
		return extra;
	}

	function setStatus( msg, cls ) {
		$( '#bf-conn-modal-status' ).text( msg ).removeClass( 'is-error is-ok' ).addClass( cls || '' );
	}

	// =====================================================================
	// Event delegation
	// =====================================================================

	$( document ).on( 'click', '#bf-conn-modal-close, #bf-conn-modal-cancel, #bf-conn-modal-backdrop', closeModal );
	$( document ).on( 'click', '.bf-conn-modal__dialog', function ( e ) { e.stopPropagation(); } );
	$( document ).on( 'keydown', function ( e ) { if ( e.key === 'Escape' ) closeModal(); } );
	$( document ).on( 'click', '#bf-conn-test-btn', function () { doFetchLists( true ); } );
	$( document ).on( 'click', '#bf-conn-save-btn', doSave );

}( jQuery ) );
