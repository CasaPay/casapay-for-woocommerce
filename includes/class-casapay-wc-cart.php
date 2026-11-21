<?php
/**
 * Deposit choice in the WooCommerce checkout.
 *
 * The customer picks "deposit now" or "CasaPay guarantee" here, before the order exists,
 * so the order total always equals what CasaPay charges. The choice adds one fee line:
 *   deposit_upfront    -> "Deposit"                         = cover_amount
 *   deposit_guaranteed -> "CasaPay guarantee, first month"  = due_today - first payment (from pricing-preview)
 * The session is then created with that fixed deposit_mode, never "choice".
 */

defined( 'ABSPATH' ) || exit;

class Casapay_WC_Cart {

	const SESSION_KEY   = 'casapay_deposit_mode';
	const FIELD         = 'casapay_deposit_mode';
	const FEE_PREFIX    = 'casapay_';
	const FEE_ITEM_META = '_casapay_fee';

	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fees' ), 20 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_gateways' ), 100 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'from_order_review' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'from_checkout_post' ) );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( __CLASS__, 'tag_fee_item' ), 10, 4 );
		// The plugin boots on plugins_loaded:11, after WooCommerce has usually fired woocommerce_blocks_loaded.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		}
	}

	/** The single rental line in the cart. */
	public static function get_rental( $cart = null ) {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return null;
		}
		foreach ( $cart->get_cart() as $item ) {
			$config = Casapay_WC_Product::get_config( $item['data'] ?? null );
			if ( $config ) {
				return array(
					'product' => $item['data'],
					'config'  => $config,
				);
			}
		}
		return null;
	}

	private static function count_rentals( $cart ) {
		$count = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( Casapay_WC_Product::get_config( $item['data'] ?? null ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** What the customer pays for the order excluding CasaPay fee lines. */
	private static function base_amount( $cart ) {
		return round(
			(float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax()
			+ (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax(),
			2
		);
	}

	/**
	 * Deposit options for the current cart, keyed by mode. Empty when there is nothing to choose.
	 *
	 * @return array<string,array{mode:string,label:string,fee:float,fee_label:string,due_today:float,monthly:?float,summary:string}>
	 */
	public static function get_options( $cart = null ) {
		$cart   = $cart ? $cart : WC()->cart;
		$rental = $cart ? self::get_rental( $cart ) : null;
		if ( ! $rental || empty( $rental['config']['modes'] ) ) {
			return array();
		}

		$cover    = $rental['config']['cover'];
		$first    = self::base_amount( $cart );
		$currency = get_woocommerce_currency();
		$options  = array();

		foreach ( $rental['config']['modes'] as $mode ) {
			if ( 'deposit_upfront' === $mode ) {
				$options[ $mode ] = array(
					'mode'      => $mode,
					'label'     => __( 'Pay the deposit now', 'casapay-for-woocommerce' ),
					'fee'       => $cover,
					'fee_label' => __( 'Deposit', 'casapay-for-woocommerce' ),
					'due_today' => round( $first + $cover, 2 ),
					'monthly'   => null,
				);
			} elseif ( 'deposit_guaranteed' === $mode ) {
				$option = self::guarantee_option( $cover, $first, $currency );
				if ( $option ) {
					$options[ $mode ] = $option;
				}
			}
		}

		foreach ( $options as $mode => $option ) {
			/* translators: %s: amount due today. */
			$summary = sprintf( __( 'Due today %s', 'casapay-for-woocommerce' ), casapay_wc_money( $option['due_today'] ) );
			if ( $option['monthly'] ) {
				/* translators: %s: monthly guarantee fee. */
				$summary .= ' · ' . sprintf( __( 'then %s per month', 'casapay-for-woocommerce' ), casapay_wc_money( $option['monthly'] ) );
			}
			$options[ $mode ]['summary'] = $summary;
		}

		return $options;
	}

	private static function guarantee_option( $cover, $first, $currency ) {
		$preview = self::pricing_preview( $cover, $first, $currency );
		if ( ! $preview ) {
			return null;
		}
		foreach ( (array) ( $preview['deposit_options'] ?? array() ) as $row ) {
			if ( ( $row['mode'] ?? '' ) !== 'deposit_guaranteed' ) {
				continue;
			}
			$due_today = (float) ( $row['due_today'] ?? 0 );
			$fee       = max( 0, round( $due_today - $first, 2 ) );
			return array(
				'mode'      => 'deposit_guaranteed',
				'label'     => __( 'CasaPay guarantee instead of a deposit', 'casapay-for-woocommerce' ),
				'fee'       => $fee,
				'fee_label' => __( 'CasaPay guarantee, first month', 'casapay-for-woocommerce' ),
				'due_today' => round( $first + $fee, 2 ),
				'monthly'   => isset( $row['subscription']['monthly_amount'] ) ? (float) $row['subscription']['monthly_amount'] : null,
			);
		}
		return null;
	}

	/** Cached pricing-preview call. Returns null when CasaPay cannot price the guarantee right now. */
	private static function pricing_preview( $cover, $first, $currency ) {
		$key    = 'casapay_pp_' . md5( wp_json_encode( array( casapay_wc_is_test_mode(), $cover, $first, $currency ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached['ok'] ? $cached['data'] : null;
		}

		$result = Casapay_WC_Api::from_settings()->pricing_preview( $cover, $first, $currency );
		if ( is_wp_error( $result ) ) {
			set_transient( $key, array( 'ok' => false ), MINUTE_IN_SECONDS );
			return null;
		}
		set_transient(
			$key,
			array(
				'ok'   => true,
				'data' => $result,
			),
			6 * HOUR_IN_SECONDS
		);
		return $result;
	}

	public static function selected_mode( array $options ) {
		if ( ! $options ) {
			return null;
		}
		$mode = ( WC()->session ) ? WC()->session->get( self::SESSION_KEY ) : null;
		if ( $mode && isset( $options[ $mode ] ) ) {
			return $mode;
		}
		$default = apply_filters( 'casapay_wc_default_deposit_mode', 'deposit_upfront', $options );
		return isset( $options[ $default ] ) ? $default : array_key_first( $options );
	}

	public static function set_mode( $mode ) {
		if ( WC()->session && in_array( $mode, array( 'deposit_upfront', 'deposit_guaranteed' ), true ) ) {
			WC()->session->set( self::SESSION_KEY, $mode );
		}
	}

	public static function add_fees( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() && ! WC()->is_rest_api_request() ) {
			return;
		}
		$options = self::get_options( $cart );
		$mode    = self::selected_mode( $options );
		if ( ! $mode ) {
			return;
		}
		$option = $options[ $mode ];
		$cart->fees_api()->add_fee(
			array(
				'id'      => self::FEE_PREFIX . $mode,
				'name'    => $option['fee_label'],
				'amount'  => $option['fee'],
				'taxable' => false,
			)
		);
	}

	/** Marks the order fee line with the deposit mode; the gateway reads the mode from here. */
	public static function tag_fee_item( $item, $fee_key, $fee, $order ) {
		$id = isset( $fee->id ) ? (string) $fee->id : (string) $fee_key;
		if ( strpos( $id, self::FEE_PREFIX ) === 0 ) {
			$item->add_meta_data( self::FEE_ITEM_META, substr( $id, strlen( self::FEE_PREFIX ) ), true );
		}
	}

	public static function validate_add_to_cart( $passed, $product_id ) {
		if ( ! $passed || ! WC()->cart ) {
			return $passed;
		}
		$product = wc_get_product( $product_id );
		if ( ! Casapay_WC_Product::get_config( $product ) ) {
			return $passed;
		}
		$existing = self::get_rental();
		if ( $existing && $existing['product']->get_id() !== $product->get_id() && $existing['product']->get_parent_id() !== $product->get_id() ) {
			wc_add_notice( __( 'Your cart can hold one rental. Remove the current one before adding another.', 'casapay-for-woocommerce' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function check_cart_items() {
		if ( WC()->cart && self::count_rentals( WC()->cart ) > 1 ) {
			wc_add_notice( __( 'Your cart can hold one rental. Remove the extra ones before paying.', 'casapay-for-woocommerce' ), 'error' );
		}
	}

	/** A rental can only be paid through CasaPay. */
	public static function limit_gateways( $gateways ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! WC()->cart || ! self::get_rental() ) {
			return $gateways;
		}
		return isset( $gateways['casapay'] ) ? array( 'casapay' => $gateways['casapay'] ) : array();
	}

	public static function from_order_review( $post_data ) {
		parse_str( (string) $post_data, $data );
		if ( isset( $data[ self::FIELD ] ) ) {
			self::set_mode( sanitize_key( $data[ self::FIELD ] ) );
		}
	}

	public static function from_checkout_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		if ( isset( $_POST[ self::FIELD ] ) ) {
			self::set_mode( sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/* Blocks checkout: expose options on the Store API cart and accept the choice back. */

	public static function register_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'casapay',
				'data_callback'   => array( __CLASS__, 'store_api_data' ),
				'schema_callback' => array( __CLASS__, 'store_api_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'casapay',
				'callback'  => function ( $data ) {
					if ( isset( $data['deposit_mode'] ) ) {
						self::set_mode( sanitize_key( $data['deposit_mode'] ) );
					}
				},
			)
		);
	}

	public static function store_api_data() {
		$options = WC()->cart ? self::get_options( WC()->cart ) : array();
		return array(
			'options'  => array_values(
				array_map(
					function ( $o ) {
						return array(
							'mode'    => $o['mode'],
							'label'   => $o['label'],
							'summary' => $o['summary'],
						);
					},
					$options
				)
			),
			'selected' => self::selected_mode( $options ),
		);
	}

	public static function store_api_schema() {
		return array(
			'options'  => array(
				'description' => 'CasaPay deposit options for this cart.',
				'type'        => 'array',
				'readonly'    => true,
			),
			'selected' => array(
				'description' => 'Selected deposit mode.',
				'type'        => array( 'string', 'null' ),
				'readonly'    => true,
			),
		);
	}
}
