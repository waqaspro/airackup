<?php
/**
 * Plugin Name: Airackup
 * Plugin URI: https://airackup.waqaspro.com/
 * Description: Zero-limitation, high-resilience WordPress backup, restore & migration plugin with incremental backups and cloud storage.
 * Version: 1.0.5
 * Author: waqaspro.com
 * Author URI: https://waqaspro.com/
 * Text Domain: airackup
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('AIRACKUP_VERSION', '1.0.5');
define('AIRACKUP_DIR', plugin_dir_path(__FILE__));
define('AIRACKUP_URL', plugin_dir_url(__FILE__));
define('AIRACKUP_AUTH_RELAY_URL', 'https://airackup-auth.itswaqas-pro.workers.dev');

require_once AIRACKUP_DIR . 'includes/class-airackup-security.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-core.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-tar.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-state.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-database.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-replacer.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-incremental.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-scheduler.php';
require_once AIRACKUP_DIR . 'includes/class-airackup-ajax.php';

register_activation_hook(__FILE__, array('Airackup_Security', 'init_vault_directory'));

add_action('plugins_loaded', function() {
    Airackup_Core::get_instance();
    Airackup_Scheduler::init();
    Airackup_Ajax::init();
});

// Handle 1-Click Cloud OAuth Returns
add_action('admin_init', function() {
    if (!current_user_can(Airackup_Security::get_required_capability())) return;

    if (isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'airackup') {
        // Disconnect actions
        if (isset($_GET['action']) && sanitize_key(wp_unslash($_GET['action'])) === 'airackup_disconnect_cloud') {
            check_admin_referer('airackup_disconnect_cloud');
            $provider = isset($_GET['provider']) ? sanitize_text_field(wp_unslash($_GET['provider'])) : '';
            $settings = get_option('airackup_settings', array());
            if (!empty($provider) && isset($settings[$provider])) {
                unset($settings[$provider]);
                update_option('airackup_settings', $settings);
            }
            wp_safe_redirect(admin_url('admin.php?page=airackup&tab=storage&disconnected=1'));
            exit;
        }

        // Relay Broker direct token return
        if (isset($_GET['auth']) && (isset($_GET['refresh_token']) || isset($_GET['access_token']))) {
            check_admin_referer('airackup_oauth_callback');
            $provider = sanitize_text_field(wp_unslash($_GET['auth']));
            $settings = get_option('airackup_settings', array());

            if ($provider === 'gdrive') {
                if (isset($_GET['refresh_token'])) {
                    $settings['gdrive']['refresh_token'] = sanitize_text_field(wp_unslash($_GET['refresh_token']));
                }
                if (isset($_GET['access_token'])) {
                    $settings['gdrive']['access_token'] = sanitize_text_field(wp_unslash($_GET['access_token']));
                }
                update_option('airackup_settings', $settings);
            } elseif ($provider === 'dropbox') {
                $token = isset($_GET['access_token']) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : sanitize_text_field(wp_unslash($_GET['refresh_token']));
                $settings['dropbox']['access_token'] = $token;
                update_option('airackup_settings', $settings);
            }

            wp_safe_redirect(admin_url('admin.php?page=airackup&tab=storage&connected=1'));
            exit;
        }

        // OAuth code exchange
        if (isset($_GET['auth']) && isset($_GET['code'])) {
            $oauth_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : (isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '');
            if (empty($oauth_nonce) || !wp_verify_nonce($oauth_nonce, 'airackup_oauth_callback')) {
                wp_die(esc_html__('Security verification failed for OAuth callback.', 'airackup'), 403);
            }

            $provider = sanitize_text_field(wp_unslash($_GET['auth']));
            $code = sanitize_text_field(wp_unslash($_GET['code']));
            $settings = get_option('airackup_settings', array());

            if ($provider === 'gdrive') {
                require_once AIRACKUP_DIR . 'includes/storage/class-airackup-storage-gdrive.php';
                $gdrive = new Airackup_Storage_GDrive();
                $redirect_uri = admin_url('admin.php?page=airackup&auth=gdrive');
                $res = $gdrive->exchange_code($code, $redirect_uri);
                if ($res['success']) {
                    $settings['gdrive']['refresh_token'] = $res['refresh_token'];
                    update_option('airackup_settings', $settings);
                }
            } elseif ($provider === 'dropbox') {
                $settings['dropbox']['access_token'] = $code;
                update_option('airackup_settings', $settings);
            }

            wp_safe_redirect(admin_url('admin.php?page=airackup&tab=storage&connected=1'));
            exit;
        }
    }
});

// Authenticated Secure File Download Handler (Bypasses Mixed-Content & .htaccess blocks)
add_action('admin_post_airackup_download_backup', function() {
    if (!current_user_can(Airackup_Security::get_required_capability())) {
        wp_die(esc_html__('Unauthorized access. Insufficient privileges.', 'airackup'), 403);
    }

    $filename = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
    if (empty($filename) || !check_admin_referer('airackup_download_' . $filename)) {
        wp_die(esc_html__('Security verification failed or download link expired.', 'airackup'), 403);
    }

    $filepath = Airackup_Security::get_vault_path('backups/' . basename($filename));
    if (!file_exists($filepath)) {
        // Fallback checks for extensions
        $base = preg_replace('/\.(airakup|airackup|tar)$/i', '', $filepath);
        if (file_exists($base . '.airakup')) {
            $filepath = $base . '.airakup';
        } elseif (file_exists($base . '.airackup')) {
            $filepath = $base . '.airackup';
        } elseif (file_exists($base . '.tar')) {
            $filepath = $base . '.tar';
        }
    }

    if (!file_exists($filepath) || !is_readable($filepath)) {
        wp_die(esc_html__('Backup archive file not found on server.', 'airackup'), 404);
    }

    // Clean all output buffers so binary TAR is not corrupted by whitespace
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Normalize output filename to .airakup to guarantee safe browser download
    $dl_filename = preg_replace('/\.(tar|airackup)$/i', '.airakup', basename($filepath));

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . esc_attr($dl_filename) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . (int)filesize($filepath));

    // Stream file to browser safely without buffer memory overflow.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct binary streaming required to prevent memory exhaustion on large backup archives.
    readfile($filepath);
    exit;
});

// Admin menu registration
function airackup_register_admin_menu() {
    add_menu_page(
        'Airackup',
        'Airackup',
        Airackup_Security::get_required_capability(),
        'airackup',
        'airackup_render_admin_page',
        'dashicons-cloud-saved',
        75
    );
}
add_action('admin_menu', 'airackup_register_admin_menu');
if (function_exists('is_multisite') && is_multisite()) {
    add_action('network_admin_menu', 'airackup_register_admin_menu');
}

// Enqueue styles and scripts
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'airackup') === false) return;

    wp_enqueue_style('airackup-admin-css', AIRACKUP_URL . 'assets/css/airackup-admin.css', array(), AIRACKUP_VERSION);

    wp_enqueue_script('airackup-chunk-uploader', AIRACKUP_URL . 'assets/js/airackup-chunk-uploader.js', array(), AIRACKUP_VERSION, true);
    wp_enqueue_script('airackup-backup-worker', AIRACKUP_URL . 'assets/js/airackup-backup-worker.js', array(), AIRACKUP_VERSION, true);
    wp_enqueue_script('airackup-restore-worker', AIRACKUP_URL . 'assets/js/airackup-restore-worker.js', array(), AIRACKUP_VERSION, true);
    wp_enqueue_script('airackup-admin-js', AIRACKUP_URL . 'assets/js/airackup-admin.js', array('jquery'), AIRACKUP_VERSION, true);

    wp_localize_script('airackup-admin-js', 'airackup_vars', array(
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('airackup_admin_nonce'),
        'oauth_nonce' => wp_create_nonce('airackup_oauth_callback'),
        'site_url'    => get_option('siteurl')
    ));
});

function airackup_render_admin_page() {
    require_once AIRACKUP_DIR . 'views/admin-page.php';
}
