<?php
/**
 * WooCommerce > Coino Live: the account balance and this shop's payments, read live from the API.
 *
 * @package CoinoLive
 */

defined( 'ABSPATH' ) || exit;

class CoinoLive_Admin {

	const PAGE = 'coinolive';

	const STATUSES = array( 'paid', 'partially_paid', 'confirming', 'open', 'expired', 'cancelled' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
	}

	public static function menu() {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Coino Live', 'coinolive' ),
			__( 'Coino Live', 'coinolive' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
		add_action(
			'admin_print_styles-' . $hook,
			static function () {
				wp_enqueue_style( 'coinolive-admin', COINOLIVE_URL . 'assets/css/admin.css', array(), COINOLIVE_VERSION );
			}
		);
	}

	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'coinolive' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';
		$order  = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : '';
		$cursor = isset( $_GET['cursor'] ) ? preg_replace( '/[^A-Za-z0-9_\-=]/', '', wp_unslash( $_GET['cursor'] ) ) : '';
		// phpcs:enable

		$settings     = get_option( 'woocommerce_coinolive_settings', array() );
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=coinolive' );
		$dashboard    = self::dashboard_url( $settings );

		echo '<div class="wrap coinolive-admin">';
		echo '<h1 class="wp-heading-inline"><img src="' . esc_url( COINOLIVE_URL . 'assets/images/coinolive.svg' ) . '" alt="" width="28" height="28"> ' . esc_html__( 'Coino Live', 'coinolive' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'coinolive' ) . '</a>';
		if ( $dashboard ) {
			echo ' <a class="page-title-action" href="' . esc_url( $dashboard ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Coino Live dashboard', 'coinolive' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';

		if ( '' === trim( (string) ( $settings['api_key'] ?? '' ) ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add your Coino Live API key to see your balance and payments here.', 'coinolive' ) . ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'coinolive' ) . '</a></p></div></div>';
			return;
		}

		$client = CoinoLive_Gateway::client();
		self::render_balances( $client, $dashboard );
		self::render_payments( $client, $status, $order, $cursor );
		echo '</div>';
	}

	private static function render_balances( CoinoLive_Client $client, $dashboard ) {
		echo '<h2>' . esc_html__( 'Balance', 'coinolive' ) . '</h2>';
		try {
			$balances = $client->balances();
		} catch ( CoinoLive_Exception $e ) {
			self::error( $e );
			return;
		}

		$held      = array_values( array_filter( $balances, static fn( $b ) => 1 === self::cmp( (string) $b['balance'], '0' ) ) );
		$total     = '0';
		$all_known = true;
		foreach ( $held as $b ) {
			if ( null === ( $b['usd_value'] ?? null ) ) {
				$all_known = false;
			} else {
				$total = self::add( $total, (string) $b['usd_value'] );
			}
		}

		echo '<div class="coinolive-cards">';
		echo '<div class="coinolive-card coinolive-total"><span>' . esc_html__( 'Total', 'coinolive' ) . '</span><strong>' . esc_html( ( $all_known ? '' : '≥ ' ) . self::money( $total ) . ' USD' ) . '</strong>';
		if ( $dashboard ) {
			echo '<a href="' . esc_url( $dashboard . '/wallet/withdraw' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Withdraw', 'coinolive' ) . ' &rarr;</a>';
		}
		echo '</div>';
		foreach ( $held as $b ) {
			echo '<div class="coinolive-card"><span>' . esc_html( $b['symbol'] . ' · ' . $b['network'] ) . '</span><strong>' . esc_html( $b['balance'] . ' ' . $b['symbol'] ) . '</strong>';
			echo '<em>' . esc_html( null === ( $b['usd_value'] ?? null ) ? '—' : '≈ ' . self::money( (string) $b['usd_value'] ) . ' USD' ) . '</em></div>';
		}
		echo '</div>';
		echo '<p class="description">' . ( $held ? esc_html__( 'Your whole Coino Live account (all gateways), after fees. Withdraw from the Coino Live dashboard.', 'coinolive' ) : esc_html__( 'No balance yet. Paid orders are added here after fees.', 'coinolive' ) ) . '</p>';
	}

	private static function render_payments( CoinoLive_Client $client, $status, $order_id, $cursor ) {
		echo '<h2>' . esc_html__( 'Payments', 'coinolive' ) . '</h2>';

		$labels = array( '' => __( 'All', 'coinolive' ) ) + self::status_labels();
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $labels as $key => $label ) {
			$class   = $key === $status ? ' class="current"' : '';
			$links[] = '<li><a href="' . esc_url( self::page_url( $key ? array( 'status' => $key ) : array() ) ) . '"' . $class . '>' . esc_html( $label ) . '</a>';
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		echo '<form method="get" class="coinolive-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		if ( $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		}
		echo '<label class="screen-reader-text" for="coinolive-order">' . esc_html__( 'Order number', 'coinolive' ) . '</label>';
		echo '<input type="search" id="coinolive-order" name="order_id" value="' . esc_attr( $order_id ) . '" placeholder="' . esc_attr__( 'Order number', 'coinolive' ) . '"> ';
		echo '<button class="button">' . esc_html__( 'Search', 'coinolive' ) . '</button></form>';

		try {
			$page = $client->list_payments(
				array(
					'status'   => $status,
					'order_id' => $order_id,
					'cursor'   => $cursor,
				)
			);
		} catch ( CoinoLive_Exception $e ) {
			echo '<br class="clear">';
			self::error( $e );
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped coinolive-payments"><thead><tr>';
		foreach ( array( __( 'Date', 'coinolive' ), __( 'Order', 'coinolive' ), __( 'Status', 'coinolive' ), __( 'Amount', 'coinolive' ), __( 'Received', 'coinolive' ), __( 'Transactions', 'coinolive' ), '' ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$payments = (array) ( $page['data'] ?? array() );
		if ( ! $payments ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No payments found.', 'coinolive' ) . '</td></tr>';
		}
		foreach ( $payments as $p ) {
			self::render_row( $p );
		}
		echo '</tbody></table>';

		$meta = (array) ( $page['meta'] ?? array() );
		$base = array_filter(
			array(
				'status'   => $status,
				'order_id' => $order_id,
			)
		);
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		if ( ! empty( $meta['prev_cursor'] ) ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $base + array( 'cursor' => $meta['prev_cursor'] ) ) ) . '">&larr; ' . esc_html__( 'Newer', 'coinolive' ) . '</a> ';
		}
		if ( ! empty( $meta['next_cursor'] ) ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $base + array( 'cursor' => $meta['next_cursor'] ) ) ) . '">' . esc_html__( 'Older', 'coinolive' ) . ' &rarr;</a>';
		}
		echo '</div></div>';
	}

	private static function render_row( array $p ) {
		$created = strtotime( (string) $p['created_at'] );
		echo '<tr>';
		echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) ) . '</td>';

		// Link to the WooCommerce order when it is one of this shop's.
		$wc_id = (int) ( $p['metadata']['wc_order_id'] ?? 0 );
		$order = $wc_id ? wc_get_order( $wc_id ) : false;
		if ( $order && $order->get_meta( CoinoLive_Gateway::META_PAYMENT ) === $p['id'] ) {
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
		} else {
			echo '<td>' . esc_html( $p['order_id'] ? '#' . $p['order_id'] : '—' ) . '</td>';
		}

		$labels = self::status_labels();
		echo '<td><mark class="coinolive-status coinolive-status-' . esc_attr( $p['status'] ) . '">' . esc_html( $labels[ $p['status'] ] ?? $p['status'] ) . '</mark></td>';

		echo '<td>' . esc_html( $p['requested_amount'] . ' ' . $p['requested_currency'] );
		if ( $p['requested_currency'] !== $p['currency'] ) {
			echo '<br><small>' . esc_html( $p['amount'] . ' ' . $p['currency'] ) . '</small>';
		}
		echo '</td>';

		echo '<td>' . esc_html( self::money( (string) $p['received_usd'] ) . ' USD' );
		if ( 'partially_paid' === $p['status'] || ( 'expired' === $p['status'] && 1 === self::cmp( (string) $p['received_usd'], '0' ) ) ) {
			/* translators: %s: USD amount still missing */
			echo '<br><small class="coinolive-short">' . esc_html( sprintf( __( '%s USD missing', 'coinolive' ), self::money( (string) $p['remaining_usd'] ) ) ) . '</small>';
		}
		echo '</td><td>';
		$deposits = (array) ( $p['deposits'] ?? array() );
		if ( ! $deposits ) {
			echo '—';
		}
		foreach ( $deposits as $d ) {
			$hash = (string) $d['tx_hash'];
			$text = strlen( $hash ) > 16 ? substr( $hash, 0, 8 ) . '…' . substr( $hash, -6 ) : $hash;
			$coin = (string) ( $d['symbol'] ?? strtoupper( (string) $d['asset'] ) );
			echo '<div class="coinolive-tx">' . esc_html( $d['amount'] . ' ' . $coin );
			if ( ! empty( $d['network'] ) ) {
				echo ' <small>' . esc_html( $d['network'] ) . '</small>';
			}
			echo '<br>';
			if ( ! empty( $d['explorer_url'] ) ) {
				echo '<a href="' . esc_url( $d['explorer_url'] ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $hash ) . '"><code>' . esc_html( $text ) . '</code></a>';
			} else {
				echo '<code title="' . esc_attr( $hash ) . '">' . esc_html( $text ) . '</code>';
			}
			echo '</div>';
		}
		echo '</td>';

		echo '<td>' . ( in_array( $p['status'], array( 'open', 'partially_paid' ), true ) ? '<a href="' . esc_url( $p['checkout_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Payment page', 'coinolive' ) . '</a>' : '' ) . '</td>';
		echo '</tr>';
	}

	/** @return array<string, string> */
	private static function status_labels() {
		return array(
			'paid'           => __( 'Paid', 'coinolive' ),
			'partially_paid' => __( 'Partially paid', 'coinolive' ),
			'confirming'     => __( 'Confirming', 'coinolive' ),
			'open'           => __( 'Waiting for payment', 'coinolive' ),
			'expired'        => __( 'Expired', 'coinolive' ),
			'cancelled'      => __( 'Cancelled', 'coinolive' ),
		);
	}

	/** The merchant dashboard next to the configured API ("…/api/v2" → "…/{locale}/dashboard"), or null. */
	private static function dashboard_url( array $settings ) {
		$api = untrailingslashit( trim( (string) ( $settings['api_url'] ?? '' ) ) );
		$api = '' === $api ? CoinoLive_Client::DEFAULT_URL : $api;
		if ( ! preg_match( '#^(https://[^/]+)/api/v2$#', $api, $m ) ) {
			return null;
		}
		$lang = substr( get_user_locale(), 0, 2 );
		return $m[1] . '/' . ( in_array( $lang, array( 'fa', 'tr', 'pt', 'ru' ), true ) ? $lang : 'en' ) . '/dashboard';
	}

	private static function error( CoinoLive_Exception $e ) {
		/* translators: %s: error message */
		echo '<div class="notice notice-error inline"><p>' . sprintf( esc_html__( 'Could not load from Coino Live: %s', 'coinolive' ), wp_kses( $e->getMessage(), array() ) ) . '</p></div>';
	}

	/** Decimal strings without floats (bcmath is not always installed). */
	private static function add( $a, $b ) {
		return function_exists( 'bcadd' ) ? bcadd( $a, $b, 2 ) : number_format( (float) $a + (float) $b, 2, '.', '' );
	}

	/** Compares two decimal strings. */
	private static function cmp( $a, $b ) {
		return function_exists( 'bccomp' ) ? bccomp( $a, $b, 18 ) : ( (float) $a <=> (float) $b );
	}

	private static function money( $amount ) {
		return number_format_i18n( (float) $amount, 2 );
	}
}

