<?php
/**
 * Plugin Name:       LLMs.txt for WooCommerce
 * Plugin URI:        https://xpay.sh
 * Description:       The llms.txt plugin specialised for commerce. Generates /llms.txt and /llms-full.txt from your live WooCommerce catalog so ChatGPT, Claude, Perplexity and Google AI can find and recommend your products.
 * Version:           1.0.0
 * Author:            xpay
 * Author URI:        https://xpay.sh
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       llms-txt-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.5
 * WC requires at least: 7.0
 * WC tested up to:   8.5
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

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'llms-txt-for-woocommerce', false, dirname( LLTXT_BASENAME ) . '/languages' );
		Lltxt_Plugin::instance();
	}
);
