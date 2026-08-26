/**
 * Quick Events Manager — editor screen.
 *
 * One job: hide the fields that do not apply to the kind of event being
 * edited. No build step and no framework — the whole interaction is a class
 * toggle, and shipping a bundle to do it would be absurd.
 *
 * Everything here is progressive enhancement. With the script blocked, every
 * field is visible and every one of them still saves — hidden fields are hidden,
 * not disabled, so the form is never less capable than the markup it came from.
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

	document.addEventListener( 'DOMContentLoaded', function () {
		var repeats = document.getElementById( 'qevm_repeats' );
		var frequency = document.getElementById( 'qevm_repeat_freq' );

		if ( ! repeats || ! frequency ) {
			return;
		}

		var repeatOnly = document.querySelectorAll( '.qevm-repeat-only' );
		var weekly = document.querySelectorAll( '.qevm-repeat-weekly' );
		var monthly = document.querySelectorAll( '.qevm-repeat-monthly' );

		function show( elements, visible ) {
			Array.prototype.forEach.call( elements, function ( element ) {
				element.hidden = ! visible;
			} );
		}

		function sync() {
			show( repeatOnly, repeats.checked );
			show( weekly, repeats.checked && 'WEEKLY' === frequency.value );
			show( monthly, repeats.checked && 'MONTHLY' === frequency.value );
		}

		repeats.addEventListener( 'change', sync );
		frequency.addEventListener( 'change', sync );
		sync();
	} );
}() );
