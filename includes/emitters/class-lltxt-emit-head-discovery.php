<?php
/**
 * <head> discovery links + JSON-LD WebSite schema. HOOK-ONLY — writes no static
 * file; injects on wp_head so AI agents fetching any storefront page learn the
 * canonical discovery-file locations.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Head_Discovery.
 */
class Lltxt_Emit_Head_Discovery implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 * Hook-only emitter — no static file.
	 */
	public function output_path() {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type() {
		return 'text/html; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 * Hook-only — no rewrite route.
	 */
	public function route_pattern() {
		return '';
	}

	/**
	 * {@inheritDoc}
	 * Returns the markup (used for the admin preview).
	 */
	public function render() {
		return self::build_markup();
	}

	/**
	 * Build the <head> markup.
	 *
	 * @return string
	 */
	public static function build_markup() {
		$base = untrailingslashit( home_url() );
		$name = Lltxt_Catalog_Reader::store_name();

		$links   = array();
		$links[] = sprintf(
			'<link rel="alternate" type="text/plain" title="%s" href="%s" />',
			esc_attr__( 'AI discovery (llms.txt)', 'llms-txt-for-woocommerce' ),
			esc_url( $base . '/llms.txt' )
		);
		$links[] = sprintf(
			'<link rel="alternate" type="application/json" title="%s" href="%s" />',
			esc_attr__( 'Product catalog (catalog.json)', 'llms-txt-for-woocommerce' ),
			esc_url( $base . '/catalog.json' )
		);

		$jsonld = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => $name,
			'url'             => $base,
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $base . '/?s={search_term_string}&post_type=product',
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		$out  = "\n<!-- LLMs.txt for WooCommerce -->\n";
		$out .= implode( "\n", $links ) . "\n";
		$out .= '<script type="application/ld+json">' . wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES ) . "</script>\n";
		$out .= "<!-- /LLMs.txt for WooCommerce -->\n";
		return $out;
	}

	/**
	 * wp_head callback — echoes the markup.
	 *
	 * @return void
	 */
	public static function render_head() {
		// Output is built from esc_url/esc_attr + wp_json_encode above; safe to echo.
		echo self::build_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
