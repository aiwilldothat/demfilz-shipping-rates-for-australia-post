<?php
/**
 * Plugin Name:       Demfilz Shipping Rates for Australia Post
 * Description:       Live Australia Post shipping rate calculation for WooCommerce, with an admin settings page for the API key and configurable options (origin, packaging, handling fees, markup, free shipping, fallback rates and caching).
 * Version:           1.0.0
 * Author:            demfilz
 * Author URI:        https://demfilz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       demfilz-shipping-rates-for-australia-post
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 * WC requires at least: 5.0
 * WC tested up to:   9.5
 *
 * @package DSRA
 */

defined( 'ABSPATH' ) || exit;

define( 'DSRA_VERSION', '1.0.0' );
define( 'DSRA_FILE', __FILE__ );
define( 'DSRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DSRA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
 * and the cart/checkout blocks. This shipping method works through the standard
 * shipping API, so it is compatible with both.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DSRA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DSRA_FILE, true );
		}
	}
);

/**
 * Bootstrap the plugin once all plugins are loaded so we can verify WooCommerce
 * is present.
 */
add_action( 'plugins_loaded', 'dsra_init', 11 );

/**
 * Initialise the plugin.
 */
function dsra_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'dsra_missing_wc_notice' );
		return;
	}

	require_once DSRA_PATH . 'includes/class-dsra-api.php';
	require_once DSRA_PATH . 'includes/class-dsra-account-api.php';
	require_once DSRA_PATH . 'includes/class-dsra-settings.php';

	// Boot the admin settings page (WooCommerce > Settings > Australia Post).
	DSRA_Settings::instance();

	// Register the shipping method so it can be added to shipping zones.
	add_action( 'woocommerce_shipping_init', 'dsra_shipping_init' );
	add_filter( 'woocommerce_shipping_methods', 'dsra_add_method' );

	// Add a "Settings" link on the Plugins screen.
	add_filter( 'plugin_action_links_' . plugin_basename( DSRA_FILE ), 'dsra_action_links' );
}

/**
 * Load the shipping method class. Hooked late on woocommerce_shipping_init so the
 * parent WC_Shipping_Method class is available.
 */
function dsra_shipping_init() {
	require_once DSRA_PATH . 'includes/class-dsra-shipping.php';
}

/**
 * Register the shipping method with WooCommerce.
 *
 * @param array $methods Registered shipping methods.
 * @return array
 */
function dsra_add_method( $methods ) {
	$methods['auspost'] = 'DSRA_Shipping';
	return $methods;
}

/**
 * Add a settings shortcut to the plugin row.
 *
 * @param array $links Existing action links.
 * @return array
 */
function dsra_action_links( $links ) {
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=auspost' );
	$links[]      = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'demfilz-shipping-rates-for-australia-post' ) . '</a>';
	return $links;
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function dsra_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Demfilz Shipping Rates for Australia Post requires WooCommerce to be installed and active.', 'demfilz-shipping-rates-for-australia-post' );
	echo '</p></div>';
}
