=== Airackup ===
Contributors: waqaspro
Donate link: https://waqaspro.com/
Tags: backup, migration, restore, all in one, clone
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-limitation WordPress backup, restore & migration. Unlimited file sizes, incremental backups, Google Drive & Dropbox cloud storage.

== Description ==

**Airackup** is a fast, resilient, zero-limitation WordPress backup, restore, and domain migration plugin engineered for modern WordPress websites of any size.

Unlike traditional backup and migration plugins that enforce arbitrary 512MB upload caps, memory timeouts, or paid paywalls just to restore your own site, **Airackup imposes zero limitations on archive size, database size, or file counts.**

Whether you are looking for a complete **all in one** site migration solution or a modern, lightweight alternative to legacy tools like **UpdraftPlus** or **All-in-One WP Migration**, Airackup delivers military-grade resilience without bloat.

### 🚀 Key Features

* **Zero Size Limitations:** No 512MB limits, no paywalls, and no arbitrary timeouts. Back up and restore sites from 50MB to 50GB+.
* **True Streaming Architecture:** Processes files and database dumps in discrete, memory-bounded chunks using pure POSIX USTAR format, bypassing PHP `max_execution_time` and `memory_limit` crashes.
* **Smart Incremental Backups:** Tracks SHA-256 state hashes and only backs up new or modified files since your previous baseline, reducing backup sizes by up to 95%.
* **1-Click Cloud Storage Integration:** Connect directly to Google Drive, Dropbox, Amazon S3, Cloudflare R2, or DigitalOcean Spaces.
* **Safe Staging Restore:** Restores archive contents into an isolated quarantine folder first, verifying archive integrity before committing live replacements to prevent half-broken sites.
* **Full Serialized Search & Replace:** Migrates website domain URLs, database table prefixes, and absolute filesystem paths cleanly across hosts without corrupting WordPress serialized arrays or objects.
* **Automated Cron Schedules:** Schedule daily, weekly, or monthly automatic backups with intelligent retention pruning to save server storage.
* **Clean Backup Directory Protection:** Hardens storage directories with `.htaccess`, empty `index.php`, and `web.config` anti-execution directives. Automatically filters out third-party backup clutter (`ai1wm-backups`, `updraftplus`, caches, debug logs).

---

### 🛡️ 1-Click Cloud Storage & External Services

Airackup provides optional 1-click cloud authentication for Google Drive and Dropbox to streamline connecting your cloud storage without forcing you to manually configure developer console tokens.

**Third-Party Services Disclosures:**

* **Service:** Airackup 1-Click OAuth Broker
  * **Provider:** Deployed by waqaspro.com via Cloudflare Workers
  * **Service URL:** `https://airackup-auth.itswaqas-pro.workers.dev`
  * **What it does:** Securely relays OAuth authorization codes for Google Drive and Dropbox back to your WordPress admin dashboard upon initial connection. No backup archives or website files are ever transmitted through or stored on this relay; all backups upload directly from your server to your own cloud storage bucket.
  * **Terms of Service:** [https://airackup.waqaspro.com/terms/](https://airackup.waqaspro.com/terms/)
  * **Privacy Policy:** [https://airackup.waqaspro.com/privacy/](https://airackup.waqaspro.com/privacy/)
  * **Cloudflare Privacy Policy:** [https://www.cloudflare.com/privacypolicy/](https://www.cloudflare.com/privacypolicy/)

---

== Installation ==

1. Upload the `airackup` directory to your `/wp-content/plugins/` directory, or install it directly via the WordPress **Plugins > Add New** screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Airackup** in your WordPress admin menu.
4. Click **Create Backup Now** to create an instant full backup of your website, or configure automated schedules and cloud storage.

== Frequently Asked Questions ==

= Is Airackup really free with no backup size limits? =
Yes. There are no artificial file size caps, no 512MB limits, and no paid unlock screens. Airackup uses streaming I/O to handle websites of any size without server exhaustion.

= How does Airackup compare to All-in-One WP Migration or UpdraftPlus? =
Airackup was built to solve the most frustrating issues in traditional backup plugins: arbitrary upload caps, corrupted serialized database strings during domain migration, and complex cloud setup. Airackup includes full domain migration, 1-click cloud connectors, and incremental delta backups natively.

= Can I use Airackup to migrate my website to a new domain or host? =
Yes. You can download your `.airakup` archive from your source site, upload it on your destination site under **Restore / Migrate**, and Airackup will automatically update database domain URLs, table prefixes, and filesystem paths.

= Where are my local backups stored? =
Local backups are stored in your WordPress uploads directory under `/wp-content/uploads/airackup-vault/`, protected against direct HTTP downloads with custom security rules.

= Which cloud storage providers are supported? =
Airackup supports Google Drive, Dropbox, Amazon S3, Cloudflare R2, and DigitalOcean Spaces.

== Screenshots ==

1. **Backups & Snapshots:** Dashboard overview of all local and cloud backup archives with single-click creation and download.
2. **Restore & Migration:** Zero-limit drag-and-drop restore engine with automatic database search-and-replace.
3. **Cloud Storage Settings:** 1-Click Google Drive and Dropbox authorization.
4. **Schedules & Settings:** Automated daily, weekly, or monthly scheduled backups with retention auto-pruning.

== Changelog ==

= 1.0.5 =
* Added 1-Click Google Drive & Dropbox Cloud Authentication Relay.
* Added built-in self-hosted update pipeline.
* Improved tab state retention across connect/disconnect redirects.
* Added comprehensive exclusions for third-party backup folders and caches.
* Enhanced serialized search-and-replace engine for multi-domain migrations.

= 1.0.0 =
* Initial public release with POSIX streaming TAR, safe staging restore, incremental delta detection, and cloud storage adapters.

== Upgrade Notice ==

= 1.0.5 =
Includes enhanced cloud storage connectors, tab state retention, and improved migration safety checks.
