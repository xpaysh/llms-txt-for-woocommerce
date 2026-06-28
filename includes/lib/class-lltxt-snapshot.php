<?php
/**
 * Snapshot backend client — the ONE place in the plugin that talks to the
 * version-control backend (snapshot, list, get, recommend, pin, delete). Every
 * public method short-circuits when phone-home is disabled, so disabling the
 * Privacy toggle truly stops all egress to xpay.sh.
 *
 * What we send (and nothing else):
 *   - rendered file body
 *   - WP / WC / plugin version strings
 *   - sha256(api_key) as the X-Xpay-Api-Key header
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Snapshot.
 */
class Lltxt_Snapshot {

	const OPT_PHONE_HOME       = 'lltxt_phone_home';
	const OPT_API_KEY          = 'lltxt_api_key';
	const OPT_BACKEND_BASE_URL = 'lltxt_backend_base_url';
	const OPT_LAST_SNAPSHOT_TS = 'lltxt_last_snapshot_ts';

	/**
	 * Transient flag set when the backend rejects our api_key as belonging to
	 * a different site (reinstall after uninstall; production -> staging
	 * clone; site moved hosts). The admin UI surfaces a "Reset connection"
	 * prompt while this flag is present.
	 */
	const TRANSIENT_KEY_MISMATCH = 'lltxt_api_key_mismatch';

	/**
	 * Default production base URL. Override via the `lltxt_backend_base_url`
	 * filter or the `lltxt_backend_base_url` wp_option for self-hosted or
	 * dev backends.
	 */
	const DEFAULT_BASE_URL = 'https://llmstxt-api.xpay.sh';

	/**
	 * Phone-home master toggle.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (int) get_option( self::OPT_PHONE_HOME, 1 ) === 1;
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
	 * @return string Hex digest, or empty string if no key.
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
	 * Slug derived from home_url host: replace dots with dashes, lowercase.
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
	 * Common request args.
	 *
	 * @param array $extra Extra args merged on top.
	 * @return array
	 */
	private static function args( $extra = array() ) {
		global $wp_version;
		$wc_version     = defined( 'WC_VERSION' ) ? WC_VERSION : '';
		$ua             = sprintf(
			'LLMs.txt-for-WooCommerce/%s (WP %s; WC %s)',
			defined( 'LLTXT_VERSION' ) ? LLTXT_VERSION : '1.0.0',
			$wp_version,
			$wc_version
		);
		$args = array(
			'timeout' => 5,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-Xpay-Api-Key'  => self::api_key_hash(),
				'User-Agent'      => $ua,
			),
		);
		return array_merge( $args, $extra );
	}

	/**
	 * POST /v1/llms-txt/snapshot. Non-blocking by default.
	 *
	 * @param string $route   Route key (e.g. 'llms.txt').
	 * @param string $body    Rendered file body.
	 * @param string $source  'plugin-generated' | 'merchant-pre-existing' | 'restore'.
	 * @param bool   $blocking Force blocking request (admin "Sync now" uses true).
	 * @return string|WP_Error|null Version id, error, or null when disabled.
	 */
	public static function post_snapshot( $route, $body, $source = 'plugin-generated', $blocking = false ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'Could not derive slug from home_url.' );
		}

		global $wp_version;
		$payload = array(
			'slug'           => $slug,
			'merchant_url'   => home_url( '/' ),
			'route'          => $route,
			'body'           => (string) $body,
			'source'         => $source,
			'wp_version'     => $wp_version,
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'plugin_version' => defined( 'LLTXT_VERSION' ) ? LLTXT_VERSION : null,
		);

		$args = self::args(
			array(
				'method'   => 'POST',
				'body'     => wp_json_encode( $payload ),
				'blocking' => (bool) $blocking,
			)
		);

		$res = wp_remote_request( self::base_url() . '/v1/llms-txt/snapshot', $args );

		// Best-effort timestamp even for non-blocking fires.
		update_option( self::OPT_LAST_SNAPSHOT_TS, time(), false );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $blocking ) {
			return null;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $decoded ) && isset( $decoded['version_id'] ) ) {
			return (string) $decoded['version_id'];
		}
		return new WP_Error( 'lltxt_snapshot_failed', 'Unexpected response', $decoded );
	}

	/**
	 * GET /v1/llms-txt/versions.
	 *
	 * @param string|null $route  Optional route filter.
	 * @param string|null $cursor Pagination cursor.
	 * @param int         $limit  Page size.
	 * @return array|WP_Error|null
	 */
	public static function list_versions( $route = null, $cursor = null, $limit = 25 ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'No slug.' );
		}
		$qs = array(
			'slug'  => $slug,
			'limit' => max( 1, min( 100, (int) $limit ) ),
		);
		if ( ! empty( $route ) ) {
			$qs['route'] = $route;
		}
		if ( ! empty( $cursor ) ) {
			$qs['cursor'] = $cursor;
		}
		$url = self::base_url() . '/v1/llms-txt/versions?' . http_build_query( $qs );

		$res = wp_remote_get( $url, self::args() );
		return self::decode( $res );
	}

	/**
	 * GET /v1/llms-txt/version/{version_id}.
	 *
	 * @param string $version_id Version id (route#timestamp).
	 * @return array|WP_Error|null
	 */
	public static function get_version( $version_id ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug || '' === $version_id ) {
			return new WP_Error( 'lltxt_bad_args', 'Missing slug or version_id.' );
		}
		$url = self::base_url() . '/v1/llms-txt/version/' . rawurlencode( $version_id ) . '?slug=' . rawurlencode( $slug );
		$res = wp_remote_get( $url, self::args() );
		return self::decode( $res );
	}

	/**
	 * POST /v1/llms-txt/recommend.
	 *
	 * @param string $route Route key.
	 * @return array|WP_Error|null
	 */
	public static function recommend( $route ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'No slug.' );
		}
		$res = wp_remote_post(
			self::base_url() . '/v1/llms-txt/recommend',
			self::args(
				array(
					'body' => wp_json_encode(
						array(
							'slug'  => $slug,
							'route' => $route,
						)
					),
				)
			)
		);
		return self::decode( $res );
	}

	/**
	 * POST /v1/llms-txt/pin.
	 *
	 * @param string $version_id Version id.
	 * @param bool   $pinned     Pin/unpin.
	 * @return array|WP_Error|null
	 */
	public static function pin( $version_id, $pinned = true ) {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'No slug.' );
		}
		$res = wp_remote_post(
			self::base_url() . '/v1/llms-txt/pin',
			self::args(
				array(
					'body' => wp_json_encode(
						array(
							'slug'       => $slug,
							'version_id' => $version_id,
							'pinned'     => (bool) $pinned,
						)
					),
				)
			)
		);
		return self::decode( $res );
	}

	/**
	 * DELETE /v1/llms-txt/merchant?slug=...
	 *
	 * @return array|WP_Error|null
	 */
	public static function delete_merchant() {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$slug = self::slug();
		if ( '' === $slug ) {
			return new WP_Error( 'lltxt_no_slug', 'No slug.' );
		}
		$res = wp_remote_request(
			self::base_url() . '/v1/llms-txt/merchant?slug=' . rawurlencode( $slug ),
			self::args( array( 'method' => 'DELETE' ) )
		);
		return self::decode( $res );
	}

	/**
	 * Decode a JSON response into an array or WP_Error.
	 *
	 * @param array|WP_Error $res Response.
	 * @return array|WP_Error
	 */
	private static function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( $code >= 200 && $code < 300 ) {
			// Successful round-trip — clear any stale mismatch flag.
			delete_transient( self::TRANSIENT_KEY_MISMATCH );
			return is_array( $json ) ? $json : array();
		}
		$err = is_array( $json ) && isset( $json['error'] ) ? (string) $json['error'] : 'HTTP ' . $code;
		// Surface api_key_mismatch in a way the admin UI can detect (it shows
		// the "Reset connection" notice in the Privacy + Version Control tabs).
		if ( 403 === $code || 'api_key_mismatch' === $err ) {
			set_transient( self::TRANSIENT_KEY_MISMATCH, 1, DAY_IN_SECONDS );
		}
		return new WP_Error(
			'lltxt_http_' . $code,
			$err,
			$json
		);
	}

	/**
	 * True when the backend recently rejected our api_key as belonging to a
	 * different site (production -> staging clone, reinstall after uninstall).
	 *
	 * @return bool
	 */
	public static function has_key_mismatch() {
		return (bool) get_transient( self::TRANSIENT_KEY_MISMATCH );
	}

	/**
	 * Reset the local connection — rotate api_key, drop the initial-backups
	 * ledger, clear the mismatch flag. Called from the Privacy tab's "Reset
	 * connection" handler. The orphan row on the backend ages out via TTL.
	 *
	 * @return void
	 */
	public static function reset_connection() {
		delete_option( self::OPT_API_KEY );
		delete_option( 'lltxt_initial_backups' );
		delete_transient( self::TRANSIENT_KEY_MISMATCH );
		// Re-seed a fresh key immediately so the next request uses the new
		// hash. api_key_hash() bootstraps on read.
		self::api_key_hash();
	}
}
