<?php
/**
 * Removes the plugin's settings. Orders keep their Coino Live notes and payment IDs.
 *
 * @package CoinoLive
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_coinolive_settings' );
delete_transient( 'coinolive_currencies' );
wp_clear_scheduled_hook( 'coinolive_sync_orders' );
