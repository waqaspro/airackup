<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Security
 * Enforces strict capability permissions, directory security, and cryptographic tokens.
 */
class Airackup_Security {

    public static function get_upload_basedir() {
        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir();
            if (!empty($upload['basedir'])) {
                return rtrim(str_replace('\\', '/', $upload['basedir']), '/');
            }
        }
        return defined('WP_CONTENT_DIR') ? rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/uploads' : sys_get_temp_dir();
    }

    public static function get_vault_secret() {
        if (function_exists('get_option')) {
            $secret = get_option('airackup_vault_secret');
            if (empty($secret) || strlen($secret) < 32) {
                if (function_exists('wp_generate_password')) {
                    $secret = wp_generate_password(48, false, false);
                } else {
                    $secret = bin2hex(random_bytes(24));
                }
                if (function_exists('update_option')) {
                    update_option('airackup_vault_secret', $secret, true);
                }
            }
            return $secret;
        }
        // Fallback for standalone test suites without active WordPress database
        static $test_secret = null;
        if ($test_secret === null) {
            $test_secret = 'airackup_test_vault_secret_token_standalone_48_chars';
        }
        return $test_secret;
    }

    public static function get_vault_path($sub = '') {
        $secret = self::get_vault_secret();
        $vault = self::get_upload_basedir() . '/airackup/' . $secret;
        if ($sub) {
            $vault .= '/' . ltrim($sub, '/\\');
        }
        return $vault;
    }

    public static function get_upload_staging_path($upload_id) {
        $salt = self::get_vault_secret();
        $safe_hash = hash_hmac('sha256', (string) $upload_id, $salt);
        return self::get_vault_path('restore-staging/upl_' . $safe_hash);
    }

    public static function protect_directory($dir) {
        if (!is_string($dir) || empty($dir)) {
            return false;
        }

        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                @mkdir($dir, 0755, true);
            }
        }

        // Lock down .htaccess for Apache 2.2 and Apache 2.4+
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $content = "# Apache 2.2\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n# Apache 2.4+\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
            file_put_contents($htaccess, $content);
        }

        // Lock down index.php
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        // Lock down web.config for IIS
        $web_config = $dir . '/web.config';
        if (!file_exists($web_config)) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";
            file_put_contents($web_config, $xml);
        }

        return true;
    }

    public static function init_vault_directory($custom_path = null) {
        // When invoked by register_activation_hook, WordPress passes $network_wide (bool true/false).
        // Ensure $custom_path is strictly a valid string path, preventing network activation from creating directory "1".
        if (!is_string($custom_path) || empty($custom_path)) {
            $custom_path = null;
        }

        // Protect parent wrapper airackup directory
        $parent_dir = self::get_upload_basedir() . '/airackup';
        self::protect_directory($parent_dir);

        $path = $custom_path ?: self::get_vault_path();
        self::protect_directory($path);

        // Create and harden isolated subdirectories
        $subdirs = array('backups', 'tmp', 'restore-staging');
        foreach ($subdirs as $subdir) {
            self::protect_directory($path . '/' . $subdir);
        }

        // Seamless migration: move any legacy backups from un-salted directory to secret directory
        $legacy_backups = $parent_dir . '/backups';
        $new_backups = self::get_vault_path('backups');
        if (is_dir($legacy_backups) && $legacy_backups !== $new_backups) {
            $legacy_files = glob($legacy_backups . '/*') ?: array();
            $allowed_backup_exts = array('airakup', 'airackup', 'tar');
            foreach ($legacy_files as $lf) {
                if (is_file($lf)) {
                    $ext = strtolower(pathinfo($lf, PATHINFO_EXTENSION));
                    // Security: Restrict migration strictly to recognized backup archives (never arbitrary/executable files)
                    if (in_array($ext, $allowed_backup_exts, true)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                        @rename($lf, $new_backups . '/' . basename($lf));
                    }
                }
            }
        }

        return true;
    }

    public static function sanitize_id($id) {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id);
    }

    public static function generate_token($session_id) {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'airackup_secret_salt_key_default';
        return hash_hmac('sha256', $session_id, $salt);
    }

    public static function verify_token($session_id, $token) {
        if (empty($session_id) || empty($token)) {
            return false;
        }
        $expected = self::generate_token($session_id);
        return hash_equals($expected, $token);
    }

    public static function get_required_capability() {
        return (function_exists('is_multisite') && is_multisite()) ? 'manage_network' : 'manage_options';
    }

    public static function check_admin_permissions() {
        $cap = self::get_required_capability();
        if (!function_exists('current_user_can') || !current_user_can($cap)) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(array('message' => 'Unauthorized. Insufficient permissions.'), 403);
            }
            exit;
        }
    }
}
