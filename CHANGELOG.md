# Changelog: Shaheen Central MXChat Sync

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-09-24 (Phase 1 Code Review & Repair)

### Fixed
- **PHP Syntax & Runtime Defect in `class-shaheen-admin.php`:**
  - Resolved undefined constant fatal error on line 451: replaced `$offset = ( $paged - 1 ) * per_page;` with correct `$offset = ( $paged - 1 ) * $per_page;`.
- **Automatic Processing Loop in `Shaheen_Sync_Engine::process_batch()`:**
  - Eliminated infinite looping on records marked `needs_review`, `pending_review`, `unchanged`, `skipped`, `rejected`, `imported`, or `error`.
  - Batch processor now strictly selects ONLY `discovered` and `queued_check` records.
  - Low-quality pages (< 30 words) marked `needs_review` remain safely held in the queue without repeated automatic processing.
  - Added run ceiling detection: browser loop stops immediately when `maximum_pages` is reached and displays: *"Maximum pages for this run reached. Remaining pages will be processed in a future run."*
- **Missing Class Inclusion in `shaheen-central-mxchat-sync.php`:**
  - Added missing `require_once` for `class-shaheen-crawler.php` before `class-shaheen-sync-engine.php`.
- **PHP 8.1+ Compatibility & Defensive Type Safety:**
  - Added defensive fallback when `mb_convert_encoding()` is not installed, preventing fatal errors during DOM encoding.
  - Corrected directory-relative URL resolution in `Shaheen_Page_Fetcher::resolve_relative_url()` when base path ends with `/`.
  - Added strict string casting across `trim()`, `substr()`, `strtolower()`, and `preg_replace()` to ensure full PHP 8.1/8.2/8.3 compatibility.

### Added
- **Continuous Website Change Detection & Baseline vs Candidate Architecture:**
  - Separate database columns for accepted baseline vs candidate text:
    `accepted_content_hash`, `candidate_content_hash`, `previous_hash`, `accepted_content`, `candidate_content`, `accepted_at`, `candidate_checked_at`.
  - When content changes on an existing URL, accepted baseline content is preserved untouched while new text is stored separately as candidate content for administrator audit.
  - Detected removed sitemap URLs are marked `missing_from_source`.
- **Controlled Manual Redirect Engine:**
  - Disabled automatic HTTP client redirection (`'redirection' => 0`).
  - Implemented manual 5-hop redirect follower with SSRF validation (blocking localhost, loopbacks, RFC 1918 private IPs) on every hop.
  - Rejects cross-domain redirects and detects redirect loops.
- **Enhanced Source Lifecycle & Onboarding (8 States):**
  - Added source statuses: `draft`, `validating`, `validation_failed`, `dry_run`, `approved`, `enabled`, `disabled`, `archived`.
  - Added AJAX actions for Source Validation, 5-page Dry Testing, Approval, and Archival.
  - If a domain is modified, it reverts to `draft` and is disabled until re-validated.
- **Password-Protected & Private Page Detection:**
  - Rejects pages containing `post_password`, `.post-password-form`, or login forms before extraction; never saves protected content into candidate or accepted fields.
- **FAQ Extraction Expansion:**
  - Extracted FAQs from `<details><summary>`, visible accordion blocks, and `application/ld+json` (`FAQPage` schema).
- **12 Admin Tabs:**
  - Added dedicated tabs: Dashboard, Source Websites, Discovered URLs, Skipped URLs, New Content, Unchanged Content, Pending Review, Errors, Knowledge Improvement, Logs, Settings, Chatbot Instructions.
  - Side-by-side diff comparison in Preview modal for changed candidate content vs accepted baseline.
- **Persistent Sync Runs Table:**
  - Added `shaheen_sync_runs` table tracking `run_id`, `run_type`, `started_at`, `completed_at`, `maximum_pages`, and `processed_pages`.
- **Knowledge Improvement Queue Architecture (Phase 1):**
  - Added `shaheen_knowledge_queue` table and admin dashboard with disabled placeholder controls and clear explanations that usage-based learning requires human verification and official source linking.
- **Chatbot Safety Instructions:**
  - Updated prompt with Shaheen Admissions phone number `1800 121 6235`.
