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
	 * No POST actions to handle.
	 *
	 * @return void
	 */
	public static function handle() {
	}

	/**
	 * Render diagnostics.
	 *
	 * @return void
	 */
	public static function render() {
		self::render_preview();
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
		<h2><?php esc_html_e( 'File preview', 'agentic-commerce-llms-txt' ); ?></h2>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( Lltxt_Admin_Page::SLUG ); ?>" />
			<input type="hidden" name="tab" value="diagnostics" />
			<select name="preview">
				<?php foreach ( array_values( $routes ) as $rel ) : ?>
					<option value="<?php echo esc_attr( $rel ); ?>" <?php selected( $preview, $rel ); ?>>/<?php echo esc_html( $rel ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Preview', 'agentic-commerce-llms-txt' ); ?></button>
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
		echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:1em;max-height:420px;overflow:auto;">' . esc_html( false === $body ? __( 'Not generated yet.', 'agentic-commerce-llms-txt' ) : $body ) . '</pre>';
	}

	/**
	 * Refresh log (last 100).
	 *
	 * @return void
	 */
	private static function render_log() {
		$log = Lltxt_Refresh::get_log();
		?>
		<h2><?php esc_html_e( 'Refresh log', 'agentic-commerce-llms-txt' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'No refreshes recorded yet.', 'agentic-commerce-llms-txt' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'OK', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Failed', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'agentic-commerce-llms-txt' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'agentic-commerce-llms-txt' ); ?></th>
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
