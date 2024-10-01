<?php
/**
 * Handles the Stripe Methods API.
 *
 * PaymentMethod objects represent your customer's payment instruments.
 * They can be used with PaymentIntents to collect payments or saved to Customer objects to store instrument details for future payments.
 * @link https://stripe.com/docs/api/payment_methods
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe payment method.
 *
 */
class GetPaid_Stripe_Payment_Method extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'paymentMethods';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'paymentMethod';

	/**
	 * Remote id.
	 *
	 * @var string
	 */
	public $object;

	/**
	 * Returns the remote payment intent's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return $this->object;
	}

	/**
	 * Creates a payment method from a token.
	 *
	 * @return string
	 */
	public function create_from_token( $token_id ) {

		$payment_method = $this->call(
			'create',
			array(
				array(
					'type' => 'card',
					'card' => array( 'token' => $token_id ),
				),
			)
		);

		return $payment_method->id;
	}

	/**
	 * Attaches the payment method to a customer.
	 *
	 * @param string $customer_id
	 * @param WPInv_Invoice $invoice
	 * @param bool $save Whether or not to save the payment method locally.
	 * @link https://stripe.com/docs/api/payment_methods/attach
	 * @return string|WP_Error Payment method id or WP_Error on failure.
	 */
	public function attach( $customer_id, $invoice, $save = false ) {

		$this->invoice  = $invoice;
		$payment_method = $this->call(
			'attach',
			array(
				$this->get_remote_id(),
				array(
					'customer' => $customer_id,
				),
			)
		);

		if ( is_wp_error( $payment_method ) ) {
			return $payment_method;
		}

		// Add a note about the validation response.
		$invoice->add_note(
			// translators: %s is the payment method id.
			sprintf( __( 'Created Stripe payment profile: %s', 'wpinv-stripe' ), $payment_method->id ),
			false,
			false,
			true
		);

		if ( $save ) {
			$this->save( $invoice, $payment_method );
		}

		return $payment_method->id;
	}

	/**
	 * Detaches the payment method from the customer.
	 *
	 * @link https://stripe.com/docs/api/payment_methods/detach
	 * @return string|WP_Error Payment method id or WP_Error on failure.
	 */
	public function detach() {

		$payment_method = $this->call(
			'detach',
			array(
				$this->get_remote_id(),
			)
		);

		return is_wp_error( $payment_method ) ? $payment_method : $payment_method->id;
	}

	/**
	 * Saves a customer payment profile.
	 *
	 *
	 * @param WPInv_Invoice $invoice Invoice.
	 * @param string|\Stripe\PaymentMethod $payment_method The payment method to save.
	 * @return string|WP_Error payment method id or WP_Error.
	 */
	public function save( $invoice, $payment_method ) {

		$default = false;

		if ( is_string( $payment_method ) ) {
			$default        = true;
			$payment_method = $this->get();
		}

		if ( is_wp_error( $payment_method ) ) {
			return $payment_method;
		}

		$this->gateway->save_token(
			array(
				'id'       => $payment_method->id,
				'name'     => $payment_method->card->brand . ' &middot;&middot;&middot;&middot; ' . $payment_method->card->last4,
				'default'  => $default,
				'currency' => $invoice->get_currency(),
				'type'     => $this->gateway->is_sandbox( $invoice ) ? 'sandbox' : 'live',
			)
		);

		return $payment_method->id;

	}

}
