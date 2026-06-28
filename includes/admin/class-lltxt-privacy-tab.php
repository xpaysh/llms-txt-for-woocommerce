<?php
/**
 * Privacy tab — the master phone-home toggle + "delete my data" + the
 * full disclosure of what's sent to xpay.sh.
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
		check_admin_referer( 'lltxt_privacy' );

		$action = sanitize_key( wp_unslash( $_POST['lltxt_privacy_action'] ) );
		$notice = '';

		if ( 'save_toggle' === $action ) {
			$enabled = isset( $_POST['lltxt_phone_home'] ) ? 1 : 0;
			update_option( Lltxt_Snapshot::OPT_PHONE_HOME, $enabled, false );
			$notice = $enabled ? 'priv_enabled' : 'priv_disabled';
		} elseif ( 'reset_connection' === $action ) {
			Lltxt_Snapshot::reset_connection();
			// Re-baseline: post the currently-served bodies under the new key
			// so the merchant immediately sees their files in the version
			// history. Best-effort — non-blocking.
			foreach ( array_values( Lltxt_Router::routes() ) as $rel ) {
				$body = Lltxt_Cache::read( $rel );
				if ( is_string( $body ) && '' !== $body ) {
					Lltxt_Snapshot::post_snapshot( $rel, $body, 'plugin-generated', false );
				}
			}
			$notice = 'priv_reset_ok';
		} elseif ( 'delete_data' === $action ) {
			// Fire delete first WHILE phone-home is still enabled.
			$was_enabled = Lltxt_Snapshot::is_enabled();
			if ( ! $was_enabled ) {
				update_option( Lltxt_Snapshot::OPT_PHONE_HOME, 1, false );
			}
			$res = Lltxt_Snapshot::delete_merchant();
			update_option( Lltxt_Snapshot::OPT_PHONE_HOME, 0, false );
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
		self::render_mismatch_notice();
		$enabled  = Lltxt_Snapshot::is_enabled();
		$slug     = Lltxt_Snapshot::slug();
		$hash     = Lltxt_Snapshot::api_key_hash();
		$hash8    = substr( $hash, 0, 8 );
		$last     = (int) get_option( Lltxt_Snapshot::OPT_LAST_SNAPSHOT_TS, 0 );
		$base_url = Lltxt_Snapshot::base_url();
		?>
		<form method="post">
			<?php wp_nonce_field( 'lltxt_privacy' ); ?>
			<input type="hidden" name="lltxt_privacy_action" value="save_toggle" />
			<h3><?php esc_html_e( 'Version history sync', 'llms-txt-for-woocommerce' ); ?></h3>
			<p>
				<label>
					<input type="checkbox" name="lltxt_phone_home" value="1" <?php checked( $enabled, true ); ?> />
					<?php esc_html_e( 'Sync /llms.txt and /llms-full.txt so I can see version history and roll back.', 'llms-txt-for-woocommerce' ); ?>
				</label>
			</p>
			<div class="card" style="max-width:none; padding:1em; margin-bottom:1em;">
				<p><strong><?php esc_html_e( 'Exactly what is sent — and nothing else:', 'llms-txt-for-woocommerce' ); ?></strong></p>
				<ul style="list-style:disc; margin-left:2em;">
					<li><?php esc_html_e( 'The rendered /llms.txt and /llms-full.txt bodies (the same bodies served publicly from your domain).', 'llms-txt-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'WordPress, WooCommerce, and plugin version strings.', 'llms-txt-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'A sha256 of a randomly-generated api_key (raw key never leaves your site).', 'llms-txt-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Your slug (derived from your domain) and your home URL.', 'llms-txt-for-woocommerce' ); ?></li>
				</ul>
				<p><strong><?php esc_html_e( 'Not sent:', 'llms-txt-for-woocommerce' ); ?></strong>
					<?php esc_html_e( 'No order data, no customer data, no admin credentials, no analytics, no IP logging beyond standard HTTP.', 'llms-txt-for-woocommerce' ); ?></p>
				<p>
					<a href="https://xpay.sh/privacy" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy →', 'llms-txt-for-woocommerce' ); ?></a>
				</p>
			</div>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'llms-txt-for-woocommerce' ); ?></button></p>
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
				<td><code><?php echo esc_html( $hash8 ); ?>…</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Backend URL', 'llms-txt-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( $base_url ); ?></code>
					<p class="description"><?php esc_html_e( 'Override with the lltxt_backend_base_url filter or the lltxt_backend_base_url option.', 'llms-txt-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last sync', 'llms-txt-for-woocommerce' ); ?></th>
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

		<hr />

		<h3><?php esc_html_e( 'Delete my version data', 'llms-txt-for-woocommerce' ); ?></h3>
		<p class="description"><?php esc_html_e( 'This removes all the saved versions and turns sync off.', 'llms-txt-for-woocommerce' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'lltxt_privacy' ); ?>
			<input type="hidden" name="lltxt_privacy_action" value="delete_data" />
			<button type="submit" class="button button-secondary"
				onclick="return confirm('<?php echo esc_js( __( 'Delete all your data from xpay.sh and disable sync?', 'llms-txt-for-woocommerce' ) ); ?>');">
				<?php esc_html_e( 'Delete my data', 'llms-txt-for-woocommerce' ); ?>
			</button>
		</form>
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
			'priv_enabled'    => array( 'success', __( 'Version history sync is on. Snapshots will sync on every refresh.', 'llms-txt-for-woocommerce' ) ),
			'priv_disabled'   => array( 'success', __( 'Version history sync is off. Nothing will be sent.', 'llms-txt-for-woocommerce' ) ),
			'priv_deleted'    => array( 'success', __( 'Your version data was deleted and sync is now off.', 'llms-txt-for-woocommerce' ) ),
			'priv_delete_err' => array( 'error',   __( 'Could not delete your version data — try again, or contact support.', 'llms-txt-for-woocommerce' ) ),
			'priv_reset_ok'   => array( 'success', __( 'Connection reset. A new api_key was generated and your current files were re-baselined.', 'llms-txt-for-woocommerce' ) ),
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

	/**
	 * "This site was reinstalled or moved" notice + Reset connection button,
	 * shown when the backend has recently rejected our api_key. Public so the
	 * Version Control tab can render the same surface above its table.
	 *
	 * @return void
	 */
	public static function render_mismatch_notice() {
		if ( ! Lltxt_Snapshot::has_key_mismatch() ) {
			return;
		}
		// Always post to the Privacy tab handler so the same notice works
		// from either Privacy or Version Control tabs.
		$action_url = admin_url( 'options-general.php?page=' . Lltxt_Admin_Page::SLUG . '&tab=privacy' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Version history sync is failing.', 'llms-txt-for-woocommerce' ); ?></strong>
				<?php esc_html_e( 'This usually means the site was reinstalled or moved (the api_key on file no longer matches). Click Reset connection to start fresh — a new key is generated locally and your current files are re-baselined.', 'llms-txt-for-woocommerce' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'lltxt_privacy' ); ?>
				<input type="hidden" name="lltxt_privacy_action" value="reset_connection" />
				<button type="submit" class="button button-primary"
					onclick="return confirm('<?php echo esc_js( __( 'Reset the connection? A new api_key is generated and your current files become the new baseline.', 'llms-txt-for-woocommerce' ) ); ?>');">
					<?php esc_html_e( 'Reset connection', 'llms-txt-for-woocommerce' ); ?>
				</button>
			</form>
		</div>
		<?php
	}
}
