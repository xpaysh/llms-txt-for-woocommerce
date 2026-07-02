<?php
/**
 * Settings page shell + tab router. Menu: Settings → LLMs.txt.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Admin_Page.
 */
class Lltxt_Admin_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'lltxt-settings';

	/**
	 * Tab definitions: tab key => [label, handler class].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function tabs() {
		return array(
			'ai-commerce'     => array( 'AI Commerce', 'Lltxt_Ai_Commerce_Tab' ),
			'files'           => array( 'Files', 'Lltxt_Files_Tab' ),
			'catalog'         => array( 'Catalog', 'Lltxt_Catalog_Tab' ),
			'version-control' => array( 'Version Control', 'Lltxt_Version_Control_Tab' ),
			'privacy'         => array( 'Privacy', 'Lltxt_Privacy_Tab' ),
			'diagnostics'     => array( 'Diagnostics', 'Lltxt_Diagnostics_Tab' ),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_activation_redirect' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . LLTXT_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Enqueue the AI Commerce tab stylesheet — ONLY on that screen/tab.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		if ( 'ai-commerce' !== self::current_tab() ) {
			return;
		}
		wp_enqueue_style(
			'lltxt-ai-commerce',
			LLTXT_URL . 'assets/css/ai-commerce.css',
			array( 'dashicons' ),
			LLTXT_VERSION
		);
	}

	/**
	 * Add the Settings submenu.
	 *
	 * @return void
	 */
	public static function menu() {
		// Page title stays the full descriptive name (shown as the page heading);
		// the Settings submenu label is kept short so it doesn't wrap in the sidebar
		// and doesn't get confused with the flagship "xpay Agentic Commerce" entry.
		add_options_page(
			__( 'Agentic Commerce – LLMs.txt for WooCommerce', 'agentic-commerce-llms-txt' ),
			__( 'WooCommerce LLMs.txt', 'agentic-commerce-llms-txt' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * One-time redirect to the AI Commerce screen right after activation.
	 *
	 * Fires only when the activation transient is set, and NEVER on bulk
	 * activation (`activate-multi`) — hijacking a bulk activate is the pattern
	 * reviewers/community object to. Also bails on AJAX, network admin, and for
	 * users who cannot manage the page. Runs at most once (transient deleted).
	 *
	 * @return void
	 */
	public static function maybe_activation_redirect() {
		if ( ! get_transient( 'lltxt_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'lltxt_activation_redirect' );

		// Do not steal the redirect during bulk plugin activation. This only reads
		// the presence of a core-set query flag (no form data, no nonce to verify).
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || is_network_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=ai-commerce' ) );
		exit;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url   = admin_url( 'options-general.php?page=' . self::SLUG );
		$label = esc_html__( 'Settings', 'agentic-commerce-llms-txt' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . $label . '</a>' );
		return $links;
	}

	/**
	 * Current tab key.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'ai-commerce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = self::tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'ai-commerce';
	}

	/**
	 * Dispatch POST/GET actions to the active tab handler before render.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab     = self::current_tab();
		$tabs    = self::tabs();
		$handler = $tabs[ $tab ][1];
		if ( class_exists( $handler ) && method_exists( $handler, 'handle' ) ) {
			call_user_func( array( $handler, 'handle' ) );
		}
	}

	/**
	 * Render the page chrome + active tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'agentic-commerce-llms-txt' ) );
		}
		$tabs    = self::tabs();
		$current = self::current_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agentic Commerce – LLMs.txt for WooCommerce', 'agentic-commerce-llms-txt' ); ?></h1>
			<?php if ( ! Lltxt_Catalog_Reader::wc_ready() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'WooCommerce is not active. The discovery files will be generated, but product data will be empty until WooCommerce is enabled.', 'agentic-commerce-llms-txt' ); ?>
				</p></div>
			<?php endif; ?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $def ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $key ) ); ?>"
						class="nav-tab <?php echo ( $current === $key ) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $def[0] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>
			<div class="lltxt-tab-content" style="margin-top:1em;">
				<?php
				$handler = $tabs[ $current ][1];
				if ( class_exists( $handler ) && method_exists( $handler, 'render' ) ) {
					call_user_func( array( $handler, 'render' ) );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Build a redirect URL back to a tab with a status message code.
	 *
	 * @param string $tab    Tab key.
	 * @param string $notice Notice code.
	 * @return string
	 */
	public static function redirect_url( $tab, $notice = '' ) {
		$args = array(
			'page' => self::SLUG,
			'tab'  => $tab,
		);
		if ( $notice ) {
			$args['lltxt_notice'] = $notice;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}
}
