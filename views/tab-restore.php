<?php
if (!defined('ABSPATH')) exit;
$airackup_current_url = get_option('siteurl');
?>
<div class="airackup-card">
    <div class="airackup-card-header">
        <h2 class="airackup-card-title">Upload & Restore Backup Archive</h2>
    </div>

    <!-- Drag and Drop Zone -->
    <div id="airackup-dropzone" class="airackup-dropzone">
        <div class="airackup-dropzone-icon">
            <span class="dashicons dashicons-cloud-upload"></span>
        </div>
        <h3 style="margin:0 0 8px; font-size:18px;">Drag & Drop Backup Archive (.airakup / .airackup) Here</h3>
        <p style="color:var(--air-gray); margin:0 0 16px; font-size:13px;">Bypasses all PHP and server upload limits using 2MB resumable chunk slicing.</p>
        <button type="button" id="airackup-btn-browse" class="airackup-btn airackup-btn-primary">
            Browse File
        </button>
        <input type="file" id="airackup-file-input" accept=".airakup,.airackup,.tar" style="display:none;" />
    </div>

    <!-- Domain Migration Settings -->
    <div style="margin-top:30px; padding-top:24px; border-top:1px solid var(--air-border);">
        <h3 style="margin:0 0 10px; font-size:16px;">Domain & URL Migration (Serialized Replacement)</h3>
        <p style="color:var(--air-gray); font-size:13px; margin:0 0 16px;">
            If this backup is from a different domain or localhost, enter the old URL below. Airackup will safely update all database entries, serialized objects, and JSON page builder elements.
        </p>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            <div>
                <label style="display:block; font-weight:600; margin-bottom:6px; font-size:13px;">Old Site URL (In Backup):</label>
                <input type="url" id="airackup-search-url" placeholder="https://old-domain.com" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid var(--air-border);" />
                <span style="font-size:11px; color:var(--air-gray);">Leave empty to auto-detect from backup metadata.</span>
            </div>
            <div>
                <label style="display:block; font-weight:600; margin-bottom:6px; font-size:13px;">New Site URL (Current):</label>
                <input type="url" id="airackup-replace-url" value="<?php echo esc_attr($airackup_current_url); ?>" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
        </div>
    </div>
</div>
