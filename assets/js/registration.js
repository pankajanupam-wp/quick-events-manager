/**
 * Shows a name field for each place a booking covers.
 *
 * The whole script. Every row already exists in the markup, translated and
 * escaped by PHP; all this does is decide how many of them are on screen and
 * which ones the browser is allowed to submit.
 *
 * Nothing here is required for a booking to succeed. With scripts off the form
 * posts a name, an email address and a number of places, exactly as it did
 * before guests existed, and the server creates that many places without names.
 */
( function () {
	'use strict';

	/**
	 * Wire one form's quantity field to its guest rows.
	 *
	 * @param {HTMLFormElement} form The registration form.
	 */
	function setup( form ) {
		var quantity = form.querySelector( '[data-qevm-quantity]' );
		var guests = form.querySelector( '[data-qevm-guests]' );

		if ( ! quantity || ! guests ) {
			return;
		}

		var rows = guests.querySelectorAll( '[data-qevm-guest]' );

		/**
		 * Match the visible rows to the number of places asked for.
		 */
		function update() {
			var places = parseInt( quantity.value, 10 );

			if ( isNaN( places ) || places < 1 ) {
				places = 1;
			}

			for ( var i = 0; i < rows.length; i++ ) {
				// The first row is the second place, so this row is place i + 2.
				var wanted = i + 2 <= places;
				var input = rows[ i ].querySelector( 'input' );

				rows[ i ].hidden = ! wanted;

				/*
				 * A disabled field is not submitted, which is the point: a
				 * booking for one should not post nineteen empty names.
				 */
				if ( input ) {
					input.disabled = ! wanted;
				}
			}

			guests.hidden = places < 2;
		}

		quantity.addEventListener( 'input', update );
		quantity.addEventListener( 'change', update );

		/*
		 * Run once on load as well. A browser restoring a form after the back
		 * button can hand back a quantity above one, and the rows have to agree
		 * with it before anybody looks at the page.
		 */
		update();
	}

	/**
	 * Put the keyboard where the problem is.
	 *
	 * The error summary carries role="alert", but a live region that is already
	 * in the document when it parses announces nothing — screen readers speak
	 * regions that change *after* load, and this one arrives with the page.
	 * Moving focus into it is what makes the message heard, and it saves every
	 * keyboard user tabbing back down from the top of the page to the field
	 * that failed.
	 *
	 * The first invalid field is preferred over the summary, because that is
	 * where the work is. The summary is the fallback for an error that belongs
	 * to the submission as a whole rather than to any one input.
	 */
	function focusFirstError() {
		var target = document.querySelector( '[data-qevm-registration-form] [aria-invalid="true"]' )
			|| document.querySelector( '[data-qevm-error-summary]' );

		if ( ! target ) {
			return;
		}

		/*
		 * After the next paint, not now.
		 *
		 * The redirect ends in #qevm-registration, and the browser acts on that
		 * fragment after DOMContentLoaded — scrolling the page and, in Chrome,
		 * resetting the focused element. Focusing here directly worked and was
		 * then silently undone: the field ended up correctly marked invalid,
		 * correctly described, and not focused. Two frames puts this after the
		 * browser has finished with the fragment.
		 */
		requestAnimationFrame( function () {
			requestAnimationFrame( function () {
				/*
				 * Only if nothing else has focus. Somebody using a slow
				 * connection may already have started typing by the time this
				 * runs, and yanking the caret out of the field they chose is
				 * worse than not announcing the error.
				 */
				if ( document.activeElement && document.activeElement !== document.body ) {
					return;
				}

				target.focus();
			} );
		} );
	}

	/**
	 * Set up every registration form on the page.
	 */
	function init() {
		var forms = document.querySelectorAll( '[data-qevm-registration-form]' );

		for ( var i = 0; i < forms.length; i++ ) {
			setup( forms[ i ] );
		}

		focusFirstError();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
