<?php
/**
 * Handles the Stripe refunds API.
 *
 * @link https://stripe.com/docs/api/refunds/create
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe refund.
 *
 */
class GetPaid_Stripe_Refund extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'refunds';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'refund';

	/**
	 * Charge id or PaymentIntent id.
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
	 * Retrieves the args for creating/updating a resource.
	 *
	 *
	 * @return array.
	 */
	public function get_args() {
		return array(
			'charge' => $this->object,
		);
	}

}
