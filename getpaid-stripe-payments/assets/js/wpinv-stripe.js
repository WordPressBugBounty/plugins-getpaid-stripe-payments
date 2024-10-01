"use strict";

jQuery( function($) {

	if( 'undefined' === typeof( Stripe ) ) {
		return;
	}

	// Create a Stripe client.
	var stripe = Stripe( GetPaid_Stripe.stripePublishableKey );

	// Set-up forms.
	$( 'body' ).on( 'getpaid_setup_payment_form', function( e, form ) {

		form.on( 'getpaid_payment_form_changed_state', function() {

			// Prepare args.
			var stripe_id    = form.find( '.getpaid-stripe-elements').attr( 'id' );
			var displayError = $( '#' + stripe_id + ' .getpaid-stripe-card-errors' );
			var form_state   = form.data( 'getpaid_js_data' );

			// Abort if we have an error
			if ( form_state.stripe_error ) {
				displayError.addClass( 'alert alert-danger' ).text( form_state.stripe_error );
				return;
			}

			// Abort if already set-up.
			if ( form.data( 'getpaid_stripe_elements' ) ) {
				form.data( 'getpaid_stripe_element' ).destroy();
			}

			displayError.removeClass( 'alert alert-danger' ).text( '' );

			// Set-up the payment intent and secret.
			form.find( '.getpaid-stripe-payment-intent' ).val( form_state.stripe_payment_intent );
			form.find( '.getpaid-stripe-payment-intent-secret' ).val( form_state.stripe_payment_intent_secret );

			// Init the payment element.
			try {

				// Create an instance of Elements.
				var elements = stripe.elements({ clientSecret: form_state ? form_state.stripe_payment_intent_secret : '' });

				// Create a payment element.
				var element = elements.create( 'payment' );

				// Mount the element.
				element.mount( '#' + stripe_id + ' .getpaid-stripe-elements-wrapper' );

				// Add the element to the cache.
				form.data( 'getpaid_stripe_elements', elements );
				form.data( 'getpaid_stripe_element', element );

				// Handle real-time validation errors from the card Element.
				element.addEventListener( 'change', function( event ) {

					if ( event.error ) {
						displayError.addClass( 'alert alert-danger mt-2' ).text( event.error.message )
					} else {
						displayError.removeClass( 'alert alert-danger mt-2' ).text( '' )
					}

				});

			} catch ( e ) {
				displayError.addClass( 'alert alert-danger mt-2' ).text( e.message )
			}

		})
	})

	// Handle form submission.
	$( 'body' ).on( 'getpaid_process_stripe_payment', function( e, data, form ) {

		var displayError = form.find( '.getpaid-stripe-card-errors' );

		// Abort if not set-up.
		if ( ! form.data( 'getpaid_stripe_elements' ) ) {
			displayError.addClass( 'alert alert-danger mt-2' ).text( GetPaid_Stripe.unknownError );
			return;
		}

		// Confirm the payment.
		wpinvBlock( form );

		var elements = form.data( 'getpaid_stripe_elements' );

		elements
			.fetchUpdates()
			.then(function(result) {

				// Handle result.error
				if ( result.error ) {
					displayError.addClass( 'alert alert-danger mt-2' ).text( result.error.message );
					wpinvUnblock( form );
					return;
				}

				// Process the payment.
				var confirmation;
				if ( data.is_setup ) {
					confirmation = stripe.confirmSetup({
						elements: elements,
						confirmParams: {
							return_url: data.redirect,
						},
					})
				} else {
					confirmation = stripe.confirmPayment({
						elements: elements,
						confirmParams: {
							return_url: data.redirect,
						},
					})
				}

				// Check for errors.
				confirmation.then(function( result ) {
					if ( ! result.error ) {
						return;
					}

					wpinvUnblock( form );

					// This point will only be reached if there is an immediate error when
					// confirming the payment. Otherwise, your customer will be redirected to
					// your `return_url`. For some payment methods like iDEAL, your customer will
					// be redirected to an intermediate site first to authorize the payment, then
					// redirected to the `return_url`.
					if ( result.error.message ) {
						displayError.addClass( 'alert alert-danger mt-2' ).text( result.error.message );
					} else {
						displayError.addClass( 'alert alert-danger mt-2' ).text( GetPaid_Stripe.unknownError );
					}

					console.log( result );
				});

			});
	});

	// Update payment methods.
    if ( $( '#getpaid-stripe-update-payment-modal' ).length ) {

		var updateModalElements;
		var updateModalElement;
		var successURL;

		// Show update form.
		$( 'body' ).on( 'click', '.getpaid-stripe-update-payment-method-button', function( e ) {

			// Do not submit the form.
            e.preventDefault();

			successURL = $( this ).data( 'redirect' );

            // Display the modal.
			if ( window.bootstrap && window.bootstrap.Modal ) {
				var paymentModal = new window.bootstrap.Modal(document.getElementById('getpaid-stripe-update-payment-modal') );
				paymentModal.show();
			} else {
				$('#getpaid-stripe-update-payment-modal').modal()
			}

			// Handle errors.
            var displayError = $( '.getpaid-stripe-update-payment-method-errors' ).removeClass( 'alert alert-danger mt-2' ).html( '' );

			if ( $( this ).data( 'error' ) ) {
				displayError.addClass( 'alert alert-danger mt-2' ).html( $( this ).data( 'error' ) );
				return;
			}

			// Init the payment element.
			try {

				if ( ! updateModalElements ) {

					// Create an instance of Elements.
					updateModalElements = stripe.elements({ clientSecret: $( this ).data( 'intent' ) });

					// Create a payment element.
					updateModalElement = updateModalElements.create( 'payment' );

					// Mount the element.
					updateModalElement.mount( '.getpaid-stripe-update-payment-method' );

					// Handle real-time validation errors from the card Element.
					updateModalElement.addEventListener( 'change', function( event ) {

						if ( event.error ) {
							displayError.addClass( 'alert alert-danger mt-2' ).text( event.error.message )
						} else {
							displayError.removeClass( 'alert alert-danger mt-2' ).text( '' )
						}

					});
				}

			} catch ( e ) {
				displayError.addClass( 'alert alert-danger mt-2' ).text( e.message )
			}

		});

		// Handle form submission.
		$( 'body' ).on( 'click', '.getpaid-process-updated-stripe-payment-method', function( e ) {

			// Do not submit the form.
            e.preventDefault();

			// Handle errors.
            var displayError = $( '.getpaid-stripe-update-payment-method-errors' ).removeClass( 'alert alert-danger mt-2' ).html( '' );

			// Abort if not set-up.
			if ( ! updateModalElements ) {
				displayError.addClass( 'alert alert-danger mt-2' ).text( GetPaid_Stripe.unknownError );
				return;
			}

			// Confirm the payment.
			wpinvBlock( '#getpaid-stripe-update-payment-modal .modal-body' );

			stripe
				.confirmSetup({
					elements: updateModalElements,
					confirmParams: {
						return_url: successURL,
					},
				})
				.then(function( result ) {
					if ( ! result.error ) {
						return;
					}
		
					wpinvUnblock( '#getpaid-stripe-update-payment-modal .modal-body' );
		
					// This point will only be reached if there is an immediate error when
					// confirming the payment. Otherwise, your customer will be redirected to
					// your `return_url`. For some payment methods like iDEAL, your customer will
					// be redirected to an intermediate site first to authorize the payment, then
					// redirected to the `return_url`.
					if ( result.error.message ) {
						displayError.addClass( 'alert alert-danger mt-2' ).text( result.error.message );
					} else {
						displayError.addClass( 'alert alert-danger mt-2' ).text( GetPaid_Stripe.unknownError );
					}
		
					console.log( result );
				});
		});
	}

});
