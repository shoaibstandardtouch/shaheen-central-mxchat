<?php
/**
 * Page Fetcher with Safe HTTP, Anti-SSRF, and Exponential Backoff Retry
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Page_Fetcher {

	/**
	 * Fetch a public page with retry and validation.
	 *
	 * @param string $url
	 * @param string $expected_domain
	 * @return array
	 */
	public static function fetch( $url, $expected_domain ) {
		// Enforce HTTPS
		if ( strpos( strtolower( $url ), 'https://' ) !== 0 ) {
			return array(
				'success' => false,
				'error'   => 'HTTPS required for all page fetching.',
				'status'  => 'rejected',
			);
		}

		// Enforce SSRF & Approved Domain Check
		$skip_reason = Shaheen_URL_Filter::should_skip_url( $url, $expected_domain );
		if ( false !== $skip_reason ) {
			return array(
				'success' => false,
				'error'   => 'URL skipped: ' . $skip_reason,
				'status'  => 'skipped',
			);
		}

		$max_retries = absint( get_option( 'shaheen_sync_retry_count', 3 ) );
		$base_delay  = absint( get_option( 'shaheen_sync_retry_delay', 1 ) ); // in seconds
		$timeout     = absint( get_option( 'shaheen_sync_timeout', 15 ) );
		$max_size    = absint( get_option( 'shaheen_sync_max_page_size', 3145728 ) ); // 3MB
		$user_agent  = get_option( 'shaheen_sync_user_agent', 'ShaheenCentralMXChatSync/1.0 (+https://shaheengroup.org)' );

		$attempts = 0;
		$retry_codes = array( 429, 500, 502, 503, 504 );

		while ( $attempts <= $max_retries ) {
			$attempts++;

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'user-agent'  => $user_agent,
					'redirection' => 5,
					'sslverify'   => true,
					'headers'     => array(
						'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
						'Accept-Language' => 'en-US,en;q=0.9,ar;q=0.8',
					),
				)
			);

			// Handle connection errors & timeouts
			if ( is_wp_error( $response ) ) {
				$err_msg = $response->get_error_message();
				Shaheen_Logger::warning( "Fetch attempt {$attempts} error for {$url}: {$err_msg}" );

				if ( $attempts <= $max_retries ) {
					$sleep_time = $base_delay * pow( 2, $attempts - 1 );
					sleep( $sleep_time );
					continue;
				}

				return array(
					'success' => false,
					'error'   => 'Network timeout or error: ' . $err_msg,
					'status'  => 'error',
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Check for retryable HTTP status codes
			if ( in_array( $code, $retry_codes, true ) ) {
				Shaheen_Logger::warning( "Fetch attempt {$attempts} returned HTTP {$code} for {$url}" );

				if ( $attempts <= $max_retries ) {
					// Check for Retry-After header
					$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
					if ( ! empty( $retry_after ) && is_numeric( $retry_after ) && (int) $retry_after < 15 ) {
						$sleep_time = (int) $retry_after;
					} else {
						$sleep_time = $base_delay * pow( 2, $attempts - 1 );
					}

					sleep( $sleep_time );
					continue;
				}

				return array(
					'success' => false,
					'error'   => "HTTP {$code} received after {$max_retries} retries",
					'status'  => 'error',
				);
			}

			// Non-200 permanent error (404, 401, 403, etc.) - do not retry
			if ( 200 !== $code ) {
				return array(
					'success' => false,
					'error'   => "Permanent HTTP {$code} response",
					'status'  => 'rejected',
				);
			}

			// Validate Content-Type
			$content_type = wp_remote_retrieve_header( $response, 'content-type' );
			if ( empty( $content_type ) || strpos( strtolower( $content_type ), 'text/html' ) === false ) {
				return array(
					'success' => false,
					'error'   => 'Non-HTML content type: ' . esc_html( (string) $content_type ),
					'status'  => 'rejected',
				);
			}

			$body = wp_remote_retrieve_body( $response );

			// Check response size
			if ( strlen( $body ) > $max_size ) {
				return array(
					'success' => false,
					'error'   => 'Response body exceeded maximum allowed page size (' . $max_size . ' bytes)',
					'status'  => 'rejected',
				);
			}

			// Validate final effective URL after redirects
			// In WordPress HTTP API, response cookies or headers might contain redirect info
			// Let's verify that the host hasn't left the approved domain
			$effective_url = wp_remote_retrieve_header( $response, 'location' );
			if ( ! empty( $effective_url ) ) {
				$redirect_host = strtolower( (string) wp_parse_url( $effective_url, PHP_URL_HOST ) );
				if ( ! empty( $redirect_host ) && $redirect_host !== strtolower( $expected_domain ) ) {
					return array(
						'success' => false,
						'error'   => 'Redirect left approved domain to: ' . esc_html( $redirect_host ),
						'status'  => 'rejected',
					);
				}
			}

			return array(
				'success' => true,
				'body'    => $body,
				'headers' => wp_remote_retrieve_headers( $response ),
			);
		}

		return array(
			'success' => false,
			'error'   => 'Exceeded maximum fetch attempts',
			'status'  => 'error',
		);
	}
}
