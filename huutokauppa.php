<?php
/**
 * Plugin Name:       Huutokauppa
 * Description:       Stand-alone online auctions: seller submissions, bidding with automatic (proxy) bids, anti-sniping, watch list and winner handling. No WooCommerce required.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Matti Mieskonen (Valveo)
 * Text Domain:       huutokauppa
 * Domain Path:       /languages
 *
 * Copyright (C) Matti Mieskonen (Valveo) - All Rights Reserved
 */

defined( 'ABSPATH' ) || exit;

define( 'HK_VERSION', '1.0.0' );
define( 'HK_DB_VERSION', '1' );
define( 'HK_FILE', __FILE__ );
define( 'HK_DIR', plugin_dir_path( __FILE__ ) );
define( 'HK_URL', plugin_dir_url( __FILE__ ) );

require_once HK_DIR . 'includes/functions.php';
require_once HK_DIR . 'includes/class-settings.php';
require_once HK_DIR . 'includes/class-install.php';
require_once HK_DIR . 'includes/class-post-types.php';
require_once HK_DIR . 'includes/class-item.php';
require_once HK_DIR . 'includes/class-bidding.php';
require_once HK_DIR . 'includes/class-mailer.php';
require_once HK_DIR . 'includes/class-winner.php';
require_once HK_DIR . 'includes/class-cron.php';
require_once HK_DIR . 'includes/class-users.php';
require_once HK_DIR . 'includes/class-rest.php';
require_once HK_DIR . 'includes/class-frontend.php';
require_once HK_DIR . 'includes/class-forms.php';

if ( is_admin() ) {
	require_once HK_DIR . 'includes/class-admin.php';
}

register_activation_hook( __FILE__, array( 'HK_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HK_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'huutokauppa', false, dirname( plugin_basename( HK_FILE ) ) . '/languages' );
		HK_Install::maybe_upgrade();
		HK_Settings::init();
		HK_Post_Types::init();
		HK_Cron::init();
		HK_Users::init();
		HK_Rest::init();
		HK_Frontend::init();
		HK_Forms::init();
		if ( is_admin() ) {
			HK_Admin::init();
		}
	}
);
