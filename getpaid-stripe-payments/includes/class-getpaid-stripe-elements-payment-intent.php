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
class GetPaid_Stripe_Elements_Payment_Intent extends GetPaid_Stripe_Resource {

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
	 * Local submission object.
	 *
	 * @var GetPaid_Payment_Form_Submission
	 */
	public $object;

	/**
	 * Local invoice object.
	 *
	 * @var WPInv_Invoice
	 */
	public $invoice;

	public $current_amount = 0;

	/**
	 * Returns the remote payment intent's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {

		// In case a raw payment intent is passed in.
		if ( is_string( $this->object ) ) {
			$intent_id = $this->object;
		} else {
			$intent_id = $this->object->get_field( 'stripe_payment_intent' );
		}

		if ( empty( $intent_id ) && $this->invoice && 0 === strpos( $this->invoice->get_transaction_id(), 'pi_' ) ) {
			$intent_id = $this->invoice->get_transaction_id();
		}

		// Intent id from saved post meta.
		if ( empty( $intent_id ) && ! empty( $this->invoice ) && $this->invoice->exists() ) {
			$intent_id = get_post_meta( (int) $this->invoice->get_id(), '_gp_stripe_intent_id', true );
		}

		if ( empty( $intent_id ) ) {
			return '';
		}

		if ( ! is_object( $this->object ) ) {
			return $intent_id;
		}

		$is_setup  = 0 === strpos( $intent_id, 'seti_' );
		$use_setup = $this->use_setup_intent_for_submission();

		if ( $is_setup === $use_setup ) {
			return $intent_id;
		}

		return '';
	}

	/**
	 * Retrieves the args for creating/updating an item.
	 *
	 * @return array.
	 */
	public function get_args( $amount = false ) {

		// Prepare variables.
		$invoice     = $this->object_invoice() ? $this->object_invoice() : $this->object->get_billing_email();
		$customer    = new GetPaid_Stripe_Customer( $this->gateway, $invoice );
		$customer_id = '';

		if ( ! empty( $invoice ) && $customer->exists() ) {
			$customer_id = $customer->get_remote_id();
		} elseif ( $this->object_invoice() ) {
			$customer    = $customer->create();
			$customer_id = is_wp_error( $customer ) ? '' : $customer->id;
		}

		// Prepare amount.
		if ( false === $amount ) {
			$amount = $this->object->get_total();
		}

		$this->current_amount = $amount;

		// Subscriptions.
		if ( $this->object->has_recurring && empty( $amount ) ) {
			return $this->get_setup_intent_args( $customer_id );
		}

		// One-time payments.
		return $this->get_payment_intent_args( $customer_id, $amount );
	}

	/**
	 * Retrieves the payment intent args.
	 *
	 * @return array
	 */
	protected function get_payment_intent_args( $customer_id, $amount = false ) {
		/*if ( ! wpinv_stripe_is_zero_decimal_currency( $this->object->get_currency() ) ) {
			$amount = $amount * 100;
		}*/
		$amount = getpaid_stripe_get_amount( $amount, $this->object->get_currency() );

		// Prepare metadata.
		$meta = array();

		if ( $this->invoice ) {
			$meta                 = get_post_meta( $this->invoice->get_id(), 'payment_form_data', true );
			$meta                 = is_array( $meta ) ? $meta : array();
			$meta['invoice_id']   = $this->invoice->get_id();
			$meta['invoice_url']  = $this->invoice->get_view_url();
			$meta['invoice_date'] = $this->invoice->get_date_created();
			$meta['invoice_key']  = $this->invoice->get_key();
		}

		// Prepare args.
		$args = array(
			'customer'    => $customer_id,
			'amount'      => (int) $amount,
			'currency'    => strtolower( $this->object->get_currency() ),
			'shipping'    => $this->the_shipping_info(),
			'metadata'    => $this->clean_metadata( $meta ),
			'description' => $this->invoice ?
				sprintf(
					// translators: %1$s: invoice number
					__( 'Payment for invoice %s', 'wpinv-stripe' ),
					$this->invoice->get_number()
				) : '',
		);

		// Prepare args.
		$payment_methods = wpinv_get_option( 'stripe_payment_methods', array() );

		if ( empty( $payment_methods ) ) {
			$payment_methods = array( 'card' );
		}

		// Subscriptions.
		if ( $this->object->has_recurring ) {
			$allowed         = wp_parse_list( 'acss_debit au_becs_debit bacs_debit bancontact blik boleto card card_present ideal link sepa_debit sofort us_bank_account' );
			$payment_methods = array_intersect( $payment_methods, $allowed );

			$args['setup_future_usage'] = 'off_session';
		}

		$args['payment_method_types'] = apply_filters( 'getpaid_stripe_payment_method_types', array_values( $payment_methods ) );

		$remote_id = $this->get_remote_id();

		if ( empty( $args['customer'] ) ) {
			unset( $args['customer'] );
		} elseif ( ! empty( $remote_id ) ) {
			$payment_intent = $this->get();

			if ( ! is_wp_error( $payment_intent ) && ! empty( $payment_intent->customer ) ) {
				unset( $args['customer'] );
			}
		}

		if ( $this->get_remote_id() ) {
			unset( $args['payment_method_types'] );
		}

		return apply_filters( 'getpaid_stripe_payment_intent_args', array_filter( $args ), $customer_id, $this );

	}

	/**
	 * Retrieves the setup intent args.
	 *
	 * @return array
	 */
	protected function get_setup_intent_args( $customer_id ) {

		// Prepare metadata.
		$meta = array();

		if ( $this->invoice ) {
			$meta                     = get_post_meta( $this->invoice->get_id(), 'payment_form_data', true );
			$meta                     = is_array( $meta ) ? $meta : array();
			$meta['invoice_id']       = $this->invoice->get_id();
			$meta['invoice_key']      = $this->invoice->get_key();
			$meta['invoice_url']      = $this->invoice->get_view_url();
			$meta['invoice_date']     = $this->invoice->get_date_created();
		}

		// Prepare args.
		$payment_methods = wpinv_get_option( 'stripe_payment_methods', array() );

		if ( empty( $payment_methods ) ) {
			$payment_methods = array( 'card' );
		}

		$payment_methods = apply_filters( 'getpaid_stripe_payment_method_types', array_values( $payment_methods ) );
		$allowed         = wp_parse_list( 'acss_debit au_becs_debit bacs_debit bancontact blik boleto card card_present ideal link sepa_debit sofort us_bank_account' );
		$payment_methods = array_intersect( $payment_methods, $allowed );

		$args = array(
			'customer'             => $customer_id,
			'metadata'             => $this->clean_metadata( $meta ),
			'payment_method_types' => array_values( $payment_methods ), // card, acss_debit, au_becs_debit, bacs_debit, blik, boleto, ideal, link, us_bank_account, sepa_debit, sofort.
		);

		if ( empty( $args['customer'] ) ) {
			unset( $args['customer'] );
		} else {
			$setup_intent = $this->get();

			if ( ! is_wp_error( $setup_intent ) && ! empty( $setup_intent->customer ) ) {
				unset( $args['customer'] );
			}
		}

		if ( $this->get_remote_id() ) {
			unset( $args['payment_method_types'] );
		}

		return apply_filters( 'getpaid_stripe_setup_intent_args', array_filter( $args ), $customer_id, $this );

	}

	/**
     * Prepares the shipping details.
     *
	 * @param string $type
	 * @return array
     */
    public function get_address_info( $type = 'shipping' ) {

		$data = $this->object->get_data();

		if ( empty( $data['same-shipping-address'] ) ) {
			return $this->prepare_address_details( $data, sanitize_key( $type ) );
		}

		return $this->prepare_address_details( $data, 'billing' );

	}

	/**
     * Retrieves address details.
     *
	 * @return array
	 * @param array $posted
	 * @param string $type
     */
    private function prepare_address_details( $posted, $type = 'billing' ) {

		$address  = array();
		$prepared = array();

		if ( ! empty( $posted[ $type ] ) ) {
			$address = $posted[ $type ];
		}

		// Clean address details.
		foreach ( $address as $key => $value ) {
			$key             = sanitize_key( $key );
			$key             = str_replace( 'wpinv_', '', $key );
			$value           = wpinv_clean( $value );
			$prepared[ $key ] = apply_filters( "getpaid_checkout_{$type}_address_$key", $value, $this->object, $this->object->get_invoice() );
		}

		// Filter address details.
		$prepared = apply_filters( "getpaid_checkout_{$type}_address", $prepared, $this->object, $this->object->get_invoice() );

		// Remove non-whitelisted values.
		$prepared = array_filter( $prepared, 'getpaid_is_address_field_whitelisted', ARRAY_FILTER_USE_KEY );

	}

	/**
	 * Helper function to retrieve the shipping details.
	 *
	 * Added here since most resource use it.
	 * @param string $type
	 * @return array
	 */
	public function the_shipping_info( $type = 'shipping' ) {

		$address = $this->get_address_info( $type );

		if ( empty( $address ) ) {
			$address = $this->get_address_info( 'billing' );
		}

		if ( empty( $address ) ) {
			return array();
		}

		$info = array(
			'address' => $address,
			'name'    => '',
		);

		if ( isset( $address['first_name'] ) ) {
			$info['name'] = $address['first_name'];
			unset( $info['address']['first_name'] );
		}

		if ( ! empty( $address['last_name'] ) ) {
			$info['name'] .= trim( ' ' . $address['last_name'] );
		}

		$info['name'] = empty( $info['name'] ) ? 'Not Provided' : $info['name'];

		if ( ! empty( $address['phone'] ) ) {
			unset( $info['address']['phone'] );
			$info['phone'] = trim( $address['phone'] );
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
	 * Executes an api call.
	 *
	 *
	 * @param string $method The method to call.
	 * @param array  $args An array of args to pass to the method as an indexed array.
	 * @link https://stripe.com/docs/api/errors/handling
	 * @return \Stripe\paymentIntent|\Stripe\setupIntent|WP_Error
	 */
	public function call( $method, $args = array() ) {

		try {

			$intent_id = $this->get_remote_id();
			$stripe    = $this->gateway->get_stripe( $this->object_invoice() );

			if ( $intent_id && 0 === strpos( $intent_id, 'seti_' ) ) {
				$object = $stripe->setupIntents;
			} else {
				$object = $stripe->paymentIntents;
			}

			if ( is_object( $this->object ) ) {
				if ( $this->use_setup_intent_for_submission() ) {
					$object = $stripe->setupIntents;
				} else {
					$object = $stripe->paymentIntents;
				}
			}

			return $this->_call( array( $object, $method ), $args );

		} catch ( Exception $e ) {

			// Something else happened, completely unrelated to Stripe.
			return $this->error_or_exception( false, $e );

		}
	}

	private function use_setup_intent_for_submission() {

		$amount = $this->object->get_total();

		return ! empty( $this->object->has_recurring ) && empty( $amount );
	}

}
