<?php
/**
 * Handles the Stripe Intents API.
 *
 * @link https://stripe.com/docs/api/setup_intents/create.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe setup intent.
 *
 */
class GetPaid_Stripe_Setup_Intent extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'setupIntents';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'setupIntent';

	/**
	 * Local subscription object.
	 *
	 * @var WPInv_Subscription
	 */
	public $object;

	/**
	 * Returns the remote setup intent's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return get_transient( 'getpaid_stripe_setup_intent_' . $this->object->get_id() );
	}

	/**
	 * Returns the remote setup intent's secret key.
	 *
	 * @return string
	 */
	public function get_secret_key() {
		return get_transient( 'getpaid_stripe_setup_intent_secret_' . $this->object->get_id() );
	}

	/**
	 * Cache keys.
	 *
	 * @param string $intent_id
	 * @param string $secret_key
	 */
	public function cache_keys( $intent_id, $secret_key ) {
		set_transient( 'getpaid_stripe_setup_intent_' . $this->object->get_id(), $intent_id, 6 * HOUR_IN_SECONDS );
		set_transient( 'getpaid_stripe_setup_intent_secret_' . $this->object->get_id(), $secret_key, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Clears the cache.
	 *
	 */
	public function clear_cache() {
		delete_transient( 'getpaid_stripe_setup_intent_' . $this->object->get_id() );
		delete_transient( 'getpaid_stripe_setup_intent_secret_' . $this->object->get_id() );
	}

	/**
	 * Returns the object invoice.
	 *
	 * This is used to calculate whether or not this is a live/sandbox transaction.
	 *
	 */
	public function object_invoice() {
		return $this->object->get_parent_invoice();
	}

	/**
	 * Retrieves the args for creating/updating an item.
	 *
	 * @return array.
	 */
	public function get_args() {

		// Prepare customer.
		$customer = new GetPaid_Stripe_Customer( $this->gateway, $this->object_invoice() );

		return array(
			'customer'             => $customer->get_remote_id(),
			'metadata'             => array(
				'subscription_id' => $this->object->get_id(),
				'remote_id'       => $this->object->get_profile_id(),
			),
			'payment_method_types' => apply_filters( 'getpaid_stripe_payment_method_types', array( 'card' ) ),
		);

	}

	/**
	 * Processes the setup intent.
	 *
	 * @return \Stripe\Subscription|WP_Error
	 */
	public function process() {

		if ( ! $this->get_remote_id() ) {
			return new WP_Error( 'getpaid_stripe_setup_intent_not_found', __( 'Setup intent not found.', 'wpinv-stripe' ) );
		}

		/** @var \Stripe\SetupIntent|WP_error $setup_intent */
		$setup_intent = $this->get();

		// Abort if an error occurred.
		if ( is_wp_error( $setup_intent ) ) {
			return $setup_intent;
		}

		return $this->process_payment_method( $setup_intent );

	}

	/**
	 * Processes the setup intent's payment method.
	 *
	 * @param \Stripe\SetupIntent $setup_intent
	 * @return \Stripe\Subscription|WP_Error
	 */
	public function process_payment_method( $setup_intent ) {

		// Check the we have a subscription id.
		if ( empty( $setup_intent->metadata->remote_id ) ) {
			return new WP_Error( 'getpaid_stripe_setup_intent_no_subscription', __( 'The setup intent has no associated subscription id.', 'wpinv-stripe' ) );
		}

		// Confirm success.
		if ( 'succeeded' !== $setup_intent->status || empty( $setup_intent->payment_method ) ) {
			return new WP_Error( 'getpaid_stripe_setup_intent_not_succeeded', __( 'The setup intent has not succeeded.', 'wpinv-stripe' ) );
		}

		$this->clear_cache();

		// Attach the payment method to the subscription.
		try {

			return $this->_call(
				array(
					$this->gateway->get_stripe( $this->object_invoice() )->subscriptions,
					'update',
				),
				array(
					$setup_intent->metadata->remote_id,
					array(
						'default_payment_method' => $setup_intent->payment_method,
					),
				)
			);

		} catch ( Exception $e ) {

			// Something else happened, completely unrelated to Stripe.
			return $this->error_or_exception( false, $e );

		}
	}

}
