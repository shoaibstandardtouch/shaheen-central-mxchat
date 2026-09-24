# Installation, Configuration & Onboarding Guide

## Plugin: Shaheen Central MXChat Sync (Phase 1)
**Target Host Website:** `https://shaheengroup.org`  
**Purpose:** Synchronize approved Shaheen Group branch and academy websites into one central MXChat knowledge base on `shaheengroup.org`.

---

## 🔒 Mandatory Phase 1 Constraints
> [!IMPORTANT]
> - **Live MXChat posting is 100% disabled in Phase 1.** No records are posted to `https://shaheengroup.org/wp-json/mxchat/v1/knowledge`.
> - **Zero Transcript API requests.** No transcripts are fetched from `https://shaheengroup.org/wp-json/mxchat/v1/transcripts`.
> - **No second chatbot.** Only the single central chatbot exists on `shaheengroup.org`. Remote sites act purely as crawled content repositories.
> - **Continuous Learning is NOT enabled.** Knowledge Improvement Queue is an architectural placeholder for human review and official source verification.

---

## 📦 System Requirements
- **WordPress:** 5.8 to 6.7+
- **PHP:** 7.4 to 8.3+ (Fully tested on PHP 8.2.20)
- **PHP Extensions:** `ext-dom`, `ext-libxml`, `ext-simplexml`
- **User Role:** Administrator (`manage_options` capability)

---

## 🛠️ Installation Steps

### Option A: WordPress Admin Upload (Recommended)
1. Download `shaheen-central-mxchat-sync.zip`.
2. In WordPress Admin on `https://shaheengroup.org`, go to **Plugins** &rarr; **Add New** &rarr; **Upload Plugin**.
3. Choose `shaheen-central-mxchat-sync.zip` and click **Install Now**.
4. Click **Activate Plugin**.

### Option B: SFTP / Direct Upload
1. Extract `shaheen-central-mxchat-sync.zip`.
2. Upload the `shaheen-central-mxchat-sync` folder to your server at `/wp-content/plugins/`.
3. In WordPress Admin, navigate to **Plugins** and activate **Shaheen Central MXChat Sync**.

---

## 🗄️ Database Tables Initialized Automatically
Upon activation, the plugin creates five custom indexed tables via `dbDelta()`:
1. `wp_shaheen_sync_sources` — Approved and draft source websites, crawl frequencies, statuses.
2. `wp_shaheen_sync_records` — Discovered URLs, normalized canonical keys, baseline and candidate content, SHA-256 hashes, review flags.
3. `wp_shaheen_sync_runs` — Persistent sync runs, maximum page ceilings, processed item tallies.
4. `wp_shaheen_knowledge_queue` — Knowledge improvement architecture for human review of unanswered visitor queries.
5. `wp_shaheen_sync_logs` — Activity and error log entries with secret/token redaction.

---

## 🌐 Future Source Onboarding Workflow (13 Steps)
To onboard a new Shaheen website (e.g. `bidar.shaheengroup.org`):
1. In the admin menu, click **Shaheen Central Sync** &rarr; **Source Websites**.
2. Under **Onboard New Source Website**, enter Institution Name, Domain, HTTPS Sitemap URL, Category, Language, and Frequency. Click **Add Source as Draft**.
3. The source is saved with status `draft` and disabled (`is_enabled=0`).
4. Click **Validate** on the new source row:
   - Verifies HTTPS reachability.
   - Tests exact host matching and blocks localhost/private IPs (SSRF protection).
   - Validates controlled redirects up to 5 hops.
   - Parses the XML index and displays detected child page/post sitemaps vs excluded taxonomy/media sitemaps.
5. Once validated, status transitions to `dry_run`.
6. Click **Dry Test**:
   - Fetches a 5-page sample batch.
   - Extracts and cleans DOM content.
   - Tests classification and displays sample results without creating live database rows.
7. Click **Approve**:
   - Status transitions to `approved`.
8. Toggle **Sync Enabled**:
   - Sets `is_enabled=1`. The source is now active for scheduled and manual crawling.
9. Note: If a domain name is ever edited, the source automatically reverts to `draft` and is disabled until re-validated.
10. Note: Archiving a source disables it from future syncs while preserving all historical records.

---

## 🧪 Dry-Run Testing & Execution Guide

1. Go to **Shaheen Central Sync** &rarr; **Dashboard**.
2. Click **Dry Run All Enabled Sources**:
   - Crawls sitemap indexes of approved and enabled sources.
   - Identifies new URLs and queues existing URLs for re-check.
   - Flags removed URLs as `missing_from_source`.
3. Click **Process Next Batch**:
   - Analyzes eligible records (`discovered` and `queued_check`).
   - Normalizes canonical URLs and checks for canonical collisions.
   - Cleans boilerplate (headers, footers, sidebars, cookie banners, popups, scripts).
   - Calculates candidate SHA-256 content hashes.
   - Compares candidate hash against accepted baseline hash:
     - Identical: marked `unchanged`.
     - Discrepancy: marked `pending_review` (accepted content is preserved, candidate content stored separately).
     - Sensitive terms detected: marked `pending_review`.
     - Content < 30 words: marked `needs_review` (held safely without repeated loops).
   - Halts cleanly when configured maximum pages per run is reached with notification.
4. Review records across the 12 tabs:
   - **New Content:** Inspect newly discovered pages.
   - **Pending Review:** Click **Compare & Review** to view side-by-side accepted baseline vs candidate text.
   - **Skipped URLs:** View exclusions and specific skip reasons.
5. Click **Export Dry-Run CSV Report** to download complete audit logs for institutional stakeholders.

---

## 🛡️ Security & Privacy Notes
- **Controlled Redirects:** Automatic HTTP client redirection is disabled. Every redirect hop is manually followed, resolving relative URLs and enforcing SSRF checks (no localhost, loopbacks, or RFC 1918 private IPs) on every hop.
- **Secret Redaction:** `Shaheen_Logger` scrubs Bearer tokens, Authorization headers, passwords, and cookies before writing to the database.
- **Password Protection Detection:** Pages containing password inputs (`post_password`, `.post-password-form`) are rejected before extraction and never saved into candidate or accepted content.
- **Human Review Safeguard:** The Knowledge Improvement Queue explicitly requires human verification and official source linking before any usage-based question can ever be trained.
