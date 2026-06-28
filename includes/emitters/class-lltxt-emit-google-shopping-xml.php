<?php
/**
 * /feed/google-shopping.xml — Google Merchant Center RSS 2.0 product feed with
 * the g: namespace. The live TS route proxies an S3 artifact; here we render the
 * GMC shape directly from WC for merchants without a dedicated feed plugin.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Google_Shopping_Xml.
 */
class Lltxt_Emit_Google_Shopping_Xml implements Lltxt_Emitter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function output_path() {
		return 'feed/google-shopping.xml';
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
		return 'feed/google\-shopping\.xml';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$base     = untrailingslashit( home_url() );
		$name     = Lltxt_Catalog_Reader::store_name();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$products = Lltxt_Catalog_Reader::get_products(
			array(
				'limit'   => 5000,
				'orderby' => 'total_sales',
				'order'   => 'DESC',
			)
		);

		$xml   = array();
		$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml[] = '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">';
		$xml[] = '  <channel>';
		$xml[] = '    <title>' . self::esc( $name ) . '</title>';
		$xml[] = '    <link>' . self::esc( $base ) . '</link>';
		$xml[] = '    <description>' . self::esc( Lltxt_Catalog_Reader::store_tagline() ) . '</description>';

		foreach ( $products as $p ) {
			$price = ( '' !== $p['price'] ) ? number_format( (float) $p['price'], 2, '.', '' ) : '';
			if ( '' === $price ) {
				continue; // GMC requires a price.
			}
			$img  = Lltxt_Catalog_Reader::card_image( $p );
			$desc = $p['short_description'] ? $p['short_description'] : $p['description'];
			$avail = ( 'InStock' === $p['availability'] ) ? 'in_stock' : ( ( 'BackOrder' === $p['availability'] ) ? 'backorder' : 'out_of_stock' );

			$xml[] = '    <item>';
			$xml[] = '      <g:id>' . self::esc( $p['sku'] ? $p['sku'] : (string) $p['id'] ) . '</g:id>';
			$xml[] = '      <g:title>' . self::esc( $p['name'] ) . '</g:title>';
			$xml[] = '      <g:description>' . self::esc( wp_trim_words( $desc, 100, '' ) ) . '</g:description>';
			$xml[] = '      <g:link>' . self::esc( $p['permalink'] ) . '</g:link>';
			if ( $img ) {
				$xml[] = '      <g:image_link>' . self::esc( $img ) . '</g:image_link>';
			}
			$xml[] = '      <g:availability>' . $avail . '</g:availability>';
			$xml[] = '      <g:price>' . self::esc( $price . ' ' . $currency ) . '</g:price>';
			if ( $p['on_sale'] && '' !== $p['sale_price'] ) {
				$xml[] = '      <g:sale_price>' . self::esc( number_format( (float) $p['sale_price'], 2, '.', '' ) . ' ' . $currency ) . '</g:sale_price>';
			}
			if ( '' !== $p['sku'] ) {
				$xml[] = '      <g:mpn>' . self::esc( $p['sku'] ) . '</g:mpn>';
			}
			if ( ! empty( $p['categories'][0] ) ) {
				$xml[] = '      <g:product_type>' . self::esc( implode( ' > ', $p['categories'] ) ) . '</g:product_type>';
			}
			$xml[] = '      <g:condition>new</g:condition>';
			$xml[] = '      <g:identifier_exists>' . ( $p['sku'] ? 'yes' : 'no' ) . '</g:identifier_exists>';
			$xml[] = '    </item>';
		}

		$xml[] = '  </channel>';
		$xml[] = '</rss>';

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
