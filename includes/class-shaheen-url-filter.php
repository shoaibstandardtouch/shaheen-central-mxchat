<?php
/**
 * URL Filtering, Canonical Normalization, Collision Detection & SSRF Protection
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_URL_Filter {

	/**
	 * Default excluded path patterns.
	 *
	 * @return array
	 */
	public static function get_default_excluded_patterns() {
		return array(
			'/wp-login\.php',
			'/wp-admin/',
			'/login/',
			'/logout/',
			'/search/',
			'[?&]s=',
			'/thank-you/?',
			'/privacy/?',
			'/privacy-policy/?',
			'/cookie/?',
			'/cookies/?',
			'/terms/?',
			'/author/',
			'/tag/',
			'/category/',
			'/attachment/',
			'/feed/?',
			'/xmlrpc',
			'/cart/?',
			'/checkout/?',
			'/my-account/?',
			'/account/?',
			'/lost-password/?',
			'/reset-password/?',
			'[?&]preview=',
			'[?&]preview_id=',
		);
	}

	/**
	 * Tracking query parameters to strip.
	 *
	 * @return array
	 */
	public static function get_tracking_parameters() {
		return array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'fbclid',
			'gclid',
			'msclkid',
			'mc_eid',
			'mc_cid',
			'_ga',
			'ref',
		);
	}

	/**
	 * Determine if a URL should be skipped.
	 *
	 * @param string $url
	 * @param string $expected_domain
	 * @return false|string False if allowed, reason string if skipped.
	 */
	public static function should_skip_url( $url, $expected_domain = '' ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return 'empty or invalid URL';
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return 'malformed URL';
		}

		// Protocol check: HTTPS only
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return 'non-https protocol rejected';
		}

		$host = strtolower( $parsed['host'] );

		// SSRF & private IP check
		if ( self::is_private_or_loopback( $host ) ) {
			return 'private or loopback host (SSRF blocked)';
		}

		// Domain match check
		if ( ! empty( $expected_domain ) ) {
			$expected_domain = strtolower( $expected_domain );
			if ( $host !== $expected_domain ) {
				return 'outside approved domain (' . esc_html( $host ) . ' != ' . esc_html( $expected_domain ) . ')';
			}
		}

		// Check against approved domain list
		if ( ! Shaheen_Source_Manager::is_domain_approved( $host ) ) {
			return 'domain is not an enabled approved source';
		}

		$path_and_query = ( isset( $parsed['path'] ) ? $parsed['path'] : '/' ) . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );

		// Check default exclusion patterns
		$patterns = self::get_all_excluded_patterns();
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( empty( $pattern ) ) {
				continue;
			}

			if ( @preg_match( '#' . $pattern . '#i', $path_and_query ) ) {
				return 'excluded path pattern: ' . $pattern;
			}
		}

		// Password protected or private URL queries
		if ( isset( $parsed['query'] ) && ( strpos( $parsed['query'], 'preview=true' ) !== false || strpos( $parsed['query'], 'post_password' ) !== false ) ) {
			return 'preview or password protected page';
		}

		return false;
	}

	/**
	 * Get combined default and user-configured excluded patterns.
	 *
	 * @return array
	 */
	public static function get_all_excluded_patterns() {
		$defaults = self::get_default_excluded_patterns();
		$custom_option = get_option( 'shaheen_sync_excluded_patterns', '' );

		if ( empty( $custom_option ) ) {
			return $defaults;
		}

		$lines = explode( "\n", str_replace( "\r", '', $custom_option ) );
		$custom = array();
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( ! empty( $trimmed ) ) {
				$custom[] = preg_quote( $trimmed, '#' );
			}
		}

		return array_unique( array_merge( $defaults, $custom ) );
	}

	/**
	 * Check if host/IP is loopback, private IP or internal.
	 *
	 * @param string $host
	 * @return bool
	 */
	public static function is_private_or_loopback( $host ) {
		$host = strtolower( trim( (string) $host ) );

		if ( empty( $host ) || 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return true;
		}

		// Reject internal/non-FQDN names without dot
		if ( strpos( $host, '.' ) === false ) {
			return true;
		}

		// Check if IP or resolve
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : @gethostbyname( $host );

		if ( ! empty( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate a canonical tag extracted from HTML.
	 *
	 * @param string $canonical_tag
	 * @param string $expected_domain
	 * @return string|false Validated canonical URL or false if invalid.
	 */
	public static function validate_canonical_tag( $canonical_tag, $expected_domain ) {
		if ( empty( $canonical_tag ) || ! is_string( $canonical_tag ) ) {
			return false;
		}

		$parsed = wp_parse_url( $canonical_tag );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}

		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return false; // HTTPS only
		}

		$host = strtolower( $parsed['host'] );
		if ( $host !== strtolower( $expected_domain ) ) {
			return false; // No external or cross-domain canonicals
		}

		if ( self::is_private_or_loopback( $host ) ) {
			return false; // No private/internal targets
		}

		return $canonical_tag;
	}

	/**
	 * Normalize a URL according to specification.
	 *
	 * @param string $url
	 * @param string $canonical_tag_url Optional canonical tag found in HTML.
	 * @param string $expected_domain
	 * @return string
	 */
	public static function normalize_url( $url, $canonical_tag_url = '', $expected_domain = '' ) {
		// Prefer valid canonical tag if it passes strict validation
		if ( ! empty( $canonical_tag_url ) && ! empty( $expected_domain ) ) {
			$validated_canonical = self::validate_canonical_tag( $canonical_tag_url, $expected_domain );
			if ( false !== $validated_canonical ) {
				$url = $validated_canonical;
			}
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return $url;
		}

		$scheme = ! empty( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'https';
		$host   = strtolower( $parsed['host'] );
		$path   = ! empty( $parsed['path'] ) ? $parsed['path'] : '/';

		// Normalize multiple slashes in path
		$path = preg_replace( '#/{2,}#', '/', $path );

		// Decode and re-encode path segments cleanly
		$segments = explode( '/', $path );
		$cleaned_segments = array();
		foreach ( $segments as $seg ) {
			if ( '' === $seg ) {
				$cleaned_segments[] = '';
			} else {
				$cleaned_segments[] = rawurlencode( rawurldecode( $seg ) );
			}
		}
		$path = implode( '/', $cleaned_segments );

		// Strip tracking parameters while preserving meaningful query parameters
		$clean_query = '';
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_params );
			$tracking_keys = self::get_tracking_parameters();

			foreach ( $tracking_keys as $tkey ) {
				unset( $query_params[ $tkey ] );
			}

			if ( ! empty( $query_params ) ) {
				ksort( $query_params );
				$clean_query = '?' . http_build_query( $query_params );
			}
		}

		// Ensure trailing slash for paths without file extensions
		if ( ! preg_match( '/\.[a-z0-9]{2,5}$/i', $path ) && substr( $path, -1 ) !== '/' ) {
			$path .= '/';
		}

		// Fragments are strictly removed
		return $scheme . '://' . $host . $path . $clean_query;
	}

	/**
	 * Generate stable record key from normalized canonical URL.
	 *
	 * @param string $normalized_url
	 * @return string
	 */
	public static function generate_record_key( $normalized_url ) {
		return hash( 'sha256', trim( (string) $normalized_url ) );
	}

	/**
	 * Check for canonical collision before inserting/updating a record.
	 *
	 * @param string $record_key
	 * @param int    $exclude_id
	 * @return bool True if collision exists, false otherwise.
	 */
	public static function has_canonical_collision( $record_key, $exclude_id = 0 ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		if ( $exclude_id > 0 ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$records_table} WHERE record_key = %s AND id != %d",
					$record_key,
					$exclude_id
				)
			);
		} else {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$records_table} WHERE record_key = %s",
					$record_key
				)
			);
		}

		return (bool) $exists;
	}
}
