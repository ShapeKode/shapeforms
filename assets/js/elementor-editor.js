/* global shapeformsElementorEditor, elementor */
( function ( $ ) {
	'use strict';

	var base = shapeformsElementorEditor.builderBase;

	function updateLink( formId ) {
		var $link = $( '.shapeforms-edit-form-link' );
		if ( ! $link.length ) {
			return;
		}
		if ( formId && formId !== '0' ) {
			$link.attr( 'href', base + formId );
		} else {
			$link.attr( 'href', '#' );
		}
	}

	function getFormIdFromModel( model ) {
		try {
			return model.get( 'settings' ).get( 'form_id' ) || '0';
		} catch ( e ) {
			return '0';
		}
	}

	$( window ).on( 'load', function () {
		if ( typeof elementor === 'undefined' ) {
			return;
		}

		// Hook fires when any widget panel opens — model is the widget model.
		elementor.hooks.addAction( 'panel/open_editor/widget/shapeforms', function ( panel, model ) {
			// Set link immediately when panel opens.
			updateLink( getFormIdFromModel( model ) );

			// Listen for form_id changes on the settings model.
			model.get( 'settings' ).on( 'change:form_id', function ( settings, value ) {
				updateLink( value || '0' );
			} );
		} );
	} );

} )( jQuery );
