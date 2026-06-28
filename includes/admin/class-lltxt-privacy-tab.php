<?php
/**
 * Privacy tab — install-info toggle + delete-install-info button + site
 * identity. Version history lives in your own database; nothing about it is
 * sent here.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Privacy_Tab.
 */
class Lltxt_Privacy_Tab {

	/**
	 * Handle POST actions.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_POST['lltxt_privacy_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'lltxt_privacy' );

		$action = sanitize_key( wp_unslash( $_POST['lltxt_privacy_action'] ) );
		$notice = '';

		if ( 'save_toggle' === $action ) {
			$enabled = isset( $_POST['lltxt_phone_home'] ) ? 1 : 0;
			update_option( Lltxt_Install_Ping::OPT_ENABLED, $enabled, false );
			$notice = $enabled ? 'priv_enabled' : 'priv_disabled';
		} elseif ( 'delete_data' === $action ) {
			// Fire delete while still enabled so the request is actually sent.
			$was_enabled = Lltxt_Install_Ping::is_enabled();
			if ( ! $was_enabled ) {
				update_option( Lltxt_Install_Ping::OPT_ENABLED, 1, false );
			}
			$res = Lltxt_Install_Ping::delete_install();
			update_option( Lltxt_Install_Ping::OPT_ENABLED, 0, false );
			$notice = is_array( $res ) ? 'priv_deleted' : 'priv_delete_err';
		}

		wp_safe_redirect( Lltxt_Admin_Page::redirect_url( 'privacy', $notice ) );
		exit;
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		self::notice();
		$enabled  = Lltxt_Install_Ping::is_enabled();
		$slug     = Lltxt_Install_Ping::slug();
		$hash     = Lltxt_Install_Ping::api_key_hash();
		$hash8    = substr( $hash, 0, 8 );
		$last     = (int) get_option( Lltxt_Install_Ping::OPT_LAST_PING_TS, 0 );
		$base_url = Lltxt_Install_Ping::base_url();
		?>
		<form method="post">
			<?php wp_nonce_field( 'lltxt_privacy' ); ?>
			<input type="hidden" name="lltxt_privacy_action" value="save_toggle" />
			<h3><?php esc_html_e( 'Install info', 'llms-txt-for-woocommerce' ); ?></h3>
			<p>
				<label>
					<input type="checkbox" name="lltxt_phone_home" value="1" <?php checked( $enabled, true ); ?> />
					<?php esc_html_e( 'Send anonymous install info', 'llms-txt-for-woocommerce' ); ?>
				</label>
			</p>
			<p class="description">
				<?php esc_html_e( 'Your site URL and slug, your WordPress / WooCommerce / plugin versions, and your active product count are saved for diagnostics.', 'llms-txt-for-woocommerce' ); ?>
				<a href="https://xpay.sh/privacy" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'llms-txt-for-woocommerce' ); ?></a>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'llms-txt-for-woocommerce' ); ?></button>
				<button type="submit" class="button button-secondary" name="lltxt_privacy_action" value="delete_data"
					formnovalidate
					onclick="return confirm('<?php echo esc_js( __( 'Delete your install info from xpay.sh and turn the ping off?', 'llms-txt-for-woocommerce' ) ); ?>');">
					<?php esc_html_e( 'Delete my install info', 'llms-txt-for-woocommerce' ); ?>
				</button>
			</p>
		</form>

		<hr />

		<h3><?php esc_html_e( 'Site identity', 'llms-txt-for-woocommerce' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Slug', 'llms-txt-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( $slug ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API key hash (first 8)', 'llms-txt-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( $hash8 ); ?>&hellip;</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Backend URL', 'llms-txt-for-woocommerce' ); ?></th>
				<td>
					<code><?php echo esc_html( $base_url ); ?></code>
					<p class="description"><?php esc_html_e( 'Override with the lltxt_backend_base_url filter or option.', 'llms-txt-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last ping', 'llms-txt-for-woocommerce' ); ?></th>
				<td>
					<?php
					if ( $last > 0 ) {
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time diff. */
								__( '%s ago', 'llms-txt-for-woocommerce' ),
								human_time_diff( $last, time() )
							)
						);
					} else {
						esc_html_e( 'Never', 'llms-txt-for-woocommerce' );
					}
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the action notice.
	 *
	 * @return void
	 */
	private static function notice() {
		$code = isset( $_GET['lltxt_notice'] ) ? sanitize_key( wp_unslash( $_GET['lltxt_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			return;
		}
		$map = array(
			'priv_enabled'    => array( 'success', __( 'Install ping is on.', 'llms-txt-for-woocommerce' ) ),
			'priv_disabled'   => array( 'success', __( 'Install ping is off.', 'llms-txt-for-woocommerce' ) ),
			'priv_deleted'    => array( 'success', __( 'Your install info was deleted and the ping is now off.', 'llms-txt-for-woocommerce' ) ),
			'priv_delete_err' => array( 'error',   __( 'Could not delete your install info — try again, or contact support.', 'llms-txt-for-woocommerce' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] )
		);
	}
}
