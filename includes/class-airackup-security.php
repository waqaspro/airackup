<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Security
 * Enforces strict capability permissions, directory security, and cryptographic tokens.
 */
class Airackup_Security {

    public static function get_vault_path($sub = '') {
        $upload_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : sys_get_temp_dir();
        $vault = $upload_dir . '/airackup-vault';
        if ($sub) {
            $vault .= '/' . ltrim($sub, '/\\');
        }
        return $vault;
    }

    public static function init_vault_directory($custom_path = null) {
        $path = $custom_path ?: self::get_vault_path();
        if (!is_dir($path)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($path);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                @mkdir($path, 0755, true);
            }
        }

        // Lock down .htaccess for Apache 2.2 and Apache 2.4+
        $htaccess = $path . '/.htaccess';
        if (!file_exists($htaccess)) {
            $content = "# Apache 2.2\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n# Apache 2.4+\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
            file_put_contents($htaccess, $content);
        }

        // Lock down index.php
        $index = $path . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        // Lock down web.config for IIS
        $web_config = $path . '/web.config';
        if (!file_exists($web_config)) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";
            file_put_contents($web_config, $xml);
        }

        // Create isolated subdirectories
        $subdirs = array('backups', 'tmp', 'restore-staging');
        foreach ($subdirs as $subdir) {
            $subpath = $path . '/' . $subdir;
            if (!is_dir($subpath)) {
                if (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p($subpath);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                    @mkdir($subpath, 0755, true);
                }
                file_put_contents($subpath . '/index.php', "<?php\n// Silence is golden.\n");
            }
        }

        return true;
    }

    public static function generate_token($session_id) {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'airackup_secret_salt_key_default';
        return hash_hmac('sha256', $session_id, $salt);
    }

    public static function verify_token($session_id, $token) {
        $expected = self::generate_token($session_id);
        return hash_equals($expected, $token);
    }

    public static function check_admin_permissions() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(array('message' => 'Unauthorized. Must be Administrator.'), 403);
            }
            exit;
        }
    }
}
