<?php
/**
 * Source Website Manager & Onboarding for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Source_Manager {

	/**
	 * Seed initial default source if not already present.
	 */
	public static function seed_default_source() {
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();

		$default_domain = 'dammam.shaheengroup.org';
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE domain = %s",
				$default_domain
			)
		);

		if ( ! $exists ) {
			$wpdb->insert(
				$table,
				array(
					'institution'     => 'Shaheen Academy Dammam / Al-Khobar',
					'domain'          => $default_domain,
					'sitemap_url'     => 'https://dammam.shaheengroup.org/wp-sitemap.xml',
					'category'        => 'Education',
					'language'        => 'English',
					'crawl_frequency' => 'daily',
					'status'          => 'approved',
					'is_enabled'      => 1,
					'notes'           => 'Initial approved source for Dammam & Al-Khobar academy.',
					'last_crawled_at' => null,
					'created_at'      => current_time( 'mysql' ),
					'updated_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			Shaheen_Logger::info( 'Initial source seeded: Shaheen Academy Dammam / Al-Khobar (' . $default_domain . ')' );
		}
	}

	/**
	 * Get all sources.
	 *
	 * @return array
	 */
	public static function get_all_sources() {
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	}

	/**
	 * Get eligible sources for scheduled synchronization (must be approved AND enabled).
	 *
	 * @return array
	 */
	public static function get_sync_eligible_sources() {
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_enabled = 1 AND status = %s ORDER BY id ASC",
				'approved'
			),
			ARRAY_A
		);
	}

	/**
	 * Alias for backward compatibility.
	 *
	 * @return array
	 */
	public static function get_enabled_sources() {
		return self::get_sync_eligible_sources();
	}

	/**
	 * Get a single source by ID.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public static function get_source( $id ) {
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);
	}

	/**
	 * Get source by domain.
	 *
	 * @param string $domain
	 * @return array|null
	 */
	public static function get_source_by_domain( $domain ) {
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		$domain = self::sanitize_domain( $domain );
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE domain = %s", $domain ),
			ARRAY_A
		);
	}

	/**
	 * Check if a domain or URL is explicitly approved and enabled.
	 *
	 * @param string $url_or_domain
	 * @return bool
	 */
	public static function is_domain_approved( $url_or_domain ) {
		$host = wp_parse_url( $url_or_domain, PHP_URL_HOST );
		if ( empty( $host ) ) {
			$host = $url_or_domain;
		}

		$host = strtolower( trim( (string) $host ) );
		$source = self::get_source_by_domain( $host );

		return ( ! empty( $source ) && (int) $source['is_enabled'] === 1 && $source['status'] === 'approved' );
	}

	/**
	 * Validate source input fields.
	 *
	 * @param array $data
	 * @param int   $exclude_id
	 * @return true|WP_Error
	 */
	public static function validate_source_data( $data, $exclude_id = 0 ) {
		$institution = isset( $data['institution'] ) ? sanitize_text_field( trim( $data['institution'] ) ) : '';
		$domain      = isset( $data['domain'] ) ? self::sanitize_domain( $data['domain'] ) : '';
		$sitemap_url = isset( $data['sitemap_url'] ) ? esc_url_raw( trim( $data['sitemap_url'] ) ) : '';

		if ( empty( $institution ) ) {
			return new WP_Error( 'invalid_institution', __( 'Institution name is required.', 'shaheen-central-mxchat-sync' ) );
		}

		if ( empty( $domain ) || ! self::is_valid_hostname( $domain ) ) {
			return new WP_Error( 'invalid_domain', __( 'Domain must be a valid, approved public hostname.', 'shaheen-central-mxchat-sync' ) );
		}

		// Disallow localhost or private IP addresses
		if ( Shaheen_URL_Filter::is_private_or_loopback( $domain ) ) {
			return new WP_Error( 'ssrf_domain_blocked', __( 'Localhost and private IP address ranges are blocked for SSRF protection.', 'shaheen-central-mxchat-sync' ) );
		}

		if ( empty( $sitemap_url ) || strpos( strtolower( $sitemap_url ), 'https://' ) !== 0 ) {
			return new WP_Error( 'invalid_sitemap_url', __( 'Sitemap URL must use HTTPS.', 'shaheen-central-mxchat-sync' ) );
		}

		$sitemap_host = strtolower( (string) wp_parse_url( $sitemap_url, PHP_URL_HOST ) );
		if ( $sitemap_host !== $domain ) {
			return new WP_Error( 'domain_mismatch', __( 'Sitemap URL host must exactly match the configured source domain.', 'shaheen-central-mxchat-sync' ) );
		}

		// Check domain uniqueness
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		if ( $exclude_id > 0 ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE domain = %s AND id != %d",
					$domain,
					$exclude_id
				)
			);
		} else {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE domain = %s",
					$domain
				)
			);
		}

		if ( $exists ) {
			return new WP_Error( 'duplicate_domain', __( 'A source with this domain already exists.', 'shaheen-central-mxchat-sync' ) );
		}

		return true;
	}

	/**
	 * Add a new source (begins as 'draft').
	 *
	 * @param array $data
	 * @return int|WP_Error
	 */
	public static function add_source( $data ) {
		$validation = self::validate_source_data( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		$domain = self::sanitize_domain( $data['domain'] );

		$inserted = $wpdb->insert(
			$table,
			array(
				'institution'     => sanitize_text_field( $data['institution'] ),
				'domain'          => $domain,
				'sitemap_url'     => esc_url_raw( $data['sitemap_url'] ),
				'category'        => ! empty( $data['category'] ) ? sanitize_text_field( $data['category'] ) : 'Education',
				'language'        => ! empty( $data['language'] ) ? sanitize_text_field( $data['language'] ) : 'English',
				'crawl_frequency' => ! empty( $data['crawl_frequency'] ) ? sanitize_key( $data['crawl_frequency'] ) : 'daily',
				'status'          => 'draft',
				'is_enabled'      => 0, // Starts disabled until validated and approved
				'notes'           => ! empty( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
				'last_crawled_at' => null,
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not save the source website.', 'shaheen-central-mxchat-sync' ) );
		}

		$new_id = (int) $wpdb->insert_id;
		Shaheen_Logger::info( "Added new source website draft: {$data['institution']} ({$domain})" );

		return $new_id;
	}

	/**
	 * Update an existing source. If domain changes, reset to 'draft' and disabled.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool|WP_Error
	 */
	public static function update_source( $id, $data ) {
		$id = absint( $id );
		$existing = self::get_source( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Source not found.', 'shaheen-central-mxchat-sync' ) );
		}

		$validation = self::validate_source_data( $data, $id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		$new_domain = self::sanitize_domain( $data['domain'] );

		// If domain changed, require re-validation and reset to draft
		$status = $existing['status'];
		$is_enabled = $existing['is_enabled'];
		if ( $new_domain !== $existing['domain'] ) {
			$status = 'draft';
			$is_enabled = 0;
			Shaheen_Logger::warning( "Domain changed for source ID {$id}. Resetting status to draft." );
		}

		$updated = $wpdb->update(
			$table,
			array(
				'institution'     => sanitize_text_field( $data['institution'] ),
				'domain'          => $new_domain,
				'sitemap_url'     => esc_url_raw( $data['sitemap_url'] ),
				'category'        => ! empty( $data['category'] ) ? sanitize_text_field( $data['category'] ) : 'Education',
				'language'        => ! empty( $data['language'] ) ? sanitize_text_field( $data['language'] ) : 'English',
				'crawl_frequency' => ! empty( $data['crawl_frequency'] ) ? sanitize_key( $data['crawl_frequency'] ) : 'daily',
				'status'          => $status,
				'is_enabled'      => $is_enabled,
				'notes'           => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : $existing['notes'],
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Database error while updating source.', 'shaheen-central-mxchat-sync' ) );
		}

		Shaheen_Logger::info( "Updated source website ID {$id} ({$new_domain})" );
		return true;
	}

	/**
	 * Validate a source: tests HTTPS reachability, parses sitemap index, and identifies child sitemaps.
	 *
	 * @param int $id
	 * @return array
	 */
	public static function validate_source( $id ) {
		$source = self::get_source( $id );
		if ( ! $source ) {
			return array( 'success' => false, 'message' => 'Source not found.' );
		}

		global $wpdb;
		$table = Shaheen_DB::get_sources_table();

		// Set status to validating
		$wpdb->update( $table, array( 'status' => 'validating' ), array( 'id' => $id ) );

		// Fetch sitemap safely with controlled redirects
		$fetch = Shaheen_Page_Fetcher::fetch_with_controlled_redirects( $source['sitemap_url'], $source['domain'] );
		if ( ! $fetch['success'] ) {
			$wpdb->update( $table, array( 'status' => 'validation_failed' ), array( 'id' => $id ) );
			Shaheen_Logger::error( "Validation failed for {$source['domain']}: {$fetch['error']}" );
			return array(
				'success' => false,
				'message' => 'Sitemap fetch failed: ' . $fetch['error'],
			);
		}

		// Parse XML safely
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $fetch['body'], 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml ) {
			libxml_clear_errors();
			$wpdb->update( $table, array( 'status' => 'validation_failed' ), array( 'id' => $id ) );
			return array(
				'success' => false,
				'message' => 'Malformed sitemap XML.',
			);
		}

		$supported_children = array();
		$rejected_children  = array();
		$estimated_urls     = 0;

		if ( 'sitemapindex' === $xml->getName() || isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $s ) {
				$loc = isset( $s->loc ) ? trim( (string) $s->loc ) : '';
				if ( empty( $loc ) ) {
					continue;
				}

				if ( Shaheen_Crawler::is_supported_child_sitemap( $loc ) ) {
					$supported_children[] = $loc;
				} else {
					$rejected_children[] = $loc;
				}
			}
		} elseif ( 'urlset' === $xml->getName() || isset( $xml->url ) ) {
			$supported_children[] = $source['sitemap_url'];
			$estimated_urls = count( $xml->url );
		}

		// Source passes validation
		$wpdb->update(
			$table,
			array(
				'status'     => 'dry_run',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return array(
			'success'            => true,
			'supported_children' => $supported_children,
			'rejected_children'  => $rejected_children,
			'estimated_urls'     => $estimated_urls,
			'message'            => sprintf(
				'Validation passed! Found %d supported child sitemaps, %d non-content sitemaps excluded.',
				count( $supported_children ),
				count( $rejected_children )
			),
		);
	}

	/**
	 * Run a dry test on a source website (fetches test batch of up to 5 URLs without saving live records).
	 *
	 * @param int $id
	 * @return array
	 */
	public static function dry_test_source( $id ) {
		$source = self::get_source( $id );
		if ( ! $source ) {
			return array( 'success' => false, 'message' => 'Source not found.' );
		}

		// Perform discovery of first child sitemap or urlset
		$test_result = Shaheen_Crawler::crawl_source_test_batch( $source, 5 );

		return array(
			'success' => true,
			'results' => $test_result,
			'message' => 'Dry test batch completed.',
		);
	}

	/**
	 * Approve a source website.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function approve_source( $id ) {
		$id = absint( $id );
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'approved',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		Shaheen_Logger::info( "Approved source website ID {$id}." );
		return (bool) $updated;
	}

	/**
	 * Toggle source enabled/disabled.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function toggle_source( $id ) {
		$id = absint( $id );
		$source = self::get_source( $id );
		if ( ! $source ) {
			return false;
		}

		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		$new_state = (int) $source['is_enabled'] === 1 ? 0 : 1;

		$wpdb->update(
			$table,
			array(
				'is_enabled' => $new_state,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		Shaheen_Logger::info( "Toggled source website ID {$id} to " . ( $new_state ? 'enabled' : 'disabled' ) );
		return true;
	}

	/**
	 * Archive a source website (preserves historical records).
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function archive_source( $id ) {
		$id = absint( $id );
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'archived',
				'is_enabled' => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		Shaheen_Logger::info( "Archived source website ID {$id}." );
		return (bool) $updated;
	}

	/**
	 * Delete a source website with explicit confirmation.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete_source( $id ) {
		$id = absint( $id );
		$source = self::get_source( $id );
		if ( ! $source ) {
			return false;
		}

		global $wpdb;
		$sources_table = Shaheen_DB::get_sources_table();
		$records_table = Shaheen_DB::get_records_table();

		// Delete associated records for this domain
		$wpdb->delete( $records_table, array( 'source_domain' => $source['domain'] ), array( '%s' ) );
		// Delete source
		$deleted = $wpdb->delete( $sources_table, array( 'id' => $id ), array( '%d' ) );

		Shaheen_Logger::info( "Deleted source website ID {$id} ({$source['domain']})" );
		return (bool) $deleted;
	}

	/**
	 * Sanitize domain name.
	 *
	 * @param string $domain
	 * @return string
	 */
	public static function sanitize_domain( $domain ) {
		$domain = trim( strtolower( (string) $domain ) );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = explode( '/', $domain )[0];
		$domain = explode( ':', $domain )[0];
		return sanitize_text_field( $domain );
	}

	/**
	 * Validate hostname RFC compliance.
	 *
	 * @param string $domain
	 * @return bool
	 */
	public static function is_valid_hostname( $domain ) {
		if ( empty( $domain ) || strlen( $domain ) > 253 ) {
			return false;
		}
		return (bool) preg_match( '/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain );
	}
}
