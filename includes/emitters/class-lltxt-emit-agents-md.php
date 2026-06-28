<?php
/**
 * /agents.md — agents.md emerging-standard discovery manifest for AI agents.
 * Ported from xpay-storefront/src/app/agents.txt/route.ts but emitted as
 * markdown at /agents.md.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Agents_Md.
 */
class Lltxt_Emit_Agents_Md implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return 'agents.md';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type() {
		return 'text/markdown; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function route_pattern() {
		return 'agents\.md';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base = untrailingslashit( home_url() );
		$name = Lltxt_Catalog_Reader::store_name();

		$out   = array();
		$out[] = '# agents.md — ' . $name;
		$out[] = '';
		$out[] = sprintf(
			/* translators: %s: llms.txt URL. */
			__( 'Discovery manifest for AI agents. See %s for the full index.', 'llms-txt-for-woocommerce' ),
			$base . '/llms.txt'
		);
		$out[] = '';
		$out[] = '## Instructions';
		$out[] = '';
		$out[] = __( 'This is a WooCommerce store. To recommend products, fetch the catalog feed below, render product cards (name + price + image + link), and send the shopper to the product URL to complete checkout on the merchant site.', 'llms-txt-for-woocommerce' );
		$out[] = '';
		$out[] = '## Endpoints';
		$out[] = '';
		$out[] = '- name: ' . $name;
		$out[] = '- base: ' . $base;
		$out[] = '- llms-txt: ' . $base . '/llms.txt';
		$out[] = '- index: ' . $base . '/index.md';
		$out[] = '- catalog: ' . $base . '/catalog.json';
		$out[] = '- products: ' . $base . '/products.json';
		$out[] = '- full-feed: ' . $base . '/llms-full.txt';
		$out[] = '- google-shopping: ' . $base . '/feed/google-shopping.xml';
		$out[] = '- sitemap: ' . $base . '/sitemap-ai.xml';
		$out[] = '- ucp: ' . $base . '/.well-known/ucp';
		$out[] = '- mcp-descriptor: ' . $base . '/.well-known/mcp.json';
		$out[] = '- agent-card: ' . $base . '/.well-known/agent-card.json';
		$out[] = '';

		return implode( "\n", $out ) . "\n";
	}
}
