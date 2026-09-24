<?php
/**
 * MXChat API Integration Architecture & Phase 2 Placeholder
 *
 * CRITICAL DELIVERY GUARANTEE:
 * Live MXChat HTTP posting and transcript fetching are 100% DISABLED
 * in Phase 1. No network requests are made to MXChat endpoints.
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_MXChat_Placeholder {

	/**
	 * Future target MXChat knowledge endpoint.
	 */
	const KNOWLEDGE_ENDPOINT = 'https://shaheengroup.org/wp-json/mxchat/v1/knowledge';

	/**
	 * Future transcript reading endpoint.
	 */
	const TRANSCRIPTS_ENDPOINT = 'https://shaheengroup.org/wp-json/mxchat/v1/transcripts';

	/**
	 * Phase 1 Lockout constant.
	 * Must remain TRUE throughout Phase 1.
	 */
	const IS_PHASE_1_LOCKED = true;

	/**
	 * Verify whether Phase 1 posting lock is active.
	 *
	 * @return bool Always true in Phase 1.
	 */
	public static function is_phase_1_locked() {
		return self::IS_PHASE_1_LOCKED;
	}

	/**
	 * Safely retrieve token from wp-config.php constant only.
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
	 * @param string $content
	 * @param string $source_url
	 * @param string $content_type
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
	 * Future Phase 2 send knowledge method - STRICTLY DISABLED IN PHASE 1.
	 *
	 * @param array $record
	 * @return WP_Error
	 */
	public static function send_knowledge_item( $record ) {
		return new WP_Error(
			'phase_1_posting_disabled',
			__( 'Phase 1 Delivery Notice: Live posting to MXChat is permanently locked and disabled in this release. No network request was executed.', 'shaheen-central-mxchat-sync' )
		);
	}

	/**
	 * Future transcript fetching method - STRICTLY DISABLED IN PHASE 1.
	 *
	 * @return WP_Error
	 */
	public static function fetch_transcripts() {
		return new WP_Error(
			'phase_1_transcripts_disabled',
			__( 'Phase 1 Delivery Notice: Transcript fetching from MXChat is disabled in this release.', 'shaheen-central-mxchat-sync' )
		);
	}
}
