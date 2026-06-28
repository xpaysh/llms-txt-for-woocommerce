<?php
/**
 * AI Bots tab — toggle which AI crawlers are allowed in the robots.txt
 * directives. Saved to the lltxt_allowed_bots option.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Bots_Tab.
 */
class Lltxt_Bots_Tab {

	/**
	 * Persist the bot toggles.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_POST['lltxt_bots_save'] ) ) {
			return;
		}
		check_admin_referer( 'lltxt_bots' );

		$posted = isset( $_POST['lltxt_bots'] ) && is_array( $_POST['lltxt_bots'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['lltxt_bots'] ) )
			: array();

		$value = array();
		foreach ( array_keys( Lltxt_Emit_Robots_Txt::known_bots() ) as $bot ) {
			$value[ $bot ] = isset( $posted[ $bot ] );
		}
		update_option( 'lltxt_allowed_bots', $value );

		wp_safe_redirect( Lltxt_Admin_Page::redirect_url( 'bots', 'bots_saved' ) );
		exit;
	}

	/**
	 * Render the toggles.
	 *
	 * @return void
	 */
	public static function render() {
		$code = isset( $_GET['lltxt_notice'] ) ? sanitize_key( wp_unslash( $_GET['lltxt_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'bots_saved' === $code ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI bot settings saved.', 'llms-txt-for-woocommerce' ) . '</p></div>';
		}

		$allowed = get_option( 'lltxt_allowed_bots', Lltxt_Emit_Robots_Txt_defaults() );
		$allowed = is_array( $allowed ) ? $allowed : array();

		$descriptions = array(
			'ChatGPT-User'  => __( 'ChatGPT shopping & browsing (recommended ON).', 'llms-txt-for-woocommerce' ),
			'OAI-SearchBot' => __( 'OpenAI search indexer (recommended ON).', 'llms-txt-for-woocommerce' ),
			'Claude-User'   => __( 'Claude browsing on a user request (recommended ON).', 'llms-txt-for-woocommerce' ),
			'PerplexityBot' => __( 'Perplexity shopping & answers (recommended ON).', 'llms-txt-for-woocommerce' ),
			'GoogleOther'   => __( 'Google AI / non-search crawlers (recommended ON).', 'llms-txt-for-woocommerce' ),
			'GPTBot'        => __( 'OpenAI MODEL-TRAINING crawler — not a shopping agent. Default OFF.', 'llms-txt-for-woocommerce' ),
		);
		?>
		<p><?php esc_html_e( 'Choose which AI crawlers may access your store. These rules are appended to your WordPress robots.txt. Existing rules you already set are never overwritten.', 'llms-txt-for-woocommerce' ); ?></p>
		<?php if ( Lltxt_Robots_Physical_Exists() ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'A physical robots.txt file exists in your webroot — it overrides WordPress\'s dynamic robots.txt, so these toggles will not take effect until you remove it or merge the directives manually (see the preview below).', 'llms-txt-for-woocommerce' ); ?>
			</p></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'lltxt_bots' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
				<?php foreach ( Lltxt_Emit_Robots_Txt::known_bots() as $bot => $is_shopping ) : ?>
					<tr>
						<th scope="row"><label for="lltxt_bot_<?php echo esc_attr( $bot ); ?>"><?php echo esc_html( $bot ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="lltxt_bot_<?php echo esc_attr( $bot ); ?>" name="lltxt_bots[<?php echo esc_attr( $bot ); ?>]" value="1" <?php checked( ! empty( $allowed[ $bot ] ) ); ?> />
								<?php esc_html_e( 'Allow', 'llms-txt-for-woocommerce' ); ?>
							</label>
							<p class="description"><?php echo esc_html( isset( $descriptions[ $bot ] ) ? $descriptions[ $bot ] : '' ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<input type="hidden" name="lltxt_bots_save" value="1" />
			<?php submit_button( __( 'Save AI Bot Settings', 'llms-txt-for-woocommerce' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'robots.txt preview', 'llms-txt-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These are the directives appended to your robots.txt:', 'llms-txt-for-woocommerce' ); ?></p>
		<pre style="background:#fff;border:1px solid #ccd0d4;padding:1em;overflow:auto;"><?php echo esc_html( Lltxt_Emit_Robots_Txt::build_block() ); ?></pre>
		<?php
	}
}

/**
 * Detect a physical robots.txt that would override WP's dynamic one.
 *
 * @return bool
 */
function Lltxt_Robots_Physical_Exists() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$path = trailingslashit( get_home_path() ) . 'robots.txt';
	return file_exists( $path );
}
