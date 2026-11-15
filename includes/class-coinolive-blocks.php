<?php
/**
 * The payment method in the block checkout.
 *
 * @package CoinoLive
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class CoinoLive_Blocks extends AbstractPaymentMethodType {

	protected $name = 'coinolive';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_coinolive_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['coinolive'] ) && $gateways['coinolive']->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'coinolive-blocks',
			COINOLIVE_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			COINOLIVE_VERSION,
			true
		);
		return array( 'coinolive-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Pay with crypto', 'coinolive' ) ),
			'description' => $this->get_setting( 'description' ),
			'icon'        => COINOLIVE_URL . 'assets/images/coinolive.svg',
			'supports'    => array( 'products' ),
		);
	}
}
