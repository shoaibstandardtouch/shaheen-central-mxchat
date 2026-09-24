<?php
/**
 * Dry-run CSV Exporter for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Exporter {

	/**
	 * Export records as a downloadable CSV.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'shaheen-central-mxchat-sync' ) );
		}

		check_admin_referer( 'shaheen_export_csv', 'nonce' );

		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		// Fetch records
		$records = $wpdb->get_results(
			"SELECT id, institution, source_domain, canonical_url, record_key, content_type, content_hash, previous_hash, status, flag_reasons, skip_reason, last_modified, last_checked, error_message FROM {$records_table} ORDER BY id ASC",
			ARRAY_A
		);

		$filename = 'shaheen-central-mxchat-dryrun-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// CSV Header
		fputcsv(
			$output,
			array(
				'ID',
				'Institution',
				'Source Domain',
				'Canonical URL',
				'Record Key',
				'Content Type',
				'Current Hash (SHA-256)',
				'Previous Hash (SHA-256)',
				'Status (Phase 1 Dry-Run)',
				'Flag Reasons',
				'Skip Reason',
				'Last Modified',
				'Last Checked',
				'Error Message',
			)
		);

		foreach ( $records as $row ) {
			fputcsv(
				$output,
				array(
					$row['id'],
					$row['institution'],
					$row['source_domain'],
					$row['canonical_url'],
					$row['record_key'],
					$row['content_type'],
					$row['content_hash'],
					$row['previous_hash'],
					$row['status'],
					$row['flag_reasons'],
					$row['skip_reason'],
					$row['last_modified'],
					$row['last_checked'],
					$row['error_message'],
				)
			);
		}

		fclose( $output );
		exit;
	}
}
