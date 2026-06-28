<?php
/**
 * Best-available product/post description, sourced from whichever SEO plugin
 * the merchant has installed. SEO plugins store hand-curated, search-tuned
 * descriptions that beat the raw WC short_description for AI cards.
 *
 * Priority chain: Yoast → Rank Math → AIOSEO → SEOPress → Slim SEO → WC
 * short_description → truncated post_content. Each step is defensively guarded
 * so a partially-loaded SEO plugin never fatals.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Seo_Bridge.
 */
class Lltxt_Seo_Bridge {

	/**
	 * Truncation length for the post_content last-resort fallback.
	 */
	const FALLBACK_MAX = 160;

	/**
	 * Return the best meta description for a post/product. Empty string when
	 * nothing usable is found.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function description_for( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}

		$chain = array(
			array( __CLASS__, 'from_yoast' ),
			array( __CLASS__, 'from_rank_math' ),
			array( __CLASS__, 'from_aioseo' ),
			array( __CLASS__, 'from_seopress' ),
			array( __CLASS__, 'from_slim_seo' ),
			array( __CLASS__, 'from_wc_short_description' ),
			array( __CLASS__, 'from_post_content' ),
		);

		foreach ( $chain as $step ) {
			$result = call_user_func( $step, $post_id );
			if ( is_array( $result ) && ! empty( $result['value'] ) ) {
				$value  = self::clean( $result['value'] );
				$source = isset( $result['source'] ) ? (string) $result['source'] : 'unknown';
				if ( '' !== $value ) {
					return (string) apply_filters( 'lltxt_post_meta_description', $value, $post_id, $source );
				}
			}
		}
		return '';
	}

	/**
	 * Detect which SEO source is currently winning the chain for diagnostics.
	 *
	 * @param int $post_id Optional sample post ID.
	 * @return string Human-readable label (e.g. "Yoast SEO", "Rank Math", "WooCommerce short description", "None").
	 */
	public static function detected_source( $post_id = 0 ) {
		// Active plugin probe (independent of any particular post).
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return __( 'Yoast SEO', 'llms-txt-for-woocommerce' );
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return __( 'Rank Math', 'llms-txt-for-woocommerce' );
		}
		if ( function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' ) ) {
			return __( 'All in One SEO', 'llms-txt-for-woocommerce' );
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return __( 'SEOPress', 'llms-txt-for-woocommerce' );
		}
		if ( defined( 'SLIM_SEO_VERSION' ) || class_exists( 'SlimSEO\\Plugin' ) ) {
			return __( 'Slim SEO', 'llms-txt-for-woocommerce' );
		}
		return __( 'WooCommerce short description', 'llms-txt-for-woocommerce' );
	}

	/**
	 * Yoast SEO.
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_yoast( $post_id ) {
		$raw = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		$value = (string) $raw;
		// Yoast tokens like %%sitename%% — expand if the replacer is available.
		if ( class_exists( 'WPSEO_Replace_Vars' ) && false !== strpos( $value, '%%' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$replacer = new WPSEO_Replace_Vars();
				$value    = $replacer->replace( $value, $post );
			}
		}
		return array(
			'value'  => $value,
			'source' => 'yoast',
		);
	}

	/**
	 * Rank Math.
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_rank_math( $post_id ) {
		$raw = get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		$value = (string) $raw;
		// Rank Math `%placeholders%`. Try Helper::replace_vars when present; else strip.
		if ( false !== strpos( $value, '%' ) ) {
			if ( class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'replace_vars' ) ) {
				$post  = get_post( $post_id );
				$value = \RankMath\Helper::replace_vars( $value, $post );
			} else {
				$value = preg_replace( '/%[a-z0-9_]+%/i', '', $value );
			}
		}
		return array(
			'value'  => $value,
			'source' => 'rank_math',
		);
	}

	/**
	 * AIOSEO.
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_aioseo( $post_id ) {
		if ( function_exists( 'aioseo' ) ) {
			try {
				$aio = aioseo();
				if ( $aio && isset( $aio->meta, $aio->meta->description ) && is_callable( array( $aio->meta->description, 'getPostDescription' ) ) ) {
					$value = (string) $aio->meta->description->getPostDescription( $post_id );
					if ( '' !== $value ) {
						return array(
							'value'  => $value,
							'source' => 'aioseo',
						);
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to meta key.
			}
		}
		$raw = get_post_meta( $post_id, '_aioseo_description', true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return array(
			'value'  => (string) $raw,
			'source' => 'aioseo',
		);
	}

	/**
	 * SEOPress.
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_seopress( $post_id ) {
		$raw = get_post_meta( $post_id, '_seopress_titles_desc', true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return array(
			'value'  => (string) $raw,
			'source' => 'seopress',
		);
	}

	/**
	 * Slim SEO — accept either historical meta key.
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_slim_seo( $post_id ) {
		$raw = get_post_meta( $post_id, '_slim_seo_description', true );
		if ( '' === $raw || null === $raw ) {
			$raw = get_post_meta( $post_id, '_slim_seo_meta_desc', true );
		}
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return array(
			'value'  => (string) $raw,
			'source' => 'slim_seo',
		);
	}

	/**
	 * WooCommerce short description (post_excerpt for products).
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_wc_short_description( $post_id ) {
		$value = (string) get_post_field( 'post_excerpt', $post_id );
		if ( '' === $value ) {
			return null;
		}
		return array(
			'value'  => $value,
			'source' => 'wc_short_description',
		);
	}

	/**
	 * Truncated post_content — last resort.
	 *
	 * @param int $post_id Post ID.
	 * @return array{value:string,source:string}|null
	 */
	private static function from_post_content( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === $content ) {
			return null;
		}
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );
		if ( '' === $content ) {
			return null;
		}
		if ( function_exists( 'mb_substr' ) && mb_strlen( $content ) > self::FALLBACK_MAX ) {
			$content = rtrim( mb_substr( $content, 0, self::FALLBACK_MAX ) ) . '…';
		} elseif ( strlen( $content ) > self::FALLBACK_MAX ) {
			$content = rtrim( substr( $content, 0, self::FALLBACK_MAX ) ) . '…';
		}
		return array(
			'value'  => $content,
			'source' => 'post_content',
		);
	}

	/**
	 * Common cleanup applied to every chain result.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		return $value;
	}
}
