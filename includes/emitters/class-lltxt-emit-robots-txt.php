<?php
/**
 * robots.txt AI-bot directives. HOOK-ONLY — writes no static file; appends to
 * WordPress's dynamic robots.txt via the `robots_txt` filter. Ported in spirit
 * from xpay-woocommerce/includes/class-xpay-robots.php.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Emit_Robots_Txt.
 */
class Lltxt_Emit_Robots_Txt implements Lltxt_Emitter_Interface {

	/**
	 * Bots we know how to toggle. Value = whether it's a shopping agent (vs a
	 * training-only crawler like GPTBot).
	 *
	 * @return array<string,bool>
	 */
	public static function known_bots() {
		return array(
			'ChatGPT-User'  => true,
			'OAI-SearchBot' => true,
			'Claude-User'   => true,
			'PerplexityBot' => true,
			'GoogleOther'   => true,
			'GPTBot'        => false,
		);
	}

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
		return 'text/plain; charset=utf-8';
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
	 * Returns the bot-rules block (used for the admin preview).
	 */
	public function render() {
		return self::build_block();
	}

	/**
	 * Build the Allow/Disallow block from the saved allowed-bots option.
	 *
	 * @return string
	 */
	public static function build_block() {
		$allowed = get_option( 'lltxt_allowed_bots', Lltxt_Emit_Robots_Txt_defaults() );
		$allowed = is_array( $allowed ) ? $allowed : array();

		$lines   = array();
		$lines[] = '# LLMs.txt for WooCommerce — AI bot directives';
		foreach ( self::known_bots() as $bot => $is_shopping ) {
			$on      = ! empty( $allowed[ $bot ] );
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = $on ? 'Allow: /' : 'Disallow: /';
		}
		$lines[] = 'Sitemap: ' . untrailingslashit( home_url() ) . '/sitemap-ai.xml';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * `robots_txt` filter callback.
	 *
	 * @param string $output    Existing robots.txt body.
	 * @param bool   $is_public Whether the site is set to be indexed.
	 * @return string
	 */
	public static function filter_robots_txt( $output, $is_public ) {
		if ( ! $is_public ) {
			// "Discourage search engines" — respect the merchant's intent.
			return $output;
		}
		$existing = strtolower( $output );
		$lines    = array();
		$allowed  = get_option( 'lltxt_allowed_bots', Lltxt_Emit_Robots_Txt_defaults() );
		$allowed  = is_array( $allowed ) ? $allowed : array();

		foreach ( self::known_bots() as $bot => $is_shopping ) {
			$needle = 'user-agent: ' . strtolower( $bot );
			if ( false !== strpos( $existing, $needle ) ) {
				// Already configured — don't fight the merchant's existing rule.
				continue;
			}
			$on      = ! empty( $allowed[ $bot ] );
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = $on ? 'Allow: /' : 'Disallow: /';
		}

		if ( empty( $lines ) ) {
			return $output;
		}

		$header = "\n# LLMs.txt for WooCommerce — AI bot directives\n";
		return rtrim( $output, "\n" ) . "\n" . $header . implode( "\n", $lines ) . "\n";
	}
}
