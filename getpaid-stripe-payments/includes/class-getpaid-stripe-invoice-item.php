<?php
/**
 * Handles the Stripe Invoice Items API.
 *
 * These are useful for adding fees/discounts to subscriptions
 * @link https://stripe.com/docs/api/invoiceitems
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe payment method.
 *
 */
class GetPaid_Stripe_Invoice_Item extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'invoiceItems';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'invoiceItem';

	/**
	 * Remote id.
	 *
	 * @var WPInv_Invoice
	 */
	public $object;

	/**
	 * Returns the remote invoice item's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return get_post_meta( $this->object->get_id(), 'wpinv_associated_stripe_invoice_item', true );
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
	 * Creates the resource in Stripe.
	 *
	 * @return \Stripe\invoiceItem|WP_Error
	 */
	public function save_item( $amount, $currency, $customer, $description ) {

		$args = array(
			'amount'      => $amount,
			'currency'    => $currency,
			'customer'    => $customer,
			'description' => $description,
		);

		if ( empty( $args['description'] ) ) {
			unset( $args['description'] );
		}

		return $this->call( 'create', array( $args ) );

	}

	/**
	 * Deletes the resource in Stripe.
	 *
	 * @return \Stripe\invoiceItem|WP_Error
	 */
	public function delete() {
		return $this->call( 'delete', array( $this->get_remote_id() ) );
	}

}
