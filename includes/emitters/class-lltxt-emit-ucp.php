<?php
/**
 * /.well-known/ucp — Universal Commerce Protocol business profile (no .json
 * extension, per UCP spec rev 2026-04-08). Ported from
 * xpay-storefront/src/app/.well-known/ucp/route.ts, rendered locally.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Ucp.
 */
class Lltxt_Emit_Ucp implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return '.well-known/ucp';
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
		return '\.well\-known/ucp';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base = untrailingslashit( home_url() );
		$name = Lltxt_Catalog_Reader::store_name();

		$profile = array(
			'ucp'          => array(
				'version'  => '2026-04-08',
				'business' => array(
					'name'        => $name,
					'description' => Lltxt_Catalog_Reader::store_tagline(),
					'url'         => $base,
				),
				'endpoint' => $base . '/api/ucp/v1',
				'services' => array(
					// Free tier exposes the static catalog feeds as the discovery surface.
					'dev.ucp.catalog' => array(
						array(
							'version'  => '1.0',
							'feeds'    => array(
								$base . '/catalog.json',
								$base . '/products.json',
							),
						),
					),
				),
				'signingKeys' => array(),
			),
			'capabilities' => array( 'catalog', 'discovery' ),
			'provider'     => array(
				'name' => 'xpay',
				'url'  => 'https://xpay.sh',
			),
		);

		return wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
