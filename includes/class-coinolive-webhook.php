<?php
/**
 * Callback endpoint: POST /wp-json/coinolive/v1/webhook
 *
 * A callback only says "this payment changed". The order is updated from the payment
 * as read back from the API with the shop's key, so a forged or replayed callback
 * cannot mark anything paid. With a signing secret set, the signature is checked too.
 *
 * @package CoinoLive
 */

defined( 'ABSPATH' ) || exit;

class CoinoLive_Webhook {

	/** Signatures older than this are refused (replays). */
	const TOLERANCE = 300;

	public static function init() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'coinolive/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'handle' ),
						'permission_callback' => '__return_true', // Authenticated by signature and the API re-read.
					)
				);
			}
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$body     = $request->get_body();
		$settings = get_option( 'woocommerce_coinolive_settings', array() );
		$secret   = trim( (string) ( $settings['webhook_secret'] ?? '' ) );

		if ( '' !== $secret && ! self::valid_signature( (string) $request->get_header( 'x_coino_signature' ), $body, $secret ) ) {
			CoinoLive_Gateway::log_static( 'Callback refused: bad signature.' );
			return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
		}

		$event = json_decode( $body, true );
		$id    = is_array( $event ) ? (string) ( $event['data']['id'] ?? '' ) : '';
		if ( 0 !== strpos( (string) ( $event['event'] ?? '' ), 'payment.' ) || ! preg_match( '/^[0-9a-z]{26}$/', $id ) ) {
			return new WP_REST_Response( array( 'ignored' => true ), 200 );
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => CoinoLive_Gateway::META_PAYMENT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( empty( $orders ) ) {
			return new WP_REST_Response( array( 'ignored' => true ), 200 ); // Not an order of this shop (or an older attempt).
		}

		try {
			CoinoLive_Gateway::refresh( $orders[0] );
		} catch ( CoinoLive_Exception $e ) {
			CoinoLive_Gateway::log_static( 'Callback for ' . $id . ' could not be checked: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'message' => 'Try again later.' ), 503 ); // Coino Live retries.
		}

		CoinoLive_Gateway::log_static( 'Callback ' . ( $event['event'] ?? '' ) . ' for ' . $id . ' handled (order ' . $orders[0]->get_id() . ').' );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** X-Coino-Signature: t=<unix time>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")> */
	public static function valid_signature( $header, $body, $secret ) {
		$parts = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$kv = explode( '=', trim( $pair ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		if ( ! isset( $parts['t'], $parts['v1'] ) || ! ctype_digit( $parts['t'] ) || abs( time() - (int) $parts['t'] ) > self::TOLERANCE ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $parts['t'] . '.' . $body, $secret ), $parts['v1'] );
	}
}
