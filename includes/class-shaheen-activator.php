<?php
/**
 * Fired during plugin activation
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Activator {

	/**
	 * Run activation logic: database migrations, default settings, seed initial source, schedule cron.
	 */
	public static function activate() {
		// 1. Create or upgrade database tables
		Shaheen_DB::create_tables();

		// 2. Seed default source: Shaheen Academy Dammam / Al-Khobar
		Shaheen_Source_Manager::seed_default_source();

		// 3. Set default options if not already configured
		$default_sensitive = implode( "\n", Shaheen_Content_Extractor::get_default_sensitive_keywords() );
		$default_excluded  = implode( "\n", Shaheen_URL_Filter::get_default_excluded_patterns() );

		add_option( 'shaheen_sync_mode', 'dry_run' );
		add_option( 'shaheen_sync_max_pages_per_sync', 100 );
		add_option( 'shaheen_sync_batch_size', 10 );
		add_option( 'shaheen_sync_timeout', 15 );
		add_option( 'shaheen_sync_retry_count', 3 );
		add_option( 'shaheen_sync_retry_delay', 1 );
		add_option( 'shaheen_sync_max_sitemap_size', 5242880 ); // 5MB
		add_option( 'shaheen_sync_max_child_sitemaps', 20 );
		add_option( 'shaheen_sync_max_urls_per_sitemap', 500 );
		add_option( 'shaheen_sync_max_page_size', 3145728 ); // 3MB
		add_option( 'shaheen_sync_cron_recurrence', 'daily' );
		add_option( 'shaheen_sync_user_agent', 'ShaheenCentralMXChatSync/1.0 (+https://shaheengroup.org)' );
		add_option( 'shaheen_sync_sensitive_keywords', $default_sensitive );
		add_option( 'shaheen_sync_excluded_patterns', $default_excluded );
		add_option( 'shaheen_sync_last_error', 'None' );

		// 4. Schedule daily WP-Cron
		Shaheen_Cron::schedule_event();

		Shaheen_Logger::info( 'Shaheen Central MXChat Sync activated successfully (Phase 1).' );
	}
}
