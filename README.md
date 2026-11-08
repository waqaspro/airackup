# Airackup &bull; Unlimited WordPress Backup, Restore & Migration Plugin

[![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/plugins/airackup/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
[![Tested up to](https://img.shields.io/badge/Tested%20Up%20To-WordPress%207.1-green.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3-8892BF.svg)](https://php.net)
[![Website](https://img.shields.io/badge/Official%20Site-airackup.waqaspro.com-4f46e5)](https://airackup.waqaspro.com)

**Airackup** is a zero-limitation WordPress backup, restore, and site migration plugin built to end artificial upload paywalls and shared-hosting timeout crashes. 

Unlike traditional plugins that cap free restores at 512MB and demand $69&ndash;$99/year for cloud storage add-ons, Airackup streams standard POSIX TAR archives directly to disk with a micro-batched, zero-memory execution engine.

---

## 🚀 Key Features

- **100% Free & Unlimited:** Back up and restore 1GB, 10GB, 50GB, or 100GB+ sites with zero file size restrictions or restore paywalls.
- **Zero-Memory Streaming Engine:** Writes standard POSIX TAR chunks directly to disk with strict 4-second micro-execution budgets. Prevents PHP RAM spikes and 504 gateway timeouts on shared hosting.
- **1-Click Cloud Connections:** Native Google Drive and Dropbox OAuth linking, plus Amazon S3, Cloudflare R2, Wasabi, DigitalOcean Spaces, and MinIO via AWS Signature Version 4.
- **Deep Serialized Search & Replace:** Recalculates byte lengths in serialized PHP objects during restoration. Safely migrates domains, site URLs, filesystem paths, and table prefixes without breaking Elementor, widgets, or user roles.
- **Automated Hands-Off Schedules:** Daily, weekly, or monthly background backups powered by WP-Cron, complete with automated retention rotation.
- **Enterprise Security Lockdown:** Backup vaults are shielded with multi-layer server access controls (`.htaccess`, `web.config`, and silent `index.php` traps).
- **Zero Telemetry:** 100% private. No tracking, phone-homes, or remote data collection.

---

## 📊 Comparison Matrix

| Capability | Airackup | UpdraftPlus | All-in-One WP Migration |
| :--- | :---: | :---: | :---: |
| **Restore File Size Limit** | **UNLIMITED (Free)** | Unlimited | 512MB Max (Paid Extension) |
| **Google Drive 1-Click Sync** | **Included Free** | Included (Basic) | Requires $99/yr Extension |
| **Dropbox 1-Click Sync** | **Included Free** | Included (Basic) | Requires $99/yr Extension |
| **Amazon S3 & Cloudflare R2** | **Included Free (SigV4)** | Included (Basic) | Requires $99/yr Extension |
| **Zero-Memory Streaming Engine** | **POSIX TAR Chunks** | ZipArchive (Memory Spikes) | Custom Archive |
| **Domain Migration Replacer** | **Deep Serialized Engine** | Requires Migrator Addon | Included |
| **Table Prefix Translation** | **Automatic User Role Sync** | Basic | Automatic |
| **Automated Background Schedules** | **Hourly / Daily / Weekly** | Included | Requires Paid Addon |

---

## 📦 Installation

1. Download [`airackup.zip`](https://github.com/waqaspro/airackup/releases) or search for **Airackup** in your WordPress dashboard under **Plugins &rarr; Add New**.
2. Click **Install Now** and activate the plugin.
3. Navigate to the **Airackup** menu in your WordPress sidebar to run your first backup or configure automated cloud schedules.

---

## 🔒 Privacy & Security

- **Direct Cloud Transfers:** Backup files stream directly between your hosting server and your cloud storage provider's API.
- **Local Credentials:** OAuth tokens and API secrets are stored strictly within your local WordPress database in the `wp_options` table.
- **Google API Services User Data Policy:** Airackup complies with Google API Services User Data Policy Limited Use requirements.

---

## 📄 License

Airackup is free and open-source software licensed under the [GNU General Public License v2 or later](LICENSE).

---

## 👨‍💻 Author

Crafted with excellence by [**waqaspro.com**](https://waqaspro.com/).
