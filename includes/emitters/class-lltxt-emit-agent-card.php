<?php
/**
 * /.well-known/agent-card.json — A2A 1.0 agent card. Ported from
 * xpay-storefront/src/app/.well-known/agent-card.json/route.ts (the
 * generateAgentCardJson shape), rendered locally with no xpay dependency.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Agent_Card.
 */
class Lltxt_Emit_Agent_Card implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return '.well-known/agent-card.json';
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
		return '\.well\-known/agent\-card\.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base = untrailingslashit( home_url() );
		$name = Lltxt_Catalog_Reader::store_name();

		$card = array(
			'protocolVersion'    => '0.1.0',
			'name'               => $name,
			'description'        => sprintf(
				/* translators: %s: store name. */
				__( 'Agentic-commerce surface for %s — search products, build a cart, and check out.', 'llms-txt-for-woocommerce' ),
				$name
			),
			'url'                => $base,
			'version'            => '0.1.0',
			'provider'           => array(
				'organization' => 'xpay',
				'url'          => 'https://xpay.sh',
			),
			'capabilities'       => array(
				'streaming'         => false,
				'pushNotifications' => false,
			),
			'defaultInputModes'  => array( 'text/plain' ),
			'defaultOutputModes' => array( 'application/json', 'text/markdown' ),
			'skills'             => array(
				array(
					'id'          => 'browse_catalog',
					'name'        => __( 'Browse catalog', 'llms-txt-for-woocommerce' ),
					'description' => __( 'Discover products with live price, stock, and images.', 'llms-txt-for-woocommerce' ),
					'tags'        => array( 'commerce', 'catalog', 'shopping' ),
					'examples'    => array(
						$base . '/catalog.json',
						$base . '/products.json',
					),
				),
			),
		);

		return wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
