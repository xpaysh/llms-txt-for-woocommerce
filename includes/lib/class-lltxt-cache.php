<?php
/**
 * Static-file writer. Writes emitted output to the merchant webroot atomically
 * and tracks per-file timestamps. Non-destructive: an existing merchant-authored
 * file is backed up + flagged 'merchant-managed', and subsequent writes are
 * skipped until the merchant takes over the file from the Files tab.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Cache.
 */
class Lltxt_Cache {

	/**
	 * Option key holding the per-file write timestamps.
	 */
	const OPT_TIMESTAMPS = 'lltxt_file_timestamps';

	/**
	 * Option key holding per-file write modes:
	 *   plugin-managed  — plugin owns the file (default for plugin-generated)
	 *   merchant-managed — merchant authored the file; plugin will not overwrite
	 *   pinned          — frozen to a specific backend version; plugin will not overwrite
	 */
	const OPT_MODES = 'lltxt_file_modes';

	/**
	 * Option key holding the one-time backup record for each pre-existing file
	 * we found at activation/first-touch.
	 */
	const OPT_INITIAL_BACKUPS = 'lltxt_initial_backups';

	const MODE_PLUGIN   = 'plugin-managed';
	const MODE_MERCHANT = 'merchant-managed';
	const MODE_PINNED   = 'pinned';

	/**
	 * Return value for {@see write()} when the write was intentionally skipped
	 * because the file is merchant-managed or pinned.
	 */
	const SKIP_MERCHANT_MANAGED = 'lltxt_skip_merchant_managed';
	const SKIP_PINNED           = 'lltxt_skip_pinned';

	/**
	 * Resolve the on-disk webroot path. Uses get_home_path() per the WP.org
	 * "Determining file and directory locations" guideline.
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	public static function webroot() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() );
	}

	/**
	 * Full on-disk path for a relative output path.
	 *
	 * @param string $relative_path Relative path, e.g. 'llms.txt'.
	 * @return string
	 */
	public static function get_path( $relative_path ) {
		return self::webroot() . ltrim( $relative_path, '/' );
	}

	/**
	 * Public URL for a relative output path.
	 *
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	public static function get_url( $relative_path ) {
		return home_url( '/' . ltrim( $relative_path, '/' ) );
	}

	/**
	 * Backup directory under wp-content/uploads. Created on demand.
	 *
	 * @return string Trailing-slashed absolute path, or '' on failure.
	 */
	public static function backup_dir() {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'lltxt-backups/';
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		return $dir;
	}

	/**
	 * Per-file mode map.
	 *
	 * @return array<string,string>
	 */
	public static function modes() {
		$m = get_option( self::OPT_MODES, array() );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Get the mode for a single relative path; defaults to plugin-managed.
	 *
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	public static function get_mode( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$m             = self::modes();
		if ( isset( $m[ $relative_path ] ) ) {
			$v = (string) $m[ $relative_path ];
			if ( in_array( $v, array( self::MODE_PLUGIN, self::MODE_MERCHANT, self::MODE_PINNED ), true ) ) {
				return $v;
			}
		}
		return self::MODE_PLUGIN;
	}

	/**
	 * Set the mode for a single relative path.
	 *
	 * @param string $relative_path Relative path.
	 * @param string $mode          One of the MODE_* constants.
	 * @return void
	 */
	public static function set_mode( $relative_path, $mode ) {
		$relative_path = ltrim( $relative_path, '/' );
		$valid         = array( self::MODE_PLUGIN, self::MODE_MERCHANT, self::MODE_PINNED );
		if ( ! in_array( $mode, $valid, true ) ) {
			return;
		}
		$m                     = self::modes();
		$m[ $relative_path ]   = $mode;
		update_option( self::OPT_MODES, $m, false );
	}

	/**
	 * Backup ledger for one-time merchant-pre-existing snapshots.
	 *
	 * @return array<string,array>
	 */
	public static function initial_backups() {
		$b = get_option( self::OPT_INITIAL_BACKUPS, array() );
		return is_array( $b ) ? $b : array();
	}

	/**
	 * Record a backup entry for a pre-existing merchant file.
	 *
	 * @param string $relative_path Relative path.
	 * @param string $backup_path   Absolute backup file path.
	 * @param string $sha256        sha256 of original.
	 * @return void
	 */
	private static function record_initial_backup( $relative_path, $backup_path, $sha256 ) {
		$b                       = self::initial_backups();
		$b[ $relative_path ]     = array(
			'backed_up_at' => time(),
			'sha256'       => $sha256,
			'backup_path'  => $backup_path,
		);
		update_option( self::OPT_INITIAL_BACKUPS, $b, false );
	}

	/**
	 * Back up a pre-existing webroot file (idempotent).
	 *
	 * @param string $relative_path Relative path.
	 * @return bool True if a new backup was created, false if already recorded or copy failed.
	 */
	public static function backup_existing( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$existing      = self::initial_backups();
		if ( isset( $existing[ $relative_path ] ) ) {
			return false;
		}
		$full = self::get_path( $relative_path );
		if ( ! file_exists( $full ) ) {
			return false;
		}
		$dir = self::backup_dir();
		if ( '' === $dir ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$body = @file_get_contents( $full );
		if ( false === $body ) {
			return false;
		}
		$sha          = hash( 'sha256', $body );
		$basename     = sanitize_file_name( str_replace( '/', '__', $relative_path ) );
		$backup_path  = $dir . $basename . '-' . gmdate( 'Y-m-d-His' ) . '.bak';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$wrote = @file_put_contents( $backup_path, $body, LOCK_EX );
		if ( false === $wrote ) {
			return false;
		}
		self::record_initial_backup( $relative_path, $backup_path, $sha );
		self::set_mode( $relative_path, self::MODE_MERCHANT );
		return true;
	}

	/**
	 * Atomically write content to a relative path under the webroot.
	 *
	 * Non-destructive: if the file already exists with different content and
	 * we haven't yet backed it up, we copy it to /wp-content/uploads/lltxt-backups/
	 * and flag the file 'merchant-managed' so we never silently clobber the
	 * merchant's hand-authored version. The merchant can take over the file
	 * from the Files tab.
	 *
	 * @param string $relative_path Relative path, e.g. '.well-known/ucp'.
	 * @param string $content       File body.
	 * @param bool   $force         If true, write regardless of mode (used by Restore).
	 * @return true|string|WP_Error  True on write; SKIP_* string when intentionally skipped; WP_Error on failure.
	 */
	public static function write( $relative_path, $content, $force = false ) {
		$relative_path = ltrim( $relative_path, '/' );
		$full          = self::get_path( $relative_path );
		$dir           = dirname( $full );
		$mode          = self::get_mode( $relative_path );

		// Pinned: never overwrite (merchant restored to a specific version).
		if ( ! $force && self::MODE_PINNED === $mode ) {
			return self::SKIP_PINNED;
		}

		// Pre-existing on-disk file the plugin didn't write → back up + skip.
		if ( ! $force && file_exists( $full ) && self::MODE_PLUGIN === $mode && ! isset( self::initial_backups()[ $relative_path ] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$existing = @file_get_contents( $full );
			if ( false !== $existing && hash( 'sha256', $existing ) !== hash( 'sha256', $content ) ) {
				self::backup_existing( $relative_path );
				// Now flagged merchant-managed by backup_existing.
				return self::SKIP_MERCHANT_MANAGED;
			}
		}

		// Merchant-managed: skip (merchant authored or took over).
		if ( ! $force && self::MODE_MERCHANT === $mode ) {
			return self::SKIP_MERCHANT_MANAGED;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'lltxt_mkdir', sprintf( 'Could not create directory: %s', $dir ) );
		}

		// wp_is_writable() is the WP_Filesystem-aware wrapper around is_writable().
		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'lltxt_not_writable', sprintf( 'Directory not writable: %s', $dir ) );
		}

		$result = self::write_with_fallbacks( $full, $content );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$bytes = (int) $result;

		// Flywheel's webroot is the sibling `www/` (not ABSPATH). If that layout is
		// detected, mirror the file there so the public URL actually serves it.
		self::maybe_mirror_to_flywheel_www( $relative_path, $content );

		self::record_timestamp( $relative_path, time(), $bytes );
		return true;
	}

	/**
	 * Try the fast path first (file_put_contents + atomic rename); fall back to
	 * WP_Filesystem (handles WP Engine / VIP / hardened hosts); finally try a
	 * raw fopen stream. Each transition is logged so support can diagnose.
	 *
	 * @param string $full    Absolute destination path.
	 * @param string $content File body.
	 * @return int|WP_Error  Bytes written on success.
	 */
	private static function write_with_fallbacks( $full, $content ) {
		$dir = dirname( $full );

		// 1. Fast path — atomic temp + rename.
		$tmp = $full . '.tmp-' . wp_generate_password( 8, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- writing /llms.txt to the site root is the plugin's whole purpose.
		$bytes = @file_put_contents( $tmp, $content, LOCK_EX );
		if ( false !== $bytes ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( @rename( $tmp, $full ) ) {
				return (int) $bytes;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[lltxt] fast-path rename failed for ' . $full . ', falling back to WP_Filesystem' );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[lltxt] file_put_contents failed for ' . $tmp . ', falling back to WP_Filesystem' );
		}

		// 2. WP_Filesystem (direct method) — works on hardened hosts.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->put_contents( $full, $content, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false ) ) {
				return strlen( $content );
			}
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[lltxt] WP_Filesystem write failed for ' . $full . ', falling back to fopen' );

		// 3. fopen stream fallback (some hosts block file_put_contents but allow streams).
		$tmp2 = $full . '.tmp-' . wp_generate_password( 8, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fh = @fopen( $tmp2, 'wb' );
		if ( $fh ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$written = @fwrite( $fh, $content );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@fclose( $fh );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false !== $written && @rename( $tmp2, $full ) ) {
				return (int) $written;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp2 );
		}

		return new WP_Error( 'lltxt_write_all_failed', sprintf( 'All write strategies failed for: %s (dir=%s)', $full, $dir ) );
	}

	/**
	 * Flywheel hosts the webroot at `dirname(ABSPATH)/www/`. Mirror writes there
	 * so /llms.txt actually resolves — fixed by ryhowa 8.2.8 upstream.
	 *
	 * @param string $relative_path Relative path.
	 * @param string $content       Body to mirror.
	 * @return void
	 */
	private static function maybe_mirror_to_flywheel_www( $relative_path, $content ) {
		$candidate = dirname( untrailingslashit( ABSPATH ) ) . '/www/';
		if ( ! is_dir( $candidate ) ) {
			return;
		}
		$mirror = $candidate . ltrim( $relative_path, '/' );
		$mdir   = dirname( $mirror );
		if ( ! wp_mkdir_p( $mdir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Flywheel mirror write to the host's actual webroot (outside ABSPATH); plugin's whole purpose is serving llms.txt at the site root.
		@file_put_contents( $mirror, $content, LOCK_EX );
	}

	/**
	 * Delete a static file by relative path.
	 *
	 * @param string $relative_path Relative path.
	 * @return void
	 */
	public static function delete( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$full          = self::get_path( $relative_path );
		if ( file_exists( $full ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $full );
		}
		$stamps = self::timestamps();
		unset( $stamps[ $relative_path ] );
		update_option( self::OPT_TIMESTAMPS, $stamps, false );
	}

	/**
	 * Record a write timestamp + size for a file.
	 *
	 * @param string $relative_path Relative path.
	 * @param int    $time          Unix time.
	 * @param int    $bytes         Bytes written.
	 * @return void
	 */
	private static function record_timestamp( $relative_path, $time, $bytes ) {
		$stamps                   = self::timestamps();
		$stamps[ $relative_path ] = array(
			'time'  => (int) $time,
			'bytes' => (int) $bytes,
		);
		update_option( self::OPT_TIMESTAMPS, $stamps, false );
	}

	/**
	 * All recorded timestamps.
	 *
	 * @return array<string,array{time:int,bytes:int}>
	 */
	public static function timestamps() {
		$stamps = get_option( self::OPT_TIMESTAMPS, array() );
		return is_array( $stamps ) ? $stamps : array();
	}

	/**
	 * The write time for one file, or 0 if never written.
	 *
	 * @param string $relative_path Relative path.
	 * @return int
	 */
	public static function last_written( $relative_path ) {
		$stamps        = self::timestamps();
		$relative_path = ltrim( $relative_path, '/' );
		return isset( $stamps[ $relative_path ]['time'] ) ? (int) $stamps[ $relative_path ]['time'] : 0;
	}

	/**
	 * Read a static file's body (for previews / delivery).
	 *
	 * @param string $relative_path Relative path.
	 * @return string|false
	 */
	public static function read( $relative_path ) {
		$full = self::get_path( $relative_path );
		if ( ! file_exists( $full ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		return @file_get_contents( $full );
	}
}
