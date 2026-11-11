/**
 * ShapeForms admin notices — generic persistent dismissal.
 *
 * Any notice rendered with `.shapeforms-admin-notice`, a `data-notice-id`
 * attribute, and a `.shapeforms-admin-notice__dismiss` button gets persistent
 * dismissal for free: on close it records the notice id for the current user
 * (so it stays closed on later page loads) and removes the banner.
 *
 * Config (ajax URL, action, nonce) is provided via wp_localize_script as
 * `shapeformsAdminNotice`.
 */
( function( $ ) {
	'use strict';

	var config = window.shapeformsAdminNotice || {};

	$( document ).on( 'click', '.shapeforms-admin-notice__dismiss', function() {
		var $notice   = $( this ).closest( '.shapeforms-admin-notice' );
		var noticeId  = $notice.data( 'noticeId' );

		if ( config.ajaxUrl && config.action && config.nonce && noticeId ) {
			$.post( config.ajaxUrl, {
				action: config.action,
				nonce: config.nonce,
				notice_id: noticeId
			} );
		}

		$notice.fadeOut( 150, function() {
			$( this ).remove();
		} );
	} );
} )( jQuery );
