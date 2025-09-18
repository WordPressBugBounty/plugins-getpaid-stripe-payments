<?php
/**
 * Stripe payment gateway
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Payment Gateway class.
 *
 */
class GetPaid_Stripe_Gateway extends GetPaid_Payment_Gateway {

	/**
	 * Payment method id.
	 *
	 * @var string
	 */
	public $id = 'stripe';

	/**
	 * An array of features that this gateway supports.
	 *
	 * @var array
	 */
	protected $supports = array(
		'subscription',
		'sandbox',
		'addons',
		'single_subscription_group',
		'multiple_subscription_groups',
		'refunds',
	);

	/**
	 * Payment method order.
	 *
	 * @var int
	 */
	public $order = 5;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->title        = __( 'Stripe', 'wpinv-stripe' );
		$this->method_title = __( 'Stripe Payment', 'wpinv-stripe' );

		add_action( 'admin_init', array( $this, 'maybe_redirect_to_settings' ) );
		add_filter( 'getpaid_get_stripe_connect_url', array( $this, 'maybe_get_connect_url' ), 10, 2 );
		add_action( 'getpaid_authenticated_admin_action_connect_stripe', array( $this, 'connect_stripe' ) );
		add_action( 'getpaid_authenticated_admin_action_disconnect_stripe', array( $this, 'disconnect_stripe' ) );
		add_action( 'wpinv_stripe_connect', 'GetPaid_Stripe_Admin::display_connect_buttons' );

		if ( $this->enabled ) {
			add_filter( 'getpaid_stripe_sandbox_notice', array( $this, 'sandbox_notice' ) );
			add_action( 'getpaid_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'getpaid-single-subscription-page-actions', array( $this, 'show_update_payment_method_button' ) );
			add_action( 'getpaid_stripe_subscription_cancelled', array( $this, 'subscription_cancelled' ) );
			add_action( 'getpaid_delete_subscription', array( $this, 'subscription_cancelled' ) );
			add_action( 'getpaid_refund_invoice_remotely', array( $this, 'refund_invoice' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'getpaid_daily_maintenance', array( $this, 'maybe_store_webhooks' ) );
			add_filter( 'wpinv_get_emails', array( $this, 'register_email_settings' ) );
			add_filter( 'getpaid_notification_email_subscription_triggers', array( $this, 'filter_email_triggers' ) );
			add_action( 'getpaid_subscription_notification_email_register_hook', array( $this, 'init_email_type_hook' ), 10, 2 );
			add_action( 'getpaid_template_default_template_path', array( $this, 'maybe_filter_default_template_path' ), 10, 2 );
			add_action( 'wpinv_tools_row', array( $this, 'register_expired_subscriptions_tool' ), 10, 2 );
			add_action( 'getpaid_authenticated_admin_action_stripe_check_expired_subscriptions', array( $this, 'admin_check_expired_subscriptions' ) );
			add_action( 'getpaid_authenticated_admin_action_stripe_manually_process_webhook_event', array( $this, 'admin_manually_process_webhook_event' ) );
			add_filter( 'getpaid_submission_js_data', array( $this, 'filter_submission_js_data' ), 10, 2 );
			add_action( 'wp', array( $this, 'maybe_process_payment_intent' ) );
			add_action( 'wp', array( $this, 'maybe_process_setup_intent' ) );
		}

	}

	/**
	* Checks if we should load Stripe.js globally.
	*
	*/
	public function load_stripe_js_globally() {
		$load = (bool) wpinv_get_option( 'load_stripe_js_globally', true );
		return apply_filters( 'wpinv_load_stripe_js_globally', $load );
	}

	/**
	* Checks if we should redirect to stripe checkout.
	*
	*/
	public function redirect_to_stripe() {
		$redirect = (bool) wpinv_get_option( 'redirect_stripe_checkout', false );
		return apply_filters( 'wpinv_redirect_to_stripe', $redirect );
	}

	/**
	 * Loads Stripe Scripts.
	 *
	 */
	public function enqueue_scripts() {

		$key = $this->is_sandbox() ? wpinv_get_option( 'stripe_test_publishable_key' ) : wpinv_get_option( 'stripe_live_publishable_key' );

		if ( empty( $key ) || $this->redirect_to_stripe() ) {
			return;
		}

		// Load Stripe.js globally or only on checkout pages.
		if ( ! wpinv_is_checkout() && ! $this->load_stripe_js_globally() ) {
			return;
		}

		$version = filemtime( WPINV_STRIPE_DIR . 'assets/js/wpinv-stripe.js' );
		wp_enqueue_script( 'wpinv-stripe-script', WPINV_STRIPE_URL . 'assets/js/wpinv-stripe.js', array( 'stripe' ), $version, true );
		wp_enqueue_script( 'stripe', 'https://js.stripe.com/v3/', array( 'jquery' ), WPINV_STRIPE_VERSION, true );

		wp_localize_script(
			'wpinv-stripe-script',
			'GetPaid_Stripe',
			array(
				'stripePublishableKey' => $key,
				'elementStyle'         => apply_filters( 'wpinv_stripe_element_style', false ),
				'locale'               => wpinv_stripe_get_checkout_locale(),
				'button_type'          => wpinv_get_option( 'stripe_payment_request_button_type', 'default' ),
				'button_theme'         => wpinv_get_option( 'stripe_payment_request_button_theme', 'dark' ),
				'unknownError'         => __( 'An unknown error occurred. Please try again.', 'wpinv-stripe' ),
			)
		);
	}

	/**
	 * Displays the payment method select field.
	 *
	 * @param int $invoice_id 0 or invoice id.
	 * @param GetPaid_Payment_Form $form Current payment form.
	 */
	public function payment_fields( $invoice_id, $form ) {

		$key = $this->is_sandbox() ? wpinv_get_option( 'stripe_test_publishable_key' ) : wpinv_get_option( 'stripe_live_publishable_key' );

		if ( empty( $key ) ) {
			aui()->alert(
				array(
					'type'    => 'danger',
					'heading' => __( 'Stripe not set-up', 'wpinv-stripe' ),
					'content' => __( 'Please ensure that you have setup your Stripe publishable and secret keys in your WordPress admin dashboard, GetPaid > Settings > Payment Gateways > Stripe.', 'wpinv-stripe' ),
				),
				true
			);
			return;
		}

		if ( $this->redirect_to_stripe() ) {
			return;
		}

		?>
			<div id="<?php echo esc_attr( wp_unique_id( 'getpaid-stripe-' ) ); ?>" class="getpaid-stripe-elements mt-1">
				<input type="hidden" name="stripe_payment_intent" class="getpaid-stripe-payment-intent" value="">
				<input type="hidden" name="stripe_payment_intent_secret" class="getpaid-stripe-payment-intent-secret" value="">
				<div class="getpaid-stripe-elements-wrapper mb-3"></div>

				<div class="getpaid-stripe-card-errors" class="mb-3 w-100">
					<!-- Card errors will appear here. -->
				</div>

				<?php

					if ( ! is_ssl() && ! $this->is_sandbox() ) {
						aui()->alert(
							array(
								'type'    => 'error',
								'content' => __( 'Stripe gateway requires HTTPS connection for live transactions.', 'wpinv-stripe' ),
							),
							true
						);
					}

				?>

			</div>

		<?php
	}

	/**
	 * Process Payment.
	 *
	 *
	 * @param WPInv_Invoice $invoice Invoice.
	 * @param array $submission_data Posted checkout fields.
	 * @param GetPaid_Payment_Form_Submission $submission Checkout submission.
	 * @return array
	 */
	public function process_payment( $invoice, $submission_data, $submission ) {

		// Validate Stripe's minimum amount. https://stripe.com/docs/currencies#minimum-and-maximum-charge-amounts
		$min_amount = (float) wpinv_stripe_get_minimum_amount( $invoice->get_currency() );

		if ( ! $invoice->has_free_trial() && $min_amount > $invoice->get_total() ) {
			wpinv_set_error(
				'min_amount_error',
				sprintf(
					// translators: %1$s is the minimum amount.
					__( 'Sorry, the minimum allowed invoice total is %s to use this payment method.', 'wpinv-stripe' ),
					wpinv_price( $min_amount, $invoice->get_currency() )
				)
			);
			wpinv_send_back_to_checkout( $invoice );
		}

		if ( $invoice->is_recurring() && $min_amount > $invoice->get_recurring_total() ) {
			wpinv_set_error(
				'min_amount_error',
				sprintf(
					// translators: %s: minimum amount.
					__( 'Sorry, the minimum allowed recurring amount is %s to use this payment method.', 'wpinv-stripe' ),
					wpinv_price( $min_amount, $invoice->get_currency() )
				)
			);
			wpinv_send_back_to_checkout( $invoice );
		}

		// Maybe redirect to Stripe.
		if ( $this->redirect_to_stripe() ) {
			$this->create_checkout_session( $invoice, $submission );
		}

		// Create / Update a payment intent.
		$payment_intent          = new GetPaid_Stripe_Elements_Payment_Intent( $this, $submission );
		$payment_intent->invoice = $invoice;

		/** @var \Stripe\PaymentIntent|WP_error $remote_intent */
		$remote_intent = $payment_intent->update();

		if ( is_wp_error( $remote_intent ) ) {
			wpinv_set_error( 'stripe_error', $remote_intent->get_error_message() );
			wpinv_send_back_to_checkout( $invoice );
		}

		$invoice->add_note( wp_sprintf( __( 'Stripe Payment Intent ID: %s', 'wpinv-stripe' ), wpinv_clean( $remote_intent->id ) ), false, false, true );

		$invoice->set_transaction_id( $remote_intent->id );
		$invoice->save();

		// Trigger a DOM event.
		wp_send_json_success(
			array(
				'action' => 'event',
				'event'  => 'getpaid_process_stripe_payment',
				'data'   => array(
					'intent'   => $remote_intent->id,
					'redirect' => $invoice->get_receipt_url(),
					'is_setup' => 0 === strpos( $remote_intent->id, 'seti_' ),
				),
			)
		);

	}

	/**
	 * Creates a checkout session.
	 *
	 * @param WPInv_Invoice $invoice Invoice.
	 * @param GetPaid_Payment_Form_Submission $submission Checkout submission.
	 */
	public function create_checkout_session( $invoice, $submission ) {

		$session = new GetPaid_Stripe_Session( $this, $invoice );
		$result  = $session->process();

		if ( is_wp_error( $result ) ) {
			wpinv_set_error( 'stripe_error', $result->get_error_message() );
			wpinv_send_back_to_checkout( $invoice );
		}

		wp_redirect( $result ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Process Payment Intent.
	 *
	 */
	public function maybe_process_payment_intent() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['payment_intent'] ) && isset( $_GET['invoice_key'] ) && wpinv_is_success_page() ) {
			$invoice = wpinv_get_invoice( wpinv_get_invoice_id_by_key( $_GET['invoice_key'] ) );

			try {
				update_post_meta( $invoice->get_id(), '_gp_stripe_process_intent', 1 );

				$this->process_payment_intent( $_GET['payment_intent'], $invoice );

				$invoice = wpinv_get_invoice( $invoice->get_id() );

				if ( $invoice->exists() && $invoice->is_paid() ) {
					wpinv_send_to_success_page( array( 'invoice_key' => $invoice->get_key() ) );
				}

				$redirect = remove_query_arg( array( 'payment_intent', 'payment_intent_client_secret', 'redirect_status' ) );
				$redirect = apply_filters( 'getpaid_stripe_process_payment_intent_redirect', $redirect, $invoice );

				wp_safe_redirect( $redirect );
				exit;
			} catch ( Exception $e ) {
				wpinv_set_error( 'stripe_error', $e->getMessage() );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Process Payment.
	 *
	 * @param string $payment_intent_id Payment intent ID.
	 * @param WPInv_Invoice $invoice Invoice.
	 */
	public function process_payment_intent( $payment_intent_id, $invoice = null, $is_checkout_session = false ) {
		// The payment intent object.
		if ( is_object( $payment_intent_id ) ) {
			/** @var \Stripe\PaymentIntent $payment_intent */
			$payment_intent = $payment_intent_id;
		} else if ( $is_checkout_session ) {
			$_payment_intent          = new GetPaid_Stripe_Payment_Intent( $this, $invoice );
			$_payment_intent->invoice = $invoice;

			/** @var \Stripe\PaymentIntent|WP_error $payment_intent */
			$payment_intent = $_payment_intent->get();
		} else {
			$_payment_intent          = new GetPaid_Stripe_Elements_Payment_Intent( $this, $payment_intent_id );
			$_payment_intent->invoice = $invoice;

			/** @var \Stripe\PaymentIntent|WP_error $payment_intent */
			$payment_intent = $_payment_intent->get();
		}

		// Abort if an error occurred.
		if ( is_wp_error( $payment_intent ) || empty( $payment_intent ) ) {
			return;
		}

		// Retrieve the matching invoice.
		if ( ! $is_checkout_session ) {
			if ( empty( $payment_intent->metadata->invoice_id ) ) {
				return;
			}

			$invoice = wpinv_get_invoice( $payment_intent->metadata->invoice_id );
		}

		if ( empty( $invoice ) || ! $invoice->exists() || $invoice->has_status( 'publish' ) ) {
			return;
		}

		$invoice_id = (int) $invoice->get_id();

		if ( ( $_intent_status = get_post_meta( $invoice_id, '_gp_stripe_intent_status', true ) ) && ( $_intent_id = get_post_meta( $invoice_id, 'wpinv_stripe_intent_id', true ) ) ) {
			if ( $_intent_id == $payment_intent->id && $_intent_status == $payment_intent->status ) {
				// Payment intent status already processed.
				return;
			}
		}

		// Save it to the invoice.
		update_post_meta( $invoice_id, 'wpinv_stripe_intent_id', $payment_intent->id );
		update_post_meta( $invoice_id, '_gp_stripe_intent_status', $payment_intent->status );

		// Process the payment intent.

		// If the payment requires additional actions, such as authenticating with 3D Secure...
		if ( 'requires_action' === $payment_intent->status ) {
			$invoice->set_status( 'wpi-onhold' );
			return $invoice->save();
		}

		// If the payment is processing...
		if ( 'processing' === $payment_intent->status ) {
			$invoice->set_status( 'wpi-processing' );
			return $invoice->save();
		}

		// The payment succeeded.
		if ( 'succeeded' === $payment_intent->status ) {

			if ( ! empty( $payment_intent->latest_charge ) ) {
				$invoice->add_note( wp_sprintf( __( 'Stripe Charge ID: %s', 'wpinv-stripe' ), wpinv_clean( $payment_intent->latest_charge ) ), false, false, true );

				$invoice->set_transaction_id( $payment_intent->latest_charge );
			} else {
				$invoice->add_note( wp_sprintf( __( 'Stripe Payment Intent ID: %s', 'wpinv-stripe' ), wpinv_clean( $payment_intent->id ) ), false, false, true );

				$invoice->set_transaction_id( $payment_intent->id );
			}

			$invoice->mark_paid();

			// Process subscriptions...
			if ( $invoice->is_recurring() && ! empty( $payment_intent->payment_method ) ) {
				$payment_method = is_object( $payment_intent->payment_method ) ? $payment_intent->payment_method->id : $payment_intent->payment_method;

				// The customer does not have a payment method with the ID pm_xyz. The payment method must be attached to the customer.
				if ( ! empty( $payment_intent->charges ) && $payment_intent->charges->data ) {
					$charge = end( $payment_intent->charges->data );

					if ( ! empty( $charge ) && ! empty( $charge->payment_method_details ) ) {
						$payment_method_details = $charge->payment_method_details;

						if ( ! empty( $payment_method_details->type ) && $payment_method_details->type == 'ideal' ) {
							if ( ! empty( $payment_method_details->ideal ) && ! empty( $payment_method_details->ideal->generated_sepa_debit ) ) {
								$payment_method = $payment_method_details->ideal->generated_sepa_debit;
							}
						}
					}
				}

				// Abort if the invoice has been processed.
				if ( get_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', true ) === $payment_method ) {
					return;
				}

				// Save the payment method to the order.
				update_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', $payment_method );

				// Fetch the invoice subscriptions.
				$subscriptions = function_exists( 'getpaid_get_invoice_subscriptions' ) ? getpaid_get_invoice_subscriptions( $invoice ) : getpaid_get_invoice_subscription( $invoice );

				// Process the subscription.
				if ( ! empty( $subscriptions ) ) {
					if ( is_array( $subscriptions ) ) {
						$this->process_subscriptions( $subscriptions, true );
					} else {
						$this->process_normal_subscription( $subscriptions, true );
					}
				}
			}
		}

	}

	/**
	 * Process a setup intent.
	 *
	 */
	public function maybe_process_setup_intent() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		try {
			// Initial setup intent.
			if ( isset( $_GET['setup_intent'] ) && isset( $_GET['invoice_key'] ) ) {
				$invoice = wpinv_get_invoice( wpinv_get_invoice_id_by_key( $_GET['invoice_key'] ) );
				$this->process_setup_intent( $_GET['setup_intent'], $invoice );
				wp_safe_redirect( remove_query_arg( array( 'setup_intent', 'setup_intent_client_secret', 'redirect_status' ) ) );
				exit;
			}

			// Payment method update setup intent.
			if ( isset( $_GET['setup_intent'] ) && isset( $_GET['subscription'] ) ) {
				$subscription = getpaid_get_subscription( absint( $_GET['subscription'] ) );
				$this->process_payment_method_update( $_GET['setup_intent'], $subscription );
				wp_safe_redirect( remove_query_arg( array( 'setup_intent', 'setup_intent_client_secret', 'redirect_status' ) ) );
				exit;
			}
		} catch ( Exception $e ) {
			wpinv_set_error( 'stripe_error', $e->getMessage() );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Processes a set-up intent.
	 *
	 * @param string $setup_intent_id Payment intent ID.
	 * @param WPInv_Subscription|false $subscription The subscription.
	 */
	public function process_payment_method_update( $setup_intent_id, $subscription ) {

		// Abort if no subscription.
		if ( empty( $subscription ) ) {
			return;
		}

		// The setup intent object.
		$setup_intent = new GetPaid_Stripe_Setup_Intent( $this, $subscription );
		$result       = $setup_intent->process();

		// Abort if an error occurred.
		if ( is_wp_error( $result ) ) {
			wpinv_set_error( $result->get_error_code(), $result->get_error_message() );
		} else {
			wpinv_set_error( 'wpinv_payment_success', __( 'Payment method update successful.', 'wpinv-stripe' ), 'success' );
		}

	}

	/**
	 * Processes a set-up intent.
	 *
	 * @param string $setup_intent_id Payment intent ID.
	 */
	public function process_setup_intent( $setup_intent_id, $invoice = null, $is_checkout_session = false ) {

		// The setup intent object.
		$_setup_intent          = new GetPaid_Stripe_Elements_Payment_Intent( $this, $setup_intent_id );
		$_setup_intent->invoice = $invoice;

		/** @var \Stripe\SetupIntent|WP_error $setup_intent */
		$setup_intent = $_setup_intent->get();

		// Abort if an error occurred.
		if ( is_wp_error( $setup_intent ) || 'succeeded' !== $setup_intent->status || empty( $setup_intent->payment_method ) ) {
			return;
		}

		if ( ! $is_checkout_session ) {
			if ( empty( $setup_intent->metadata->invoice_id ) ) {
				return;
			}

			// Retrieve the matching invoice.
			$invoice = wpinv_get_invoice( $setup_intent->metadata->invoice_id );

			if ( empty( $invoice ) || ! $invoice->exists() ) {
				return;
			}
		}

		$payment_method = is_object( $setup_intent->payment_method ) ? $setup_intent->payment_method->id : $setup_intent->payment_method;

		// The customer does not have a payment method with the ID pm_xyz. The payment method must be attached to the customer.
		if ( ! empty( $setup_intent->charges ) && $setup_intent->charges->data ) {
			$charge = end( $setup_intent->charges->data );

			if ( ! empty( $charge ) && ! empty( $charge->payment_method_details ) ) {
				$payment_method_details = $charge->payment_method_details;

				if ( ! empty( $payment_method_details->type ) && $payment_method_details->type == 'ideal' ) {
					if ( ! empty( $payment_method_details->ideal ) && ! empty( $payment_method_details->ideal->generated_sepa_debit ) ) {
						$payment_method = $payment_method_details->ideal->generated_sepa_debit;
					}
				}
			}
		}

		// Abort if the invoice has been processed.
		if ( get_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', true ) === $payment_method ) {
			return;
		}

		// Save the payment method to the order.
		update_post_meta( $invoice->get_id(), 'getpaid_stripe_payment_profile_id', $payment_method );

		// Fetch the invoice subscriptions.
		$subscriptions = function_exists( 'getpaid_get_invoice_subscriptions' ) ? getpaid_get_invoice_subscriptions( $invoice ) : getpaid_get_invoice_subscription( $invoice );

		// Process the subscription.
		if ( ! empty( $subscriptions ) ) {
			if ( is_array( $subscriptions ) ) {
				$this->process_subscriptions( $subscriptions );
			} else {
				$this->process_normal_subscription( $subscriptions );
			}
		}

	}

	/**
	 * Processes normal recurring payments.
	 *
     * @param WPInv_Subscription $subscription Subscription.
	 * @param bool $is_initial_payment_taken Is initial payment taken.
	 */
	public function process_normal_subscription( $subscription, $is_initial_payment_taken = false ) {

		// Process the product.
		$product       = new GetPaid_Stripe_Product( $this, $subscription->get_parent_invoice() );
		$product->item = $subscription->get_product();

		if ( ! $product->exists() ) {
			$_product = $product->create();

			if ( is_wp_error( $_product ) ) {
				wpinv_set_error( $_product->get_error_code(), $_product->get_error_message() );
				return;
			}

			update_post_meta( $subscription->get_product_id(), $product->get_item_profile_meta_name(), $_product->id );
		}

		$stripe_subscription = new GetPaid_Stripe_Subscription( $this, $subscription );
		$stripe_subscription->start( true, false, $is_initial_payment_taken );

	}

	/**
	 * Processes multiple recurring payments.
	 *
     * @param WPInv_Subscription[] $subscriptions Subscription.
	 * @param bool $is_initial_payment_taken Is initial payment taken.
	 */
	public function process_subscriptions( $subscriptions, $is_initial_payment_taken = false ) {

		$is_first  = true;
		$processed = 0;

		foreach ( $subscriptions as $subscription ) {
			$processed++;

			if ( $subscription->exists() ) {
				$this->_process_single_subscription( $subscription, $is_first, count( $subscriptions ) === $processed, $is_initial_payment_taken );
				$is_first = false;
			}
		}

	}

	/**
	 * Processes recurring payments.
	 *
     * @param WPInv_Subscription $subscription Subscription.
	 * @param bool $is_first Whether or not this is the first subscription in the subscription group.
	 * @param bool $is_last Whether or not this is the last subscription in the subscription group.
	 * @param bool $is_initial_payment_taken Is initial payment taken.
	 * @internal
	 */
	public function _process_single_subscription( $subscription, $is_first, $is_last, $is_initial_payment_taken = false ) {

		// Fetch the recurring items.
		$subscription_group  = getpaid_get_invoice_subscription_group( $subscription->get_parent_payment_id(), $subscription->get_id() );
		$recurring_items     = empty( $subscription_group ) ? array( $subscription->get_product_id() ) : array_keys( $subscription_group['items'] );
		$invoice             = $subscription->get_parent_payment();
		$stripe_subscription = new GetPaid_Stripe_Subscription( $this, $subscription );

		// Process the recurring items.
		foreach ( $recurring_items as $item ) {

			$item = $invoice->get_item( $item );
			if ( empty( $item ) ) {
				continue;
			}

			$product           = new GetPaid_Stripe_Product( $this, $subscription->get_parent_invoice() );
			$product->item     = $item;

			if ( ! $product->exists() ) {
				$_product = $product->create();

				if ( is_wp_error( $_product ) ) {
					wpinv_set_error( $_product->get_error_code(), $_product->get_error_message() );
					wpinv_send_back_to_checkout( $subscription->get_parent_invoice() );
				}

				update_post_meta( $item->get_id(), $product->get_item_profile_meta_name(), $_product->id );
			}

			$stripe_subscription->items[] = $item;
		}

		$stripe_subscription->start( $is_last, $is_first, $is_initial_payment_taken );

	}

	/**
	 * Processes invoice addons.
	 *
	 * @param WPInv_Invoice $invoice
	 * @param GetPaid_Form_Item[] $items
	 * @return WPInv_Invoice
	 */
	public function process_addons( $invoice, $items ) {

        $amount = 0;
        foreach ( $items as $item ) {

            if ( is_null( $invoice->get_item( $item->get_id() ) ) && ! is_wp_error( $invoice->add_item( $item ) ) ) {
                $amount += $item->get_sub_total();
            }
		}

		$payment_intent  = new GetPaid_Stripe_Payment_Intent( $this, $invoice );
		$_payment_intent = $payment_intent->call( 'create', array( $payment_intent->get_args( $amount ) ) );
		$result          = $payment_intent->process( $_payment_intent );

        // There was an error processing the payment intent.
		if ( is_wp_error( $result ) ) {

			$message = __( 'We are sorry. There was an error processing your payment.', 'wpinv-stripe' );
			$error   = $result->get_error_message();
			wpinv_set_error( $result->get_error_code(), wp_kses_post( "$message $error" ) );
			return;

		}

        $invoice->recalculate_total();
        $invoice->save();
	}

	/**
	 * Returns the stripe client.
	 *
	 * @param WPInv_Invoice|null $invoice
	 * @return \Stripe\StripeClient
	 * @throws Exception
	 */
	public function get_stripe( $invoice = null ) {

		//Set app info for all requests @link https://stripe.com/docs/building-plugins#setappinfo
		\Stripe\Stripe::setAppInfo(
			'WordPress Invoicing',
			WPINV_VERSION,
			esc_url( 'https://wpinvoicing.com/' ),
			'pp_partner_FounODljaTLlZL'
		);

		$secret_key = $this->get_secret_key( $invoice );

		if ( empty( $secret_key ) ) {
			if ( ! empty( $invoice ) && is_a( $invoice, 'WPInv_Invoice' ) ) {
				$mode = $invoice->get_mode();
			} else {
				$mode = wpinv_is_test_mode( $this->id ) ? 'test' : 'live';
			}

			wpinv_error_log( $mode, __( 'You have not set-up your stripe secret key.', 'wpinv-stripe' ) );
			throw new Exception( __( 'You have not set-up your stripe secret key.', 'wpinv-stripe' ) );
		}

		return new \Stripe\StripeClient(
			array(
				'api_key'        => $secret_key,
				'stripe_version' => WPINV_STRIPE_API_VERSION,
			)
		);

	}

	/**
	 * Retrieves the appropriate secret key to use.
	 *
	 * @param string
	 */
	public function get_secret_key( $invoice = null ) {

		if ( $this->is_sandbox( $invoice ) ) {
			return wpinv_get_option( 'stripe_test_secret_key' );
		}

		return wpinv_get_option( 'stripe_live_secret_key' );
	}

	/**
	 * Retrieves the appropriate account id to use.
	 *
	 * @param string
	 */
	public function get_account_id( $invoice = null ) {

		if ( $this->is_sandbox( $invoice ) ) {
			return wpinv_get_option( 'stripe_test_connect_account_id' );
		}

		return wpinv_get_option( 'stripe_live_connect_account_id' );
	}

	/**
	 * Filters the gateway settings.
	 *
	 * @param array $admin_settings
	 */
	public function admin_settings( $admin_settings ) {

		$admin_settings['stripe_active']['desc']    .= __( '( See: <a href="https://stripe.com/docs/currencies" target="_blank">Supported Currencies</a> )', 'wpinv-stripe' );
		return array_merge( $admin_settings, GetPaid_Stripe_Admin::get_settings() );

	}

	/**
	 * Displays a notice on the checkout page if sandbox is enabled.
	 */
	public function sandbox_notice() {

		return sprintf(
			/* translators: %s: Opening link, %s: closing link */
			__( 'SANDBOX ENABLED. You can use sandbox testing details only. See the %1$sStripe Sandbox Testing Guide%2$s for more details.', 'wpinv-stripe' ),
			'<a href="https://stripe.com/docs/testing">',
			'</a>'
		);

	}

	/**
	 * Displays the update payment method button.
	 *
	 * @param WPInv_Subscription $subscription
	 */
	public function show_update_payment_method_button( $subscription ) {

		$disabled = wpinv_get_option( 'stripe_disable_update_card', 0 );

		if ( empty( $disabled ) && $subscription->is_active() && $subscription->get_gateway() === $this->id ) {
			$setup_intent = new GetPaid_Stripe_Setup_Intent( $this, $subscription );
			$error        = '';
			$setup_secret = '';

			if ( $setup_intent->get_secret_key() ) {
				$setup_secret = $setup_intent->get_secret_key();
			} else {
				$_setup_intent = $setup_intent->create();

				if ( is_wp_error( $_setup_intent ) ) {
					$error = $_setup_intent->get_error_message();
				} else {
					$setup_secret = $_setup_intent->client_secret;
					$setup_intent->cache_keys( $_setup_intent->id, $_setup_intent->client_secret );
				}
			}

			printf(
				'<a href="#" class="btn btn-info btn-sm getpaid-stripe-update-payment-method-button" data-redirect="%s" data-subscription="%d" data-error="%s" data-intent="%s">%s</a>',
				esc_url( $subscription->get_view_url() ),
				absint( $subscription->get_id() ),
				esc_attr( $error ),
				esc_attr( $setup_secret ),
				esc_html__( 'Update Payment Card', 'wpinv-stripe' )
			);

			add_action( 'wp_footer', array( $this, 'show_update_payment_method_modal' ) );
		}

	}

	/**
	 * Displays the update payment method modal.
	 *
	 */
	public function show_update_payment_method_modal() {
		$card = $this->get_cc_form( 1 != wpinv_get_option( 'stripe_disable_save_card' ) );
		include plugin_dir_path( __FILE__ ) . 'update-card-modal.php';
	}

	/**
	 * Get a link to the transaction on the 3rd party gateway site (if applicable).
	 *
	 * @param string $transaction_url transaction url.
	 * @param WPInv_Invoice $invoice Invoice object.
	 * @return string transaction URL, or empty string.
	 */
	public function filter_transaction_url( $transaction_url, $invoice ) {

		$transaction_id  = $invoice->get_transaction_id();

		if ( ! ( strpos( $transaction_id, 'ch_' ) === 0 || strpos( $transaction_id, 'in_' ) === 0 ) ) {
			return $transaction_url;
		}

		if ( $transaction_id && strpos( $transaction_id, 'in_' ) === 0 ) {
			$transaction_link = $this->is_sandbox( $invoice ) ? 'test/invoices' : 'invoices';
		} else {
			$transaction_link = $this->is_sandbox( $invoice ) ? 'test/payments' : 'payments';
		}

		$transaction_link .= '/' . $transaction_id;

		return 'https://dashboard.stripe.com/' . $transaction_link;
	}

	/**
	 * Get a link to the subscription on the 3rd party gateway site (if applicable).
	 *
	 * @param string $subscription_url transaction url.
	 * @param WPInv_Subscription $subscription Subscription objectt.
	 * @return string subscription URL, or empty string.
	 */
	public function generate_subscription_url( $subscription_url, $subscription ) {

		$profile_id      = $subscription->get_profile_id();

		if ( $this->id == $subscription->get_gateway() && ! empty( $profile_id ) ) {

			$subscription_url = sprintf( 'https://dashboard.stripe.com/{sandbox}subscriptions/%s', $profile_id );
			$replace          = $this->is_sandbox( $subscription->get_parent_invoice() ) ? 'test/' : '';
			$subscription_url = str_replace( '{sandbox}', $replace, $subscription_url );

		}

		return $subscription_url;
	}

	/**
	 * Cancels a subscription remotely.
	 *
	 * @param WPInv_Subscription $subscription Subscription object.
	 */
	public function subscription_cancelled( $subscription ) {

		if ( $subscription->get_gateway() != $this->id ) {
			return;
		}

		$stripe_subscription = new GetPaid_Stripe_Subscription( $this, $subscription );
		$result              = $stripe_subscription->cancel();

		if ( is_wp_error( $result ) ) {

			$error = sprintf(
				// translators: %s is the subscription ID.
				__( 'An error occured while trying to cancel subscription #%s in Stripe.', 'wpinv-stripe' ),
				$subscription->get_id()
			);

			getpaid_admin()->show_error( $error . ' ' . $result->get_error_message() );

			if ( ! is_admin() ) {
				wpinv_set_error( $result->get_error_code(), $error );
			}

			return;
		}

		if ( is_admin() ) {
			getpaid_admin()->show_success(
				sprintf(
					// translators: %s is the subscription ID.
					__( 'Successfully cancelled subscription #%s in Stripe.', 'wpinv-stripe' ),
					$subscription->get_id()
				)
			);
		}

	}

	/**
	 * Refunds an invoice remotely.
	 *
	 * @param WPInv_Invoice $invoice Invoice object.
	 */
	public function refund_invoice( $invoice ) {

		if ( $invoice->get_gateway() !== $this->id ) {
			return;
		}

		$refund          = new GetPaid_Stripe_Refund( $this, $invoice->get_transaction_id() );
		$refund->invoice = $invoice;

		$result = $refund->create();

		if ( is_wp_error( $result ) ) {
			$invoice->add_system_note(
				sprintf(
					// translators: %s is the error message.
					__( 'An error occured while trying to refund invoice #%1$s in Stripe: %2$s', 'wpinv-stripe' ),
					$invoice->get_id(),
					$result->get_error_message()
				)
			);
		} else {
			$invoice->add_system_note(
				sprintf(
					// translators: %s is the refund ID.
					__( 'Successfully refunded invoice #%1$s in Stripe. Refund ID: %2$s', 'wpinv-stripe' ),
					$invoice->get_id(),
					$result->id
				)
			);
		}
	}

	/**
	 * Processes ipns.
	 *
	 * @return void
	 */
	public function verify_ipn() {

		// Init our IPN handler.
		$ipn = new GetPaid_Stripe_IPN_Handler( $this );
		$ipn->process();

	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices() {

	    if ( ! is_ssl() && ! $this->is_sandbox() && wpinv_current_user_can_manage_invoicing() ) {
		    echo '<div class="error"><p>' . esc_html__( 'Stripe requires HTTPS connection for live transactions.', 'wpinv-stripe' ) . '</p></div>';
		}

	}

	/**
	 * Generates Stripe Webhooks if not yet generated.
	 *
	 * @return void
	 */
	public function maybe_store_webhooks() {

		$key          = $this->is_sandbox() ? 'getpaid_stripe_sandbox_webhook_id' : 'getpaid_stripe_webhook_id';
		$value        = get_option( $key );
		$is_connected = $this->get_account_id();
		$has_api_keys = $this->get_secret_key();

		if ( empty( $is_connected ) && ! empty( $has_api_keys ) && ! $this->is_localhost() && empty( $value ) ) {

			$webhook = new GetPaid_Stripe_Webhook( $this );
			$value   = $webhook->is_saved();

			// In case there was an error, e.g no keys.
			if ( is_wp_error( $value ) ) {
				return;
			}

			// In case the webhook exists.
			if ( is_string( $value ) ) {
				update_option( $key, $value );
				return;
			}

			$value = $webhook->save();

			if ( is_string( $value ) ) {
				update_option( $key, $value );
			}
		}

	}

	/**
	 * Checks if we're on localhost.
	 *
	 * @return void
	 */
	public function is_localhost() {

		// set the array for testing the local environment
		$whitelist = array( '127.0.0.1', '::1' );

		// check if the server is in the array
		if ( in_array( $_SERVER['REMOTE_ADDR'], $whitelist ) ) {

			// this is a local environment
			return true;

		}

		return false;
	}

	/**
	 * Retrieves the Stripe connect URL when using the setup wizzard.
	 *
	 *
     * @param array $data
     * @return string
	 */
	public static function maybe_get_connect_url( $url = '', $data = array() ) {
		return GetPaid_Stripe_Admin::get_connect_url( false, urldecode( $data['redirect'] ) );
	}

	/**
	 * Connects to Stripe.
	 *
	 * @param array $data Connection data.
	 * @return void
	 */
	public function connect_stripe( $data ) {

		$sandbox = $this->is_sandbox();
		$data    = wp_unslash( $data );

		if ( isset( $data['live_mode'] ) ) {
			$sandbox = empty( $data['live_mode'] );
		}

		wpinv_update_option( 'stripe_sandbox', (int) $sandbox );
		wpinv_update_option( 'stripe_active', 1 );
		wpinv_update_option( 'disable_stripe_connect', 0 );

		if ( ! empty( $data['error_description'] ) ) {
			getpaid_admin()->show_error( wp_kses_post( urldecode( $data['error_description'] ) ) );
		} elseif ( $sandbox ) {
			wpinv_update_option( 'stripe_test_publishable_key', sanitize_text_field( urldecode( $data['stripe_publishable_key'] ) ) );
			wpinv_update_option( 'stripe_test_secret_key', sanitize_text_field( urldecode( $data['access_token'] ) ) );
			wpinv_update_option( 'stripe_test_refresh_token', sanitize_text_field( urldecode( $data['refresh_token'] ) ) );
			wpinv_update_option( 'stripe_test_connect_account_id', sanitize_text_field( urldecode( $data['stripe_user_id'] ) ) );
			getpaid_admin()->show_success( __( 'Successfully connected your test Stripe account', 'wpinv-stripe' ) );
		} else {
			wpinv_update_option( 'stripe_live_publishable_key', sanitize_text_field( urldecode( $data['stripe_publishable_key'] ) ) );
			wpinv_update_option( 'stripe_live_secret_key', sanitize_text_field( urldecode( $data['access_token'] ) ) );
			wpinv_update_option( 'stripe_live_refresh_token', sanitize_text_field( urldecode( $data['refresh_token'] ) ) );
			wpinv_update_option( 'stripe_live_connect_account_id', sanitize_text_field( urldecode( $data['stripe_user_id'] ) ) );
			getpaid_admin()->show_success( __( 'Successfully connected your live Stripe account', 'wpinv-stripe' ) );
		}

		$redirect = empty( $data['redirect'] ) ? admin_url( 'admin.php?page=wpinv-settings&tab=gateways&section=stripe' ) : urldecode( $data['redirect'] );
		if ( isset( $data['step'] ) ) {
			$redirect = add_query_arg( 'step', $data['step'], $redirect );
		}
		wp_redirect( $redirect );
		exit;
	}

	/**
	 * Disconnects from Stripe.
	 *
	 * @param array $data Connection data.
	 * @return void
	 */
	public function disconnect_stripe( $data ) {

		if ( ! empty( $data['live'] ) ) {
			$live_mode  = 1;
			$account_id = wpinv_get_option( 'stripe_live_connect_account_id' );
			wpinv_update_option( 'stripe_live_publishable_key', '' );
			wpinv_update_option( 'stripe_live_secret_key', '' );
			wpinv_update_option( 'stripe_live_refresh_token', '' );
			wpinv_update_option( 'stripe_live_connect_account_id', '' );
			getpaid_admin()->show_success( __( 'Successfully disconnected your live Stripe account', 'wpinv-stripe' ) );
		} else {
			$live_mode  = 0;
			$account_id = wpinv_get_option( 'stripe_test_connect_account_id' );
			wpinv_update_option( 'stripe_test_publishable_key', '' );
			wpinv_update_option( 'stripe_test_secret_key', '' );
			wpinv_update_option( 'stripe_test_refresh_token', '' );
			wpinv_update_option( 'stripe_test_connect_account_id', '' );
			getpaid_admin()->show_success( __( 'Successfully disconnected your test Stripe account', 'wpinv-stripe' ) );
		}

		wp_remote_post(
			add_query_arg( 'live_mode', $live_mode, 'https://ayecode.io/oauth/stripe?action=disconnect' ),
			array( 'body' => array( 'stripe_user_id' => $account_id ) )
		);

		wp_redirect( admin_url( 'admin.php?page=wpinv-settings&tab=gateways&section=stripe' ) );
		exit;
	}

	/**
	 * Registers the stripe email settings.
	 *
	 * @since    1.0.0
	 * @param array $settings Current email settings.
	 */
	public function register_email_settings( $settings ) {

		return array_merge(
			$settings,
			array(

				'stripe_payment_failed' => array(

					'email_stripe_payment_failed_header'  => array(
						'id'   => 'email_stripe_payment_failed_header',
						'name' => '<h3>' . __( 'Renewal Payment Failed (Stripe)', 'wpinv-stripe' ) . '</h3>',
						'desc' => __( 'These emails are sent to the customer when a renewal payment fails.', 'wpinv-stripe' ),
						'type' => 'header',
					),

					'email_stripe_payment_failed_active'  => array(
						'id'   => 'email_stripe_payment_failed_active',
						'name' => __( 'Enable/Disable', 'wpinv-stripe' ),
						'desc' => __( 'Enable this email notification', 'wpinv-stripe' ),
						'type' => 'checkbox',
						'std'  => 0,
					),

					'email_stripe_payment_failed_admin_bcc' => array(
						'id'   => 'email_stripe_payment_failed_admin_bcc',
						'name' => __( 'Enable Admin BCC', 'wpinv-stripe' ),
						'desc' => __( 'Check if you want to send a copy of this notification email to to the site admin.', 'wpinv-stripe' ),
						'type' => 'checkbox',
						'std'  => 0,
					),

					'email_stripe_payment_failed_subject' => array(
						'id'       => 'email_stripe_payment_failed_subject',
						'name'     => __( 'Subject', 'wpinv-stripe' ),
						'desc'     => __( 'Enter the subject line for this email.', 'wpinv-stripe' ),
						'help-tip' => true,
						'type'     => 'text',
						'std'      => __( '[{site_title}] Payment Failed', 'wpinv-stripe' ),
						'size'     => 'large',
					),

					'email_stripe_payment_failed_heading' => array(
						'id'       => 'email_stripe_payment_failed_heading',
						'name'     => __( 'Email Heading', 'wpinv-stripe' ),
						'desc'     => __( 'Enter the main heading contained within the email notification.', 'wpinv-stripe' ),
						'help-tip' => true,
						'type'     => 'text',
						'std'      => __( 'Payment Failed', 'wpinv-stripe' ),
						'size'     => 'large',
					),

					'email_stripe_payment_failed_body'    => array(
						'id'    => 'email_stripe_payment_failed_body',
						'name'  => __( 'Email Content', 'wpinv-stripe' ),
						'desc'  => '',
						'type'  => 'rich_editor',
						'std'   => __( '<p>Hi {name},</p><p>We are having trouble processing your payment. <a class="btn btn-success" href="{subscription_url}">Update your payment details</a></p>', 'wpinv-stripe' ),
						'class' => 'large',
						'size'  => '10',
					),

				),

			)
		);

	}

	/**
	 * Filters email triggers.
	 *
	 * @since 1.0.0
	 */
	public function filter_email_triggers( $triggers ) {
		$triggers['getpaid_stripe_subscription_past_due'] = 'stripe_payment_failed';
		return $triggers;
	}

	/**
	 * Inits quote email type hooks.
	 *
	 * @since 1.0.0
	 */
	public function init_email_type_hook( $email_type, $hook ) {

		if ( in_array( $email_type, array( 'stripe_payment_failed' ), true ) ) {

			$email_type = "send_{$email_type}_email";
			add_action( $hook, array( $this, $email_type ), 100, 2 );

		}

	}

	/**
	 * Sends the stripe payment failed email.
	 *
	 * @param WPInv_Subscription $local_subscription
	 * @param Stripe\Subscription $stripe_subscription
	 * @since 1.0.0
	 */
	public function send_stripe_payment_failed_email( $local_subscription, $stripe_subscription ) {
		$email     = new GetPaid_Notification_Email( 'stripe_payment_failed', $local_subscription );
		$sender    = getpaid()->get( 'subscription_emails' );
		return $sender->send_email( $local_subscription, $email, 'stripe_payment_failed' );
	}

	/**
	 * Filters the default template paths.
	 *
	 * @since 1.0.0
	 */
	public function maybe_filter_default_template_path( $default_path, $template_name ) {

		$our_emails = array(
			'emails/wpinv-email-stripe_payment_failed.php',
		);

		if ( in_array( $template_name, $our_emails, true ) ) {
			return WPINV_STRIPE_DIR . 'templates';
		}

		return $default_path;
	}

	/**
	 * Registers an expired subscriptions tool.
	 *
	 * @since 1.0.0
	 */
	public function register_expired_subscriptions_tool() {

		add_action( 'admin_footer', array( $this, 'webhook_modal' ) );
		?>

			<tr>
                <td><?php esc_html_e( 'Check Expired Subscriptions (Stripe)', 'wpinv-stripe' ); ?></td>
                <td>
                    <small><?php esc_html_e( 'Checks if expired subscriptions are actually expired in Stripe.', 'wpinv-stripe' ); ?></small>
                </td>
                <td>
					<a href="
                    <?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg( 'getpaid-admin-action', 'stripe_check_expired_subscriptions' ),
								'getpaid-nonce',
								'getpaid-nonce'
							)
						);
					?>
                    " class="button button-primary"><?php esc_html_e( 'Run', 'wpinv-stripe' ); ?></a>
                </td>
			</tr>

			<tr>
                <td><?php esc_html_e( 'Process past event (Stripe)', 'wpinv-stripe' ); ?></td>
                <td>
                    <small><?php esc_html_e( 'Manually process any Stripe event that occurred in the last 30 days.', 'wpinv-stripe' ); ?></small>
                </td>
                <td class="bsui">
					<button type="button" class="button button-primary" data-toggle="modal" data-bs-toggle="modal" data-bs-target="#getpaid-stripe-run-event-modal" data-target="#getpaid-stripe-run-event-modal">
						<?php esc_html_e( 'Run', 'wpinv-stripe' ); ?>
					</button>
                </td>
			</tr>
		<?php
	}

	/**
	 * Registers an expired subscriptions tool.
	 *
	 * @since 1.0.0
	 */
	public function webhook_modal() {

		?>

			<div class="bsui">
					<div class="modal fade" id="getpaid-stripe-run-event-modal" tabindex="-1" role="dialog" aria-labelledby="getpaid-stripe-run-event-modal-label" aria-hidden="true">
						<div class="modal-dialog modal-dialog-centered" role="document">
							<div class="modal-content">
								<form method="GET">
									<div class="modal-header">
										<h5 class="modal-title" id="getpaid-stripe-run-event-modal-label"><?php esc_html_e( 'Process past event (Stripe)', 'wpinv-stripe' ); ?></h5>
										<button type="button" class="close btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="<?php esc_html_e( 'Close', 'wpinv-stripe' ); ?>">
											<?php if ( empty( $GLOBALS['aui_bs5'] ) ) : ?>
												<span aria-hidden="true">×</span>
											<?php endif; ?>
										</button>
									</div>
									<div class="modal-body">
										<?php
											getpaid_hidden_field( 'getpaid-admin-action', 'stripe_manually_process_webhook_event' );

											wp_nonce_field( 'getpaid-nonce', 'getpaid-nonce' );

											aui()->input(
												array(
													'type'               => 'text',
													'name'               => 'getpaid_event_id',
													'id'                 => 'getpaid_stripe_webhook_event_id',
													'label'              => __( 'Event ID', 'wpinv-stripe' ),
													'placeholder'        => __( 'For example, evt_3OPLyuIbYxW2G9BD07LRNDds', 'wpinv-stripe' ),
													'label_type'         => 'top',
													'help_text'          => __( 'Open your Stripe dashboard and then click on Developers > Events. Click on the event you want to process and copy the ID from the URL. You can also press Control + i to copy the event id.', 'wpinv-stripe' ),
													'validation_pattern' => '^evt_[a-zA-Z0-9_]+$',
													'validation_text'    => __( 'The event ID must start with evt_.', 'wpinv-stripe' ),
													'required'           => true,
												),
												true
											);
										?>
									</div>
									<script>
										jQuery( document ).ready( function( $ ) {
											// Add was-validated class to the form when the event id changes, unless the class is already there.
											$( '#getpaid_stripe_webhook_event_id' ).on( 'change', function() {
												if ( ! $( this ).closest( 'form' ).hasClass( 'was-validated' ) ) {
													$( this ).closest( 'form' ).addClass( 'was-validated' );
												}
											} );
										});
									</script>
									<div class="modal-footer">
										<button type="button" class="btn btn-secondary getpaid-cancel" data-bs-dismiss="modal" data-dismiss="modal"><?php esc_html_e( 'Cancel', 'wpinv-stripe' ); ?></button>
										<input type="submit" class="btn btn-primary" value="<?php esc_attr_e( 'Process', 'wpinv-stripe' ); ?>">
									</div>
								</form>
							</div>
						</div>
                	</div>
			</div>
		<?php
	}

	/**
     * Checks for expired subscriptions in Stripe.
	 *
     */
    public function admin_check_expired_subscriptions() {

		// Check the subscriptions.
		/** @var WPInv_Subscription[] */
		$subscriptions = getpaid_get_subscriptions(
			array(
				'status' => array( 'failing', 'expired' ),
				'number' => -1,
			)
		);

		foreach ( $subscriptions as $subscription ) {

			if ( $this->id == $subscription->get_gateway() ) {

				$remote   = new GetPaid_Stripe_Subscription( $this, $subscription );
				$resource = $remote->get();

				if ( is_wp_error( $resource ) ) {
					continue;
				}

				if ( 'trialing' === $resource->status || 'active' === $resource->status ) {
					$subscription->set_next_renewal_date( gmdate( 'Y-m-d H:i:s', $resource->current_period_end ) );
					$subscription->activate();
				}
			}
		}

		// Show an admin message.
		getpaid_admin()->show_success( __( 'Your subscriptions have been checked.', 'wpinv-stripe' ) );

		// Redirect the admin.
		wp_safe_redirect( remove_query_arg( array( 'getpaid-admin-action', 'getpaid-nonce' ) ) );
		exit;

	}

	/**
     * Manually processes a webhook event.
	 *
     */
    public function admin_manually_process_webhook_event( $args ) {

		// Init our IPN handler.
		$ipn = new GetPaid_Stripe_IPN_Handler( $this );
		$ipn->process_manually( $args['getpaid_event_id'] );
	}

	/**
	 * Redirect users to settings on activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_settings() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$redirected = get_option( 'wpinv_stripe_redirected_to_settings' );

		if ( ! empty( $redirected ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Bail if activating from network, or bulk
		if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

	    update_option( 'wpinv_stripe_redirected_to_settings', 1 );

		if ( empty( $_GET['page'] ) || 'gp-setup' !== $_GET['page'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpinv-settings&tab=gateways&section=stripe' ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Filters a submission's js data.
	 *
	 * @param array $data
	 * @param GetPaid_Payment_Form_Submission $submission
	 * @return array
	 */
	public function filter_submission_js_data( $data, $submission ) {

		if ( $this->redirect_to_stripe() ) {
			return $data;
		}

		try {
			// Create / Update a payment intent.
			$payment_intent          = new GetPaid_Stripe_Elements_Payment_Intent( $this, $submission );
			$payment_intent->invoice = $submission->get_invoice();

			/** @var \Stripe\PaymentIntent|WP_error $remote_intent */
			$remote_intent = $payment_intent->update();

			// Save intent id to prevent generating unwanted intents.
			if ( ! is_wp_error( $remote_intent ) && ! empty( $remote_intent->id ) && ! empty( $payment_intent->invoice ) && $payment_intent->invoice->exists() && ! get_post_meta( (int) $payment_intent->invoice->get_id(), '_gp_stripe_intent_id', true ) ) {
				update_post_meta( (int) $payment_intent->invoice->get_id(), '_gp_stripe_intent_id', $remote_intent->id );
			}
		} catch ( Exception $e ) {
			$remote_intent = new WP_Error( 'stripe_error', $e->getMessage() );
		}

		return array_merge(
			$data,
			array(
				'stripe_payment_intent'        => is_wp_error( $remote_intent ) ? '' : $remote_intent->id,
				'stripe_payment_intent_secret' => is_wp_error( $remote_intent ) ? '' : $remote_intent->client_secret,
				'stripe_error'                 => is_wp_error( $remote_intent ) ? $remote_intent->get_error_message() : '',
			)
		);

	}

}
