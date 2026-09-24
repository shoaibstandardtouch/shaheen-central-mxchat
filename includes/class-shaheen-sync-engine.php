<?php
/**
 * Core Synchronization and Batch Processing Engine for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Sync_Engine {

	const LOCK_TRANSIENT = 'shaheen_sync_lock';
	const PROGRESS_OPTION = 'shaheen_sync_progress';

	/**
	 * Build standardized source context header.
	 *
	 * @param string $institution
	 * @param string $domain
	 * @param string $canonical_url
	 * @param string $content_type
	 * @param string $last_modified
	 * @return string
	 */
	public static function format_source_context( $institution, $domain, $canonical_url, $content_type, $last_modified = '' ) {
		$context  = "Institution: " . trim( $institution ) . "\n";
		$context .= "Source website: " . trim( $domain ) . "\n";
		$context .= "Original URL: " . trim( $canonical_url ) . "\n";
		$context .= "Content type: " . trim( $content_type ) . "\n";
		$context .= "Last modified: " . ( ! empty( $last_modified ) ? trim( $last_modified ) : 'Unknown' ) . "\n\n";

		return $context;
	}

	/**
	 * Acquire lock to prevent overlapping sync operations.
	 *
	 * @param int $duration_seconds
	 * @return bool
	 */
	public static function acquire_lock( $duration_seconds = 900 ) {
		$existing = get_transient( self::LOCK_TRANSIENT );
		if ( $existing ) {
			// Check if stale (older than duration_seconds)
			$lock_time = absint( $existing );
			if ( time() - $lock_time > $duration_seconds ) {
				Shaheen_Logger::warning( 'Releasing stale synchronization lock.' );
				self::release_lock();
			} else {
				return false; // Still active
			}
		}

		set_transient( self::LOCK_TRANSIENT, time(), $duration_seconds );
		return true;
	}

	/**
	 * Release sync lock.
	 */
	public static function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Run crawl and discovery for all enabled sources or a specific source.
	 *
	 * @param int $source_id Optional single source ID.
	 * @return array
	 */
	public static function run_sitemap_discovery( $source_id = 0 ) {
		if ( ! self::acquire_lock() ) {
			return array(
				'success' => false,
				'message' => __( 'A sync operation is already running. Please wait or clear stale lock.', 'shaheen-central-mxchat-sync' ),
			);
		}

		Shaheen_Logger::info( 'Started sitemap discovery run.' );

		if ( $source_id > 0 ) {
			$sources = array( Shaheen_Source_Manager::get_source( $source_id ) );
		} else {
			$sources = Shaheen_Source_Manager::get_enabled_sources();
		}

		$total_discovered = 0;
		$total_skipped    = 0;

		foreach ( $sources as $source ) {
			if ( empty( $source ) || (int) $source['is_enabled'] !== 1 ) {
				continue;
			}

			$crawl_res = Shaheen_Crawler::crawl_source_sitemap( $source );
			if ( ! empty( $crawl_res['success'] ) ) {
				$total_discovered += $crawl_res['discovered'];
				$total_skipped    += $crawl_res['skipped'];
			}
		}

		update_option( 'shaheen_sync_last_run', time() );
		self::release_lock();

		return array(
			'success'    => true,
			'discovered' => $total_discovered,
			'skipped'    => $total_skipped,
			'message'    => sprintf(
				/* translators: 1: discovered count, 2: skipped count */
				__( 'Sitemap crawl complete. Discovered: %1$d, Skipped: %2$d', 'shaheen-central-mxchat-sync' ),
				$total_discovered,
				$total_skipped
			),
		);
	}

	/**
	 * Process a batch of discovered or pending pages.
	 *
	 * @param int $batch_size
	 * @return array
	 */
	public static function process_batch( $batch_size = 0 ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		if ( ! self::acquire_lock() ) {
			return array(
				'success' => false,
				'message' => __( 'Sync is currently locked by an active process.', 'shaheen-central-mxchat-sync' ),
				'done'    => false,
			);
		}

		if ( $batch_size <= 0 ) {
			$batch_size = absint( get_option( 'shaheen_sync_batch_size', 10 ) );
		}

		// Fetch records that need fetching: status = 'discovered' or status = 'needs_review' or newly queued
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$records_table} WHERE status = %s OR status = %s ORDER BY id ASC LIMIT %d",
				'discovered',
				'needs_review',
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $records ) ) {
			self::release_lock();
			return array(
				'success'   => true,
				'processed' => 0,
				'remaining' => 0,
				'done'      => true,
				'message'   => __( 'All discovered pages have been analyzed.', 'shaheen-central-mxchat-sync' ),
			);
		}

		$processed = 0;

		foreach ( $records as $record ) {
			self::process_single_record( $record );
			$processed++;
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$records_table} WHERE status = %s OR status = %s",
				'discovered',
				'needs_review'
			)
		);

		self::release_lock();

		return array(
			'success'   => true,
			'processed' => $processed,
			'remaining' => $remaining,
			'done'      => ( $remaining === 0 ),
			'message'   => sprintf(
				/* translators: 1: processed in batch, 2: remaining items */
				__( 'Processed %1$d pages in batch. %2$d remaining.', 'shaheen-central-mxchat-sync' ),
				$processed,
				$remaining
			),
		);
	}

	/**
	 * Process and analyze a single record.
	 *
	 * @param array $record
	 * @return bool
	 */
	public static function process_single_record( $record ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$url    = $record['canonical_url'];
		$domain = $record['source_domain'];

		// 1. Fetch page safely
		$fetch_result = Shaheen_Page_Fetcher::fetch( $url, $domain );

		if ( ! $fetch_result['success'] ) {
			$wpdb->update(
				$records_table,
				array(
					'status'        => $fetch_result['status'],
					'error_message' => sanitize_text_field( $fetch_result['error'] ),
					'last_checked'  => current_time( 'mysql' ),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $record['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			Shaheen_Logger::error( "Error fetching {$url}: {$fetch_result['error']}" );
			return false;
		}

		$html = $fetch_result['body'];

		// 2. Extract and clean content
		$extracted = Shaheen_Content_Extractor::extract( $html, $url );

		// Check if canonical URL was updated by <link rel="canonical">
		$canonical_url = Shaheen_URL_Filter::normalize_url( $url, $extracted['canonical_url'] );

		// 3. Classify content
		$content_type = Shaheen_Classifier::classify(
			$canonical_url,
			$extracted['title'],
			$extracted['headings'],
			$extracted['faq_items'],
			$record['source_sitemap']
		);

		// 4. Build source context header and final content
		$source_context = self::format_source_context(
			$record['institution'],
			$record['source_domain'],
			$canonical_url,
			$content_type,
			$extracted['last_modified'] ? $extracted['last_modified'] : $record['last_modified']
		);

		// Cleaned content with source context
		$full_cleaned_content = $source_context . $extracted['cleaned_text'];

		// 5. Generate SHA-256 Hash of cleaned text + source context
		$new_hash = hash( 'sha256', trim( $full_cleaned_content ) );
		$old_hash = ! empty( $record['content_hash'] ) ? $record['content_hash'] : '';

		// 6. Determine Record Status (Phase 1 Rules)
		$status = 'new';
		$flag_reasons = array();

		// Check low quality or empty extraction
		if ( $extracted['is_empty_or_low_quality'] ) {
			$status = 'needs_review';
			$flag_reasons[] = 'Insufficient main content detected (< 30 words)';
		}

		// Check sensitive content flags
		if ( ! empty( $extracted['sensitive_flags'] ) ) {
			$status = 'pending_review';
			$flag_reasons[] = 'Sensitive terms detected: ' . implode( ', ', $extracted['sensitive_flags'] );
		}

		// Compare hashes if previously recorded
		if ( ! empty( $old_hash ) ) {
			if ( $old_hash === $new_hash ) {
				$status = 'unchanged';
			} else {
				$status = 'pending_review';
				$flag_reasons[] = 'Content has changed since previous crawl';
			}
		}

		// 7. Update database record
		$update_data = array(
			'canonical_url'   => $canonical_url,
			'content_hash'    => $new_hash,
			'previous_hash'   => ( $old_hash && $old_hash !== $new_hash ) ? $old_hash : $record['previous_hash'],
			'content_type'    => $content_type,
			'title'           => sanitize_text_field( $extracted['title'] ),
			'cleaned_content' => $full_cleaned_content,
			'flag_reasons'    => ! empty( $flag_reasons ) ? implode( '; ', $flag_reasons ) : null,
			'last_modified'   => $extracted['last_modified'] ? $extracted['last_modified'] : $record['last_modified'],
			'last_checked'    => current_time( 'mysql' ),
			'status'          => $status,
			'error_message'   => null,
			'updated_at'      => current_time( 'mysql' ),
		);

		$wpdb->update(
			$records_table,
			$update_data,
			array( 'id' => $record['id'] ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		Shaheen_Logger::info( "Processed URL: {$canonical_url} [{$status}]" );
		return true;
	}

	/**
	 * Retry a specific URL by record ID.
	 *
	 * @param int $record_id
	 * @return array
	 */
	public static function retry_record( $record_id ) {
		global $wpdb;
		$record_id = absint( $record_id );
		$records_table = Shaheen_DB::get_records_table();

		$record = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$records_table} WHERE id = %d", $record_id ),
			ARRAY_A
		);

		if ( ! $record ) {
			return array(
				'success' => false,
				'message' => __( 'Record not found.', 'shaheen-central-mxchat-sync' ),
			);
		}

		// Re-evaluate URL skip filters first
		$skip_reason = Shaheen_URL_Filter::should_skip_url( $record['canonical_url'], $record['source_domain'] );
		if ( false !== $skip_reason ) {
			$wpdb->update(
				$records_table,
				array(
					'status'        => 'skipped',
					'skip_reason'   => $skip_reason,
					'last_checked'  => current_time( 'mysql' ),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $record_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return array(
				'success' => true,
				'status'  => 'skipped',
				'message' => __( 'URL re-checked and marked as skipped: ', 'shaheen-central-mxchat-sync' ) . $skip_reason,
			);
		}

		$success = self::process_single_record( $record );
		$updated_record = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$records_table} WHERE id = %d", $record_id ),
			ARRAY_A
		);

		return array(
			'success' => $success,
			'status'  => $updated_record ? $updated_record['status'] : 'unknown',
			'message' => $success ? __( 'Record successfully re-processed.', 'shaheen-central-mxchat-sync' ) : __( 'Record processing encountered an error.', 'shaheen-central-mxchat-sync' ),
		);
	}
}
