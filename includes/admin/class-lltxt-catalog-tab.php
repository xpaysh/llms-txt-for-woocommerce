<?php
/**
 * Catalog tab — top-N count, ordering, and category include/exclude controls
 * for the products surfaced in the sample tables + feeds.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Catalog_Tab.
 */
class Lltxt_Catalog_Tab {

	/**
	 * Persist catalog settings.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_POST['lltxt_catalog_save'] ) ) {
			return;
		}
		check_admin_referer( 'lltxt_catalog' );

		$top_n = isset( $_POST['lltxt_top_n'] ) ? (int) $_POST['lltxt_top_n'] : 100;
		$top_n = max( 10, min( 500, $top_n ) );

		$orderby_in    = isset( $_POST['lltxt_orderby'] ) ? sanitize_key( wp_unslash( $_POST['lltxt_orderby'] ) ) : 'total_sales';
		$allowed_order = array( 'total_sales', 'date', 'title', 'price' );
		$orderby       = in_array( $orderby_in, $allowed_order, true ) ? $orderby_in : 'total_sales';

		$include = isset( $_POST['lltxt_include_cats'] ) && is_array( $_POST['lltxt_include_cats'] )
			? array_map( 'absint', wp_unslash( $_POST['lltxt_include_cats'] ) )
			: array();
		$exclude = isset( $_POST['lltxt_exclude_cats'] ) && is_array( $_POST['lltxt_exclude_cats'] )
			? array_map( 'absint', wp_unslash( $_POST['lltxt_exclude_cats'] ) )
			: array();

		update_option(
			'lltxt_catalog_settings',
			array(
				'top_n'        => $top_n,
				'orderby'      => $orderby,
				'include_cats' => array_values( array_filter( $include ) ),
				'exclude_cats' => array_values( array_filter( $exclude ) ),
			)
		);

		wp_safe_redirect( Lltxt_Admin_Page::redirect_url( 'catalog', 'catalog_saved' ) );
		exit;
	}

	/**
	 * Render the controls.
	 *
	 * @return void
	 */
	public static function render() {
		$code = isset( $_GET['lltxt_notice'] ) ? sanitize_key( wp_unslash( $_GET['lltxt_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'catalog_saved' === $code ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Catalog settings saved. Regenerate files from the Files tab to apply.', 'llms-txt-for-woocommerce' ) . '</p></div>';
		}

		$cfg = get_option( 'lltxt_catalog_settings', array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		$top_n   = isset( $cfg['top_n'] ) ? (int) $cfg['top_n'] : 100;
		$orderby = isset( $cfg['orderby'] ) ? $cfg['orderby'] : 'total_sales';
		$include = isset( $cfg['include_cats'] ) ? (array) $cfg['include_cats'] : array();
		$exclude = isset( $cfg['exclude_cats'] ) ? (array) $cfg['exclude_cats'] : array();

		$cats = Lltxt_Catalog_Reader::get_categories();

		$orderby_opts = array(
			'total_sales' => __( 'Best selling (total sales)', 'llms-txt-for-woocommerce' ),
			'date'        => __( 'Newest first', 'llms-txt-for-woocommerce' ),
			'title'       => __( 'Title (A–Z)', 'llms-txt-for-woocommerce' ),
			'price'       => __( 'Price', 'llms-txt-for-woocommerce' ),
		);
		?>
		<form method="post">
			<?php wp_nonce_field( 'lltxt_catalog' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="lltxt_top_n"><?php esc_html_e( 'Products to surface (top N)', 'llms-txt-for-woocommerce' ); ?></label></th>
						<td>
							<input type="range" id="lltxt_top_n" name="lltxt_top_n" min="10" max="500" step="10" value="<?php echo esc_attr( $top_n ); ?>"
								oninput="document.getElementById('lltxt_top_n_val').textContent=this.value;" />
							<output id="lltxt_top_n_val"><?php echo esc_html( $top_n ); ?></output>
							<p class="description"><?php esc_html_e( 'How many products to pull for the sample tables and feeds (10–500). Default 100.', 'llms-txt-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lltxt_orderby"><?php esc_html_e( 'Ordering', 'llms-txt-for-woocommerce' ); ?></label></th>
						<td>
							<select id="lltxt_orderby" name="lltxt_orderby">
								<?php foreach ( $orderby_opts as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $orderby, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lltxt_include_cats"><?php esc_html_e( 'Only include categories', 'llms-txt-for-woocommerce' ); ?></label></th>
						<td>
							<select id="lltxt_include_cats" name="lltxt_include_cats[]" multiple size="6" style="min-width:280px;">
								<?php foreach ( $cats as $c ) : ?>
									<option value="<?php echo esc_attr( $c['id'] ); ?>" <?php echo in_array( $c['id'], array_map( 'intval', $include ), true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $c['name'] . ' (' . $c['count'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Leave empty to include all categories.', 'llms-txt-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lltxt_exclude_cats"><?php esc_html_e( 'Exclude categories', 'llms-txt-for-woocommerce' ); ?></label></th>
						<td>
							<select id="lltxt_exclude_cats" name="lltxt_exclude_cats[]" multiple size="6" style="min-width:280px;">
								<?php foreach ( $cats as $c ) : ?>
									<option value="<?php echo esc_attr( $c['id'] ); ?>" <?php echo in_array( $c['id'], array_map( 'intval', $exclude ), true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $c['name'] . ' (' . $c['count'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Products in these categories are removed from the surfaced set.', 'llms-txt-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<input type="hidden" name="lltxt_catalog_save" value="1" />
			<?php submit_button( __( 'Save Catalog Settings', 'llms-txt-for-woocommerce' ) ); ?>
		</form>
		<?php
	}
}
