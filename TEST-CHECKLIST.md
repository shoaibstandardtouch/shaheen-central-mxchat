# Quality Assurance & Test Verification Checklist (Phase 1)

This document verifies the 42 mandatory functional, security, and architectural test points for **Shaheen Central MXChat Sync (Phase 1)**.

| # | Test Item | Verification Method / Acceptance Criteria | Status |
|---|---|---|---|
| 1 | **Plugin activation** | Plugin activates cleanly via `wp_register_activation_hook` without PHP notices or warnings. | Pass |
| 2 | **Database table creation** | `{$wpdb->prefix}shaheen_sync_records`, `{$wpdb->prefix}shaheen_sync_sources`, and `{$wpdb->prefix}shaheen_sync_logs` created with appropriate keys and indexes via `dbDelta()`. | Pass |
| 3 | **Initial Dammam source creation** | Source `dammam.shaheengroup.org` seeded with category `Education` and status `Enabled`. No secondary sources added. | Pass |
| 4 | **Admin capability restrictions** | Menu and all AJAX actions restricted to users with `manage_options` capability. Unprivileged users denied. | Pass |
| 5 | **Nonce validation** | All form submissions and AJAX endpoints verified with `check_admin_referer` and `check_ajax_referer('shaheen_admin_nonce')`. | Pass |
| 6 | **Sitemap index fetching** | Root sitemap index fetched using `wp_safe_remote_get()` with timeouts and SSL verification. | Pass |
| 7 | **Child sitemap detection** | XML `<sitemapindex>` parsed using `simplexml_load_string(..., LIBXML_NONET)` to enumerate child sitemaps. | Pass |
| 8 | **Page sitemap importing** | Child sitemaps matching `wp-sitemap-posts-page-*.xml` or `page-sitemap*.xml` correctly approved and imported. | Pass |
| 9 | **Post sitemap importing** | Child sitemaps matching `wp-sitemap-posts-post-*.xml` or `post-sitemap*.xml` correctly approved and imported. | Pass |
| 10 | **Category sitemap exclusion** | Sitemaps matching `category`, `taxonomy`, or `term` explicitly skipped with reason logged. | Pass |
| 11 | **Author sitemap exclusion** | Sitemaps matching `author` explicitly skipped. | Pass |
| 12 | **User sitemap exclusion** | Sitemaps matching `user` or `users` explicitly skipped. | Pass |
| 13 | **Attachment sitemap exclusion** | Sitemaps matching `attachment`, `media`, `image`, or `video` explicitly skipped. | Pass |
| 14 | **Duplicate URL detection** | Record keys generated via `hash('sha256', $normalized_url)` prevent duplicate database rows. | Pass |
| 15 | **Canonical URL normalization** | `<link rel="canonical">` followed when on same domain; trailing slashes normalized; URL encodings cleaned. | Pass |
| 16 | **Tracking parameter removal** | Query parameters `utm_source`, `utm_medium`, `utm_campaign`, `fbclid`, `gclid`, `msclkid`, etc. stripped. | Pass |
| 17 | **Private page exclusion** | Pages flagged with `preview=true` or private visibility flags skipped with reason logged. | Pass |
| 18 | **Password-protected page exclusion** | Pages requiring password access rejected prior to extraction. | Pass |
| 19 | **Login/search/thank-you/privacy exclusions** | Paths matching `/wp-login.php`, `/wp-admin/`, `/login/`, `/logout/`, `/search/`, `?s=`, `/thank-you/`, `/privacy/`, `/cookie/`, `/terms/` skipped. | Pass |
| 20 | **Main content extraction** | Target containers (`article`, `main`, `.entry-content`, `.post-content`, `.content-area`) isolated. | Pass |
| 21 | **Header/footer removal** | `<header>`, `<footer>`, `<nav>`, `<aside>`, `.menu`, `.sidebar` DOM nodes stripped. | Pass |
| 22 | **Cookie banner removal** | Cookie notices, consent banners, modal dialogs, and popups removed prior to text conversion. | Pass |
| 23 | **Content classification** | Automated classification into `page`, `programme`, `admissions`, `FAQ`, and `post` using URL, titles, and headings. | Pass |
| 24 | **Source metadata generation** | Cleaned records prefixed with standardized Institution, Source website, Original URL, Content type, and Last modified metadata. | Pass |
| 25 | **SHA-256 hash generation** | SHA-256 hash calculated from cleaned content with source metadata (never from raw HTML). | Pass |
| 26 | **Unchanged content detection** | Identical content hashes mark records as `unchanged` without re-processing. | Pass |
| 27 | **Changed content pending review** | Changed hashes trigger `pending_review` state, preserving previous and new hashes for comparison. | Pass |
| 28 | **Sensitive content pending review** | Records containing fees, tuition, admission deadlines, cutoffs, or scholarships routed to Pending Review queue with matching terms displayed. | Pass |
| 29 | **HTTP 429 retry** | HTTP 429 triggers exponential backoff retry adhering to `Retry-After` header when available. | Pass |
| 30 | **HTTP 500 retry** | HTTP 500 server errors retried up to configured retry limit before marking as error. | Pass |
| 31 | **Timeout retry** | Connection timeouts retried with exponential delay. | Pass |
| 32 | **Batch processing** | Asynchronous batch execution limits processing to small chunks (default 10) to prevent PHP execution timeouts. | Pass |
| 33 | **Maximum page limit** | Configurable `max_pages_per_sync` limit halts crawl/analysis when cap reached. | Pass |
| 34 | **Cron lock** | Transient `shaheen_sync_lock` prevents concurrent overlapping executions. | Pass |
| 35 | **Stale lock recovery** | Locks older than duration timeout safely invalidated and released. Manual reset button available in UI. | Pass |
| 36 | **Dry-run report** | Comprehensive dashboard metrics display counts for discovered, skipped, new, unchanged, pending review, and errors. | Pass |
| 37 | **Error logging** | Activity logger records warnings and errors to custom table with strict token/authorization/cookie redaction. | Pass |
| 38 | **Retry failed URL** | Single-click "Re-check" action in records table allows administrators to re-evaluate individual URLs. | Pass |
| 39 | **CSV export** | Administrator can export dry-run records to downloadable CSV including status, hashes, and reasons. | Pass |
| 40 | **Confirmation: No MXChat API request occurs in Phase 1** | `Shaheen_MXChat_Placeholder::is_phase_1_locked()` enforces hard lock. Zero outbound requests made to `https://shaheengroup.org/wp-json/mxchat/v1/knowledge`. | Pass |
| 41 | **Confirmation: No second chatbot is created** | Plugin does not instantiate, render, or enqueue any front-end chatbot widget or chat window. Central chatbot remains on shaheengroup.org. | Pass |
| 42 | **Confirmation: MXChat plugin files are unchanged** | Plugin is entirely standalone and does not edit, patch, or overwrite any files of the MXChat plugin. | Pass |
