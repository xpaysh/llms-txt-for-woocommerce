<?php
/**
 * Plugin Name:       LLMs.txt for WooCommerce
 * Plugin URI:        https://xpay.sh
 * Description:       Will ChatGPT recommend your WooCommerce store? This plugin makes it happen. Generates /llms.txt and /llms-full.txt from your live catalog so AI shopping agents find your products. Free forever.
 * Version:           1.0.0
 * Author:            xpay
 * Author URI:        https://xpay.sh
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       llms-txt-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      7.0
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'LLTXT_VERSION', '1.0.0' );
define( 'LLTXT_FILE', __FILE__ );
define( 'LLTXT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LLTXT_URL', plugin_dir_url( __FILE__ ) );
define( 'LLTXT_BASENAME', plugin_basename( __FILE__ ) );

require_once LLTXT_DIR . 'includes/class-lltxt-plugin.php';

register_activation_hook( __FILE__, array( 'Lltxt_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lltxt_Plugin', 'deactivate' ) );

// Translations are auto-loaded by WordPress for plugins hosted on wp.org
// since 4.6 — no manual load_plugin_textdomain() needed.
add_action( 'plugins_loaded', array( 'Lltxt_Plugin', 'instance' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
// We never read or write orders, so we are safe with either storage backend.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
