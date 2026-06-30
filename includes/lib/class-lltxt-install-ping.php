<?php
/**
 * Install ping — the ONE place in the plugin that talks to xpay.sh. Fires on
 * activate, weekly via WP-Cron, and on uninstall. Short-circuits when the
 * Privacy toggle is off so disabling truly stops all egress.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Install_Ping.
 */
class Lltxt_Install_Ping {

	const OPT_ENABLED          = 'lltxt_phone_home';
	const OPT_API_KEY          = 'lltxt_api_key';
	const OPT_BACKEND_BASE_URL = 'lltxt_backend_base_url';
	const OPT_LAST_PING_TS     = 'lltxt_last_snapshot_ts';

	/**
	 * Default production base URL. Override via the `lltxt_backend_base_url`
	 * filter or the `lltxt_backend_base_url` wp_option.
	 */
	const DEFAULT_BASE_URL = 'https://llmstxt-api.xpay.sh';

	/**
	 * Master toggle. OFF by default — install ping is strictly opt-in.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (int) get_option( self::OPT_ENABLED, 0 ) === 1;
	}

	/**
	 * Filterable backend base URL.
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = get_option( self::OPT_BACKEND_BASE_URL, self::DEFAULT_BASE_URL );
		/**
		 * Filter the backend base URL.
		 *
		 * @param string $url Base URL (no trailing slash).
		 */
		$url = apply_filters( 'lltxt_backend_base_url', $url );
		return untrailingslashit( (string) $url );
	}

	/**
	 * Sha256 of the locally-stored api_key. Bootstrap on first call.
	 *
	 * @return string
	 */
	public static function api_key_hash() {
		$key = get_option( self::OPT_API_KEY, '' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, false, false );
			update_option( self::OPT_API_KEY, $key, false );
		}
		return hash( 'sha256', $key );
	}

	/**
	 * Slug derived from home_url host.
	 *
	 * @return string
	 */
	public static function slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		return strtolower( str_replace( '.', '-', $host ) );
	}

	/**
	 * Count of published WooCommerce products. Used in the install ping so we
	 * can size-segment installs.
	 *
	 * @return int
	 */
	private static function active_products() {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		if ( is_object( $counts ) && isset( $counts->publish ) ) {
			return (int) $counts->publish;
		}
		return 0;
	}

	/**
	 * Common request args.
	 *
	 * @param array $extra Extra args merged on top.
	 * @return array
	 */
	private static function args( $extra = array() ) {
		global $wp_version;
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '';
		$ua         = sprintf(
			'LLMs.txt-for-WooCommerce/%s (WP %s; WC %s)',
			defined( 'LLTXT_VERSION' ) ? LLTXT_VERSION : '1.0.0',
			$wp_version,
			$wc_version
		);
		$args = array(
			'timeout' => 5,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
				'X-Xpay-Api-Key' => self::api_key_hash(),
				'User-Agent'     => $ua,
			),
		);
		return array_merge( $args, $extra );
	}

	/**
	 * POST /v1/llms-txt/installs.
	 *
	 * @param string $context 'activate' | 'weekly' | 'manual' | 'deactivate'.
	 *                       (Uninstall has its own fire-and-forget call from
	 *                       uninstall.php — the plugin code isn't loaded then.)
	 * @return array|WP_Error|null
	 */
	public static function ping( $context = 'activate' ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'Could not derive slug from home_url.' );
		}

		global $wp_version;
		$payload = array(
			'slug'                    => $slug,
			'home_url'                => home_url( '/' ),
			'wp_version'              => $wp_version,
			'wc_version'              => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'plugin_version'          => defined( 'LLTXT_VERSION' ) ? LLTXT_VERSION : null,
			'active_products'         => self::active_products(),
			'version_history_enabled' => true,
			'context'                 => $context,
		);

		$args = self::args(
			array(
				'method' => 'POST',
				'body'   => wp_json_encode( $payload ),
			)
		);

		$res = wp_remote_post( self::base_url() . '/v1/llms-txt/installs', $args );

		update_option( self::OPT_LAST_PING_TS, time(), false );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * DELETE /v1/llms-txt/installs?slug=<slug>. Used by the "Delete my install
	 * info" button and by uninstall.php.
	 *
	 * @return array|WP_Error|null
	 */
	public static function delete_install() {
		if ( ! self::is_enabled() ) {
			return null;
		}
		// If no api_key has ever been generated, this install has never sent
		// anything to the backend — there is nothing to delete. Short-circuit
		// here so we do NOT generate a persistent identifier purely for a
		// delete request.
		if ( '' === (string) get_option( self::OPT_API_KEY, '' ) ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'No slug.' );
		}
		$res = wp_remote_request(
			self::base_url() . '/v1/llms-txt/installs?slug=' . rawurlencode( $slug ),
			self::args( array( 'method' => 'DELETE' ) )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
