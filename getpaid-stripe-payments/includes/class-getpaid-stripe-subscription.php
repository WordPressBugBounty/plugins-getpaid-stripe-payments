<?php
/**
 * Handles the Stripe Subscriptions API.
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe subscription.
 *
 */
class GetPaid_Stripe_Subscription extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'subscriptions';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'subscription';

	/**
	 * Local Subscription object.
	 *
	 * @var WPInv_Subscription
	 */
	public $object;

	/**
	 * Local items
	 *
	 * @var GetPaid_Form_Item[]
	 */
	public $items = array();

	/**
	 * Whether or not the initial payment has been processed.
	 *
	 * @var bool
	 */
	public $initial_payment_processed = false;

	/**
	 * Returns the remote subscription's id.
	 *
	 * @return string
	 */
	public function get_remote_id() {
		return $this->object->get_profile_id();
	}

	/**
	 * Returns the object invoice.
	 *
	 * This is used to calculate whether or not this is a live/sandbox transaction.
	 * @return WPInv_Invoice
	 */
	public function object_invoice() {
		return $this->object->get_parent_payment();
	}

	/**
	 * Retrieves the args for creating/updating a subscription item.
	 *
	 *
	 * @param bool
	 * @return array.
	 */
	public function get_args( $is_first = false ) {

		$invoice          = $this->object->get_parent_invoice();
		$customer         = new GetPaid_Stripe_Customer( $this->gateway, $invoice );
		$items            = array();
		$initial_amount   = 0;
		$recurring_amount = 0;

		// Recurring items.
		if ( empty( $this->items ) ) {

			$item              = $this->object->get_product();
			$product           = new GetPaid_Stripe_Product( $this->gateway, $invoice );
			$product->item     = $item;

			// Prepare subscription amounts.
			$initial_amount    = $this->object->get_initial_amount();
			$recurring_amount  = $this->object->get_recurring_amount();
			$initial_amount    = getpaid_stripe_get_amount( $initial_amount, $invoice->get_currency() );
			$recurring_amount  = getpaid_stripe_get_amount( $recurring_amount, $invoice->get_currency() );

			/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
				$initial_amount   = $initial_amount * 100;
				$recurring_amount = $recurring_amount * 100;
			}*/

			if ( $this->initial_payment_processed ) {
				$initial_amount = 0;
			}

			$items[] = array(

				'price_data' => array(
					'currency'    => $invoice->get_currency(),
					'product'     => $product->get_remote_id(),
					'recurring'   => array(
						'interval'       => $this->object->get_period(),
						'interval_count' => $this->object->get_frequency(),
					),
					'unit_amount' => $recurring_amount,
				),

			);

		} else {

			if ( $is_first && ! $this->initial_payment_processed ) {
				$initial_amount = $invoice->get_non_recurring_total();
				$initial_amount = getpaid_stripe_get_amount( $initial_amount, $invoice->get_currency() );

				/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
					$initial_amount = $initial_amount * 100;
				}*/
			}

			foreach ( $this->items as $item ) {
				$product       = new GetPaid_Stripe_Product( $this->gateway, $invoice );
				$product->item = $item;
				$initial       = $item->get_sub_total();
				$recurring     = $item->get_recurring_sub_total();
				$initial       = getpaid_stripe_get_amount( $initial, $invoice->get_currency() );
				$recurring     = getpaid_stripe_get_amount( $recurring, $invoice->get_currency() );

				/*if ( ! wpinv_stripe_is_zero_decimal_currency( $invoice->get_currency() ) ) {
					$initial   = $initial * 100;
					$recurring = $recurring * 100;
				}*/

				if ( 'trialling' !== $this->object->get_status() && ! $this->initial_payment_processed ) {
					$initial_amount += $initial;
				}

				$recurring_amount += $recurring;

				$items[] = array(

					'price_data' => array(
						'currency'    => $invoice->get_currency(),
						'product'     => $product->get_remote_id(),
						'recurring'   => array(
							'interval'       => $this->object->get_period(),
							'interval_count' => $this->object->get_frequency(),
						),
						'unit_amount' => $recurring,
					),

				);

			}
		}

		// Subscription data.
		$meta                     = get_post_meta( $invoice->get_id(), 'payment_form_data', true );
		$meta                     = is_array( $meta ) ? $meta : array();
		$meta['subscription_id']  = $this->object->get_id();
		$meta['invoice_id']       = $invoice->get_id();
		$meta['invoice_url']      = $invoice->get_view_url();
		$meta['subscription_url'] = $this->object->get_view_url();
		$subscription_data        = array(
			'default_payment_method' => get_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', true ),
			'customer'               => $customer->get_remote_id(),
			'items'                  => $items,
			'metadata'               => $this->clean_metadata( $meta ),
			'expand'                 => array( 'latest_invoice.payment_intent', 'pending_setup_intent' ),
		);

		$customer->update();

		if ( empty( $subscription_data['default_payment_method'] ) ) {
			unset( $subscription_data['default_payment_method'] );
		}

		// Trial periods.
		if ( 'trialling' === $this->object->get_status() ) {
			$subscription_data['trial_end'] = strtotime( $this->object->get_next_renewal_date() );
		} elseif ( $this->initial_payment_processed ) {
			$subscription_data['billing_cycle_anchor'] = strtotime( $this->object->get_next_renewal_date_gmt() ) - HOUR_IN_SECONDS;
			$subscription_data['proration_behavior']   = 'none';
		}

		// Max renewals.
		if ( 0 < $this->object->get_bill_times() ) {

			$expires = ( $this->object->get_bill_times() * $this->object->get_frequency() ) . ' ' . $this->object->get_period();
			$base    = empty( $subscription_data['trial_end'] ) ? time() : $subscription_data['trial_end'];
			$subscription_data['cancel_at'] = strtotime( gmdate( 'Y-m-d H:i:s', strtotime( "+ $expires", $base ) ) ) - HOUR_IN_SECONDS;

		}

		// Discounts/Set-up fees.
		if ( 0 != $initial_amount && $initial_amount != $recurring_amount ) {

			$invoice_item = new GetPaid_Stripe_Invoice_Item( $this->gateway, $invoice );
			$invoice_item = $invoice_item->save_item(
				$initial_amount - $recurring_amount,
				$invoice->get_currency(),
				$customer->get_remote_id(),
				__( 'Misc', 'wpinv-stripe' )
			);

			if ( is_wp_error( $invoice_item ) ) {
				wpinv_set_error( $invoice_item->get_error_code(), $invoice_item->get_error_message() );
				return;
			}

			update_post_meta( $invoice->get_id(), 'wpinv_associated_stripe_invoice_item', $invoice_item->id );

		}

		$subscription_data = apply_filters( 'getpaid_stripe_subscription_data', $subscription_data, $invoice, $this );

		return $subscription_data;
	}

	/**
	 * Updates the default payment method.
	 *
	 * @return \Stripe\Subscription|WP_Error
	 */
	public function update_payment_method( $new_payment_method ) {

		return $this->call(
			'update',
			array(
				$this->get_remote_id(),
				array(
					'default_payment_method' => $new_payment_method,
				),
			)
		);

	}

	/**
	 * Cancels a subscription.
	 *
	 * @return \Stripe\Subscription|WP_Error
	 */
	public function cancel() {
		return $this->call(
			'cancel',
			array(
				$this->get_remote_id(),
			)
		);
	}

	/**
	 * Starts a subscription.
	 *
	 * @param $bool Whether or not to send to the success page.
	 * @param $bool Whether or not to add non-recurring totals.
	 * @param bool $is_initial_payment_taken Is initial payment taken.
	 * @return string|WP_Error subscription id or error.
	 */
	public function start( $last_subscription = true, $first_subscription = false, $is_initial_payment_taken = false ) {

		$this->initial_payment_processed = $is_initial_payment_taken;

		$subscription = $this->call(
			'create',
			array(
				$this->get_args( $first_subscription ),
			)
		);

		if ( is_wp_error( $subscription ) ) {

			if ( ! $is_initial_payment_taken ) {
				$invoice_item = new GetPaid_Stripe_Invoice_Item( $this->gateway, $this->object->get_parent_payment() );
				$invoice_item->delete();
				wpinv_set_error( $subscription->get_error_code(), $subscription->get_error_message() );
			}
			return;
		}

		// Save the profile id.
		$this->object->set_profile_id( $subscription->id );
		$this->object->save();

		// Check if the subscription is active.
		if ( isset( $subscription->status ) && ( 'trialing' === $subscription->status || 'active' === $subscription->status ) ) {

			// Mark the invoice as paid.
			$this->activate_subscription( $subscription );

			if ( $last_subscription ) {
				$invoice = $this->object->get_parent_invoice();

				// Set the remote subscription id.
				$invoice->set_remote_subscription_id( $subscription->id );

				if ( ! $is_initial_payment_taken && ! empty( $subscription->latest_invoice->charge ) ) {
					$invoice->set_transaction_id( $subscription->latest_invoice->charge );
				}

				if ( ! $is_initial_payment_taken ) {
					$invoice->mark_paid();
				} else {
					$invoice->save();
				}

				wpinv_send_to_success_page( array( 'invoice_key' => $this->object->get_parent_invoice()->get_key() ) );
			}
		} elseif ( empty( $subscription->latest_invoice->payment_intent ) ) {
			// Process the payment intent.
			wpinv_set_error( 'wpinv_stripe_intent_error', __( 'Payment Intent creation failed while creating your subscription.', 'wpinv-stripe' ) );
			return;
		} else {
			$this->handle_subscription_intent( $subscription->latest_invoice->payment_intent, $subscription, $last_subscription );
		}

	}

	/**
	 * Activates a subscription.
	 *
	 * @param \Stripe\Subscription $subscription
	 */
	public function activate_subscription( $subscription ) {

		if ( ! empty( $subscription ) ) {
			$this->object->set_next_renewal_date( gmdate( 'Y-m-d H:i:s', $subscription->current_period_end ) );
			$this->object->set_date_created( gmdate( 'Y-m-d H:i:s', $subscription->current_period_start ) );
			$this->object->set_profile_id( $subscription->id );
		}

		$this->object->activate();

	}

	/**
	 * Handles SCA.
	 *
	 * @link https://stripe.com/docs/api/setup_intents/object
	 * @param \Stripe\SetupIntent $setup_intent
	 */
	public function handle_setup_intent( $setup_intent ) {

		update_post_meta( $this->object->get_parent_invoice_id(), 'wpinv_stripe_intent_id', $setup_intent->id );

		// need to call handleCardSetup to fulfill 3DS if needed
		$verification_url = add_query_arg(
			array(
				'invoice_id'      => $this->object->get_parent_invoice_id(),
				'confirm-payment' => 'yes',
				'nonce'           => wp_create_nonce( 'wpinv_stripe_confirm_payment' ),
				'redirect_to'     => rawurlencode(
					add_query_arg(
						array( 'invoice_key' => $this->object->get_parent_invoice()->get_key() ),
						wpinv_get_success_page_uri()
					)
				),
			),
			home_url()
		);

		$redirect = sprintf( '#wpi-confirm-si-%s:%s', $setup_intent->client_secret, rawurlencode( $verification_url ) );
		wp_redirect( $redirect );
		exit;

	}

	/**
	 * Payment intents.
	 *
	 * @link https://stripe.com/docs/api/payment_intents
	 * @param \Stripe\PaymentIntent $intent
	 * @param \Stripe\Subscription $subscription
	 * @param bool $send_to_success
	 */
	public function handle_subscription_intent( $intent, $subscription, $send_to_success = false ) {

		$invoice = $this->object->get_parent_payment();
		$object  = new GetPaid_Stripe_Payment_Intent( $this->gateway, $invoice );
		$result  = $object->process( $intent );

		if ( is_wp_error( $result ) ) {
			wpinv_error_log( $intent, __( 'Payment Intent Failed', 'wpinv-stripe' ) );
			wpinv_set_error( $result->get_error_code(), $result->get_error_message() );
			return;
		}

		// The payment is still processing.
		if ( 2 == $result ) {
			$invoice->set_status( 'wpi-onhold' );
			wpinv_set_error( 'processing', __( 'This invoice will be marked as paid as soon as we confirm your payment.', 'wpinv-stripe' ), 'info' );
		} else {
			$this->activate_subscription( $subscription );

			if ( $send_to_success ) {
				$invoice->set_remote_subscription_id( $subscription->id );
				$invoice->set_transaction_id( $result );
				$invoice->add_note( sprintf( __( 'Stripe Charge ID: ', 'wpinv-stripe' ), $result ), false, false, true );
				$invoice->mark_paid();
			}
		}

	}

}
