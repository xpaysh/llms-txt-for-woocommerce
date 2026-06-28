<?php
/**
 * Files tab — table of the emitted files with last-generated time + per-file
 * Regenerate / Preview, plus a "Regenerate All" button.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Files_Tab.
 */
class Lltxt_Files_Tab {

	/**
	 * Handle Regenerate-All / Regenerate-One actions.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_POST['lltxt_files_action'] ) ) {
			return;
		}
		check_admin_referer( 'lltxt_files' );

		$action = sanitize_key( wp_unslash( $_POST['lltxt_files_action'] ) );
		if ( 'regen_all' === $action ) {
			$res    = Lltxt_Refresh::run();
			$notice = ( 0 === $res['fail'] ) ? 'regen_all_ok' : 'regen_partial';
		} elseif ( 'regen_one' === $action ) {
			$class  = isset( $_POST['lltxt_class'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_class'] ) ) : '';
			$valid  = in_array( $class, Lltxt_Plugin::emitter_classes(), true );
			$notice = 'regen_one_err';
			if ( $valid ) {
				$res    = Lltxt_Refresh::run_one( $class );
				$notice = ( 0 === $res['fail'] ) ? 'regen_one_ok' : 'regen_one_err';
			}
		} elseif ( 'take_over' === $action ) {
			$path = isset( $_POST['lltxt_path'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_path'] ) ) : '';
			$valid = in_array( $path, array_values( Lltxt_Router::routes() ), true );
			if ( $valid ) {
				Lltxt_Cache::set_mode( $path, Lltxt_Cache::MODE_PLUGIN );
				$notice = 'take_over_ok';
			} else {
				$notice = 'regen_one_err';
			}
		} elseif ( 'restore_backup' === $action ) {
			$path  = isset( $_POST['lltxt_path'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_path'] ) ) : '';
			$valid = in_array( $path, array_values( Lltxt_Router::routes() ), true );
			if ( $valid ) {
				$res    = Lltxt_Cache::restore_initial_backup( $path );
				$notice = is_wp_error( $res ) ? 'restore_err' : 'restore_ok';
			} else {
				$notice = 'restore_err';
			}
		} else {
			$notice = '';
		}

		wp_safe_redirect( Lltxt_Admin_Page::redirect_url( 'files', $notice ) );
		exit;
	}

	/**
	 * Render the table.
	 *
	 * @return void
	 */
	public static function render() {
		self::notice();
		$emitters = Lltxt_Plugin::emitter_classes();
		?>
		<p><?php esc_html_e( 'These files are generated locally on your server and served from your domain. Daily refresh runs via WP-Cron; you can also regenerate on demand below.', 'llms-txt-for-woocommerce' ); ?></p>
		<form method="post" style="margin-bottom:1em;">
			<?php wp_nonce_field( 'lltxt_files' ); ?>
			<input type="hidden" name="lltxt_files_action" value="regen_all" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Regenerate All', 'llms-txt-for-woocommerce' ); ?></button>
			<?php
			$last = Lltxt_Refresh::last_refresh();
			if ( $last ) {
				echo ' <span class="description">' . esc_html(
					sprintf(
						/* translators: %s: human-readable time difference. */
						__( 'Last full refresh: %s ago', 'llms-txt-for-woocommerce' ),
						human_time_diff( $last, time() )
					)
				) . '</span>';
			}
			?>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Path', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Last Generated', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'llms-txt-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $emitters as $class ) :
					$emitter = Lltxt_Plugin::make_emitter( $class );
					if ( null === $emitter ) {
						continue;
					}
					$path     = $emitter->output_path();
					$hook_only = ( null === $path || '' === $path );
					$rel       = $hook_only ? self::hook_label( $class ) : $path;
					$url       = $hook_only ? '' : Lltxt_Cache::get_url( $path );
					$last_w    = $hook_only ? 0 : Lltxt_Cache::last_written( $path );
					?>
					<tr>
						<td><code><?php echo esc_html( self::file_label( $class ) ); ?></code></td>
						<td>
							<?php if ( $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '/' . $rel ); ?></a>
							<?php else : ?>
								<em><?php echo esc_html( $rel ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( $hook_only ) {
								esc_html_e( 'Served live (no static file)', 'llms-txt-for-woocommerce' );
							} elseif ( $last_w ) {
								echo esc_html(
									sprintf(
										/* translators: %s: time difference. */
										__( '%s ago', 'llms-txt-for-woocommerce' ),
										human_time_diff( $last_w, time() )
									)
								);
							} else {
								esc_html_e( 'Not generated yet', 'llms-txt-for-woocommerce' );
							}
							?>
						</td>
						<td>
							<?php
							if ( $hook_only ) {
								echo '<span class="description">—</span>';
							} else {
								$mode = Lltxt_Cache::get_mode( $path );
								$colors = array(
									Lltxt_Cache::MODE_PLUGIN   => '#1a7f37',
									Lltxt_Cache::MODE_MERCHANT => '#9a6700',
									Lltxt_Cache::MODE_PINNED   => '#0969da',
								);
								$labels = array(
									Lltxt_Cache::MODE_PLUGIN   => __( 'plugin-managed', 'llms-txt-for-woocommerce' ),
									Lltxt_Cache::MODE_MERCHANT => __( 'merchant-managed', 'llms-txt-for-woocommerce' ),
									Lltxt_Cache::MODE_PINNED   => __( 'pinned', 'llms-txt-for-woocommerce' ),
								);
								printf(
									'<span style="display:inline-block;padding:2px 8px;border:1px solid %s;border-radius:10px;color:%s;font-size:11px;">%s</span>',
									esc_attr( $colors[ $mode ] ),
									esc_attr( $colors[ $mode ] ),
									esc_html( $labels[ $mode ] )
								);
							}
							?>
						</td>
						<td>
							<?php if ( ! $hook_only ) :
								$mode = Lltxt_Cache::get_mode( $path );
								$regen_enabled = ( Lltxt_Cache::MODE_PLUGIN === $mode );
							?>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'lltxt_files' ); ?>
									<input type="hidden" name="lltxt_files_action" value="regen_one" />
									<input type="hidden" name="lltxt_class" value="<?php echo esc_attr( $class ); ?>" />
									<button type="submit" class="button button-small" <?php disabled( ! $regen_enabled ); ?>><?php esc_html_e( 'Regenerate', 'llms-txt-for-woocommerce' ); ?></button>
								</form>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => Lltxt_Admin_Page::SLUG, 'tab' => 'diagnostics', 'preview' => rawurlencode( $path ) ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Preview', 'llms-txt-for-woocommerce' ); ?></a>
								<?php if ( Lltxt_Cache::MODE_MERCHANT === $mode ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'lltxt_files' ); ?>
										<input type="hidden" name="lltxt_files_action" value="take_over" />
										<input type="hidden" name="lltxt_path" value="<?php echo esc_attr( $path ); ?>" />
										<button type="submit" class="button button-small"
											onclick="return confirm('<?php echo esc_js( __( 'Take over this file? The plugin will overwrite your existing file on the next refresh. Your original was already backed up to /wp-content/uploads/lltxt-backups/.', 'llms-txt-for-woocommerce' ) ); ?>');">
											<?php esc_html_e( 'Take over this file', 'llms-txt-for-woocommerce' ); ?>
										</button>
									</form>
								<?php endif; ?>
								<?php
								$backups = Lltxt_Cache::initial_backups();
								if ( isset( $backups[ $path ] ) ) :
								?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'lltxt_files' ); ?>
										<input type="hidden" name="lltxt_files_action" value="restore_backup" />
										<input type="hidden" name="lltxt_path" value="<?php echo esc_attr( $path ); ?>" />
										<button type="submit" class="button button-small"
											onclick="return confirm('<?php echo esc_js( __( 'Restore your original file (the one in your webroot before this plugin was activated)? The plugin will stop refreshing this route until you flip it back.', 'llms-txt-for-woocommerce' ) ); ?>');">
											<?php esc_html_e( 'Restore my version', 'llms-txt-for-woocommerce' ); ?>
										</button>
									</form>
								<?php endif; ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Configured in the AI Bots tab', 'llms-txt-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Human label for a file emitter.
	 *
	 * @param string $class Emitter class.
	 * @return string
	 */
	private static function file_label( $class ) {
		$emitter = Lltxt_Plugin::make_emitter( $class );
		$path    = $emitter ? $emitter->output_path() : '';
		if ( null === $path || '' === $path ) {
			return self::hook_label( $class );
		}
		return '/' . $path;
	}

	/**
	 * Label for hook-only emitters.
	 *
	 * @param string $class Emitter class.
	 * @return string
	 */
	private static function hook_label( $class ) {
		return $class;
	}

	/**
	 * Show the action notice.
	 *
	 * @return void
	 */
	private static function notice() {
		$code = isset( $_GET['lltxt_notice'] ) ? sanitize_key( wp_unslash( $_GET['lltxt_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			return;
		}
		$map = array(
			'regen_all_ok'  => array( 'success', __( 'All files regenerated.', 'llms-txt-for-woocommerce' ) ),
			'regen_partial' => array( 'warning', __( 'Files regenerated with some errors — see Diagnostics.', 'llms-txt-for-woocommerce' ) ),
			'regen_one_ok'  => array( 'success', __( 'File regenerated.', 'llms-txt-for-woocommerce' ) ),
			'regen_one_err' => array( 'error', __( 'Could not regenerate that file — see Diagnostics.', 'llms-txt-for-woocommerce' ) ),
			'take_over_ok'  => array( 'success', __( 'File flagged plugin-managed. The next refresh will overwrite the on-disk copy.', 'llms-txt-for-woocommerce' ) ),
			'restore_ok'    => array( 'success', __( 'Your original file is back in place. The plugin will not refresh this route until you flip it back to plugin-managed.', 'llms-txt-for-woocommerce' ) ),
			'restore_err'   => array( 'error',   __( 'Could not restore the backup — see the Diagnostics tab for details.', 'llms-txt-for-woocommerce' ) ),
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
