/**
 * Quick Events Manager — scanning a ticket at the door.
 *
 * An enhancement over a form that already works. The person on the door can
 * always type or scan a code into the box and press the button; this adds a
 * camera to the same box, and only when the browser can actually decode one.
 *
 * `BarcodeDetector` is used rather than a bundled decoder. Decoding a QR code
 * is the encoder's problem again plus perspective correction, and shipping
 * that would be a large amount of code to do worse than the browser does it
 * natively. Where the API is missing — Safari and Firefox, today — nothing is
 * offered and nothing is broken: the box is still there and a hardware scanner
 * that types for you still works.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '[data-qevm-scan]' );

		if ( ! form ) {
			return;
		}

		var field = form.querySelector( '[data-qevm-code]' );
		var slot = form.querySelector( '[data-qevm-scanner]' );
		var strings = window.qevmCheckIn || {};

		if ( ! field || ! slot ) {
			return;
		}

		// The box is where the door's attention is; put the cursor in it.
		field.focus();

		var supported = 'BarcodeDetector' in window &&
			navigator.mediaDevices &&
			typeof navigator.mediaDevices.getUserMedia === 'function';

		if ( ! supported ) {
			return;
		}

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'button';
		button.textContent = strings.scan || 'Scan a ticket';

		var status = document.createElement( 'span' );

		status.className = 'qevm-door__scanstate';
		status.setAttribute( 'role', 'status' );

		var video = document.createElement( 'video' );

		video.className = 'qevm-door__video';
		video.setAttribute( 'playsinline', '' );
		video.setAttribute( 'muted', '' );
		video.hidden = true;

		slot.appendChild( button );
		slot.appendChild( status );
		slot.appendChild( video );

		var stream = null;
		var timer = null;

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}

			if ( stream ) {
				stream.getTracks().forEach( function ( track ) {
					track.stop();
				} );

				stream = null;
			}

			video.hidden = true;
			button.textContent = strings.scan || 'Scan a ticket';
			status.textContent = '';
		}

		/*
		 * The camera is released before the form submits. A page that navigates
		 * away with a live track leaves the light on for a moment, which reads
		 * as the site watching the room.
		 */
		window.addEventListener( 'pagehide', stop );

		function found( code ) {
			stop();

			field.value = code;
			form.submit();
		}

		function start() {
			var detector = new window.BarcodeDetector( { formats: [ 'qr_code' ] } );

			navigator.mediaDevices
				.getUserMedia( { video: { facingMode: 'environment' } } )
				.then( function ( opened ) {
					stream = opened;
					video.srcObject = opened;
					video.hidden = false;
					button.textContent = strings.stop || 'Stop scanning';
					status.textContent = strings.looking || '';

					return video.play();
				} )
				.then( function () {
					timer = window.setInterval( function () {
						detector
							.detect( video )
							.then( function ( codes ) {
								if ( codes.length && codes[ 0 ].rawValue ) {
									found( codes[ 0 ].rawValue.trim().toUpperCase() );
								}
							} )
							.catch( function () {
								/*
								 * A frame that cannot be read is the normal
								 * case between one ticket and the next, not an
								 * error worth showing anybody.
								 */
							} );
					}, 400 );
				} )
				.catch( function () {
					stop();
					status.textContent = strings.noCamera || '';
				} );
		}

		button.addEventListener( 'click', function () {
			if ( stream ) {
				stop();

				return;
			}

			start();
		} );
	} );
}() );
