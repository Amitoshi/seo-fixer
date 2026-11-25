/**
 * SEO Fixer — remembers when a notice's "×" is clicked.
 *
 * Any notice with data-seofx-dismiss-action and data-seofx-nonce qualifies.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.seofx-dismissible-notice .notice-dismiss' );
		if ( ! button || ! window.ajaxurl ) {
			return;
		}

		var notice = button.closest( '.seofx-dismissible-notice' );
		var body = new window.URLSearchParams();
		body.append( 'action', notice.getAttribute( 'data-seofx-dismiss-action' ) );
		body.append( 'nonce', notice.getAttribute( 'data-seofx-nonce' ) );

		window.fetch( window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} );
	} );
} )();
