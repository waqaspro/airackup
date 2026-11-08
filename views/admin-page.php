<?php
if (!defined('ABSPATH')) exit;

$airackup_allowed_tabs = array('backups', 'restore', 'storage', 'settings');
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switching is view-only navigation.
$airackup_raw_tab      = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'backups';
$airackup_active_tab   = in_array($airackup_raw_tab, $airackup_allowed_tabs, true) ? $airackup_raw_tab : 'backups';
?>
<div class="airackup-wrap">
    <!-- Header -->
    <div class="airackup-header">
        <div class="airackup-brand">
            <div class="airackup-brand-icon">
                <span class="dashicons dashicons-cloud-saved"></span>
            </div>
            <div>
                <h1>Airackup <span style="font-size:12px; font-weight:normal; background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:12px; margin-left:6px; vertical-align:middle;">v<?php echo esc_html(defined('AIRACKUP_VERSION') ? AIRACKUP_VERSION : '1.0.5'); ?></span></h1>
                <p>Zero-limitation WordPress Backup, Restore & Domain Migration &bull; by <a href="https://waqaspro.com/" target="_blank" style="color:#fff; text-decoration:underline;">waqaspro.com</a></p>
            </div>
        </div>
        <div class="airackup-badge">
            Zero Limitations &bull; Unlimited Size
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="airackup-tabs">
        <button class="airackup-tab-btn <?php echo $airackup_active_tab === 'backups' ? 'active' : ''; ?>" data-tab="backups">
            <span class="dashicons dashicons-database"></span> Backups & Snapshots
        </button>
        <button class="airackup-tab-btn <?php echo $airackup_active_tab === 'restore' ? 'active' : ''; ?>" data-tab="restore">
            <span class="dashicons dashicons-upload"></span> Restore / Migrate
        </button>
        <button class="airackup-tab-btn <?php echo $airackup_active_tab === 'storage' ? 'active' : ''; ?>" data-tab="storage">
            <span class="dashicons dashicons-cloud"></span> Cloud Storage
        </button>
        <button class="airackup-tab-btn <?php echo $airackup_active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
            <span class="dashicons dashicons-admin-settings"></span> Schedules & Settings
        </button>
    </div>

    <!-- Tab Contents -->
    <div id="tab-backups" class="airackup-tab-content <?php echo $airackup_active_tab === 'backups' ? 'active' : ''; ?>" style="<?php echo $airackup_active_tab === 'backups' ? '' : 'display:none;'; ?>">
        <?php require_once __DIR__ . '/tab-backups.php'; ?>
    </div>

    <div id="tab-restore" class="airackup-tab-content <?php echo $airackup_active_tab === 'restore' ? 'active' : ''; ?>" style="<?php echo $airackup_active_tab === 'restore' ? '' : 'display:none;'; ?>">
        <?php require_once __DIR__ . '/tab-restore.php'; ?>
    </div>

    <div id="tab-storage" class="airackup-tab-content <?php echo $airackup_active_tab === 'storage' ? 'active' : ''; ?>" style="<?php echo $airackup_active_tab === 'storage' ? '' : 'display:none;'; ?>">
        <?php require_once __DIR__ . '/tab-storage.php'; ?>
    </div>

    <div id="tab-settings" class="airackup-tab-content <?php echo $airackup_active_tab === 'settings' ? 'active' : ''; ?>" style="<?php echo $airackup_active_tab === 'settings' ? '' : 'display:none;'; ?>">
        <?php require_once __DIR__ . '/tab-settings.php'; ?>
    </div>

    <!-- Progress Modal -->
    <div id="airackup-progress-modal" class="airackup-modal">
        <div class="airackup-modal-box">
            <h3 id="airackup-modal-title" style="margin:0 0 10px;">Processing...</h3>
            <p id="airackup-modal-desc" style="color:var(--air-gray); margin:0;">Please keep this browser window open while Airackup completes the task.</p>
            
            <div class="airackup-progress-bar-wrap">
                <div id="airackup-progress-fill" class="airackup-progress-bar-fill"></div>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600;">
                <span id="airackup-status-text">Starting...</span>
                <span id="airackup-percent-text">0%</span>
            </div>

            <div id="airackup-log" class="airackup-status-log">
                [Ready] Initializing Airackup micro-batch worker...
            </div>

            <div style="margin-top:20px; text-align:right; display:flex; justify-content:flex-end; gap:10px;">
                <button id="airackup-btn-close-modal" class="airackup-btn airackup-btn-secondary" style="display:none;">Close</button>
                <a id="airackup-btn-download-modal" href="#" class="airackup-btn airackup-btn-primary" style="display:none;">
                    <span class="dashicons dashicons-download"></span> Download Backup
                </a>
            </div>
        </div>
    </div>
</div>
