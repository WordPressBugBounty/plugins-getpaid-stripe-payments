<?php
/**
 * This is the main plugin file, here we declare and call the important stuff
 *
 * @package           GETPAID
 * @subpackage        STRIPE
 * @copyright         2020 AyeCode Ltd
 * @license           GPLv2
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       GetPaid Stripe Payments
 * Plugin URI:        https://wpgetpaid.com/downloads/stripe-payment-gateway/
 * Description:       Stripe payment gateway for Invoicing/GetPaid plugin.
 * Version:           2.3.11
 * Author:            AyeCode Ltd
 * Author URI:        https://wpgetpaid.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins:  invoicing
 * Text Domain:       wpinv-stripe
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! defined( 'WPINV_STRIPE_VERSION' ) ) {
    define( 'WPINV_STRIPE_VERSION', '2.3.11' );
}

if ( ! defined( 'WPINV_STRIPE_FILE' ) ) {
    define( 'WPINV_STRIPE_FILE', __FILE__ );
}

if ( ! defined( 'WPINV_STRIPE_API_VERSION' ) ) {
	define( 'WPINV_STRIPE_API_VERSION', '2020-03-02' );
}

if ( ! defined( 'WPINV_STRIPE_DIR' ) ) {
    define( 'WPINV_STRIPE_DIR', plugin_dir_path( WPINV_STRIPE_FILE ) );
}

if ( ! defined( 'WPINV_STRIPE_URL' ) ) {
    define( 'WPINV_STRIPE_URL', plugin_dir_url( WPINV_STRIPE_FILE ) );
}

/**
 * Registers Invoicing as a required plugin.
 */
function wpinv_stripe_register_required_plugins() {
	/*
	 * Array of plugin arrays. Required keys are name and slug.
	 */
	$plugins = array(

		// This is an example of how to include a plugin from the WordPress Plugin Repository.
		array(
			'name'     => 'GetPaid/Invoicing',
			'slug'     => 'invoicing',
            'required' => true,
            'version'  => '2.5.0',
		),

	);

	/*
	 * Array of configuration settings. Amend each line as needed.
	 *
	 */
	$config = array(
		'id'           => 'wpinv-stripe',                 // Unique ID for hashing notices for multiple instances of TGMPA.
		'default_path' => '',                             // Default absolute path to bundled plugins.
		'menu'         => 'wpinv-stripe-install-plugins', // Menu slug.
		'parent_slug'  => 'plugins.php',                  // Parent menu slug.
		'capability'   => 'manage_options',               // Capability needed to view plugin install page, should be a capability associated with the parent menu used.
		'has_notices'  => true,                           // Show admin notices or not.
		'dismissable'  => false,                          // If false, a user cannot dismiss the nag message.
		'dismiss_msg'  => '',                             // If 'dismissable' is false, this message will be output at top of nag.
		'is_automatic' => false,                          // Automatically activate plugins after installation or not.
		'message'      => '',                             // Message to output right before the plugins table.
	);

	tgmpa( $plugins, $config );
}
add_action( 'tgmpa_register', 'wpinv_stripe_register_required_plugins' );
require_once plugin_dir_path( WPINV_STRIPE_FILE ) . 'includes/class-tgm-plugin-activation.php';


// (Maybe) Ask the user to install GetPaid.
add_action( 'admin_notices', 'wpinv_stripe_check_getpaid' );
function wpinv_stripe_check_getpaid() {

    if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
        ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong>
                        <?php esc_html_e( 'Your version of PHP is below the minimum version of PHP required by "GetPaid Stripe Payments". Please contact your host and request that your version be upgraded to 5.6.0 or greater.', 'wpinv-stripe' ); ?>
                    </strong>
                </p>
            </div>
        <?php
    }

}

// Add our path to the list of autoload searches.
add_filter( 'getpaid_autoload_locations', 'wpinv_stripe_autoload_locations' );
function wpinv_stripe_autoload_locations( $locations ) {
    $locations[] = plugin_dir_path( WPINV_STRIPE_FILE ) . 'includes';
    return $locations;
}

// Register our gateway.
add_filter( 'getpaid_default_gateways', 'wpinv_stripe_register_gateway' );
function wpinv_stripe_register_gateway( $gateways ) {
    $gateways['stripe'] = 'GetPaid_Stripe_Gateway';
    return $gateways;
}

// Load text domain.
add_action( 'plugins_loaded', 'wpinv_stripe_load_plugin_textdomain' );
function wpinv_stripe_load_plugin_textdomain() {

    load_plugin_textdomain(
        'wpinv-stripe',
        false,
        plugin_dir_path( WPINV_STRIPE_FILE ) . 'languages/'
    );

}

// Load files.
require_once plugin_dir_path( WPINV_STRIPE_FILE ) . 'includes/stripe-functions.php';
require_once plugin_dir_path( WPINV_STRIPE_FILE ) . 'vendor/autoload.php';
