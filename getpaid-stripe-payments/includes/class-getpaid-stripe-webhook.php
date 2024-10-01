<?php
/**
 * Handles the Stripe Webhooks API.
 *
 * You can configure webhook endpoints via the API to be notified about events that happen in your Stripe account or connected accounts.
 * @link https://stripe.com/docs/api/webhook_endpoints
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a stripe webhook.
 *
 */
class GetPaid_Stripe_Webhook extends GetPaid_Stripe_Resource {

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'webhookEndpoints';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'webhookEndpoint';

	/**
	 * Checks if the customer has set up our webhook.
	 *
	 *
	 * @return false|string|WP_Error true, webhook id or WP_Error.
	 */
	public function is_saved() {

		$all_webhooks = $this->call( 'all', array( array( 'limit' => 100 ) ) );

		if ( is_wp_error( $all_webhooks ) ) {
			wpinv_error_log( $all_webhooks->get_error_message(), false );
			return $all_webhooks;
		}

		foreach ( $all_webhooks->data as $webhook ) {

			if ( wpinv_get_ipn_url( 'stripe' ) === $webhook->url ) {
				return $webhook->id;
			}
		}

		return false;

	}

	/**
	 * Saves our webhook endpoint.
	 *
	 *
	 * @return string|WP_Error webhook id or WP_Error.
	 */
	public function save() {

		// Endpoint args.
		$args = array(
			'url'            => wpinv_get_ipn_url( 'stripe' ),
			'enabled_events' => array(
				'invoice.payment_failed',
				'invoice.payment_succeeded',
				'customer.subscription.created',
				'customer.subscription.deleted',
				'customer.subscription.updated',
				'customer.subscription.trial_will_end',
				'charge.refunded',
				'setup_intent.canceled',
				'setup_intent.created',
				'setup_intent.requires_action',
				'setup_intent.setup_failed',
				'setup_intent.succeeded',
				'payment_intent.succeeded',
				'checkout.session.completed'
			),
		);

		// Create the webhook.
		$webhook = $this->call( 'create', array( $args ) );

		if ( ! is_wp_error( $webhook ) ) {
			return $webhook->id;
		}

		wpinv_error_log( $webhook->get_error_message(), false );
		return $webhook;
	}

}
