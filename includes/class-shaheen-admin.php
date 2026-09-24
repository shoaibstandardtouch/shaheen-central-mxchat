<?php
/**
 * Administrator Interface and AJAX Handlers for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Admin {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_shaheen_start_crawl', array( __CLASS__, 'ajax_start_crawl' ) );
		add_action( 'wp_ajax_shaheen_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_shaheen_get_preview', array( __CLASS__, 'ajax_get_preview' ) );
		add_action( 'wp_ajax_shaheen_retry_record', array( __CLASS__, 'ajax_retry_record' ) );
		add_action( 'wp_ajax_shaheen_validate_source', array( __CLASS__, 'ajax_validate_source' ) );
		add_action( 'wp_ajax_shaheen_dry_test_source', array( __CLASS__, 'ajax_dry_test_source' ) );
		add_action( 'wp_ajax_shaheen_approve_source', array( __CLASS__, 'ajax_approve_source' ) );
		add_action( 'wp_ajax_shaheen_archive_source', array( __CLASS__, 'ajax_archive_source' ) );
		add_action( 'wp_ajax_shaheen_toggle_source', array( __CLASS__, 'ajax_toggle_source' ) );
		add_action( 'wp_ajax_shaheen_clear_logs', array( __CLASS__, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_shaheen_release_lock', array( __CLASS__, 'ajax_release_lock' ) );

		// Form Handlers
		add_action( 'admin_post_shaheen_save_source', array( __CLASS__, 'handle_save_source' ) );
		add_action( 'admin_post_shaheen_delete_source', array( __CLASS__, 'handle_delete_source' ) );
		add_action( 'admin_post_shaheen_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_shaheen_export_csv', array( 'Shaheen_Exporter', 'export_csv' ) );
	}

	/**
	 * Register admin menu.
	 */
	public static function register_admin_menu() {
		add_menu_page(
			__( 'Shaheen Central Sync', 'shaheen-central-mxchat-sync' ),
			__( 'Shaheen Central Sync', 'shaheen-central-mxchat-sync' ),
			'manage_options',
			'shaheen-central-sync',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-cloud-saved',
			30
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_shaheen-central-sync' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'shaheen-admin-css',
			SHAHEEN_SYNC_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SHAHEEN_SYNC_VERSION
		);

		wp_enqueue_script(
			'shaheen-admin-js',
			SHAHEEN_SYNC_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			SHAHEEN_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'shaheen-admin-js',
			'shaheenSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'shaheen_admin_nonce' ),
				'strings' => array(
					'confirmDelete'   => __( 'Are you sure you want to permanently delete this source and all its records? This cannot be undone.', 'shaheen-central-mxchat-sync' ),
					'confirmArchive'  => __( 'Are you sure you want to archive this source? It will be disabled from future syncs while preserving historical records.', 'shaheen-central-mxchat-sync' ),
					'confirmClear'    => __( 'Are you sure you want to clear all activity and error logs?', 'shaheen-central-mxchat-sync' ),
					'crawling'        => __( 'Crawling approved source sitemaps...', 'shaheen-central-mxchat-sync' ),
					'processing'      => __( 'Analyzing pages in dry-run batches...', 'shaheen-central-mxchat-sync' ),
					'complete'        => __( 'Batch run completed.', 'shaheen-central-mxchat-sync' ),
					'limitReached'    => __( 'Maximum pages for this run reached. Remaining pages will be processed in a future run.', 'shaheen-central-mxchat-sync' ),
					'validating'      => __( 'Validating domain and sitemap...', 'shaheen-central-mxchat-sync' ),
					'testing'         => __( 'Running dry test crawl...', 'shaheen-central-mxchat-sync' ),
				),
			)
		);
	}

	/**
	 * Render main administrative interface.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'shaheen-central-mxchat-sync' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$stats       = Shaheen_DB::get_stats();

		$notice = isset( $_GET['notice'] ) ? sanitize_text_field( $_GET['notice'] ) : '';
		$error  = isset( $_GET['error'] ) ? sanitize_text_field( $_GET['error'] ) : '';

		?>
		<div class="wrap shaheen-wrap">
			<div class="shaheen-header">
				<h1>
					<span class="dashicons dashicons-cloud-saved"></span>
					<?php esc_html_e( 'Shaheen Central MXChat Sync', 'shaheen-central-mxchat-sync' ); ?>
					<span class="shaheen-badge-phase">Phase 1 &bull; Dry Run Only</span>
				</h1>
				<p class="shaheen-subtitle">
					<?php esc_html_e( 'Central knowledge synchronization pipeline for Shaheen Group source websites. Serves the single central chatbot on shaheengroup.org.', 'shaheen-central-mxchat-sync' ); ?>
				</p>
			</div>

			<?php if ( ! empty( $notice ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( urldecode( $notice ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $error ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( urldecode( $error ) ); ?></p></div>
			<?php endif; ?>

			<!-- Phase 1 Strict Guard Banner -->
			<div class="shaheen-alert-box shaheen-alert-info">
				<div class="shaheen-alert-icon"><span class="dashicons dashicons-shield-alt"></span></div>
				<div class="shaheen-alert-content">
					<strong><?php esc_html_e( 'Phase 1 Dry Run — MXChat posting is disabled.', 'shaheen-central-mxchat-sync' ); ?></strong><br>
					<?php esc_html_e( 'No outbound requests are made to https://shaheengroup.org/wp-json/mxchat/v1/knowledge. All crawl, DOM cleaning, classification, and change detection procedures run strictly in dry-run mode for administrative audit and approval.', 'shaheen-central-mxchat-sync' ); ?>
				</div>
			</div>

			<!-- 12 Admin Tabs Navigation -->
			<nav class="nav-tab-wrapper shaheen-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=dashboard' ) ); ?>" class="nav-tab <?php echo $current_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Dashboard', 'shaheen-central-mxchat-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=sources' ) ); ?>" class="nav-tab <?php echo $current_tab === 'sources' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Source Websites', 'shaheen-central-mxchat-sync' ); ?> (<?php echo esc_html( $stats['enabled_sources'] ); ?>)
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=discovered' ) ); ?>" class="nav-tab <?php echo $current_tab === 'discovered' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Discovered URLs', 'shaheen-central-mxchat-sync' ); ?> (<?php echo esc_html( $stats['total_discovered'] ); ?>)
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=skipped' ) ); ?>" class="nav-tab <?php echo $current_tab === 'skipped' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Skipped URLs', 'shaheen-central-mxchat-sync' ); ?> (<?php echo esc_html( $stats['skipped'] ); ?>)
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=new_content' ) ); ?>" class="nav-tab <?php echo $current_tab === 'new_content' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'New Content', 'shaheen-central-mxchat-sync' ); ?> (<?php echo esc_html( $stats['new_records'] ); ?>)
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=unchanged' ) ); ?>" class="nav-tab <?php echo $current_tab === 'unchanged' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Unchanged', 'shaheen-central-mxchat-sync' ); ?> (<?php echo esc_html( $stats['unchanged'] ); ?>)
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=pending' ) ); ?>" class="nav-tab <?php echo $current_tab === 'pending' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-flag"></span> <?php esc_html_e( 'Pending Review', 'shaheen-central-mxchat-sync' ); ?>
					<?php if ( $stats['pending_review'] > 0 ) : ?>
						<span class="shaheen-count-bubble"><?php echo esc_html( $stats['pending_review'] ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=errors' ) ); ?>" class="nav-tab <?php echo $current_tab === 'errors' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Errors', 'shaheen-central-mxchat-sync' ); ?>
					<?php if ( $stats['errors'] > 0 ) : ?>
						<span class="shaheen-count-bubble-error"><?php echo esc_html( $stats['errors'] ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=knowledge_queue' ) ); ?>" class="nav-tab <?php echo $current_tab === 'knowledge_queue' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Knowledge Improvement', 'shaheen-central-mxchat-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=logs' ) ); ?>" class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-media-text"></span> <?php esc_html_e( 'Logs', 'shaheen-central-mxchat-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=settings' ) ); ?>" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Settings', 'shaheen-central-mxchat-sync' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shaheen-central-sync&tab=instructions' ) ); ?>" class="nav-tab <?php echo $current_tab === 'instructions' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Chatbot Instructions', 'shaheen-central-mxchat-sync' ); ?>
				</a>
			</nav>

			<div class="shaheen-tab-content">
				<?php
				switch ( $current_tab ) {
					case 'sources':
						self::render_tab_sources();
						break;
					case 'discovered':
						self::render_tab_records( 'discovered' );
						break;
					case 'skipped':
						self::render_tab_records( 'skipped' );
						break;
					case 'new_content':
						self::render_tab_records( 'new' );
						break;
					case 'unchanged':
						self::render_tab_records( 'unchanged' );
						break;
					case 'pending':
						self::render_tab_pending();
						break;
					case 'errors':
						self::render_tab_records( 'error' );
						break;
					case 'knowledge_queue':
						self::render_tab_knowledge_queue();
						break;
					case 'logs':
						self::render_tab_logs();
						break;
					case 'settings':
						self::render_tab_settings();
						break;
					case 'instructions':
						self::render_tab_instructions();
						break;
					case 'dashboard':
					default:
						self::render_tab_dashboard( $stats );
						break;
				}
				?>
			</div>
		</div>

		<!-- Preview / Comparison Modal -->
		<div id="shaheen-preview-modal" class="shaheen-modal-overlay" style="display:none;">
			<div class="shaheen-modal-container">
				<div class="shaheen-modal-header">
					<h3 id="shaheen-modal-title"><?php esc_html_e( 'Content Preview & Baseline Comparison', 'shaheen-central-mxchat-sync' ); ?></h3>
					<button type="button" class="shaheen-modal-close">&times;</button>
				</div>
				<div class="shaheen-modal-body" id="shaheen-modal-body">
					<div class="shaheen-loading-spinner"><?php esc_html_e( 'Loading details...', 'shaheen-central-mxchat-sync' ); ?></div>
				</div>
				<div class="shaheen-modal-footer">
					<button type="button" class="button button-secondary shaheen-modal-close"><?php esc_html_e( 'Close', 'shaheen-central-mxchat-sync' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 1: Dashboard
	 */
	private static function render_tab_dashboard( $stats ) {
		?>
		<div class="shaheen-dashboard-grid">
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['mode'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Current Mode', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['enabled_sources'] ); ?> / <?php echo esc_html( $stats['approved_sources'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Enabled / Approved Sources', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['sources_waiting_validation'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Waiting for Validation', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['total_discovered'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Discovered URLs', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['skipped'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Skipped URLs', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['new_records'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'New Pages', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num"><?php echo esc_html( $stats['unchanged'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Unchanged Pages', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num shaheen-text-warning"><?php echo esc_html( $stats['pending_review'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Pending Updates / Review', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num shaheen-text-warning"><?php echo esc_html( $stats['sensitive_pages'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Sensitive Pages', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
			<div class="shaheen-card shaheen-stat-card">
				<div class="shaheen-stat-num shaheen-text-danger"><?php echo esc_html( $stats['errors'] ); ?></div>
				<div class="shaheen-stat-label"><?php esc_html_e( 'Errors Encountered', 'shaheen-central-mxchat-sync' ); ?></div>
			</div>
		</div>

		<!-- Action Control Panel -->
		<div class="shaheen-card shaheen-actions-card">
			<h2><?php esc_html_e( 'Dry-Run Crawl & Analysis Actions', 'shaheen-central-mxchat-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Trigger sitemap discovery and controlled batch analysis. All actions execute locally in dry-run mode without sending any data to MXChat.', 'shaheen-central-mxchat-sync' ); ?>
			</p>

			<div class="shaheen-btn-group">
				<button type="button" id="btn-run-crawl" class="button button-primary button-hero">
					<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Dry Run All Enabled Sources', 'shaheen-central-mxchat-sync' ); ?>
				</button>
				<button type="button" id="btn-process-batch" class="button button-secondary button-hero">
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Process Next Batch', 'shaheen-central-mxchat-sync' ); ?>
				</button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="shaheen_export_csv">
					<?php wp_nonce_field( 'shaheen_export_csv', 'nonce' ); ?>
					<button type="submit" class="button button-secondary button-hero">
						<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export Dry-Run CSV Report', 'shaheen-central-mxchat-sync' ); ?>
					</button>
				</form>
				<?php if ( $stats['is_running'] ) : ?>
					<button type="button" id="btn-release-lock" class="button button-link-delete">
						<?php esc_html_e( 'Release Stale Lock', 'shaheen-central-mxchat-sync' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Live Progress UI -->
			<div id="shaheen-progress-container" style="display:none; margin-top:20px;">
				<div class="shaheen-progress-bar-bg">
					<div id="shaheen-progress-bar-fill" class="shaheen-progress-bar-fill" style="width:0%;"></div>
				</div>
				<div id="shaheen-progress-status" class="shaheen-progress-status">
					<?php esc_html_e( 'Initializing...', 'shaheen-central-mxchat-sync' ); ?>
				</div>
			</div>
		</div>

		<!-- Sync Execution & Scheduling Status -->
		<div class="shaheen-card">
			<h3><?php esc_html_e( 'Execution & Scheduling Status', 'shaheen-central-mxchat-sync' ); ?></h3>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th style="width:250px;"><?php esc_html_e( 'Running Status', 'shaheen-central-mxchat-sync' ); ?></th>
						<td>
							<?php if ( $stats['is_running'] ) : ?>
								<span class="shaheen-status-tag shaheen-status-pending"><?php esc_html_e( 'Running (Locked)', 'shaheen-central-mxchat-sync' ); ?></span>
							<?php else : ?>
								<span class="shaheen-status-tag shaheen-status-unchanged"><?php esc_html_e( 'Idle', 'shaheen-central-mxchat-sync' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Sync Time', 'shaheen-central-mxchat-sync' ); ?></th>
						<td><?php echo esc_html( $stats['last_sync'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Next Scheduled WP-Cron', 'shaheen-central-mxchat-sync' ); ?></th>
						<td><?php echo esc_html( $stats['next_sync'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Error Message', 'shaheen-central-mxchat-sync' ); ?></th>
						<td><code><?php echo esc_html( $stats['last_error'] ); ?></code></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab 2: Source Websites Management & Onboarding
	 */
	private static function render_tab_sources() {
		$sources = Shaheen_Source_Manager::get_all_sources();
		?>
		<div class="shaheen-sources-view">
			<div class="shaheen-card">
				<h2><?php esc_html_e( 'Approved & Onboarding Source Websites', 'shaheen-central-mxchat-sync' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Every Shaheen website must be manually added, validated, and approved by an administrator before scheduled crawling can be enabled. Off-domain redirects and private IP ranges are strictly blocked for SSRF protection.', 'shaheen-central-mxchat-sync' ); ?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'shaheen-central-mxchat-sync' ); ?></th>
							<th><?php esc_html_e( 'Institution Name', 'shaheen-central-mxchat-sync' ); ?></th>
							<th><?php esc_html_e( 'Domain', 'shaheen-central-mxchat-sync' ); ?></th>
							<th><?php esc_html_e( 'Category / Language', 'shaheen-central-mxchat-sync' ); ?></th>
							<th><?php esc_html_e( 'Lifecycle Status', 'shaheen-central-mxchat-sync' ); ?></th>
							<th><?php esc_html_e( 'Sync Enabled', 'shaheen-central-mxchat-sync' ); ?></th>
							<th><?php esc_html_e( 'Last Crawled', 'shaheen-central-mxchat-sync' ); ?></th>
							<th style="width:280px;"><?php esc_html_e( 'Actions', 'shaheen-central-mxchat-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $sources ) ) : ?>
							<?php foreach ( $sources as $src ) : ?>
								<tr>
									<td><?php echo esc_html( $src['id'] ); ?></td>
									<td>
										<strong><?php echo esc_html( $src['institution'] ); ?></strong><br>
										<small><a href="<?php echo esc_url( $src['sitemap_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $src['sitemap_url'] ); ?></a></small>
									</td>
									<td><code><?php echo esc_html( $src['domain'] ); ?></code></td>
									<td>
										<?php echo esc_html( $src['category'] ); ?> &bull; <em><?php echo esc_html( isset( $src['language'] ) ? $src['language'] : 'English' ); ?></em>
									</td>
									<td>
										<span class="shaheen-source-status shaheen-status-<?php echo esc_attr( $src['status'] ); ?>">
											<?php echo esc_html( strtoupper( $src['status'] ) ); ?>
										</span>
									</td>
									<td>
										<button type="button" class="button button-small btn-toggle-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
											<?php echo (int) $src['is_enabled'] === 1 ? '<span class="shaheen-badge-enabled">Yes (Enabled)</span>' : '<span class="shaheen-badge-disabled">No (Disabled)</span>'; ?>
										</button>
									</td>
									<td><?php echo $src['last_crawled_at'] ? esc_html( $src['last_crawled_at'] ) : 'Never'; ?></td>
									<td>
										<button type="button" class="button button-small btn-validate-source" data-id="<?php echo esc_attr( $src['id'] ); ?>" title="Verify HTTPS and sitemap reachability">
											<?php esc_html_e( 'Validate', 'shaheen-central-mxchat-sync' ); ?>
										</button>
										<button type="button" class="button button-small btn-dry-test-source" data-id="<?php echo esc_attr( $src['id'] ); ?>" title="Crawl a 5-page sample batch without saving">
											<?php esc_html_e( 'Dry Test', 'shaheen-central-mxchat-sync' ); ?>
										</button>
										<?php if ( $src['status'] !== 'approved' && $src['status'] !== 'archived' ) : ?>
											<button type="button" class="button button-small button-primary btn-approve-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
												<?php esc_html_e( 'Approve', 'shaheen-central-mxchat-sync' ); ?>
											</button>
										<?php endif; ?>
										<?php if ( $src['status'] !== 'archived' ) : ?>
											<button type="button" class="button button-small btn-archive-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
												<?php esc_html_e( 'Archive', 'shaheen-central-mxchat-sync' ); ?>
											</button>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete this source and all its records? This cannot be undone.', 'shaheen-central-mxchat-sync' ); ?>');">
											<input type="hidden" name="action" value="shaheen_delete_source">
											<input type="hidden" name="source_id" value="<?php echo esc_attr( $src['id'] ); ?>">
											<?php wp_nonce_field( 'shaheen_delete_source', 'nonce' ); ?>
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'shaheen-central-mxchat-sync' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="8"><?php esc_html_e( 'No source websites configured.', 'shaheen-central-mxchat-sync' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Add New Source Website Form (Onboarding Step 1) -->
			<div class="shaheen-card">
				<h3><?php esc_html_e( 'Onboard New Source Website (Draft)', 'shaheen-central-mxchat-sync' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Adding a source creates a draft. You must validate the sitemap and approve the source before scheduled synchronization can run.', 'shaheen-central-mxchat-sync' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="shaheen_save_source">
					<?php wp_nonce_field( 'shaheen_save_source', 'nonce' ); ?>

					<table class="form-table">
						<tr>
							<th scope="row"><label for="institution"><?php esc_html_e( 'Institution Name', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td><input type="text" name="institution" id="institution" class="regular-text" required placeholder="Shaheen PU College Bidar"></td>
						</tr>
						<tr>
							<th scope="row"><label for="domain"><?php esc_html_e( 'Domain', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td>
								<input type="text" name="domain" id="domain" class="regular-text" required placeholder="bidar.shaheengroup.org">
								<p class="description"><?php esc_html_e( 'Public hostname only without https:// or paths.', 'shaheen-central-mxchat-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitemap_url"><?php esc_html_e( 'Sitemap Index URL', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td>
								<input type="url" name="sitemap_url" id="sitemap_url" class="large-text" required placeholder="https://bidar.shaheengroup.org/wp-sitemap.xml">
								<p class="description"><?php esc_html_e( 'Must use HTTPS and match the domain configured above.', 'shaheen-central-mxchat-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="category"><?php esc_html_e( 'Source Category', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td>
								<select name="category" id="category">
									<option value="Education"><?php esc_html_e( 'Education', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="Medical"><?php esc_html_e( 'Medical', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="Corporate"><?php esc_html_e( 'Corporate', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="Branch"><?php esc_html_e( 'Branch', 'shaheen-central-mxchat-sync' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="language"><?php esc_html_e( 'Website Language', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td>
								<select name="language" id="language">
									<option value="English"><?php esc_html_e( 'English', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="Arabic"><?php esc_html_e( 'Arabic', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="Urdu"><?php esc_html_e( 'Urdu', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="Mixed/Multilingual"><?php esc_html_e( 'Mixed / Multilingual', 'shaheen-central-mxchat-sync' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="crawl_frequency"><?php esc_html_e( 'Crawl Frequency', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td>
								<select name="crawl_frequency" id="crawl_frequency">
									<option value="daily"><?php esc_html_e( 'Daily', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="twicedaily"><?php esc_html_e( 'Twice Daily', 'shaheen-central-mxchat-sync' ); ?></option>
									<option value="weekly"><?php esc_html_e( 'Weekly', 'shaheen-central-mxchat-sync' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="notes"><?php esc_html_e( 'Administrative Notes', 'shaheen-central-mxchat-sync' ); ?></label></th>
							<td><textarea name="notes" id="notes" rows="3" class="large-text" placeholder="Branch contact, accreditation notes, or scope..."></textarea></td>
						</tr>
					</table>
					<?php submit_button( __( 'Add Source as Draft', 'shaheen-central-mxchat-sync' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Generic filtered records table for Discovered, Skipped, New, Unchanged, Errors tabs.
	 *
	 * @param string $fixed_status
	 */
	private static function render_tab_records( $fixed_status = '' ) {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( trim( $_GET['s'] ) ) : '';
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page     = 25;
		$offset       = ( $paged - 1 ) * $per_page; // FIXED: Added $ to per_page

		$where_clauses = array( '1=1' );
		$params = array();

		if ( ! empty( $fixed_status ) ) {
			if ( 'skipped' === $fixed_status ) {
				$where_clauses[] = "(status = 'skipped' OR status = 'rejected')";
			} else {
				$where_clauses[] = 'status = %s';
				$params[] = $fixed_status;
			}
		}

		if ( ! empty( $search_query ) ) {
			$where_clauses[] = '(canonical_url LIKE %s OR title LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( $search_query ) . '%';
			$params[] = '%' . $wpdb->esc_like( $search_query ) . '%';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$total_sql = "SELECT COUNT(*) FROM {$records_table} WHERE {$where_sql}";
		$total_records = ! empty( $params ) ? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : (int) $wpdb->get_var( $total_sql );

		$query_sql = "SELECT * FROM {$records_table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$records = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ), ARRAY_A );

		$total_pages = ceil( $total_records / $per_page );
		?>
		<div class="shaheen-card">
			<div class="shaheen-card-header-flex">
				<h2>
					<?php
					$titles = array(
						'discovered' => __( 'Discovered URLs', 'shaheen-central-mxchat-sync' ),
						'skipped'    => __( 'Skipped URLs & Exclusions', 'shaheen-central-mxchat-sync' ),
						'new'        => __( 'New Content Records', 'shaheen-central-mxchat-sync' ),
						'unchanged'  => __( 'Unchanged Content Records', 'shaheen-central-mxchat-sync' ),
						'error'      => __( 'Crawling & Fetching Errors', 'shaheen-central-mxchat-sync' ),
					);
					echo esc_html( isset( $titles[ $fixed_status ] ) ? $titles[ $fixed_status ] : __( 'Records', 'shaheen-central-mxchat-sync' ) );
					?>
				</h2>
				<span class="shaheen-total-count"><?php printf( esc_html__( 'Total: %d items', 'shaheen-central-mxchat-sync' ), $total_records ); ?></span>
			</div>

			<form method="get" class="shaheen-filter-bar">
				<input type="hidden" name="page" value="shaheen-central-sync">
				<input type="hidden" name="tab" value="<?php echo esc_attr( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'discovered' ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search URL or Title...', 'shaheen-central-mxchat-sync' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'shaheen-central-mxchat-sync' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Page Title / Canonical URL', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Source Domain', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Type', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Status', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Hash (Candidate)', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Last Checked', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'shaheen-central-mxchat-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $records ) ) : ?>
						<?php foreach ( $records as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['id'] ); ?></td>
								<td>
									<strong><?php echo esc_html( $row['title'] ? $row['title'] : 'Untitled' ); ?></strong><br>
									<a href="<?php echo esc_url( $row['canonical_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="shaheen-url-link">
										<?php echo esc_html( $row['canonical_url'] ); ?>
									</a>
									<?php if ( ! empty( $row['skip_reason'] ) ) : ?>
										<div class="shaheen-meta-sub shaheen-text-muted"><strong><?php esc_html_e( 'Skip Reason:', 'shaheen-central-mxchat-sync' ); ?></strong> <?php echo esc_html( $row['skip_reason'] ); ?></div>
									<?php endif; ?>
									<?php if ( ! empty( $row['error_message'] ) ) : ?>
										<div class="shaheen-meta-sub shaheen-text-danger"><strong><?php esc_html_e( 'Error:', 'shaheen-central-mxchat-sync' ); ?></strong> <?php echo esc_html( $row['error_message'] ); ?></div>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $row['source_domain'] ); ?></code></td>
								<td><span class="shaheen-badge-type"><?php echo esc_html( $row['content_type'] ); ?></span></td>
								<td><span class="shaheen-status-tag shaheen-status-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
								<td><code><?php echo esc_html( $row['candidate_content_hash'] ? substr( $row['candidate_content_hash'], 0, 10 ) . '...' : ( $row['accepted_content_hash'] ? substr( $row['accepted_content_hash'], 0, 10 ) . '...' : '—' ) ); ?></code></td>
								<td><?php echo $row['last_checked'] ? esc_html( $row['last_checked'] ) : '—'; ?></td>
								<td>
									<button type="button" class="button button-small btn-view-preview" data-id="<?php echo esc_attr( $row['id'] ); ?>">
										<?php esc_html_e( 'Preview', 'shaheen-central-mxchat-sync' ); ?>
									</button>
									<button type="button" class="button button-small btn-retry-record" data-id="<?php echo esc_attr( $row['id'] ); ?>">
										<?php esc_html_e( 'Re-check', 'shaheen-central-mxchat-sync' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No records found matching criteria.', 'shaheen-central-mxchat-sync' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tab 7: Pending Review Queue
	 */
	private static function render_tab_pending() {
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$records_table} WHERE status = %s OR status = %s ORDER BY id DESC",
				'pending_review',
				'needs_review'
			),
			ARRAY_A
		);
		?>
		<div class="shaheen-card">
			<h2><?php esc_html_e( 'Pending Review Queue (Sensitive & Changed Content)', 'shaheen-central-mxchat-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Pages containing sensitive terms (fees, deadlines, cutoffs) or changed text since prior crawl are held here. Accepted content is preserved intact. Candidate content is never sent to MXChat automatically.', 'shaheen-central-mxchat-sync' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'URL / Title', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Flag Reason & Matching Terms', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Baseline vs Candidate Hash', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Last Modified', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'shaheen-central-mxchat-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $pending ) ) : ?>
						<?php foreach ( $pending as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['id'] ); ?></td>
								<td>
									<strong><?php echo esc_html( $item['title'] ? $item['title'] : 'Untitled' ); ?></strong><br>
									<a href="<?php echo esc_url( $item['canonical_url'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $item['canonical_url'] ); ?>
									</a>
								</td>
								<td><span class="shaheen-text-warning"><strong><?php echo esc_html( $item['flag_reasons'] ); ?></strong></span></td>
								<td>
									<small>Baseline: <code><?php echo esc_html( $item['accepted_content_hash'] ? substr( $item['accepted_content_hash'], 0, 8 ) . '...' : 'None (New)' ); ?></code></small><br>
									<small>Candidate: <code><?php echo esc_html( $item['candidate_content_hash'] ? substr( $item['candidate_content_hash'], 0, 8 ) . '...' : '—' ); ?></code></small>
								</td>
								<td><?php echo $item['last_modified'] ? esc_html( $item['last_modified'] ) : 'Unknown'; ?></td>
								<td>
									<button type="button" class="button button-small btn-view-preview" data-id="<?php echo esc_attr( $item['id'] ); ?>">
										<?php esc_html_e( 'Compare & Review', 'shaheen-central-mxchat-sync' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No items currently in pending review queue.', 'shaheen-central-mxchat-sync' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab 9: Knowledge Improvement Queue Architecture (Phase 1)
	 */
	private static function render_tab_knowledge_queue() {
		global $wpdb;
		$k_table = Shaheen_DB::get_knowledge_table();
		$queue_items = $wpdb->get_results( "SELECT * FROM {$k_table} ORDER BY id DESC LIMIT 50", ARRAY_A );
		?>
		<div class="shaheen-card">
			<div class="shaheen-card-header-flex">
				<h2><?php esc_html_e( 'Knowledge Improvement Queue', 'shaheen-central-mxchat-sync' ); ?></h2>
				<span class="shaheen-badge-phase"><?php esc_html_e( 'Phase 1 Architecture', 'shaheen-central-mxchat-sync' ); ?></span>
			</div>
			<div class="shaheen-alert-box shaheen-alert-info">
				<div class="shaheen-alert-icon"><span class="dashicons dashicons-info"></span></div>
				<div class="shaheen-alert-content">
					<strong><?php esc_html_e( 'Human Review & Verification Rule:', 'shaheen-central-mxchat-sync' ); ?></strong><br>
					<?php esc_html_e( 'Never automatically train MXChat on raw visitor input or generated bot answers. Visitor questions may contain hallucinations, prompt injection, incorrect facts, or private student data. In a future Phase 2, unanswered questions will be redacted for PII, reviewed by an administrator, linked to an official Shaheen source, and approved before ingestion.', 'shaheen-central-mxchat-sync' ); ?>
				</div>
			</div>

			<!-- Report Metrics Summary Placeholders -->
			<div class="shaheen-dashboard-grid" style="margin-top:15px;">
				<div class="shaheen-card shaheen-stat-card">
					<div class="shaheen-stat-num">0</div>
					<div class="shaheen-stat-label"><?php esc_html_e( 'Questions Without Retrieved Context', 'shaheen-central-mxchat-sync' ); ?></div>
				</div>
				<div class="shaheen-card shaheen-stat-card">
					<div class="shaheen-stat-num">0</div>
					<div class="shaheen-stat-label"><?php esc_html_e( 'Questions Receiving Fallbacks', 'shaheen-central-mxchat-sync' ); ?></div>
				</div>
				<div class="shaheen-card shaheen-stat-card">
					<div class="shaheen-stat-num">0</div>
					<div class="shaheen-stat-label"><?php esc_html_e( 'Pending Human Review', 'shaheen-central-mxchat-sync' ); ?></div>
				</div>
				<div class="shaheen-card shaheen-stat-card">
					<div class="shaheen-stat-num">0</div>
					<div class="shaheen-stat-label"><?php esc_html_e( 'Approved Verified FAQs', 'shaheen-central-mxchat-sync' ); ?></div>
				</div>
			</div>

			<table class="widefat striped" style="margin-top:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Visitor Question (PII Redacted)', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Institution', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Context Available', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Fallback Detected', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Proposed Verified Answer', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Review Status', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'shaheen-central-mxchat-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $queue_items ) ) : ?>
						<?php foreach ( $queue_items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['id'] ); ?></td>
								<td><?php echo esc_html( $item['redacted_question'] ); ?></td>
								<td><?php echo esc_html( $item['detected_institution'] ); ?></td>
								<td><?php echo $item['retrieval_context_available'] ? 'Yes' : 'No'; ?></td>
								<td><?php echo $item['fallback_detected'] ? 'Yes' : 'No'; ?></td>
								<td><?php echo esc_html( $item['proposed_official_answer'] ); ?></td>
								<td><span class="shaheen-status-tag"><?php echo esc_html( $item['review_status'] ); ?></span></td>
								<td><button type="button" class="button button-small" disabled><?php esc_html_e( 'Review (Phase 2)', 'shaheen-central-mxchat-sync' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No knowledge improvement items queued. Transcripts will only be read in Phase 2 with explicit administrator review.', 'shaheen-central-mxchat-sync' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab 10: Activity & Error Logs
	 */
	private static function render_tab_logs() {
		$filter_level = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page     = 50;
		$offset       = ( $paged - 1 ) * $per_page;

		$logs       = Shaheen_Logger::get_logs( $per_page, $offset, $filter_level );
		$total_logs = Shaheen_Logger::get_logs_count( $filter_level );
		$total_pages = ceil( $total_logs / $per_page );
		?>
		<div class="shaheen-card">
			<div class="shaheen-card-header-flex">
				<h2><?php esc_html_e( 'Activity & Error Logs', 'shaheen-central-mxchat-sync' ); ?></h2>
				<button type="button" id="btn-clear-logs" class="button button-secondary">
					<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Logs', 'shaheen-central-mxchat-sync' ); ?>
				</button>
			</div>
			<p class="description">
				<?php esc_html_e( 'All authorization headers, tokens, passwords, cookies, and visitor secrets are automatically redacted before saving.', 'shaheen-central-mxchat-sync' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:180px;"><?php esc_html_e( 'Timestamp', 'shaheen-central-mxchat-sync' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Level', 'shaheen-central-mxchat-sync' ); ?></th>
						<th><?php esc_html_e( 'Message', 'shaheen-central-mxchat-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $logs ) ) : ?>
						<?php foreach ( $logs as $lg ) : ?>
							<tr>
								<td><?php echo esc_html( $lg['created_at'] ); ?></td>
								<td><span class="shaheen-log-level shaheen-log-<?php echo esc_attr( $lg['level'] ); ?>"><?php echo esc_html( strtoupper( $lg['level'] ) ); ?></span></td>
								<td><code><?php echo esc_html( $lg['message'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No log entries found.', 'shaheen-central-mxchat-sync' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tab 11: Settings
	 */
	private static function render_tab_settings() {
		$max_pages   = absint( get_option( 'shaheen_sync_max_pages_per_sync', 100 ) );
		$batch_size  = absint( get_option( 'shaheen_sync_batch_size', 10 ) );
		$timeout     = absint( get_option( 'shaheen_sync_timeout', 15 ) );
		$retries     = absint( get_option( 'shaheen_sync_retry_count', 3 ) );
		$retry_delay = absint( get_option( 'shaheen_sync_retry_delay', 1 ) );
		$max_sitemap = absint( get_option( 'shaheen_sync_max_sitemap_size', 5242880 ) );
		$max_page    = absint( get_option( 'shaheen_sync_max_page_size', 3145728 ) );
		$recurrence  = get_option( 'shaheen_sync_cron_recurrence', 'daily' );
		$user_agent  = get_option( 'shaheen_sync_user_agent', 'ShaheenCentralMXChatSync/1.0 (+https://shaheengroup.org)' );
		$keywords    = get_option( 'shaheen_sync_sensitive_keywords', implode( "\n", Shaheen_Content_Extractor::get_default_sensitive_keywords() ) );
		$excluded    = get_option( 'shaheen_sync_excluded_patterns', implode( "\n", Shaheen_URL_Filter::get_default_excluded_patterns() ) );
		?>
		<div class="shaheen-card">
			<h2><?php esc_html_e( 'Crawl & Safety Settings', 'shaheen-central-mxchat-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shaheen_save_settings">
				<?php wp_nonce_field( 'shaheen_save_settings', 'nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Operating Mode', 'shaheen-central-mxchat-sync' ); ?></th>
						<td>
							<input type="text" class="regular-text" value="Dry Run (Phase 1 Only)" readonly disabled>
							<p class="description"><?php esc_html_e( 'In Phase 1, live MXChat knowledge import and transcript fetching are permanently locked off.', 'shaheen-central-mxchat-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_pages"><?php esc_html_e( 'Maximum Pages Per Sync Run', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="max_pages" id="max_pages" value="<?php echo esc_attr( $max_pages ); ?>" min="1" max="1000"></td>
					</tr>
					<tr>
						<th scope="row"><label for="batch_size"><?php esc_html_e( 'Batch Size', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="batch_size" id="batch_size" value="<?php echo esc_attr( $batch_size ); ?>" min="1" max="50"></td>
					</tr>
					<tr>
						<th scope="row"><label for="timeout"><?php esc_html_e( 'Request Timeout (seconds)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="timeout" id="timeout" value="<?php echo esc_attr( $timeout ); ?>" min="5" max="60"></td>
					</tr>
					<tr>
						<th scope="row"><label for="retries"><?php esc_html_e( 'Max HTTP Retries (429/5xx)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="retries" id="retries" value="<?php echo esc_attr( $retries ); ?>" min="0" max="5"></td>
					</tr>
					<tr>
						<th scope="row"><label for="retry_delay"><?php esc_html_e( 'Base Retry Delay (seconds)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="retry_delay" id="retry_delay" value="<?php echo esc_attr( $retry_delay ); ?>" min="1" max="5"></td>
					</tr>
					<tr>
						<th scope="row"><label for="max_sitemap"><?php esc_html_e( 'Max Sitemap Size (bytes)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="max_sitemap" id="max_sitemap" value="<?php echo esc_attr( $max_sitemap ); ?>" min="100000" max="20000000"></td>
					</tr>
					<tr>
						<th scope="row"><label for="max_page"><?php esc_html_e( 'Max Page HTML Size (bytes)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="number" name="max_page" id="max_page" value="<?php echo esc_attr( $max_page ); ?>" min="100000" max="10000000"></td>
					</tr>
					<tr>
						<th scope="row"><label for="recurrence"><?php esc_html_e( 'WP-Cron Schedule', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td>
							<select name="recurrence" id="recurrence">
								<option value="daily" <?php selected( $recurrence, 'daily' ); ?>><?php esc_html_e( 'Once Daily', 'shaheen-central-mxchat-sync' ); ?></option>
								<option value="twicedaily" <?php selected( $recurrence, 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'shaheen-central-mxchat-sync' ); ?></option>
								<option value="hourly" <?php selected( $recurrence, 'hourly' ); ?>><?php esc_html_e( 'Hourly (Testing Only)', 'shaheen-central-mxchat-sync' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="user_agent"><?php esc_html_e( 'Custom User-Agent', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td><input type="text" name="user_agent" id="user_agent" value="<?php echo esc_attr( $user_agent ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sensitive_keywords"><?php esc_html_e( 'Sensitive Review Keywords (One per line)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td>
							<textarea name="sensitive_keywords" id="sensitive_keywords" rows="8" class="large-text"><?php echo esc_textarea( $keywords ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Pages containing these terms are flagged and routed to Pending Review.', 'shaheen-central-mxchat-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="excluded_patterns"><?php esc_html_e( 'Custom Excluded URL Patterns (One regex/path per line)', 'shaheen-central-mxchat-sync' ); ?></label></th>
						<td>
							<textarea name="excluded_patterns" id="excluded_patterns" rows="8" class="large-text"><?php echo esc_textarea( $excluded ); ?></textarea>
							<p class="description"><?php esc_html_e( 'URLs matching these patterns will be skipped automatically.', 'shaheen-central-mxchat-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Configuration', 'shaheen-central-mxchat-sync' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab 12: Chatbot Safety Instructions
	 */
	private static function render_tab_instructions() {
		$instructions = "Answer only from retrieved knowledge-base content.

Do not invent fees, deadlines, eligibility criteria, academic years, cutoffs,
programme details, phone numbers or admission requirements.

Do not combine information from different Shaheen institutions unless the user
explicitly asks for a comparison.

If the answer is unavailable, say:

“I could not find this information in the official Shaheen sources. Please
contact the relevant institution or Shaheen admissions office at
1800 121 6235.”

Always include the relevant official source link when available.";
		?>
		<div class="shaheen-card">
			<h2><?php esc_html_e( 'Recommended MXChat Safety Instructions', 'shaheen-central-mxchat-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Copy the following system prompt into your central MXChat chatbot configuration on shaheengroup.org to enforce strict grounding in verified facts and prevent fee/deadline hallucinations.', 'shaheen-central-mxchat-sync' ); ?>
			</p>

			<div class="shaheen-instructions-box">
				<textarea id="shaheen-instructions-text" readonly rows="13" class="large-text code"><?php echo esc_textarea( $instructions ); ?></textarea>
				<button type="button" id="btn-copy-instructions" class="button button-primary">
					<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy Instructions to Clipboard', 'shaheen-central-mxchat-sync' ); ?>
				</button>
				<span id="shaheen-copy-feedback" style="display:none; color:green; margin-left:10px;"><?php esc_html_e( 'Copied!', 'shaheen-central-mxchat-sync' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Start Sitemap Crawl
	 */
	public static function ajax_start_crawl() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$res = Shaheen_Sync_Engine::run_sitemap_discovery();
		if ( $res['success'] ) {
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * AJAX: Process Batch
	 */
	public static function ajax_process_batch() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$batch_size = absint( get_option( 'shaheen_sync_batch_size', 10 ) );
		$max_pages  = absint( get_option( 'shaheen_sync_max_pages_per_sync', 100 ) );

		$res = Shaheen_Sync_Engine::process_batch( $batch_size, $max_pages );
		if ( $res['success'] ) {
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * AJAX: Get Record Preview & Hash Comparison
	 */
	public static function ajax_get_preview() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$record_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		global $wpdb;
		$records_table = Shaheen_DB::get_records_table();

		$record = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$records_table} WHERE id = %d", $record_id ),
			ARRAY_A
		);

		if ( ! $record ) {
			wp_send_json_error( array( 'message' => __( 'Record not found.', 'shaheen-central-mxchat-sync' ) ) );
		}

		wp_send_json_success( $record );
	}

	/**
	 * AJAX: Retry Record
	 */
	public static function ajax_retry_record() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$record_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = Shaheen_Sync_Engine::retry_record( $record_id );

		if ( $res['success'] ) {
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * AJAX: Validate Source Website
	 */
	public static function ajax_validate_source() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$source_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = Shaheen_Source_Manager::validate_source( $source_id );

		if ( $res['success'] ) {
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * AJAX: Dry Test Source
	 */
	public static function ajax_dry_test_source() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$source_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = Shaheen_Source_Manager::dry_test_source( $source_id );

		if ( $res['success'] ) {
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * AJAX: Approve Source
	 */
	public static function ajax_approve_source() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$source_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = Shaheen_Source_Manager::approve_source( $source_id );

		if ( $res ) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * AJAX: Archive Source
	 */
	public static function ajax_archive_source() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$source_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = Shaheen_Source_Manager::archive_source( $source_id );

		if ( $res ) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * AJAX: Toggle Source Enabled
	 */
	public static function ajax_toggle_source() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		$source_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$success = Shaheen_Source_Manager::toggle_source( $source_id );

		if ( $success ) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * AJAX: Clear Logs
	 */
	public static function ajax_clear_logs() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		Shaheen_Logger::clear_logs();
		wp_send_json_success();
	}

	/**
	 * AJAX: Release Stale Lock
	 */
	public static function ajax_release_lock() {
		check_ajax_referer( 'shaheen_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shaheen-central-mxchat-sync' ) ) );
		}

		Shaheen_Sync_Engine::release_lock();
		wp_send_json_success();
	}

	/**
	 * Form Handler: Save New Source
	 */
	public static function handle_save_source() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'shaheen-central-mxchat-sync' ) );
		}

		check_admin_referer( 'shaheen_save_source', 'nonce' );

		$data = array(
			'institution'     => isset( $_POST['institution'] ) ? sanitize_text_field( $_POST['institution'] ) : '',
			'domain'          => isset( $_POST['domain'] ) ? sanitize_text_field( $_POST['domain'] ) : '',
			'sitemap_url'     => isset( $_POST['sitemap_url'] ) ? esc_url_raw( $_POST['sitemap_url'] ) : '',
			'category'        => isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : 'Education',
			'language'        => isset( $_POST['language'] ) ? sanitize_text_field( $_POST['language'] ) : 'English',
			'crawl_frequency' => isset( $_POST['crawl_frequency'] ) ? sanitize_key( $_POST['crawl_frequency'] ) : 'daily',
			'notes'           => isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : null,
		);

		$result = Shaheen_Source_Manager::add_source( $data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=shaheen-central-sync&tab=sources&error=' . urlencode( $result->get_error_message() ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=shaheen-central-sync&tab=sources&notice=' . urlencode( __( 'Source website added as draft. Please validate and approve it.', 'shaheen-central-mxchat-sync' ) ) ) );
		exit;
	}

	/**
	 * Form Handler: Delete Source
	 */
	public static function handle_delete_source() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'shaheen-central-mxchat-sync' ) );
		}

		check_admin_referer( 'shaheen_delete_source', 'nonce' );

		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		Shaheen_Source_Manager::delete_source( $source_id );

		wp_safe_redirect( admin_url( 'admin.php?page=shaheen-central-sync&tab=sources&notice=' . urlencode( __( 'Source website deleted.', 'shaheen-central-mxchat-sync' ) ) ) );
		exit;
	}

	/**
	 * Form Handler: Save Settings
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'shaheen-central-mxchat-sync' ) );
		}

		check_admin_referer( 'shaheen_save_settings', 'nonce' );

		if ( isset( $_POST['max_pages'] ) ) {
			update_option( 'shaheen_sync_max_pages_per_sync', absint( $_POST['max_pages'] ) );
		}
		if ( isset( $_POST['batch_size'] ) ) {
			update_option( 'shaheen_sync_batch_size', absint( $_POST['batch_size'] ) );
		}
		if ( isset( $_POST['timeout'] ) ) {
			update_option( 'shaheen_sync_timeout', absint( $_POST['timeout'] ) );
		}
		if ( isset( $_POST['retries'] ) ) {
			update_option( 'shaheen_sync_retry_count', absint( $_POST['retries'] ) );
		}
		if ( isset( $_POST['retry_delay'] ) ) {
			update_option( 'shaheen_sync_retry_delay', absint( $_POST['retry_delay'] ) );
		}
		if ( isset( $_POST['max_sitemap'] ) ) {
			update_option( 'shaheen_sync_max_sitemap_size', absint( $_POST['max_sitemap'] ) );
		}
		if ( isset( $_POST['max_page'] ) ) {
			update_option( 'shaheen_sync_max_page_size', absint( $_POST['max_page'] ) );
		}
		if ( isset( $_POST['recurrence'] ) ) {
			$new_rec = sanitize_key( $_POST['recurrence'] );
			update_option( 'shaheen_sync_cron_recurrence', $new_rec );
			Shaheen_Cron::clear_event();
			Shaheen_Cron::schedule_event();
		}
		if ( isset( $_POST['user_agent'] ) ) {
			update_option( 'shaheen_sync_user_agent', sanitize_text_field( $_POST['user_agent'] ) );
		}
		if ( isset( $_POST['sensitive_keywords'] ) ) {
			update_option( 'shaheen_sync_sensitive_keywords', sanitize_textarea_field( $_POST['sensitive_keywords'] ) );
		}
		if ( isset( $_POST['excluded_patterns'] ) ) {
			update_option( 'shaheen_sync_excluded_patterns', sanitize_textarea_field( $_POST['excluded_patterns'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=shaheen-central-sync&tab=settings&notice=' . urlencode( __( 'Configuration saved successfully.', 'shaheen-central-mxchat-sync' ) ) ) );
		exit;
	}
}
