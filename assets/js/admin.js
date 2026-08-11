/**
 * Quick Events Manager — editor screen.
 *
 * One job: hide the fields that do not apply to the kind of event being
 * edited. No build step and no framework — the whole interaction is a class
 * toggle, and shipping a bundle to do it would be absurd.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var onlineToggle = document.getElementById( 'qevm_is_online' );

		if ( ! onlineToggle ) {
			return;
		}

		var onlineOnly = document.querySelectorAll( '.qevm-online-only' );
		var venueOnly = document.querySelectorAll( '.qevm-venue-only' );

		function sync() {
			var isOnline = onlineToggle.checked;

			Array.prototype.forEach.call( onlineOnly, function ( element ) {
				element.hidden = ! isOnline;
			} );

			Array.prototype.forEach.call( venueOnly, function ( element ) {
				element.hidden = isOnline;
			} );
		}

		onlineToggle.addEventListener( 'change', sync );
		sync();
	} );
}() );
