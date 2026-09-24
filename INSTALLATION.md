# Installation & Deployment Guide: Shaheen Central MXChat Sync (Phase 1)

## Overview
**Shaheen Central MXChat Sync** is an enterprise WordPress synchronization plugin engineered exclusively for:
```
https://shaheengroup.org
```
**Architecture Note:** Only one central chatbot exists, embedded on `shaheengroup.org`. All other Shaheen group branch and academy websites (such as `dammam.shaheengroup.org`) serve strictly as content sources.

---

## Delivery Rule: Phase 1 Only
> [!IMPORTANT]
> **Live posting to MXChat is permanently locked in Phase 1.**
> No data will be sent to `https://shaheengroup.org/wp-json/mxchat/v1/knowledge`. All crawling, extraction, hashing, and duplicate detection operations operate entirely in **Dry-Run mode** for administrative auditing and review.

---

## System Requirements
- **WordPress Version:** 5.8 or higher (tested on 6.7)
- **PHP Version:** 7.4 or higher (8.1 / 8.2 / 8.3 fully supported)
- **PHP Extensions:** `ext-dom`, `ext-libxml`, `ext-mbstring`, `ext-simplexml`
- **Permissions:** `manage_options` capability required for admin access

---

## Installation Steps

### Option A: WordPress Admin Upload (Recommended)
1. Download `shaheen-central-mxchat-sync.zip`.
2. Log into the WordPress administrator dashboard on `https://shaheengroup.org`.
3. Navigate to **Plugins** &rarr; **Add New** &rarr; **Upload Plugin**.
4. Choose the zip file and click **Install Now**.
5. Click **Activate Plugin**.

### Option B: SFTP / Direct Upload
1. Extract `shaheen-central-mxchat-sync.zip` locally.
2. Upload the `shaheen-central-mxchat-sync` folder to your server directory:
   ```text
   /wp-content/plugins/shaheen-central-mxchat-sync/
   ```
3. In WordPress Admin, navigate to **Plugins** &rarr; **Installed Plugins**.
4. Locate **Shaheen Central MXChat Sync** and click **Activate**.

---

## Post-Activation Verification

1. **Database Tables Created Automatically:**
   Upon activation, the plugin uses `dbDelta()` to create three custom indexed tables:
   - `wp_shaheen_sync_records` (Stores URL records, normalized canonical URLs, SHA-256 hashes, status, and cleaned content)
   - `wp_shaheen_sync_sources` (Stores approved source websites and crawl status)
   - `wp_shaheen_sync_logs` (Stores safe activity and error logs)

2. **Pre-Seeded Initial Source:**
   The initial source is automatically seeded and enabled:
   - **Institution:** Shaheen Academy Dammam / Al-Khobar
   - **Domain:** `dammam.shaheengroup.org`
   - **Sitemap Index:** `https://dammam.shaheengroup.org/wp-sitemap.xml`
   - **Category:** Education
   - **Status:** Enabled

3. **Accessing the Dashboard:**
   In the WordPress admin sidebar, click **Shaheen Central Sync**.
   - Notice the **Phase 1 • Dry Run Only** badge and security notification.
   - Review the metrics dashboard.

---

## Running Your First Dry-Run Sync

1. In the **Shaheen Central Sync** dashboard, click **Run Dry-Run Crawl Now**.
2. The crawler will:
   - Fetch `https://dammam.shaheengroup.org/wp-sitemap.xml`.
   - Identify supported child sitemaps (e.g. `wp-sitemap-posts-page-1.xml`, `wp-sitemap-posts-post-1.xml`).
   - Filter out category, tag, author, user, attachment, and media sitemaps.
   - Apply SSRF protection and path exclusion filters.
   - Populate the database with discovered records.
3. Click **Analyze Discovered Pages** to process pages in small batches without PHP timeouts.
4. Review results in:
   - **Discovered Records:** Click **Preview** on any record to inspect the DOM-extracted clean text, headings, and metadata.
   - **Pending Review:** Inspect records flagged for sensitive terms (tuition, fees, eligibility, admission deadlines) or changed hashes.
5. Click **Export Dry-Run CSV Report** to download an audit trail for institutional review.
