<?php
/**
 * /sitemap-ai.xml — XML sitemap of products + discovery surfaces, optimized for
 * AI crawlers. Ported from xpay-storefront/src/app/sitemap.xml/route.ts.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Sitemap_Ai.
 */
class Lltxt_Emit_Sitemap_Ai implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return 'sitemap-ai.xml';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type() {
		return 'application/xml; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function route_pattern() {
		return 'sitemap\-ai\.xml';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base       = untrailingslashit( home_url() );
		$generated  = gmdate( 'c' );
		$categories = Lltxt_Catalog_Reader::get_categories();
		$products   = Lltxt_Catalog_Reader::get_products(
			array(
				'limit'   => 5000,
				'orderby' => 'total_sales',
				'order'   => 'DESC',
			)
		);

		$entries = array();

		// Discovery surfaces.
		$entries[] = array( $base . '/', '1.0', 'daily' );
		$entries[] = array( $base . '/shop', '0.9', 'daily' );
		$entries[] = array( $base . '/llms.txt', '0.7', 'weekly' );
		$entries[] = array( $base . '/index.md', '0.7', 'weekly' );
		$entries[] = array( $base . '/agents.md', '0.7', 'weekly' );
		$entries[] = array( $base . '/catalog.json', '0.6', 'daily' );
		$entries[] = array( $base . '/.well-known/ucp', '0.5', 'weekly' );
		$entries[] = array( $base . '/.well-known/mcp.json', '0.5', 'weekly' );
		$entries[] = array( $base . '/.well-known/agent-card.json', '0.5', 'weekly' );

		foreach ( $categories as $c ) {
			$link      = $c['link'] ? $c['link'] : ( $base . '/product-category/' . $c['slug'] );
			$entries[] = array( untrailingslashit( $link ), '0.7', 'weekly' );
		}

		foreach ( $products as $p ) {
			$entries[] = array( $p['permalink'], '0.8', 'weekly' );
		}

		$xml   = array();
		$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ( $entries as $e ) {
			$xml[] = '  <url>';
			$xml[] = '    <loc>' . self::esc( $e[0] ) . '</loc>';
			$xml[] = '    <lastmod>' . self::esc( $generated ) . '</lastmod>';
			$xml[] = '    <changefreq>' . self::esc( $e[2] ) . '</changefreq>';
			$xml[] = '    <priority>' . self::esc( $e[1] ) . '</priority>';
			$xml[] = '  </url>';
		}
		$xml[] = '</urlset>';

		return implode( "\n", $xml ) . "\n";
	}

	/**
	 * XML-escape a value.
	 *
	 * @param string $s Raw.
	 * @return string
	 */
	private static function esc( $s ) {
		return str_replace(
			array( '&', '<', '>', '"', "'" ),
			array( '&amp;', '&lt;', '&gt;', '&quot;', '&apos;' ),
			(string) $s
		);
	}
}
