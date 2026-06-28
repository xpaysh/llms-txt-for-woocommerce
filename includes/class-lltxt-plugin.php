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

		// Hook-only emitters (no static file).
		add_filter( 'robots_txt', array( 'Lltxt_Emit_Robots_Txt', 'filter_robots_txt' ), 20, 2 );
		add_action( 'wp_head', array( 'Lltxt_Emit_Head_Discovery', 'render_head' ), 5 );

		if ( is_admin() ) {
			Lltxt_Admin_Page::init();
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
				__( '<strong>LLMs.txt for WooCommerce</strong> syncs your AI-discovery files to xpay.sh so you can see version history and roll back. Toggle off in <a href="%s">Settings → LLMs.txt → Privacy</a>.', 'llms-txt-for-woocommerce' ),
				esc_url( $settings_url )
			)
		);
		if ( is_array( $found ) && ! empty( $found ) ) {
			echo '<br />';
			echo esc_html(
				sprintf(
					/* translators: %d: number of files. */
					_n( 'Found %d existing file in your webroot. Backed up to /wp-content/uploads/lltxt-backups/ — we won\'t overwrite it.', 'Found %d existing files in your webroot. Backed up to /wp-content/uploads/lltxt-backups/ — we won\'t overwrite them.', count( $found ), 'llms-txt-for-woocommerce' ),
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

		// Emitters.
		require_once LLTXT_DIR . 'includes/emitters/interface-lltxt-emitter.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-llms-txt.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-index-md.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-llms-full-txt.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-catalog-json.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-products-json.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-agent-card.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-mcp-json.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-ucp.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-agents-md.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-sitemap-ai.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-robots-txt.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-google-shopping-xml.php';
		require_once LLTXT_DIR . 'includes/emitters/class-lltxt-emit-head-discovery.php';

		// Admin.
		if ( is_admin() ) {
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-admin-page.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-files-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-bots-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-catalog-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-version-control-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-privacy-tab.php';
			require_once LLTXT_DIR . 'includes/admin/class-lltxt-diagnostics-tab.php';
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
			'Lltxt_Emit_Index_Md',
			'Lltxt_Emit_Llms_Full_Txt',
			'Lltxt_Emit_Catalog_Json',
			'Lltxt_Emit_Products_Json',
			'Lltxt_Emit_Agent_Card',
			'Lltxt_Emit_Mcp_Json',
			'Lltxt_Emit_Ucp',
			'Lltxt_Emit_Agents_Md',
			'Lltxt_Emit_Sitemap_Ai',
			'Lltxt_Emit_Google_Shopping_Xml',
			'Lltxt_Emit_Robots_Txt',
			'Lltxt_Emit_Head_Discovery',
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
		if ( false === get_option( 'lltxt_allowed_bots', false ) ) {
			add_option( 'lltxt_allowed_bots', Lltxt_Emit_Robots_Txt_defaults() );
		}
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
		// webroot so we never silently clobber a merchant's hand-authored file.
		$found = array();
		foreach ( array_values( Lltxt_Router::routes() ) as $rel ) {
			$full = Lltxt_Cache::get_path( $rel );
			if ( file_exists( $full ) ) {
				if ( Lltxt_Cache::backup_existing( $rel ) ) {
					$found[] = $rel;
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

/**
 * Default allowed-bots map. Declared as a function so activation can call it
 * before the emitter class file is required.
 *
 * @return array<string,bool>
 */
function Lltxt_Emit_Robots_Txt_defaults() {
	return array(
		'ChatGPT-User'  => true,
		'Claude-User'   => true,
		'PerplexityBot' => true,
		'GoogleOther'   => true,
		'OAI-SearchBot' => true,
		'GPTBot'        => false, // Training crawler, not a shopping agent — default OFF.
	);
}
