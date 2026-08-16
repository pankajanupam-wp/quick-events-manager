/**
 * Calendar navigation and keyboard movement.
 *
 * Everything here is an enhancement over markup that already works. The
 * previous and next controls are real links; without this file they navigate,
 * and the calendar is fully usable. With it they swap the month in place.
 *
 * The new month's HTML comes from fetching the very URL the link points at and
 * lifting the calendar out of the response — not from a separate endpoint
 * returning a fragment. A fragment endpoint would be faster and would be a
 * second render path, and this plugin has already been bitten twice by two
 * paths that were supposed to agree and quietly stopped. Fetching the page the
 * link would have loaded makes them the same path by definition.
 *
 * @package QuickEventsManager
 */

( function () {
	'use strict';

	/**
	 * Whether a calendar is currently being fetched.
	 *
	 * Guards against somebody holding down "next" and stacking requests that
	 * then land out of order, leaving the grid on a month nobody asked for.
	 *
	 * @type {boolean}
	 */
	var busy = false;

	/**
	 * Wire one calendar up.
	 *
	 * @param {HTMLElement} calendar Calendar root.
	 */
	function enhance( calendar ) {
		calendar.querySelectorAll( '[data-qevm-calendar-prev], [data-qevm-calendar-next], [data-qevm-calendar-today]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				/*
				 * Modified clicks are the browser's business. Ctrl-clicking to
				 * open next month in a tab must keep working.
				 */
				if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 0 !== event.button ) {
					return;
				}

				event.preventDefault();

				load( calendar, link.href, link );
			} );
		} );

		grid( calendar );
	}

	/**
	 * Fetch a month and swap it in.
	 *
	 * @param {HTMLElement} calendar Calendar root.
	 * @param {string}      url      URL to load.
	 * @param {HTMLElement} source   Element that triggered it, to keep focus on.
	 */
	function load( calendar, url, source ) {
		if ( busy ) {
			return;
		}

		busy = true;
		calendar.setAttribute( 'aria-busy', 'true' );

		window.fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'calendar request failed' );
				}

				return response.text();
			} )
			.then( function ( html ) {
				var parsed = new window.DOMParser().parseFromString( html, 'text/html' );
				var fresh = parsed.querySelector( '[data-qevm-calendar]' );

				if ( ! fresh ) {
					throw new Error( 'no calendar in response' );
				}

				calendar.replaceWith( fresh );

				window.history.pushState( {}, '', url );

				enhance( fresh );
				announce( fresh );

				/*
				 * Focus goes back to the control that was pressed, so somebody
				 * stepping through months can keep pressing it. The month
				 * itself is announced through the live region rather than by
				 * moving focus — see the note in the template about why the two
				 * cases differ.
				 */
				var again = fresh.querySelector( selectorFor( source ) );

				if ( again ) {
					again.focus();
				}
			} )
			.catch( function () {
				/*
				 * Fall back to the navigation that would have happened anyway.
				 * A calendar stuck on one month because a request failed is
				 * worse than a page load.
				 */
				window.location.href = url;
			} )
			.finally( function () {
				busy = false;
				calendar.removeAttribute( 'aria-busy' );
			} );
	}

	/**
	 * The attribute identifying which control was used.
	 *
	 * @param {HTMLElement} source Element that triggered the load.
	 * @return {string} A selector matching the same control in the new markup.
	 */
	function selectorFor( source ) {
		if ( source && source.hasAttribute( 'data-qevm-calendar-prev' ) ) {
			return '[data-qevm-calendar-prev]';
		}

		if ( source && source.hasAttribute( 'data-qevm-calendar-today' ) ) {
			return '[data-qevm-calendar-today]';
		}

		return '[data-qevm-calendar-next]';
	}

	/**
	 * Say which month is now showing, and how much is in it.
	 *
	 * @param {HTMLElement} calendar Calendar root.
	 */
	function announce( calendar ) {
		var status = calendar.querySelector( '[data-qevm-calendar-status]' );
		var month = calendar.querySelector( '.qevm-calendar__month' );
		var summary = calendar.querySelector( '.qevm-calendar__summary' );

		if ( ! status || ! month ) {
			return;
		}

		status.textContent = month.textContent.trim() + ( summary ? ', ' + summary.textContent.trim() : '' );
	}

	/**
	 * Arrow-key movement between days.
	 *
	 * A roving tabindex, added here and never rendered by PHP. Without script
	 * the cells are not focusable at all and Tab reaches the event links
	 * directly, which is a complete way to use the calendar; with script one
	 * cell is in the tab order and the arrows move between them, which is what
	 * anybody who has used a calendar widget will try.
	 *
	 * @param {HTMLElement} calendar Calendar root.
	 */
	function grid( calendar ) {
		var days = Array.prototype.slice.call( calendar.querySelectorAll( '[data-qevm-day]' ) );

		if ( 0 === days.length ) {
			return;
		}

		var current = calendar.querySelector( '.qevm-calendar__day--today[data-qevm-day]' ) || days[ 0 ];

		days.forEach( function ( day ) {
			day.setAttribute( 'tabindex', day === current ? '0' : '-1' );
		} );

		calendar.addEventListener( 'keydown', function ( event ) {
			var day = event.target.closest ? event.target.closest( '[data-qevm-day]' ) : null;

			if ( ! day || day !== event.target ) {
				return;
			}

			var index = days.indexOf( day );
			var moves = {
				ArrowRight: 1,
				ArrowLeft: -1,
				ArrowDown: 7,
				ArrowUp: -7,
			};

			var next = null;

			if ( Object.prototype.hasOwnProperty.call( moves, event.key ) ) {
				next = days[ index + moves[ event.key ] ] || null;
			} else if ( 'Home' === event.key ) {
				next = days[ 0 ];
			} else if ( 'End' === event.key ) {
				next = days[ days.length - 1 ];
			}

			if ( ! next ) {
				return;
			}

			event.preventDefault();

			day.setAttribute( 'tabindex', '-1' );
			next.setAttribute( 'tabindex', '0' );
			next.focus();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-qevm-calendar]' ).forEach( enhance );
	} );

	/*
	 * Back and forward have to work. pushState without this leaves the browser
	 * buttons changing the URL and nothing else, which is worse than not
	 * intercepting the clicks in the first place.
	 */
	window.addEventListener( 'popstate', function () {
		var calendar = document.querySelector( '[data-qevm-calendar]' );

		if ( calendar ) {
			load( calendar, window.location.href, null );
		}
	} );
}() );
