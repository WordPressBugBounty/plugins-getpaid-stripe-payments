<?php
/**
 * Handles the Stripe Intents API.
 *
 * After the PaymentIntent is created, attach a payment method and confirm to continue the payment.
 * @link https://stripe.com/docs/api/payment_intents/create.
 * @link https://stripe.com/docs/payments/intents#intent-statuses
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe payment intent.
 *
 */
class GetPaid_Stripe_Payment_Intent extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'paymentIntents';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'paymentIntent';

	/**
	 * Local Invoice object.
	 *
	 * @var WPInv_Invoice
	 */
	public $object;

	/**
	 * Returns the remote payment intent's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return get_post_meta( $this->object->get_id(), 'wpinv_stripe_intent_id', true );
	}

	/**
	 * Returns the payment method id associated with this payment intent.
	 *
	 *
	 * @return string
	 */
	public function get_payment_method_id() {
		return get_post_meta( $this->object->get_id(), 'getpaid_stripe_payment_profile_id', true );
	}

	/**
	 * Returns the object invoice.
	 *
	 * This is used to calculate whether or not this is a live/sandbox transaction.
	 *
	 */
	public function object_invoice() {
		return $this->object;
	}

	/**
	 * Retrieves the args for creating/updating an item.
	 *
	 * @return array.
	 */
	public function get_args( $amount = false ) {

		// Prepare variables.
		$invoice    = $this->object;
		$customer   = new GetPaid_Stripe_Customer( $this->gateway, $invoice );

		if ( false === $amount ) {
			$amount = $invoice->get_total();
		}

		/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
			$amount = $amount * 100;
		}*/
		$amount = getpaid_stripe_get_amount( $amount, $invoice->get_currency() );

		$meta                     = get_post_meta( $invoice->get_id(), 'payment_form_data', true );
		$meta                     = is_array( $meta ) ? $meta : array();
		$meta['invoice_id']       = $invoice->get_id();
		$meta['invoice_url']      = $invoice->get_view_url();
		$meta['invoice_date']     = $invoice->get_date_created();

		$args = array(
			'customer'       => $customer->get_remote_id(),
			'payment_method' => $this->get_payment_method_id(),
			'amount'         => $amount,
			'currency'       => strtolower( $invoice->get_currency() ),
			'description'    => sprintf(
				// translators: %1$s: invoice number
				__( 'Payment for invoice %s', 'wpinv-stripe' ),
				$invoice->get_number()
			),
			'shipping'       => $this->get_shipping_info( $invoice ),
			'metadata'       => $this->clean_metadata( $meta ),

		);

		$customer->update();

		return apply_filters( 'getpaid_stripe_payment_intent_args', $args, $invoice, $this );

	}

	/**
	 * Confirms the payment intent.
	 *
	 * @link https://stripe.com/docs/api/payment_intents/confirm
	 * @return \Stripe\PaymentIntent|WP_Error
	 */
	public function confirm() {

		return $this->call(
			'confirm',
			array(
				$this->get_remote_id(),
				array(
					'payment_method' => $this->get_payment_method_id(),
				),
			)
		);

	}

	/**
	 * Capture the funds of an existing uncaptured PaymentIntent when its status is requires_capture.
	 *
	 * @link https://stripe.com/docs/api/payment_intents/
	 * @return \Stripe\PaymentIntent|WP_Error
	 */
	public function capture() {

		return $this->call(
			'capture',
			array(
				$this->get_remote_id(),
			)
		);

	}

	/**
	 * Processes the payment intent.
	 *
	 * @link https://stripe.com/docs/payments/intents#intent-statuses
	 * @return WP_Error|int|string WP_Error on error, 1 on processing, charge-id on success.
	 */
	public function process( $payment_intent = null ) {

		// Ensure we are using the latest values.
		if ( empty( $payment_intent ) ) {

			if ( $this->exists() ) {
				$payment_intent = $this->get();

				if ( is_wp_error( $payment_intent ) ) {
					return $payment_intent;
				}

				if ( in_array( $payment_intent->status, array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
					$payment_intent = $this->update();
				}
			} else {
				$payment_intent = $this->update();
			}
		}

		if ( is_wp_error( $payment_intent ) ) {
			return $payment_intent;
		}

		// Save it to the invoice.
		update_post_meta( $this->object->get_id(), 'wpinv_stripe_intent_id', $payment_intent->id );

		// Maybe confirm the payment intent.
		if ( empty( $payment_intent->last_payment_error ) && in_array( $payment_intent->status, array( 'requires_confirmation', 'requires_payment_method' ) ) ) {
			$payment_intent = $this->confirm();
		}

		// Do we have an error?
		if ( ! empty( $payment_intent->last_payment_error ) ) {
			wpinv_error_log( $payment_intent->last_payment_error, 'Payment Intent Error' );
			return new WP_Error( 'payment_intent_last_payment_error', $payment_intent->last_payment_error->message, $payment_intent->last_payment_error );
		}

		// If the payment requires additional actions, such as authenticating with 3D Secure...
		if ( 'requires_action' === $payment_intent->status ) {

			$verification_url = rawurlencode( $this->get_action_url() );

			wp_redirect(
				sprintf(
					'#wpi-confirm-pi-%s:%s',
					$payment_intent->client_secret,
					$verification_url
				)
			);

			exit;
		}

		// If the payment is processing...
		if ( 'processing' === $payment_intent->status ) {
			return 2;
		}

		// The payment succeeded.
		if ( 'succeeded' === $payment_intent->status ) {

			$charge = end( $payment_intent->charges->data );

			if ( $charge ) {
				return $charge->id;
			}
}

		return new WP_Error( 'invalid_payment_method', __( 'Please try again with a different payment method.', 'wpinv-stripe' ) );
	}

	/**
	 * Returns a given intent's action URL.
	 *
	 */
	public function get_action_url() {

		return add_query_arg(
			array(
				'invoice_id'      => $this->object->get_id(),
				'confirm-payment' => 'yes',
				'nonce'           => wp_create_nonce( 'wpinv_stripe_confirm_payment' ),
				'redirect_to'     => rawurlencode(
					add_query_arg(
						'invoice_key',
						$this->object->get_key(),
						wpinv_get_success_page_uri()
					)
				),
			),
			home_url()
		);

	}

}
