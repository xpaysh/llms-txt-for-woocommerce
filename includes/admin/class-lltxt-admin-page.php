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
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_filter( 'plugin_action_links_' . LLTXT_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the Settings submenu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'LLMs.txt for WooCommerce', 'llms-txt-for-woocommerce' ),
			__( 'LLMs.txt', 'llms-txt-for-woocommerce' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url   = admin_url( 'options-general.php?page=' . self::SLUG );
		$label = esc_html__( 'Settings', 'llms-txt-for-woocommerce' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . $label . '</a>' );
		return $links;
	}

	/**
	 * Current tab key.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'files'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = self::tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'files';
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'llms-txt-for-woocommerce' ) );
		}
		$tabs    = self::tabs();
		$current = self::current_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LLMs.txt for WooCommerce', 'llms-txt-for-woocommerce' ); ?></h1>
			<?php if ( ! Lltxt_Catalog_Reader::wc_ready() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'WooCommerce is not active. The discovery files will be generated, but product data will be empty until WooCommerce is enabled.', 'llms-txt-for-woocommerce' ); ?>
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
