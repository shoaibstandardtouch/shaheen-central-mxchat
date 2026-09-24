<?php
/**
 * Fired during plugin deactivation
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Deactivator {

	/**
	 * Run deactivation logic: clear scheduled cron and release locks.
	 */
	public static function deactivate() {
		// 1. Unschedule daily cron
		Shaheen_Cron::clear_event();

		// 2. Release sync lock
		Shaheen_Sync_Engine::release_lock();

		Shaheen_Logger::info( 'Shaheen Central MXChat Sync deactivated.' );
	}
}
