<?php
if (!defined('ABSPATH')) exit;
$airackup_settings = get_option('airackup_settings', array());
$airackup_freq = isset($airackup_settings['schedule_frequency']) ? $airackup_settings['schedule_frequency'] : 'disabled';
$airackup_retention = isset($airackup_settings['retention_count']) ? $airackup_settings['retention_count'] : 5;
$airackup_is_incremental = isset($airackup_settings['enable_incremental']) ? $airackup_settings['enable_incremental'] : 0;
?>
<div class="airackup-card">
    <div class="airackup-card-header">
        <h2 class="airackup-card-title">Automated Schedules & Retention</h2>
    </div>

    <div style="max-width:700px;">
        <div style="margin-bottom:20px;">
            <label style="display:block; font-weight:600; margin-bottom:6px; font-size:13px;">Backup Frequency:</label>
            <select id="airackup-setting-freq" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid var(--air-border);">
                <option value="disabled" <?php selected($airackup_freq, 'disabled'); ?>>Disabled (Manual Only)</option>
                <option value="daily" <?php selected($airackup_freq, 'daily'); ?>>Once Daily</option>
                <option value="weekly" <?php selected($airackup_freq, 'weekly'); ?>>Once Weekly</option>
                <option value="monthly" <?php selected($airackup_freq, 'monthly'); ?>>Once Monthly</option>
            </select>
        </div>

        <div style="margin-bottom:20px;">
            <label style="display:block; font-weight:600; margin-bottom:6px; font-size:13px;">Auto-Retention Pruning:</label>
            <input type="number" id="airackup-setting-retention" min="1" max="50" value="<?php echo esc_attr($airackup_retention); ?>" style="width:100px; padding:8px 12px; border-radius:6px; border:1px solid var(--air-border);" />
            <span style="font-size:13px; color:var(--air-gray); margin-left:8px;">Keep only the latest [N] backups (automatically deletes older archives to save disk space).</span>
        </div>

        <div style="margin-bottom:24px; padding:15px; background:var(--air-light); border-radius:8px; border:1px solid var(--air-border);">
            <label style="display:flex; align-items:center; gap:10px; font-weight:600; cursor:pointer;">
                <input type="checkbox" id="airackup-setting-incremental" value="1" <?php checked($airackup_is_incremental, 1); ?> />
                Enable Incremental Delta Engine
            </label>
            <p style="margin:6px 0 0 28px; font-size:12px; color:var(--air-gray);">
                Only packages new or modified files since the previous baseline. Reduces backup archive size by up to 95% and saves bandwidth.
            </p>
        </div>

        <button type="button" id="airackup-btn-save-schedule" class="airackup-btn airackup-btn-primary">
            Save Schedule Settings
        </button>
    </div>
</div>

<div class="airackup-card" style="margin-top:24px;">
    <div class="airackup-card-header">
        <h2 class="airackup-card-title">Version & Updates</h2>
    </div>

    <div style="max-width:700px;">
        <div style="background:var(--air-light); border:1px solid var(--air-border); border-radius:8px; padding:16px; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-weight:600; font-size:13px;">Installed Version:</span>
                <span style="font-weight:700; color:var(--air-primary); font-family:monospace; font-size:14px; background:#fff; padding:3px 10px; border-radius:4px; border:1px solid var(--air-border);">
                    v<?php echo esc_html(defined('AIRACKUP_VERSION') ? AIRACKUP_VERSION : '1.0.5'); ?>
                </span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-weight:600; font-size:13px;">Author & Developer:</span>
                <a href="https://waqaspro.com/" target="_blank" style="font-weight:600; color:var(--air-primary); text-decoration:none;">
                    waqaspro.com <span class="dashicons dashicons-external" style="font-size:14px; vertical-align:middle; text-decoration:none;"></span>
                </a>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:600; font-size:13px;">Update Channel:</span>
                <span style="font-size:12px; color:var(--air-gray);">Official WordPress.org Plugin Directory</span>
            </div>
        </div>

        <p style="font-size:13px; color:var(--air-gray); margin:0;">
            Updates are managed and verified directly through the official WordPress.org Plugin Directory.
        </p>
    </div>
</div>
