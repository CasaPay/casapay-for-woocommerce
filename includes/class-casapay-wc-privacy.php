<?php
/**
 * Suggested privacy policy text (Settings → Privacy → Policy guide).
 *
 * @package CasaPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the privacy policy suggestion.
 */
class Casapay_WC_Privacy {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/**
	 * Adds the suggested text to the privacy policy guide.
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'CasaPay for WooCommerce sends customer data to CasaPay when a customer pays with CasaPay. Review the text below and add it to your privacy policy.', 'casapay-for-woocommerce' ) . '</p>';
		$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'casapay-for-woocommerce' ) . ' </strong>';
		$content .= esc_html__( 'When you pay with CasaPay, we send your name, e-mail address, phone number, personal identification code (if you enter it), order number, ordered items and amounts to CasaPay. CasaPay uses this data to verify your identity, take the payment and, if you choose it, provide a deposit guarantee. On CasaPay\'s payment page you give your identity and payment details directly to CasaPay. CasaPay\'s privacy policy explains how they process this data.', 'casapay-for-woocommerce' );
		$content .= '</p>';

		wp_add_privacy_policy_content( 'CasaPay for WooCommerce', wp_kses_post( wpautop( $content, false ) ) );
	}
}
