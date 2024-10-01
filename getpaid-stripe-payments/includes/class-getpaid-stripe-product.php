<?php
/**
 * Represents a stripe product.
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe product.
 *
 */
class GetPaid_Stripe_Product extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'products';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'product';

	/**
	 * Local item
	 *
	 * @var WPInv_Item|GetPaid_Form_Item
	 */
	public $item;

	/**
	 * Returns the item's profile meta name.
	 *
	 *
	 * @return string
	 */
	public function get_item_profile_meta_name() {
		return $this->gateway->is_sandbox( $this->object ) ? 'wpinv_stripe_sandbox_product_id' : 'wpinv_stripe_product_id';
	}

	/**
	 * Retrives a local profile meta value.
	 *
	 * @return string.
	 */
	public function get_remote_id() {
		return get_post_meta( $this->item->get_id(), $this->get_item_profile_meta_name(), true );
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
	public function get_args() {

		$item = $this->item;
		$args = array(
			'name'        => strip_tags( $item->get_name() ),
			'description' => strip_tags( $item->get_description() ),
			'metadata'    => $this->clean_metadata(
				array(
					'ID'      => $item->get_id(),
					'Created' => getpaid_format_date_value( $item->get_date_created() ),
					'Edit'    => $item->get_edit_url(),
					'Price'   => html_entity_decode( strip_tags( $item->get_the_price() ) ),
				)
			),
		);

		if ( empty( $args['description'] ) ) {
			unset( $args['description'] );
		}

		$args = apply_filters( 'getpaid_stripe_product_args', $args, $item, $this );

		return $args;
	}

}
