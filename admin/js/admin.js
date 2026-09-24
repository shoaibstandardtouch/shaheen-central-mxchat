/**
 * Admin JavaScript for Shaheen Central MXChat Sync
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		var isProcessing = false;

		// 1. Dry Run Crawl Button
		$('#btn-run-crawl').on('click', function(e) {
			e.preventDefault();
			if (isProcessing) return;

			var $btn = $(this);
			$btn.prop('disabled', true);
			showProgress(shaheenSync.strings.crawling, 15);

			$.post(shaheenSync.ajaxUrl, {
				action: 'shaheen_start_crawl',
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					updateProgress(response.data.message, 100);
					setTimeout(function() {
						location.reload();
					}, 1200);
				} else {
					hideProgress();
					alert(response.data && response.data.message ? response.data.message : 'Crawl error');
					$btn.prop('disabled', false);
				}
			}).fail(function() {
				hideProgress();
				alert('Network error while requesting sitemap crawl.');
				$btn.prop('disabled', false);
			});
		});

		// 2. Process Batch Button (Looping until complete)
		$('#btn-process-batch').on('click', function(e) {
			e.preventDefault();
			if (isProcessing) return;

			var $btn = $(this);
			$btn.prop('disabled', true);
			isProcessing = true;
			showProgress(shaheenSync.strings.processing, 5);

			runBatchIteration($btn);
		});

		function runBatchIteration($btn) {
			$.post(shaheenSync.ajaxUrl, {
				action: 'shaheen_process_batch',
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					var data = response.data;
					if (data.done) {
						updateProgress(data.message, 100);
						isProcessing = false;
						setTimeout(function() {
							location.reload();
						}, 1200);
					} else {
						updateProgress(data.message, 50);
						// Schedule next batch
						setTimeout(function() {
							runBatchIteration($btn);
						}, 400);
					}
				} else {
					hideProgress();
					isProcessing = false;
					$btn.prop('disabled', false);
					alert(response.data && response.data.message ? response.data.message : 'Batch processing error');
				}
			}).fail(function() {
				hideProgress();
				isProcessing = false;
				$btn.prop('disabled', false);
				alert('Network error during batch processing.');
			});
		}

		// 3. View Preview Modal
		$(document).on('click', '.btn-view-preview', function(e) {
			e.preventDefault();
			var recordId = $(this).data('id');
			$('#shaheen-preview-modal').fadeIn(150);
			$('#shaheen-modal-body').html('<div class="shaheen-loading-spinner">Loading record details...</div>');

			$.get(shaheenSync.ajaxUrl, {
				action: 'shaheen_get_preview',
				id: recordId,
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					var r = response.data;
					var metaHtml = '<div class="shaheen-preview-meta">' +
						'<strong>Institution:</strong> ' + escapeHtml(r.institution) + '<br>' +
						'<strong>Source Domain:</strong> ' + escapeHtml(r.source_domain) + '<br>' +
						'<strong>Canonical URL:</strong> <a href="' + escapeHtml(r.canonical_url) + '" target="_blank">' + escapeHtml(r.canonical_url) + '</a><br>' +
						'<strong>Classification Type:</strong> ' + escapeHtml(r.content_type) + '<br>' +
						'<strong>Status:</strong> <span class="shaheen-status-tag shaheen-status-' + escapeHtml(r.status) + '">' + escapeHtml(r.status) + '</span><br>' +
						'<strong>Content SHA-256 Hash:</strong> <code>' + escapeHtml(r.content_hash || '—') + '</code><br>';

					if (r.previous_hash) {
						metaHtml += '<strong>Previous Hash:</strong> <code>' + escapeHtml(r.previous_hash) + '</code><br>';
					}
					if (r.flag_reasons) {
						metaHtml += '<strong>Flag Reasons:</strong> <span class="shaheen-text-warning">' + escapeHtml(r.flag_reasons) + '</span><br>';
					}
					if (r.skip_reason) {
						metaHtml += '<strong>Skip Reason:</strong> <span class="shaheen-text-muted">' + escapeHtml(r.skip_reason) + '</span><br>';
					}
					if (r.last_modified) {
						metaHtml += '<strong>Last Modified:</strong> ' + escapeHtml(r.last_modified) + '<br>';
					}
					metaHtml += '</div>';

					var contentHtml = '<h4>Cleaned Content Prepared for Future MXChat:</h4>' +
						'<div class="shaheen-preview-content">' + (r.cleaned_content ? escapeHtml(r.cleaned_content) : '<em>No content extracted.</em>') + '</div>';

					$('#shaheen-modal-body').html(metaHtml + contentHtml);
				} else {
					$('#shaheen-modal-body').html('<div class="notice notice-error"><p>' + (response.data.message || 'Failed to load preview.') + '</p></div>');
				}
			}).fail(function() {
				$('#shaheen-modal-body').html('<div class="notice notice-error"><p>Network error loading preview.</p></div>');
			});
		});

		// Close Modal
		$(document).on('click', '.shaheen-modal-close', function(e) {
			e.preventDefault();
			$('#shaheen-preview-modal').fadeOut(150);
		});

		$(document).on('click', '#shaheen-preview-modal', function(e) {
			if ($(e.target).is('#shaheen-preview-modal')) {
				$('#shaheen-preview-modal').fadeOut(150);
			}
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $('#shaheen-preview-modal').is(':visible')) {
				$('#shaheen-preview-modal').fadeOut(150);
			}
		});

		// 4. Retry Record
		$(document).on('click', '.btn-retry-record', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var recordId = $btn.data('id');
			$btn.prop('disabled', true).text('Re-checking...');

			$.post(shaheenSync.ajaxUrl, {
				action: 'shaheen_retry_record',
				id: recordId,
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					$btn.text('Done!');
					setTimeout(function() {
						location.reload();
					}, 800);
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Retry failed.');
					$btn.prop('disabled', false).text('Re-check');
				}
			}).fail(function() {
				alert('Network error.');
				$btn.prop('disabled', false).text('Re-check');
			});
		});

		// 5. Toggle Source
		$(document).on('click', '.btn-toggle-source', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var sourceId = $btn.data('id');

			$.post(shaheenSync.ajaxUrl, {
				action: 'shaheen_toggle_source',
				id: sourceId,
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					location.reload();
				}
			});
		});

		// 6. Clear Logs
		$('#btn-clear-logs').on('click', function(e) {
			e.preventDefault();
			if (!confirm(shaheenSync.strings.confirmClear)) return;

			$.post(shaheenSync.ajaxUrl, {
				action: 'shaheen_clear_logs',
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					location.reload();
				}
			});
		});

		// 7. Release Stale Lock
		$('#btn-release-lock').on('click', function(e) {
			e.preventDefault();
			$.post(shaheenSync.ajaxUrl, {
				action: 'shaheen_release_lock',
				nonce: shaheenSync.nonce
			}, function(response) {
				if (response.success) {
					location.reload();
				}
			});
		});

		// 8. Copy Chatbot Instructions
		$('#btn-copy-instructions').on('click', function(e) {
			e.preventDefault();
			var text = $('#shaheen-instructions-text').val();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					showCopyFeedback();
				});
			} else {
				var textarea = document.getElementById('shaheen-instructions-text');
				textarea.select();
				document.execCommand('copy');
				showCopyFeedback();
			}
		});

		function showCopyFeedback() {
			$('#shaheen-copy-feedback').fadeIn(200).delay(1500).fadeOut(200);
		}

		// Progress UI Helpers
		function showProgress(text, pct) {
			$('#shaheen-progress-container').show();
			$('#shaheen-progress-bar-fill').css('width', pct + '%');
			$('#shaheen-progress-status').text(text);
		}

		function updateProgress(text, pct) {
			$('#shaheen-progress-bar-fill').css('width', pct + '%');
			$('#shaheen-progress-status').text(text);
		}

		function hideProgress() {
			$('#shaheen-progress-container').hide();
			$('#shaheen-progress-bar-fill').css('width', '0%');
		}

		function escapeHtml(text) {
			if (!text) return '';
			return String(text)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}
	});
})(jQuery);
