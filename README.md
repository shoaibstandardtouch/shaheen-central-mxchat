# Shaheen Central MXChat Sync (Phase 1)

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF.svg)](https://php.net)
[![Phase](https://img.shields.io/badge/Phase-1%20(Dry%20Run%20Only)-orange.svg)](#delivery-rules--phase-1-scope)
[![Status](https://img.shields.io/badge/Production-Quality-brightgreen.svg)](#)

Enterprise WordPress synchronization plugin engineered exclusively for **`https://shaheengroup.org`**.

## Purpose
Synchronize approved Shaheen Group branch and academy websites (such as `dammam.shaheengroup.org`) into one central MXChat knowledge base.
- **Single Central Chatbot:** Displayed only on `https://shaheengroup.org`.
- **Remote Websites as Content Sources:** Other Shaheen sites act purely as crawled content repositories.

---

## Delivery Rules & Phase 1 Scope
> [!IMPORTANT]
> **Live posting to MXChat is 100% disabled in Phase 1.**
> Even if an API token is provided in `wp-config.php`, Phase 1 executes solely in **Dry-Run mode**. No network requests are made to `https://shaheengroup.org/wp-json/mxchat/v1/knowledge`. Automatic importing will only be activated in Phase 2 after the administrator has audited and approved dry-run results.

### Phase 1 Features:
* **Sitemap Crawler:** Automatically parses XML sitemap indexes, identifies child post/page sitemaps, and skips category/tag/author/media sitemaps.
* **URL Filtering & Anti-SSRF:** Blocks localhost and private IP addresses (RFC 1918), normalizes canonical URLs, strips tracking query strings (`utm_*`, `fbclid`, `gclid`), and skips admin/login/cart/privacy/terms URLs.
* **DOM Content Extraction & Cleaning:** Strips navigation, headers, footers, sidebars, cookie banners, popups, and scripts while capturing title, headings, main content, and FAQs.
* **Content Classification:** Automatically classifies items into `page`, `programme`, `admissions`, `FAQ`, or `post`.
* **Standardized Source Context:** Prefixes each record with institution name, source domain, canonical URL, content type, and last modified date.
* **Deterministic SHA-256 Hashing:** Tracks duplicate and changed content without saving or hashing raw HTML.
* **Pending Review Queue:** Flags records containing sensitive institutional terms (fees, tuition, admission deadlines, cutoffs, eligibility) or changed content.
* **Administrative Interface:** Complete dashboard with real-time stats, AJAX dry-run execution, content preview drawer, source management, log viewing with secret redaction, and CSV export.
* **WP-Cron Automation:** Daily batch runner with transient lock prevention and stale lock recovery.

---

## Directory & File Structure
```
shaheen-central-mxchat-sync/
├── shaheen-central-mxchat-sync.php   # Main plugin entry point & constants
├── readme.txt                        # Standard WordPress plugin metadata
├── INSTALLATION.md                   # Installation & deployment guide
├── TEST-CHECKLIST.md                 # 42-point test & verification matrix
├── CHANGELOG.md                      # Release changelog
├── includes/
│   ├── class-shaheen-activator.php   # Activation handler: dbDelta schema & source seed
│   ├── class-shaheen-deactivator.php # Deactivation handler: cron & lock cleanup
│   ├── class-shaheen-db.php          # Database queries & stats abstraction
│   ├── class-shaheen-source-manager.php # Approved source management & validation
│   ├── class-shaheen-crawler.php     # Sitemap parser & child sitemap filter
│   ├── class-shaheen-url-filter.php  # Normalization, exclusion rules, & anti-SSRF
│   ├── class-shaheen-page-fetcher.php# Safe HTTP fetcher with retry & backoff
│   ├── class-shaheen-content-extractor.php # DOM cleaner & sensitive term scanner
│   ├── class-shaheen-classifier.php  # Content categorization engine
│   ├── class-shaheen-sync-engine.php # Core state machine & batch processor
│   ├── class-shaheen-cron.php        # Daily WP-Cron scheduler & batch budgeter
│   ├── class-shaheen-logger.php      # Activity logger with automatic secret redaction
│   ├── class-shaheen-exporter.php    # Dry-run report CSV exporter
│   ├── class-shaheen-mxchat-placeholder.php # Phase 2 API architecture & Phase 1 lock
│   └── class-shaheen-admin.php       # Admin menu, UI tabs, and AJAX handlers
└── admin/
    ├── css/
    │   └── admin.css                 # Admin styles, status badges, and preview modal
    └── js/
        └── admin.js                  # AJAX runners, progress bar, and modal handlers
```

---

## Pre-Seeded Default Source
Upon activation, the plugin initializes:
* **Institution:** Shaheen Academy Dammam / Al-Khobar
* **Domain:** `dammam.shaheengroup.org`
* **Sitemap URL:** `https://dammam.shaheengroup.org/wp-sitemap.xml`
* **Category:** Education
* **Status:** Enabled

---

## Phase 2 Future MXChat API Preparation
In Phase 2, live synchronization will push approved records to:
```http
POST https://shaheengroup.org/wp-json/mxchat/v1/knowledge
Headers:
  Authorization: Bearer [token]
  Content-Type: application/json
Body:
{
  "content": "[Cleaned content with source context header]",
  "source_url": "https://dammam.shaheengroup.org/example-page/",
  "content_type": "programme"
}
```
* The token will be read strictly from `wp-config.php`:
  ```php
  define( 'SHAHEEN_MXCHAT_API_TOKEN', 'private-token-here' );
  ```
* Never displayed in logs, HTML, JavaScript, database rows, or admin screens.

---

## License
Proprietary &copy; Shaheen Group. All rights reserved.
