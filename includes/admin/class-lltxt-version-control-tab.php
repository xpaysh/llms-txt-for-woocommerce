<?php
/**
 * Version Control tab — list versions per route from the xpay.sh backend,
 * view/restore/pin/unpin, and ask for a recommended best version.
 *
 * "Restore" semantics = Restore + Pin (B): the restored version is written to
 * the webroot, then pinned both locally (Lltxt_Cache MODE_PINNED) and remotely
 * (POST /pin). Daily refresh skips pinned routes until the merchant unpins.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Version_Control_Tab.
 */
class Lltxt_Version_Control_Tab {

	/**
	 * Handle POST actions.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_POST['lltxt_vc_action'] ) ) {
			return;
		}
		check_admin_referer( 'lltxt_vc' );

		$action = sanitize_key( wp_unslash( $_POST['lltxt_vc_action'] ) );
		$route  = isset( $_POST['lltxt_route'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_route'] ) ) : 'llms.txt';
		$valid  = in_array( $route, array_values( Lltxt_Router::routes() ), true );
		if ( ! $valid ) {
			$route = 'llms.txt';
		}
		$notice = '';

		if ( 'sync_now' === $action ) {
			$res    = Lltxt_Refresh::run();
			$notice = ( 0 === $res['fail'] ) ? 'vc_sync_ok' : 'vc_sync_partial';
		} elseif ( 'restore' === $action || 'apply_recommendation' === $action ) {
			$version_id = isset( $_POST['lltxt_version_id'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_version_id'] ) ) : '';
			$ok         = self::do_restore( $route, $version_id );
			$notice     = $ok ? 'vc_restored' : 'vc_restore_err';
		} elseif ( 'unpin' === $action ) {
			$version_id = isset( $_POST['lltxt_version_id'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_version_id'] ) ) : '';
			$ok         = self::do_unpin( $route, $version_id );
			$notice     = $ok ? 'vc_unpinned' : 'vc_unpin_err';
		} elseif ( 'pin' === $action ) {
			$version_id = isset( $_POST['lltxt_version_id'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_version_id'] ) ) : '';
			$res        = Lltxt_Snapshot::pin( $version_id, true );
			if ( is_array( $res ) ) {
				Lltxt_Cache::set_mode( $route, Lltxt_Cache::MODE_PINNED );
				$notice = 'vc_pinned';
			} else {
				$notice = 'vc_pin_err';
			}
		}

		$url = add_query_arg( 'route', rawurlencode( $route ), Lltxt_Admin_Page::redirect_url( 'version-control', $notice ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Restore + pin a version.
	 *
	 * @param string $route      Route.
	 * @param string $version_id Version id.
	 * @return bool
	 */
	private static function do_restore( $route, $version_id ) {
		if ( '' === $version_id ) {
			return false;
		}
		$v = Lltxt_Snapshot::get_version( $version_id );
		if ( ! is_array( $v ) || ! isset( $v['body'] ) ) {
			return false;
		}
		// Force-write irrespective of current mode (this IS the merchant taking action).
		$res = Lltxt_Cache::write( $route, (string) $v['body'], true );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		// Pin remotely + locally.
		Lltxt_Snapshot::pin( $version_id, true );
		Lltxt_Cache::set_mode( $route, Lltxt_Cache::MODE_PINNED );
		return true;
	}

	/**
	 * Unpin a route → resume daily refresh.
	 *
	 * @param string $route      Route.
	 * @param string $version_id Version id.
	 * @return bool
	 */
	private static function do_unpin( $route, $version_id ) {
		if ( '' !== $version_id ) {
			Lltxt_Snapshot::pin( $version_id, false );
		}
		Lltxt_Cache::set_mode( $route, Lltxt_Cache::MODE_PLUGIN );
		return true;
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		self::notice();
		if ( class_exists( 'Lltxt_Privacy_Tab' ) ) {
			Lltxt_Privacy_Tab::render_mismatch_notice();
		}

		$enabled = Lltxt_Snapshot::is_enabled();
		$route   = isset( $_GET['route'] ) ? sanitize_text_field( wp_unslash( $_GET['route'] ) ) : 'llms.txt'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$routes  = array_values( Lltxt_Router::routes() );
		if ( ! in_array( $route, $routes, true ) ) {
			$route = 'llms.txt';
		}

		if ( ! $enabled ) {
			$privacy_url = admin_url( 'options-general.php?page=' . Lltxt_Admin_Page::SLUG . '&tab=privacy' );
			echo '<div class="notice notice-warning inline"><p>';
			echo wp_kses_post(
				sprintf(
					/* translators: %s: Privacy tab URL. */
					__( 'Sync is off. Enable it in the <a href="%s">Privacy tab</a> to see version history.', 'llms-txt-for-woocommerce' ),
					esc_url( $privacy_url )
				)
			);
			echo '</p></div>';
			return;
		}
		?>
		<p>
			<strong><?php esc_html_e( 'Backend:', 'llms-txt-for-woocommerce' ); ?></strong>
			<span style="color:#1a7f37;">●</span> <?php esc_html_e( 'enabled', 'llms-txt-for-woocommerce' ); ?>
			<form method="post" style="display:inline; margin-left:1em;">
				<?php wp_nonce_field( 'lltxt_vc' ); ?>
				<input type="hidden" name="lltxt_vc_action" value="sync_now" />
				<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Sync now', 'llms-txt-for-woocommerce' ); ?></button>
			</form>
		</p>

		<form method="get" style="margin:1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( Lltxt_Admin_Page::SLUG ); ?>" />
			<input type="hidden" name="tab" value="version-control" />
			<label for="lltxt-vc-route"><?php esc_html_e( 'Route:', 'llms-txt-for-woocommerce' ); ?></label>
			<select id="lltxt-vc-route" name="route" onchange="this.form.submit()">
				<?php foreach ( $routes as $r ) : ?>
					<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $route, $r ); ?>><?php echo esc_html( '/' . $r ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php self::render_recommendation_card( $route ); ?>
		<?php self::render_versions_table( $route ); ?>
		<?php
	}

	/**
	 * "Get a recommended best version" card.
	 *
	 * @param string $route Route.
	 * @return void
	 */
	private static function render_recommendation_card( $route ) {
		$rec = null;
		if ( isset( $_POST['lltxt_vc_recommend'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( check_admin_referer( 'lltxt_vc' ) ) {
				$rec = Lltxt_Snapshot::recommend( $route );
			}
		}
		?>
		<div class="card" style="padding:1em; margin-bottom:1em; max-width:none;">
			<h3><?php esc_html_e( 'Recommended best version', 'llms-txt-for-woocommerce' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Ask xpay.sh to merge the best of your version history into a single recommended body for this route.', 'llms-txt-for-woocommerce' ); ?>
			</p>
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( 'lltxt_vc' ); ?>
				<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
				<input type="hidden" name="lltxt_vc_recommend" value="1" />
				<button type="submit" class="button"><?php esc_html_e( 'Get recommendation', 'llms-txt-for-woocommerce' ); ?></button>
			</form>
			<?php if ( is_array( $rec ) && isset( $rec['body'] ) ) : ?>
				<h4 style="margin-top:1em;"><?php esc_html_e( 'Preview', 'llms-txt-for-woocommerce' ); ?></h4>
				<pre style="max-height:260px; overflow:auto; background:#f6f7f7; padding:8px; border:1px solid #ccd0d4;"><?php echo esc_html( substr( (string) $rec['body'], 0, 6000 ) ); ?></pre>
				<form method="post">
					<?php wp_nonce_field( 'lltxt_vc' ); ?>
					<input type="hidden" name="lltxt_vc_action" value="apply_recommendation" />
					<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
					<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( isset( $rec['version_id'] ) ? $rec['version_id'] : '' ); ?>" />
					<button type="submit" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( 'Apply this recommendation? This restores + pins it; daily refresh will pause for this route until you unpin.', 'llms-txt-for-woocommerce' ) ); ?>');">
						<?php esc_html_e( 'Apply this', 'llms-txt-for-woocommerce' ); ?>
					</button>
				</form>
			<?php elseif ( is_wp_error( $rec ) ) : ?>
				<p class="notice notice-error inline"><?php echo esc_html( $rec->get_error_message() ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Versions table.
	 *
	 * @param string $route Route.
	 * @return void
	 */
	private static function render_versions_table( $route ) {
		$cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res    = Lltxt_Snapshot::list_versions( $route, $cursor );
		if ( is_wp_error( $res ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
			return;
		}
		if ( ! is_array( $res ) || empty( $res['versions'] ) ) {
			echo '<p><em>' . esc_html__( 'No versions yet. They\'ll appear here after the first refresh.', 'llms-txt-for-woocommerce' ) . '</em></p>';
			return;
		}
		$local_mode = Lltxt_Cache::get_mode( $route );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Created', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Source', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Bytes', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Sha', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Pinned', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'llms-txt-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $res['versions'] as $v ) :
				$vid      = isset( $v['version_id'] ) ? (string) $v['version_id'] : '';
				$created  = isset( $v['created_at'] ) ? (string) $v['created_at'] : '';
				$source   = isset( $v['source'] ) ? (string) $v['source'] : '';
				$bytes    = isset( $v['bytes'] ) ? (int) $v['bytes'] : 0;
				$sha      = isset( $v['sha256'] ) ? substr( (string) $v['sha256'], 0, 8 ) : '';
				$pinned   = ! empty( $v['pinned'] );
			?>
				<tr>
					<td><code><?php echo esc_html( $created ); ?></code></td>
					<td><?php echo esc_html( $source ); ?></td>
					<td><?php echo esc_html( (string) $bytes ); ?></td>
					<td><code><?php echo esc_html( $sha ); ?></code></td>
					<td><?php if ( $pinned ) { echo '📌 ' . esc_html__( 'Pinned — refresh paused for this route', 'llms-txt-for-woocommerce' ); } ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'lltxt_vc' ); ?>
							<input type="hidden" name="lltxt_vc_action" value="restore" />
							<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
							<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( $vid ); ?>" />
							<button type="submit" class="button button-small"
								onclick="return confirm('<?php echo esc_js( __( 'Restore + pin this version? Daily refresh will pause for this route until you unpin.', 'llms-txt-for-woocommerce' ) ); ?>');">
								<?php esc_html_e( 'Restore', 'llms-txt-for-woocommerce' ); ?>
							</button>
						</form>
						<?php if ( $pinned || Lltxt_Cache::MODE_PINNED === $local_mode ) : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'lltxt_vc' ); ?>
								<input type="hidden" name="lltxt_vc_action" value="unpin" />
								<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
								<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( $vid ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Unpin', 'llms-txt-for-woocommerce' ); ?></button>
							</form>
						<?php else : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'lltxt_vc' ); ?>
								<input type="hidden" name="lltxt_vc_action" value="pin" />
								<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
								<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( $vid ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Pin', 'llms-txt-for-woocommerce' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $res['next_cursor'] ) ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url(
					add_query_arg(
						array(
							'route'  => rawurlencode( $route ),
							'cursor' => rawurlencode( $res['next_cursor'] ),
						),
						admin_url( 'options-general.php?page=' . Lltxt_Admin_Page::SLUG . '&tab=version-control' )
					)
				); ?>"><?php esc_html_e( 'Load more', 'llms-txt-for-woocommerce' ); ?></a>
			</p>
		<?php endif; ?>
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
			'vc_sync_ok'     => array( 'success', __( 'Sync complete. Snapshots posted to xpay.sh.', 'llms-txt-for-woocommerce' ) ),
			'vc_sync_partial'=> array( 'warning', __( 'Sync ran with some emitter errors — see Diagnostics.', 'llms-txt-for-woocommerce' ) ),
			'vc_restored'    => array( 'success', __( 'Restored. Refresh paused for this route — unpin in Version Control to resume.', 'llms-txt-for-woocommerce' ) ),
			'vc_restore_err' => array( 'error',   __( 'Could not restore that version.', 'llms-txt-for-woocommerce' ) ),
			'vc_unpinned'    => array( 'success', __( 'Resumed daily refresh for this route.', 'llms-txt-for-woocommerce' ) ),
			'vc_unpin_err'   => array( 'error',   __( 'Could not unpin.', 'llms-txt-for-woocommerce' ) ),
			'vc_pinned'      => array( 'success', __( 'Pinned. Daily refresh is paused for this route.', 'llms-txt-for-woocommerce' ) ),
			'vc_pin_err'     => array( 'error',   __( 'Could not pin.', 'llms-txt-for-woocommerce' ) ),
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
