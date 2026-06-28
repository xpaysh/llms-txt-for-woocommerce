<?php
/**
 * Clean uninstall — best-effort backend wipe (DELETE /v1/llms-txt/merchant),
 * then removes static files from the webroot, all lltxt_ options, and any
 * scheduled cron hooks. Merchant backups under /wp-content/uploads/lltxt-backups/
 * are intentionally preserved.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 1. Best-effort backend wipe — must read api_key + phone_home + slug BEFORE
//    we delete the options below. Synchronous, 5s timeout, errors ignored.
$lltxt_phone_home = (int) get_option( 'lltxt_phone_home', 1 );
$lltxt_api_key    = (string) get_option( 'lltxt_api_key', '' );
$lltxt_base_url   = (string) get_option(
	'lltxt_backend_base_url',
	'https://llmstxt-api.xpay.sh'
);
/** Honour the `lltxt_backend_base_url` filter so self-hosted backends are reached at uninstall too. */
$lltxt_base_url   = (string) apply_filters( 'lltxt_backend_base_url', $lltxt_base_url );
$lltxt_host       = wp_parse_url( home_url(), PHP_URL_HOST );
$lltxt_slug       = is_string( $lltxt_host ) ? strtolower( str_replace( '.', '-', $lltxt_host ) ) : '';

if ( 1 === $lltxt_phone_home && '' !== $lltxt_api_key && '' !== $lltxt_slug ) {
	$lltxt_delete_url = untrailingslashit( $lltxt_base_url ) . '/v1/llms-txt/merchant?slug=' . rawurlencode( $lltxt_slug );
	// phpcs:ignore WordPress.WP.AlternativeFunctions
	wp_remote_request(
		$lltxt_delete_url,
		array(
			'method'  => 'DELETE',
			'timeout' => 5,
			'headers' => array(
				'X-Xpay-Api-Key' => hash( 'sha256', $lltxt_api_key ),
				'Accept'         => 'application/json',
			),
		)
	);
}

// 2. Remove static files from the webroot.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$lltxt_root = trailingslashit( get_home_path() );

$lltxt_files = array(
	'llms.txt',
	'llms-full.txt',
	'index.md',
	'catalog.json',
	'products.json',
	'agents.md',
	'sitemap-ai.xml',
	'feed/google-shopping.xml',
	'.well-known/agent-card.json',
	'.well-known/mcp.json',
	'.well-known/ucp',
);

foreach ( $lltxt_files as $lltxt_rel ) {
	$lltxt_full = $lltxt_root . $lltxt_rel;
	if ( file_exists( $lltxt_full ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $lltxt_full );
	}
}

// 3. Delete all plugin options. Backups under /wp-content/uploads/lltxt-backups/
//    are intentionally NOT deleted — the merchant's original files belong to them.
delete_option( 'lltxt_allowed_bots' );
delete_option( 'lltxt_catalog_settings' );
delete_option( 'lltxt_file_timestamps' );
delete_option( 'lltxt_last_refresh_ts' );
delete_option( 'lltxt_refresh_log' );
delete_option( 'lltxt_file_modes' );
delete_option( 'lltxt_initial_backups' );
delete_option( 'lltxt_phone_home' );
delete_option( 'lltxt_api_key' );
delete_option( 'lltxt_backend_base_url' );
delete_option( 'lltxt_last_snapshot_ts' );

// 4. Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'lltxt_daily_refresh' );
wp_clear_scheduled_hook( 'lltxt_refresh_now' );
