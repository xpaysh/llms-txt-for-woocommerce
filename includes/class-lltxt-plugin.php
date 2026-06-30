<?php
/**
 * Main plugin singleton — wires up emitters, router, refresh, admin.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Plugin.
 */
final class Lltxt_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Lltxt_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Cron hook name for the daily refresh.
	 */
	const CRON_DAILY = 'lltxt_daily_refresh';

	/**
	 * One-off hook used for the stale-fallback refresh.
	 */
	const CRON_NOW = 'lltxt_refresh_now';

	/**
	 * Weekly install ping hook.
	 */
	const CRON_WEEKLY_PING = 'lltxt_weekly_ping';

	/**
	 * Get (or create) the singleton.
	 *
	 * @return Lltxt_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — load dependencies and register hooks.
	 */
	private function __construct() {
		$this->includes();

		Lltxt_Router::init();
		Lltxt_Refresh::init();

		add_action( self::CRON_WEEKLY_PING, array( __CLASS__, 'run_weekly_ping' ) );

		if ( is_admin() ) {
			Lltxt_Admin_Page::init();
			Lltxt_Product_Metabox::init();
		}
	}

	/**
	 * Weekly install-ping cron handler.
	 *
	 * @return void
	 */
	public static function run_weekly_ping() {
		if ( class_exists( 'Lltxt_Install_Ping' ) ) {
			Lltxt_Install_Ping::ping( 'weekly' );
		}
	}

/**
	 * Require all class files.
	 */
	private function includes() {
		// Lib.
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-cache.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-catalog-reader.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-router.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-versions.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-refresh.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-install-ping.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-seo-bridge.php';

		// Emitters — just llms.txt + llms-full.txt. WC-specialized content.
		require_once LLTXT_DIR . 'includes/emitters/interface-lltxt-emitter.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-llms-txt.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-llms-full-txt.php';

		// Admin.
		if ( is_admin() ) {
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-admin-page.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-files-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-catalog-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-version-control-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-privacy-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-diagnostics-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-product-metabox.php';
		}
	}

	/**
	 * Return the canonical list of emitter class names.
	 *
	 * Order matters for the "Regenerate All" UI listing only.
	 *
	 * @return string[]
	 */
	public static function emitter_classes() {
		return array(
			'Lltxt_Emit_Llms_Txt',
			'Lltxt_Emit_Llms_Full_Txt',
		);
	}

	/**
	 * Instantiate a fresh emitter object by class name.
	 *
	 * @param string $class Emitter class name.
	 * @return Lltxt_Emitter_Interface|null
	 */
	public static function make_emitter( $class ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$obj = new $class();
		return ( $obj instanceof Lltxt_Emitter_Interface ) ? $obj : null;
	}

	/**
	 * Activation: install schema, register rewrite rules, schedule cron,
	 * fire the initial install ping.
	 */
	public static function activate() {
		// Make sure class files are loaded during activation.
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-cache.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-catalog-reader.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-router.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-versions.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-refresh.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-install-ping.php';
		require_once LLTXT_DIR . 'includes/emitters/interface-lltxt-emitter.php';

		// Custom table for local version history.
		Lltxt_Versions::install_schema();

		// FIRST: capture whatever is currently being served at each of our
		// routes — BEFORE we register our own rewrite rules. Covers three
		// cases the on-disk file_exists() check misses:
		//   (a) static /llms.txt at a non-standard webroot path (Bedrock etc.)
		//   (b) another plugin serving /llms.txt dynamically (no static file)
		//   (c) a CDN edge-cached body even though the origin file is gone
		// The HTTP self-fetch is the source of truth for "what does a
		// crawler see when they hit /llms.txt right now". We record any
		// non-empty body as merchant-pre-existing so the merchant never
		// loses their prior content.
		$captured = array();
		foreach ( array_values( Lltxt_Router::routes() ) as $rel ) {
			$body = self::capture_existing_route_body( $rel );
			if ( is_string( $body ) && '' !== $body ) {
				Lltxt_Versions::insert( $rel, $body, 'merchant-pre-existing' );
				$captured[ $rel ] = strlen( $body );
				// Also persist to the on-disk backup if we can resolve a path
				// for it (best-effort — backup_existing handles the
				// file_exists check internally and is a no-op otherwise).
				Lltxt_Cache::backup_existing( $rel );
			}
		}

		Lltxt_Router::register_rules();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
		if ( ! wp_next_scheduled( self::CRON_WEEKLY_PING ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_WEEKLY_PING );
		}

		// Seed defaults without clobbering existing operator choices.
		if ( false === get_option( 'lltxt_catalog_settings', false ) ) {
			add_option(
				'lltxt_catalog_settings',
				array(
					'top_n'        => 100,
					'orderby'      => 'total_sales',
					'include_cats' => array(),
					'exclude_cats' => array(),
				)
			);
		}

		// Install ping is opt-in. We do not seed OPT_ENABLED, do not generate
		// an api_key, and do not fire any ping on activation. Both happen
		// lazily only after the merchant turns the toggle on from
		// Settings → Agentic Commerce → Privacy.
	}

	/**
	 * Capture whatever is currently being served at a route, BEFORE we
	 * register our own rewrite rules. Tries the static file first; falls
	 * back to a loopback HTTP fetch so we catch dynamically-served files
	 * from other llms.txt plugins. Returns null on any failure.
	 *
	 * @param string $rel Route relative path (e.g. 'llms.txt').
	 * @return string|null File body, or null if nothing meaningful was served.
	 */
	private static function capture_existing_route_body( $rel ) {
		// 1. Static file at the resolved webroot path.
		$full = Lltxt_Cache::get_path( $rel );
		if ( file_exists( $full ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$body = @file_get_contents( $full );
			if ( is_string( $body ) && '' !== $body ) {
				return $body;
			}
		}
		// 2. Loopback HTTP fetch — catches dynamic routes from other plugins,
		// non-standard webroot layouts, and CDN-edge bodies. We're still
		// inside our activate() callback so our own rewrite rules are NOT
		// yet flushed to the cache → the request hits the prior handler.
		$url        = Lltxt_Cache::get_url( $rel );
		$host       = wp_parse_url( $url, PHP_URL_HOST );
		$is_local   = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		$resp       = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				// Only skip SSL verification for loopback hosts that commonly
				// use self-signed certs. Public hosts must verify normally.
				'sslverify'   => ! $is_local,
				'headers'     => array(
					'User-Agent' => 'AgenticCommerceLlmsTxt/preflight',
					'Accept'     => 'text/plain, */*',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		// Ignore obvious 404/empty pages that came back as 200 (some hosts
		// return a styled "Not Found" with a 200 + tiny HTML body).
		if ( '' === $body || strlen( $body ) < 16 ) {
			return null;
		}
		return $body;
	}

	/**
	 * Deactivation: clear cron, flush rewrite rules, fire a deactivate ping
	 * so the install digest can show this row as dormant (vs uninstalled,
	 * which is irreversible). Blocking so the request actually goes out
	 * before WP unloads the plugin.
	 */
	public static function deactivate() {
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-install-ping.php';
		// ping() is blocking via wp_remote_post default — completes before
		// WP unloads the plugin and clears the cron hooks below.
		Lltxt_Install_Ping::ping( 'deactivate' );
		wp_clear_scheduled_hook( self::CRON_DAILY );
		wp_clear_scheduled_hook( self::CRON_NOW );
		wp_clear_scheduled_hook( self::CRON_WEEKLY_PING );
		flush_rewrite_rules();
	}
}
