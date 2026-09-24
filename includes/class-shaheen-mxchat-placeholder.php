<?php
/**
 * MXChat API Integration Architecture & Phase 2 Placeholder
 *
 * IMPORTANT NOTE FOR PHASE 1:
 * In accordance with Phase 1 safety rules, live MXChat HTTP posting
 * is STRICTLY DISABLED in this version. No outbound API requests to MXChat
 * are executed under any circumstances.
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_MXChat_Placeholder {

	/**
	 * Future target MXChat endpoint.
	 */
	const LIVE_ENDPOINT = 'https://shaheengroup.org/wp-json/mxchat/v1/knowledge';

	/**
	 * Phase 1 Lock constant.
	 * Must remain TRUE throughout Phase 1.
	 */
	const IS_PHASE_1_LOCKED = true;

	/**
	 * Check whether MXChat live posting is locked.
	 *
	 * @return bool Always true in Phase 1.
	 */
	public static function is_phase_1_locked() {
		return self::IS_PHASE_1_LOCKED;
	}

	/**
	 * Retrieve token securely from wp-config.php constant only.
	 * Never reads from database, never exposes in logs or UI.
	 *
	 * @return string|false
	 */
	public static function get_api_token() {
		if ( defined( 'SHAHEEN_MXCHAT_API_TOKEN' ) && ! empty( constant( 'SHAHEEN_MXCHAT_API_TOKEN' ) ) ) {
			return (string) constant( 'SHAHEEN_MXCHAT_API_TOKEN' );
		}
		return false;
	}

	/**
	 * Format payload according to MXChat API specifications.
	 * Supported fields: content, source_url, content_type
	 *
	 * @param string $content Cleaned content with source context header
	 * @param string $source_url Normalized canonical URL
	 * @param string $content_type One of: page, programme, admissions, FAQ, post
	 * @return array
	 */
	public static function build_payload( $content, $source_url, $content_type ) {
		return array(
			'content'      => (string) $content,
			'source_url'   => esc_url_raw( $source_url ),
			'content_type' => strtolower( sanitize_key( $content_type ) ),
		);
	}

	/**
	 * Phase 2 send method - STRICTLY DISABLED IN PHASE 1.
	 *
	 * @param array $record
	 * @return WP_Error
	 */
	public static function send_knowledge_item( $record ) {
		// Strict Phase 1 guard
		return new WP_Error(
			'phase_1_posting_disabled',
			__( 'MXChat live posting is completely disabled in Phase 1. No network requests are made to MXChat.', 'shaheen-central-mxchat-sync' )
		);
	}
}
