<?php
/**
 * Local version-history store. One custom table, owned by this plugin.
 * Every snapshot of /llms.txt and /llms-full.txt lives here — never leaves
 * the site.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Versions.
 */
class Lltxt_Versions {

	/**
	 * Dedup window — skip inserts when the same (route, sha256) was written
	 * inside this window. 7 days matches the prior cloud-side dedup.
	 */
	const DEDUP_WINDOW_DAYS = 7;

	/**
	 * Retention window — sweep_expired() prunes rows older than this unless
	 * pinned.
	 */
	const TTL_DAYS = 90;

	/**
	 * Valid source values.
	 *
	 * @var string[]
	 */
	const VALID_SOURCES = array(
		'plugin-generated',
		'merchant-pre-existing',
		'restored',
	);

	/**
	 * Table name (with $wpdb->prefix).
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lltxt_versions';
	}

	/**
	 * Create the table via dbDelta. Idempotent.
	 *
	 * @return void
	 */
	public static function install_schema() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is strict about whitespace + must use "KEY" not "INDEX".
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			route VARCHAR(64) NOT NULL,
			sha256 CHAR(64) NOT NULL,
			body LONGTEXT NOT NULL,
			bytes INT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(32) NOT NULL,
			pinned TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY route_created (route, created_at),
			KEY pinned (pinned)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Drop the table. Called from uninstall.php only.
	 *
	 * @return void
	 */
	public static function drop_schema() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Insert a new version, with sha256-based dedup against the last
	 * DEDUP_WINDOW_DAYS days for the same route.
	 *
	 * @param string $route  Route relative path (e.g. "llms.txt").
	 * @param string $body   File body.
	 * @param string $source One of VALID_SOURCES.
	 * @return int|false Inserted row id, existing row id on dedup, or false on failure.
	 */
	public static function insert( $route, $body, $source = 'plugin-generated' ) {
		global $wpdb;
		if ( ! is_string( $route ) || '' === $route ) {
			return false;
		}
		if ( ! is_string( $body ) ) {
			return false;
		}
		if ( ! in_array( $source, self::VALID_SOURCES, true ) ) {
			$source = 'plugin-generated';
		}
		$table  = self::table_name();
		$sha    = hash( 'sha256', $body );
		$bytes  = strlen( $body );

		// Dedup: same route + sha within DEDUP_WINDOW_DAYS → return existing id.
		// {$table} is safe — built from $wpdb->prefix + a literal; it cannot be
		// user-controlled. WordPress core uses the same pattern.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM {$table} WHERE route = %s AND sha256 = %s AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY id DESC LIMIT 1",
			$route,
			$sha,
			self::DEDUP_WINDOW_DAYS
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$existing = $wpdb->get_var( $sql );
		if ( $existing ) {
			return (int) $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'route'      => $route,
				'sha256'     => $sha,
				'body'       => $body,
				'bytes'      => $bytes,
				'source'     => $source,
				'pinned'     => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		if ( false === $ok ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * List versions newest-first, optionally filtered by route. Returns a row
	 * shape without the (potentially-large) body field.
	 *
	 * @param string|null $route  Optional route filter.
	 * @param int         $limit  Page size (1..100).
	 * @param int|null    $cursor Last id from previous page (exclusive).
	 * @return array{rows:array<int,array<string,mixed>>,next_cursor:int|null}
	 */
	public static function list( $route = null, $limit = 25, $cursor = null ) {
		global $wpdb;
		$table  = self::table_name();
		$limit  = max( 1, min( 100, (int) $limit ) );
		$cursor = empty( $cursor ) ? 0 : (int) $cursor;

		// Static SQL variants per filter combination — keeps PluginCheck happy
		// vs a dynamically-concatenated WHERE clause. {$table} is safe (built
		// from $wpdb->prefix + a literal); WP core uses the same pattern.
		if ( ! empty( $route ) && $cursor > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT id, route, sha256, bytes, source, pinned, created_at FROM {$table} WHERE route = %s AND id < %d ORDER BY id DESC LIMIT %d",
				$route, $cursor, $limit + 1
			);
		} elseif ( ! empty( $route ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT id, route, sha256, bytes, source, pinned, created_at FROM {$table} WHERE route = %s ORDER BY id DESC LIMIT %d",
				$route, $limit + 1
			);
		} elseif ( $cursor > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT id, route, sha256, bytes, source, pinned, created_at FROM {$table} WHERE id < %d ORDER BY id DESC LIMIT %d",
				$cursor, $limit + 1
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT id, route, sha256, bytes, source, pinned, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit + 1
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$next_cursor = null;
		if ( count( $rows ) > $limit ) {
			$extra       = array_pop( $rows );
			$next_cursor = (int) $rows[ count( $rows ) - 1 ]['id'];
		}
		return array( 'rows' => $rows, 'next_cursor' => $next_cursor );
	}

	/**
	 * Fetch one row by id, including body.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read just the body for a row id.
	 *
	 * @param int $id Row id.
	 * @return string|null
	 */
	public static function read_body( $id ) {
		$row = self::get( $id );
		if ( ! $row || ! isset( $row['body'] ) ) {
			return null;
		}
		return (string) $row['body'];
	}

	/**
	 * Pin / unpin a row.
	 *
	 * @param int  $id     Row id.
	 * @param bool $pinned Pin or unpin.
	 * @return bool
	 */
	public static function pin( $id, $pinned = true ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$res = $wpdb->update(
			self::table_name(),
			array( 'pinned' => $pinned ? 1 : 0 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
		return false !== $res;
	}

	/**
	 * Delete every row in the table.
	 *
	 * @return int Rows deleted.
	 */
	public static function delete_all() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "DELETE FROM {$table}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->query( $sql );
		return $count;
	}

	/**
	 * Delete non-pinned rows older than TTL_DAYS. Called from the daily cron.
	 *
	 * @return int Rows deleted.
	 */
	public static function sweep_expired() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY) AND pinned = 0",
			self::TTL_DAYS
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->query( $sql );
		return $count;
	}
}
