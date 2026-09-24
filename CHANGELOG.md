# Changelog: Shaheen Central MXChat Sync

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Phase 1 Initial Release (Dry Run Only)

### Added
- **Core Architecture:**
  - Standard WordPress plugin architecture with separation of concerns across `includes/` classes.
  - Three indexed database tables via `dbDelta()`: `shaheen_sync_records`, `shaheen_sync_sources`, and `shaheen_sync_logs`.
  - Automatic seeding of initial source: Shaheen Academy Dammam / Al-Khobar (`dammam.shaheengroup.org`).
- **Sitemap Crawler & Discovery:**
  - Safe sitemap index fetching via `wp_safe_remote_get()` and `LIBXML_NONET`.
  - Selective child sitemap discovery filtering for page and post content.
  - Explicit skipping of category, tag, author, user, media, attachment, and taxonomy sitemaps.
- **URL Filtering & Anti-SSRF:**
  - Strict host validation preventing localhost, private IP ranges (RFC 1918), and off-domain redirects.
  - Path exclusion rules for logins, admin screens, checkout, account, search, privacy, and terms pages.
  - URL normalization removing fragments, trailing slash discrepancies, and query tracking parameters (`utm_*`, `fbclid`, `gclid`).
- **DOM Content Extraction & Classification:**
  - Content parsing using `DOMDocument` and `DOMXPath`.
  - Stripping of navigation, headers, footers, sidebars, cookie banners, scripts, styles, and empty forms.
  - Classification into `page`, `programme`, `admissions`, `FAQ`, and `post`.
  - Standardized source context header attached to cleaned content.
- **Duplicate & Change Detection:**
  - Deterministic SHA-256 content hashing.
  - Categorization of records into `new`, `unchanged`, and `pending_review`.
  - Detection and flagging of sensitive institutional terms (tuition, fees, cutoff, deadlines, eligibility).
- **Administration & Safety UI:**
  - Administrator menu under `Shaheen Central Sync` (`manage_options`).
  - Seven dedicated views: Dashboard, Source Websites, Discovered Records, Pending Review Queue, Activity Logs, Settings, and Chatbot Safety Instructions.
  - AJAX dry-run crawl runner with live progress bar.
  - Cleaned content preview modal with metadata and hash comparison.
  - CSV report exporter for audit trails.
- **WP-Cron Batch Preparation:**
  - Daily scheduled event with execution-time budget to prevent timeouts.
  - Transient lock preventing concurrent runs with automatic stale lock recovery.
- **Strict Phase 1 Lockout:**
  - Permanent architectural guard preventing any outbound POST request to `https://shaheengroup.org/wp-json/mxchat/v1/knowledge`.
