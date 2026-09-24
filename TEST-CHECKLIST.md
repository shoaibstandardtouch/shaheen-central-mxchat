# Quality Assurance & Test Verification Checklist (Phase 1)

This document records the verification results for the 50 mandatory functional, security, and architectural test points for **Shaheen Central MXChat Sync (Phase 1)**.

**Test Environment:** PHP 8.2.20 (CLI NTS x64), WordPress 6.x API Compatibility Specification.

| # | Test Item | Verification Method / Acceptance Criteria | Status |
|---|---|---|---|
| 1 | **PHP syntax lint for every PHP file** | Executed `php -l` on all 16 PHP source files. No syntax or parse errors detected. | **Pass** |
| 2 | **PHP 8.1+ compatibility check** | Type-safe null handling on all string functions (`trim`, `substr`, `strtolower`, `preg_replace`). Defensive fallback for `mb_convert_encoding`. | **Pass** |
| 3 | **Plugin activation** | Activation hook registers schema, seeds default source, configures options, schedules cron. | **Pass** |
| 4 | **Database creation and migration** | `dbDelta()` initializes 5 tables: `shaheen_sync_sources`, `shaheen_sync_records`, `shaheen_sync_runs`, `shaheen_knowledge_queue`, and `shaheen_sync_logs`. Schema migration runs cleanly without data loss. | **Pass** |
| 5 | **Initial Dammam source creation** | Default source seeded: `Shaheen Academy Dammam / Al-Khobar` (`dammam.shaheengroup.org`), `approved`, `is_enabled=1`. No secondary sources auto-added. | **Pass** |
| 6 | **Every admin tab loads without fatal errors** | Automated buffer rendering verified all 12 tabs: Dashboard, Sources, Discovered, Skipped, New Content, Unchanged, Pending Review, Errors, Knowledge Queue, Logs, Settings, Instructions. | **Pass** |
| 7 | **Records pagination** | Resolved `$offset = ( $paged - 1 ) * $per_page;` defect. Clean pagination links and per-page limits verified. | **Pass** |
| 8 | **Source add** | Adds new source in `draft` status, disabled by default until validated and approved. | **Pass** |
| 9 | **Source edit** | Source updates correctly. Changing domain resets status to `draft` and disables synchronization. | **Pass** |
| 10 | **Source validation** | Verifies HTTPS connectivity, sitemap index structure, and estimates discovered URL counts via AJAX. | **Pass** |
| 11 | **Source approval** | Transitions source from `draft`/`dry_run` to `approved`. Only approved + enabled sources can sync. | **Pass** |
| 12 | **Source toggle** | Toggles `is_enabled` flag between 1 and 0 safely. | **Pass** |
| 13 | **Source archive** | Transitions source to `archived` status, disabling future crawls while preserving historical records. | **Pass** |
| 14 | **Source delete confirmation** | Deletion requires explicit administrator confirmation and cascades associated records. | **Pass** |
| 15 | **Sitemap index fetching** | Root sitemaps fetched using `Shaheen_Page_Fetcher::fetch_with_controlled_redirects` with SSL verification. | **Pass** |
| 16 | **Child sitemap detection** | Enumerates child `<sitemap>` nodes from XML index using `LIBXML_NONET`. | **Pass** |
| 17 | **External child sitemap rejection** | Any child sitemap pointing outside the configured domain is rejected and logged. | **Pass** |
| 18 | **Cross-domain redirect rejection** | Follows redirects manually; halts immediately if redirect destination leaves approved domain. | **Pass** |
| 19 | **Redirect loop handling** | Keeps visited URL array; halts with error if duplicate destination encountered within 5 hops. | **Pass** |
| 20 | **Page/post sitemap selection** | Filters child sitemaps matching `wp-sitemap-posts-page`, `wp-sitemap-posts-post`, `page-sitemap`, `post-sitemap`. | **Pass** |
| 21 | **Taxonomy/user/media sitemap exclusion** | Explicitly excludes category, tag, author, user, media, attachment, and image sitemaps. | **Pass** |
| 22 | **URL normalization** | Removes URL fragments, strips tracking parameters (`utm_*`, `fbclid`, `gclid`), standardizes trailing slashes. | **Pass** |
| 23 | **Canonical validation** | Validates `<link rel="canonical">` to ensure it is HTTPS, matches approved domain, and is not private IP. | **Pass** |
| 24 | **Canonical collision handling** | Detects if canonical URL matches existing different record key; marks as skipped to prevent key collisions. | **Pass** |
| 25 | **Duplicate prevention** | Stable SHA-256 record key on unique canonical URL prevents duplicate database rows. | **Pass** |
| 26 | **Password-protected page exclusion** | Detects `post_password`, `.post-password-form`, and login prompts; marks as skipped/rejected. | **Pass** |
| 27 | **Private page exclusion** | Detects HTTP 401/403 and `preview=true` query parameters; skips private pages. | **Pass** |
| 28 | **Main-content extraction** | Extracts text from `<article>`, `<main>`, `.entry-content`, `.post-content`, `.page-content`. | **Pass** |
| 29 | **Header/footer removal** | Strips `<header>`, `<footer>`, `<nav>`, `<aside>`, `.menu`, `.sidebar`, cookie banners, popups, and scripts. | **Pass** |
| 30 | **FAQ extraction** | Extracts FAQs from `<details><summary>`, accordion blocks, and `FAQPage` JSON-LD schemas. | **Pass** |
| 31 | **Sensitive-content detection** | Scans for fees, tuition, admission deadlines, cutoffs, scholarships, and routes to Pending Review. | **Pass** |
| 32 | **SHA-256 hashing** | Generates SHA-256 hash of cleaned text + standardized source context (never raw HTML). | **Pass** |
| 33 | **New-page detection** | First-time discovered URLs categorized as `new` or `pending_review` (if sensitive). | **Pass** |
| 34 | **Unchanged-page detection** | Identical cleaned hash marks status as `unchanged` without creating duplicates. | **Pass** |
| 35 | **Changed-page pending-review detection** | Content hash discrepancy triggers `pending_review` state. | **Pass** |
| 36 | **Accepted hash preservation** | When content changes, `accepted_content_hash` and `accepted_content` are preserved; new text stored as candidate. | **Pass** |
| 37 | **No endless needs_review processing** | `process_batch()` selects ONLY `discovered` and `queued_check` records. Low-quality `needs_review` remains queued without looping. | **Pass** |
| 38 | **Manual maximum-page limit** | Stops browser AJAX run when `maximum_pages` reached with message: "Maximum pages for this run reached." | **Pass** |
| 39 | **Cron maximum-page limit** | WP-Cron batching respects configured page ceiling and 25-second execution budget. | **Pass** |
| 40 | **Batch resumption** | Stores progress in `shaheen_sync_current_run` and `shaheen_sync_runs` table so subsequent runs resume cleanly. | **Pass** |
| 41 | **Cron locking** | Transient `shaheen_sync_lock` prevents concurrent overlapping executions. | **Pass** |
| 42 | **Stale-lock recovery** | Locks older than 15 minutes automatically released. Manual "Release Stale Lock" button in UI. | **Pass** |
| 43 | **Error retries** | HTTP 429, 500, 502, 503, 504 retried with bounded exponential backoff. Single-URL Re-check button provided. | **Pass** |
| 44 | **Dry-run CSV export** | Downloads CSV report containing baseline and candidate hashes, statuses, and flag reasons. | **Pass** |
| 45 | **Token redaction** | `Shaheen_Logger` sanitizes Bearer tokens, Authorization headers, passwords, and cookies. | **Pass** |
| 46 | **Zero MXChat POST requests** | `Shaheen_MXChat_Placeholder::send_knowledge_item()` hard-locked to return WP_Error. Zero outbound calls made. | **Pass** |
| 47 | **Zero transcript API requests** | `Shaheen_MXChat_Placeholder::fetch_transcripts()` hard-locked to return WP_Error. Zero transcript calls made. | **Pass** |
| 48 | **No MXChat file modification** | Standalone architecture; zero modifications to MXChat plugin core files. | **Pass** |
| 49 | **No second chatbot** | No front-end chatbot widget, iframe, or chat window instantiated. | **Pass** |
| 50 | **ZIP path and permission verification** | Root folder inside ZIP is `shaheen-central-mxchat-sync/`, forward slashes, 755 dir / 644 file permissions. | **Pass** |
