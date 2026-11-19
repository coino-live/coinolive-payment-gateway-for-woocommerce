<?php
/**
 * Plugin Name:          Coino Live Crypto Payments
 * Plugin URI:           https://coino.live/woocommerce
 * Description:          Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more in WooCommerce. Customers pay on the Coino Live checkout; orders update automatically.
 * Version:              1.1.3
 * Author:               Coino Live
 * Author URI:           https://coino.live
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          coinolive
 * Domain Path:          /languages
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.6
 * WC tested up to:      9.9
 */

defined( 'ABSPATH' ) || exit;

define( 'COINOLIVE_VERSION', '1.1.3' );
define( 'COINOLIVE_FILE', __FILE__ );
define( 'COINOLIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'COINOLIVE_URL', plugin_dir_url( __FILE__ ) );

// High-performance order storage and the block checkout are both supported.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Only in the download from coino.live (see the file); WordPress.org delivers translations itself.
if ( is_readable( COINOLIVE_DIR . 'includes/bundled-translations.php' ) ) {
	require_once COINOLIVE_DIR . 'includes/bundled-translations.php';
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Coino Live Crypto Payments needs WooCommerce to be installed and active.', 'coinolive' ) . '</p></div>';
				}
			);
			return;
		}

		require_once COINOLIVE_DIR . 'includes/class-coinolive-client.php';
		require_once COINOLIVE_DIR . 'includes/class-coinolive-gateway.php';
		require_once COINOLIVE_DIR . 'includes/class-coinolive-webhook.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'CoinoLive_Gateway';
				return $gateways;
			}
		);

		CoinoLive_Webhook::init();
		CoinoLive_Gateway::init_hooks();

		if ( is_admin() ) {
			require_once COINOLIVE_DIR . 'includes/class-coinolive-admin.php';
			CoinoLive_Admin::init();
		}
	}
);

// Block checkout (the default checkout since WooCommerce 8.3).
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once COINOLIVE_DIR . 'includes/class-coinolive-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new CoinoLive_Blocks() );
			}
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=coinolive' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'coinolive' ) . '</a>' );
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=coinolive' ) ) . '">' . esc_html__( 'Dashboard', 'coinolive' ) . '</a>' );
		return $links;
	}
);

// Safety net for missed webhooks: re-check open orders every five minutes.
add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$schedules['coinolive_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'coinolive' ),
		);
		return $schedules;
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'coinolive_sync_orders' ) ) {
			wp_schedule_event( time() + 300, 'coinolive_five_minutes', 'coinolive_sync_orders' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'coinolive_sync_orders' );
	}
);
