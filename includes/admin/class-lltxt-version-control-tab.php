<?php
/**
 * Version Control tab — list, restore and pin versions of /llms.txt and
 * /llms-full.txt straight from this site's database. Nothing leaves the site.
 *
 * "Restore" semantics = Restore + Pin: the restored body is written to the
 * webroot and the route is flipped to MODE_PINNED. Daily refresh skips pinned
 * routes until the merchant unpins.
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
			$res = Lltxt_Refresh::run();
			if ( ! empty( $res['fail'] ) ) {
				$notice = 'vc_sync_partial';
			} elseif ( ! empty( $res['skipped'] ) ) {
				set_transient( 'lltxt_last_skipped_vc', $res['skipped'], HOUR_IN_SECONDS );
				$notice = 'vc_sync_skipped';
			} else {
				$notice = 'vc_sync_ok';
			}
		} elseif ( 'restore' === $action ) {
			$id     = isset( $_POST['lltxt_version_id'] ) ? (int) $_POST['lltxt_version_id'] : 0;
			$ok     = self::do_restore( $route, $id );
			$notice = $ok ? 'vc_restored' : 'vc_restore_err';
		} elseif ( 'unpin' === $action ) {
			$id = isset( $_POST['lltxt_version_id'] ) ? (int) $_POST['lltxt_version_id'] : 0;
			if ( $id > 0 ) {
				Lltxt_Versions::pin( $id, false );
			}
			Lltxt_Cache::set_mode( $route, Lltxt_Cache::MODE_PLUGIN );
			$notice = 'vc_unpinned';
		} elseif ( 'pin' === $action ) {
			$id = isset( $_POST['lltxt_version_id'] ) ? (int) $_POST['lltxt_version_id'] : 0;
			if ( $id > 0 && Lltxt_Versions::pin( $id, true ) ) {
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
	 * Restore + pin a version from the local store.
	 *
	 * @param string $route Route.
	 * @param int    $id    Row id.
	 * @return bool
	 */
	private static function do_restore( $route, $id ) {
		if ( $id <= 0 ) {
			return false;
		}
		$body = Lltxt_Versions::read_body( $id );
		if ( null === $body ) {
			return false;
		}
		$res = Lltxt_Cache::write( $route, $body, true );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		Lltxt_Versions::pin( $id, true );
		Lltxt_Cache::set_mode( $route, Lltxt_Cache::MODE_PINNED );
		// Record the restored body itself as a version so the audit trail is complete.
		Lltxt_Versions::insert( $route, $body, 'restored' );
		return true;
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		self::notice();

		$route  = isset( $_GET['route'] ) ? sanitize_text_field( wp_unslash( $_GET['route'] ) ) : 'llms.txt'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$routes = array_values( Lltxt_Router::routes() );
		if ( ! in_array( $route, $routes, true ) ) {
			$route = 'llms.txt';
		}
		?>
		<p>
			<?php esc_html_e( 'Every refresh is kept in this site\'s database for 90 days. Pin a version to freeze it and pause refresh for that file.', 'agentic-commerce-llms-txt' ); ?>
			<form method="post" style="display:inline; margin-left:1em;">
				<?php wp_nonce_field( 'lltxt_vc' ); ?>
				<input type="hidden" name="lltxt_vc_action" value="sync_now" />
				<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Refresh now', 'agentic-commerce-llms-txt' ); ?></button>
			</form>
		</p>

		<form method="get" style="margin:1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( Lltxt_Admin_Page::SLUG ); ?>" />
			<input type="hidden" name="tab" value="version-control" />
			<label for="lltxt-vc-route"><?php esc_html_e( 'Route:', 'agentic-commerce-llms-txt' ); ?></label>
			<select id="lltxt-vc-route" name="route" onchange="this.form.submit()">
				<?php foreach ( $routes as $r ) : ?>
					<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $route, $r ); ?>><?php echo esc_html( '/' . $r ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php self::render_versions_table( $route ); ?>
		<?php
	}

	/**
	 * Versions table.
	 *
	 * @param string $route Route.
	 * @return void
	 */
	private static function render_versions_table( $route ) {
		$cursor = isset( $_GET['cursor'] ) ? (int) $_GET['cursor'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = Lltxt_Versions::list( $route, 25, $cursor );
		$rows   = isset( $page['rows'] ) ? $page['rows'] : array();

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No versions yet. They\'ll appear here after the first refresh.', 'agentic-commerce-llms-txt' ) . '</em></p>';
			return;
		}
		$local_mode = Lltxt_Cache::get_mode( $route );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Created', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Source', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Bytes', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Sha', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Pinned', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'agentic-commerce-llms-txt' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $v ) :
				$id      = (int) $v['id'];
				$created = (string) $v['created_at'];
				$source  = (string) $v['source'];
				$bytes   = (int) $v['bytes'];
				$sha     = substr( (string) $v['sha256'], 0, 8 );
				$pinned  = ! empty( $v['pinned'] );
			?>
				<tr>
					<td><code><?php echo esc_html( $created ); ?></code></td>
					<td><?php echo esc_html( $source ); ?></td>
					<td><?php echo esc_html( (string) $bytes ); ?></td>
					<td><code><?php echo esc_html( $sha ); ?></code></td>
					<td><?php if ( $pinned ) { echo esc_html__( 'Pinned — refresh paused for this route', 'agentic-commerce-llms-txt' ); } ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'lltxt_vc' ); ?>
							<input type="hidden" name="lltxt_vc_action" value="restore" />
							<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
							<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( (string) $id ); ?>" />
							<button type="submit" class="button button-small"
								onclick="return confirm('<?php echo esc_js( __( 'Restore + pin this version? Daily refresh will pause for this route until you unpin.', 'agentic-commerce-llms-txt' ) ); ?>');">
								<?php esc_html_e( 'Restore', 'agentic-commerce-llms-txt' ); ?>
							</button>
						</form>
						<?php if ( $pinned || Lltxt_Cache::MODE_PINNED === $local_mode ) : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'lltxt_vc' ); ?>
								<input type="hidden" name="lltxt_vc_action" value="unpin" />
								<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
								<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( (string) $id ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Unpin', 'agentic-commerce-llms-txt' ); ?></button>
							</form>
						<?php else : ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'lltxt_vc' ); ?>
								<input type="hidden" name="lltxt_vc_action" value="pin" />
								<input type="hidden" name="lltxt_route" value="<?php echo esc_attr( $route ); ?>" />
								<input type="hidden" name="lltxt_version_id" value="<?php echo esc_attr( (string) $id ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Pin', 'agentic-commerce-llms-txt' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $page['next_cursor'] ) ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url(
					add_query_arg(
						array(
							'route'  => rawurlencode( $route ),
							'cursor' => (int) $page['next_cursor'],
						),
						admin_url( 'options-general.php?page=' . Lltxt_Admin_Page::SLUG . '&tab=version-control' )
					)
				); ?>"><?php esc_html_e( 'Load more', 'agentic-commerce-llms-txt' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Build the notice text for a Refresh that landed but skipped one or
	 * more routes because their mode forbids overwrite.
	 *
	 * @return string
	 */
	private static function vc_skip_notice_text() {
		$skipped = get_transient( 'lltxt_last_skipped_vc' );
		delete_transient( 'lltxt_last_skipped_vc' );
		if ( ! is_array( $skipped ) || empty( $skipped ) ) {
			return __( 'Refresh ran. Some files were left alone because they are pinned or merchant-managed.', 'agentic-commerce-llms-txt' );
		}
		$pinned   = array();
		$merchant = array();
		foreach ( $skipped as $path => $reason ) {
			if ( 'pinned' === $reason ) {
				$pinned[] = '/' . $path;
			} else {
				$merchant[] = '/' . $path;
			}
		}
		$lines = array( __( 'Refresh ran, but these files were left alone:', 'agentic-commerce-llms-txt' ) );
		if ( $pinned ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated file paths. */
				__( 'Pinned (unpin a row below to resume refresh): %s', 'agentic-commerce-llms-txt' ),
				implode( ', ', $pinned )
			);
		}
		if ( $merchant ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated file paths. */
				__( 'Merchant-managed (use "Take over this file" on the Files tab): %s', 'agentic-commerce-llms-txt' ),
				implode( ', ', $merchant )
			);
		}
		return implode( ' ', $lines );
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
			'vc_sync_ok'      => array( 'success', __( 'Refresh complete. New versions added to your local history.', 'agentic-commerce-llms-txt' ) ),
			'vc_sync_skipped' => array( 'warning', self::vc_skip_notice_text() ),
			'vc_sync_partial' => array( 'warning', __( 'Refresh ran with some emitter errors — see Diagnostics.', 'agentic-commerce-llms-txt' ) ),
			'vc_restored'     => array( 'success', __( 'Restored. Refresh paused for this route — unpin in Version Control to resume.', 'agentic-commerce-llms-txt' ) ),
			'vc_restore_err'  => array( 'error',   __( 'Could not restore that version.', 'agentic-commerce-llms-txt' ) ),
			'vc_unpinned'     => array( 'success', __( 'Resumed daily refresh for this route.', 'agentic-commerce-llms-txt' ) ),
			'vc_pinned'       => array( 'success', __( 'Pinned. Daily refresh is paused for this route.', 'agentic-commerce-llms-txt' ) ),
			'vc_pin_err'      => array( 'error',   __( 'Could not pin.', 'agentic-commerce-llms-txt' ) ),
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
