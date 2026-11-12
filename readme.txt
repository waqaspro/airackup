=== Airackup ===
Contributors: itswaqaspro
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

**Airackup** is a fast, resilient WordPress backup, restore, and domain migration plugin engineered for modern WordPress websites of any size.

Airackup processes archives without artificial file size caps, allowing you to back up, migrate, and restore full sites or databases using a streaming architecture built to run reliably even on shared hosting environments.

### Key Features

* **Unlimited Archive Sizes:** Back up and restore websites of any size—from small blogs to 50GB+ enterprise sites—without artificial size caps.
* **True Streaming Architecture:** Processes files and database dumps in discrete, memory-bounded micro-batches using pure POSIX USTAR format, avoiding PHP `max_execution_time` and `memory_limit` timeouts.
* **Smart Incremental Backups:** Tracks SHA-256 state hashes and backs up new or modified files since your previous baseline.
* **Cloud Storage Integrations:** Connect to Google Drive, Dropbox, Amazon S3, Cloudflare R2, DigitalOcean Spaces, or any S3-compatible bucket.
* **Safe Staging Restore:** Extracts archive contents into an isolated staging directory first, verifying archive integrity before deploying live file replacements.
* **Full Serialized Search & Replace:** Migrates website domain URLs, database table prefixes, and filesystem paths cleanly across hosts while preserving WordPress serialized arrays and objects.
* **Automated Cron Schedules:** Schedule hourly, daily, or weekly automatic backups with configurable retention pruning.
* **Protected Backup Storage:** Stores local backups inside a dedicated folder in your WordPress uploads directory, hardened with `.htaccess`, `index.php`, and `web.config` access restrictions.

== Configuration & Usage ==

1. **Create a Backup:** Go to **Airackup > Backups & Snapshots**, choose **Full Backup** or **Database Only**, select your storage destination, and click **Create Backup Now**.
2. **Connect Cloud Storage:** Go to **Airackup > Cloud Storage** to connect Google Drive or Dropbox using 1-Click Connect (or custom developer credentials), or enter your S3-compatible endpoint and keys.
3. **Restore or Migrate:** Go to **Airackup > Restore / Migrate**, drag and drop your `.airakup` archive (or select an existing snapshot), enter your target domain URL if migrating to a new domain, and start the restore.
4. **Automate Schedules:** Go to **Airackup > Settings** to enable scheduled backups and configure retention limits.

== External services ==

This plugin relies on external cloud storage and authentication services when you choose to connect a cloud storage provider. No external requests are made unless you explicitly initiate a cloud connection or upload/download a backup to a remote provider.

= Google Drive API =
* **What it is and what it is used for:** Used to authenticate your Google account and upload, list, download, or delete your backup archives in your personal Google Drive.
* **What data is sent and when:** When you click "Sign in with Google" or authorize custom OAuth/Service Account credentials, authentication tokens are exchanged with Google (`https://accounts.google.com/o/oauth2/v2/auth`, `https://oauth2.googleapis.com/token`). When you run or manage a Google Drive backup, your backup archive and file metadata are transmitted directly from your WordPress server to Google Drive (`https://www.googleapis.com/upload/drive/v3/files`, `https://www.googleapis.com/drive/v3/files`).
* **Terms of Service:** [https://policies.google.com/terms](https://policies.google.com/terms)
* **Privacy Policy:** [https://policies.google.com/privacy](https://policies.google.com/privacy)

= Dropbox API =
* **What it is and what it is used for:** Used to authenticate your Dropbox account and upload, list, download, or delete your backup archives in your Dropbox account.
* **What data is sent and when:** When you connect Dropbox, your authorization/access token is verified with Dropbox (`https://www.dropbox.com/oauth2/authorize`, `https://api.dropboxapi.com/oauth2/token`, `https://api.dropboxapi.com/2/users/get_current_account`). When you run or manage a Dropbox backup, your backup archive and file metadata are transmitted directly from your WordPress server to Dropbox (`https://content.dropboxapi.com/2/files/upload`).
* **Terms of Service:** [https://www.dropbox.com/terms](https://www.dropbox.com/terms)
* **Privacy Policy:** [https://www.dropbox.com/privacy](https://www.dropbox.com/privacy)

= Airackup 1-Click OAuth Broker =
* **What it is and what it is used for:** An optional OAuth relay deployed by waqaspro.com via Cloudflare Workers (`https://airackup-auth.itswaqas-pro.workers.dev`) that lets you connect Google Drive or Dropbox in one click without creating your own developer app credentials.
* **What data is sent and when:** Only when you click the 1-Click Connect button for Google Drive or Dropbox, your WordPress admin site URL and a security nonce are passed to initiate the OAuth flow, and the resulting authorization token is returned to your WordPress admin. No backup archives, database dumps, or site files are ever transmitted through or stored on this service.
* **Terms of Service:** [https://airackup.waqaspro.com/terms](https://airackup.waqaspro.com/terms)
* **Privacy Policy:** [https://airackup.waqaspro.com/privacy](https://airackup.waqaspro.com/privacy)
* **Cloudflare Privacy Policy:** [https://www.cloudflare.com/privacypolicy/](https://www.cloudflare.com/privacypolicy/)

== Installation ==

1. Upload the `airackup` directory to your `/wp-content/plugins/` directory, or install it directly via the WordPress **Plugins > Add New** screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Airackup** in your WordPress admin menu.
4. Click **Create Backup Now** to create an instant full backup of your website, or configure automated schedules and cloud storage.

== Frequently Asked Questions ==

= Is Airackup free to use without backup size limits? =
Yes. Airackup uses streaming I/O in pure POSIX USTAR format so you can back up and restore websites of any size without arbitrary file size caps.

= Can I use Airackup to migrate my website to a new domain or host? =
Yes. You can download your `.airakup` archive from your source site, upload it on your destination site under **Restore / Migrate**, and Airackup will automatically update database domain URLs, table prefixes, and filesystem paths.

= Where are my local backups stored? =
Local backups are stored in a protected `airackup` folder inside your WordPress uploads directory (resolved via `wp_upload_dir()`), protected against direct HTTP downloads with `.htaccess`, `web.config`, and `index.php` rules.

= Which cloud storage providers are supported? =
Airackup supports Google Drive, Dropbox, Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, and other S3-compatible services.

== Screenshots ==

1. **Backups & Snapshots:** Dashboard overview of all local and cloud backup archives with single-click creation and download.
2. **Restore & Migration:** Drag-and-drop restore engine with automatic database search-and-replace.
3. **Cloud Storage Settings:** 1-Click Google Drive and Dropbox authorization.
4. **Schedules & Settings:** Automated hourly, daily, or weekly scheduled backups with retention auto-pruning.

== Changelog ==

= 1.0.5 =
* Added 1-Click Google Drive & Dropbox Cloud Authentication Relay.
* Improved tab state retention across connect/disconnect redirects.
* Added comprehensive exclusions for third-party backup folders and caches.
* Enhanced serialized search-and-replace engine for multi-domain migrations.

= 1.0.0 =
* Initial public release with POSIX streaming TAR, safe staging restore, incremental delta detection, and cloud storage adapters.

== Upgrade Notice ==

= 1.0.5 =
Includes enhanced cloud storage connectors, tab state retention, and improved migration safety checks.
