<?php
/**
 * Core Synchronization, Change Detection & Batch Processing Engine
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Sync_Engine {

	const LOCK_TRANSIENT = 'shaheen_sync_lock';

	/**
	 * Build standardized source context header.
	 *
	 * @param string $institution
	 * @param string $domain
	 * @param string $canonical_url
	 * @param string $content_type
	 * @param string $last_modified
	 * @param string $language
	 * @return string
	 */
	public static function format_source_context( $institution, $domain, $canonical_url, $content_type, $last_modified = '', $language = 'English' ) {
		$context  = "Institution: " . trim( (string) $institution ) . "\n";
		$context .= "Source website: " . trim( (string) $domain ) . "\n";
		$context .= "Original URL: " . trim( (string) $canonical_url ) . "\n";
		$context .= "Content type: " . trim( (string) $content_type ) . "\n";
		$context .= "Last modified: " . ( ! empty( $last_modified ) ? trim( (string) $last_modified ) : 'Unknown' ) . "\n";
		$context .= "Language: " . ( ! empty( $language ) ? trim( (string) $language ) : 'English' ) . "\n\n";

		return $context;
	}

	/**
	 * Acquire lock to prevent overlapping sync runs.
	 *
	 * @param int $duration_seconds
	 * @return bool
	 */
	public static function acquire_lock( $duration_seconds = 900 ) {
		$existing = get_transient( self::LOCK_TRANSIENT );
		if ( $existing ) {
			$lock_time = absint( $existing );
			if ( time() - $lock_time > $duration_seconds ) {
				Shaheen_Logger::warning( 'Releasing stale synchronization lock.' );
				self::release_lock();
			} else {
				return false;
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
	 * Run sitemap discovery for all approved/enabled sources or a single source.
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

		try {
			Shaheen_Logger::info( 'Started sitemap discovery run.' );

			if ( $source_id > 0 ) {
				$sources = array( Shaheen_Source_Manager::get_source( $source_id ) );
			} else {
				$sources = Shaheen_Source_Manager::get_sync_eligible_sources();
			}

			$total_discovered = 0;
			$total_queued     = 0;
			$total_skipped    = 0;
			$total_missing    = 0;

			foreach ( $sources as $source ) {
				if ( empty( $source ) || (int) $source['is_enabled'] !== 1 || $source['status'] !== 'approved' ) {
					continue;
				}

				$crawl_res = Shaheen_Crawler::crawl_source_sitemap( $source );
				if ( ! empty( $crawl_res['success'] ) ) {
					$total_discovered += $crawl_res['discovered'];
					$total_queued     += $crawl_res['queued'];
					$total_skipped    += $crawl_res['skipped'];
					$total_missing    += $crawl_res['missing'];
				}
			}

			update_option( 'shaheen_sync_last_run', time() );

			return array(
				'success'    => true,
				'discovered' => $total_discovered,
				'queued'     => $total_queued,
				'skipped'    => $total_skipped,
				'missing'    => $total_missing,
				'message'    => sprintf(
					/* translators: 1: new discovered, 2: queued check, 3: skipped */
					__( 'Sitemap crawl complete. Discovered: %1$d, Re-check queued: %2$d, Skipped: %3$d', 'shaheen-central-mxchat-sync' ),
					$total_discovered,
					$total_queued,
					$total_skipped
				),
			);
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Process a controlled batch of discovered or queued_check records.
	 * Enforces maximum pages per run and prevents repeated loops.
	 *
	 * @param int $batch_size
	 * @param int $max_pages
	 * @return array
	 */
	public static function process_batch( $batch_size = 0, $max_pages = 0 ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();
		$runs_table    = Shaheen_DB::get_runs_table();

		if ( ! self::acquire_lock() ) {
			return array(
				'success' => false,
				'message' => __( 'Sync is currently locked by another active process.', 'shaheen-central-mxchat-sync' ),
				'done'    => false,
			);
		}

		try {
			if ( $batch_size <= 0 ) {
				$batch_size = absint( get_option( 'shaheen_sync_batch_size', 10 ) );
			}
			if ( $max_pages <= 0 ) {
				$max_pages = absint( get_option( 'shaheen_sync_max_pages_per_sync', 100 ) );
			}

			// Track active run progress
			$active_run = get_option( 'shaheen_sync_current_run', null );
			if ( ! $active_run ) {
				$run_id = 'run_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );
				$active_run = array(
					'run_id'          => $run_id,
					'processed_pages' => 0,
					'maximum_pages'   => $max_pages,
					'started_at'      => current_time( 'mysql' ),
				);
				$wpdb->insert(
					$runs_table,
					array(
						'run_id'          => $run_id,
						'run_type'        => 'manual',
						'started_at'      => current_time( 'mysql' ),
						'status'          => 'running',
						'maximum_pages'   => $max_pages,
						'processed_pages' => 0,
					),
					array( '%s', '%s', '%s', '%s', '%d', '%d' )
				);
			}

			// Check if maximum run limit is already reached
			if ( $active_run['processed_pages'] >= $active_run['maximum_pages'] ) {
				$wpdb->update(
					$runs_table,
					array(
						'status'       => 'limit_reached',
						'completed_at' => current_time( 'mysql' ),
					),
					array( 'run_id' => $active_run['run_id'] ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				delete_option( 'shaheen_sync_current_run' );

				return array(
					'success'       => true,
					'processed'     => 0,
					'remaining'     => 0,
					'done'          => true,
					'limit_reached' => true,
					'message'       => __( 'Maximum pages for this run reached. Remaining pages will be processed in a future run.', 'shaheen-central-mxchat-sync' ),
				);
			}

			// Select ONLY 'discovered' and 'queued_check' records!
			// Never automatically select needs_review, pending_review, unchanged, skipped, rejected, imported, error!
			$records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$records_table} WHERE status = %s OR status = %s ORDER BY id ASC LIMIT %d",
					'discovered',
					'queued_check',
					$batch_size
				),
				ARRAY_A
			);

			if ( empty( $records ) ) {
				$wpdb->update(
					$runs_table,
					array(
						'status'       => 'completed',
						'completed_at' => current_time( 'mysql' ),
					),
					array( 'run_id' => $active_run['run_id'] ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				delete_option( 'shaheen_sync_current_run' );

				return array(
					'success'       => true,
					'processed'     => 0,
					'remaining'     => 0,
					'done'          => true,
					'limit_reached' => false,
					'message'       => __( 'All discovered pages have been analyzed.', 'shaheen-central-mxchat-sync' ),
				);
			}

			$processed_in_batch = 0;
			foreach ( $records as $record ) {
				self::process_single_record( $record );
				$processed_in_batch++;
				$active_run['processed_pages']++;

				if ( $active_run['processed_pages'] >= $active_run['maximum_pages'] ) {
					break;
				}
			}

			// Update persistent run record
			$wpdb->update(
				$runs_table,
				array( 'processed_pages' => $active_run['processed_pages'] ),
				array( 'run_id' => $active_run['run_id'] ),
				array( '%d' ),
				array( '%s' )
			);

			$limit_reached = ( $active_run['processed_pages'] >= $active_run['maximum_pages'] );
			if ( $limit_reached ) {
				$wpdb->update(
					$runs_table,
					array(
						'status'       => 'limit_reached',
						'completed_at' => current_time( 'mysql' ),
					),
					array( 'run_id' => $active_run['run_id'] ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				delete_option( 'shaheen_sync_current_run' );
			} else {
				update_option( 'shaheen_sync_current_run', $active_run );
			}

			// Count remaining eligible records
			$remaining = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$records_table} WHERE status = %s OR status = %s",
					'discovered',
					'queued_check'
				)
			);

			$done = ( $remaining === 0 || $limit_reached );

			$message = $limit_reached
				? __( 'Maximum pages for this run reached. Remaining pages will be processed in a future run.', 'shaheen-central-mxchat-sync' )
				: sprintf(
					/* translators: 1: batch count, 2: total run count, 3: remaining */
					__( 'Analyzed %1$d pages (%2$d total this run). %3$d remaining.', 'shaheen-central-mxchat-sync' ),
					$processed_in_batch,
					$active_run['processed_pages'],
					$remaining
				);

			return array(
				'success'       => true,
				'processed'     => $processed_in_batch,
				'total_run'     => $active_run['processed_pages'],
				'remaining'     => $remaining,
				'done'          => $done,
				'limit_reached' => $limit_reached,
				'message'       => $message,
			);
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Process and analyze a single record using Continuous Website Change Detection.
	 *
	 * @param array $record
	 * @return bool
	 */
	public static function process_single_record( $record ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$url    = $record['canonical_url'];
		$domain = $record['source_domain'];

		// 1. Safe fetch with controlled redirects and anti-SSRF
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

		// Validate canonical tag
		$validated_canonical = Shaheen_URL_Filter::validate_canonical_tag( $extracted['canonical_url'], $domain );
		$canonical_url = $validated_canonical ? Shaheen_URL_Filter::normalize_url( $validated_canonical, '', $domain ) : $url;

		// Canonical collision check: if canonical URL resolves to another existing record, handle safely
		$new_record_key = Shaheen_URL_Filter::generate_record_key( $canonical_url );
		if ( $new_record_key !== $record['record_key'] && Shaheen_URL_Filter::has_canonical_collision( $new_record_key, $record['id'] ) ) {
			// Mark this duplicate URL as skipped with collision reason
			$wpdb->update(
				$records_table,
				array(
					'status'       => 'skipped',
					'skip_reason'  => 'Canonical collision with existing record key',
					'last_checked' => current_time( 'mysql' ),
					'updated_at'   => current_time( 'mysql' ),
				),
				array( 'id' => $record['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return true;
		}

		// 3. Classify content
		$content_type = Shaheen_Classifier::classify(
			$canonical_url,
			$extracted['title'],
			$extracted['headings'],
			$extracted['faq_items'],
			$record['source_sitemap']
		);

		// 4. Build source context header
		$source_context = self::format_source_context(
			$record['institution'],
			$record['source_domain'],
			$canonical_url,
			$content_type,
			$extracted['last_modified'] ? $extracted['last_modified'] : $record['last_modified'],
			$record['language']
		);

		// Full cleaned candidate text with source context
		$candidate_text = $source_context . $extracted['cleaned_text'];

		// 5. Generate SHA-256 hash of cleaned text + source context (never raw HTML)
		$candidate_hash = hash( 'sha256', trim( (string) $candidate_text ) );
		$accepted_hash  = ! empty( $record['accepted_content_hash'] ) ? $record['accepted_content_hash'] : '';

		// 6. State Machine & Continuous Change Detection Rules
		$status = 'new';
		$flag_reasons = array();

		// Check low quality or empty extraction
		if ( $extracted['is_empty_or_low_quality'] ) {
			$status = 'needs_review';
			$flag_reasons[] = 'Insufficient main content detected (< 30 words)';
		}

		// Check sensitive content
		if ( ! empty( $extracted['sensitive_flags'] ) ) {
			$status = 'pending_review';
			$flag_reasons[] = 'Sensitive terms detected: ' . implode( ', ', $extracted['sensitive_flags'] );
		}

		$update_data = array(
			'canonical_url'          => $canonical_url,
			'record_key'             => $new_record_key,
			'candidate_content_hash' => $candidate_hash,
			'candidate_content'      => $candidate_text,
			'content_type'           => $content_type,
			'title'                  => sanitize_text_field( $extracted['title'] ),
			'last_modified'          => $extracted['last_modified'] ? $extracted['last_modified'] : $record['last_modified'],
			'last_checked'           => current_time( 'mysql' ),
			'candidate_checked_at'   => current_time( 'mysql' ),
			'error_message'          => null,
			'updated_at'             => current_time( 'mysql' ),
		);

		if ( empty( $accepted_hash ) ) {
			// Brand new URL: store candidate values; status: new / needs_review / pending_review
			$update_data['status']       = $status;
			$update_data['flag_reasons'] = ! empty( $flag_reasons ) ? implode( '; ', $flag_reasons ) : null;
		} else {
			// Existing URL with accepted baseline:
			if ( $accepted_hash === $candidate_hash ) {
				// Same hash: unchanged
				$update_data['status']       = 'unchanged';
				$update_data['flag_reasons'] = null;
			} else {
				// Changed hash: PRESERVE accepted content & accepted hash!
				// Store new content separately as candidate content
				$update_data['status']        = 'pending_review';
				$update_data['previous_hash'] = $accepted_hash;
				$flag_reasons[]               = 'Content changed since previous acceptance';
				$update_data['flag_reasons']  = implode( '; ', $flag_reasons );
			}
		}

		$wpdb->update(
			$records_table,
			$update_data,
			array( 'id' => $record['id'] )
		);

		Shaheen_Logger::info( "Processed URL: {$canonical_url} [{$update_data['status']}]" );
		return true;
	}

	/**
	 * Re-check a single record on demand.
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

		$success = self::process_single_record( $record );
		$updated_record = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$records_table} WHERE id = %d", $record_id ),
			ARRAY_A
		);

		return array(
			'success' => $success,
			'status'  => $updated_record ? $updated_record['status'] : 'unknown',
			'message' => $success ? __( 'Record successfully re-checked.', 'shaheen-central-mxchat-sync' ) : __( 'Record processing encountered an error.', 'shaheen-central-mxchat-sync' ),
		);
	}
}
