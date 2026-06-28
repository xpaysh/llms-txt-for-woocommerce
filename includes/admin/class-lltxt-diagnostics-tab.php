<?php
/**
 * Diagnostics tab — live file preview, "Test as ChatGPT-User" loopback fetch,
 * and the refresh log (last 100).
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Diagnostics_Tab.
 */
class Lltxt_Diagnostics_Tab {

	/**
	 * Handle the "Test as ChatGPT-User" action (stores result in a transient).
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_POST['lltxt_diag_test'] ) ) {
			return;
		}
		check_admin_referer( 'lltxt_diag' );

		$path = isset( $_POST['lltxt_test_path'] ) ? sanitize_text_field( wp_unslash( $_POST['lltxt_test_path'] ) ) : 'llms.txt';
		$valid = in_array( $path, array_values( Lltxt_Router::routes() ), true );
		if ( ! $valid ) {
			$path = 'llms.txt';
		}

		$url      = Lltxt_Cache::get_url( $path );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
				'headers'     => array( 'Accept' => '*/*' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'error'  => $response->get_error_message(),
				'url'    => $url,
			);
		} else {
			$body   = wp_remote_retrieve_body( $response );
			$result = array(
				'url'     => $url,
				'code'    => wp_remote_retrieve_response_code( $response ),
				'ctype'   => wp_remote_retrieve_header( $response, 'content-type' ),
				'bytes'   => strlen( $body ),
				'excerpt' => substr( $body, 0, 4000 ),
			);
		}

		set_transient( 'lltxt_diag_test_' . get_current_user_id(), $result, 300 );
		wp_safe_redirect( Lltxt_Admin_Page::redirect_url( 'diagnostics', 'tested' ) );
		exit;
	}

	/**
	 * Render diagnostics.
	 *
	 * @return void
	 */
	public static function render() {
		self::render_preview();
		self::render_test();
		self::render_log();
	}

	/**
	 * File preview block.
	 *
	 * @return void
	 */
	private static function render_preview() {
		$routes  = Lltxt_Router::routes();
		$preview = isset( $_GET['preview'] ) ? sanitize_text_field( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $preview, array_values( $routes ), true ) ) {
			$preview = 'llms.txt';
		}
		?>
		<h2><?php esc_html_e( 'File preview', 'llms-txt-for-woocommerce' ); ?></h2>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( Lltxt_Admin_Page::SLUG ); ?>" />
			<input type="hidden" name="tab" value="diagnostics" />
			<select name="preview">
				<?php foreach ( array_values( $routes ) as $rel ) : ?>
					<option value="<?php echo esc_attr( $rel ); ?>" <?php selected( $preview, $rel ); ?>>/<?php echo esc_html( $rel ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Preview', 'llms-txt-for-woocommerce' ); ?></button>
		</form>
		<?php
		$body = Lltxt_Cache::read( $preview );
		if ( false === $body ) {
			// Render live so the operator still sees content before first cron run.
			foreach ( Lltxt_Plugin::emitter_classes() as $class ) {
				$e = Lltxt_Plugin::make_emitter( $class );
				if ( $e && $e->output_path() === $preview ) {
					$body = $e->render();
					break;
				}
			}
		}
		echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:1em;max-height:420px;overflow:auto;">' . esc_html( false === $body ? __( 'Not generated yet.', 'llms-txt-for-woocommerce' ) : $body ) . '</pre>';
	}

	/**
	 * "Test as ChatGPT-User" block + last result.
	 *
	 * @return void
	 */
	private static function render_test() {
		$routes = Lltxt_Router::routes();
		?>
		<h2><?php esc_html_e( 'Test as ChatGPT-User', 'llms-txt-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Fetch one of your discovery files from your own site using the ChatGPT-User user-agent, to confirm it is reachable and served with the right Content-Type.', 'llms-txt-for-woocommerce' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'lltxt_diag' ); ?>
			<select name="lltxt_test_path">
				<?php foreach ( array_values( $routes ) as $rel ) : ?>
					<option value="<?php echo esc_attr( $rel ); ?>">/<?php echo esc_html( $rel ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="hidden" name="lltxt_diag_test" value="1" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run test', 'llms-txt-for-woocommerce' ); ?></button>
		</form>
		<?php
		$result = get_transient( 'lltxt_diag_test_' . get_current_user_id() );
		if ( $result ) {
			delete_transient( 'lltxt_diag_test_' . get_current_user_id() );
			echo '<table class="widefat striped" style="margin-top:1em;max-width:760px;"><tbody>';
			if ( ! empty( $result['error'] ) ) {
				echo '<tr><th>' . esc_html__( 'Result', 'llms-txt-for-woocommerce' ) . '</th><td><span style="color:#b32d2e;">' . esc_html__( 'Error', 'llms-txt-for-woocommerce' ) . '</span> — ' . esc_html( $result['error'] ) . '</td></tr>';
				echo '<tr><th>URL</th><td><code>' . esc_html( $result['url'] ) . '</code></td></tr>';
			} else {
				echo '<tr><th>URL</th><td><code>' . esc_html( $result['url'] ) . '</code></td></tr>';
				echo '<tr><th>HTTP</th><td>' . esc_html( (string) $result['code'] ) . '</td></tr>';
				echo '<tr><th>Content-Type</th><td><code>' . esc_html( (string) $result['ctype'] ) . '</code></td></tr>';
				echo '<tr><th>Bytes</th><td>' . esc_html( (string) $result['bytes'] ) . '</td></tr>';
				echo '</tbody></table>';
				echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:1em;max-height:300px;overflow:auto;">' . esc_html( $result['excerpt'] ) . '</pre>';
				return;
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Refresh log (last 100).
	 *
	 * @return void
	 */
	private static function render_log() {
		$log = Lltxt_Refresh::get_log();
		?>
		<h2><?php esc_html_e( 'Refresh log', 'llms-txt-for-woocommerce' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'No refreshes recorded yet.', 'llms-txt-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'OK', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Failed', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'llms-txt-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'llms-txt-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) . ' UTC' : '' ); ?></td>
						<td><?php echo esc_html( (string) ( isset( $entry['ok'] ) ? $entry['ok'] : 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( isset( $entry['fail'] ) ? $entry['fail'] : 0 ) ); ?></td>
						<td><?php echo esc_html( ( isset( $entry['duration'] ) ? $entry['duration'] : 0 ) . ' ms' ); ?></td>
						<td>
							<?php
							if ( ! empty( $entry['errors'] ) && is_array( $entry['errors'] ) ) {
								$bits = array();
								foreach ( $entry['errors'] as $file => $msg ) {
									$bits[] = $file . ': ' . $msg;
								}
								echo esc_html( implode( ' | ', $bits ) );
							} else {
								echo '—';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
