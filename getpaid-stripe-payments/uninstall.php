<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @link       https://wpgeodirectory.com
 * @since      1.0.0
 *
 * @package    WPInv_Stripe
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'wpinv_settings', array() );
if ( ! empty( $settings['remove_data_on_unistall'] ) ) {
	$options = array(
		'stripe_sandbox',
		'stripe_test_secret_key',
		'stripe_test_publishable_key',
		'stripe_live_secret_key',
		'stripe_live_publishable_key',
		'stripe_locale',
		'stripe_disable_update_card',
		'stripe_ipn_url',
		'uninstall_wpinv_stripe',
	);

	$options = apply_filters( 'uninstall_wpinv_stripe_data', $options );

	if ( ! empty( $options ) ) {
		foreach ( $options as $option ) {

			if ( isset( $settings[ $option ] ) ) {
				unset( $settings[ $option ] );
			}
}
	}

	update_option( 'wpinv_settings', $settings );
}
