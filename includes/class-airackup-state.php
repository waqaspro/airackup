<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_State
 * Tracks session state, micro-batch pointers, cursors, and progress.
 */
class Airackup_State {

    public static function create_session($action_type = 'backup', $options = array()) {
        $rand = function_exists('wp_generate_password') ? wp_generate_password(8, false, false) : substr(bin2hex(random_bytes(6)), 0, 8);
        $session_id = $action_type . '_' . gmdate('Ymd_His') . '_' . $rand;
        $token = Airackup_Security::generate_token($session_id);

        $state_dir = Airackup_Security::get_vault_path('tmp/' . $session_id);
        if (!is_dir($state_dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($state_dir);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                @mkdir($state_dir, 0755, true);
            }
        }

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

        self::save_state_file($session_id, $initial_state);
        return $initial_state;
    }

    public static function get_state($session_id) {
        $file = self::get_state_file_path($session_id);
        if (!file_exists($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        return json_decode($raw, true);
    }

    public static function update_state($session_id, $data) {
        $state = self::get_state($session_id);
        if (!$state) return null;

        foreach ($data as $k => $v) {
            $state[$k] = $v;
        }
        $state['updated_at'] = time();

        self::save_state_file($session_id, $state);
        return $state;
    }

    public static function delete_session($session_id) {
        $dir = Airackup_Security::get_vault_path('tmp/' . $session_id);
        if (is_dir($dir)) {
            self::recursive_rmdir($dir);
            return true;
        }
        return false;
    }

    private static function get_state_file_path($session_id) {
        return Airackup_Security::get_vault_path('tmp/' . $session_id . '/state.json');
    }

    private static function save_state_file($session_id, $state) {
        $file = self::get_state_file_path($session_id);
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
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
