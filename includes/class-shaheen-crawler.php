<?php
/**
 * Sitemap Crawler & Child Sitemap Filter for Shaheen Central MXChat Sync
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
		$url_lower = strtolower( (string) $sitemap_url );

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
	 * Crawl a source website's sitemap index and discover URLs.
	 *
	 * @param array $source
	 * @return array
	 */
	public static function crawl_source_sitemap( $source ) {
		$sitemap_url = $source['sitemap_url'];
		$domain      = $source['domain'];
		$institution = $source['institution'];

		Shaheen_Logger::info( "Starting sitemap crawl for {$institution} ({$domain}): {$sitemap_url}" );

		$skip_check = Shaheen_URL_Filter::should_skip_url( $sitemap_url, $domain );
		if ( false !== $skip_check && strpos( $skip_check, 'excluded path' ) === false ) {
			Shaheen_Logger::error( "Sitemap URL skipped for {$domain}: {$skip_check}" );
			return array(
				'success' => false,
				'error'   => $skip_check,
			);
		}

		$max_sitemap_size   = absint( get_option( 'shaheen_sync_max_sitemap_size', 5242880 ) ); // 5MB
		$max_child_sitemaps = absint( get_option( 'shaheen_sync_max_child_sitemaps', 20 ) );
		$max_urls_per_sitemap = absint( get_option( 'shaheen_sync_max_urls_per_sitemap', 500 ) );

		// Fetch sitemap index using controlled redirects & anti-SSRF
		$response = Shaheen_Page_Fetcher::fetch_with_controlled_redirects( $sitemap_url, $domain, false );

		if ( ! $response['success'] ) {
			Shaheen_Logger::error( "Failed to fetch sitemap for {$domain}: {$response['error']}" );
			return array(
				'success' => false,
				'error'   => $response['error'],
			);
		}

		$body = $response['body'];
		if ( strlen( $body ) > $max_sitemap_size ) {
			$msg = 'Sitemap response exceeds maximum allowed size';
			Shaheen_Logger::error( "Sitemap error for {$domain}: {$msg}" );
			return array(
				'success' => false,
				'error'   => $msg,
			);
		}

		// Safe XML parsing with LIBXML_NONET
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

				// Validate child sitemap host matches approved domain & is HTTPS
				$child_host = strtolower( (string) wp_parse_url( $loc, PHP_URL_HOST ) );
				if ( $child_host !== strtolower( $domain ) || strpos( strtolower( $loc ), 'https://' ) !== 0 ) {
					Shaheen_Logger::warning( "Rejected off-domain or non-HTTPS child sitemap: {$loc}" );
					continue;
				}

				if ( self::is_supported_child_sitemap( $loc ) ) {
					$child_sitemaps_to_crawl[] = $loc;
					$count++;
				} else {
					Shaheen_Logger::info( "Skipped non-content child sitemap: {$loc}" );
				}
			}
		} elseif ( 'urlset' === $xml->getName() || isset( $xml->url ) ) {
			$child_sitemaps_to_crawl[] = $sitemap_url;
		} else {
			return array(
				'success' => false,
				'error'   => 'Unknown sitemap XML format',
			);
		}

		$discovered_urls = array();

		foreach ( $child_sitemaps_to_crawl as $child_url ) {
			$child_items = self::fetch_child_sitemap_urls( $child_url, $domain, $max_urls_per_sitemap, $max_sitemap_size );
			foreach ( $child_items as $u_item ) {
				$discovered_urls[] = $u_item;
			}
		}

		// Store discovered URLs and update continuous change state
		$stats = self::store_discovered_urls_with_change_detection( $discovered_urls, $source );

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
			'queued'     => $stats['queued'],
			'skipped'    => $stats['skipped'],
			'missing'    => $stats['missing'],
		);
	}

	/**
	 * Fetch child sitemap safely with controlled redirects.
	 *
	 * @param string $url
	 * @param string $domain
	 * @param int    $max_urls
	 * @param int    $max_size
	 * @return array
	 */
	private static function fetch_child_sitemap_urls( $url, $domain, $max_urls, $max_size ) {
		$items = array();

		$response = Shaheen_Page_Fetcher::fetch_with_controlled_redirects( $url, $domain, false );
		if ( ! $response['success'] ) {
			return $items;
		}

		$body = $response['body'];
		if ( strlen( $body ) > $max_size ) {
			return $items;
		}

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
	 * Store discovered URLs with Continuous Website Change Detection:
	 * - New URL -> 'discovered'
	 * - Existing eligible URL -> 'queued_check'
	 * - Excluded URL -> 'skipped'
	 * - Removed sitemap URL -> 'missing_from_source'
	 *
	 * @param array $urls
	 * @param array $source
	 * @return array
	 */
	public static function store_discovered_urls_with_change_detection( $urls, $source ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$discovered_count = 0;
		$queued_count     = 0;
		$skipped_count    = 0;
		$seen_record_keys = array();

		foreach ( $urls as $item ) {
			$raw_url        = $item['url'];
			$lastmod_raw    = $item['lastmod'];
			$source_sitemap = $item['source_sitemap'];

			$norm_url   = Shaheen_URL_Filter::normalize_url( $raw_url, '', $source['domain'] );
			$record_key = Shaheen_URL_Filter::generate_record_key( $norm_url );
			$seen_record_keys[] = $record_key;

			$last_modified = null;
			if ( ! empty( $lastmod_raw ) ) {
				$time = strtotime( $lastmod_raw );
				if ( false !== $time ) {
					$last_modified = gmdate( 'Y-m-d H:i:s', $time );
				}
			}

			$skip_reason = Shaheen_URL_Filter::should_skip_url( $norm_url, $source['domain'] );

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, accepted_content_hash, candidate_content_hash FROM {$records_table} WHERE record_key = %s",
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
							'institution'            => $source['institution'],
							'source_domain'          => $source['domain'],
							'canonical_url'          => $norm_url,
							'record_key'             => $record_key,
							'source_sitemap'         => $source_sitemap,
							'accepted_content_hash'  => '',
							'candidate_content_hash' => '',
							'previous_hash'          => '',
							'accepted_content'       => null,
							'candidate_content'      => null,
							'mxchat_response_id'     => null,
							'content_type'           => 'page',
							'language'               => ! empty( $source['language'] ) ? $source['language'] : 'English',
							'title'                  => '',
							'flag_reasons'           => null,
							'skip_reason'            => $skip_reason,
							'last_modified'          => $last_modified,
							'last_checked'           => current_time( 'mysql' ),
							'status'                 => 'skipped',
							'error_message'          => null,
							'created_at'             => current_time( 'mysql' ),
							'updated_at'             => current_time( 'mysql' ),
						),
						array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
					);
				} else {
					$wpdb->update(
						$records_table,
						array(
							'status'       => 'skipped',
							'skip_reason'  => $skip_reason,
							'last_checked' => current_time( 'mysql' ),
							'updated_at'   => current_time( 'mysql' ),
						),
						array( 'id' => $existing['id'] ),
						array( '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
				}
				continue;
			}

			// Valid discovered or existing URL
			if ( ! $existing ) {
				$discovered_count++;
				$content_type = ( strpos( $source_sitemap, 'posts-post' ) !== false || strpos( $source_sitemap, 'post-sitemap' ) !== false ) ? 'post' : 'page';

				$wpdb->insert(
					$records_table,
					array(
						'institution'            => $source['institution'],
						'source_domain'          => $source['domain'],
						'canonical_url'          => $norm_url,
						'record_key'             => $record_key,
						'source_sitemap'         => $source_sitemap,
						'accepted_content_hash'  => '',
						'candidate_content_hash' => '',
						'previous_hash'          => '',
						'accepted_content'       => null,
						'candidate_content'      => null,
						'mxchat_response_id'     => null,
						'content_type'           => $content_type,
						'language'               => ! empty( $source['language'] ) ? $source['language'] : 'English',
						'title'                  => '',
						'flag_reasons'           => null,
						'skip_reason'            => null,
						'last_modified'          => $last_modified,
						'last_checked'           => null,
						'status'                 => 'discovered',
						'error_message'          => null,
						'created_at'             => current_time( 'mysql' ),
						'updated_at'             => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			} else {
				// Existing URL: if status is 'unchanged' or 'new', queue for periodic re-check
				if ( in_array( $existing['status'], array( 'unchanged', 'new' ), true ) ) {
					$queued_count++;
					$wpdb->update(
						$records_table,
						array(
							'status'         => 'queued_check',
							'source_sitemap' => $source_sitemap,
							'last_modified'  => $last_modified ? $last_modified : null,
							'updated_at'     => current_time( 'mysql' ),
						),
						array( 'id' => $existing['id'] ),
						array( '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
				}
			}
		}

		// Detect removed URLs from source sitemap
		$missing_count = 0;
		if ( ! empty( $seen_record_keys ) ) {
			// Find existing active records for this domain that were not present in this sitemap run
			$domain_records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, record_key, status FROM {$records_table} WHERE source_domain = %s AND status NOT IN ('skipped', 'rejected', 'missing_from_source')",
					$source['domain']
				),
				ARRAY_A
			);

			foreach ( $domain_records as $dr ) {
				if ( ! in_array( $dr['record_key'], $seen_record_keys, true ) ) {
					$wpdb->update(
						$records_table,
						array(
							'status'     => 'missing_from_source',
							'updated_at' => current_time( 'mysql' ),
						),
						array( 'id' => $dr['id'] ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					$missing_count++;
				}
			}
		}

		return array(
			'discovered' => $discovered_count,
			'queued'     => $queued_count,
			'skipped'    => $skipped_count,
			'missing'    => $missing_count,
		);
	}

	/**
	 * Run a small dry-test crawl batch (fetches first child sitemap and extracts sample items).
	 *
	 * @param array $source
	 * @param int   $sample_limit
	 * @return array
	 */
	public static function crawl_source_test_batch( $source, $sample_limit = 5 ) {
		$sitemap_url = $source['sitemap_url'];
		$domain      = $source['domain'];

		$response = Shaheen_Page_Fetcher::fetch_with_controlled_redirects( $sitemap_url, $domain, false );
		if ( ! $response['success'] ) {
			return array( 'error' => $response['error'] );
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NONET );
		if ( false === $xml ) {
			libxml_clear_errors();
			return array( 'error' => 'Malformed XML' );
		}

		$sample_urls = array();
		if ( 'sitemapindex' === $xml->getName() || isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $s ) {
				$loc = isset( $s->loc ) ? trim( (string) $s->loc ) : '';
				if ( self::is_supported_child_sitemap( $loc ) ) {
					$items = self::fetch_child_sitemap_urls( $loc, $domain, $sample_limit, 5242880 );
					foreach ( $items as $it ) {
						$sample_urls[] = $it['url'];
						if ( count( $sample_urls ) >= $sample_limit ) {
							break 2;
						}
					}
				}
			}
		} elseif ( 'urlset' === $xml->getName() || isset( $xml->url ) ) {
			foreach ( $xml->url as $u ) {
				$loc = isset( $u->loc ) ? trim( (string) $u->loc ) : '';
				if ( ! empty( $loc ) ) {
					$sample_urls[] = $loc;
					if ( count( $sample_urls ) >= $sample_limit ) {
						break;
					}
				}
			}
		}

		$results = array();
		foreach ( $sample_urls as $test_url ) {
			$norm = Shaheen_URL_Filter::normalize_url( $test_url, '', $domain );
			$skip = Shaheen_URL_Filter::should_skip_url( $norm, $domain );
			if ( false !== $skip ) {
				$results[] = array(
					'url'    => $norm,
					'status' => 'skipped',
					'reason' => $skip,
				);
				continue;
			}

			$fetch = Shaheen_Page_Fetcher::fetch( $norm, $domain );
			if ( ! $fetch['success'] ) {
				$results[] = array(
					'url'    => $norm,
					'status' => 'error',
					'reason' => $fetch['error'],
				);
				continue;
			}

			$extracted = Shaheen_Content_Extractor::extract( $fetch['body'], $norm );
			$classification = Shaheen_Classifier::classify( $norm, $extracted['title'], $extracted['headings'], $extracted['faq_items'] );

			$results[] = array(
				'url'             => $norm,
				'status'          => $extracted['is_empty_or_low_quality'] ? 'needs_review' : 'valid',
				'title'           => $extracted['title'],
				'type'            => $classification,
				'sensitive_terms' => $extracted['sensitive_flags'],
				'preview_snippet' => substr( (string) $extracted['cleaned_text'], 0, 200 ) . '...',
			);
		}

		return $results;
	}
}
