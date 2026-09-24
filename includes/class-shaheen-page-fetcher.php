<?php
/**
 * Safe Page Fetcher with Controlled Redirects, Anti-SSRF, and Exponential Backoff Retry
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Page_Fetcher {

	/**
	 * Fetch a public page or sitemap using controlled manual redirects and SSRF validation on every hop.
	 *
	 * @param string $initial_url
	 * @param string $expected_domain
	 * @param bool   $require_html
	 * @return array
	 */
	public static function fetch_with_controlled_redirects( $initial_url, $expected_domain, $require_html = false ) {
		// Enforce HTTPS
		if ( strpos( strtolower( $initial_url ), 'https://' ) !== 0 ) {
			return array(
				'success' => false,
				'error'   => 'HTTPS required for all requests.',
				'status'  => 'rejected',
			);
		}

		$current_url = $initial_url;
		$redirect_count = 0;
		$max_redirects  = 5;
		$visited_urls   = array();

		$timeout    = absint( get_option( 'shaheen_sync_timeout', 15 ) );
		$max_size   = absint( get_option( 'shaheen_sync_max_page_size', 3145728 ) ); // 3MB
		$user_agent = get_option( 'shaheen_sync_user_agent', 'ShaheenCentralMXChatSync/1.0 (+https://shaheengroup.org)' );

		while ( $redirect_count <= $max_redirects ) {
			// Enforce SSRF & Domain Validation on EVERY hop
			$skip_reason = Shaheen_URL_Filter::should_skip_url( $current_url, $expected_domain );
			if ( false !== $skip_reason && strpos( $skip_reason, 'excluded path' ) === false ) {
				return array(
					'success' => false,
					'error'   => 'Destination blocked: ' . $skip_reason,
					'status'  => 'rejected',
				);
			}

			// Detect redirect loops
			if ( in_array( $current_url, $visited_urls, true ) ) {
				return array(
					'success' => false,
					'error'   => 'Redirect loop detected at: ' . esc_html( $current_url ),
					'status'  => 'rejected',
				);
			}
			$visited_urls[] = $current_url;

			// Perform HTTP request with redirection disabled (manual controlled redirect)
			$response = self::execute_single_request_with_retry( $current_url, $timeout, $user_agent );

			if ( ! $response['success'] ) {
				return $response;
			}

			$code = $response['code'];

			// Handle HTTP 3xx Redirects manually
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$redirect_count++;
				if ( $redirect_count > $max_redirects ) {
					return array(
						'success' => false,
						'error'   => 'Exceeded maximum redirect limit (5 hops).',
						'status'  => 'rejected',
					);
				}

				$location = isset( $response['headers']['location'] ) ? $response['headers']['location'] : '';
				if ( empty( $location ) ) {
					return array(
						'success' => false,
						'error'   => 'Redirect response missing Location header.',
						'status'  => 'rejected',
					);
				}

				// Resolve relative redirect URL
				$resolved_url = self::resolve_relative_url( $current_url, $location );
				if ( ! $resolved_url ) {
					return array(
						'success' => false,
						'error'   => 'Invalid redirect Location target: ' . esc_html( $location ),
						'status'  => 'rejected',
					);
				}

				// Check that redirect destination has not left approved domain
				$target_host = strtolower( (string) wp_parse_url( $resolved_url, PHP_URL_HOST ) );
				if ( $target_host !== strtolower( $expected_domain ) ) {
					return array(
						'success' => false,
						'error'   => 'Cross-domain redirect rejected (attempted redirect to ' . esc_html( $target_host ) . ').',
						'status'  => 'rejected',
					);
				}

				$current_url = $resolved_url;
				continue;
			}

			// Final response checks
			if ( 200 !== $code ) {
				// Detect private or password protected status codes
				if ( 401 === $code || 403 === $code ) {
					return array(
						'success' => false,
						'error'   => "Private or password-protected content (HTTP {$code})",
						'status'  => 'rejected',
					);
				}

				return array(
					'success' => false,
					'error'   => "HTTP {$code} response",
					'status'  => 'rejected',
				);
			}

			$body = $response['body'];

			// Validate maximum response size
			if ( strlen( $body ) > $max_size ) {
				return array(
					'success' => false,
					'error'   => 'Response body exceeded maximum allowed size (' . $max_size . ' bytes)',
					'status'  => 'rejected',
				);
			}

			// Validate Content-Type if HTML is required
			if ( $require_html ) {
				$content_type = isset( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : '';
				if ( empty( $content_type ) || strpos( strtolower( (string) $content_type ), 'text/html' ) === false ) {
					return array(
						'success' => false,
						'error'   => 'Non-HTML content type: ' . esc_html( (string) $content_type ),
						'status'  => 'rejected',
					);
				}

				// Check for WordPress password form or protected page indicators in HTML
				if ( self::is_password_protected_content( $body ) ) {
					return array(
						'success' => false,
						'error'   => 'Page contains password protection form or private prompt.',
						'status'  => 'rejected',
					);
				}
			}

			return array(
				'success'       => true,
				'body'          => $body,
				'effective_url' => $current_url,
				'headers'       => $response['headers'],
			);
		}

		return array(
			'success' => false,
			'error'   => 'Exceeded maximum redirects',
			'status'  => 'rejected',
		);
	}

	/**
	 * Backward compatible fetch wrapper.
	 *
	 * @param string $url
	 * @param string $expected_domain
	 * @return array
	 */
	public static function fetch( $url, $expected_domain ) {
		return self::fetch_with_controlled_redirects( $url, $expected_domain, true );
	}

	/**
	 * Execute single HTTP request with bounded exponential backoff for 429 and 5xx errors.
	 *
	 * @param string $url
	 * @param int    $timeout
	 * @param string $user_agent
	 * @return array
	 */
	private static function execute_single_request_with_retry( $url, $timeout, $user_agent ) {
		$max_retries = absint( get_option( 'shaheen_sync_retry_count', 3 ) );
		$base_delay  = absint( get_option( 'shaheen_sync_retry_delay', 1 ) );
		$attempts    = 0;
		$retry_codes = array( 429, 500, 502, 503, 504 );

		while ( $attempts <= $max_retries ) {
			$attempts++;

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'user-agent'  => $user_agent,
					'redirection' => 0, // Controlled manual redirect
					'sslverify'   => true,
					'headers'     => array(
						'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
						'Accept-Language' => 'en-US,en;q=0.9,ar;q=0.8',
					),
				)
			);

			// Handle connection errors & timeouts
			if ( is_wp_error( $response ) ) {
				$err_msg = $response->get_error_message();
				Shaheen_Logger::warning( "HTTP attempt {$attempts} error for {$url}: {$err_msg}" );

				if ( $attempts <= $max_retries ) {
					// Bounded delay: min 1s, max 3s to prevent PHP timeout
					$sleep_time = min( 3, $base_delay * $attempts );
					sleep( $sleep_time );
					continue;
				}

				return array(
					'success' => false,
					'error'   => 'Network timeout or connection error: ' . $err_msg,
					'status'  => 'error',
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Check retryable status codes
			if ( in_array( $code, $retry_codes, true ) ) {
				Shaheen_Logger::warning( "HTTP attempt {$attempts} returned status {$code} for {$url}" );

				if ( $attempts <= $max_retries ) {
					$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
					if ( ! empty( $retry_after ) && is_numeric( $retry_after ) && (int) $retry_after <= 5 ) {
						$sleep_time = (int) $retry_after;
					} else {
						$sleep_time = min( 3, $base_delay * $attempts );
					}

					sleep( $sleep_time );
					continue;
				}

				return array(
					'success' => false,
					'error'   => "HTTP {$code} received after {$max_retries} attempts",
					'status'  => 'error',
				);
			}

			return array(
				'success' => true,
				'code'    => $code,
				'body'    => wp_remote_retrieve_body( $response ),
				'headers' => wp_remote_retrieve_headers( $response ),
			);
		}

		return array(
			'success' => false,
			'error'   => 'Exceeded maximum fetch attempts',
			'status'  => 'error',
		);
	}

	/**
	 * Detect if page contains password protection form or login prompt.
	 *
	 * @param string $html
	 * @return bool
	 */
	public static function is_password_protected_content( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return false;
		}

		$patterns = array(
			'post_password',
			'post-password-form',
			'This content is password protected',
			'To view this protected post, enter the password',
			'wp-login.php?action=postpass',
			'class="password-protect',
		);

		foreach ( $patterns as $pattern ) {
			if ( stripos( $html, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @param string $base_url
	 * @param string $relative_path
	 * @return string|false
	 */
	public static function resolve_relative_url( $base_url, $relative_path ) {
		$relative_path = trim( (string) $relative_path );

		// If already absolute with scheme
		if ( preg_match( '#^https?://#i', $relative_path ) ) {
			return $relative_path;
		}

		$parsed_base = wp_parse_url( $base_url );
		if ( ! $parsed_base || empty( $parsed_base['host'] ) ) {
			return false;
		}

		$scheme = ! empty( $parsed_base['scheme'] ) ? strtolower( $parsed_base['scheme'] ) : 'https';
		$host   = strtolower( $parsed_base['host'] );

		// Protocol-relative //domain/path
		if ( strpos( $relative_path, '//' ) === 0 ) {
			return $scheme . ':' . $relative_path;
		}

		// Root-relative /path
		if ( strpos( $relative_path, '/' ) === 0 ) {
			return $scheme . '://' . $host . $relative_path;
		}

		// Directory-relative path
		$base_path = isset( $parsed_base['path'] ) ? $parsed_base['path'] : '/';
		if ( substr( $base_path, -1 ) === '/' ) {
			$dir = $base_path;
		} else {
			$dir = dirname( $base_path );
			$dir = str_replace( '\\', '/', $dir );
			if ( '.' === $dir || '/' === $dir ) {
				$dir = '/';
			} else {
				$dir = rtrim( $dir, '/' ) . '/';
			}
		}

		return $scheme . '://' . $host . $dir . $relative_path;
	}
}
