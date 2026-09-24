<?php
/**
 * Database Management Class for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_DB {

	/**
	 * Get table name for sync records.
	 *
	 * @return string
	 */
	public static function get_records_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_sync_records';
	}

	/**
	 * Get table name for source websites.
	 *
	 * @return string
	 */
	public static function get_sources_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_sync_sources';
	}

	/**
	 * Get table name for activity logs.
	 *
	 * @return string
	 */
	public static function get_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_sync_logs';
	}

	/**
	 * Create or upgrade custom database tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$records_table = self::get_records_table();
		$sql_records   = "CREATE TABLE {$records_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution varchar(255) NOT NULL,
			source_domain varchar(255) NOT NULL,
			canonical_url varchar(500) NOT NULL,
			record_key varchar(64) NOT NULL,
			source_sitemap varchar(500) DEFAULT '' NOT NULL,
			content_hash varchar(64) DEFAULT '' NOT NULL,
			previous_hash varchar(64) DEFAULT '' NOT NULL,
			mxchat_response_id varchar(100) DEFAULT NULL,
			content_type varchar(50) DEFAULT 'page' NOT NULL,
			title text DEFAULT NULL,
			cleaned_content longtext DEFAULT NULL,
			flag_reasons text DEFAULT NULL,
			skip_reason varchar(255) DEFAULT NULL,
			last_modified datetime DEFAULT NULL,
			last_checked datetime DEFAULT NULL,
			last_sent datetime DEFAULT NULL,
			status varchar(50) DEFAULT 'discovered' NOT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY canonical_url (canonical_url(191)),
			KEY record_key (record_key),
			KEY content_hash (content_hash),
			KEY status (status),
			KEY source_domain (source_domain(191))
		) {$charset_collate};";

		$sources_table = self::get_sources_table();
		$sql_sources   = "CREATE TABLE {$sources_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution varchar(255) NOT NULL,
			domain varchar(255) NOT NULL,
			sitemap_url varchar(500) NOT NULL,
			category varchar(100) DEFAULT 'Education' NOT NULL,
			is_enabled tinyint(1) DEFAULT 1 NOT NULL,
			last_crawled_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY domain (domain(191))
		) {$charset_collate};";

		$logs_table = self::get_logs_table();
		$sql_logs   = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) DEFAULT 'info' NOT NULL,
			message text NOT NULL,
			context text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_records );
		dbDelta( $sql_sources );
		dbDelta( $sql_logs );
	}

	/**
	 * Get aggregated stats for the dashboard.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;
		$records_table = self::get_records_table();
		$sources_table = self::get_sources_table();

		$enabled_sources = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$sources_table} WHERE is_enabled = 1"
		);

		$total_discovered = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$records_table}"
		);

		$skipped = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
			'skipped'
		) );

		$new_records = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
			'new'
		) );

		$unchanged = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
			'unchanged'
		) );

		$pending_review = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$records_table} WHERE status = %s OR status = %s",
			'pending_review',
			'needs_review'
		) );

		$imported = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
			'imported'
		) );

		$errors = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
			'error'
		) );

		$last_sync = get_option( 'shaheen_sync_last_run', null );
		$next_sync = wp_next_scheduled( 'shaheen_sync_daily_event' );
		$is_running = (bool) get_transient( 'shaheen_sync_lock' );
		$last_error = get_option( 'shaheen_sync_last_error', 'None' );

		return array(
			'mode'             => 'Dry Run',
			'enabled_sources'  => $enabled_sources,
			'total_discovered' => $total_discovered,
			'skipped'          => $skipped,
			'new_records'      => $new_records,
			'unchanged'        => $unchanged,
			'pending_review'   => $pending_review,
			'imported'         => $imported,
			'errors'           => $errors,
			'last_sync'        => $last_sync ? get_date_from_gmt( date( 'Y-m-d H:i:s', $last_sync ), 'Y-m-d H:i:s' ) : 'Never',
			'next_sync'        => $next_sync ? get_date_from_gmt( date( 'Y-m-d H:i:s', $next_sync ), 'Y-m-d H:i:s' ) : 'Not scheduled',
			'is_running'       => $is_running,
			'last_error'       => $last_error,
		);
	}
}
