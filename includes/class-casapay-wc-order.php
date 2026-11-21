<?php
/**
 * Order <-> CasaPay session: payload, state sync, polling fallback, agreement/alias lookup.
 *
 * Every status change goes through sync(), which reads GET /sessions/{id}. Webhooks, the
 * polling job and the thank-you page only trigger a sync; none of them is trusted on its own.
 */

defined( 'ABSPATH' ) || exit;

class Casapay_WC_Order {

	const META_SESSION        = '_casapay_session_id';
	const META_GATEWAY_URL    = '_casapay_gateway_url';
	const META_ATTEMPTS       = '_casapay_attempts';
	const META_MODE           = '_casapay_deposit_mode';
	const META_TYPE           = '_casapay_agreement_type';
	const META_COVER          = '_casapay_cover_amount';
	const META_FIRST          = '_casapay_first_payment_amount';
	const META_GUARANTEE_FEE  = '_casapay_guarantee_fee';
	const META_AGREEMENT_ID   = '_casapay_payment_agreement_id';
	const META_AGREEMENT_REF  = '_casapay_agreement_ref';
	const META_ALIAS          = '_casapay_email_alias';
	const META_ALIAS_ATTEMPTS = '_casapay_alias_attempts';
	const META_RESOLVED       = '_casapay_resolved_mode';
	const META_GUARANTEE_ID   = '_casapay_guarantee_id';
	const META_EVENTS         = '_casapay_events';
	const META_FINGERPRINT    = '_casapay_session_fingerprint';

	/** A lock older than this is treated as left behind by a crashed request. */
	const LOCK_TTL = 60;

	const PERSONAL_CODE_BLOCKS_ID = 'casapay/personal-code';
	const AS_GROUP                = 'casapay';
	const ALIAS_MAX_ATTEMPTS      = 12;

	public static function init() {
		add_action( 'casapay_wc_poll_session', array( __CLASS__, 'poll' ) );
		add_action( 'casapay_wc_fetch_agreement', array( __CLASS__, 'fetch_agreement' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_cancelled' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_panel' ) );

		// Personal code (optional; helps CasaPay KYC).
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'classic_personal_code_field' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'blocks_personal_code_field' ) );
	}

	/* -----------------------------------------------------------------
	 * Session payload
	 * --------------------------------------------------------------- */

	public static function rental_config( WC_Order $order ) {
		foreach ( $order->get_items() as $item ) {
			$config = Casapay_WC_Product::get_config( $item->get_product() );
			if ( $config ) {
				return $config;
			}
		}
		return null;
	}

	/**
	 * Builds the POST /sessions body from the order.
	 *
	 * @return array|WP_Error {payload, mode, first, guarantee_fee}
	 */
	public static function build_session( WC_Order $order ) {
		$settings = casapay_wc_settings();
		$config   = self::rental_config( $order );
		$type     = $config ? $config['type'] : ( $settings['default_agreement_type'] ?? 'payment_link' );

		$total         = round( (float) $order->get_total(), 2 );
		$deposit_fee   = 0.0;
		$guarantee_fee = 0.0;
		$fee_mode      = null;
		foreach ( $order->get_fees() as $fee ) {
			$mode = $fee->get_meta( Casapay_WC_Cart::FEE_ITEM_META );
			if ( 'deposit_upfront' === $mode ) {
				$deposit_fee += (float) $fee->get_total();
				$fee_mode     = $mode;
			} elseif ( 'deposit_guaranteed' === $mode ) {
				$guarantee_fee += (float) $fee->get_total();
				$fee_mode       = $mode;
			}
		}

		$number   = $order->get_order_number();
		$attempts = (int) $order->get_meta( self::META_ATTEMPTS );
		$items    = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name();
		}
		$label = mb_substr( implode( ', ', $items ), 0, 200 );

		$payload = array(
			'tenant'                    => self::tenant( $order ),
			'agreement_type'            => $type,
			'currency'                  => $order->get_currency(),
			/* translators: 1: order number, 2: ordered item names. */
			'description'               => sprintf( __( 'Order %1$s: %2$s', 'casapay-for-woocommerce' ), $number, $label ),
			'first_payment_description' => $label,
			// A retried order gets a suffix so CasaPay never sees the same invoice number twice.
			'invoice_no'                => mb_substr( preg_replace( '#[^A-Za-z0-9 /\-_.]#', '', 'WC-' . $number . ( $attempts > 0 ? '-' . ( $attempts + 1 ) : '' ) ), 0, 50 ),
			'reference_number'          => mb_substr( preg_replace( '#[^A-Za-z0-9]#', '', (string) $number ), 0, 35 ),
			'success_url'               => $order->get_checkout_order_received_url(),
			'cancel_url'                => $order->get_cancel_order_url_raw(),
			'metadata'                  => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
				'source'    => 'woocommerce',
			),
		);

		$mode = null;
		if ( $config && $config['cover'] > 0 && in_array( $type, array( 'ontime', 'cover' ), true ) ) {
			$mode = $fee_mode;
			if ( ! $mode && count( $config['modes'] ) === 1 ) {
				$mode = $config['modes'][0];
			}
			if ( ! $mode || ! in_array( $mode, $config['modes'], true ) ) {
				return new WP_Error( 'casapay_mode', __( 'Choose whether to pay the deposit now or use a CasaPay guarantee.', 'casapay-for-woocommerce' ) );
			}
			$payload['cover_amount'] = $config['cover'];
			$payload['deposit_mode'] = $mode;
		}

		$first = round( $total - $deposit_fee - $guarantee_fee, 2 );
		if ( $first <= 0 ) {
			return new WP_Error( 'casapay_amount', __( 'The order total is zero, so no CasaPay payment is needed.', 'casapay-for-woocommerce' ) );
		}
		$payload['first_payment_amount'] = $first;

		$verification = $settings['verification_required'] ?? 'entity';
		if ( 'force' === $verification ) {
			$payload['verification_required'] = true;
		} elseif ( 'skip' === $verification ) {
			$payload['verification_required'] = false;
		}

		$methods = array_values( array_intersect( (array) ( $settings['allowed_payment_methods'] ?? array() ), array( 'card', 'bank_transfer', 'open_banking' ) ) );
		if ( $methods ) {
			$payload['allowed_payment_methods'] = $methods;
		}

		$payload = apply_filters( 'casapay_wc_session_payload', $payload, $order );

		return array(
			'payload'       => $payload,
			'fingerprint'   => self::fingerprint( $payload ),
			'mode'          => $mode,
			'first'         => $first,
			'guarantee_fee' => round( $guarantee_fee, 2 ),
		);
	}

	/**
	 * What the session would charge and for whom. Two payloads with the same fingerprint
	 * describe the same payment, so an open session for one can be reused for the other.
	 * invoice_no is left out: it changes on every attempt.
	 */
	public static function fingerprint( array $payload ) {
		unset( $payload['invoice_no'] );
		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Per-order lock so a double click or a second tab cannot start two sessions at once.
	 * INSERT IGNORE on the unique option_name is atomic; add_option() is not.
	 */
	public static function lock( $order_id ) {
		global $wpdb;
		$name = '_casapay_lock_' . (int) $order_id;
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) );
		if ( $inserted ) {
			return true;
		}

		// Take over a stale lock, but only if nobody else took it over first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %s", (string) $now, $name, (string) ( $now - self::LOCK_TTL ) ) );
		return (bool) $taken;
	}

	public static function unlock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_casapay_lock_' . (int) $order_id ) );
	}

	private static function tenant( WC_Order $order ) {
		$tenant = array(
			'email'      => $order->get_billing_email(),
			'first_name' => $order->get_billing_first_name() ? $order->get_billing_first_name() : $order->get_shipping_first_name(),
			'last_name'  => $order->get_billing_last_name() ? $order->get_billing_last_name() : $order->get_shipping_last_name(),
		);

		$phone = preg_replace( '/[\s\-()]/', '', (string) $order->get_billing_phone() );
		if ( preg_match( '/^\+[1-9]\d{6,14}$/', $phone ) ) {
			$tenant['phone'] = $phone; // CasaPay wants E.164; anything else is left out.
		}

		$code = self::personal_code( $order );
		if ( '' !== $code ) {
			$tenant['personal_code'] = $code;
		}
		return $tenant;
	}

	public static function personal_code( WC_Order $order ) {
		$code = (string) $order->get_meta( '_billing_casapay_personal_code' );
		if ( '' === $code ) {
			$code = (string) $order->get_meta( '_wc_other/' . self::PERSONAL_CODE_BLOCKS_ID );
		}
		return trim( $code );
	}

	public static function remember_session( WC_Order $order, array $built, array $session ) {
		$payload = $built['payload'];
		$order->update_meta_data( self::META_SESSION, (string) $session['session_id'] );
		$order->update_meta_data( self::META_GATEWAY_URL, (string) ( $session['gateway_url'] ?? '' ) );
		$order->update_meta_data( self::META_FINGERPRINT, (string) ( $built['fingerprint'] ?? '' ) );
		$order->update_meta_data( self::META_ATTEMPTS, (int) $order->get_meta( self::META_ATTEMPTS ) + 1 );
		$order->update_meta_data( self::META_TYPE, $payload['agreement_type'] );
		$order->update_meta_data( self::META_MODE, (string) $built['mode'] );
		$order->update_meta_data( self::META_COVER, isset( $payload['cover_amount'] ) ? (string) $payload['cover_amount'] : '' );
		$order->update_meta_data( self::META_FIRST, (string) $built['first'] );
		$order->update_meta_data( self::META_GUARANTEE_FEE, (string) $built['guarantee_fee'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: session id 2: agreement type 3: deposit mode */
				__( 'CasaPay session %1$s created (%2$s%3$s). Customer sent to the CasaPay payment page.', 'casapay-for-woocommerce' ),
				$session['session_id'],
				$payload['agreement_type'],
				$built['mode'] ? ', ' . $built['mode'] : ''
			)
		);
		$order->save();
	}

	/* -----------------------------------------------------------------
	 * State sync
	 * --------------------------------------------------------------- */

	/**
	 * Reads the session from CasaPay and applies it to the order.
	 *
	 * @return true|WP_Error
	 */
	public static function sync( WC_Order $order ) {
		$session_id = (string) $order->get_meta( self::META_SESSION );
		if ( '' === $session_id ) {
			return new WP_Error( 'casapay_no_session', 'Order has no CasaPay session.' );
		}
		$session = Casapay_WC_Api::from_settings()->get_session( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		self::apply_session( $order, $session );
		return true;
	}

	private static function apply_session( WC_Order $order, array $session ) {
		if ( ( $session['session_id'] ?? '' ) !== $order->get_meta( self::META_SESSION ) ) {
			return; // An older, replaced session.
		}

		$status = (string) ( $session['status'] ?? '' );
		if ( 'completed' === $status ) {
			self::complete( $order, $session );
			return;
		}

		if ( in_array( $status, array( 'expired', 'cancelled', 'failed' ), true ) && $order->needs_payment() ) {
			$messages = array(
				/* translators: %s: CasaPay session ID. */
				'expired'   => __( 'CasaPay session %s expired.', 'casapay-for-woocommerce' ),
				/* translators: %s: CasaPay session ID. */
				'cancelled' => __( 'CasaPay session %s was cancelled.', 'casapay-for-woocommerce' ),
				/* translators: %s: CasaPay session ID. */
				'failed'    => __( 'CasaPay session %s failed.', 'casapay-for-woocommerce' ),
			);
			$order->update_status(
				'failed' === $status ? 'failed' : 'cancelled',
				sprintf( $messages[ $status ], $session['session_id'] )
			);
		}
	}

	private static function complete( WC_Order $order, array $session ) {
		if ( ! $order->needs_payment() && $order->get_meta( self::META_AGREEMENT_ID ) ) {
			return; // Already handled.
		}

		$transaction_id = (string) ( $session['payment']['transaction_id'] ?? $session['session_id'] );
		$resolved       = (string) ( $session['cover']['resolved_mode'] ?? '' );

		if ( ! empty( $session['payment_agreement_id'] ) ) {
			$order->update_meta_data( self::META_AGREEMENT_ID, (string) $session['payment_agreement_id'] );
		}
		if ( '' !== $resolved ) {
			$order->update_meta_data( self::META_RESOLVED, $resolved );
		}
		if ( ! empty( $session['guarantee']['guarantee_id'] ) ) {
			$order->update_meta_data( self::META_GUARANTEE_ID, (string) $session['guarantee']['guarantee_id'] );
		}

		$expected_mode = (string) $order->get_meta( self::META_MODE );
		if ( '' !== $expected_mode && '' !== $resolved && $resolved !== $expected_mode ) {
			/* translators: 1: deposit mode chosen at checkout, 2: deposit mode reported by CasaPay. */
			$order->add_order_note( sprintf( __( 'Check this order: %1$s was chosen at checkout, CasaPay reported %2$s.', 'casapay-for-woocommerce' ), $expected_mode, $resolved ) );
		}

		if ( $order->needs_payment() ) {
			$cash  = isset( $session['total_cash_amount'] ) ? (float) $session['total_cash_amount'] : (float) ( $session['amount'] ?? 0 );
			$total = round( (float) $order->get_total(), 2 );
			$fee   = (float) $order->get_meta( self::META_GUARANTEE_FEE );
			// CasaPay may report the guarantee fee inside or outside total_cash_amount; accept both.
			$matches = abs( $cash - $total ) < 0.01 || abs( $cash - ( $total - $fee ) ) < 0.01;

			if ( $matches ) {
				/* translators: %s: CasaPay session ID. */
				$order->add_order_note( sprintf( __( 'CasaPay payment confirmed through the API (session %s).', 'casapay-for-woocommerce' ), $session['session_id'] ) );
				$order->payment_complete( $transaction_id );
			} else {
				$order->set_transaction_id( $transaction_id );
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: amount paid at CasaPay, 2: order total. */
						__( 'CasaPay payment of %1$s does not match the order total of %2$s. Check before fulfilling.', 'casapay-for-woocommerce' ),
						casapay_wc_money( $cash, $order->get_currency() ),
						casapay_wc_money( $total, $order->get_currency() )
					)
				);
			}
		}
		$order->save();

		self::schedule( 'casapay_wc_fetch_agreement', $order->get_id(), 0 );
		do_action( 'casapay_wc_session_completed', $order, $session );
	}

	/* -----------------------------------------------------------------
	 * Background jobs (Action Scheduler)
	 * --------------------------------------------------------------- */

	public static function schedule( $hook, $order_id, $delay ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array( 'order_id' => (int) $order_id );
		as_unschedule_all_actions( $hook, $args, self::AS_GROUP );
		as_schedule_single_action( time() + (int) $delay, $hook, $args, self::AS_GROUP );
	}

	/** Fallback for a lost webhook: checks a pending order every 10 minutes until the session can no longer complete. */
	public static function poll( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== 'casapay' || ! $order->needs_payment() ) {
			return;
		}
		self::sync( $order );

		$order   = wc_get_order( $order_id );
		$created = $order->get_date_created();
		$age     = $created ? time() - $created->getTimestamp() : 0;
		if ( $order->needs_payment() && $order->get_status() === 'pending' && $age < 26 * HOUR_IN_SECONDS ) {
			self::schedule( 'casapay_wc_poll_session', $order_id, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Reads the agreement and stores its e-mail alias. CasaPay may create the alias a little after
	 * the first payment, so this retries every 10 minutes, up to 12 times.
	 */
	public static function fetch_agreement( $order_id, $retry = true ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$agreement_id = $order->get_meta( self::META_AGREEMENT_ID );
		if ( ! $agreement_id || $order->get_meta( self::META_ALIAS ) ) {
			return;
		}

		$agreement = Casapay_WC_Api::from_settings()->get_agreement( $agreement_id );
		$alias     = is_wp_error( $agreement ) ? '' : (string) ( $agreement['email_alias'] ?? '' );

		if ( ! is_wp_error( $agreement ) ) {
			$order->update_meta_data( self::META_AGREEMENT_REF, (string) ( $agreement['agreement_id'] ?? '' ) );
			if ( ! empty( $agreement['agreement_type'] ) ) {
				$order->update_meta_data( self::META_TYPE, (string) $agreement['agreement_type'] );
			}
		}

		if ( '' !== $alias ) {
			$order->update_meta_data( self::META_ALIAS, $alias );
			/* translators: 1: CasaPay agreement reference, 2: e-mail alias. */
			$order->add_order_note( sprintf( __( 'CasaPay agreement %1$s, invoice e-mail alias: %2$s', 'casapay-for-woocommerce' ), $agreement['agreement_id'] ?? $agreement_id, $alias ) );
			$order->save();
			do_action( 'casapay_wc_agreement_ready', $order, $alias, $agreement );
			return;
		}

		$attempts = (int) $order->get_meta( self::META_ALIAS_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ALIAS_ATTEMPTS, $attempts );
		$order->save();

		if ( $retry && $attempts < self::ALIAS_MAX_ATTEMPTS ) {
			self::schedule( 'casapay_wc_fetch_agreement', $order_id, 10 * MINUTE_IN_SECONDS );
		} elseif ( $retry ) {
			casapay_wc_log( sprintf( 'No e-mail alias for order %d (agreement %s) after %d attempts.', $order_id, $agreement_id, $attempts ), 'warning' );
		}
	}

	/** Cancels the CasaPay session when an unpaid order is cancelled in WooCommerce (customer or admin). */
	public static function on_order_cancelled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== 'casapay' || $order->get_meta( self::META_AGREEMENT_ID ) ) {
			return;
		}
		$session_id = (string) $order->get_meta( self::META_SESSION );
		if ( '' === $session_id ) {
			return;
		}
		$result = Casapay_WC_Api::from_settings()->cancel_session( $session_id, sprintf( 'WooCommerce order %s cancelled', $order->get_order_number() ) );
		if ( is_wp_error( $result ) ) {
			return; // Usually already terminal at CasaPay.
		}
		if ( isset( $result['invoice_cancelled'] ) && false === $result['invoice_cancelled'] ) {
			$order->add_order_note( __( 'The CasaPay session was cancelled, but its invoice was already partly paid. Refund it through CasaPay.', 'casapay-for-woocommerce' ) );
		}
	}

	/** Records a webhook event; returns false when this session+event was already handled. */
	public static function event_seen( WC_Order $order, $session_id, $event ) {
		return in_array( $session_id . '|' . $event, (array) $order->get_meta( self::META_EVENTS ), true );
	}

	public static function record_event( WC_Order $order, $session_id, $event ) {
		$events   = (array) $order->get_meta( self::META_EVENTS );
		$events[] = $session_id . '|' . $event;
		$order->update_meta_data( self::META_EVENTS, array_slice( array_unique( $events ), -50 ) );
		$order->save();
	}

	/* -----------------------------------------------------------------
	 * Admin + personal code field
	 * --------------------------------------------------------------- */

	public static function admin_panel( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'casapay' ) {
			return;
		}
		$rows = array(
			__( 'Session', 'casapay-for-woocommerce' )   => $order->get_meta( self::META_SESSION ),
			__( 'Agreement type', 'casapay-for-woocommerce' ) => $order->get_meta( self::META_TYPE ),
			__( 'Deposit', 'casapay-for-woocommerce' )   => $order->get_meta( self::META_RESOLVED ) ? $order->get_meta( self::META_RESOLVED ) : $order->get_meta( self::META_MODE ),
			__( 'Agreement', 'casapay-for-woocommerce' ) => $order->get_meta( self::META_AGREEMENT_REF ) ? $order->get_meta( self::META_AGREEMENT_REF ) : $order->get_meta( self::META_AGREEMENT_ID ),
			__( 'Guarantee', 'casapay-for-woocommerce' ) => $order->get_meta( self::META_GUARANTEE_ID ),
			__( 'E-mail alias', 'casapay-for-woocommerce' ) => $order->get_meta( self::META_ALIAS ),
		);
		echo '<div class="casapay-wc-admin"><h3>CasaPay</h3><p>';
		foreach ( $rows as $label => $value ) {
			if ( $value ) {
				echo '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '<br>';
			}
		}
		echo '</p></div>';
	}

	public static function classic_personal_code_field( $fields ) {
		if ( ! apply_filters( 'casapay_wc_collect_personal_code', true ) || ! is_checkout() || ! WC()->cart || ! Casapay_WC_Cart::get_rental() ) {
			return $fields;
		}
		$fields['billing_casapay_personal_code'] = array(
			'label'    => __( 'Personal ID code', 'casapay-for-woocommerce' ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 125,
		);
		return $fields;
	}

	public static function blocks_personal_code_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! apply_filters( 'casapay_wc_collect_personal_code', true ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::PERSONAL_CODE_BLOCKS_ID,
				'label'         => __( 'Personal ID code', 'casapay-for-woocommerce' ),
				'optionalLabel' => __( 'Personal ID code (optional)', 'casapay-for-woocommerce' ),
				'location'      => 'contact',
				'type'          => 'text',
				'required'      => false,
			)
		);
	}
}
