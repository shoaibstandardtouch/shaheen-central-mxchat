<?php
/**
 * Sitemap Crawler for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Crawler {

	/**
	 * Check if a child sitemap filename or URL matches page or post content.
	 *
	 * @param string $sitemap_url
	 * @return bool
	 */
	public static function is_supported_child_sitemap( $sitemap_url ) {
		$url_lower = strtolower( $sitemap_url );

		// Check for skipped types first
		$forbidden_keywords = array(
			'category',
			'tag',
			'author',
			'user',
			'attachment',
			'media',
			'image',
			'video',
			'taxonom',
			'term',
		);

		foreach ( $forbidden_keywords as $forbidden ) {
			if ( strpos( $url_lower, $forbidden ) !== false ) {
				return false;
			}
		}

		// Must match page or post content
		// WordPress Core: wp-sitemap-posts-page-1.xml, wp-sitemap-posts-post-1.xml
		// Yoast / RankMath / AIOSEO: page-sitemap.xml, post-sitemap.xml, etc.
		if (
			strpos( $url_lower, 'posts-page' ) !== false ||
			strpos( $url_lower, 'posts-post' ) !== false ||
			strpos( $url_lower, 'page-sitemap' ) !== false ||
			strpos( $url_lower, 'post-sitemap' ) !== false ||
			preg_match( '#/(page|post)-sitemap([0-9]*)\.xml#i', $url_lower )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Crawl a source website's sitemap and discover URLs.
	 *
	 * @param array $source Source row from DB.
	 * @return array Discovered and skipped counts or errors.
	 */
	public static function crawl_source_sitemap( $source ) {
		$sitemap_url = $source['sitemap_url'];
		$domain      = $source['domain'];
		$institution = $source['institution'];

		Shaheen_Logger::info( "Starting sitemap crawl for {$institution} ({$domain}): {$sitemap_url}" );

		// Check domain approval & SSRF
		$skip_check = Shaheen_URL_Filter::should_skip_url( $sitemap_url, $domain );
		if ( false !== $skip_check && strpos( $skip_check, 'excluded path' ) === false ) {
			Shaheen_Logger::error( "Sitemap URL skipped for {$domain}: {$skip_check}" );
			return array(
				'success' => false,
				'error'   => $skip_check,
			);
		}

		$max_sitemap_size = absint( get_option( 'shaheen_sync_max_sitemap_size', 5242880 ) ); // 5MB
		$max_child_sitemaps = absint( get_option( 'shaheen_sync_max_child_sitemaps', 20 ) );
		$max_urls_per_sitemap = absint( get_option( 'shaheen_sync_max_urls_per_sitemap', 500 ) );
		$timeout = absint( get_option( 'shaheen_sync_timeout', 15 ) );
		$user_agent = get_option( 'shaheen_sync_user_agent', 'ShaheenCentralMXChatSync/1.0 (+https://shaheengroup.org)' );

		$response = wp_safe_remote_get(
			$sitemap_url,
			array(
				'timeout'    => $timeout,
				'user-agent' => $user_agent,
				'headers'    => array(
					'Accept' => 'application/xml, text/xml, */*',
				),
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			Shaheen_Logger::error( "Failed to fetch sitemap for {$domain}: {$error_msg}" );
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$msg = "Sitemap returned HTTP {$code}";
			Shaheen_Logger::error( "Sitemap error for {$domain}: {$msg}" );
			return array(
				'success' => false,
				'error'   => $msg,
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > $max_sitemap_size ) {
			$msg = 'Sitemap response exceeds maximum allowed size';
			Shaheen_Logger::error( "Sitemap error for {$domain}: {$msg}" );
			return array(
				'success' => false,
				'error'   => $msg,
			);
		}

		// Parse XML safely
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml ) {
			libxml_clear_errors();
			$msg = 'Malformed sitemap XML';
			Shaheen_Logger::error( "Sitemap error for {$domain}: {$msg}" );
			return array(
				'success' => false,
				'error'   => $msg,
			);
		}

		$child_sitemaps_to_crawl = array();
		$direct_urls             = array();

		// Check if sitemap index
		if ( 'sitemapindex' === $xml->getName() || isset( $xml->sitemap ) ) {
			$count = 0;
			foreach ( $xml->sitemap as $sitemap_node ) {
				if ( $count >= $max_child_sitemaps ) {
					Shaheen_Logger::warning( "Maximum child sitemaps limit reached ({$max_child_sitemaps}) for {$domain}" );
					break;
				}

				$loc = isset( $sitemap_node->loc ) ? trim( (string) $sitemap_node->loc ) : '';
				if ( empty( $loc ) ) {
					continue;
				}

				if ( self::is_supported_child_sitemap( $loc ) ) {
					$child_sitemaps_to_crawl[] = $loc;
					$count++;
				} else {
					Shaheen_Logger::info( "Skipping non-page/post child sitemap: {$loc}" );
				}
			}
		} elseif ( 'urlset' === $xml->getName() || isset( $xml->url ) ) {
			// Direct urlset
			$child_sitemaps_to_crawl[] = $sitemap_url;
		} else {
			return array(
				'success' => false,
				'error'   => 'Unknown sitemap XML format',
			);
		}

		$discovered_urls = array();

		// Crawl supported child sitemaps
		foreach ( $child_sitemaps_to_crawl as $child_url ) {
			$child_result = self::fetch_sitemap_urls( $child_url, $domain, $max_urls_per_sitemap, $timeout, $user_agent );
			if ( is_array( $child_result ) ) {
				foreach ( $child_result as $u_item ) {
					$discovered_urls[] = $u_item;
				}
			}
		}

		// Process discovered URLs into database records
		$stats = self::store_discovered_urls( $discovered_urls, $source );

		// Update source last crawled timestamp
		global $wpdb;
		$wpdb->update(
			Shaheen_DB::get_sources_table(),
			array(
				'last_crawled_at' => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $source['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'success'    => true,
			'discovered' => $stats['discovered'],
			'skipped'    => $stats['skipped'],
		);
	}

	/**
	 * Fetch and extract URLs from a child sitemap.
	 *
	 * @param string $url
	 * @param string $domain
	 * @param int    $max_urls
	 * @param int    $timeout
	 * @param string $user_agent
	 * @return array
	 */
	private static function fetch_sitemap_urls( $url, $domain, $max_urls, $timeout, $user_agent ) {
		$items = array();

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => $timeout,
				'user-agent' => $user_agent,
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $items;
		}

		$body = wp_remote_retrieve_body( $response );
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml || ! isset( $xml->url ) ) {
			libxml_clear_errors();
			return $items;
		}

		$count = 0;
		foreach ( $xml->url as $url_node ) {
			if ( $count >= $max_urls ) {
				break;
			}

			$loc = isset( $url_node->loc ) ? trim( (string) $url_node->loc ) : '';
			$lastmod = isset( $url_node->lastmod ) ? trim( (string) $url_node->lastmod ) : null;

			if ( ! empty( $loc ) ) {
				$items[] = array(
					'url'            => $loc,
					'lastmod'        => $lastmod,
					'source_sitemap' => $url,
				);
				$count++;
			}
		}

		return $items;
	}

	/**
	 * Store or update discovered URLs in the records table.
	 *
	 * @param array $urls
	 * @param array $source
	 * @return array Stats
	 */
	public static function store_discovered_urls( $urls, $source ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$discovered_count = 0;
		$skipped_count    = 0;

		foreach ( $urls as $item ) {
			$raw_url        = $item['url'];
			$lastmod_raw    = $item['lastmod'];
			$source_sitemap = $item['source_sitemap'];

			// Normalize URL first
			$norm_url = Shaheen_URL_Filter::normalize_url( $raw_url );
			$record_key = Shaheen_URL_Filter::generate_record_key( $norm_url );

			// Format lastmod datetime
			$last_modified = null;
			if ( ! empty( $lastmod_raw ) ) {
				$time = strtotime( $lastmod_raw );
				if ( false !== $time ) {
					$last_modified = gmdate( 'Y-m-d H:i:s', $time );
				}
			}

			// Check URL filtering
			$skip_reason = Shaheen_URL_Filter::should_skip_url( $norm_url, $source['domain'] );

			// Check existing record
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, content_hash FROM {$records_table} WHERE record_key = %s",
					$record_key
				),
				ARRAY_A
			);

			if ( false !== $skip_reason ) {
				$skipped_count++;
				if ( ! $existing ) {
					$wpdb->insert(
						$records_table,
						array(
							'institution'        => $source['institution'],
							'source_domain'      => $source['domain'],
							'canonical_url'      => $norm_url,
							'record_key'         => $record_key,
							'source_sitemap'     => $source_sitemap,
							'content_hash'       => '',
							'previous_hash'      => '',
							'mxchat_response_id' => null,
							'content_type'       => 'page',
							'title'              => '',
							'cleaned_content'    => '',
							'flag_reasons'       => '',
							'skip_reason'        => $skip_reason,
							'last_modified'      => $last_modified,
							'last_checked'       => current_time( 'mysql' ),
							'last_sent'          => null,
							'status'             => 'skipped',
							'error_message'      => null,
							'created_at'         => current_time( 'mysql' ),
							'updated_at'         => current_time( 'mysql' ),
						),
						array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
					);
				} else {
					$wpdb->update(
						$records_table,
						array(
							'status'        => 'skipped',
							'skip_reason'   => $skip_reason,
							'last_checked'  => current_time( 'mysql' ),
							'updated_at'    => current_time( 'mysql' ),
						),
						array( 'id' => $existing['id'] ),
						array( '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
				}
				continue;
			}

			// Valid discovered URL
			$discovered_count++;
			if ( ! $existing ) {
				$wpdb->insert(
					$records_table,
					array(
						'institution'        => $source['institution'],
						'source_domain'      => $source['domain'],
						'canonical_url'      => $norm_url,
						'record_key'         => $record_key,
						'source_sitemap'     => $source_sitemap,
						'content_hash'       => '',
						'previous_hash'      => '',
						'mxchat_response_id' => null,
						'content_type'       => ( strpos( $source_sitemap, 'posts-post' ) !== false || strpos( $source_sitemap, 'post-sitemap' ) !== false ) ? 'post' : 'page',
						'title'              => '',
						'cleaned_content'    => '',
						'flag_reasons'       => '',
						'skip_reason'        => null,
						'last_modified'      => $last_modified,
						'last_checked'       => null,
						'last_sent'          => null,
						'status'             => 'discovered',
						'error_message'      => null,
						'created_at'         => current_time( 'mysql' ),
						'updated_at'         => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			} else {
				// If existing record was previously discovered or skipped but now valid, update last_modified
				$wpdb->update(
					$records_table,
					array(
						'source_sitemap' => $source_sitemap,
						'last_modified'  => $last_modified,
						'updated_at'     => current_time( 'mysql' ),
					),
					array( 'id' => $existing['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		return array(
			'discovered' => $discovered_count,
			'skipped'    => $skipped_count,
		);
	}
}
