<?php
if (!defined('ABSPATH')) exit;

$airackup_settings = get_option('airackup_settings', array());
$airackup_s3       = isset($airackup_settings['s3']) ? $airackup_settings['s3'] : array();
$airackup_gdrive   = isset($airackup_settings['gdrive']) ? $airackup_settings['gdrive'] : array();
$airackup_dropbox  = isset($airackup_settings['dropbox']) ? $airackup_settings['dropbox'] : array();
$airackup_sftp     = isset($airackup_settings['sftp']) ? $airackup_settings['sftp'] : array();

require_once AIRACKUP_DIR . 'includes/storage/class-airackup-storage-gdrive.php';
require_once AIRACKUP_DIR . 'includes/storage/class-airackup-storage-dropbox.php';

$airackup_gdrive_storage  = new Airackup_Storage_GDrive($airackup_gdrive);
$airackup_gdrive_status   = $airackup_gdrive_storage->test_connection();

$airackup_dropbox_storage = new Airackup_Storage_Dropbox($airackup_dropbox);
$airackup_dropbox_status  = $airackup_dropbox_storage->test_connection();

$airackup_gdrive_auth_url  = $airackup_gdrive_storage->get_auth_url(admin_url('admin.php?page=airackup&auth=gdrive'));
$airackup_dropbox_auth_url = $airackup_dropbox_storage->get_auth_url(admin_url('admin.php?page=airackup&auth=dropbox'));
?>
<div class="airackup-card">
    <div class="airackup-card-header">
        <h2 class="airackup-card-title">Remote Cloud Storage Integrations</h2>
        <span class="airackup-badge">Multi-Cloud Ready</span>
    </div>
    <p style="color:var(--air-gray); font-size:13px; margin:0 0 24px;">
        Optionally mirror and store your backups off-site across cloud object storage or remote servers.
    </p>

    <!-- 1. Google Drive (Service Account + OAuth) -->
    <div style="border:1px solid var(--air-border); border-radius:8px; padding:20px; margin-bottom:20px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="dashicons dashicons-google" style="font-size:24px; width:24px; height:24px; color:#4285F4;"></span>
                <h3 style="margin:0; font-size:16px;">Google Drive</h3>
                <?php if ($airackup_gdrive_status['success']) : ?>
                    <span style="background:#ecfdf5; color:#059669; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600;">
                        🟢 Connected
                    </span>
                <?php else : ?>
                    <span style="background:#f1f5f9; color:#64748b; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600;">
                        ⚪ Not Connected
                    </span>
                <?php endif; ?>
            </div>
            
            <div>
                <?php if ($airackup_gdrive_status['success']) : ?>
                    <?php
                    $airackup_disconnect_url = wp_nonce_url(
                        admin_url('admin.php?page=airackup&action=airackup_disconnect_cloud&provider=gdrive'),
                        'airackup_disconnect_cloud'
                    );
                    ?>
                    <a href="<?php echo esc_url($airackup_disconnect_url); ?>" class="airackup-btn airackup-btn-danger" style="padding:6px 12px; font-size:12px;">
                        Disconnect
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($airackup_gdrive_status['success']) : ?>
            <p style="font-size:12px; color:#059669; margin:0; font-weight:500;">
                <?php echo esc_html($airackup_gdrive_status['message']); ?>
            </p>
        <?php else : ?>
            <p style="font-size:13px; color:var(--air-gray); margin:0 0 16px;">
                Connect your Google account with one click to store automated, secure backups directly in your personal Google Drive.
            </p>

            <div style="margin-bottom:16px;">
                <a href="<?php echo esc_url(AIRACKUP_AUTH_RELAY_URL . '/auth/google?site_url=' . rawurlencode(admin_url('admin.php'))); ?>" class="airackup-btn airackup-btn-primary" style="background:#4285F4; border-color:#4285F4; padding:10px 22px; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                    <span class="dashicons dashicons-google" style="font-size:18px; width:18px; height:18px; line-height:18px;"></span> Sign in with Google (1-Click Connect)
                </a>
            </div>

            <details style="margin-top:14px; font-size:12px; color:var(--air-gray);">
                <summary style="cursor:pointer; color:var(--air-primary); font-weight:600; user-select:none; margin-bottom:12px;">
                    Advanced: Custom Developer Credentials / Service Account
                </summary>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                    <!-- Option 1: Service Account JSON -->
                    <div style="background:var(--air-light); border:1px solid var(--air-border); border-radius:6px; padding:14px;">
                        <div style="font-weight:700; font-size:12px; margin-bottom:4px; color:var(--air-dark);">
                            Service Account Key (Private Servers)
                        </div>
                        <p style="font-size:11px; color:var(--air-gray); margin:0 0 8px;">
                            Paste your Google Cloud Service Account JSON key:
                        </p>
                        <textarea id="gdrive-service-json" rows="3" placeholder='Paste {"type": "service_account", ...}' style="width:100%; font-family:monospace; font-size:11px; padding:6px 10px; border-radius:4px; border:1px solid var(--air-border); margin-bottom:8px;"><?php echo esc_textarea(isset($airackup_gdrive['service_account_json']) ? $airackup_gdrive['service_account_json'] : ''); ?></textarea>
                        <button type="button" id="airackup-btn-connect-gdrive-sa" class="airackup-btn airackup-btn-secondary" style="width:100%; justify-content:center; font-size:12px;">
                            Connect Service Account
                        </button>
                    </div>

                    <!-- Option 2: Custom Client ID -->
                    <div style="background:var(--air-light); border:1px solid var(--air-border); border-radius:6px; padding:14px;">
                        <div style="font-weight:700; font-size:12px; margin-bottom:4px; color:var(--air-dark);">
                            Custom OAuth Credentials
                        </div>
                        <div style="margin-bottom:6px;">
                            <label style="display:block; font-size:11px; font-weight:600;">Client ID:</label>
                            <input type="text" id="gdrive-client-id" value="<?php echo esc_attr(isset($airackup_gdrive['client_id']) ? $airackup_gdrive['client_id'] : ''); ?>" style="width:100%; padding:4px 8px; font-size:11px; border-radius:4px; border:1px solid var(--air-border);" />
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="display:block; font-size:11px; font-weight:600;">Client Secret:</label>
                            <input type="password" id="gdrive-client-secret" value="<?php echo esc_attr(isset($airackup_gdrive['client_secret']) ? $airackup_gdrive['client_secret'] : ''); ?>" style="width:100%; padding:4px 8px; font-size:11px; border-radius:4px; border:1px solid var(--air-border);" />
                        </div>
                        <button type="button" id="airackup-btn-auth-gdrive-oauth" class="airackup-btn airackup-btn-secondary" style="width:100%; justify-content:center; font-size:12px;">
                            Authorize Custom Credentials
                        </button>
                    </div>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <!-- 2. Dropbox (1-Click Connect) -->
    <div style="border:1px solid var(--air-border); border-radius:8px; padding:20px; margin-bottom:20px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="dashicons dashicons-portfolio" style="font-size:24px; width:24px; height:24px; color:#0061FE;"></span>
                <h3 style="margin:0; font-size:16px;">Dropbox</h3>
                <?php if ($airackup_dropbox_status['success']) : ?>
                    <span style="background:#ecfdf5; color:#059669; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600;">
                        🟢 Connected
                    </span>
                <?php else : ?>
                    <span style="background:#f1f5f9; color:#64748b; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600;">
                        ⚪ Not Connected
                    </span>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($airackup_dropbox_status['success']) : ?>
                    <?php
                    $airackup_disconnect_dropbox_url = wp_nonce_url(
                        admin_url('admin.php?page=airackup&action=airackup_disconnect_cloud&provider=dropbox'),
                        'airackup_disconnect_cloud'
                    );
                    ?>
                    <a href="<?php echo esc_url($airackup_disconnect_dropbox_url); ?>" class="airackup-btn airackup-btn-danger" style="padding:6px 12px; font-size:12px;">
                        Disconnect
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($airackup_dropbox_status['success']) : ?>
            <p style="font-size:12px; color:#059669; margin:0; font-weight:500;">
                <?php echo esc_html($airackup_dropbox_status['message']); ?>
            </p>
        <?php else : ?>
            <p style="font-size:13px; color:var(--air-gray); margin:0 0 16px;">
                Connect to Dropbox with one click to automatically replicate your backups off-site.
            </p>

            <div style="margin-bottom:16px;">
                <a href="<?php echo esc_url(AIRACKUP_AUTH_RELAY_URL . '/auth/dropbox?site_url=' . rawurlencode(admin_url('admin.php'))); ?>" class="airackup-btn airackup-btn-primary" style="background:#0061FE; border-color:#0061FE; padding:10px 22px; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                    <span class="dashicons dashicons-portfolio" style="font-size:18px; width:18px; height:18px; line-height:18px;"></span> Connect with Dropbox (1-Click)
                </a>
            </div>

            <details style="margin-top:14px; font-size:12px; color:var(--air-gray);">
                <summary style="cursor:pointer; color:var(--air-primary); font-weight:600; user-select:none; margin-bottom:12px;">
                    Advanced: Custom Dropbox Access Token
                </summary>
                <div style="background:var(--air-light); border:1px solid var(--air-border); border-radius:6px; padding:14px;">
                    <p style="font-size:11px; color:var(--air-gray); margin:0 0 8px;">
                        Paste your generated Dropbox Access Token from <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">Dropbox App Console</a>:
                    </p>
                    <div style="display:flex; gap:10px;">
                        <input type="password" id="dropbox-access-token" value="<?php echo esc_attr(isset($airackup_dropbox['access_token']) ? $airackup_dropbox['access_token'] : ''); ?>" placeholder="sl.u.xxxxxxxxxxxxxxxxxxxxxxxxxxxxx" style="flex:1; padding:6px 10px; font-size:11px; border-radius:4px; border:1px solid var(--air-border);" />
                        <button type="button" id="airackup-btn-connect-dropbox-token" class="airackup-btn airackup-btn-secondary" style="white-space:nowrap; font-size:12px;">
                            Connect Token
                        </button>
                    </div>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <!-- 3. Amazon S3 / Cloudflare R2 / Wasabi -->
    <div style="border:1px solid var(--air-border); border-radius:8px; padding:20px; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0; font-size:15px; display:flex; align-items:center; gap:8px;">
                <span class="dashicons dashicons-cloud"></span> Amazon S3 / Cloudflare R2 / Wasabi / DigitalOcean Spaces
            </h3>
            <button type="button" class="airackup-btn airackup-btn-secondary airackup-test-conn" data-provider="s3">
                Test Connection
            </button>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Access Key ID:</label>
                <input type="text" id="s3-access-key" value="<?php echo esc_attr(isset($airackup_s3['access_key']) ? $airackup_s3['access_key'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Secret Access Key:</label>
                <input type="password" id="s3-secret-key" value="<?php echo esc_attr(isset($airackup_s3['secret_key']) ? $airackup_s3['secret_key'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Bucket Name:</label>
                <input type="text" id="s3-bucket" value="<?php echo esc_attr(isset($airackup_s3['bucket']) ? $airackup_s3['bucket'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Region (e.g. us-east-1, auto):</label>
                <input type="text" id="s3-region" value="<?php echo esc_attr(isset($airackup_s3['region']) ? $airackup_s3['region'] : 'us-east-1'); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div style="grid-column: span 2;">
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Custom Endpoint (Optional for Cloudflare R2 / Wasabi / MinIO):</label>
                <input type="text" id="s3-endpoint" placeholder="https://<account_id>.r2.cloudflarestorage.com" value="<?php echo esc_attr(isset($airackup_s3['endpoint']) ? $airackup_s3['endpoint'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
        </div>
    </div>

    <!-- 4. SFTP / Remote Server -->
    <div style="border:1px solid var(--air-border); border-radius:8px; padding:20px; margin-bottom:20px;">
        <h3 style="margin:0 0 15px; font-size:15px; display:flex; align-items:center; gap:8px;">
            <span class="dashicons dashicons-networking"></span> SFTP / Remote Server
        </h3>
        <div style="display:grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap:16px;">
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Host / Server IP:</label>
                <input type="text" id="sftp-host" value="<?php echo esc_attr(isset($airackup_sftp['host']) ? $airackup_sftp['host'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Port:</label>
                <input type="number" id="sftp-port" value="<?php echo esc_attr(isset($airackup_sftp['port']) ? $airackup_sftp['port'] : '22'); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Username:</label>
                <input type="text" id="sftp-user" value="<?php echo esc_attr(isset($airackup_sftp['username']) ? $airackup_sftp['username'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Password:</label>
                <input type="password" id="sftp-pass" value="<?php echo esc_attr(isset($airackup_sftp['password']) ? $airackup_sftp['password'] : ''); ?>" style="width:100%; padding:6px 10px; border-radius:6px; border:1px solid var(--air-border);" />
            </div>
        </div>
    </div>

    <div style="text-align:right;">
        <button type="button" id="airackup-btn-save-storage" class="airackup-btn airackup-btn-primary">
            Save Cloud Storage Settings
        </button>
    </div>
</div>
