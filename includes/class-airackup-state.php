<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_State
 * Tracks session state, micro-batch pointers, cursors, and progress.
 */
class Airackup_State {

    public static function create_session($action_type = 'backup', $options = array()) {
        $rand = function_exists('wp_generate_password') ? wp_generate_password(32, false, false) : bin2hex(random_bytes(16));
        $session_id = Airackup_Security::sanitize_id($action_type . '_' . gmdate('Ymd_His') . '_' . $rand);
        $token = Airackup_Security::generate_token($session_id);

        $state_dir = Airackup_Security::get_vault_path('tmp/' . $session_id);
        Airackup_Security::protect_directory($state_dir);

        $initial_state = array(
            'session_id'     => $session_id,
            'token'          => $token,
            'action_type'    => $action_type,
            'phase'          => 'init',
            'status'         => 'running',
            'created_at'     => time(),
            'updated_at'     => time(),
            'percent'        => 0,
            'current_step'   => 'Initializing session...',
            'options'        => $options,
            'db_table_index' => 0,
            'db_row_offset'  => 0,
            'file_cursor'    => 0,
            'total_files'    => 0,
            'bytes_written'  => 0,
            'errors'         => array()
        );

        self::save_state_data($session_id, $initial_state);
        return $initial_state;
    }

    public static function get_state($session_id) {
        $session_id = Airackup_Security::sanitize_id($session_id);
        if (empty($session_id)) {
            return null;
        }

        if (function_exists('get_transient')) {
            $state = get_transient('airackup_state_' . $session_id);
            if (is_array($state)) {
                return $state;
            }
        }

        $file = self::get_state_file_path($session_id);
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Migration fallback for any legacy state file
        $legacy_file = Airackup_Security::get_vault_path('tmp/' . $session_id . '/state.php');
        if (file_exists($legacy_file)) {
            $raw = file_get_contents($legacy_file);
            $prefix = "<?php exit; ?>\n";
            if (strpos($raw, $prefix) === 0) {
                $raw = substr($raw, strlen($prefix));
            }
            if (function_exists('wp_delete_file')) {
                wp_delete_file($legacy_file);
            } else {
                @unlink($legacy_file);
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                self::save_state_data($session_id, $decoded);
                return $decoded;
            }
        }

        return null;
    }

    public static function update_state($session_id, $data) {
        $session_id = Airackup_Security::sanitize_id($session_id);
        $state = self::get_state($session_id);
        if (!$state) return null;

        foreach ($data as $k => $v) {
            $state[$k] = $v;
        }
        $state['updated_at'] = time();

        self::save_state_data($session_id, $state);
        return $state;
    }

    public static function delete_session($session_id) {
        $session_id = Airackup_Security::sanitize_id($session_id);
        if (empty($session_id)) {
            return false;
        }

        $deleted = false;
        if (function_exists('delete_transient')) {
            $deleted = delete_transient('airackup_state_' . $session_id);
        }

        $dir = Airackup_Security::get_vault_path('tmp/' . $session_id);
        if (is_dir($dir)) {
            self::recursive_rmdir($dir);
            return true;
        }
        return $deleted;
    }

    private static function get_state_file_path($session_id) {
        return Airackup_Security::get_vault_path('tmp/' . $session_id . '/state.json');
    }

    private static function save_state_data($session_id, $state) {
        if (function_exists('set_transient')) {
            $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            set_transient('airackup_state_' . $session_id, $state, $ttl);
            return;
        }

        $file = self::get_state_file_path($session_id);
        $payload = function_exists('wp_json_encode') ? wp_json_encode($state, JSON_PRETTY_PRINT) : json_encode($state, JSON_PRETTY_PRINT);
        file_put_contents($file, $payload, LOCK_EX);
    }

    private static function recursive_rmdir($dir) {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $p = "$dir/$file";
            if (is_dir($p)) {
                self::recursive_rmdir($p);
            } else {
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($p);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($p);
                }
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        return @rmdir($dir);
    }
}
