<?php
/**
 * Coino Live API v2 client (WordPress HTTP API).
 *
 * @package CoinoLive
 */

defined( 'ABSPATH' ) || exit;

/** An API call that failed: the message is safe to show to the shop owner. */
class CoinoLive_Exception extends Exception {

	/** @var int HTTP status (0 when the request did not reach the API). */
	public $status;

	public function __construct( $message, $status = 0 ) {
		parent::__construct( $message );
		$this->status = (int) $status;
	}
}

class CoinoLive_Client {

	const DEFAULT_URL = 'https://coino.live/api/v2';

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	public function __construct( $api_key, $base_url = '' ) {
		$this->api_key  = trim( (string) $api_key );
		$this->base_url = untrailingslashit( $base_url ? $base_url : self::DEFAULT_URL );
	}

	/**
	 * Creates a payment. The same idempotency key always returns the same payment.
	 *
	 * @param array  $data            amount, currency, order_id, … (see the API reference).
	 * @param string $idempotency_key Up to 128 letters, digits and -_:. characters.
	 * @return array The payment.
	 */
	public function create_payment( array $data, $idempotency_key ) {
		return $this->request( 'POST', '/payments', $data, $idempotency_key );
	}

	/** @return array The payment. */
	public function get_payment( $id ) {
		return $this->request( 'GET', '/payments/' . rawurlencode( $id ) );
	}

	/** @return array List of ['code' => 'EUR', 'usd_per_unit' => '1.08']. */
	public function currencies() {
		return $this->request( 'GET', '/currencies' );
	}

	/** @return array List of ['asset', 'symbol', 'network', 'balance', 'usd_value'] for the whole Coino Live account. */
	public function balances() {
		return $this->request( 'GET', '/balances' );
	}

	/**
	 * One page of payments, newest first.
	 *
	 * @param array $query status, order_id, cursor.
	 * @return array ['data' => payments, 'meta' => ['next_cursor' => …, 'prev_cursor' => …]].
	 */
	public function list_payments( array $query = array() ) {
		$query = array_filter( $query, static fn( $v ) => null !== $v && '' !== $v );
		return $this->request( 'GET', '/payments' . ( $query ? '?' . http_build_query( $query ) : '' ), null, null, true );
	}

	/**
	 * @param bool $full Return the whole JSON answer instead of its "data".
	 * @return array The response's "data".
	 * @throws CoinoLive_Exception On a network error or a non-2xx answer.
	 */
	private function request( $method, $path, $body = null, $idempotency_key = null, $full = false ) {
		if ( '' === $this->api_key ) {
			throw new CoinoLive_Exception( esc_html__( 'The Coino Live API key is not set.', 'coinolive' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'CoinoLive-WooCommerce/' . COINOLIVE_VERSION . '; WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
		);
		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 20,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: network error message */
			throw new CoinoLive_Exception( esc_html( sprintf( __( 'Could not reach Coino Live: %s', 'coinolive' ), $response->get_error_message() ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		// A redirect or an HTML 404 means the API URL points at a website, not at the API.
		if ( ( $status >= 300 && $status < 400 ) || ( 404 === $status && ! is_array( $json ) ) ) {
			/* translators: 1: the API URL from the settings, 2: HTTP status code */
			throw new CoinoLive_Exception( esc_html( sprintf( __( 'There is no Coino Live API at %1$s (HTTP %2$d). Check the API URL setting.', 'coinolive' ), $this->base_url, $status ) ), (int) $status );
		}
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $json ) && isset( $json['message'] ) ? (string) $json['message'] : 'HTTP ' . $status;
			if ( is_array( $json ) && ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$first = reset( $json['errors'] );
				$message = is_array( $first ) ? (string) reset( $first ) : $message;
			}
			throw new CoinoLive_Exception( esc_html( $message ), (int) $status );
		}
		if ( ! is_array( $json ) || ! array_key_exists( 'data', $json ) ) {
			throw new CoinoLive_Exception( esc_html__( 'Unexpected answer from Coino Live.', 'coinolive' ), (int) $status );
		}

		return $full ? $json : $json['data'];
	}
}
