<?php
/**
 * Source Website Manager for Shaheen Central MXChat Sync
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
					'is_enabled'      => 1,
					'last_crawled_at' => null,
					'created_at'      => current_time( 'mysql' ),
					'updated_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
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
	 * Get enabled sources only.
	 *
	 * @return array
	 */
	public static function get_enabled_sources() {
		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE is_enabled = 1 ORDER BY id ASC", ARRAY_A );
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

		$host = strtolower( trim( $host ) );
		$source = self::get_source_by_domain( $host );

		return ( ! empty( $source ) && (int) $source['is_enabled'] === 1 );
	}

	/**
	 * Validate source input data.
	 *
	 * @param array $data
	 * @param int   $exclude_id
	 * @return true|WP_Error
	 */
	public static function validate_source_data( $data, $exclude_id = 0 ) {
		$institution = isset( $data['institution'] ) ? sanitize_text_field( trim( $data['institution'] ) ) : '';
		$domain      = isset( $data['domain'] ) ? self::sanitize_domain( $data['domain'] ) : '';
		$sitemap_url = isset( $data['sitemap_url'] ) ? esc_url_raw( trim( $data['sitemap_url'] ) ) : '';
		$category    = isset( $data['category'] ) ? sanitize_text_field( trim( $data['category'] ) ) : 'Education';

		if ( empty( $institution ) ) {
			return new WP_Error( 'invalid_institution', __( 'Institution name is required.', 'shaheen-central-mxchat-sync' ) );
		}

		if ( empty( $domain ) || ! self::is_valid_hostname( $domain ) ) {
			return new WP_Error( 'invalid_domain', __( 'Domain must be a valid, approved public hostname (e.g. dammam.shaheengroup.org).', 'shaheen-central-mxchat-sync' ) );
		}

		// Disallow localhost or private IP addresses
		if ( Shaheen_URL_Filter::is_private_or_loopback( $domain ) ) {
			return new WP_Error( 'ssrf_domain_blocked', __( 'Localhost and private IP address ranges are blocked for SSRF protection.', 'shaheen-central-mxchat-sync' ) );
		}

		if ( empty( $sitemap_url ) || strpos( strtolower( $sitemap_url ), 'https://' ) !== 0 ) {
			return new WP_Error( 'invalid_sitemap_url', __( 'Sitemap URL must use HTTPS (e.g. https://domain/wp-sitemap.xml).', 'shaheen-central-mxchat-sync' ) );
		}

		$sitemap_host = strtolower( (string) wp_parse_url( $sitemap_url, PHP_URL_HOST ) );
		if ( $sitemap_host !== $domain ) {
			return new WP_Error( 'domain_mismatch', __( 'Sitemap URL domain must exactly match the configured source domain.', 'shaheen-central-mxchat-sync' ) );
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
	 * Add a new source.
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
				'category'        => sanitize_text_field( ! empty( $data['category'] ) ? $data['category'] : 'Education' ),
				'is_enabled'      => ! empty( $data['is_enabled'] ) ? 1 : 0,
				'last_crawled_at' => null,
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not save the source website to the database.', 'shaheen-central-mxchat-sync' ) );
		}

		$new_id = (int) $wpdb->insert_id;
		Shaheen_Logger::info( "Added source website: {$data['institution']} ({$domain})" );

		return $new_id;
	}

	/**
	 * Update an existing source.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool|WP_Error
	 */
	public static function update_source( $id, $data ) {
		$id = absint( $id );
		$validation = self::validate_source_data( $data, $id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		global $wpdb;
		$table = Shaheen_DB::get_sources_table();
		$domain = self::sanitize_domain( $data['domain'] );

		$updated = $wpdb->update(
			$table,
			array(
				'institution' => sanitize_text_field( $data['institution'] ),
				'domain'      => $domain,
				'sitemap_url' => esc_url_raw( $data['sitemap_url'] ),
				'category'    => sanitize_text_field( ! empty( $data['category'] ) ? $data['category'] : 'Education' ),
				'is_enabled'  => ! empty( $data['is_enabled'] ) ? 1 : 0,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Database error while updating source.', 'shaheen-central-mxchat-sync' ) );
		}

		Shaheen_Logger::info( "Updated source website ID {$id} ({$domain})" );
		return true;
	}

	/**
	 * Delete a source website and optionally cascade delete its records.
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
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		Shaheen_Logger::info( "Toggled source website ID {$id} to " . ( $new_state ? 'enabled' : 'disabled' ) );
		return true;
	}

	/**
	 * Sanitize a domain string.
	 *
	 * @param string $domain
	 * @return string
	 */
	public static function sanitize_domain( $domain ) {
		$domain = trim( strtolower( (string) $domain ) );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = explode( '/', $domain )[0];
		$domain = explode( ':', $domain )[0]; // remove port
		return sanitize_text_field( $domain );
	}

	/**
	 * Check if hostname is valid RFC format.
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
