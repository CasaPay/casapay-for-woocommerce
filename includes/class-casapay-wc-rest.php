<?php
/**
 * REST routes:
 *   POST /wp-json/casapay/v1/webhook                   CasaPay -> WooCommerce (HMAC signed)
 *   GET  /wp-json/casapay/v1/orders/{id}/agreement     agreement + e-mail alias for other systems (manage_woocommerce)
 */

defined( 'ABSPATH' ) || exit;

class Casapay_WC_Rest {

	const TIMESTAMP_TOLERANCE = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'casapay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by the HMAC signature below.
			)
		);

		register_rest_route(
			'casapay/v1',
			'/orders/(?P<id>\d+)/agreement',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'order_agreement' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	public static function webhook( WP_REST_Request $request ) {
		$raw       = $request->get_body();
		$timestamp = (string) $request->get_header( 'x-casapay-timestamp' );
		$signature = (string) $request->get_header( 'x-casapay-signature' );
		$secret    = casapay_wc_webhook_secret();

		if ( '' === $secret ) {
			casapay_wc_log( 'Webhook received but no webhook secret is configured.', 'error' );
			return new WP_REST_Response( array( 'error' => 'not_configured' ), 503 );
		}
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			casapay_wc_log( 'Webhook rejected: timestamp missing or outside 5 minutes.', 'warning' );
			return new WP_REST_Response( array( 'error' => 'invalid_timestamp' ), 401 );
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '?';
			casapay_wc_log( 'Webhook rejected: invalid signature from ' . $ip, 'critical' );
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['session_id'] ) || empty( $event['event'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		$session_id = (string) $event['session_id'];
		$type       = (string) $event['event'];
		$order      = self::find_order( $event );
		if ( ! $order ) {
			casapay_wc_log( sprintf( 'Webhook %s for unknown session %s ignored.', $type, $session_id ), 'warning' );
			return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}
		if ( Casapay_WC_Order::event_seen( $order, $session_id, $type ) ) {
			return new WP_REST_Response( array( 'status' => 'already_processed' ), 200 );
		}

		switch ( $type ) {
			case 'gateway.session.completed':
			case 'gateway.session.expired':
			case 'gateway.session.cancelled':
				// Never act on the webhook body alone: confirm with GET /sessions/{id}.
				$result = Casapay_WC_Order::sync( $order );
				if ( is_wp_error( $result ) ) {
					return new WP_REST_Response( array( 'error' => 'sync_failed' ), 500 ); // CasaPay retries.
				}
				break;

			case 'gateway.payment.failed':
				$failure = (array) ( $event['failure'] ?? array() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: failure reason code, 2: attempt number. */
						__( 'CasaPay payment did not go through (%1$s, attempt %2$d). The customer can try again on the same link.', 'casapay-for-woocommerce' ),
						$failure['reason'] ?? 'unknown',
						(int) ( $failure['retry_count'] ?? 0 )
					)
				);
				break;

			case 'gateway.verification.pending':
				$order->add_order_note( __( 'CasaPay: identity verification is waiting for manual review.', 'casapay-for-woocommerce' ) );
				break;

			case 'gateway.verification.approved':
				$order->add_order_note( __( 'CasaPay: identity verification approved.', 'casapay-for-woocommerce' ) );
				break;

			case 'gateway.verification.failed':
				$order->add_order_note( __( 'CasaPay: identity verification failed.', 'casapay-for-woocommerce' ) );
				break;

			case 'gateway.guarantee.activated':
				if ( ! empty( $event['guarantee']['guarantee_id'] ) ) {
					$order->update_meta_data( Casapay_WC_Order::META_GUARANTEE_ID, (string) $event['guarantee']['guarantee_id'] );
					$order->save();
				}
				$order->add_order_note( __( 'CasaPay guarantee activated.', 'casapay-for-woocommerce' ) );
				break;
		}

		Casapay_WC_Order::record_event( wc_get_order( $order->get_id() ), $session_id, $type );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function find_order( array $event ) {
		$session_id = (string) $event['session_id'];

		$order_id = isset( $event['metadata']['order_id'] ) ? absint( $event['metadata']['order_id'] ) : 0;
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && $order->get_meta( Casapay_WC_Order::META_SESSION ) === $session_id ) {
				return $order;
			}
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Casapay_WC_Order::META_SESSION,
						'value' => $session_id,
					),
				),
			)
		);
		return $orders ? $orders[0] : null;
	}

	public static function order_agreement( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'order_not_found' ), 404 );
		}
		casapay_wc_get_order_email_alias( $order, true );
		$agreement = casapay_wc_get_order_agreement( $order->get_id() );
		if ( ! $agreement ) {
			return new WP_REST_Response( array( 'error' => 'no_casapay_agreement' ), 404 );
		}
		return new WP_REST_Response( array_merge( array( 'order_id' => $order->get_id() ), $agreement ), 200 );
	}
}
