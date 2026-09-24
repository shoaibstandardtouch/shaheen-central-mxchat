<?php
/**
 * Safe Activity Logger for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Logger {

	/**
	 * Log levels
	 */
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Log a message to the database table with strict secret redaction.
	 *
	 * @param string $message
	 * @param string $level
	 * @param array  $context
	 */
	public static function log( $message, $level = self::LEVEL_INFO, $context = array() ) {
		global $wpdb;

		$sanitized_message = self::redact_sensitive_data( $message );
		$sanitized_context = ! empty( $context ) ? wp_json_encode( self::redact_context( $context ) ) : null;

		$logs_table = Shaheen_DB::get_logs_table();

		$wpdb->insert(
			$logs_table,
			array(
				'level'      => sanitize_key( $level ),
				'message'    => sanitize_text_field( $sanitized_message ),
				'context'    => $sanitized_context,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( $level === self::LEVEL_ERROR ) {
			update_option( 'shaheen_sync_last_error', $sanitized_message );
		}

		self::prune_old_logs();
	}

	/**
	 * Log error helper.
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function error( $message, $context = array() ) {
		self::log( $message, self::LEVEL_ERROR, $context );
	}

	/**
	 * Log warning helper.
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function warning( $message, $context = array() ) {
		self::log( $message, self::LEVEL_WARNING, $context );
	}

	/**
	 * Log info helper.
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function info( $message, $context = array() ) {
		self::log( $message, self::LEVEL_INFO, $context );
	}

	/**
	 * Redact tokens, passwords, bearer headers, and cookies from strings.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function redact_sensitive_data( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		// Redact Bearer tokens
		$text = preg_replace( '/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [REDACTED]', $text );

		// Redact Authorization headers
		$text = preg_replace( '/(Authorization[:=]\s*)[^\s,]+/i', '$1[REDACTED]', $text );

		// Redact passwords or api keys in key-value patterns
		$text = preg_replace( '/(token|api_key|password|secret|pass|auth)[:=]\s*["\']?[A-Za-z0-9_\-\.]+["\']?/i', '$1=[REDACTED]', $text );

		// Redact cookies
		$text = preg_replace( '/(Cookie[:=]\s*)[^\r\n;]+/i', '$1[REDACTED]', $text );

		return $text;
	}

	/**
	 * Recursively redact array context.
	 *
	 * @param array $context
	 * @return array
	 */
	private static function redact_context( $context ) {
		$redacted = array();
		$sensitive_keys = array( 'token', 'authorization', 'api_key', 'secret', 'password', 'cookie', 'cookies', 'pass' );

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
				$redacted[ $key ] = '[REDACTED]';
			} elseif ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_context( $value );
			} elseif ( is_string( $value ) ) {
				$redacted[ $key ] = self::redact_sensitive_data( $value );
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}

	/**
	 * Get logs with pagination.
	 *
	 * @param int    $limit
	 * @param int    $offset
	 * @param string $level
	 * @return array
	 */
	public static function get_logs( $limit = 100, $offset = 0, $level = '' ) {
		global $wpdb;
		$logs_table = Shaheen_DB::get_logs_table();
		$limit      = absint( $limit );
		$offset     = absint( $offset );

		if ( ! empty( $level ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$logs_table} WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					sanitize_key( $level ),
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$logs_table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Get total count of logs.
	 *
	 * @param string $level
	 * @return int
	 */
	public static function get_logs_count( $level = '' ) {
		global $wpdb;
		$logs_table = Shaheen_DB::get_logs_table();

		if ( ! empty( $level ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$logs_table} WHERE level = %s",
					sanitize_key( $level )
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
	}

	/**
	 * Clear all logs.
	 */
	public static function clear_logs() {
		global $wpdb;
		$logs_table = Shaheen_DB::get_logs_table();
		$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
		update_option( 'shaheen_sync_last_error', 'None' );
	}

	/**
	 * Prune old logs keeping max 1000 entries.
	 */
	private static function prune_old_logs() {
		global $wpdb;
		$logs_table = Shaheen_DB::get_logs_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
		if ( $count > 1000 ) {
			$wpdb->query( "DELETE FROM {$logs_table} ORDER BY id ASC LIMIT 200" );
		}
	}
}
