<?php
/**
 * Reads + normalizes the WooCommerce catalog into a stable array shape the
 * emitters consume. Ported in spirit from xpay-storefront/src/lib/catalog.ts
 * + catalog-sample.ts, but reads directly from WC via wc_get_products().
 *
 * Critical rule (memory ref_chatgpt_card_requires_price_and_image): a variable
 * product must NEVER report price 0 — we use the min variation price so AI
 * shopping cards render a "from" price instead of dropping to prose.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Catalog_Reader.
 */
class Lltxt_Catalog_Reader {

	/**
	 * Transient cache lifetime for a normalized read.
	 */
	const CACHE_TTL = 60;

	/**
	 * Is WooCommerce active / loaded?
	 *
	 * @return bool
	 */
	public static function wc_ready() {
		return function_exists( 'wc_get_products' ) && function_exists( 'get_woocommerce_currency' );
	}

	/**
	 * Read normalized products.
	 *
	 * @param array $args {
	 *     Optional. Query overrides.
	 *
	 *     @type string   $status   Post status. Default 'publish'.
	 *     @type int      $limit    Max products. Default 100.
	 *     @type string   $orderby  Order field. Default 'total_sales'.
	 *     @type string   $order    ASC|DESC. Default 'DESC'.
	 *     @type int[]    $include_cats Category term IDs to include.
	 *     @type int[]    $exclude_cats Category term IDs to exclude.
	 * }
	 * @return array<int,array<string,mixed>> Normalized product rows.
	 */
	public static function get_products( $args = array() ) {
		if ( ! self::wc_ready() ) {
			return array();
		}

		$defaults = array(
			'status'       => 'publish',
			'limit'        => 100,
			'orderby'      => 'total_sales',
			'order'        => 'DESC',
			'include_cats' => array(),
			'exclude_cats' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$cache_key = 'lltxt_cat_' . md5( wp_json_encode( $args ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$query_args = array(
			'status'  => $args['status'],
			'limit'   => (int) $args['limit'],
			'orderby' => sanitize_key( $args['orderby'] ),
			'order'   => ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC',
			'return'  => 'objects',
		);

		if ( ! empty( $args['include_cats'] ) ) {
			$query_args['category'] = array_filter( array_map( array( __CLASS__, 'cat_slug' ), (array) $args['include_cats'] ) );
		}

		// WC stores catalog visibility as product_visibility taxonomy terms; products
		// flagged exclude-from-catalog / exclude-from-search must not leak into the
		// public AI-discovery files. Default = "visible" (catalog + search). Override
		// via filter to include 'catalog' or 'search'-only products.
		$visibility = apply_filters( 'lltxt_catalog_visibility', array( 'visible' ), $args );
		$tax_query  = self::build_visibility_tax_query( (array) $visibility );
		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// Per-product opt-out (_lltxt_exclude metabox) wins over everything.
		$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
				'key'     => '_lltxt_exclude',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_lltxt_exclude',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		$products = wc_get_products( $query_args );
		$rows     = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$row = self::normalize( $product );
			if ( null === $row ) {
				continue;
			}
			if ( ! empty( $args['exclude_cats'] ) && self::has_excluded_cat( $row, (array) $args['exclude_cats'] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		set_transient( $cache_key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Build the tax_query that excludes products WC has marked exclude-from-catalog
	 * / exclude-from-search, based on the desired visibility set.
	 *
	 * @param string[] $visibility One or more of: visible, catalog, search, hidden.
	 * @return array
	 */
	private static function build_visibility_tax_query( $visibility ) {
		$visibility = array_filter( array_map( 'strval', $visibility ) );
		if ( empty( $visibility ) ) {
			return array();
		}
		// All four = no filter needed.
		if ( in_array( 'hidden', $visibility, true ) && in_array( 'visible', $visibility, true )
			&& in_array( 'catalog', $visibility, true ) && in_array( 'search', $visibility, true ) ) {
			return array();
		}
		$exclude_terms = array();
		if ( ! in_array( 'search', $visibility, true ) && ! in_array( 'hidden', $visibility, true ) ) {
			$exclude_terms[] = 'exclude-from-catalog';
		}
		if ( ! in_array( 'catalog', $visibility, true ) && ! in_array( 'hidden', $visibility, true ) ) {
			$exclude_terms[] = 'exclude-from-search';
		}
		if ( empty( $exclude_terms ) ) {
			return array();
		}
		return array(
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'slug',
				'terms'    => array_values( array_unique( $exclude_terms ) ),
				'operator' => 'NOT IN',
			),
		);
	}

	/**
	 * Map a category term id to its slug (for wc_get_products 'category').
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private static function cat_slug( $term_id ) {
		$term = get_term( (int) $term_id, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
	}

	/**
	 * Does the normalized row carry any of the excluded category term IDs?
	 *
	 * @param array $row          Normalized row.
	 * @param int[] $exclude_cats Term IDs.
	 * @return bool
	 */
	private static function has_excluded_cat( $row, $exclude_cats ) {
		$exclude = array_map( 'intval', $exclude_cats );
		foreach ( $row['category_ids'] as $cid ) {
			if ( in_array( (int) $cid, $exclude, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a single WC_Product into the emitter shape.
	 *
	 * @param WC_Product $product Product object.
	 * @return array<string,mixed>|null
	 */
	public static function normalize( $product ) {
		$id   = $product->get_id();
		$type = $product->get_type();

		// Price: NEVER 0 for a variable product — use the min variation price so a
		// card can render a "from" price (ref_chatgpt_card_requires_price_and_image).
		$price         = $product->get_price();
		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();
		$price_max     = '';

		if ( 'variable' === $type && $product instanceof WC_Product_Variable ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			if ( '' !== $min && null !== $min ) {
				$price = $min;
			}
			if ( '' !== $max && null !== $max ) {
				$price_max = $max;
			}
			$reg_min = $product->get_variation_regular_price( 'min', true );
			if ( '' !== $reg_min && null !== $reg_min ) {
				$regular_price = $reg_min;
			}
		}

		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : '';
		if ( ! $image_url ) {
			$image_url = wc_placeholder_img_src( 'woocommerce_single' );
		}

		$gallery_ids = $product->get_gallery_image_ids();
		$images      = array();
		if ( $image_url ) {
			$images[] = $image_url;
		}
		foreach ( $gallery_ids as $gid ) {
			$g = wp_get_attachment_image_url( $gid, 'woocommerce_single' );
			if ( $g ) {
				$images[] = $g;
			}
		}

		$cat_ids   = $product->get_category_ids();
		$cat_names = array();
		$cat_refs  = array();
		foreach ( $cat_ids as $cid ) {
			$term = get_term( (int) $cid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$cat_names[] = $term->name;
				$cat_refs[]  = array(
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		$availability = ( 'instock' === $product->get_stock_status() ) ? 'InStock' : 'OutOfStock';
		if ( 'onbackorder' === $product->get_stock_status() ) {
			$availability = 'BackOrder';
		}

		return array(
			'id'                => $id,
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $type,
			'price'             => self::num( $price ),
			'price_max'         => self::num( $price_max ),
			'regular_price'     => self::num( $regular_price ),
			'sale_price'        => self::num( $sale_price ),
			'on_sale'           => (bool) $product->is_on_sale(),
			'currency'          => get_woocommerce_currency(),
			'stock_status'      => $product->get_stock_status(),
			'availability'      => $availability,
			'image_url'         => $image_url,
			'images'            => array_values( array_unique( $images ) ),
			'permalink'         => get_permalink( $id ),
			'categories'        => $cat_names,
			'category_refs'     => $cat_refs,
			'category_ids'      => array_map( 'intval', $cat_ids ),
			'short_description' => self::best_description( $id, $product ),
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'sku'               => $product->get_sku(),
			'total_sales'       => (int) get_post_meta( $id, 'total_sales', true ),
			'featured'          => (bool) $product->is_featured(),
		);
	}

	/**
	 * Best-available product description: hand-curated SEO-plugin meta first
	 * (Yoast / Rank Math / AIOSEO / SEOPress / Slim SEO), then WC short_description.
	 *
	 * @param int        $id      Product post ID.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	private static function best_description( $id, $product ) {
		if ( class_exists( 'Lltxt_Seo_Bridge' ) ) {
			$desc = Lltxt_Seo_Bridge::description_for( $id );
			if ( '' !== $desc ) {
				return $desc;
			}
		}
		return wp_strip_all_tags( $product->get_short_description() );
	}

	/**
	 * Cast a WC price string to a clean float-as-string ('' stays '').
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	private static function num( $v ) {
		if ( '' === $v || null === $v ) {
			return '';
		}
		$n = (float) $v;
		return ( $n > 0 ) ? (string) $n : '';
	}

	/**
	 * List published product categories (id, name, slug, count, link).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$link  = get_term_link( $term );
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => (int) $term->count,
				'link'  => is_wp_error( $link ) ? '' : $link,
			);
		}
		return $out;
	}

	/* ---- Shared store / sample helpers (ported from catalog-sample.ts) ---- */

	/**
	 * Store display name.
	 *
	 * @return string
	 */
	public static function store_name() {
		$name = get_bloginfo( 'name' );
		return $name ? $name : wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Store tagline / description.
	 *
	 * @return string
	 */
	public static function store_tagline() {
		return (string) get_bloginfo( 'description' );
	}

	/**
	 * Card-ready image for a row.
	 *
	 * @param array $row Normalized row.
	 * @return string
	 */
	public static function card_image( $row ) {
		if ( ! empty( $row['image_url'] ) ) {
			return $row['image_url'];
		}
		return ( ! empty( $row['images'][0] ) ) ? $row['images'][0] : '';
	}

	/**
	 * A product becomes an AI shopping CARD only with a usable price AND image
	 * (ref_chatgpt_card_requires_price_and_image).
	 *
	 * @param array $row Normalized row.
	 * @return bool
	 */
	public static function is_card_ready( $row ) {
		$has_price = ( '' !== $row['price'] && (float) $row['price'] > 0 );
		return $has_price && '' !== self::card_image( $row );
	}

	/**
	 * Sample size scales with catalog size: floor 40, ~10%, capped 100.
	 *
	 * @param int $total Total products.
	 * @return int
	 */
	public static function sample_size( $total ) {
		$target = (int) ceil( $total * 0.1 );
		return max( 40, min( 100, max( $target, 0 ) ) );
	}

	/**
	 * Representative sample: card-ready first, spread across categories.
	 *
	 * @param array $rows Normalized rows.
	 * @param int   $n    Desired sample size.
	 * @return array
	 */
	public static function pick_sample( $rows, $n ) {
		if ( $n <= 0 || empty( $rows ) ) {
			return array();
		}
		$ready = array_values( array_filter( $rows, array( __CLASS__, 'is_card_ready' ) ) );
		$pool  = ! empty( $ready ) ? $ready : $rows;

		// Stratify: round-robin across primary categories, each sorted low→high price.
		$by_cat = array();
		foreach ( $pool as $p ) {
			$k = ! empty( $p['category_refs'][0]['slug'] ) ? $p['category_refs'][0]['slug'] : '_';
			$by_cat[ $k ][] = $p;
		}
		foreach ( $by_cat as &$list ) {
			usort(
				$list,
				function ( $a, $b ) {
					return ( (float) $a['price'] <=> (float) $b['price'] );
				}
			);
		}
		unset( $list );
		// Deepest categories first.
		uasort(
			$by_cat,
			function ( $a, $b ) {
				return count( $b ) - count( $a );
			}
		);

		$queues = array_values( $by_cat );
		$out     = array();
		$drained = false;
		while ( count( $out ) < $n && ! $drained ) {
			$drained = true;
			foreach ( $queues as &$q ) {
				$next = array_shift( $q );
				if ( null !== $next ) {
					$out[]   = $next;
					$drained = false;
					if ( count( $out ) >= $n ) {
						break;
					}
				}
			}
			unset( $q );
		}
		return $out;
	}

	/**
	 * Price label for display ("from $X" for variable ranges).
	 *
	 * @param array $row Normalized row.
	 * @return string
	 */
	public static function price_label( $row ) {
		$price = self::format_price( $row['price'] );
		if ( '' === $price ) {
			return '';
		}
		if ( 'variable' === $row['type'] && '' !== $row['price_max'] && (float) $row['price_max'] > (float) $row['price'] ) {
			/* translators: %s: lowest price in a variable-product range. */
			return sprintf( __( 'from %s', 'agentic-commerce-llms-txt' ), $price );
		}
		return $price;
	}

	/**
	 * Number formatted with the store currency precision.
	 *
	 * @param string|float $price Numeric price.
	 * @return string
	 */
	public static function format_price( $price ) {
		if ( '' === $price || null === $price || (float) $price <= 0 ) {
			return '';
		}
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( (float) $price ) );
		}
		return number_format_i18n( (float) $price, 2 );
	}

	/**
	 * Convenience: the products honoring the operator's Catalog-tab settings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_configured_products() {
		$cfg = get_option( 'lltxt_catalog_settings', array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		return self::get_products(
			array(
				'limit'        => isset( $cfg['top_n'] ) ? (int) $cfg['top_n'] : 100,
				'orderby'      => isset( $cfg['orderby'] ) ? $cfg['orderby'] : 'total_sales',
				'order'        => 'DESC',
				'include_cats' => isset( $cfg['include_cats'] ) ? (array) $cfg['include_cats'] : array(),
				'exclude_cats' => isset( $cfg['exclude_cats'] ) ? (array) $cfg['exclude_cats'] : array(),
			)
		);
	}
}
