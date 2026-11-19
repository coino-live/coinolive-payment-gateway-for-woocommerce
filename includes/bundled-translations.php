<?php
/**
 * Translations shipped with the download from coino.live (Persian, Turkish, Portuguese, Russian).
 * The WordPress.org package leaves this file and the .po/.mo files out: there, translate.wordpress.org
 * delivers the translations and WordPress loads them on its own.
 *
 * @package coinolive
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'coinolive', false, dirname( plugin_basename( COINOLIVE_FILE ) ) . '/languages' );
	}
);
