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
			// Schedule to run daily at 02:00 AM local time or next hour
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
	 * Daily scheduled sync runner.
	 */
	public static function run_scheduled_sync() {
		Shaheen_Logger::info( 'WP-Cron triggered scheduled crawl and dry-run analysis.' );

		// Step 1: Crawl enabled sources sitemaps
		$discovery = Shaheen_Sync_Engine::run_sitemap_discovery();
		if ( ! $discovery['success'] ) {
			Shaheen_Logger::error( 'Cron sitemap discovery failed: ' . $discovery['message'] );
			return;
		}

		// Step 2: Process batch of discovered pages within execution time budget
		$max_pages = absint( get_option( 'shaheen_sync_max_pages_per_sync', 100 ) );
		$batch_size = absint( get_option( 'shaheen_sync_batch_size', 10 ) );
		$processed_total = 0;
		$start_time = time();

		while ( $processed_total < $max_pages ) {
			// Limit execution to 25 seconds per cron run to avoid PHP execution limits
			if ( ( time() - $start_time ) > 25 ) {
				Shaheen_Logger::info( "Cron batch execution paused after {$processed_total} pages to avoid timeout." );
				break;
			}

			$result = Shaheen_Sync_Engine::process_batch( $batch_size );
			if ( ! $result['success'] || $result['done'] ) {
				break;
			}

			$processed_total += $result['processed'];
		}

		Shaheen_Logger::info( "Cron job finished batch pass. Analyzed {$processed_total} pages in dry-run mode." );
	}
}
