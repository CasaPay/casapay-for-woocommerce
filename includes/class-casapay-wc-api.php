<?php
/**
 * Thin CasaPay Gateway API client. Server side only; the key never reaches the browser.
 */

defined( 'ABSPATH' ) || exit;

class Casapay_WC_Api {

	const LIVE_URL = 'https://manage.casapay.com/api/v1/gateway';
	const TEST_URL = 'https://manage.test.casapay.com/api/v1/gateway';

	private $base_url;
	private $api_key;

	public function __construct( $base_url, $api_key ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->api_key  = (string) $api_key;
	}

	public static function from_settings() {
		$s    = casapay_wc_settings();
		$test = casapay_wc_is_test_mode();
		$base = $test ? ( $s['test_base_url'] ?? '' ) : ( $s['live_base_url'] ?? '' );
		$key  = $test ? ( $s['test_api_key'] ?? '' ) : ( $s['live_api_key'] ?? '' );
		return new self( $base ? $base : ( $test ? self::TEST_URL : self::LIVE_URL ), $key );
	}

	public function is_configured() {
		return '' !== $this->api_key;
	}

	public function pricing_preview( $cover_amount, $first_payment_amount, $currency ) {
		return $this->request(
			'POST',
			'/pricing-preview',
			array(
				'cover_amount'         => round( (float) $cover_amount, 2 ),
				'first_payment_amount' => round( (float) $first_payment_amount, 2 ),
				'currency'             => $currency,
			)
		);
	}

	public function create_session( array $payload ) {
		return $this->request( 'POST', '/sessions', $payload );
	}

	public function get_session( $session_id ) {
		return $this->request( 'GET', '/sessions/' . rawurlencode( $session_id ) );
	}

	public function cancel_session( $session_id, $note = '' ) {
		$body = array( 'reason' => 'operator_cancelled' );
		if ( '' !== $note ) {
			$body['note'] = mb_substr( $note, 0, 500 );
		}
		return $this->request( 'POST', '/sessions/' . rawurlencode( $session_id ) . '/cancel', $body );
	}

	public function get_agreement( $payment_agreement_id ) {
		return $this->request( 'GET', '/agreements/' . rawurlencode( (string) $payment_agreement_id ) );
	}

	/**
	 * @return array|WP_Error Decoded JSON on 2xx.
	 */
	private function request( $method, $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'casapay_not_configured', __( 'The CasaPay API key is not set.', 'casapay-for-woocommerce' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			casapay_wc_log( sprintf( '%s %s failed: %s', $method, $path, $response->get_error_message() ), 'error' );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		list( $code, $message, $details ) = self::parse_error( $data, $status );
		casapay_wc_log(
			sprintf( '%s %s -> %d %s: %s', $method, $path, $status, $code, $message ),
			$status >= 500 ? 'error' : 'warning',
			array( 'details' => $details )
		);

		return new WP_Error(
			'casapay_' . strtolower( $code ),
			$message,
			array(
				'status'  => $status,
				'code'    => $code,
				'details' => $details,
			)
		);
	}

	/** CasaPay uses two error shapes: {error:{code,message,details}} and {error:"...",code:"..."}. */
	private static function parse_error( $data, $status ) {
		if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) {
			return array(
				(string) ( $data['error']['code'] ?? 'API_ERROR' ),
				(string) ( $data['error']['message'] ?? 'CasaPay API error' ),
				$data['error']['details'] ?? null,
			);
		}
		if ( is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return array( (string) ( $data['code'] ?? 'API_ERROR' ), $data['error'], null );
		}
		return array( 'HTTP_' . $status, 'CasaPay API error (HTTP ' . $status . ')', null );
	}
}
