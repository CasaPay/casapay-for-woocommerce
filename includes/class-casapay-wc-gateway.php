<?php
/**
 * The "CasaPay" payment method (classic checkout and order-pay page).
 */

defined( 'ABSPATH' ) || exit;

class Casapay_WC_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'casapay';
		$this->method_title       = 'CasaPay';
		$this->method_description = __( 'Sell rentals through CasaPay: identity verification, payment, and either a deposit or a CasaPay guarantee. The customer chooses the deposit option at checkout.', 'casapay-for-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                 => array(
				'title'   => __( 'Enable/Disable', 'casapay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CasaPay', 'casapay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                   => array(
				'title'   => __( 'Title at checkout', 'casapay-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'CasaPay',
			),
			'description'             => array(
				'title'   => __( 'Description at checkout', 'casapay-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely on CasaPay\'s page. Instead of a deposit you can choose a CasaPay guarantee.', 'casapay-for-woocommerce' ),
			),
			'webhook_info'            => array(
				'title'       => __( 'Webhook URL', 'casapay-for-woocommerce' ),
				'type'        => 'title',
				/* translators: %s: webhook URL. */
				'description' => sprintf( __( 'Give this address to CasaPay: %s', 'casapay-for-woocommerce' ), '<code>' . esc_html( rest_url( 'casapay/v1/webhook' ) ) . '</code>' ),
			),
			'testmode'                => array(
				'title'   => __( 'Test mode', 'casapay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use the sandbox (sk_test_ key)', 'casapay-for-woocommerce' ),
				'default' => 'yes',
			),
			'test_api_key'            => array(
				'title' => __( 'Test API key', 'casapay-for-woocommerce' ),
				'type'  => 'password',
			),
			'test_webhook_secret'     => array(
				'title' => __( 'Test webhook secret', 'casapay-for-woocommerce' ),
				'type'  => 'password',
			),
			'live_api_key'            => array(
				'title' => __( 'Live API key', 'casapay-for-woocommerce' ),
				'type'  => 'password',
			),
			'live_webhook_secret'     => array(
				'title' => __( 'Live webhook secret', 'casapay-for-woocommerce' ),
				'type'  => 'password',
			),
			'default_agreement_type'  => array(
				'title'       => __( 'Agreement type for other products', 'casapay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Used for orders without a CasaPay rental product. Rental products use the type set on the product.', 'casapay-for-woocommerce' ),
				'default'     => 'payment_link',
				'options'     => array(
					'payment_link' => 'payment_link',
					'ontime'       => 'ontime',
					'cover'        => 'cover',
				),
			),
			'verification_required'   => array(
				'title'   => __( 'Identity verification', 'casapay-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'entity',
				'options' => array(
					'entity' => __( 'CasaPay account default', 'casapay-for-woocommerce' ),
					'force'  => __( 'Always', 'casapay-for-woocommerce' ),
					'skip'   => __( 'Never', 'casapay-for-woocommerce' ),
				),
			),
			'allowed_payment_methods' => array(
				'title'       => __( 'Allowed payment methods', 'casapay-for-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Leave empty to use the CasaPay account default.', 'casapay-for-woocommerce' ),
				'default'     => array(),
				'options'     => array(
					'card'          => __( 'Card', 'casapay-for-woocommerce' ),
					'bank_transfer' => __( 'Bank transfer', 'casapay-for-woocommerce' ),
					'open_banking'  => __( 'Open banking', 'casapay-for-woocommerce' ),
				),
			),
			'test_base_url'           => array(
				'title'       => __( 'Test API URL', 'casapay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => Casapay_WC_Api::TEST_URL,
				'description' => __( 'Leave empty unless CasaPay gave you a different address.', 'casapay-for-woocommerce' ),
			),
			'live_base_url'           => array(
				'title'       => __( 'Live API URL', 'casapay-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => Casapay_WC_Api::LIVE_URL,
			),
		);
	}

	public function is_available() {
		return parent::is_available()
			&& Casapay_WC_Api::from_settings()->is_configured()
			&& in_array( get_woocommerce_currency(), array( 'EUR', 'GBP' ), true );
	}

	public function payment_scripts() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}
		wp_enqueue_script( 'casapay-wc-checkout', CASAPAY_WC_URL . 'assets/js/checkout.js', array( 'jquery', 'wc-checkout' ), CASAPAY_WC_VERSION, true );
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		if ( is_checkout_pay_page() || ! WC()->cart ) {
			return;
		}

		$options  = Casapay_WC_Cart::get_options( WC()->cart );
		$selected = Casapay_WC_Cart::selected_mode( $options );
		if ( ! $options ) {
			return;
		}

		echo '<fieldset class="casapay-wc-options"><legend>' . esc_html__( 'Deposit', 'casapay-for-woocommerce' ) . '</legend>';
		foreach ( $options as $mode => $option ) {
			printf(
				'<p class="form-row"><label><input type="radio" name="%1$s" value="%2$s" %3$s> <strong>%4$s</strong><br><small>%5$s</small></label></p>',
				esc_attr( Casapay_WC_Cart::FIELD ),
				esc_attr( $mode ),
				checked( $selected, $mode, false ),
				esc_html( $option['label'] ),
				esc_html( $option['summary'] )
			);
		}
		echo '</fieldset>';
	}

	public function validate_fields() {
		$rental = Casapay_WC_Cart::get_rental();
		if ( $rental && $rental['config']['cover'] > 0 && ! Casapay_WC_Cart::get_options( WC()->cart ) ) {
			wc_add_notice( __( 'We could not get the CasaPay guarantee price right now. Please try again in a few minutes.', 'casapay-for-woocommerce' ), 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		if ( ! Casapay_WC_Order::lock( $order_id ) ) {
			wc_add_notice( __( 'Your CasaPay payment is already being started. Please wait a moment and try again.', 'casapay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		try {
			return $this->start_payment( wc_get_order( $order_id ) );
		} finally {
			Casapay_WC_Order::unlock( $order_id );
		}
	}

	/**
	 * One open CasaPay session per order: reuse it when nothing changed, replace it only
	 * once CasaPay has confirmed it is cancelled, and never start a new one after a payment.
	 */
	private function start_payment( WC_Order $order ) {
		$api     = Casapay_WC_Api::from_settings();
		$failure = array( 'result' => 'failure' );

		$built = Casapay_WC_Order::build_session( $order );
		if ( is_wp_error( $built ) ) {
			wc_add_notice( $built->get_error_message(), 'error' );
			return $failure;
		}

		$previous = (string) $order->get_meta( Casapay_WC_Order::META_SESSION );
		if ( '' !== $previous ) {
			$existing = $api->get_session( $previous );

			if ( is_wp_error( $existing ) ) {
				$data = $existing->get_error_data();
				// Unknown to CasaPay (e.g. created with the other mode's key): nothing to reuse or cancel.
				if ( 404 !== (int) ( $data['status'] ?? 0 ) ) {
					return $this->payment_error( $existing );
				}
			} else {
				$status = (string) ( $existing['status'] ?? '' );

				if ( 'completed' === $status ) {
					return $this->already_paid( $order );
				}

				if ( in_array( $status, array( 'pending', 'processing' ), true ) ) {
					$url = (string) $order->get_meta( Casapay_WC_Order::META_GATEWAY_URL );
					if ( '' !== $url && hash_equals( (string) $order->get_meta( Casapay_WC_Order::META_FINGERPRINT ), $built['fingerprint'] ) ) {
						casapay_wc_log( sprintf( 'Order %d: reusing open session %s.', $order->get_id(), $previous ) );
						return array(
							'result'   => 'success',
							'redirect' => $url,
						);
					}

					// The order changed (amount, deposit option, customer details): replace the session,
					// but only once CasaPay confirms the old one can no longer be paid.
					$cancelled = $api->cancel_session( $previous, 'Replaced by a new session for the same order' );
					if ( is_wp_error( $cancelled ) ) {
						$data = $cancelled->get_error_data();
						if ( 'CANCEL_FAILED' === ( $data['code'] ?? '' ) ) {
							return $this->already_paid( $order );
						}
						return $this->payment_error( $cancelled );
					}
				}
				// expired / cancelled / failed: the old session is dead, start a new one.
			}
		}

		$session = $api->create_session( $built['payload'] );
		if ( is_wp_error( $session ) || empty( $session['gateway_url'] ) ) {
			$message = __( 'We could not start the CasaPay payment. Please try again or contact us.', 'casapay-for-woocommerce' );
			if ( is_wp_error( $session ) && casapay_wc_is_test_mode() ) {
				$message .= ' [' . $session->get_error_message() . ']';
			}
			wc_add_notice( $message, 'error' );
			return array( 'result' => 'failure' );
		}

		Casapay_WC_Order::remember_session( $order, $built, $session );
		Casapay_WC_Order::schedule( 'casapay_wc_poll_session', $order->get_id(), 5 * MINUTE_IN_SECONDS );

		return array(
			'result'   => 'success',
			'redirect' => $session['gateway_url'],
		);
	}

	/**
	 * The previous session took (or is taking) the money. Never start another one:
	 * sync the order and send the customer to the thank-you page, which keeps checking.
	 */
	private function already_paid( WC_Order $order ) {
		Casapay_WC_Order::sync( $order );
		$order->add_order_note( __( 'A new CasaPay payment was not started because the existing CasaPay session has already been paid.', 'casapay-for-woocommerce' ) );
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	private function payment_error( WP_Error $error ) {
		$message = __( 'We could not start the CasaPay payment. Please try again or contact us.', 'casapay-for-woocommerce' );
		if ( casapay_wc_is_test_mode() ) {
			$message .= ' [' . $error->get_error_message() . ']';
		}
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	/** The redirect proves nothing: ask CasaPay, and keep showing "processing" until it confirms. */
	public function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->needs_payment() ) {
			return;
		}

		Casapay_WC_Order::sync( $order );
		$order = wc_get_order( $order_id );
		if ( ! $order->needs_payment() ) {
			return;
		}

		if ( $order->has_status( array( 'cancelled', 'failed' ) ) ) {
			echo '<div class="woocommerce-error">' . esc_html__( 'The payment was not completed. You can place the order again.', 'casapay-for-woocommerce' ) . '</div>';
			return;
		}

		printf(
			'<div class="woocommerce-info casapay-wc-processing" data-order-id="%1$d">%2$s</div>',
			(int) $order_id,
			esc_html__( 'Waiting for CasaPay to confirm your payment. This page refreshes on its own.', 'casapay-for-woocommerce' )
		);
		wp_enqueue_script( 'casapay-wc-thankyou', CASAPAY_WC_URL . 'assets/js/thankyou.js', array(), CASAPAY_WC_VERSION, true );
	}
}
