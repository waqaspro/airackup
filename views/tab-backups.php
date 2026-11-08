<?php
if (!defined('ABSPATH')) exit;

require_once AIRACKUP_DIR . 'includes/storage/class-airackup-storage-local.php';
$airackup_storage = new Airackup_Storage_Local();
$airackup_backups = $airackup_storage->list_backups();
?>
<div class="airackup-card">
    <div class="airackup-card-header">
        <h2 class="airackup-card-title">Site Backups on Server</h2>
        <div style="display:flex; gap:10px;">
            <select id="airackup-backup-type-select" style="border-radius:6px; padding:6px 10px; border-color:var(--air-border);">
                <option value="full">Full Backup (DB + Files)</option>
                <option value="db_only">Database Only</option>
            </select>
            <button id="airackup-btn-create-backup" class="airackup-btn airackup-btn-primary">
                <span class="dashicons dashicons-plus-alt2"></span> Create Backup Now
            </button>
        </div>
    </div>

    <?php if (!is_ssl()) : ?>
        <div style="background:#fff8e5; border-left:4px solid #f0b849; padding:10px 14px; margin-bottom:16px; border-radius:4px; font-size:12px; color:#614700; line-height:1.5;">
            <strong>💡 Note for Local HTTP Sites:</strong> Because this site is running on unencrypted <code>http://</code>, Google Chrome will flag <em>"This site isn't using a secure connection / Insecure download"</em> for downloaded files. You can safely click <strong>"Keep"</strong> in Chrome's download bar, or switch to <a href="<?php echo esc_url(set_url_scheme(admin_url('admin.php?page=airackup'), 'https')); ?>" style="font-weight:bold; color:#183ad6;">HTTPS</a> for 100% warning-free downloads.
        </div>
    <?php endif; ?>

    <?php if (empty($airackup_backups)) : ?>
        <div style="text-align:center; padding:50px 20px; color:var(--air-gray);">
            <span class="dashicons dashicons-archive" style="font-size:48px; width:48px; height:48px; margin-bottom:12px;"></span>
            <p style="font-size:15px; margin:0;">No backups created yet. Click <strong>"Create Backup Now"</strong> above to make your first secure backup.</p>
        </div>
    <?php else : ?>
        <table class="airackup-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>File Name</th>
                    <th>Size</th>
                    <th>Storage</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($airackup_backups as $airackup_b) : ?>
                    <tr>
                        <td><strong><?php echo esc_html(gmdate('Y-m-d H:i:s', $airackup_b['date'])); ?></strong></td>
                        <td><code><?php echo esc_html($airackup_b['filename']); ?></code></td>
                        <td><?php echo esc_html(size_format($airackup_b['size'])); ?></td>
                        <td><span class="airackup-badge"><?php echo esc_html(strtoupper($airackup_b['storage'])); ?></span></td>
                        <td style="text-align:right;">
                            <?php 
                            $airackup_clean_fn = preg_replace('/\.(tar|airackup)$/i', '.airakup', $airackup_b['filename']);
                            $airackup_dl_url = wp_nonce_url(
                                admin_url('admin-post.php?action=airackup_download_backup&file=' . rawurlencode($airackup_b['filename'])),
                                'airackup_download_' . $airackup_b['filename']
                            );
                            ?>
                            <a href="<?php echo esc_url($airackup_dl_url); ?>" download="<?php echo esc_attr($airackup_clean_fn); ?>" class="airackup-btn airackup-btn-secondary airackup-action-download" data-filename="<?php echo esc_attr($airackup_clean_fn); ?>" data-url="<?php echo esc_url($airackup_dl_url); ?>">
                                <span class="dashicons dashicons-download"></span> Download
                            </a>
                            <button class="airackup-btn airackup-btn-primary airackup-action-restore" data-file="<?php echo esc_attr($airackup_b['filename']); ?>">
                                <span class="dashicons dashicons-update"></span> Restore
                            </button>
                            <button class="airackup-btn airackup-btn-danger airackup-action-delete" data-file="<?php echo esc_attr($airackup_b['filename']); ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
