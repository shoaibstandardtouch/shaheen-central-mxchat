<?php
/**
 * Plugin Name:       Shaheen Central MXChat Sync
 * Plugin URI:        https://shaheengroup.org
 * Description:       Synchronize approved Shaheen Group websites into one central MXChat knowledge base. Phase 1: Sitemap crawler, URL filtering, page fetching, content extraction & cleaning, classification, duplicate detection, SHA-256 hashing, dry-run dashboard, and daily WP-Cron preparation.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Shaheen Group
 * Author URI:        https://shaheengroup.org
 * Text Domain:       shaheen-central-mxchat-sync
 * Domain Path:       /languages
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'SHAHEEN_SYNC_VERSION', '1.0.0' );
define( 'SHAHEEN_SYNC_PLUGIN_FILE', __FILE__ );
define( 'SHAHEEN_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHAHEEN_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHAHEEN_SYNC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SHAHEEN_SYNC_PHASE_1_ONLY', true );

// Require all core classes
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-db.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-logger.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-source-manager.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-url-filter.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-page-fetcher.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-content-extractor.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-classifier.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-sync-engine.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-cron.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-exporter.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-mxchat-placeholder.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-activator.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-deactivator.php';
require_once SHAHEEN_SYNC_PLUGIN_DIR . 'includes/class-shaheen-admin.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'Shaheen_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Shaheen_Deactivator', 'deactivate' ) );

// Initialize plugin
function shaheen_sync_init() {
	// Initialize scheduled cron runner
	Shaheen_Cron::init();

	// Initialize admin dashboard
	if ( is_admin() ) {
		Shaheen_Admin::init();
	}
}
add_action( 'plugins_loaded', 'shaheen_sync_init' );
