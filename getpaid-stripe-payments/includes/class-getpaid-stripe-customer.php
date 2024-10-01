<?php
/**
 * Represents a stripe customer.
 *
 * https://stripe.com/docs/api/customers
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe customer.
 *
 */
class GetPaid_Stripe_Customer extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'customers';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'customer';

	/**
	 * Default payment id.
	 *
	 * @var string
	 */
	public $default_payment_id = null;

	/**
	 * Local Invoice object or custome email address.
	 *
	 * @var WPInv_Invoice|string
	 */
	public $object;

	/**
	 * Checks if we're in sandbox mode.
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return $this->gateway->is_sandbox( $this->object_invoice() );
	}

	/**
	 * Returns the remote customer's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		if ( empty( $this->object ) ) {
			return null;
		}

		$email_address = $this->object;

		// If we have an invoice, get the customer's email address.
		if ( is_a( $this->object, 'WPInv_Invoice' ) ) {
			$email_address = $this->object->get_email();
		}

		// If we have an email, get the customer with that email...
		if ( is_string( $email_address ) && is_email( $email_address ) ) {

			// Get from cache.
			$cache_key   = $this->is_sandbox() ? 'getpaid_stripe_customers_test' : 'getpaid_stripe_customers';
			$customer_id = wp_cache_get( $email_address, $cache_key );

			if ( ! empty( $customer_id ) ) {
				return $customer_id;
			}

			$customer_id = $this->from_email( $email_address );

			// Cache the customer id.
			if ( ! empty( $customer_id ) ) {
				wp_cache_set( $email_address, $customer_id, $cache_key, MINUTE_IN_SECONDS );
			}

			return $customer_id;
		}

		return '';
	}

	/**
	 * Retrieves a customer given an email address.
	 *
	 * @return string
	 */
	public function from_email( $email_address ) {
		if ( ! is_email( $email_address ) ) {
			return '';
		}

		/** @var Stripe\Collection|WP_Error $customer */
		$customer = $this->call(
			'all',
			array(
				array(
					'email' => sanitize_email( $email_address ),
					'limit' => 1,
				),
			)
		);

		if ( ! is_wp_error( $customer ) && ! empty( $customer->data ) ) {
			return $customer->data[0]->id;
		}

		// Create a new customer.
		$customer = $this->call( 'create', array( array( 'email' => sanitize_email( $email_address ) ) ) );

		if ( ! is_wp_error( $customer ) ) {
			return $customer->id;
		}

		return '';
	}

	/**
	 * Returns the customers's profile meta key.
	 *
	 * @return string
	 */
	public function get_customer_profile_meta_key( $old_style = false ) {

		/*
		 @todo brian, why why why change these? (;ﾟ︵ﾟ;)
		 */
		if ( $old_style ) {
			$meta_key = $this->object->get_currency();
			$meta_key .= $this->gateway->is_sandbox( $this->object ) ? '_wpi_stripe_customer_id_test' : '_wpi_stripe_customer_id';
		} else {
			$meta_key = $this->gateway->is_sandbox( $this->object ) ? 'wpinv_stripe_sandbox_customer_id' : 'wpinv_stripe_customer_id';
			$meta_key .= $this->object->get_currency();
		}

		return $meta_key;
	}

	/**
	 * Returns the object invoice.
	 *
	 * This is used to calculate whether or not this is a live/sandbox transaction.
	 *
	 */
	public function object_invoice() {
		return is_a( $this->object, 'WPInv_Invoice' ) ? $this->object : null;
	}

	/**
	 * Retrieves the args for creating/updating a customer.
	 *
	 *
	 * @param array $args.
	 * @return array.
	 */
	public function get_args() {

		if ( ! is_object( $this->object ) ) {
			return array();
		}

		$invoice         = $this->object;
		$payment_profile = $this->default_payment_id;
		$name            = $invoice->get_full_name();

		$args = array(
			'name'             => empty( $name ) ? 'Not Provided' : $name,
			'email'            => $invoice->get_email(),
			'metadata'         => $this->clean_metadata(
				array(
					'wpi_user_id' => $invoice->get_user_id(),
					'website'     => get_site_url(),
					'currency'    => $invoice->get_currency(),
				)
			),
			'address'          => $this->invoice_address( $invoice ),
			'shipping'         => $this->get_shipping_info( $invoice ),
			'payment_method'   => $payment_profile,
			'invoice_settings' => array(
				'default_payment_method' => $payment_profile,
			),
		);

		if ( empty( $payment_profile ) ) {
			unset( $args['payment_method'] );
			unset( $args['invoice_settings'] );
		}

		$args = apply_filters( 'getpaid_stripe_customer_args', $args, $invoice, $this );

		return $args;
	}

	/**
	 * Creates the customer and attaches the provided payment method.
	 *
	 * @param GetPaid_Stripe_Payment_Method $payment_method
	 * @param bool $save Whether or not to save the payment method locally.
	 * @return string|WP_Error Payment method id or WP_Error on failure.
	 */
	public function create_then_attach( $payment_method, $save = false ) {

		$this->default_payment_id = $payment_method->get_remote_id();
		$customer                 = $this->create();

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		update_user_meta( $this->object->get_user_id(), $this->get_customer_profile_meta_key(), $customer->id );

		// Save the payment token.
		if ( $save ) {
			$payment_method->save( $this->object, $this->default_payment_id );
		}

		// Add a note about the new customer.
		$this->object->add_note(
			// translators: %s is the customer id.
			sprintf( __( 'Created Stripe customer profile: %s', 'wpinv-stripe' ), $customer->id ),
			false,
			false,
			true
		);

		return $this->default_payment_id;
	}

}
