<?php
/**
 * Refresh orchestrator. Runs all file-emitters, writes static files, logs the
 * outcome. Daily WP-Cron + on-demand + stale-fallback (>48h => schedule a
 * one-off refresh on next page load).
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package LLMsTxtForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Lltxt_Refresh.
 */
class Lltxt_Refresh {

	/**
	 * Option holding the last successful refresh unix timestamp.
	 */
	const OPT_LAST = 'lltxt_last_refresh_ts';

	/**
	 * Option holding the rolling refresh log (capped).
	 */
	const OPT_LOG = 'lltxt_refresh_log';

	/**
	 * Max log entries retained.
	 */
	const LOG_CAP = 100;

	/**
	 * Stale threshold (48h) after which a fallback refresh is scheduled.
	 */
	const STALE_SECONDS = 172800;

	/**
	 * Transient key used as a mutex so two refreshes can't run concurrently
	 * (cron + admin "Sync now" overlap, double emitter cost, duplicate
	 * snapshot POSTs to xpay.sh).
	 */
	const LOCK_KEY = 'lltxt_refresh_lock';

	/**
	 * Max lock TTL (seconds). Long enough for a slow store full refresh,
	 * short enough that a dead-locked refresh self-clears.
	 */
	const LOCK_TTL = 120;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( Lltxt_Plugin::CRON_DAILY, array( __CLASS__, 'run' ) );
		add_action( Lltxt_Plugin::CRON_NOW, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_refresh' ), 999 );
	}

	/**
	 * Stale-fallback: if we've never refreshed, or it's been >48h, schedule a
	 * one-off refresh to fire on the next loopback request.
	 *
	 * @return void
	 */
	public static function maybe_schedule_refresh() {
		$last = (int) get_option( self::OPT_LAST, 0 );
		if ( $last > 0 && ( time() - $last ) < self::STALE_SECONDS ) {
			return;
		}
		if ( wp_next_scheduled( Lltxt_Plugin::CRON_NOW ) ) {
			return;
		}
		wp_schedule_single_event( time(), Lltxt_Plugin::CRON_NOW );
	}

	/**
	 * Run every static-file emitter and write its output.
	 *
	 * @param string[]|null $only Optional list of emitter class names to run.
	 * @return array{ok:int,fail:int,errors:array<string,string>}
	 */
	public static function run( $only = null ) {
		// Concurrent-run guard. get_transient() returns false when no lock is
		// set, so the conditional handles "no lock" + "expired" identically.
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return array(
				'ok'     => 0,
				'fail'   => 0,
				'errors' => array( '_lock' => 'Another refresh is already running.' ),
			);
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

		$started = microtime( true );
		$classes = is_array( $only ) ? $only : Lltxt_Plugin::emitter_classes();
		$ok      = 0;
		$fail    = 0;
		$errors  = array();
		// Routes skipped because the route's current mode forbids overwrite.
		// path => 'pinned' | 'merchant-managed'.
		$skipped = array();
		// Pending snapshot fires: collected during the write loop, fired in
		// a second pass so we never interleave write+POST.
		$snaps   = array();

		foreach ( $classes as $class ) {
			$emitter = Lltxt_Plugin::make_emitter( $class );
			if ( null === $emitter ) {
				continue;
			}
			$path = $emitter->output_path();
			// Defensive: skip an emitter that reports no output path (no current
			// emitter does, but third-party emitters added via filters might).
			if ( null === $path || '' === $path ) {
				continue;
			}

			try {
				$body   = $emitter->render();
				$result = Lltxt_Cache::write( $path, $body );
				if ( is_wp_error( $result ) ) {
					++$fail;
					$errors[ $path ] = $result->get_error_message();
				} elseif ( Lltxt_Cache::SKIP_MERCHANT_MANAGED === $result ) {
					// Merchant authored this file — record THEIR copy upstream so
					// they can still see and roll forward from it. Read on-disk
					// body so we send what's actually being served.
					$existing = Lltxt_Cache::read( $path );
					if ( is_string( $existing ) ) {
						$snaps[] = array( $path, $existing, 'merchant-pre-existing' );
					}
					$skipped[ $path ] = 'merchant-managed';
					++$ok;
				} elseif ( Lltxt_Cache::SKIP_PINNED === $result ) {
					// Pinned to a specific version — nothing to do.
					$skipped[ $path ] = 'pinned';
					++$ok;
				} else {
					$snaps[] = array( $path, $body, 'plugin-generated' );
					++$ok;
				}
			} catch ( Exception $e ) {
				++$fail;
				$errors[ $path ] = $e->getMessage();
			}
		}

		// Second pass: persist new versions to the local store.
		if ( class_exists( 'Lltxt_Versions' ) ) {
			foreach ( $snaps as $s ) {
				Lltxt_Versions::insert( $s[0], $s[1], $s[2] );
			}
		}

		// Only treat a whole-run (no $only filter) as a "last refresh" bump,
		// and only then do the daily TTL sweep.
		if ( null === $only ) {
			update_option( self::OPT_LAST, time(), false );
			if ( class_exists( 'Lltxt_Versions' ) ) {
				Lltxt_Versions::sweep_expired();
			}
		}

		self::log(
			array(
				'time'     => time(),
				'ok'       => $ok,
				'fail'     => $fail,
				'duration' => round( ( microtime( true ) - $started ) * 1000 ),
				'errors'   => $errors,
				'partial'  => ( null !== $only ),
			)
		);

		delete_transient( self::LOCK_KEY );

		return array(
			'ok'      => $ok,
			'fail'    => $fail,
			'errors'  => $errors,
			'skipped' => $skipped,
		);
	}

	/**
	 * Run a single emitter by class name (used by per-file "Regenerate").
	 *
	 * @param string $class Emitter class name.
	 * @return array{ok:int,fail:int,errors:array<string,string>}
	 */
	public static function run_one( $class ) {
		return self::run( array( $class ) );
	}

	/**
	 * Append a log entry, capped at LOG_CAP (newest first).
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return void
	 */
	private static function log( $entry ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LOG_CAP );
		update_option( self::OPT_LOG, $log, false );
	}

	/**
	 * Read the refresh log (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_log() {
		$log = get_option( self::OPT_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Last refresh unix timestamp (0 if never).
	 *
	 * @return int
	 */
	public static function last_refresh() {
		return (int) get_option( self::OPT_LAST, 0 );
	}
}
