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
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation_field' ), 10, 2 );
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

	/** Deposit per variation, e.g. one listing with a 500 and a 1000 room. Empty = the product's deposit. */
	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation );
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_COVER . '_' . $loop,
				'name'          => self::META_COVER . '[' . $loop . ']',
				'value'         => $product ? (string) $product->get_meta( self::META_COVER ) : '',
				/* translators: %s: currency symbol. */
				'label'         => sprintf( __( 'CasaPay deposit (%s)', 'casapay-for-woocommerce' ), get_woocommerce_currency_symbol() ),
				'data_type'     => 'price',
				'desc_tip'      => true,
				'description'   => __( 'Leave empty to use the deposit set on the product. 0 means no deposit for this variation.', 'casapay-for-woocommerce' ),
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}

	public static function save_variation_field( $variation, $loop ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the save-variations nonce.
		if ( ! isset( $_POST[ self::META_COVER ] ) || ! is_array( $_POST[ self::META_COVER ] ) ) {
			return;
		}
		$raw = isset( $_POST[ self::META_COVER ][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_COVER ][ $loop ] ) ) : '';
		// phpcs:enable
		$cover = '' === $raw ? '' : wc_format_decimal( $raw );
		if ( '' !== $cover && (float) $cover > self::MAX_COVER ) {
			$cover = (string) self::MAX_COVER;
		}
		$variation->update_meta_data( self::META_COVER, $cover ); // WooCommerce saves the variation after this hook.
	}

	/**
	 * Rental configuration of a product, or null when it is not a CasaPay rental.
	 * A variation uses its own deposit when set, otherwise the parent's.
	 *
	 * @return array{type:string,cover:float,options:string,modes:string[]}|null
	 */
	public static function get_config( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$variation_cover = '';
		if ( $product->is_type( 'variation' ) ) {
			$variation_cover = (string) $product->get_meta( self::META_COVER );
			$product         = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return null;
			}
		}

		$type = (string) $product->get_meta( self::META_TYPE );
		if ( '' === $type ) {
			return null;
		}

		$cover = '' !== $variation_cover ? $variation_cover : $product->get_meta( self::META_COVER );
		return self::make_config( $type, (float) $cover, (string) $product->get_meta( self::META_OPTIONS ) );
	}

	/**
	 * Normalised rental configuration. The modes follow from the cover: no cover, nothing to choose.
	 *
	 * @param string $type    Agreement type.
	 * @param float  $cover   Cover (deposit) amount.
	 * @param string $options Deposit options setting: both | deposit_upfront | deposit_guaranteed.
	 * @return array{type:string,cover:float,options:string,modes:string[]}
	 */
	public static function make_config( $type, $cover, $options ) {
		$type    = (string) $type;
		$options = in_array( $options, array( 'deposit_upfront', 'deposit_guaranteed' ), true ) ? $options : 'both';
		$cover   = in_array( $type, array( 'ontime', 'cover' ), true ) ? min( max( 0.0, round( (float) $cover, 2 ) ), (float) self::MAX_COVER ) : 0.0;

		$modes = array();
		if ( $cover > 0 ) {
			$modes = 'both' === $options ? array( 'deposit_upfront', 'deposit_guaranteed' ) : array( $options );
		}

		return array(
			'type'    => $type,
			'cover'   => $cover,
			'options' => $options,
			'modes'   => $modes,
		);
	}

	public static function sold_individually( $sold_individually, $product ) {
		return self::get_config( $product ) ? true : $sold_individually;
	}
}
