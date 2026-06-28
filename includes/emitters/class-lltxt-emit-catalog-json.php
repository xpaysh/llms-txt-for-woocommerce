<?php
/**
 * /catalog.json — machine-readable ACP-compatible product feed: store metadata
 * + products array with all fields. The live TS route proxies an S3 artifact;
 * here we render the same shape directly from WC.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Catalog_Json.
 */
class Lltxt_Emit_Catalog_Json implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return 'catalog.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type() {
		return 'application/json; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function route_pattern() {
		return 'catalog\.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base     = untrailingslashit( home_url() );
		$products = Lltxt_Catalog_Reader::get_products(
			array(
				'limit'   => 5000,
				'orderby' => 'total_sales',
				'order'   => 'DESC',
			)
		);

		$items = array();
		foreach ( $products as $p ) {
			$items[] = array(
				'id'                => (string) $p['id'],
				'name'              => $p['name'],
				'sku'               => $p['sku'],
				'description'       => $p['short_description'] ? $p['short_description'] : $p['description'],
				'url'               => $p['permalink'],
				'image'             => Lltxt_Catalog_Reader::card_image( $p ),
				'images'            => $p['images'],
				'price'             => ( '' !== $p['price'] ) ? (float) $p['price'] : null,
				'price_max'         => ( '' !== $p['price_max'] ) ? (float) $p['price_max'] : null,
				'regular_price'     => ( '' !== $p['regular_price'] ) ? (float) $p['regular_price'] : null,
				'sale_price'        => ( '' !== $p['sale_price'] ) ? (float) $p['sale_price'] : null,
				'currency'          => $p['currency'],
				'availability'      => $p['availability'],
				'inventory_status'  => $p['stock_status'],
				'categories'        => $p['categories'],
			);
		}

		$doc = array(
			'version'      => '1.0',
			'protocol'     => 'acp',
			'store'        => array(
				'name'        => Lltxt_Catalog_Reader::store_name(),
				'description' => Lltxt_Catalog_Reader::store_tagline(),
				'url'         => $base,
				'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			),
			'generated_at' => gmdate( 'c' ),
			'count'        => count( $items ),
			'products'     => $items,
		);

		return wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
