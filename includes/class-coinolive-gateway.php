<?php
/**
 * The WooCommerce payment gateway.
 *
 * Flow: placing an order creates a Coino Live payment for the order total and sends
 * the customer to its checkout page. The order then follows the payment: a webhook,
 * the customer's return to the shop and a five-minute check all re-read the payment
 * from the API (never trusting a callback's body alone) and update the order.
 *
 * @package CoinoLive
 */

defined( 'ABSPATH' ) || exit;

class CoinoLive_Gateway extends WC_Payment_Gateway {

	const META_PAYMENT  = '_coinolive_payment_id';
	const META_CHECKOUT = '_coinolive_checkout_url';
	const META_PRICE    = '_coinolive_price';    // "25.00 EUR": what the payment was created for.
	const META_ATTEMPT  = '_coinolive_attempt';  // Bumped when a new payment replaces an expired one.
	const META_STATE    = '_coinolive_state';    // Last status handled, so notes are added once.

	public function __construct() {
		$this->id                 = 'coinolive';
		$this->method_title       = __( 'Coino Live (crypto)', 'coinolive' );
		$this->method_description = __( 'Customers pay with USDT, Bitcoin, Ethereum, TRON, TON, Solana and other coins on the Coino Live checkout. Orders are marked paid automatically once the payment is confirmed on chain.', 'coinolive' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = apply_filters( 'coinolive_icon', COINOLIVE_URL . 'assets/images/coinolive.svg' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Hooks that do not need a gateway instance per request. */
	public static function init_hooks() {
		add_action( 'woocommerce_thankyou_coinolive', array( __CLASS__, 'on_thank_you' ) );
		add_action( 'coinolive_sync_orders', array( __CLASS__, 'sync_open_orders' ) );
		// Re-created if lost (an update without reactivation, a cron reset).
		if ( ! wp_next_scheduled( 'coinolive_sync_orders' ) ) {
			wp_schedule_event( time() + 300, 'coinolive_five_minutes', 'coinolive_sync_orders' );
		}
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'coinolive' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept crypto payments with Coino Live', 'coinolive' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'coinolive' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'coinolive' ),
				'default'     => __( 'Pay with crypto', 'coinolive' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'   => __( 'Description', 'coinolive' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with USDT, Bitcoin, Ethereum, TRON, TON, Solana and more. You will be taken to a secure payment page.', 'coinolive' ),
			),
			'api_key'        => array(
				'title'       => __( 'API key', 'coinolive' ),
				'type'        => 'password',
				'description' => __( 'Create one in your Coino Live dashboard under Developers. Keys start with ck_live_ (or ck_test_ on the test network).', 'coinolive' ),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret (optional)', 'coinolive' ),
				'type'        => 'password',
				'description' => __( 'From your gateway settings (whsec_…). When set, callbacks with a wrong signature are refused. Orders are always checked against the API before they change, so this is an extra layer.', 'coinolive' ),
			),
			'expires_in'     => array(
				'title'             => __( 'Payment window (minutes)', 'coinolive' ),
				'type'              => 'number',
				'description'       => __( 'How long the customer has to send the payment. Between 15 and 10080.', 'coinolive' ),
				'default'           => '60',
				'custom_attributes' => array(
					'min' => 15,
					'max' => 10080,
				),
			),
			'debug'          => array(
				'title'       => __( 'Debug log', 'coinolive' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API calls and callbacks (WooCommerce > Status > Logs, source "coinolive")', 'coinolive' ),
				'default'     => 'no',
			),
			'api_url'        => array(
				'title'       => __( 'API URL', 'coinolive' ),
				'type'        => 'text',
				'description' => __( 'Leave as is unless Coino Live support tells you otherwise.', 'coinolive' ),
				'default'     => CoinoLive_Client::DEFAULT_URL,
			),
		);
	}

	public function admin_options() {
		parent::admin_options();
		echo '<p>' . esc_html__( 'Callback URL (set automatically on every payment, nothing to configure):', 'coinolive' ) . ' <code>' . esc_html( rest_url( 'coinolive/v1/webhook' ) ) . '</code></p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=coinolive' ) ) . '">' . esc_html__( 'Balance and payments', 'coinolive' ) . '</a></p>';
	}

	/** Saving the settings checks the key against the API, so a typo shows up at once. */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->init_settings();
		delete_transient( 'coinolive_currencies' );

		$url = trim( (string) $this->get_option( 'api_url' ) );
		if ( '' !== $url && ! self::is_allowed_api_url( $url ) ) {
			WC_Admin_Settings::add_error( __( 'The API URL must start with https://.', 'coinolive' ) );
		} elseif ( '' !== $this->get_option( 'api_key' ) ) {
			try {
				$currencies = self::currencies( $this );
				$shop       = get_woocommerce_currency();
				if ( ! in_array( $shop, $currencies, true ) ) {
					/* translators: %s: currency code */
					WC_Admin_Settings::add_error( sprintf( __( 'Connected, but Coino Live cannot price payments in %s yet. The payment method stays hidden at checkout.', 'coinolive' ), $shop ) );
				} else {
					WC_Admin_Settings::add_message( __( 'Connected to Coino Live.', 'coinolive' ) );
				}
			} catch ( CoinoLive_Exception $e ) {
				/* translators: %s: error message */
				WC_Admin_Settings::add_error( sprintf( __( 'Coino Live refused the API key: %s', 'coinolive' ), $e->getMessage() ) );
			}
		}

		return $saved;
	}

	public function is_available() {
		if ( ! parent::is_available() || '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			return false;
		}
		try {
			return in_array( get_woocommerce_currency(), self::currencies( $this ), true );
		} catch ( CoinoLive_Exception $e ) {
			return true; // API unreachable right now: placing the order will say so.
		}
	}

	/**
	 * Creates (or reuses) the payment and sends the customer to its checkout page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		try {
			$payment = $this->payment_for( $order );
		} catch ( CoinoLive_Exception $e ) {
			$this->log( 'Payment creation failed for order ' . $order->get_id() . ': ' . $e->getMessage() );
			/* translators: %s: error message from Coino Live */
			wc_add_notice( sprintf( __( 'Crypto payment is not available right now: %s', 'coinolive' ), $e->getMessage() ), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $payment['checkout_url'],
		);
	}

	/**
	 * The order's open payment for its current total, or a new one.
	 *
	 * @throws CoinoLive_Exception When the API refuses or cannot be reached.
	 */
	private function payment_for( WC_Order $order ) {
		$amount   = number_format( (float) $order->get_total(), 2, '.', '' );
		$currency = $order->get_currency();
		$price    = $amount . ' ' . $currency;
		$client   = self::client( $this );

		// The customer came back and placed the order again: reuse the payment while it is open.
		$existing = (string) $order->get_meta( self::META_PAYMENT );
		if ( '' !== $existing && $order->get_meta( self::META_PRICE ) === $price ) {
			$payment = $client->get_payment( $existing );
			if ( 'open' === $payment['status'] ) {
				return $payment;
			}
			if ( in_array( $payment['status'], array( 'confirming', 'paid', 'partially_paid' ), true ) ) {
				throw new CoinoLive_Exception( esc_html__( 'A payment for this order is already on its way. Please wait for it to confirm.', 'coinolive' ) );
			}
		}

		// A new attempt (first one, changed total, or the last payment expired): a new idempotency key.
		$attempt = (int) $order->get_meta( self::META_ATTEMPT ) + ( '' === $existing ? 0 : 1 );
		// The random order key keeps keys unique even if order numbers repeat (a reset shop database).
		$key = 'wc-' . $order->get_id() . '-' . $attempt . '-' . substr( md5( $order->get_order_key() . '|' . $price . '|' . home_url() ), 0, 16 );

		$data = array(
			'amount'         => $amount,
			'currency'       => $currency,
			'order_id'       => (string) $order->get_order_number(),
			/* translators: 1: order number, 2: shop name */
			'description'    => mb_substr( sprintf( __( 'Order #%1$s at %2$s', 'coinolive' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, 500 ),
			'customer_email' => $order->get_billing_email() ? $order->get_billing_email() : null,
			'success_url'    => $this->get_return_url( $order ),
			'cancel_url'     => $order->get_checkout_payment_url(),
			'expires_in'     => max( 15, min( 10080, (int) $this->get_option( 'expires_in', 60 ) ) ),
			'metadata'       => array(
				'wc_order_id' => (string) $order->get_id(),
				'site'        => home_url( '/' ),
			),
		);
		// Callbacks need a URL Coino Live can reach (not a local development site).
		if ( self::is_public_url( rest_url( 'coinolive/v1/webhook' ) ) ) {
			$data['notify_url'] = rest_url( 'coinolive/v1/webhook' );
		}

		$payment = $client->create_payment( $data, $key );
		if ( (string) ( $payment['metadata']['wc_order_id'] ?? '' ) !== (string) $order->get_id() || 'open' !== $payment['status'] ) {
			throw new CoinoLive_Exception( esc_html__( 'Coino Live returned a payment that does not belong to this order. Please try again.', 'coinolive' ) );
		}
		$this->log( 'Created payment ' . $payment['id'] . ' for order ' . $order->get_id() . ' (' . $price . ')' );

		$order->update_meta_data( self::META_PAYMENT, $payment['id'] );
		$order->update_meta_data( self::META_CHECKOUT, $payment['checkout_url'] );
		$order->update_meta_data( self::META_PRICE, $price );
		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->delete_meta_data( self::META_STATE );
		$order->set_transaction_id( $payment['id'] );
		if ( ! $order->has_status( 'pending' ) ) {
			$order->set_status( 'pending' );
		}
		/* translators: 1: payment ID, 2: amount in USD */
		$order->add_order_note( sprintf( __( 'Coino Live payment %1$s created (%2$s USD). Waiting for the customer to pay.', 'coinolive' ), $payment['id'], $payment['amount'] ) );
		$order->save();

		return $payment;
	}

	/**
	 * Brings the order in line with the payment (as read from the API).
	 * Safe to call any number of times: each state is handled once.
	 */
	public static function sync( WC_Order $order, array $payment ) {
		if ( ( $payment['id'] ?? '' ) !== $order->get_meta( self::META_PAYMENT ) ) {
			return; // An older payment of this order, or not ours.
		}
		$status   = (string) $payment['status'];
		$received = (string) ( $payment['received_usd'] ?? '0' );
		$state    = $status . '|' . $received;
		if ( $order->get_meta( self::META_STATE ) === $state ) {
			return;
		}

		switch ( $status ) {
			case 'paid':
				if ( $order->is_paid() ) {
					break;
				}
				// The payment must be for this order's price (the total can be edited after checkout).
				$asked = number_format( (float) ( $payment['requested_amount'] ?? 0 ), 2, '.', '' ) . ' ' . ( $payment['requested_currency'] ?? '' );
				if ( $asked !== $order->get_meta( self::META_PRICE ) || (string) ( $payment['metadata']['wc_order_id'] ?? '' ) !== (string) $order->get_id() ) {
					/* translators: 1: payment price, 2: order price */
					$order->update_status( 'on-hold', sprintf( __( 'Coino Live payment is paid but was for %1$s, the order expects %2$s. Check before shipping.', 'coinolive' ), $asked, (string) $order->get_meta( self::META_PRICE ) ) );
					break;
				}
				$order->add_order_note( self::deposits_note( $payment ) );
				$order->payment_complete( $payment['id'] );
				break;

			case 'confirming':
				if ( ! $order->is_paid() ) {
					$order->update_status( 'on-hold', __( 'Coino Live: the payment was sent and is waiting for network confirmations.', 'coinolive' ) );
				}
				break;

			case 'partially_paid':
				if ( ! $order->is_paid() ) {
					/* translators: 1: received USD, 2: total USD */
					$order->update_status( 'on-hold', sprintf( __( 'Coino Live: only %1$s of %2$s USD received so far. The customer was asked to send the rest.', 'coinolive' ), $received, $payment['amount'] ) );
				}
				break;

			case 'expired':
			case 'cancelled':
				if ( $order->is_paid() ) {
					break;
				}
				if ( (float) $received > 0 ) {
					/* translators: 1: received USD, 2: total USD */
					$order->update_status( 'on-hold', sprintf( __( 'Coino Live: the payment closed with %1$s of %2$s USD received (in your Coino Live balance). Settle the rest with the customer.', 'coinolive' ), $received, $payment['amount'] ) );
				} elseif ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
					$order->update_status( 'failed', __( 'Coino Live: the payment window closed without payment. The customer can pay again from their account.', 'coinolive' ) );
				}
				break;
		}

		$order->update_meta_data( self::META_STATE, $state );
		$order->save();
	}

	/** Re-reads the order's payment from the API and syncs the order. */
	public static function refresh( WC_Order $order ) {
		$id = (string) $order->get_meta( self::META_PAYMENT );
		if ( '' === $id ) {
			return;
		}
		self::sync( $order, self::client()->get_payment( $id ) );
	}

	/** The customer is back on the shop: show the current state without waiting for the callback. */
	public static function on_thank_you( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		try {
			self::refresh( $order );
		} catch ( CoinoLive_Exception $e ) {
			self::log_static( 'Thank-you refresh failed for order ' . $order->get_id() . ': ' . $e->getMessage() );
		}
		if ( $order->has_status( 'on-hold' ) ) {
			echo '<p class="woocommerce-info">' . esc_html__( 'Your crypto payment was received and is being confirmed on the network. We will email you when the order is paid.', 'coinolive' ) . '</p>';
		} elseif ( $order->needs_payment() && $order->get_meta( self::META_CHECKOUT ) ) {
			echo '<p class="woocommerce-info">' . esc_html__( 'We have not seen your payment yet. If you have not paid, you can still do so:', 'coinolive' ) . ' <a class="button" href="' . esc_url( (string) $order->get_meta( self::META_CHECKOUT ) ) . '">' . esc_html__( 'Open the payment page', 'coinolive' ) . '</a></p>';
		}
	}

	/** Every five minutes: orders still waiting (last three days) are checked against the API. */
	public static function sync_open_orders() {
		$orders = wc_get_orders(
			array(
				'payment_method' => 'coinolive',
				'status'         => array( 'pending', 'on-hold' ),
				'date_created'   => '>' . ( time() - 3 * DAY_IN_SECONDS ),
				'limit'          => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $orders as $order ) {
			try {
				self::refresh( $order );
			} catch ( CoinoLive_Exception $e ) {
				self::log_static( 'Sync failed for order ' . $order->get_id() . ': ' . $e->getMessage() );
				if ( 0 === $e->status ) {
					return; // API unreachable: try again next run.
				}
			}
		}
	}

	private static function deposits_note( array $payment ) {
		$lines = array();
		foreach ( (array) ( $payment['deposits'] ?? array() ) as $d ) {
			$lines[] = sprintf( '%s %s (≈ %s USD), tx %s', $d['amount'], strtoupper( (string) $d['asset'] ), $d['usd_value'], $d['tx_hash'] );
		}
		/* translators: 1: payment ID, 2: list of deposits */
		return sprintf( __( 'Coino Live payment %1$s confirmed: %2$s', 'coinolive' ), $payment['id'], implode( '; ', $lines ) );
	}

	/** @return list<string> Currency codes the API can price payments in (cached 12 hours). */
	private static function currencies( $gateway = null ) {
		$cached = get_transient( 'coinolive_currencies' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$codes = array_values( array_map( static fn( $c ) => (string) $c['code'], self::client( $gateway )->currencies() ) );
		set_transient( 'coinolive_currencies', $codes, 12 * HOUR_IN_SECONDS );
		return $codes;
	}

	public static function client( $gateway = null ) {
		$settings = $gateway ? $gateway->settings : get_option( 'woocommerce_coinolive_settings', array() );
		$url      = trim( (string) ( $settings['api_url'] ?? '' ) );
		return new CoinoLive_Client( $settings['api_key'] ?? '', self::is_allowed_api_url( $url ) ? $url : '' );
	}

	private static function is_allowed_api_url( $url ) {
		return 0 === strpos( $url, 'https://' ) || (bool) preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $url );
	}

	/** False for local development sites, which Coino Live cannot (and must not) call. */
	private static function is_public_url( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(local|localhost|test|internal)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	private function log( $message ) {
		if ( 'yes' === $this->get_option( 'debug' ) ) {
			wc_get_logger()->info( $message, array( 'source' => 'coinolive' ) );
		}
	}

	public static function log_static( $message ) {
		$settings = get_option( 'woocommerce_coinolive_settings', array() );
		if ( 'yes' === ( $settings['debug'] ?? 'no' ) ) {
			wc_get_logger()->info( $message, array( 'source' => 'coinolive' ) );
		}
	}
}
