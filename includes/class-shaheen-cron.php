<?php
/**
 * WP-Cron Scheduler and Batch Runner for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Cron {

	const CRON_HOOK = 'shaheen_sync_daily_event';

	/**
	 * Register cron hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_sync' ) );
	}

	/**
	 * Schedule daily cron event if not already scheduled.
	 */
	public static function schedule_event() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$recurrence = get_option( 'shaheen_sync_cron_recurrence', 'daily' );
			wp_schedule_event( time() + 3600, $recurrence, self::CRON_HOOK );
			Shaheen_Logger::info( 'Scheduled recurring WP-Cron event (' . $recurrence . ').' );
		}
	}

	/**
	 * Clear scheduled cron event.
	 */
	public static function clear_event() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			Shaheen_Logger::info( 'Cleared scheduled WP-Cron event.' );
		}
	}

	/**
	 * Scheduled sync runner with strict execution time & page limits.
	 */
	public static function run_scheduled_sync() {
		Shaheen_Logger::info( 'WP-Cron triggered scheduled crawl and dry-run analysis.' );

		// Step 1: Sitemap discovery on approved and enabled sources
		$discovery = Shaheen_Sync_Engine::run_sitemap_discovery();
		if ( ! $discovery['success'] ) {
			Shaheen_Logger::error( 'Cron sitemap discovery halted: ' . $discovery['message'] );
			return;
		}

		// Step 2: Process batch with time budget (25s) and maximum pages limit
		$max_pages   = absint( get_option( 'shaheen_sync_max_pages_per_sync', 100 ) );
		$batch_size  = absint( get_option( 'shaheen_sync_batch_size', 10 ) );
		$start_time  = time();
		$total_run   = 0;

		while ( $total_run < $max_pages ) {
			// Limit execution to 25s per cron run to avoid PHP execution timeouts
			if ( ( time() - $start_time ) > 25 ) {
				Shaheen_Logger::info( "Cron batch run paused after {$total_run} pages to avoid script timeout." );
				break;
			}

			$result = Shaheen_Sync_Engine::process_batch( $batch_size, $max_pages );
			if ( ! $result['success'] || $result['done'] ) {
				break;
			}

			$total_run += $result['processed'];
		}

		Shaheen_Logger::info( "Cron pass completed. Analyzed {$total_run} pages." );
	}
}
