<?php
/**
 * /.well-known/mcp.json — static MCP server descriptor. Adapted from
 * xpay-storefront/src/app/.well-known/mcp.json/route.ts but pointed at the
 * LOCAL catalog.json (no xpay backend dependency for the Free tier).
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Mcp_Json.
 */
class Lltxt_Emit_Mcp_Json implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return '.well-known/mcp.json';
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
		return '\.well\-known/mcp\.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base = untrailingslashit( home_url() );
		$name = Lltxt_Catalog_Reader::store_name();

		$descriptor = array(
			'name'        => $name,
			'description' => sprintf(
				/* translators: %s: store name. */
				__( 'Commerce catalog for %s — discover products, prices, stock, and images.', 'llms-txt-for-woocommerce' ),
				$name
			),
			// Free tier has no live MCP endpoint; advertise the static catalog feeds.
			'resources'   => array(
				array(
					'name'        => 'catalog',
					'description' => __( 'Full machine-readable product catalog (ACP JSON).', 'llms-txt-for-woocommerce' ),
					'uri'         => $base . '/catalog.json',
					'mimeType'    => 'application/json',
				),
				array(
					'name'        => 'products',
					'description' => __( 'Shopify-shape products feed.', 'llms-txt-for-woocommerce' ),
					'uri'         => $base . '/products.json',
					'mimeType'    => 'application/json',
				),
			),
			'pricing'     => array(
				'model'    => 'free',
				'currency' => 'USD',
			),
			'provider'    => array(
				'name' => 'xpay',
				'url'  => 'https://xpay.sh',
			),
		);

		return wp_json_encode( $descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
