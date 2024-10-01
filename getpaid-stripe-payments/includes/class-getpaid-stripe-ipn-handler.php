<?php
/**
 * Stripe payment gateway IPN handler
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Payment Gateway IPN handler class.
 *
 */
class GetPaid_Stripe_IPN_Handler extends GetPaid_Stripe_Resource {

	/**
	 * Payment method id.
	 *
	 * @var string
	 */
	protected $id = 'stripe';

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'events';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'event';

	/**
	 * Processes ipns and marks payments as complete.
	 *
	 * @return void
	 */
	public function process() {

		wpinv_error_log( 'GetPaid Stripe Webhook Handler', false );

		// Retrieve the request's body and parse it as JSON.
		$body    = @file_get_contents( 'php://input' );
		$posted  = json_decode( $body );

		// Validate the IPN.
		if ( empty( $posted ) ) {
			wpinv_error_log( 'Stripe webhook request failure. No request body.', false );
			wp_die( 'No request body', 200 );
			return;
		}

		// For test IPNs..
		if ( 'evt_00000000000000' === $posted->id ) {
			wp_die( 'Webhook is working fine :)', 200 );
		}

		// For extra security, retrieve from the Stripe API.
		$event = $this->call( 'retrieve', array( $posted->id ) );

		if ( is_wp_error( $event ) ) {
			wpinv_error_log( wp_kses_post( $event->get_error_message() ), 'Error retrieving webhook' );
			wpinv_error_log( $posted, false );
			wp_die( wp_kses_post( $event->get_error_message() ), 200 );
		}

		wpinv_error_log( 'Stripe event_type: ' . $event->type . ' #' . $event->id, false, basename( __FILE__ ), __LINE__ );

		$event_type = strtolower( str_replace( '.', '_', $event->type ) );

		if ( method_exists( $this, 'process_' . $event_type ) ) {
			wpinv_error_log( 'Processing Stripe webhook', false );

			call_user_func( array( $this, 'process_' . $event_type ), $event->data->object, $event );
		}

		do_action( "getpaid_stripe_event_{$event_type}", $event );

		wpinv_error_log( 'Done processing Stripe webhook', false );

		wp_die( 'Processed', 200 );
	}

	/**
	 * Manually processes an event.
	 *
	 * @return void
	 */
	public function process_manually( $event_id ) {

		if ( empty( $event_id ) ) {
			wp_die( 'No event id', 200 );
		}

		wpinv_error_log( 'GetPaid Stripe Webhook Handler', false );

		// For extra security, retrieve from the Stripe API.
		$event = $this->call( 'retrieve', array( $event_id ) );

		if ( is_wp_error( $event ) ) {
			wpinv_error_log( wp_kses_post( $event->get_error_message() ), 'Error retrieving webhook' );
			wp_die( wp_kses_post( $event->get_error_message() ), 'Error retrieving webhook', 200 );
		}

		wpinv_error_log( 'Stripe event_type: ' . $event->type . ' #' . $event->id, false, basename( __FILE__ ), __LINE__ );

		$event_type = strtolower( str_replace( '.', '_', $event->type ) );

		if ( method_exists( $this, 'process_' . $event_type ) ) {
			wpinv_error_log( 'Processing Stripe webhook', false );

			call_user_func( array( $this, 'process_' . $event_type ), $event->data->object, $event );
		}

		do_action( "getpaid_stripe_event_{$event_type}", $event );

		wpinv_error_log( 'Done processing Stripe webhook', false );

		printf( '<h3>Processed <code>%s</code> event</h3>', esc_html( $event_type ) );
		echo '<pre>';
		print_r( $event );
		echo '</pre>';
		exit;
	}

	/**
	 * Processes payment failures.
	 *
	 * @param Stripe\Invoice $invoice
	 */
	protected function process_invoice_payment_failed( $invoice ) {

		wpinv_error_log( 'Processing invoice payment failure webhook', false );

		// Only process if there is a subscription.
		$subscription_profile = $invoice->subscription;
		if ( empty( $subscription_profile ) ) {
			return;
		}

		$subscription = WPInv_Subscription::get_subscription_id_by_field( $subscription_profile );
		$subscription = new WPInv_Subscription( $subscription );

		if ( ! $subscription->exists() ) {
			return;
		}

		$subscription->failing();

	}

	/**
	 * Processes payment successes.
	 *
	 * @param Stripe\paymentIntent $payment_intent
	 */
	public function process_payment_intent_succeeded( $payment_intent ) {

		wpinv_error_log( 'Processing payment intent succeeded webhook', false );

		// Retreive the invoice.
		$invoice = wpinv_get_invoice( $payment_intent->id );

		if ( empty( $invoice ) ) {
			return;
		}

		wpinv_error_log( 'Found invoice #' . $invoice->get_number(), false );

		getpaid()->gateways['stripe']->process_payment_intent( $payment_intent, $invoice );
	}

	/**
	 * Processes payments.
	 *
	 * @param Stripe\Invoice $invoice
	 * @param Stripe\Event $event
	 */
	protected function process_invoice_payment_succeeded( $invoice, $event ) {

		wpinv_error_log( 'Processing invoice payment succeeded webhook', false );

		// Only process if there is a subscription.
		$subscription_profile = $invoice->subscription;
		if ( empty( $subscription_profile ) ) {
			return;
		}

		$subscription = WPInv_Subscription::get_subscription_id_by_field( $subscription_profile );
		$subscription = new WPInv_Subscription( $subscription );

		if ( ! $subscription->exists() ) {
			return;
		}

		// Abort if this is the first payment.
		$_invoice       = $subscription->get_parent_invoice();
		$transaction_id = empty( $invoice->charge ) ? $invoice->id : $invoice->charge;

		if ( gmdate( 'Ynd', $subscription->get_time_created() ) === gmdate( 'Ynd', $event->created ) ) {

			$subscription->activate();

			$_invoice->set_transaction_id( $transaction_id );
			if ( ! $_invoice->is_paid() ) {
				$_invoice->mark_paid();
			}

			return;
		}

		$subscription->add_payment( compact( 'transaction_id' ) );

		$subscription->renew();

	}

	/**
	 * Processes refunds.
	 *
	 * @param Stripe\Charge $charge
	 * @param Stripe\Event $event
	 */
	protected function process_charge_refunded( $charge, $event ) {

		wpinv_error_log( 'Processing charge refund', false );

		$transaction_id = $charge->id;
		$invoice        = WPInv_Invoice::get_invoice_id_by_field( $transaction_id, 'transaction_id' );
		$invoice        = new WPInv_Invoice( $invoice );

		if ( ! $invoice->exists() || $invoice->is_refunded() ) {
			return;
		}

		$refunded_amount = ! empty( $charge->amount_refunded ) ? $charge->amount_refunded : 0;
		$payment_amount  = $invoice->get_total();
		/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
			$payment_amount = $payment_amount * 100;
		}*/
		$payment_amount = getpaid_stripe_get_amount( $payment_amount, $invoice->get_currency() );

		// This is a partial refund;
		if ( floatval( $refunded_amount ) < floatval( $payment_amount ) ) {
			$invoice->add_note( __( 'Invoice partically refunded.', 'wpinv-stripe' ), false, false, true );
			return;
		}

		$invoice->refund();

	}

	/**
	 * Processes subscription update.
	 *
	 * @param Stripe\Subscription $subscription
	 * @param Stripe\Event $event
	 */
	protected function process_customer_subscription_updated( $stripe_subscription, $event = array() ) {
		wpinv_error_log( 'Processing subscription update for #' . $stripe_subscription->id, false );

		$subscription_id = WPInv_Subscription::get_subscription_id_by_field( $stripe_subscription->id );
		if ( empty( $subscription_id ) ) {
			wpinv_error_log( 'No subscription found for #' . $stripe_subscription->id, false );
			return;
		}

		$subscription = new WPInv_Subscription( $subscription_id );

		if ( ! $subscription->exists() ) {
			return;
		}

		$new_status = $stripe_subscription->status;

		if ( $new_status == 'trialing' && ! empty( $stripe_subscription->trial_end ) && strpos( $subscription->get_expiration(), date( 'Y-m-d', (int) $stripe_subscription->trial_end ) ) !== 0 ) {
			$subscription->set_expiration( date( 'Y-m-d H:i:s', (int) $stripe_subscription->trial_end ) );
			$subscription->save();
		} else if ( ! empty( $stripe_subscription->current_period_end ) && strpos( $subscription->get_expiration(), date( 'Y-m-d', (int) $stripe_subscription->current_period_end ) ) !== 0 ) {
			$subscription->set_expiration( date( 'Y-m-d H:i:s', (int) $stripe_subscription->current_period_end ) );
			$subscription->save();
		}

		do_action( "getpaid_stripe_process_customer_subscription_updated", $subscription, $stripe_subscription );

		wpinv_error_log( 'Subscription #' . $stripe_subscription->id . ' is updated.', false );
	}

	/**
	 * Processes subscription deleted.
	 *
	 * @param Stripe\Subscription $subscription
	 * @param Stripe\Event $event
	 */
	protected function process_customer_subscription_deleted( $stripe_subscription, $event = array() ) {
		wpinv_error_log( 'Processing subscription deleted for #' . $stripe_subscription->id, false );

		$subscription_id = WPInv_Subscription::get_subscription_id_by_field( $stripe_subscription->id );

		if ( empty( $subscription_id ) ) {
			wpinv_error_log( 'No subscription found for #' . $stripe_subscription->id, false );
			return;
		}

		$subscription = new WPInv_Subscription( $subscription_id );

		if ( ! $subscription->exists() ) {
			return;
		}

		wpinv_error_log( 'Processing subscription cancellation for #' . $stripe_subscription->id, false );

		$subscription->cancel();

		wpinv_error_log( 'Subscription #' . $stripe_subscription->id . ' is cancelled.', false );
	}

	/**
	 * Processes customer subscription updated.
	 *
	 * @param Stripe\Subscription $subscription
	 * @param Stripe\Event $event
	 */
	protected function customer_subscription_updated( $subscription ) {
		wpinv_error_log( 'Processing subscription update', false );

		$_subscription = WPInv_Subscription::get_subscription_id_by_field( $subscription->id );
		$_subscription = new WPInv_Subscription( $subscription );

		if ( ! $_subscription->exists() ) {
			return;
		}

		do_action( "getpaid_stripe_subscription_{$subscription->status}", $_subscription, $subscription );

		wpinv_error_log( 'Subscription updated.', false );
	}

	/**
	 * Processes setup intent success.
	 *
	 * @param Stripe\setupIntent $setup_intent
	 */
	protected function process_setup_intent_succeeded( $setup_intent ) {

		wpinv_error_log( 'Processing subscription setup intent', false );

		// If there is a remote_id metadata field, then we're updating a payment method.
		if ( ! empty( $setup_intent->metadata->remote_id ) ) {

			// Fetch local sub.
			$subscription = WPInv_Subscription::get_subscription_id_by_field( $setup_intent->metadata->remote_id );
			$subscription = new WPInv_Subscription( $subscription );

			if ( ! $subscription->exists() ) {
				return;
			}

			// Preocess the payment method.
			$_setup_intent = new GetPaid_Stripe_Setup_Intent( $this, $subscription );
			return $_setup_intent->process_payment_method( $setup_intent );
		}

		// Otherwise, we're creating a new subscription.
		if ( empty( $setup_intent->metadata->invoice_key ) || empty( $setup_intent->payment_method ) ) {
			return;
		}

		// Retrieve the matching invoice.
		$invoice = wpinv_get_invoice( $setup_intent->metadata->invoice_key );

		if ( empty( $invoice ) || ! $invoice->exists() ) {
			return;
		}

		// Abort if the invoice has been processed.
		if ( get_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', true ) === $setup_intent->payment_method ) {
			return;
		}

		// Save the payment method to the order.
		update_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', $setup_intent->payment_method );

		// Fetch the invoice subscriptions.
		$subscriptions = function_exists( 'getpaid_get_invoice_subscriptions' ) ? getpaid_get_invoice_subscriptions( $invoice ) : getpaid_get_invoice_subscription( $invoice );

		// Process the subscription.
		if ( ! empty( $subscriptions ) ) {
			if ( is_array( $subscriptions ) ) {
				getpaid()->gateways['stripe']->process_subscriptions( $subscriptions );
			} else {
				getpaid()->gateways['stripe']->process_normal_subscription( $subscriptions );
			}
		}

	}

	/**
	 * Processes checkout session success.
	 *
	 * @param Stripe\Checkout\Session $session
	 */
	protected function process_checkout_session_completed( $session ) {

		wpinv_error_log( 'Processing checkout session complete', false );

		// Retrieve the matching invoice.
		$invoice = wpinv_get_invoice( $session->client_reference_id );

		if ( empty( $invoice ) || ! $invoice->exists() ) {
			return;
		}

		// Process setup intents.
		if ( 'setup' === $session->mode ) {
			return $this->gateway->process_setup_intent( $session->setup_intent, $invoice, true );
		}

		// Processes payment intents.
		if ( 'payment' === $session->mode ) {
			update_post_meta( $invoice->get_id(), 'wpinv_stripe_intent_id', $session->payment_intent );
			return $this->gateway->process_payment_intent( $session->payment_intent, $invoice, true );
		}

		// Process subscriptions.
		if ( 'subscription' === $session->mode ) {

			$subscription = getpaid_get_invoice_subscriptions( $invoice );

			if ( is_object( $subscription ) ) {
				if ( ! empty( $session->subscription ) ) {
					$subscription->set_profile_id( $session->subscription );
				}

				$subscription->activate();
			}

			if ( ! empty( $session->invoice ) ) {
				$invoice->set_transaction_id( $session->invoice );
			}

			$invoice->set_remote_subscription_id( $session->subscription );
			$invoice->mark_paid();
		}

	}
}
