<?php
/**
 * WordPress rewrite rules → public/lltxt-deliver.php. We use add_rewrite_rule()
 * (NOT a custom .htaccess) so the routes work across Apache / nginx / LiteSpeed
 * and are cleaned up on deactivate.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Router.
 */
class Lltxt_Router {

	/**
	 * The query var used to identify a routed request.
	 */
	const QV = 'lltxt_route';

	/**
	 * Map of route key => relative output path. The route key is what lands in
	 * the lltxt_route query var; the relative path is the static file served.
	 *
	 * @return array<string,string>
	 */
	public static function routes() {
		return array(
			'llms.txt'                      => 'llms.txt',
			'llms-full.txt'                 => 'llms-full.txt',
			'index.md'                      => 'index.md',
			'catalog.json'                  => 'catalog.json',
			'products.json'                 => 'products.json',
			'agents.md'                     => 'agents.md',
			'sitemap-ai.xml'                => 'sitemap-ai.xml',
			'feed/google-shopping.xml'      => 'feed/google-shopping.xml',
			'.well-known/agent-card.json'   => '.well-known/agent-card.json',
			'.well-known/mcp.json'          => '.well-known/mcp.json',
			'.well-known/ucp'               => '.well-known/ucp',
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_deliver' ), 0 );
	}

	/**
	 * Register a rewrite rule + tag for each route.
	 *
	 * @return void
	 */
	public static function register_rules() {
		add_rewrite_tag( '%' . self::QV . '%', '([^&]+)' );
		foreach ( array_keys( self::routes() ) as $route ) {
			// Anchor to the exact path. preg_quote keeps dots/slashes literal.
			$pattern = '^' . preg_quote( $route, '/' ) . '$';
			add_rewrite_rule( $pattern, 'index.php?' . self::QV . '=' . rawurlencode( $route ), 'top' );
		}
	}

	/**
	 * Whitelist our query var.
	 *
	 * @param string[] $vars Existing vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/**
	 * If the request matched a route, hand off to the deliverer.
	 *
	 * @return void
	 */
	public static function maybe_deliver() {
		$route = get_query_var( self::QV );
		if ( '' === $route || null === $route ) {
			return;
		}
		$routes = self::routes();
		if ( ! isset( $routes[ $route ] ) ) {
			return;
		}
		require LLTXT_DIR . 'public/lltxt-deliver.php';
		exit;
	}
}
