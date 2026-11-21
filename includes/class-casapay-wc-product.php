<?php
/**
 * Product settings: which products are rentals sold through CasaPay, and how the deposit works.
 */

defined( 'ABSPATH' ) || exit;

class Casapay_WC_Product {

	const META_TYPE    = '_casapay_agreement_type';
	const META_COVER   = '_casapay_cover_amount';
	const META_OPTIONS = '_casapay_deposit_options';

	const MAX_COVER = 15000;

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_fields' ) );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 10, 2 );
	}

	public static function render_fields() {
		echo '<div class="options_group casapay-wc-product">';

		woocommerce_wp_select(
			array(
				'id'          => self::META_TYPE,
				'label'       => __( 'CasaPay agreement', 'casapay-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'The rental becomes a CasaPay agreement. A cart can hold one rental at a time.', 'casapay-for-woocommerce' ),
				'options'     => array(
					''             => __( '— Not a rental —', 'casapay-for-woocommerce' ),
					'ontime'       => __( 'OnTime: guarantee, payout on due date', 'casapay-for-woocommerce' ),
					'cover'        => __( 'Cover: guarantee, payout 30 days after due date', 'casapay-for-woocommerce' ),
					'payment_link' => __( 'Payment link: no guarantee', 'casapay-for-woocommerce' ),
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_COVER,
				/* translators: %s: currency symbol. */
				'label'       => sprintf( __( 'Deposit (%s)', 'casapay-for-woocommerce' ), get_woocommerce_currency_symbol() ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'OnTime and Cover only. Up to 15,000. Leave empty for no deposit.', 'casapay-for-woocommerce' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => self::META_OPTIONS,
				'label'       => __( 'Deposit options', 'casapay-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'What the customer can choose at checkout.', 'casapay-for-woocommerce' ),
				'options'     => array(
					'both'               => __( 'Customer chooses: deposit now or CasaPay guarantee', 'casapay-for-woocommerce' ),
					'deposit_upfront'    => __( 'Deposit paid now only', 'casapay-for-woocommerce' ),
					'deposit_guaranteed' => __( 'CasaPay guarantee only', 'casapay-for-woocommerce' ),
				),
			)
		);

		echo '</div>';
	}

	public static function save_fields( WC_Product $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
		$type    = isset( $_POST[ self::META_TYPE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_TYPE ] ) ) : '';
		$cover   = isset( $_POST[ self::META_COVER ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ self::META_COVER ] ) ) ) : '';
		$options = isset( $_POST[ self::META_OPTIONS ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_OPTIONS ] ) ) : 'both';
		// phpcs:enable

		if ( ! in_array( $type, array( '', 'ontime', 'cover', 'payment_link' ), true ) ) {
			$type = '';
		}
		if ( ! in_array( $options, array( 'both', 'deposit_upfront', 'deposit_guaranteed' ), true ) ) {
			$options = 'both';
		}
		if ( '' === $type || 'payment_link' === $type ) {
			$cover = '';
		}
		if ( '' !== $cover && (float) $cover > self::MAX_COVER ) {
			$cover = (string) self::MAX_COVER;
			WC_Admin_Meta_Boxes::add_error( __( 'The CasaPay deposit can be at most 15,000. The amount was lowered.', 'casapay-for-woocommerce' ) );
		}

		$product->update_meta_data( self::META_TYPE, $type );
		$product->update_meta_data( self::META_COVER, $cover );
		$product->update_meta_data( self::META_OPTIONS, $options );
	}

	/**
	 * Rental configuration of a product, or null when it is not a CasaPay rental.
	 *
	 * @return array{type:string,cover:float,modes:string[]}|null
	 */
	public static function get_config( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return null;
			}
		}

		$type = (string) $product->get_meta( self::META_TYPE );
		if ( '' === $type ) {
			return null;
		}

		$cover = in_array( $type, array( 'ontime', 'cover' ), true ) ? (float) $product->get_meta( self::META_COVER ) : 0.0;
		$modes = array();
		if ( $cover > 0 ) {
			$option = (string) $product->get_meta( self::META_OPTIONS );
			$modes  = 'deposit_upfront' === $option || 'deposit_guaranteed' === $option
				? array( $option )
				: array( 'deposit_upfront', 'deposit_guaranteed' );
		}

		return array(
			'type'  => $type,
			'cover' => min( $cover, self::MAX_COVER ),
			'modes' => $modes,
		);
	}

	public static function sold_individually( $sold_individually, $product ) {
		return self::get_config( $product ) ? true : $sold_individually;
	}
}
