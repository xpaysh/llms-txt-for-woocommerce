<?php
/**
 * /products.json — Shopify-convention catalog discovery. The single most-probed
 * path by shopping agents assuming a store is on Shopify. Ported from
 * xpay-storefront/src/lib/store-shapes.ts toShopifyProduct().
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Products_Json.
 */
class Lltxt_Emit_Products_Json implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return 'products.json';
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
		return 'products\.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$generated = gmdate( 'c' );
		$products  = Lltxt_Catalog_Reader::get_products(
			array(
				'limit'   => 5000,
				'orderby' => 'total_sales',
				'order'   => 'DESC',
			)
		);

		$items = array();
		foreach ( $products as $p ) {
			$imgs        = ! empty( $p['images'] ) ? $p['images'] : array();
			$image_nodes = array();
			$i           = 1;
			foreach ( $imgs as $src ) {
				$image_nodes[] = array(
					'id'  => $i,
					'src' => $src,
				);
				++$i;
			}
			$price = ( '' !== $p['price'] ) ? number_format( (float) $p['price'], 2, '.', '' ) : '0.00';

			$items[] = array(
				'id'           => $p['id'],
				'title'        => $p['name'],
				'handle'       => $p['slug'],
				'body_html'    => $p['description'] ? $p['description'] : $p['short_description'],
				'published_at' => $generated,
				'created_at'   => $generated,
				'updated_at'   => $generated,
				'vendor'       => '',
				'product_type' => ! empty( $p['categories'][0] ) ? $p['categories'][0] : '',
				'tags'         => implode( ', ', $p['categories'] ),
				'url'          => $p['permalink'],
				'variants'     => array(
					array(
						'id'                => $p['id'],
						'title'             => 'Default',
						'sku'               => $p['sku'],
						'price'             => $price,
						'available'         => ( 'InStock' === $p['availability'] ),
						'requires_shipping' => true,
					),
				),
				'images'       => $image_nodes,
				'image'        => ! empty( $imgs ) ? array( 'src' => $imgs[0] ) : null,
			);
		}

		return wp_json_encode( array( 'products' => $items ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
