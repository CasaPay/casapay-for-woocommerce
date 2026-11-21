<?php
/**
 * Helpers and the public API for other plugins.
 * See DEVELOPERS.md for how to use the public functions and hooks.
 *
 * @package CasaPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------
 * Internal helpers
 * ------------------------------------------------------------------- */

/**
 * Gateway settings.
 *
 * @return array
 */
function casapay_wc_settings() {
	$settings = get_option( 'woocommerce_casapay_settings', array() );
	return is_array( $settings ) ? $settings : array();
}

/**
 * Whether the sandbox is used.
 *
 * @return bool
 */
function casapay_wc_is_test_mode() {
	$settings = casapay_wc_settings();
	return ( $settings['testmode'] ?? 'yes' ) === 'yes';
}

/**
 * Webhook secret for the active mode.
 *
 * @return string
 */
function casapay_wc_webhook_secret() {
	$settings = casapay_wc_settings();
	$key      = casapay_wc_is_test_mode() ? 'test_webhook_secret' : 'live_webhook_secret';
	return (string) ( $settings[ $key ] ?? '' );
}

/**
 * Writes to WooCommerce → Status → Logs → casapay.
 *
 * @param string $message Message.
 * @param string $level   PSR-3 level.
 * @param array  $context Extra context.
 */
function casapay_wc_log( $message, $level = 'info', array $context = array() ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}
	wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'casapay' ), $context ) );
}

/**
 * Plain-text price, e.g. "2,912.00 €".
 *
 * @param float  $amount   Amount.
 * @param string $currency Currency code.
 * @return string
 */
function casapay_wc_money( $amount, $currency = '' ) {
	$args = $currency ? array( 'currency' => $currency ) : array();
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
}

/* ---------------------------------------------------------------------
 * Public API (stable, documented in DEVELOPERS.md)
 * ------------------------------------------------------------------- */

/**
 * CasaPay agreement data stored on an order.
 *
 * @param int|WC_Order $order Order or order ID.
 * @return array|null Null when the order was not paid with CasaPay or the agreement is not known yet.
 */
function casapay_wc_get_order_agreement( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order || $order->get_payment_method() !== 'casapay' ) {
		return null;
	}

	$payment_agreement_id = $order->get_meta( Casapay_WC_Order::META_AGREEMENT_ID );
	if ( ! $payment_agreement_id ) {
		return null;
	}

	return array(
		'payment_agreement_id' => (int) $payment_agreement_id,
		'agreement_id'         => (string) $order->get_meta( Casapay_WC_Order::META_AGREEMENT_REF ),
		'agreement_type'       => (string) $order->get_meta( Casapay_WC_Order::META_TYPE ),
		'email_alias'          => $order->get_meta( Casapay_WC_Order::META_ALIAS ) ? $order->get_meta( Casapay_WC_Order::META_ALIAS ) : null,
		'deposit_mode'         => $order->get_meta( Casapay_WC_Order::META_RESOLVED ) ? $order->get_meta( Casapay_WC_Order::META_RESOLVED ) : ( $order->get_meta( Casapay_WC_Order::META_MODE ) ? $order->get_meta( Casapay_WC_Order::META_MODE ) : null ),
		'guarantee_id'         => $order->get_meta( Casapay_WC_Order::META_GUARANTEE_ID ) ? $order->get_meta( Casapay_WC_Order::META_GUARANTEE_ID ) : null,
		'session_id'           => (string) $order->get_meta( Casapay_WC_Order::META_SESSION ),
	);
}

/**
 * The CasaPay e-mail alias (e.g. johndoe-abc123@casapay.me) for the agreement created by this order.
 *
 * @param int|WC_Order $order            Order or order ID.
 * @param bool         $fetch_if_missing Ask CasaPay right now if the alias is not stored yet (one HTTP call).
 * @return string|null
 */
function casapay_wc_get_order_email_alias( $order, $fetch_if_missing = false ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order || $order->get_payment_method() !== 'casapay' ) {
		return null;
	}

	$alias = $order->get_meta( Casapay_WC_Order::META_ALIAS );
	if ( ! $alias && $fetch_if_missing && $order->get_meta( Casapay_WC_Order::META_AGREEMENT_ID ) ) {
		Casapay_WC_Order::fetch_agreement( $order->get_id(), false );
		$order = wc_get_order( $order->get_id() );
		$alias = $order ? $order->get_meta( Casapay_WC_Order::META_ALIAS ) : '';
	}

	return $alias ? (string) $alias : null;
}

/**
 * All CasaPay aliases of one customer, keyed by order ID (newest first).
 *
 * @param int $user_id Customer user ID.
 * @return array<int,string>
 */
function casapay_wc_get_customer_email_aliases( $user_id ) {
	$orders = wc_get_orders(
		array(
			'customer_id'    => (int) $user_id,
			'payment_method' => 'casapay',
			'status'         => array( 'processing', 'completed', 'on-hold' ),
			'limit'          => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	$aliases = array();
	foreach ( $orders as $order ) {
		$alias = $order->get_meta( Casapay_WC_Order::META_ALIAS );
		if ( $alias ) {
			$aliases[ $order->get_id() ] = (string) $alias;
		}
	}
	return $aliases;
}
