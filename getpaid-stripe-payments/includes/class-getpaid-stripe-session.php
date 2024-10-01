<?php
/**
 * Handles the Stripe Sessions API.
 *
 * @link https://stripe.com/docs/api/checkout/sessions/create
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe setup intent.
 *
 */
class GetPaid_Stripe_Session extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'checkoutSessions';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'checkoutSession';

	/**
	 * Local invoice object.
	 *
	 * @var WPInv_Invoice
	 */
	public $object;

	/**
	 * Returns the remote session.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return get_transient( 'getpaid_stripe_checkout_session_id_' . $this->object->get_id() );
	}

	/**
	 * Returns the payment url.
	 *
	 * @return string
	 */
	public function get_payment_url() {
		return get_transient( 'getpaid_stripe_checkout_session_url_' . $this->object->get_id() );
	}

	/**
	 * Cache keys.
	 *
	 * @param string $session_id
	 * @param string $payment_url
	 */
	public function cache_keys( $session_id, $payment_url ) {
		set_transient( 'getpaid_stripe_checkout_session_id_' . $this->object->get_id(), $session_id, 6 * HOUR_IN_SECONDS );
		set_transient( 'getpaid_stripe_checkout_session_url_' . $this->object->get_id(), $payment_url, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Clears the cache.
	 *
	 */
	public function clear_cache() {
		delete_transient( 'getpaid_stripe_checkout_session_id_' . $this->object->get_id() );
		delete_transient( 'getpaid_stripe_checkout_session_url_' . $this->object->get_id() );
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
	 * Retrieves the remote product id.
	 *
	 * @param WPInv_Item $item
	 * @param WPInv_Invoice $invoice
	 * @return array.
	 */
	public function get_remote_product_id( $item, $invoice ) {

		$product       = new GetPaid_Stripe_Product( $this->gateway, $invoice );
		$product->item = $item;

		if ( ! $product->exists() ) {
			$_product = $product->create();

			if ( is_wp_error( $_product ) ) {
				wpinv_set_error( $_product->get_error_code(), $_product->get_error_message() );
				return;
			}

			update_post_meta( $item->get_id(), $product->get_item_profile_meta_name(), $_product->id );
		}

		return $product->get_remote_id();
	}

	/**
	 * Retrieves the args for creating/updating an item.
	 *
	 * @return array.
	 */
	public function get_args() {

		// Recurring items.
		$invoice       = $this->object;
		$subscriptions = getpaid_get_invoice_subscriptions( $invoice );
		$subscriptions = is_object( $subscriptions ) ? array( $subscriptions ) : $subscriptions;

		// Checkout args.
		$args = array(
			'mode'                => ! empty( $subscriptions ) ? 'subscription' : 'payment',
			'success_url'         => $invoice->get_receipt_url(),
			'cancel_url'          => $invoice->get_checkout_payment_url(),
			'client_reference_id' => $invoice->get_id(),
			'currency'            => $invoice->get_currency(),
			'customer_email'      => $invoice->get_email(),
			'line_items'          => array(),
		);

		// Checkout does not support multiple prices with different billing intervals.
		if ( is_array( $subscriptions ) && 1 < count( $subscriptions ) ) {
			$args['mode'] = 'setup';
		}

		// Set customer.
		$customer   = new GetPaid_Stripe_Customer( $this->gateway, $invoice );
		$customer   = $customer->get_remote_id();

		if ( ! empty( $customer ) ) {
			$args['customer'] = $customer;
			unset( $args['customer_email'] );
		}

		// Add subscriptions.
		/** @var WPInv_Subscription[] */
		$subscriptions = is_array( $subscriptions ) ? $subscriptions : array();
		foreach ( $subscriptions as $subscription ) {

			// Prepare subscription amounts.
			$initial_amount    = $subscription->get_initial_amount();
			$recurring_amount  = $subscription->get_recurring_amount();
			$initial_amount    = getpaid_stripe_get_amount( $initial_amount, $invoice->get_currency() );
			$recurring_amount  = getpaid_stripe_get_amount( $recurring_amount, $invoice->get_currency() );

			/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
				$initial_amount   = $initial_amount * 100;
				$recurring_amount = $recurring_amount * 100;
			}*/

			if ( $initial_amount !== $recurring_amount ) {
				$args['mode'] = 'setup';
			}

			$args['line_items'][] = array(
				'price_data' => array(
					'currency'    => $invoice->get_currency(),
					'product'     => $this->get_remote_product_id( $subscription->get_product(), $invoice ),
					'recurring'   => array(
						'interval'       => $subscription->get_period(),
						'interval_count' => $subscription->get_frequency(),
					),
					'unit_amount' => $recurring_amount,
				),
				'quantity'   => 1,
			);
		}

		// Add line items.
		foreach ( $invoice->get_items() as $item ) {
			$amount = round( ( $item->get_sub_total() + $item->item_tax - $item->item_discount ) / $item->get_quantity(), 2 );
			$amount = getpaid_stripe_get_amount( $amount, $invoice->get_currency() );

			/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
				$amount = $amount * 100;
			}*/

			if ( ! $item->get_is_recurring() ) {

				// Add as price data.
				$args['line_items'][] = array(
					'price_data' => array(
						'currency'    => $invoice->get_currency(),
						'product'     => $this->get_remote_product_id( $item, $invoice ),
						'unit_amount' => $amount,
					),
					'quantity'   => $item->get_quantity(),
				);
			}
		}

		if ( 'setup' === $args['mode'] ) {
			$payment_methods = wpinv_get_option( 'stripe_payment_methods', array() );

			if ( empty( $payment_methods ) ) {
				$payment_methods = array( 'card' );
			}

			$args['payment_method_types'] = apply_filters( 'getpaid_stripe_payment_method_types', array_values( $payment_methods ) );
			unset( $args['line_items'] );
		}

		return $args;
	}

	/**
	 * Processes the checkout session.
	 *
	 */
	public function process() {

		$this->clear_cache();
		$result = $this->create();

		// Abort if an error occurred.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->cache_keys( $result->id, $result->url );

		return $result->url;

	}

}
