=== Shaheen Central MXChat Sync ===
Contributors: shaheengroup, standardtouch
Tags: mxchat, chatbot, knowledge-base, sitemap-crawler, sync
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: Proprietary
License URI: https://shaheengroup.org

Synchronize approved Shaheen Group websites into one central MXChat knowledge base on shaheengroup.org.

== Description ==

**Shaheen Central MXChat Sync** provides an enterprise crawler and content normalization pipeline to synchronize approved Shaheen Group branch and academy websites into a single central MXChat knowledge base on `https://shaheengroup.org`.

### Phase 1 Scope & Delivery Rules
This release is strictly **Phase 1 (Dry Run Only)**.
* Crawls sitemap indexes and child post/page sitemaps (`wp-sitemap-posts-page-1.xml`, `wp-sitemap-posts-post-1.xml`).
* Filters out non-content sitemaps (categories, tags, authors, media, attachments).
* Enforces strict URL exclusions (login, admin, carts, checkouts, privacy, terms, thank you pages).
* Normalizes URLs and strips marketing/tracking parameters (`utm_*`, `fbclid`, `gclid`).
* Protects against SSRF by blocking localhost and private IP address ranges.
* Extracts DOM content cleanly without headers, footers, sidebars, cookie banners, popups, or scripts.
* Classifies content into: `page`, `programme`, `admissions`, `FAQ`, and `post`.
* Adds canonical source context metadata.
* Calculates deterministic SHA-256 hashes of cleaned content.
* Detects duplicates, unchanged records, and changed records.
* Routes sensitive content (fees, cutoffs, deadlines, eligibility) to a Pending Review queue.
* **Zero outbound MXChat API posting**: Live posting is completely disabled until administrator dry-run approval.

== Installation ==

1. Upload the `shaheen-central-mxchat-sync.zip` file via **Plugins > Add New > Upload Plugin** in the WordPress admin on `https://shaheengroup.org`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Access the administrative panel via **Shaheen Central Sync** in the admin sidebar.
4. Verify the pre-seeded Dammam source (`dammam.shaheengroup.org`) and run a Dry-Run Crawl.

== Frequently Asked Questions ==

= Does Phase 1 send any data to MXChat? =
No. In Phase 1, all outbound API requests to the MXChat endpoint are strictly disabled and blocked at the architectural level.

= Where will the chatbot appear? =
The single central chatbot is hosted exclusively on `https://shaheengroup.org`. Other Shaheen websites act purely as remote content sources.

= How is the API token stored in Phase 2? =
In Phase 2, the token is defined exclusively in `wp-config.php` via `define('SHAHEEN_MXCHAT_API_TOKEN', '...')`. It is never stored in the database or exposed in the UI.

== Changelog ==

= 1.0.0 =
* Initial Phase 1 production-quality release.
* Automated sitemap crawler with child sitemap detection.
* Anti-SSRF and canonical URL normalization engine.
* DOM-based content cleaner and classifier.
* SHA-256 content change tracking.
* Dry-run dashboard, pending review queue, and CSV reporting.
* Daily WP-Cron batching with lock protection.
