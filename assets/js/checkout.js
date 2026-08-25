/**
 * The payment screen.
 *
 * Stripe draws the card fields into an iframe it owns and confirms the payment
 * itself; this file wires a button to that and gets out of the way. No card
 * value passes through here, which is deliberate and is what keeps this site
 * out of scope for card handling.
 *
 * @package QuickEventsManager
 */

( function () {
	'use strict';

	var config = window.qevmCheckout || {};
	var mount = document.getElementById( 'qevm-card' );
	var button = document.getElementById( 'qevm-pay' );
	var error = document.getElementById( 'qevm-checkout-error' );

	if ( ! mount || ! button ) {
		return;
	}

	/**
	 * Say what went wrong, where a screen reader will hear it.
	 *
	 * @param {string} message What to say.
	 */
	function say( message ) {
		if ( error ) {
			error.textContent = message;
		}
	}

	/*
	 * Stripe.js comes from Stripe's own servers, so it is the one dependency
	 * here that can simply fail to arrive — a blocker, an offline moment, a
	 * corporate proxy. Saying so is better than a button that does nothing.
	 */
	if ( 'undefined' === typeof window.Stripe ) {
		button.disabled = true;
		say( config.strings ? config.strings.blocked : '' );

		return;
	}

	var stripe = window.Stripe( config.publishable );
	var elements = stripe.elements( { clientSecret: config.secret } );
	var payment = elements.create( 'payment' );

	payment.mount( mount );

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.textContent = config.strings ? config.strings.paying : '';
		say( '' );

		stripe
			.confirmPayment( {
				elements: elements,
				confirmParams: {
					return_url: config.returnUrl
				}
			} )
			.then( function ( result ) {
				/*
				 * Only reached when the payment did not need a redirect. A
				 * successful one leaves this page for the bank and comes back
				 * to the return URL, so there is nothing to do on that path.
				 */
				if ( result && result.error ) {
					say( result.error.message || ( config.strings ? config.strings.failed : '' ) );

					button.disabled = false;
				}
			} );
	} );
}() );
