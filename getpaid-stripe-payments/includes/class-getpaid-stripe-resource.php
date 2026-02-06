<?php
/**
 * Represents a stripe resource.
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a resource.
 *
 */
class GetPaid_Stripe_Resource {

	/**
	 * Gateway instance.
	 *
	 * @var GetPaid_Stripe_Gateway
	 */
	public $gateway;

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural;

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular;

	/**
	 * Local Resource object.
	 *
	 */
	public $object = null;

	/**
	 * Associated invoice
	 *
	 */
	public $invoice = null;

	/**
	 * Class constructor.
	 *
	 * @param GetPaid_Stripe_Gateway $gateway
	 * @param mixed $local_resource
	 */
	public function __construct( $gateway, $local_resource = null ) {
		$this->gateway = $gateway;
		$this->object  = $local_resource;
	}

	/**
	 * Executes an api call.
	 *
	 *
	 * @param string $method The method to call.
	 * @param array  $args An array of args to pass to the method as an indexed array.
	 * @link https://stripe.com/docs/api/errors/handling
	 * @return \Stripe\Product|\Stripe\Subscription|\Stripe\paymentIntent|\Stripe\Customer|\Stripe\Event|WP_Error
	 */
	public function call( $method, $args = array() ) {

		if ( 'checkoutSessions' === $this->plural ) {
			$class = $this->gateway->get_stripe( $this->object_invoice() )->checkout->sessions;
		} else {
			$class = $this->gateway->get_stripe( $this->object_invoice() )->{$this->plural};
		}

		try {

			// Execute the call.
			return $this->_call( array( $class, $method ), $args );

		} catch ( Exception $e ) {

			// Something else happened, completely unrelated to Stripe.
			return $this->error_or_exception( false, $e );

		}

	}

	/**
	 * Executes an api call.
	 *
	 *
	 * @param string $callback The stripe client cb.
	 * @param array  $args An array of args to pass to the method as an indexed array.
	 * @link https://stripe.com/docs/api/errors/handling
	 * @return \Stripe\Product|\Stripe\Subscription|\Stripe\paymentIntent|\Stripe\Customer|\Stripe\Event|WP_Error
	 */
	protected function _call( $callback, $args = array() ) {

		try {
			if ( ! empty( $callback ) && ! empty( $callback[1] ) && ( $callback[1] == 'delete' || $callback[1] == 'cancel' ) ) {
				wpinv_error_log( wp_debug_backtrace_summary( null, 0, false ), $callback[1], false );
			}

			return call_user_func_array( $callback, $args );

		} catch ( \Stripe\Exception\CardException $e ) {

			// Card was declined.
			return $this->error_or_exception( $e->getError(), $e );

		} catch ( \Stripe\Exception\RateLimitException $e ) {

			// Too many requests made to the API too quickly
			return $this->error_or_exception( $e->getError(), $e );

		} catch ( \Stripe\Exception\InvalidRequestException $e ) {

			// Invalid parameters were supplied to Stripe's API
			return $this->error_or_exception( $e->getError(), $e );

		} catch ( \Stripe\Exception\AuthenticationException $e ) {

			// Authentication with Stripe's API failed
			// (maybe you changed API keys recently)
			return $this->error_or_exception( $e->getError(), $e );

		} catch ( \Stripe\Exception\ApiConnectionException $e ) {

			// Network communication with Stripe failed
			return $this->error_or_exception( $e->getError(), $e );

		} catch ( \Stripe\Exception\ApiErrorException $e ) {

			// Display a very generic error to the user.
			return $this->error_or_exception( $e->getError(), $e );

		} catch ( \Stripe\Exception\InvalidArgumentException $e ) {

			// This call used invalid arguments.
			return $this->error_or_exception( false, $e );

		} catch ( Exception $e ) {

			// Something else happened, completely unrelated to Stripe.
			return $this->error_or_exception( false, $e );

		}

	}

	/**
	 * Retrieves the error from an error object or exception.
	 *
	 * @param null|\Stripe\ErrorObject $error
	 * @param null|Exception $e
	 */
	public function error_or_exception( $error, $e ) {

		if ( ! empty( $error ) ) {

			if ( 'card_declined' == $error->code ) {
				$error->message = __( 'Your card was declined. Please contact your bank for more information or try again with a different card.', 'wpinv-stripe' ) . '<span class="form-text text-light">' . sprintf( /* translators: %s: Decline code. */ __( 'The decline code is %s', 'wpinv-stripe' ), "($error->decline_code)" ) . '</span>';
			}

			$error->code = empty( $error->code ) ? $error->type : $error->code;
			return new WP_Error( $error->code, $error->message );
		}

		if ( ! empty( $e ) ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}

		return new WP_Error( 'unknown_error', __( 'An unknown error occured while contacting Stripe. Please verify your details then try again.', 'wpinv-stripe' ) );

	}

	/**
	 * Retrieves the resource from Stripe.
	 *
	 *
	 * @param string $profile_id Remote profile id.
	 * @return array|\Stripe\Product|\Stripe\Subscription|\Stripe\paymentIntent|\Stripe\Customer|WP_Error
	 */
	public function get() {
		return $this->call( 'retrieve', array( $this->get_remote_id() ) );
	}

	/**
	 * Checks if a resource profile exists.
	 *
	 * @return bool
	 */
	public function exists() {

		$remote_id = $this->get_remote_id();

		if ( empty( $remote_id ) ) {
			return false;
		}

		$profile = $this->get();
		return ! is_wp_error( $profile ) && ! $profile->isDeleted();
	}

	/**
	 * Creates the resource in Stripe.
	 * Whenever possible, use self::update() instead of this method.
	 *
	 * @return \Stripe\Product|\Stripe\Subscription|\Stripe\paymentIntent|\Stripe\Customer|\Stripe\Checkout\Session|WP_Error
	 */
	public function create() {
		return $this->call( 'create', array( $this->get_args() ) );
	}

	/**
	 * Creates/Updates a resource.
	 *
	 * @return \Stripe\Product|\Stripe\Subscription|\Stripe\paymentIntent|\Stripe\PaymentIntent|\Stripe\Customer|WP_Error
	 */
	public function update() {
		if ( ! $this->exists() ) {
			return $this->create();
		}

		return $this->call( 'update', array( $this->get_remote_id(), $this->get_args() ) );

	}

	/**
	 * Retrieves the args for creating/updating a resource.
	 *
	 *
	 * @return array.
	 */
	public function get_args() {
		return array();
	}

	/**
	 * Retrives the remote profile id.
	 *
	 *
	 * @return string.
	 */
	public function get_remote_id() {
		return '';
	}

	/**
	 * Returns the object invoice.
	 *
	 * This is used to calculate whether or not this is a live/sandbox transaction.
	 *
	 */
	public function object_invoice() {
		return $this->invoice;
	}

	/**
	 * Helper function to calculate invoice address.
	 *
	 * Added here since most resource use it.
	 * @param WPInv_invoice $invoice
	 * @param string $type
	 * @return array
	 */
	public function invoice_address( $invoice, $type = 'billing' ) {
		$address  = $invoice->get_address();

		$address  = array(
			'line1'       => empty( $address ) ? 'Not Provided' : $address,
			'city'        => $invoice->get_city(),
			'country'     => $invoice->get_country(),
			'postal_code' => $invoice->get_zip(),
			'state'       => $invoice->get_state(),
		);

		// Maybe replace with the shipping address.
		if ( 'shipping' === $type ) {

			// Retrieve shipping address.
			$shipping_address = get_post_meta( $invoice->get_id(), 'shipping_address', true );

			// Ensure it is valid.
			if ( is_array( $shipping_address ) ) {

				if ( ! empty( $shipping_address['address'] ) ) {
					$address['line1'] = $shipping_address['address'];
				}

				if ( ! empty( $shipping_address['city'] ) ) {
					$address['city'] = $shipping_address['city'];
				}

				if ( ! empty( $shipping_address['country'] ) ) {
					$address['country'] = $shipping_address['country'];
				}

				if ( ! empty( $shipping_address['zip'] ) ) {
					$address['postal_code'] = $shipping_address['zip'];
				}

				if ( ! empty( $shipping_address['state'] ) ) {
					$address['state'] = $shipping_address['state'];
				}
}
}

		foreach ( $address as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $address[ $key ] );
			}
		}

		return $address;
	}

	/**
	 * Helper function to retrieve the shipping details.
	 *
	 * Added here since most resource use it.
	 * @param WPInv_invoice $invoice
	 * @param string $type
	 * @return array
	 */
	public function get_shipping_info( $invoice ) {

		$info = array(
			'address' => $this->invoice_address( $invoice, 'shipping' ),
			'name'    => $invoice->get_full_name(),
			'phone'   => $invoice->get_phone_number(),
		);

		$shipping_address = get_post_meta( $invoice->get_id(), 'shipping_address', true );

		if ( is_array( $shipping_address ) ) {

			if ( ! empty( $shipping_address['phone'] ) ) {
				$info['phone'] = $shipping_address['phone'];
			}

			$name = '';
			if ( ! empty( $shipping_address['first_name'] ) ) {
				$name = $shipping_address['first_name'] . ' ';
			}

			if ( ! empty( $shipping_address['last_name'] ) ) {
				$name .= $shipping_address['last_name'];
			}

			if ( ! empty( $name ) ) {
				$info['name'] = trim( $name );
			}
		}

		if ( empty( $info['name'] ) ) {
			$info['name'] = 'Not Provided';
		}

		foreach ( $info as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $info[ $key ] );
			}
		}

		return $info;
	}

	/**
	 * You can specify up to 50 keys, with key names up to 40 characters long
	 * and values up to 500 characters long.
	 */
	public function clean_metadata( $meta ) {
		$clean = array();

		if ( ! is_array( $meta ) ) {
			return $clean;
		}

		foreach ( $meta as $key => $value ) {
			$clean[ getpaid_limit_length( $key, 40 ) ] = getpaid_limit_length( $value, 500 );
		}

		return array_slice( $clean, 0, 50, true );
	}

}
