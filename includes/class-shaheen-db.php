<?php
/**
 * Database Management & Schema Migrations for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_DB {

	/**
	 * Table name for source websites.
	 *
	 * @return string
	 */
	public static function get_sources_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_sync_sources';
	}

	/**
	 * Table name for synchronization records.
	 *
	 * @return string
	 */
	public static function get_records_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_sync_records';
	}

	/**
	 * Table name for sync runs.
	 *
	 * @return string
	 */
	public static function get_runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_sync_runs';
	}

	/**
	 * Table name for knowledge improvement queue.
	 *
	 * @return string
	 */
	public static function get_knowledge_table() {
		global $wpdb;
		return $wpdb->prefix . 'shaheen_knowledge_queue';
	}

	/**
	 * Table name for activity logs.
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
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// 1. Source Websites Table
		$sources_table = self::get_sources_table();
		$sql_sources   = "CREATE TABLE {$sources_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution varchar(255) NOT NULL,
			domain varchar(255) NOT NULL,
			sitemap_url varchar(500) NOT NULL,
			category varchar(100) DEFAULT 'Education' NOT NULL,
			language varchar(50) DEFAULT 'English' NOT NULL,
			crawl_frequency varchar(50) DEFAULT 'daily' NOT NULL,
			status varchar(50) DEFAULT 'approved' NOT NULL,
			is_enabled tinyint(1) DEFAULT 1 NOT NULL,
			notes text DEFAULT NULL,
			last_crawled_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY domain (domain(191)),
			KEY status (status)
		) {$charset_collate};";

		// 2. Synchronization Records Table
		$records_table = self::get_records_table();
		$sql_records   = "CREATE TABLE {$records_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			institution varchar(255) NOT NULL,
			source_domain varchar(255) NOT NULL,
			canonical_url varchar(500) NOT NULL,
			record_key varchar(64) NOT NULL,
			source_sitemap varchar(500) DEFAULT '' NOT NULL,
			accepted_content_hash varchar(64) DEFAULT '' NOT NULL,
			candidate_content_hash varchar(64) DEFAULT '' NOT NULL,
			previous_hash varchar(64) DEFAULT '' NOT NULL,
			accepted_content longtext DEFAULT NULL,
			candidate_content longtext DEFAULT NULL,
			mxchat_response_id varchar(100) DEFAULT NULL,
			content_type varchar(50) DEFAULT 'page' NOT NULL,
			language varchar(50) DEFAULT 'English' NOT NULL,
			title text DEFAULT NULL,
			flag_reasons text DEFAULT NULL,
			skip_reason varchar(255) DEFAULT NULL,
			last_modified datetime DEFAULT NULL,
			last_checked datetime DEFAULT NULL,
			last_sent datetime DEFAULT NULL,
			accepted_at datetime DEFAULT NULL,
			candidate_checked_at datetime DEFAULT NULL,
			status varchar(50) DEFAULT 'discovered' NOT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY record_key (record_key),
			KEY canonical_url (canonical_url(191)),
			KEY accepted_content_hash (accepted_content_hash),
			KEY candidate_content_hash (candidate_content_hash),
			KEY status (status),
			KEY source_domain (source_domain(191))
		) {$charset_collate};";

		// 3. Sync Runs Table
		$runs_table = self::get_runs_table();
		$sql_runs   = "CREATE TABLE {$runs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(64) NOT NULL,
			source_id bigint(20) unsigned DEFAULT NULL,
			run_type varchar(50) DEFAULT 'manual' NOT NULL,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			status varchar(50) DEFAULT 'running' NOT NULL,
			maximum_pages int(11) DEFAULT 100 NOT NULL,
			processed_pages int(11) DEFAULT 0 NOT NULL,
			discovered_pages int(11) DEFAULT 0 NOT NULL,
			skipped_pages int(11) DEFAULT 0 NOT NULL,
			error_count int(11) DEFAULT 0 NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY status (status)
		) {$charset_collate};";

		// 4. Knowledge Improvement Queue Table (Phase 1 Architecture)
		$knowledge_table = self::get_knowledge_table();
		$sql_knowledge   = "CREATE TABLE {$knowledge_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_ref varchar(100) DEFAULT NULL,
			original_question text NOT NULL,
			redacted_question text NOT NULL,
			bot_answer text DEFAULT NULL,
			detected_institution varchar(255) DEFAULT NULL,
			retrieval_context_available tinyint(1) DEFAULT 0 NOT NULL,
			fallback_detected tinyint(1) DEFAULT 0 NOT NULL,
			similar_question_count int(11) DEFAULT 1 NOT NULL,
			source_url varchar(500) DEFAULT NULL,
			proposed_official_answer longtext DEFAULT NULL,
			proposed_content_type varchar(50) DEFAULT 'FAQ' NOT NULL,
			review_status varchar(50) DEFAULT 'new' NOT NULL,
			reviewer_user_id bigint(20) unsigned DEFAULT NULL,
			reviewed_at datetime DEFAULT NULL,
			rejection_reason text DEFAULT NULL,
			publish_status varchar(50) DEFAULT 'draft' NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY review_status (review_status),
			KEY publish_status (publish_status)
		) {$charset_collate};";

		// 5. Activity Logs Table
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

		dbDelta( $sql_sources );
		dbDelta( $sql_records );
		dbDelta( $sql_runs );
		dbDelta( $sql_knowledge );
		dbDelta( $sql_logs );

		// Run custom schema data migration if upgrading from earlier schema
		self::migrate_schema_if_needed();
	}

	/**
	 * Perform safe migration from earlier Phase 1 schema without data loss.
	 */
	public static function migrate_schema_if_needed() {
		global $wpdb;
		$records_table = self::get_records_table();
		$sources_table = self::get_sources_table();

		// Check if records table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $records_table ) );
		if ( ! $table_exists ) {
			return;
		}

		// Inspect existing columns in records table
		$columns = $wpdb->get_col( "DESCRIBE {$records_table}" );
		if ( empty( $columns ) ) {
			return;
		}

		// 1. If old 'content_hash' exists and 'candidate_content_hash' is empty, migrate values
		if ( in_array( 'content_hash', $columns, true ) && in_array( 'candidate_content_hash', $columns, true ) ) {
			$wpdb->query(
				"UPDATE {$records_table} SET candidate_content_hash = content_hash WHERE (candidate_content_hash = '' OR candidate_content_hash IS NULL) AND content_hash != ''"
			);
		}

		// 2. If old 'cleaned_content' exists and 'candidate_content' is empty, migrate values
		if ( in_array( 'cleaned_content', $columns, true ) && in_array( 'candidate_content', $columns, true ) ) {
			$wpdb->query(
				"UPDATE {$records_table} SET candidate_content = cleaned_content WHERE (candidate_content = '' OR candidate_content IS NULL) AND cleaned_content IS NOT NULL"
			);
		}

		// 3. Deduplicate record_key if duplicates exist before enforcing uniqueness
		$duplicates = $wpdb->get_results(
			"SELECT record_key, COUNT(*) as cnt FROM {$records_table} GROUP BY record_key HAVING cnt > 1"
		);

		if ( ! empty( $duplicates ) ) {
			foreach ( $duplicates as $dup ) {
				$dup_key = $dup->record_key;
				// Keep the latest ID, delete older duplicates
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM {$records_table} WHERE record_key = %s ORDER BY id DESC",
						$dup_key
					)
				);
				if ( count( $ids ) > 1 ) {
					$keep_id = array_shift( $ids );
					$ids_str = implode( ',', array_map( 'absint', $ids ) );
					$wpdb->query( "DELETE FROM {$records_table} WHERE id IN ({$ids_str})" );
				}
			}
		}

		// Add UNIQUE index on record_key if not already present
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$records_table} WHERE Key_name = 'record_key'" );
		$is_unique = false;
		foreach ( $indexes as $idx ) {
			if ( 0 === (int) $idx->Non_unique ) {
				$is_unique = true;
				break;
			}
		}

		if ( ! $is_unique ) {
			@$wpdb->query( "ALTER TABLE {$records_table} DROP INDEX record_key" );
			@$wpdb->query( "ALTER TABLE {$records_table} ADD UNIQUE KEY record_key (record_key)" );
		}

		// Update sources table columns if missing
		$src_columns = $wpdb->get_col( "DESCRIBE {$sources_table}" );
		if ( ! in_array( 'language', $src_columns, true ) ) {
			@$wpdb->query( "ALTER TABLE {$sources_table} ADD COLUMN language varchar(50) DEFAULT 'English' NOT NULL AFTER category" );
		}
		if ( ! in_array( 'crawl_frequency', $src_columns, true ) ) {
			@$wpdb->query( "ALTER TABLE {$sources_table} ADD COLUMN crawl_frequency varchar(50) DEFAULT 'daily' NOT NULL AFTER language" );
		}
		if ( ! in_array( 'notes', $src_columns, true ) ) {
			@$wpdb->query( "ALTER TABLE {$sources_table} ADD COLUMN notes text DEFAULT NULL AFTER is_enabled" );
		}
	}

	/**
	 * Get aggregated statistics for the administrative dashboard.
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

		$approved_sources = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sources_table} WHERE status = %s",
				'approved'
			)
		);

		$sources_waiting_validation = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sources_table} WHERE status = %s OR status = %s",
				'draft',
				'validating'
			)
		);

		$total_discovered = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$records_table}"
		);

		$skipped = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$records_table} WHERE status = %s OR status = %s",
				'skipped',
				'rejected'
			)
		);

		$new_records = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
				'new'
			)
		);

		$unchanged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
				'unchanged'
			)
		);

		$pending_review = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$records_table} WHERE status = %s OR status = %s",
				'pending_review',
				'needs_review'
			)
		);

		$sensitive_pages = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$records_table} WHERE flag_reasons LIKE '%Sensitive%'"
		);

		$errors = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$records_table} WHERE status = %s",
				'error'
			)
		);

		$last_sync = get_option( 'shaheen_sync_last_run', null );
		$next_sync = wp_next_scheduled( 'shaheen_sync_daily_event' );
		$is_running = (bool) get_transient( 'shaheen_sync_lock' );
		$last_error = get_option( 'shaheen_sync_last_error', 'None' );
		$run_progress = get_option( 'shaheen_sync_progress', array( 'message' => 'Idle', 'percent' => 0 ) );

		return array(
			'mode'                       => 'Dry Run',
			'enabled_sources'            => $enabled_sources,
			'approved_sources'           => $approved_sources,
			'sources_waiting_validation' => $sources_waiting_validation,
			'total_discovered'           => $total_discovered,
			'skipped'                    => $skipped,
			'new_records'                => $new_records,
			'unchanged'                  => $unchanged,
			'pending_review'             => $pending_review,
			'sensitive_pages'            => $sensitive_pages,
			'errors'                     => $errors,
			'last_sync'                  => $last_sync ? get_date_from_gmt( date( 'Y-m-d H:i:s', $last_sync ), 'Y-m-d H:i:s' ) : 'Never',
			'next_sync'                  => $next_sync ? get_date_from_gmt( date( 'Y-m-d H:i:s', $next_sync ), 'Y-m-d H:i:s' ) : 'Not scheduled',
			'is_running'                 => $is_running,
			'current_progress'           => $run_progress,
			'last_error'                 => $last_error,
		);
	}
}
