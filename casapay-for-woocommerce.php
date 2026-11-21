<?php
/**
 * Plugin Name:          CasaPay for WooCommerce
 * Plugin URI:           https://github.com/CasaPay/casapay-for-woocommerce
 * Description:          Sell rentals with CasaPay. At checkout the tenant chooses between paying the security deposit or a CasaPay deposit guarantee, then pays on CasaPay's hosted page.
 * Version:              0.1.0
 * Author:               CasaPay
 * Author URI:           https://casapay.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.9
 * Text Domain:          casapay-for-woocommerce
 * Domain Path:          /languages
 *
 * @package CasaPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'CASAPAY_WC_VERSION', '0.1.0' );
define( 'CASAPAY_WC_FILE', __FILE__ );
define( 'CASAPAY_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CASAPAY_WC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CASAPAY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CASAPAY_WC_FILE, true );
		}
	}
);

add_action(
	'init',
	function () {
		// Bundled translations; language packs from translate.wordpress.org take precedence when installed.
		load_plugin_textdomain( 'casapay-for-woocommerce', false, dirname( plugin_basename( CASAPAY_WC_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action( 'plugins_loaded', 'casapay_wc_init', 11 );

/**
 * Boots the plugin once WooCommerce is loaded.
 */
function casapay_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once CASAPAY_WC_DIR . 'includes/functions.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-api.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-product.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-cart.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-order.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-rest.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-privacy.php';
	require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-gateway.php';

	Casapay_WC_Product::init();
	Casapay_WC_Cart::init();
	Casapay_WC_Order::init();
	Casapay_WC_Rest::init();
	Casapay_WC_Privacy::init();

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'Casapay_WC_Gateway';
			return $gateways;
		}
	);

	add_filter(
		'plugin_action_links_' . plugin_basename( CASAPAY_WC_FILE ),
		function ( $links ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=casapay' );
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'casapay-for-woocommerce' ) . '</a>' );
			return $links;
		}
	);
}

add_action(
	'woocommerce_blocks_payment_method_type_registration',
	function ( $registry ) {
		require_once CASAPAY_WC_DIR . 'includes/class-casapay-wc-blocks.php';
		$registry->register( new Casapay_WC_Blocks() );
	}
);
