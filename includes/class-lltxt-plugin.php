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

		if ( is_admin() ) {
			Lltxt_Admin_Page::init();
			Lltxt_Product_Metabox::init();
			add_action( 'admin_notices', array( __CLASS__, 'render_first_run_notice' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_notice' ) );
		}
	}

	/**
	 * Dismissible disclosure on first activate.
	 *
	 * @return void
	 */
	public static function render_first_run_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( 'lltxt_show_first_run_notice' ) ) {
			return;
		}
		$settings_url = admin_url( 'options-general.php?page=' . Lltxt_Admin_Page::SLUG . '&tab=privacy' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'lltxt_dismiss_first_run', 1 ),
			'lltxt_dismiss_first_run'
		);
		$found        = get_transient( 'lltxt_first_activation_found_existing' );
		echo '<div class="notice notice-info is-dismissible"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: settings URL. */
				__( '<strong>LLMs.txt for WooCommerce</strong> keeps a version history of your /llms.txt and /llms-full.txt so you can roll back if needed. Turn it off in <a href="%s">Settings → LLMs.txt → Privacy</a>.', 'llms-txt-for-woocommerce' ),
				esc_url( $settings_url )
			)
		);
		if ( is_array( $found ) && ! empty( $found ) ) {
			echo '<br />';
			echo esc_html(
				sprintf(
					/* translators: %d: number of files. */
					_n( 'Found %d existing file in your webroot — backed up to /wp-content/uploads/lltxt-backups/, the plugin is now managing it. Restore your version any time from the Files tab.', 'Found %d existing files in your webroot — backed up to /wp-content/uploads/lltxt-backups/, the plugin is now managing them. Restore your versions any time from the Files tab.', count( $found ), 'llms-txt-for-woocommerce' ),
					count( $found )
				)
			);
		}
		echo ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:8px;">' . esc_html__( 'Dismiss', 'llms-txt-for-woocommerce' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Dismiss the first-run notice.
	 *
	 * @return void
	 */
	public static function maybe_dismiss_notice() {
		if ( ! isset( $_GET['lltxt_dismiss_first_run'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'lltxt_dismiss_first_run' );
		delete_transient( 'lltxt_show_first_run_notice' );
		delete_transient( 'lltxt_first_activation_found_existing' );
		wp_safe_redirect( remove_query_arg( array( 'lltxt_dismiss_first_run', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Require all class files.
	 */
	private function includes() {
		// Lib.
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-cache.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-catalog-reader.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-router.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-refresh.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-snapshot.php';
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
	 * Activation: register rewrite rules, schedule cron, flush.
	 */
	public static function activate() {
		// Make sure class files are loaded during activation.
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-cache.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-catalog-reader.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-router.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-refresh.php';
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-snapshot.php';
		require_once LLTXT_DIR . 'includes/emitters/interface-lltxt-emitter.php';

		Lltxt_Router::register_rules();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
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

		// Phone-home: default ON. Merchant can flip in Settings → LLMs.txt → Privacy.
		if ( false === get_option( Lltxt_Snapshot::OPT_PHONE_HOME, false ) ) {
			add_option( Lltxt_Snapshot::OPT_PHONE_HOME, 1, '', false );
		}

		// Bootstrap api_key once. Raw key never leaves the site — only sha256(key)
		// is sent in the X-Xpay-Api-Key header.
		if ( false === get_option( Lltxt_Snapshot::OPT_API_KEY, false ) ) {
			add_option( Lltxt_Snapshot::OPT_API_KEY, wp_generate_password( 64, false, false ), '', false );
		}

		// First-activation pass: back up any pre-existing emitted files in the
		// webroot, then snapshot the merchant's original to the version-history
		// backend with source=merchant-pre-existing so it appears in the
		// Version Control tab alongside future plugin-generated versions.
		require_once LLTXT_DIR . 'includes/lib/class-lltxt-snapshot.php';
		$found = array();
		foreach ( array_values( Lltxt_Router::routes() ) as $rel ) {
			$full = Lltxt_Cache::get_path( $rel );
			if ( ! file_exists( $full ) ) {
				continue;
			}
			// Capture body BEFORE backup_existing flips the route to plugin-managed.
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$existing_body = @file_get_contents( $full );
			if ( Lltxt_Cache::backup_existing( $rel ) ) {
				$found[] = $rel;
				if ( is_string( $existing_body ) && '' !== $existing_body ) {
					Lltxt_Snapshot::post_snapshot( $rel, $existing_body, 'merchant-pre-existing' );
				}
			}
		}
		if ( ! empty( $found ) ) {
			set_transient( 'lltxt_first_activation_found_existing', $found, DAY_IN_SECONDS );
		}

		// First-run admin notice for the phone-home disclosure.
		set_transient( 'lltxt_show_first_run_notice', 1, MONTH_IN_SECONDS );
	}

	/**
	 * Deactivation: clear cron and flush rewrite rules.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_DAILY );
		wp_clear_scheduled_hook( self::CRON_NOW );
		flush_rewrite_rules();
	}
}

