<?php
/**
 * CasaPay in the block-based (Cart & Checkout blocks) checkout.
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Casapay_WC_Blocks extends AbstractPaymentMethodType {

	protected $name = 'casapay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_casapay_settings', array() );
	}

	public function is_active() {
		return ( $this->settings['enabled'] ?? 'no' ) === 'yes';
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'casapay-wc-blocks',
			CASAPAY_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wc-blocks-checkout', 'wp-element', 'wp-html-entities' ),
			CASAPAY_WC_VERSION,
			true
		);
		return array( 'casapay-wc-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->settings['title'] ?? 'CasaPay',
			'description' => $this->settings['description'] ?? '',
			'legend'      => __( 'Deposit', 'casapay-for-woocommerce' ),
			'supports'    => array( 'products' ),
		);
	}
}
