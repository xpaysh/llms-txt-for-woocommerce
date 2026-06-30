<?php
/**
 * Clean uninstall — best-effort DELETE /v1/llms-txt/installs to remove our
 * install row from xpay.sh, restore the merchant's pre-existing original
 * file if we backed one up, drop the local version-history table, remove
 * static files from the webroot, drop options + cron hooks. Merchant
 * backups under /wp-content/uploads/lltxt-backups/ are intentionally
 * preserved.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 1. Best-effort uninstall ping — POST with context=uninstall so the row is
//    soft-marked (uninstalled_at set) and surfaces in the install-digest
//    churn cohort. A daily sweep at the backend hard-deletes any row whose
//    uninstalled_at is older than 180 days, so retention is bounded.
//    Synchronous, 5s timeout, errors ignored. Must read api_key + toggle +
//    slug BEFORE we delete the options below.
$lltxt_phone_home = (int) get_option( 'lltxt_phone_home', 0 );
$lltxt_api_key    = (string) get_option( 'lltxt_api_key', '' );
$lltxt_base_url   = (string) get_option(
	'lltxt_backend_base_url',
	'https://llmstxt-api.xpay.sh'
);
/** Honour the `lltxt_backend_base_url` filter so self-hosted backends are reached at uninstall too. */
$lltxt_base_url = (string) apply_filters( 'lltxt_backend_base_url', $lltxt_base_url );
$lltxt_host     = wp_parse_url( home_url(), PHP_URL_HOST );
$lltxt_slug     = is_string( $lltxt_host ) ? strtolower( str_replace( '.', '-', $lltxt_host ) ) : '';

if ( 1 === $lltxt_phone_home && '' !== $lltxt_api_key && '' !== $lltxt_slug ) {
	$lltxt_uninstall_url = untrailingslashit( $lltxt_base_url ) . '/v1/llms-txt/installs';
	wp_remote_request(
		$lltxt_uninstall_url,
		array(
			'method'  => 'POST',
			'timeout' => 5,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
				'X-Xpay-Api-Key' => hash( 'sha256', $lltxt_api_key ),
			),
			'body'    => wp_json_encode(
				array(
					'slug'     => $lltxt_slug,
					'home_url' => home_url( '/' ),
					'context'  => 'uninstall',
				)
			),
		)
	);
}

// 2. Restore the merchant's pre-existing originals back into the webroot, if
//    any were backed up at activation. Their file is theirs.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$lltxt_root    = trailingslashit( get_home_path() );
$lltxt_backups = get_option( 'lltxt_initial_backups', array() );
if ( is_array( $lltxt_backups ) ) {
	foreach ( $lltxt_backups as $lltxt_rel => $lltxt_meta ) {
		$lltxt_backup_path = isset( $lltxt_meta['backup_path'] ) ? (string) $lltxt_meta['backup_path'] : '';
		if ( '' === $lltxt_backup_path || ! file_exists( $lltxt_backup_path ) ) {
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$lltxt_body = @file_get_contents( $lltxt_backup_path );
		if ( false === $lltxt_body ) {
			continue;
		}
		$lltxt_full = $lltxt_root . ltrim( (string) $lltxt_rel, '/' );
		$lltxt_dir  = dirname( $lltxt_full );
		if ( ! is_dir( $lltxt_dir ) ) {
			wp_mkdir_p( $lltxt_dir );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
		@file_put_contents( $lltxt_full, $lltxt_body, LOCK_EX );
	}
}

// 3. Remove any plugin-generated static files still in the webroot (skip
//    routes the merchant had a backup for — those are theirs again now).
$lltxt_files = array(
	'llms.txt',
	'llms-full.txt',
);
foreach ( $lltxt_files as $lltxt_rel ) {
	if ( is_array( $lltxt_backups ) && isset( $lltxt_backups[ $lltxt_rel ] ) ) {
		continue; // restored above.
	}
	$lltxt_full = $lltxt_root . $lltxt_rel;
	if ( file_exists( $lltxt_full ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $lltxt_full );
	}
}

// 4. Drop the local version-history table.
require_once dirname( __FILE__ ) . '/includes/lib/class-lltxt-versions.php';
if ( class_exists( 'Lltxt_Versions' ) ) {
	Lltxt_Versions::drop_schema();
}

// 5. Delete all plugin options. Backups under /wp-content/uploads/lltxt-backups/
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

// 6. Clear scheduled cron hooks.
wp_clear_scheduled_hook( 'lltxt_daily_refresh' );
wp_clear_scheduled_hook( 'lltxt_refresh_now' );
wp_clear_scheduled_hook( 'lltxt_weekly_ping' );
