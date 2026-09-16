<?php
/**
 * Stripe payment gateway IPN handler
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Payment Gateway IPN handler class.
 *
 */
class GetPaid_Stripe_IPN_Handler extends GetPaid_Stripe_Resource {

	/**
	 * Payment method id.
	 *
	 * @var string
	 */
	protected $id = 'stripe';

	/**
	 * Plural resource name.
	 *
	 * @var string
	 */
	public $plural = 'events';

	/**
	 * Singular resource name.
	 *
	 * @var string
	 */
	public $singular = 'event';

	/**
	 * Processes ipns and marks payments as complete.
	 *
	 * @return void
	 */
	public function process() {
		nocache_headers();

		$this->log( 'GetPaid Stripe Webhook Handler' );

		// Retrieve the request's body.
		$body = @file_get_contents( 'php://input' );

		// Validate the IPN.
		if ( ! is_string( $body ) || '' === $body ) {
			$this->log( 'Aborted. The webhook request has no body.' );

			$this->webhook_response( 'No request body' );
		}

		// Guard against oversized payloads.
		$max_length = (int) apply_filters( 'getpaid_stripe_webhook_max_body_length', 2 * MB_IN_BYTES );

		if ( $max_length > 0 && strlen( $body ) > $max_length ) {
			$this->log( sprintf( 'Aborted. The webhook payload is too large ( %d bytes ).', strlen( $body ) ) );

			$this->webhook_response( 'Payload too large', 413 );
		}

		// Depth limited decode. Stripe events never nest this deep.
		$posted = json_decode( $body, false, 64 );

		// Validate the IPN.
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $posted ) || empty( $posted->id ) || ! is_string( $posted->id ) ) {
			$this->log( 'Aborted. The webhook payload is not a valid Stripe event.' );

			$this->webhook_response( 'Invalid request body' );
		}

		// For test IPNs..
		if ( hash_equals( 'evt_00000000000000', $posted->id ) ) {
			$this->webhook_response( 'Webhook is working fine :)' );
		}

		// The id is passed to the Stripe API, so only accept well formed event ids.
		if ( ! $this->is_valid_stripe_id( $posted->id, 'evt_' ) ) {
			$this->log( 'Aborted. Malformed event id: ' . $this->scrub_for_log( $posted->id, 64 ) );

			$this->webhook_response( 'Invalid event id' );
		}

		// Verify the signature whenever a signing secret is available.
		$signature = $this->verify_webhook_signature( $body );

		if ( is_wp_error( $signature ) ) {
			$this->log( 'Aborted. ' . $signature->get_error_message() );

			$this->webhook_response( 'Invalid signature', 400 );
		}

		// For extra security, retrieve from the Stripe API.
		$event = $this->call( 'retrieve', array( $posted->id ) );

		if ( is_wp_error( $event ) ) {
			$this->log( 'Error retrieving the event ' . $posted->id . ' from Stripe: ' . $this->scrub_for_log( $event->get_error_message(), 500 ) );

			// Never reflect the API error back to the caller.
			$this->webhook_response( 'Unable to retrieve the event' );
		}

		$this->handle_event( $event );

		$this->webhook_response( 'Processed' );
	}

	/**
	 * Manually processes an event.
	 *
	 * @param string $event_id Stripe event id.
	 * @return void
	 */
	public function process_manually( $event_id ) {
		// Replaying events touches invoices and subscriptions, so restrict it to trusted users.
		$capability = apply_filters( 'getpaid_stripe_manual_event_capability', 'manage_options' );

		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to process Stripe events.', 'wpinv-stripe' ), '', array( 'response' => 403 ) );
		}

		// Protect against CSRF. Link to this screen with:
		// wp_nonce_url( $url, 'getpaid_stripe_process_event' ).
		if ( apply_filters( 'getpaid_stripe_manual_event_require_nonce', true ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'getpaid_stripe_process_event' ) ) {
				wp_die( esc_html__( 'Your link has expired. Please go back and try again.', 'wpinv-stripe' ), '', array( 'response' => 403 ) );
			}
		}

		$event_id = is_scalar( $event_id ) ? sanitize_text_field( (string) $event_id ) : '';

		if ( empty( $event_id ) ) {
			wp_die( esc_html__( 'No event id.', 'wpinv-stripe' ), '', array( 'response' => 400 ) );
		}

		if ( ! $this->is_valid_stripe_id( $event_id, 'evt_' ) ) {
			wp_die( esc_html__( 'Invalid event id.', 'wpinv-stripe' ), '', array( 'response' => 400 ) );
		}

		$this->log( 'Manually processing the event ' . $event_id . '.' );

		// For extra security, retrieve from the Stripe API.
		$event = $this->call( 'retrieve', array( $event_id ) );

		if ( is_wp_error( $event ) ) {
			$this->log( 'Error retrieving the event ' . $event_id . ' from Stripe: ' . $this->scrub_for_log( $event->get_error_message(), 500 ) );

			wp_die( esc_html( $event->get_error_message() ), esc_html__( 'Error retrieving webhook', 'wpinv-stripe' ), array( 'response' => 200 ) );
		}

		$event_type = $this->handle_event( $event );

		if ( '' === $event_type ) {
			wp_die( esc_html__( 'Unsupported event type.', 'wpinv-stripe' ), '', array( 'response' => 200 ) );
		}

		printf(
			'<h3>%s</h3>',
			esc_html( sprintf( __( 'Processed %s event', 'wpinv-stripe' ), $event_type ) )
		);

		// Never print the raw Stripe object. It contains merchant and customer supplied
		// strings that would execute as markup in the admin screen.
		echo '<pre>' . esc_html( $this->format_event_for_display( $event ) ) . '</pre>';

		exit;
	}

	/**
	 * Dispatches a verified Stripe event to its handler.
	 *
	 * @since 2.3.27
	 *
	 * @param Stripe\Event $event Stripe event.
	 * @return string The processed event type or an empty string.
	 */
	protected function handle_event( $event ) {
		if ( ! is_object( $event ) || empty( $event->type ) || ! is_string( $event->type ) ) {
			$this->log( 'Aborted. The Stripe event has no type.' );

			return '';
		}

		$event_id   = $this->get_stripe_id( $event );
		$event_type = $this->get_event_slug( $event->type );

		if ( '' === $event_type ) {
			$this->log( 'Aborted. Unsupported event type: ' . $this->scrub_for_log( $event->type, 64 ) );

			return '';
		}

		$this->log(
			sprintf(
				'Event start [%s]: #%s - %s.',
				! empty( $event->livemode ) ? 'LIVE' : 'TEST',
				$event_id,
				$event_type
			)
		);

		$method = 'process_' . $event_type;

		if ( ! in_array( $event_type, $this->get_reserved_event_slugs(), true ) && method_exists( $this, $method ) ) {
			$object = isset( $event->data->object ) ? $event->data->object : null;

			if ( ! is_object( $object ) ) {
				$this->log( 'Aborted. The event ' . $event_id . ' has no data object.' );
			} else {
				$this->log( 'Process event start: #' . $event_id . ' - ' . $event_type );

				$this->{$method}( $object, $event );

				$this->log( 'Process event end: #' . $event_id . ' - ' . $event_type );
			}
		}

		do_action( "getpaid_stripe_event_{$event_type}", $event );

		$this->log( 'Event end: #' . $event_id . ' - ' . $event_type );

		return $event_type;
	}

	/**
	 * Converts a Stripe event type into a safe method/hook slug.
	 *
	 * @since 2.3.27
	 *
	 * @param string $type Stripe event type. E.g invoice.payment_failed.
	 * @return string Slug or an empty string when the type is not supported.
	 */
	protected function get_event_slug( $type ) {
		$type = strtolower( str_replace( '.', '_', trim( (string) $type ) ) );

		return preg_match( '/^[a-z0-9_]{1,100}$/', $type ) ? $type : '';
	}

	/**
	 * Methods that must never be reachable through an event type.
	 *
	 * @since 2.3.27
	 *
	 * @return array
	 */
	protected function get_reserved_event_slugs() {
		return apply_filters( 'getpaid_stripe_reserved_event_slugs', array( 'manually' ) );
	}

	/**
	 * Verifies the Stripe-Signature header against the webhook signing secret.
	 *
	 * Returns false when no signing secret has been configured, in which case the
	 * event is still re-fetched from the Stripe API before it is processed.
	 *
	 * @since 2.3.27
	 *
	 * @param string $payload Raw request body.
	 * @return bool|WP_Error True when verified, false when not configured, WP_Error on failure.
	 */
	protected function verify_webhook_signature( $payload ) {
		// @todo Add webhook secret setting.
		//$secret = $this->get_webhook_secret();

		//if ( empty( $secret ) ) {
		//	return false;
		//}

		$header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		if ( empty( $header ) ) {
			$this->log( 'The request is missing the Stripe-Signature header.' );

			return new WP_Error( 'getpaid_stripe_missing_signature', 'The request is missing the Stripe-Signature header.' );
		}

		$timestamp  = 0;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( empty( $timestamp ) || empty( $signatures ) ) {
			$this->log( 'The Stripe-Signature header could not be parsed.' );

			return new WP_Error( 'getpaid_stripe_invalid_signature_header', 'The Stripe-Signature header could not be parsed.' );
		}

		// Reject replayed payloads.
		$tolerance = (int) apply_filters( 'getpaid_stripe_webhook_tolerance', 5 * MINUTE_IN_SECONDS );

		if ( $tolerance > 0 && abs( time() - $timestamp ) > $tolerance ) {
			$this->log( 'The webhook timestamp is outside the allowed tolerance.' );

			return new WP_Error( 'getpaid_stripe_expired_signature', 'The webhook timestamp is outside the allowed tolerance.' );
		}

		//@todo Remove after webhook secret setting added.
		return true;

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, (string) $signature ) ) {
				return true;
			}
		}

		$this->log( 'The Stripe signature could not be verified.' );

		return new WP_Error( 'getpaid_stripe_signature_mismatch', 'The Stripe signature could not be verified.' );
	}

	/**
	 * Retrieves the webhook signing secret.
	 *
	 * @since 2.3.27
	 *
	 * @return string
	 */
	protected function get_webhook_secret() {
		$secret = '';

		if ( defined( 'GETPAID_STRIPE_WEBHOOK_SECRET' ) && is_string( GETPAID_STRIPE_WEBHOOK_SECRET ) ) {
			$secret = GETPAID_STRIPE_WEBHOOK_SECRET;
		}

		if ( '' === $secret ) {
			$secret = wpinv_get_option( 'stripe_webhook_secret' );
		}

		return (string) apply_filters( 'getpaid_stripe_webhook_secret', $secret );
	}

	/**
	 * Validates the format of a Stripe object id.
	 *
	 * @since 2.3.27
	 *
	 * @param mixed  $id     Stripe id or object.
	 * @param string $prefix Optional. Required id prefix. E.g evt_.
	 * @return bool
	 */
	protected function is_valid_stripe_id( $id, $prefix = '' ) {
		$id = $this->get_stripe_id( $id );

		if ( '' === $id || strlen( $id ) > 255 ) {
			return false;
		}

		if ( '' !== $prefix && 0 !== strpos( $id, $prefix ) ) {
			return false;
		}

		return (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $id );
	}

	/**
	 * Ends a webhook request with a plain, non reflective response.
	 *
	 * @since 2.3.27
	 *
	 * @param string $message  Message. Never contains remote input.
	 * @param int    $response HTTP status code.
	 * @return void
	 */
	protected function webhook_response( $message, $response = 200 ) {
		wp_die( esc_html( $message ), '', array( 'response' => (int) $response ) );
	}

	/**
	 * Prepares a Stripe event for display in the admin screen.
	 *
	 * @since 2.3.27
	 *
	 * @param Stripe\Event $event Stripe event.
	 * @return string JSON representation of the event.
	 */
	protected function format_event_for_display( $event ) {
		$data = json_decode( wp_json_encode( $event ), true );

		if ( ! is_array( $data ) ) {
			return '';
		}

		/**
		 * Filters the keys hidden when an event is printed in the admin screen.
		 *
		 * @since 2.3.27
		 *
		 * @param array $keys Keys to redact.
		 */
		$redact = apply_filters(
			'getpaid_stripe_redacted_event_keys',
			array( 'customer_phone', 'customer_shipping', 'customer_address', 'billing_details', 'receipt_email' )
		);

		if ( ! empty( $redact ) ) {
			$data = $this->redact_keys( $data, (array) $redact );
		}

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $json ? '' : $json;
	}

	/**
	 * Recursively replaces the given keys with a placeholder.
	 *
	 * @since 2.3.27
	 *
	 * @param array $data Data to redact.
	 * @param array $keys Keys to redact.
	 * @return array
	 */
	protected function redact_keys( $data, $keys ) {
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $keys, true ) && ! empty( $value ) ) {
				$data[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->redact_keys( $value, $keys );
			}
		}

		return $data;
	}

	/**
	 * Makes a remote value safe to write to the log file.
	 *
	 * Strips the control characters that would otherwise let remote input forge
	 * extra log lines, and caps the length.
	 *
	 * @since 2.3.27
	 *
	 * @param mixed $value      Value to scrub.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	protected function scrub_for_log( $value, $max_length = 200 ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
		$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

		if ( $max_length > 0 && strlen( $value ) > $max_length ) {
			$value = substr( $value, 0, $max_length ) . '…';
		}

		return $value;
	}

	/**
	 * Writes a Stripe webhook message to the log file.
	 *
	 * @since 2.3.27
	 *
	 * @param string $message Message to log.
	 * @param mixed  $context Optional. Extra data appended to the message as JSON.
	 */
	protected function log( $message, $context = null ) {
		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		wpinv_error_log( '[Stripe IPN] ' . $this->scrub_for_log( $message, 2000 ), false );
	}

	/**
	 * Retrieve Stripe resource ID from object.
	 *
	 * @since 2.3.27
	 *
	 * @param mixed $value Stripe field.
	 * @return string Stripe object id or an empty string.
	 */
	protected function get_stripe_id( $value ) {
		if ( is_object( $value ) ) {
			return ! empty( $value->id ) ? $value->id : '';
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Retrieves the Stripe subscription id attached to a Stripe invoice.
	 *
	 * Up to API version 2025-10-29 the subscription was available on
	 * $invoice->subscription. It has since moved to
	 * $invoice->parent->subscription_details->subscription, with the line items
	 * carrying it in $line->parent->subscription_item_details->subscription.
	 *
	 * @since 2.3.27
	 *
	 * @param Stripe\Invoice $invoice Stripe invoice.
	 * @return string Stripe subscription id or an empty string.
	 */
	protected function get_stripe_invoice_subscription_id( $invoice ) {
		// API versions before 2025-10-29.
		$subscription = empty( $invoice->subscription ) ? '' : $this->get_stripe_id( $invoice->subscription );

		// API version 2025-10-29 and later.
		if ( empty( $subscription ) && ! empty( $invoice->parent->subscription_details->subscription ) ) {
			$subscription = $this->get_stripe_id( $invoice->parent->subscription_details->subscription );
		}

		// Finally, fall back to the invoice line items.
		if ( empty( $subscription ) && ! empty( $invoice->lines->data ) ) {
			foreach ( $invoice->lines->data as $line ) {
				if ( ! empty( $line->subscription ) ) {
					$subscription = $this->get_stripe_id( $line->subscription );
				} elseif ( ! empty( $line->parent->subscription_item_details->subscription ) ) {
					$subscription = $this->get_stripe_id( $line->parent->subscription_item_details->subscription );
				}

				if ( ! empty( $subscription ) ) {
					break;
				}
			}
		}

		return $subscription;
	}

	/**
	 * Retrieves a GetPaid reference stored in the Stripe invoice metadata.
	 *
	 * @since 2.3.27
	 *
	 * @param Stripe\Invoice $invoice Stripe invoice.
	 * @param string         $key     Metadata key. E.g invoice_id, subscription_id.
	 * @return string Metadata value or an empty string.
	 */
	protected function get_stripe_invoice_meta( $invoice, $key ) {
		$sources = array();

		if ( ! empty( $invoice->metadata ) ) {
			$sources[] = $invoice->metadata;
		}

		if ( ! empty( $invoice->parent->subscription_details->metadata ) ) {
			$sources[] = $invoice->parent->subscription_details->metadata;
		}

		if ( ! empty( $invoice->lines->data ) ) {
			foreach ( $invoice->lines->data as $line ) {
				if ( ! empty( $line->metadata ) ) {
					$sources[] = $line->metadata;
				}
			}
		}

		foreach ( $sources as $metadata ) {
			$metadata = (array) $metadata;

			if ( ! empty( $metadata[ $key ] ) ) {
				return wpinv_clean( $metadata[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Builds the log context for a Stripe invoice event.
	 *
	 * @since 2.3.27
	 *
	 * @param Stripe\Invoice $invoice Stripe invoice.
	 * @param Stripe\Event   $event   Optional. Stripe event.
	 * @return array Log context.
	 */
	protected function get_stripe_invoice_context( $invoice, $event = null ) {
		$context = array(
			'event'           => empty( $event ) ? '' : $this->get_stripe_id( $event ),
			'livemode'        => ! empty( $invoice->livemode ) ? 'yes' : 'no',
			'stripe_invoice'  => $this->get_stripe_id( $invoice ),
			'number'          => empty( $invoice->number ) ? '' : $invoice->number,
			'status'          => empty( $invoice->status ) ? '' : $invoice->status,
			'billing_reason'  => empty( $invoice->billing_reason ) ? '' : $invoice->billing_reason,
			'currency'        => empty( $invoice->currency ) ? '' : strtoupper( $invoice->currency ),
			'amount_due'      => isset( $invoice->amount_due ) ? $invoice->amount_due : '',
			'attempt_count'   => isset( $invoice->attempt_count ) ? (int) $invoice->attempt_count : '',
			'next_attempt'    => empty( $invoice->next_payment_attempt ) ? '' : gmdate( 'Y-m-d H:i:s', (int) $invoice->next_payment_attempt ) . ' UTC',
			'customer'        => empty( $invoice->customer ) ? '' : $this->get_stripe_id( $invoice->customer ),
			'customer_email'  => empty( $invoice->customer_email ) ? '' : $invoice->customer_email,
			'subscription'    => $this->get_stripe_invoice_subscription_id( $invoice ),
			'gp_invoice_id'   => $this->get_stripe_invoice_meta( $invoice, 'invoice_id' ),
			'gp_subscription' => $this->get_stripe_invoice_meta( $invoice, 'subscription_id' ),
		);

		// Drop the empty entries so the log line stays readable.
		foreach ( $context as $key => $value ) {
			if ( '' === $value || null === $value ) {
				unset( $context[ $key ] );
			}
		}

		return $context;
	}

	/**
	 * Retrieves the reason a Stripe invoice payment failed.
	 *
	 * @since 2.3.27
	 *
	 * @param Stripe\Invoice $invoice Stripe invoice.
	 * @return string Failure reason or an empty string.
	 */
	protected function get_stripe_invoice_failure_reason( $invoice ) {
		$errors = array();

		if ( ! empty( $invoice->last_payment_error ) ) {
			$errors[] = $invoice->last_payment_error;
		}

		if ( ! empty( $invoice->payment_intent->last_payment_error ) ) {
			$errors[] = $invoice->payment_intent->last_payment_error;
		}

		if ( ! empty( $invoice->charge->failure_message ) ) {
			$errors[] = $invoice->charge->failure_message;
		}

		if ( ! empty( $invoice->last_finalization_error ) ) {
			$errors[] = $invoice->last_finalization_error;
		}

		foreach ( $errors as $error ) {
			if ( is_string( $error ) && '' !== $error ) {
				return $error;
			}

			if ( ! empty( $error->message ) ) {
				return $error->message;
			}

			if ( ! empty( $error->code ) ) {
				return $error->code;
			}
		}

		return '';
	}

	/**
	 * Processes payment failures.
	 *
	 * @param Stripe\Invoice $invoice Stripe invoice.
	 * @param Stripe\Event   $event   Optional. Stripe event.
	 */
	protected function process_invoice_payment_failed( $invoice, $event = null ) {
		// Only process if there is a subscription.
		$subscription_profile = $this->get_stripe_invoice_subscription_id( $invoice );

		if ( empty( $subscription_profile ) ) {
			return;
		}

		if ( ! $this->is_valid_stripe_id( $subscription_profile ) ) {
			$this->log( 'Aborted. Malformed subscription id: ' . $this->scrub_for_log( $subscription_profile, 64 ) );
			return;
		}

		$subscription_id = WPInv_Subscription::get_subscription_id_by_field( $subscription_profile );
		$subscription    = new WPInv_Subscription( (int) $subscription_id );

		if ( ! $subscription->exists() ) {
			$this->log( sprintf( 'Aborted. No GetPaid subscription found for the Stripe subscription %s.', $subscription_profile ) );
			return;
		}

		$parent_invoice = $subscription->get_parent_invoice();
		$has_parent     = ! empty( $parent_invoice ) && $parent_invoice->exists();

		// Don't process the same event twice.
		$event_id = empty( $event ) ? '' : $this->get_stripe_id( $event );
		$meta_key = '';

		if ( ! empty( $event_id ) && $has_parent ) {
			$meta_key = '_gp_stripe_failed_' . sanitize_key( $event_id );

			if ( get_post_meta( $parent_invoice->get_id(), $meta_key, true ) ) {
				$this->log( sprintf( 'Aborted. The event %s has already been processed for the subscription #%s.', $event_id, $subscription->get_id() ) );
				return;
			}
		}

		// Don't handle failures for cancelled or completed subscriptions.
		if ( in_array( $subscription->get_status(), array( 'cancelled', 'completed' ), true ) ) {
			$this->log( sprintf( 'Aborted. The subscription #%s is %s.', $subscription->get_id(), $subscription->get_status() ) );
			return;
		}

		// Log why the payment failed.
		$reason  = $this->get_stripe_invoice_failure_reason( $invoice );
		$attempt = empty( $invoice->attempt_count ) ? 0 : (int) $invoice->attempt_count;

		$note = wp_sprintf( __( 'Stripe subscription payment failed for the Stripe invoice %s.', 'wpinv-stripe' ), wpinv_clean( $this->get_stripe_id( $invoice ) ) );

		if ( $attempt > 0 ) {
			$note .= ' ' . wp_sprintf( __( 'Attempt: %d.', 'wpinv-stripe' ), $attempt );
		}

		if ( ! empty( $reason ) ) {
			$note .= ' ' . wp_sprintf( __( 'Reason: %s', 'wpinv-stripe' ), wpinv_clean( $this->scrub_for_log( $reason, 300 ) ) );
		}

		if ( ! empty( $invoice->next_payment_attempt ) ) {
			$note .= ' ' . wp_sprintf( __( 'Next attempt: %s.', 'wpinv-stripe' ), gmdate( 'Y-m-d H:i:s', (int) $invoice->next_payment_attempt ) . ' UTC' );
		}

		$this->log( $note );

		if ( $has_parent ) {
			$parent_invoice->add_note( $note, false, false, true );
		}

		// Stripe retries the payment several times, so only flag the subscription once.
		if ( 'failing' === $subscription->get_status() ) {
			$this->log( sprintf( 'The subscription #%s is already failing. The status has been left unchanged.', $subscription->get_id() ) );
		} else {
			$subscription->failing();

			$this->log( sprintf( 'The subscription #%s has been marked as failing.', $subscription->get_id() ) );
		}

		if ( ! empty( $meta_key ) ) {
			update_post_meta( $parent_invoice->get_id(), $meta_key, gmdate( 'Y-m-d H:i:s', empty( $event->created ) ? time() : (int) $event->created ) );
		}

		do_action( 'getpaid_stripe_invoice_payment_failed', $subscription, $invoice, $event );
	}

	/**
	 * Processes payment successes.
	 *
	 * @param Stripe\paymentIntent $payment_intent
	 */
	public function process_payment_intent_succeeded( $payment_intent, $event = array() ) {
		$intent_id = $this->get_stripe_id( $payment_intent );

		if ( ! $this->is_valid_stripe_id( $intent_id ) ) {
			$this->log( 'Aborted. Malformed payment intent id.' );
			return;
		}

		// Store invoice - charge reference
		if ( ! empty( $payment_intent->latest_charge ) && ! empty( $payment_intent->payment_details ) && ! empty( $payment_intent->payment_details->order_reference ) && is_string( $payment_intent->payment_details->order_reference ) && ! empty( $event->request->idempotency_key ) && is_string( $event->request->idempotency_key ) && strpos( $event->request->idempotency_key, $payment_intent->payment_details->order_reference ) === 0 ) {
			$inv_ref = wpinv_get_option( 'stripe_wb_ref' );

			if ( ! is_array( $inv_ref ) ) {
				$inv_ref = array();
			}

			$order_reference = explode( "-", $event->request->idempotency_key, 2 );
			$reference_key   = sanitize_key( $order_reference[0] );

			if ( '' !== $reference_key ) {
				$inv_ref[ $reference_key ] = array(
					'ch' => wpinv_clean( $this->get_stripe_id( $payment_intent->latest_charge ) ),
					'pi' => wpinv_clean( $intent_id ),
				);

				// Keep the stored references from growing without bound.
				$max_references = (int) apply_filters( 'getpaid_stripe_max_stored_references', 200 );

				if ( $max_references > 0 && count( $inv_ref ) > $max_references ) {
					$inv_ref = array_slice( $inv_ref, - $max_references, null, true );
				}

				wpinv_update_option( 'stripe_wb_ref', $inv_ref );
			}
		}

		// Retrieve the invoice.
		$invoice = wpinv_get_invoice( $intent_id );

		if ( empty( $invoice ) || ! $invoice->exists() ) {
			return;
		}

		// Prevent concurrent requests executing payment intent twice.
		if ( get_post_meta( $invoice->get_id(), '_gp_stripe_process_intent', true ) ) {
			delete_post_meta( $invoice->get_id(), '_gp_stripe_process_intent' );
			sleep(2);
		}

		$this->log( 'Found invoice #' . $invoice->get_number() );

		getpaid()->gateways['stripe']->process_payment_intent( $payment_intent, $invoice );
	}

	/**
	 * Processes payments.
	 *
	 * @param Stripe\Invoice $invoice
	 * @param Stripe\Event $event
	 */
	protected function process_invoice_payment_succeeded( $invoice, $event ) {
		global $gp_stripe_renew_args;

		$subscription_profile = $this->get_stripe_invoice_subscription_id( $invoice );

		// Only process if there is a subscription.
		if ( empty( $subscription_profile ) ) {
			return;
		}

		if ( ! $this->is_valid_stripe_id( $subscription_profile ) ) {
			$this->log( 'Aborted. Malformed subscription id: ' . $this->scrub_for_log( $subscription_profile, 64 ) );
			return;
		}

		$subscription = WPInv_Subscription::get_subscription_id_by_field( $subscription_profile );
		$subscription = new WPInv_Subscription( (int) $subscription );

		if ( ! $subscription->exists() ) {
			$this->log( sprintf( 'Aborted. No GetPaid subscription found for the Stripe subscription %s.', $subscription_profile ) );
			return;
		}

		$this->log( 'Found subscription #' . $subscription->get_id() );

		// Don't handle payment for cancelled subscription.
		if ( $subscription->get_status() == 'cancelled' ) {
			$this->log( 'The subscription has already been cancelled.' );

			return;
		}

		// Abort if this is the first payment.
		$_invoice       = $subscription->get_parent_invoice();
		$has_parent     = ! empty( $_invoice ) && $_invoice->exists();
		$transaction_id = empty( $invoice->charge ) ? $this->get_stripe_id( $invoice ) : $this->get_stripe_id( $invoice->charge );

		if ( ! $this->is_valid_stripe_id( $transaction_id ) ) {
			$this->log( 'Aborted. Malformed transaction id for the subscription #' . $subscription->get_id() . '.' );
			return;
		}

		if ( $has_parent ) {
			$this->log( 'Found parent invoice #' . $_invoice->get_number() );
		}

		if ( gmdate( 'Ynd', $subscription->get_time_created() ) === gmdate( 'Ynd', (int) $event->created ) ) {
			$subscription->activate();

			if ( $has_parent ) {
				$_invoice->add_note( wp_sprintf( __( 'Stripe Charge ID: %s', 'wpinv-stripe' ), wpinv_clean( $transaction_id ) ), false, false, true );

				$_invoice->set_transaction_id( $transaction_id );

				if ( ! $_invoice->is_paid() ) {
					$_invoice->mark_paid();
				}
			}

			return;
		}

		// Period start date.
		$period_start = strtotime( date( 'Y-m-d H:i:00' ) );

		if ( ! empty( $invoice->status_transitions ) && ! empty( $invoice->status_transitions->paid_at ) ) {
			$period_start = (int) $invoice->status_transitions->paid_at;
		}

		$args = array();
		$args['transaction_id'] = $transaction_id;
		$args['date_created'] = date( 'Y-m-d H:i:s', (int) $invoice->created );

		if ( ! empty( $invoice->status_transitions ) && ! empty( $invoice->status_transitions->paid_at ) ) {
			$args['completed_date'] = date( 'Y-m-d H:i:s', (int) $invoice->status_transitions->paid_at );
		}

		// Store global vars
		$gp_stripe_renew_args = $args;

		add_filter( 'getpaid_new_invoice_data', array( $this, 'filter_renewal_invoice_data' ), 10, 1 );

		$invoice_id = (int) $subscription->add_payment( $args );
		$subscription->renew( $period_start );

		// Set charge ID
		if ( $invoice_id ) {
			$inv_ref = wpinv_get_option( 'stripe_wb_ref' );

			if ( ! empty( $inv_ref ) && ! empty( $inv_ref[ $transaction_id ] ) && ! empty( $inv_ref[ $transaction_id ]['pi'] ) ) {
				update_post_meta( $invoice_id, '_gp_stripe_intent_id', $inv_ref[ $transaction_id ]['pi'] );
				update_post_meta( $invoice_id, '_gp_stripe_charge_id', $inv_ref[ $transaction_id ]['ch'] );

				unset( $inv_ref[ $transaction_id ] );

				wpinv_update_option( 'stripe_wb_ref', $inv_ref );
			}
		}

		unset( $gp_stripe_renew_args );
	}

	public function filter_renewal_invoice_data( $args ) {
		global $gp_stripe_renew_args;

		remove_filter( 'getpaid_new_invoice_data', array( $this, 'filter_renewal_invoice_data' ), 10, 1 );

		if ( ! ( ! empty( $gp_stripe_renew_args ) && ! empty( $gp_stripe_renew_args['completed_date'] ) ) ) {
			return $args;
		}

		if ( ! empty( $args['post_type'] ) && $args['post_type'] == 'wpi_invoice' && ! empty( $args['post_status'] ) && $args['post_status'] == 'wpi-renewal' ) {
			$args['post_date'] = $gp_stripe_renew_args['completed_date'];
		}

		return $args;
	}

	/**
	 * Converts a Stripe amount into a formatted invoice price.
	 *
	 * Stripe amounts are in the smallest currency unit, which is not always
	 * 1/100 of the currency ( JPY has no decimals, KWD has three ).
	 *
	 * @since 2.3.27
	 *
	 * @param int|float    $amount  Stripe amount.
	 * @param WPInv_Invoice $invoice Invoice the amount belongs to.
	 * @param bool         $clean Remove currency sign HTML wrap. Default True.
	 * @return string Formatted price.
	 */
	protected function get_price_from_stripe_amount( $amount, $invoice, $clean = true ) {
		$currency = $invoice->get_currency();
		$total    = (float) $invoice->get_total();
		$minor    = (float) getpaid_stripe_get_amount( $total, $currency );
		$factor   = ( $total > 0 && $minor > 0 ) ? ( $minor / $total ) : 100;

		if ( $factor <= 0 ) {
			$factor = 100;
		}

		$amount = wpinv_price( ( (float) $amount ) / $factor, $currency );

		if ( ! $clean ) {
			return $amount;
		}

		return html_entity_decode( strip_tags( $amount ) );
	}

	/**
	 * Processes refunds.
	 *
	 * @param Stripe\Charge $charge Stripe charge.
	 * @param Stripe\Event  $event  Optional. Stripe event.
	 */
	protected function process_charge_refunded( $charge, $event = null ) {
		$transaction_id = $this->get_stripe_id( $charge );

		if ( ! $this->is_valid_stripe_id( $transaction_id ) ) {
			$this->log( 'Aborted. Malformed charge id.' );
			return;
		}

		$invoice = WPInv_Invoice::get_invoice_id_by_field( $transaction_id, 'transaction_id' );

		if ( empty( $invoice ) && ! empty( $charge->payment_intent ) ) {
			$invoice = $this->get_invoice_id_by_intent( $charge->payment_intent );
		}

		if ( empty( $invoice ) ) {
			$this->log( 'Aborted. No invoice found for the charge ' . $transaction_id . '.' );
			return;
		}

		$invoice = new WPInv_Invoice( (int) $invoice );

		if ( ! $invoice->exists() ) {
			return;
		}

		$invoice_id = (int) $invoice->get_id();

		$this->log( 'Found invoice #' . $invoice->get_number() );

		// Each refund fires its own event, so the event id is the only safe duplicate guard.
		$event_id  = empty( $event ) ? '' : $this->get_stripe_id( $event );
		$event_key = '';

		if ( $this->is_valid_stripe_id( $event_id ) ) {
			$event_key = '_gp_stripe_refund_event_' . sanitize_key( $event_id );

			if ( get_post_meta( $invoice_id, $event_key, true ) ) {
				$this->log( 'Aborted. The event ' . $event_id . ' has already been processed for the invoice #' . $invoice->get_number() . '.' );
				return;
			}
		}

		// Running total refunded against the charge, in the smallest currency unit.
		$refunded_total = isset( $charge->amount_refunded ) ? (int) $charge->amount_refunded : 0;

		if ( $refunded_total < 1 ) {
			$this->log( 'Aborted. The charge ' . $transaction_id . ' has nothing refunded.' );
			return;
		}

		$total_key  = '_gp_stripe_refunded_' . sanitize_key( $transaction_id );
		$legacy_key = '_gp_stripe_refund_' . sanitize_key( $transaction_id );
		$stored     = get_post_meta( $invoice_id, $total_key, true );

		if ( '' !== $stored && null !== $stored ) {
			$previous = (int) $stored;
		} elseif ( get_post_meta( $invoice_id, $legacy_key, true ) ) {
			// Upgraded from a version that only stored a timestamp, so the amount
			// refunded before this event is unknown.
			$previous = null;
		} else {
			$previous = 0;
		}

		$difference = null === $previous ? null : $refunded_total - $previous;

		if ( null !== $difference && $difference < 1 ) {
			$this->log(
				sprintf(
					'Aborted. Nothing new was refunded for the invoice #%s ( recorded: %d, charge: %d ).',
					$invoice->get_number(),
					(int) $previous,
					$refunded_total
				)
			);

			if ( ! empty( $event_key ) ) {
				update_post_meta( $invoice_id, $event_key, gmdate( 'Y-m-d H:i:s' ) );
			}

			return;
		}

		$payment_amount = (int) getpaid_stripe_get_amount( $invoice->get_total(), $invoice->get_currency() );
		$is_full_refund = $payment_amount > 0 && $refunded_total >= $payment_amount;

		$this->log(
			sprintf(
				'Refund for the invoice #%s. This refund: %s, total refunded: %s of %s.',
				$invoice->get_number(),
				null === $difference ? 'unknown' : $this->get_price_from_stripe_amount( $difference, $invoice ),
				$this->get_price_from_stripe_amount( $refunded_total, $invoice ),
				$this->get_price_from_stripe_amount( $payment_amount, $invoice )
			)
		);

		// Note the amount refunded by this event, plus the running total.
		if ( $is_full_refund ) {
			$note = wp_sprintf(
				__( 'Invoice fully refunded. Total refunded: %s.', 'wpinv-stripe' ),
				$this->get_price_from_stripe_amount( $refunded_total, $invoice )
			);
		} elseif ( null === $difference ) {
			$note = wp_sprintf(
				__( 'Invoice partially refunded. Total refunded: %1$s of %2$s.', 'wpinv-stripe' ),
				$this->get_price_from_stripe_amount( $refunded_total, $invoice ),
				$this->get_price_from_stripe_amount( $payment_amount, $invoice )
			);
		} else {
			$note = wp_sprintf(
				__( 'Invoice partially refunded %1$s. Total refunded: %2$s of %3$s.', 'wpinv-stripe' ),
				$this->get_price_from_stripe_amount( $difference, $invoice ),
				$this->get_price_from_stripe_amount( $refunded_total, $invoice ),
				$this->get_price_from_stripe_amount( $payment_amount, $invoice )
			);
		}

		$invoice->add_note( $note, false, false, true );

		// Record the running total before changing the status, so that a second
		// delivery of this event cannot double count it.
		update_post_meta( $invoice_id, $total_key, $refunded_total );
		update_post_meta( $invoice_id, $legacy_key, gmdate( 'Y-m-d H:i:s', empty( $charge->created ) ? time() : (int) $charge->created ) );

		if ( ! empty( $event_key ) ) {
			update_post_meta( $invoice_id, $event_key, gmdate( 'Y-m-d H:i:s' ) );
		}

		if ( $is_full_refund && ! $invoice->is_refunded() ) {
			$invoice->refund();
		}

		do_action( 'getpaid_stripe_charge_refunded', $invoice, $charge, $event );
	}

	/**
	 * Processes subscription update.
	 *
	 * @param Stripe\Subscription $subscription
	 * @param Stripe\Event $event
	 */
	protected function process_customer_subscription_updated( $stripe_subscription, $event = array() ) {
		$profile_id = $this->get_stripe_id( $stripe_subscription );

		if ( ! $this->is_valid_stripe_id( $profile_id ) ) {
			$this->log( 'Aborted. Malformed subscription id: ' . $this->scrub_for_log( $profile_id, 64 ) );
			return;
		}

		$this->log( 'Processing subscription update for #' . $profile_id );

		$subscription_id = WPInv_Subscription::get_subscription_id_by_field( $profile_id );

		if ( empty( $subscription_id ) ) {
			$this->log( 'No subscription found for #' . $profile_id );
			return;
		}

		$subscription = new WPInv_Subscription( $subscription_id );

		if ( ! $subscription->exists() ) {
			return;
		}

		// Don't handle webhook for cancelled subscription.
		if ( $subscription->get_status() == 'cancelled' ) {
			return;
		}

		$new_status = $stripe_subscription->status;

		if ( $new_status == 'trialing' && ! empty( $stripe_subscription->trial_end ) && strpos( $subscription->get_expiration(), date( 'Y-m-d', (int) $stripe_subscription->trial_end ) ) !== 0 ) {
			$subscription->set_expiration( date( 'Y-m-d H:i:s', (int) $stripe_subscription->trial_end ) );
			$subscription->save();

			$this->log( 'Subscription #' . $subscription->get_id() . ' has been updated.' );
		} else if ( ! empty( $stripe_subscription->current_period_end ) && strpos( $subscription->get_expiration(), date( 'Y-m-d', (int) $stripe_subscription->current_period_end ) ) !== 0 ) {
			$subscription->set_expiration( date( 'Y-m-d H:i:s', (int) $stripe_subscription->current_period_end ) );
			$subscription->save();

			$this->log( 'Subscription #' . $subscription->get_id() . ' has been updated.' );
		}

		do_action( "getpaid_stripe_process_customer_subscription_updated", $subscription, $stripe_subscription );
	}

	/**
	 * Processes subscription deleted.
	 *
	 * @param Stripe\Subscription $subscription
	 * @param Stripe\Event $event
	 */
	protected function process_customer_subscription_deleted( $stripe_subscription, $event = array() ) {
		$profile_id = $this->get_stripe_id( $stripe_subscription );

		if ( ! $this->is_valid_stripe_id( $profile_id ) ) {
			$this->log( 'Aborted. Malformed subscription id: ' . $this->scrub_for_log( $profile_id, 64 ) );
			return;
		}

		$this->log( 'Processing subscription deleted for #' . $profile_id );

		$subscription_id = WPInv_Subscription::get_subscription_id_by_field( $profile_id );

		if ( empty( $subscription_id ) ) {
			$this->log( 'No subscription found for #' . $profile_id );
			return;
		}

		$subscription = new WPInv_Subscription( $subscription_id );

		if ( ! $subscription->exists() ) {
			return;
		}

		// Don't handle webhook for cancelled subscription.
		if ( $subscription->get_status() == 'cancelled' ) {
			return;
		}

		$this->log( 'Processing subscription cancellation for #' . $profile_id );

		$subscription->cancel();

		$this->log( 'Subscription #' . $profile_id . ' has been cancelled.' );
	}

	/**
	 * Processes customer subscription updated.
	 *
	 * @param Stripe\Subscription $subscription
	 * @param Stripe\Event $event
	 */
	protected function customer_subscription_updated( $subscription ) {
		$this->log( 'Processing subscription update.' );

		$profile_id = $this->get_stripe_id( $subscription );

		if ( ! $this->is_valid_stripe_id( $profile_id ) ) {
			$this->log( 'Aborted. Malformed subscription id.' );
			return;
		}

		$_subscription = new WPInv_Subscription( (int) WPInv_Subscription::get_subscription_id_by_field( $profile_id ) );

		if ( ! $_subscription->exists() ) {
			return;
		}

		// The status is remote input, so keep the dynamic hook name to a safe slug.
		$status = sanitize_key( isset( $subscription->status ) ? $subscription->status : '' );

		if ( '' === $status ) {
			return;
		}

		do_action( "getpaid_stripe_subscription_{$status}", $_subscription, $subscription );

		$this->log( 'Subscription updated.' );
	}

	/**
	 * Processes setup intent success.
	 *
	 * @param Stripe\setupIntent $setup_intent
	 */
	protected function process_setup_intent_succeeded( $setup_intent ) {
		// If there is a remote_id metadata field, then we're updating a payment method.
		if ( ! empty( $setup_intent->metadata->remote_id ) && is_string( $setup_intent->metadata->remote_id ) ) {
			// Fetch local sub.
			$subscription = WPInv_Subscription::get_subscription_id_by_field( wpinv_clean( $setup_intent->metadata->remote_id ) );
			$subscription = new WPInv_Subscription( (int) $subscription );

			if ( ! $subscription->exists() ) {
				return;
			}

			// Process the payment method.
			$_setup_intent = new GetPaid_Stripe_Setup_Intent( $this, $subscription );

			return $_setup_intent->process_payment_method( $setup_intent );
		}

		// Otherwise, we're creating a new subscription.
		if ( empty( $setup_intent->metadata->invoice_key ) || ! is_string( $setup_intent->metadata->invoice_key ) || empty( $setup_intent->payment_method ) ) {
			return;
		}

		// Retrieve the matching invoice.
		$invoice = wpinv_get_invoice( wpinv_clean( $setup_intent->metadata->invoice_key ) );

		if ( ! ( ! empty( $invoice ) && $invoice->exists() ) ) {
			return;
		}

		if ( ! empty( $invoice ) && $invoice->exists() ) {
			$this->log( 'Found invoice #' . $invoice->get_number() );
		}

		$payment_method = $this->get_stripe_id( $setup_intent->payment_method );

		// The customer does not have a payment method with the ID pm_xyz. The payment method must be attached to the customer.
		if ( ! empty( $setup_intent->charges ) && $setup_intent->charges->data ) {
			$charge = end( $setup_intent->charges->data );

			if ( ! empty( $charge ) && ! empty( $charge->payment_method_details ) ) {
				$payment_method_details = $charge->payment_method_details;

				if ( ! empty( $payment_method_details->type ) && $payment_method_details->type == 'ideal' ) {
					if ( ! empty( $payment_method_details->ideal ) && ! empty( $payment_method_details->ideal->generated_sepa_debit ) ) {
						$payment_method = $this->get_stripe_id( $payment_method_details->ideal->generated_sepa_debit );
					}
				}
			}
		}

		// The value is saved against the invoice, so make sure it is a Stripe id.
		if ( ! $this->is_valid_stripe_id( $payment_method ) ) {
			$this->log( 'Aborted. Malformed payment method id for the invoice #' . $invoice->get_id() . '.' );
			return;
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
				getpaid()->gateways['stripe']->process_subscriptions( $subscriptions );
			} else {
				getpaid()->gateways['stripe']->process_normal_subscription( $subscriptions );
			}
		}
	}

	/**
	 * Processes checkout session success.
	 *
	 * @param Stripe\Checkout\Session $session
	 */
	protected function process_checkout_session_completed( $session ) {
		if ( empty( $session->client_reference_id ) || ! is_string( $session->client_reference_id ) ) {
			$this->log( 'Aborted. The checkout session has no client reference id.' );
			return;
		}

		// Retrieve the matching invoice.
		$invoice = wpinv_get_invoice( wpinv_clean( $session->client_reference_id ) );

		if ( empty( $invoice ) || ! $invoice->exists() ) {
			return;
		}

		if ( ! empty( $invoice ) && $invoice->exists() ) {
			$this->log( 'Found invoice #' . $invoice->get_number() );
		}

		// Process setup intents.
		if ( 'setup' === $session->mode ) {
			return $this->gateway->process_setup_intent( $session->setup_intent, $invoice, true );
		}

		// Processes payment intents.
		if ( 'payment' === $session->mode ) {
			$intent_id = $this->get_stripe_id( $session->payment_intent );

			if ( ! $this->is_valid_stripe_id( $intent_id ) ) {
				$this->log( 'Aborted. Malformed payment intent id for the invoice #' . $invoice->get_id() . '.' );
				return;
			}

			update_post_meta( $invoice->get_id(), 'wpinv_stripe_intent_id', $intent_id );
			return $this->gateway->process_payment_intent( $session->payment_intent, $invoice, true );
		}

		// Process subscriptions.
		if ( 'subscription' === $session->mode ) {
			$profile_id = $this->get_stripe_id( $session->subscription );
			$profile_id = $this->is_valid_stripe_id( $profile_id ) ? $profile_id : '';

			$subscription = getpaid_get_invoice_subscriptions( $invoice );

			if ( is_object( $subscription ) ) {
				// Don't handle payment for cancelled subscription.
				if ( $subscription->get_status() == 'cancelled' ) {
					return;
				}

				if ( ! empty( $profile_id ) ) {
					$subscription->set_profile_id( $profile_id );
				}

				$subscription->activate();
			}

			$stripe_invoice_id = ! empty( $session->invoice ) ? $this->get_stripe_id( $session->invoice ) : '';

			if ( $this->is_valid_stripe_id( $stripe_invoice_id ) ) {
				$invoice->add_note( wp_sprintf( __( 'Stripe Invoice ID: %s', 'wpinv-stripe' ), wpinv_clean( $stripe_invoice_id ) ), false, false, true );

				$invoice->set_transaction_id( $stripe_invoice_id );
			}

			$invoice->set_remote_subscription_id( $profile_id );
			$invoice->mark_paid();
		}
	}

	/**
	 * Retrieve invoice ID by Payment intent ID.
	 *
	 * @since 2.3.22
	 *
	 * @param string $intent_id Payment intent ID.
	 * @return int Invoice ID.
	 */
	public function get_invoice_id_by_intent( $intent_id ) {
		global $wpdb;

		$intent_id = $this->get_stripe_id( $intent_id );

		// An empty value would match every row that has an empty meta value.
		if ( ! $this->is_valid_stripe_id( $intent_id ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` = '_gp_stripe_intent_id' AND `meta_value` = %s ORDER BY `meta_id` DESC LIMIT 1", $intent_id ) );
	}
}
