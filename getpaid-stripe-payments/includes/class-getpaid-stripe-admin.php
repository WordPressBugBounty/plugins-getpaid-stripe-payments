<?php
/**
 * Stripe payment gateway admin class
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Payment Gateway admin class.
 *
 */
class GetPaid_Stripe_Admin {

	/**
	 * Retrieves the Stripe connect URL.
	 *
	 *
	 * @param bool $is_sandbox
	 * @param string $redirect
	 * @return string
	 */
	public static function get_connect_url( $is_sandbox, $redirect = '' ) {

		$redirect_url = add_query_arg(
			array(
				'getpaid-admin-action' => 'connect_stripe',
				'page'                 => 'wpinv-settings',
				'live_mode'            => (int) empty( $is_sandbox ),
				'tab'                  => 'gateways',
				'section'              => 'stripe',
				'getpaid-nonce'        => wp_create_nonce( 'getpaid-nonce' ),
				'redirect'             => rawurlencode( $redirect ),
			),
			admin_url( 'admin.php' )
		);

		return add_query_arg(
			array(
				'live_mode'    => (int) empty( $is_sandbox ),
				'redirect_url' => rawurlencode( str_replace( '&amp;', '&', $redirect_url ) ),
			),
			'https://ayecode.io/oauth/stripe'
		);

	}

	/**
	 * Generates settings page js.
	 *
	 * @return void
	 */
	public static function display_connect_buttons() {

		// Connection URLs.
		$live_connect    = esc_url( self::get_connect_url( false ) );
		$sandbox_connect = esc_url( self::get_connect_url( true ) );

		// Connected accounts.
		$live_account    = wpinv_get_option( 'stripe_live_connect_account_id' );
		$sandbox_account = wpinv_get_option( 'stripe_test_connect_account_id' );

		// Disconnection URLs.
		$live_disconnect    = wp_nonce_url( admin_url( 'admin.php?page=wpinv-settings&live=1&getpaid-admin-action=disconnect_stripe' ), 'getpaid-nonce', 'getpaid-nonce' );
		$sandbox_disconnect = wp_nonce_url( admin_url( 'admin.php?page=wpinv-settings&live=0&getpaid-admin-action=disconnect_stripe' ), 'getpaid-nonce', 'getpaid-nonce' );

		// Buttons.
		$connect    = __( 'Connect with Stripe', 'wpinv-stripe' );
		$disconnect = __( 'Disconnect from Stripe', 'wpinv-stripe' );

		if ( empty( $live_account ) ) {
			$desc  = __( 'Not connected to your live Stripe account.', 'wpinv-stripe' );
			$live  = "<a href='$live_connect' class='wpinv-stripe-connect-btn'><span>$connect</span></a><p class='description not-connected'><strong>$desc</strong></p></div>";
		} else {
			$desc  = __( 'Connected to your live Stripe account.', 'wpinv-stripe' );
			$live  = "<a href='$live_disconnect' class='wpinv-stripe-connect-btn'><span>$disconnect</span></a><p class='description connected'><strong>$desc</strong></p></div>";
		}

		if ( empty( $sandbox_account ) ) {
			$desc    = __( 'Not connected to your sandbox Stripe account.', 'wpinv-stripe' );
			$sandbox = "<a href='$sandbox_connect' class='wpinv-stripe-connect-btn'><span>$connect</span></a><p class='description not-connected'><strong>$desc</strong></p></div>";
		} else {
			$desc    = __( 'Connected to your sandbox Stripe account.', 'wpinv-stripe' );
			$sandbox = "<a href='$sandbox_disconnect' class='wpinv-stripe-connect-btn'><span>$disconnect</span></a><p class='description connected'><strong>$desc</strong></p></div>";
		}

		?>
			<style>
				.wpinv-stripe-connect-btn {
					display: inline-block;
					margin-bottom: 1px;
					background-image: -webkit-linear-gradient(#28A0E5, #015E94);
					background-image: -moz-linear-gradient(#28A0E5, #015E94);
					background-image: -ms-linear-gradient(#28A0E5, #015E94);
					background-image: linear-gradient(#28A0E5, #015E94);
					-webkit-font-smoothing: antialiased;
					border: 0;
					padding: 1px;
					height: 32px;
					text-decoration: none;
					-moz-border-radius: 4px;
					-webkit-border-radius: 4px;
					border-radius: 4px;
					-moz-box-shadow: 0 1px 0 rgba(0,0,0,0.2);
					-webkit-box-shadow: 0 1px 0 rgba(0, 0, 0, 0.2);
					box-shadow: 0 1px 0 rgba(0, 0, 0, 0.2);
					cursor: pointer;
					-moz-user-select: none;
					-webkit-user-select: none;
					-ms-user-select: none;
					user-select: none;
					text-decoration: none !important;
					font-weight: 500;
				}

				.wpinv-stripe-connect-btn span {
					display: block;
					position: relative;
					padding: 0 12px 0 44px;
					height: 30px;
					background: #1275FF;
					background-image: -webkit-linear-gradient(#7DC5EE, #008CDD 85%, #30A2E4);
					background-image: -moz-linear-gradient(#7DC5EE, #008CDD 85%, #30A2E4);
					background-image: -ms-linear-gradient(#7DC5EE, #008CDD 85%, #30A2E4);
					background-image: linear-gradient(#7DC5EE, #008CDD 85%, #30A2E4);
					font-size: 15px;
					line-height: 30px;
					color: white;
					font-weight: bold;
					font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
					text-shadow: 0 -1px 0 rgba(0, 0, 0, 0.2);
					-moz-box-shadow: inset 0 1px 0 rgba(255,255,255,0.25);
					-webkit-box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
					box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
					-moz-border-radius: 3px;
					-webkit-border-radius: 3px;
					border-radius: 3px;
				}

				.wpinv-stripe-connect-btn span:before {
					background-image: url(data:image/png;base64,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);
					content: '';
					display: block;
					position: absolute;
					left: 11px;
					top: 50%;
					width: 23px;
					height: 24px;
					margin-top: -12px;
					background-repeat: no-repeat;
					background-size: 23px 24px;
				}

				.wpinv-stripe-connect-live .connected,
				.wpinv-stripe-connect-sandbox .connected {
					color: #28a745;
				}

				.wpinv-stripe-connect-live .not-connected,
				.wpinv-stripe-connect-sandbox .not-connected {
					color: #dc3545;
				}

			</style>

			<div class='wpinv-stripe-connect-live'><?php echo wp_kses_post( $live ); ?></div>
			<div class='wpinv-stripe-connect-sandbox'><?php echo wp_kses_post( $sandbox ); ?></div>

			<script>
				jQuery(document).ready(function() {

					var misc_settings   = '#wpinv-settings-stripe_locale, #wpinv-settings-stripe_disable_save_card, #wpinv-settings-stripe_disable_update_card, #wpinv-settings-stripe_ipn_url, #wpinv-settings-stripe_ordering, #wpinv-settings-stripe_desc, #wpinv-settings-stripe_title, #wpinv-settings-stripe_ordering, #wpinv-settings-stripe_payment_request'
					var manual_settings = '#wpinv-settings-stripe_test_publishable_key, #wpinv-settings-stripe_test_secret_key, #wpinv-settings-stripe_live_publishable_key, #wpinv-settings-stripe_live_secret_key'

					jQuery( '#wpinv-settings-stripe_sandbox' ).on ( 'change', function( e ) {

						if ( jQuery('#wpinv-settings-disable_stripe_connect').is(':checked') ) {
							//jQuery( misc_settings ).closest( 'tr' ).show()
							jQuery( '.wpinv-stripe-connect-sandbox' ).closest( 'tr' ).hide()
							jQuery( manual_settings ).closest( 'tr' ).show()
							return
						}

						jQuery( '.wpinv-stripe-connect-sandbox' ).closest( 'tr' ).show()
						jQuery( manual_settings ).closest( 'tr' ).hide()

						if ( this.checked ) {
							jQuery( '.wpinv-stripe-connect-live' ).hide()
							jQuery( '.wpinv-stripe-connect-sandbox' ).show()

							// Hide settings if sandbox is not connected.
							if ( jQuery( '.wpinv-stripe-connect-sandbox .description' ).hasClass( 'not-connected' ) ) {
								//jQuery( misc_settings ).closest( 'tr' ).hide()
							} else {
								//jQuery( misc_settings ).closest( 'tr' ).show()
							}

						} else {
							jQuery( '.wpinv-stripe-connect-live' ).show()
							jQuery( '.wpinv-stripe-connect-sandbox' ).hide()

							// Hide settings if live is not connected.
							if ( jQuery( '.wpinv-stripe-connect-live .description' ).hasClass( 'not-connected' ) ) {
								//jQuery( misc_settings ).closest( 'tr' ).hide()
							} else {
								//jQuery( misc_settings ).closest( 'tr' ).show()
							}
						}

					})

					jQuery( '#wpinv-settings-disable_stripe_connect' ).on ( 'change', function( e ) {
						jQuery( '#wpinv-settings-stripe_sandbox' ).trigger( 'change' )
					});

					// Set initial state.
					jQuery( '#wpinv-settings-disable_stripe_connect' ).trigger( 'change' )

				});
			</script>
		<?php

}

	/**
	 * Returns an array of stripe settings.
	 *
	 *
	 * @return array
	 */
	public static function get_settings() {

		return array(

			'redirect_stripe_checkout'    => array(
				'type' => 'checkbox',
				'id'   => 'redirect_stripe_checkout',
				'name' => __( 'Redirect to Stripe Checkout', 'wpinv-stripe' ),
				'desc' => __( 'Redirect customers to Stripe checkout.', 'wpinv-stripe' ) . '<strong style="color: #a00;"> ' . __( 'WARNING: This functionality is still in BETA.', 'wpinv-stripe' ) . '</strong>',
				'std'  => false,
			),

			'disable_stripe_connect'      => array(
				'type' => 'checkbox',
				'id'   => 'disable_stripe_connect',
				'name' => __( 'Disable Stripe Connect', 'wpinv-stripe' ),
				'desc' => __( 'Check to manually enter your API keys', 'wpinv-stripe' ),
				'std'  => false !== wpinv_get_option( 'stripe_test_publishable_key' ) && false !== wpinv_get_option( 'stripe_live_publishable_key' ),
			),

			'stripe_test_publishable_key' => array(
				'type' => 'text',
				'id'   => 'stripe_test_publishable_key',
				'name' => __( 'Test Publishable Key', 'wpinv-stripe' ),
				'desc' => __( 'Enter your test publishable key', 'wpinv-stripe' ),
				'size' => 'large',
			),

			'stripe_test_secret_key'      => array(
				'type' => 'text',
				'id'   => 'stripe_test_secret_key',
				'name' => __( 'Test Secret Key', 'wpinv-stripe' ),
				'desc' => __( 'Enter your test secret key', 'wpinv-stripe' ),
			),

			'stripe_live_publishable_key' => array(
				'type' => 'text',
				'id'   => 'stripe_live_publishable_key',
				'name' => __( 'Live Publishable Key', 'wpinv-stripe' ),
				'desc' => __( 'Enter your live publishable key', 'wpinv-stripe' ),
				'size' => 'large',
			),

			'stripe_live_secret_key'      => array(
				'type' => 'text',
				'id'   => 'stripe_live_secret_key',
				'name' => __( 'Live Secret Key', 'wpinv-stripe' ),
				'desc' => __( 'Enter your live secret key', 'wpinv-stripe' ),
			),

			'stripe_connect'              => array(
				'type' => 'hook',
				'id'   => 'stripe_connect',
				'name' => __( 'Connect to Stripe', 'wpinv-stripe' ),
			),

			'stripe_payment_methods'      => array(
				'type'    => 'multicheck',
				'id'      => 'stripe_payment_methods',
				'name'    => __( 'Payment Methods', 'wpinv-stripe' ),
				'desc'    => __( 'Select the payment methods you want to enable. Make sure they are enabled in your Stripe account.', 'wpinv-stripe' ),
				'options' => array(
					'acss_debit'        => __( 'ACSS Debit', 'wpinv-stripe' ),
					'affirm'            => __( 'Affirm', 'wpinv-stripe' ),
					'afterpay_clearpay' => __( 'Afterpay Clearpay', 'wpinv-stripe' ),
					'alipay'            => __( 'Alipay', 'wpinv-stripe' ),
					'au_becs_debit'     => __( 'Australian BECS Debit', 'wpinv-stripe' ),
					'bacs_debit'        => __( 'Bacs Debit', 'wpinv-stripe' ),
					'bancontact'        => __( 'Bancontact', 'wpinv-stripe' ),
					'blik'              => __( 'BLIK', 'wpinv-stripe' ),
					'boleto'            => __( 'Boleto', 'wpinv-stripe' ),
					'card'              => __( 'Card', 'wpinv-stripe' ),
					'customer_balance'  => __( 'Customer Balance', 'wpinv-stripe' ),
					'eps'               => __( 'EPS', 'wpinv-stripe' ),
					'fpx'               => __( 'FPX', 'wpinv-stripe' ),
					'giropay'           => __( 'Giropay', 'wpinv-stripe' ),
					'grabpay'           => __( 'GrabPay', 'wpinv-stripe' ),
					'ideal'             => __( 'iDEAL', 'wpinv-stripe' ),
					'klarna'            => __( 'Klarna', 'wpinv-stripe' ),
					'konbini'           => __( 'Konbini', 'wpinv-stripe' ),
					'link'              => __( 'Link', 'wpinv-stripe' ),
					'oxxo'              => __( 'OXXO', 'wpinv-stripe' ),
					'p24'               => __( 'P24', 'wpinv-stripe' ),
					'paynow'            => __( 'PayNow', 'wpinv-stripe' ),
					'promptpay'         => __( 'PromptPay', 'wpinv-stripe' ),
					'sepa_debit'        => __( 'SEPA Debit', 'wpinv-stripe' ),
					'sofort'            => __( 'SOFORT', 'wpinv-stripe' ),
					'us_bank_account'   => __( 'US Bank Account', 'wpinv-stripe' ),
					'wechat_pay'        => __( 'WeChat Pay', 'wpinv-stripe' ),
				),
				'std'     => array( 'card' ),
			),

			'stripe_ipn_url'              => array(
				'type'   => 'text',
				'id'     => 'stripe_ipn_url',
				'name'   => __( 'Stripe Webhook URL', 'wpinv-stripe' ),
				'std'    => wpinv_get_ipn_url( 'stripe' ),
				'desc'   => __( 'Copy and paste this URL into your Stripe account at Developers > Webhooks > Add endpoint > Endpoint URL. Events to send are invoice.payment_failed, invoice.payment_succeeded, customer.subscription.created, customer.subscription.deleted, customer.subscription.updated, customer.subscription.trial_will_end, charge.refunded, payment_intent.succeeded, setup_intent.succeeded, setup_intent.setup_failed, setup_intent.requires_action, setup_intent.created, setup_intent.canceled, checkout.session.completed', 'wpinv-stripe' ),
				'size'   => 'large',
				'custom' => 'stripe',
				'faux'   => true,
			),

		);

	}

	/**
	 * Migrates old-style Stripe keys to their new keys.
	 *
	 * @return string
	 */
	public static function maybe_migrate_keys() {
		global $wpdb;

		if ( '1' !== get_option( 'getpaid_stripe_migrated_keys' ) ) {

			// Update test meta keys.
			$test_user_meta_keys = $wpdb->get_col( "SELECT DISTINCT( meta_key ) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%_wpi_stripe_customer_id_test'" );
			foreach ( $test_user_meta_keys as $test_user_meta_key ) {

				if ( '_' === substr( $test_user_meta_key, 0, 1 ) ) {
					$currency = strtoupper( wpinv_get_currency() );
				} else {
					$currency = strtoupper( substr( $test_user_meta_key, 0, 3 ) );
				}

				$wpdb->update(
					$wpdb->usermeta,
					array( 'meta_key' => "wpinv_stripe_sandbox_customer_id$currency" ),
					array( 'meta_key' => $test_user_meta_key )
				);

			}

			// Update live meta keys.
			$live_user_meta_keys = $wpdb->get_col( "SELECT DISTINCT( meta_key ) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%_wpi_stripe_customer_id'" );
			foreach ( $live_user_meta_keys as $live_user_meta_key ) {

				if ( '_' === substr( $live_user_meta_key, 0, 1 ) ) {
					$currency = strtoupper( wpinv_get_currency() );
				} else {
					$currency = strtoupper( substr( $live_user_meta_key, 0, 3 ) );
				}

				$wpdb->update(
					$wpdb->usermeta,
					array( 'meta_key' => "wpinv_stripe_customer_id$currency" ),
					array( 'meta_key' => $live_user_meta_key )
				);

			}

			update_option( 'getpaid_stripe_migrated_keys', '1' );
		}

	}

}
