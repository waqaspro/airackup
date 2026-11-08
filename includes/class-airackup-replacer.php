<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Replacer
 * Deep recursive serialized and JSON search-and-replace engine for seamless site migration.
 */
class Airackup_Replacer {

    public static function fix_prefix_keys_in_options($options, $old_prefix, $new_prefix) {
        if (empty($old_prefix) || empty($new_prefix) || $old_prefix === $new_prefix) return $options;

        $fixed = array();
        $old_len = strlen($old_prefix);

        foreach ($options as $key => $val) {
            if (strpos($key, $old_prefix) === 0) {
                $new_key = $new_prefix . substr($key, $old_len);
                $fixed[$new_key] = $val;
            } else {
                $fixed[$key] = $val;
            }
        }
        return $fixed;
    }

    public static function fix_prefix_keys_in_usermeta($rows, $old_prefix, $new_prefix) {
        if (empty($old_prefix) || empty($new_prefix) || $old_prefix === $new_prefix) return $rows;

        $old_len = strlen($old_prefix);
        foreach ($rows as &$row) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Modifying in-memory array key, not an unindexed database query.
            if (isset($row['meta_key']) && strpos($row['meta_key'], $old_prefix) === 0) {
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                $row['meta_key'] = $new_prefix . substr($row['meta_key'], $old_len);
            }
        }
        return $rows;
    }

    public static function fix_database_prefix_internal_references($old_prefix, $new_prefix) {
        global $wpdb;
        if (!isset($wpdb) || empty($old_prefix) || empty($new_prefix) || $old_prefix === $new_prefix) {
            return;
        }

        $old_len = strlen($old_prefix);

        // 1. Fix options table: e.g. wp_user_roles -> wp_new_user_roles
        $options_table = preg_replace('/[^a-zA-Z0-9_$]/', '', $new_prefix . 'options');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $matching_options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name FROM `{$options_table}` WHERE option_name LIKE %s",
                $wpdb->esc_like($old_prefix) . '%'
            ),
            ARRAY_A
        );

        if ($matching_options) {
            foreach ($matching_options as $opt) {
                $new_opt_name = $new_prefix . substr($opt['option_name'], $old_len);
                $wpdb->update($options_table, array('option_name' => $new_opt_name), array('option_id' => $opt['option_id']));
            }
        }
        // phpcs:enable

        // 2. Fix usermeta table: e.g. wp_capabilities, wp_user_level
        $usermeta_table = preg_replace('/[^a-zA-Z0-9_$]/', '', $new_prefix . 'usermeta');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $matching_usermeta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT umeta_id, meta_key FROM `{$usermeta_table}` WHERE meta_key LIKE %s",
                $wpdb->esc_like($old_prefix) . '%'
            ),
            ARRAY_A
        );

        if ($matching_usermeta) {
            foreach ($matching_usermeta as $meta) {
                $new_meta_key = $new_prefix . substr($meta['meta_key'], $old_len);
                $wpdb->update($usermeta_table, array('meta_key' => $new_meta_key), array('umeta_id' => $meta['umeta_id']));
            }
        }
        // phpcs:enable
    }

    public static function replace($data, $search, $replace) {
        if (is_string($data)) {
            // Check if serialized
            if (self::is_serialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    $replaced_unserialized = self::replace($unserialized, $search, $replace);
                    return serialize($replaced_unserialized);
                }
            }

            // Check if JSON
            if (self::is_json($data, $decoded)) {
                $replaced_decoded = self::replace($decoded, $search, $replace);
                return json_encode($replaced_decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            // Standard string replacement
            return str_replace($search, $replace, $data);
        }

        if (is_array($data)) {
            $new_array = array();
            foreach ($data as $k => $v) {
                $new_key = self::replace($k, $search, $replace);
                $new_array[$new_key] = self::replace($v, $search, $replace);
            }
            return $new_array;
        }

        if (is_object($data)) {
            $class_name = get_class($data);
            $new_object = new $class_name();
            foreach ($data as $prop => $val) {
                $new_object->$prop = self::replace($val, $search, $replace);
            }
            return $new_object;
        }

        return $data;
    }

    public static function replace_in_database($search_url, $replace_url, $search_path = '', $replace_path = '') {
        global $wpdb;
        if (!isset($wpdb)) return 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        $total_rows_affected = 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        foreach ($tables as $table) {
            $clean_table = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$clean_table}`", ARRAY_A);
            $text_cols = array();
            $pri_col = null;

            foreach ($columns as $col) {
                if ($col['Key'] === 'PRI' && !$pri_col) {
                    $pri_col = $col['Field'];
                }
                $type = strtolower($col['Type']);
                if (strpos($type, 'char') !== false || strpos($type, 'text') !== false) {
                    $text_cols[] = $col['Field'];
                }
            }

            if (empty($text_cols) || !$pri_col) continue;

            $clean_pri = preg_replace('/[^a-zA-Z0-9_$]/', '', $pri_col);
            $clean_cols = array_map(function($c) { return '`' . preg_replace('/[^a-zA-Z0-9_$]/', '', $c) . '`'; }, $text_cols);

            $offset = 0;
            $batch_size = 500;

            while (true) {
                $rows = $wpdb->get_results("SELECT `{$clean_pri}`, " . implode(', ', $clean_cols) . " FROM `{$clean_table}` LIMIT {$offset}, {$batch_size}", ARRAY_A);
                if (empty($rows)) break;

                foreach ($rows as $row) {
                    $pri_val = $row[$pri_col];
                    $updates = array();

                    foreach ($text_cols as $c) {
                        $original = $row[$c];
                        if (empty($original) || (!is_string($original))) continue;

                        $modified = self::replace($original, $search_url, $replace_url);
                        if (!empty($search_path) && !empty($replace_path)) {
                            $modified = self::replace($modified, $search_path, $replace_path);
                        }

                        if ($modified !== $original) {
                            $updates[$c] = $modified;
                        }
                    }

                    if (!empty($updates)) {
                        $wpdb->update($table, $updates, array($pri_col => $pri_val));
                        $total_rows_affected++;
                    }
                }

                $offset += $batch_size;
            }
        }
        // phpcs:enable

        return $total_rows_affected;
    }

    public static function is_serialized($data) {
        if (!is_string($data)) return false;
        $data = trim($data);
        if ('N;' === $data) return true;
        if (strlen($data) < 4) return false;
        if (':' !== $data[1]) return false;
        $last = substr($data, -1);
        if (';' !== $last && '}' !== $last) return false;
        $token = $data[0];
        return in_array($token, array('s', 'a', 'O', 'b', 'i', 'd'), true);
    }

    public static function is_json($string, &$result = null) {
        if (!is_string($string) || empty($string)) return false;
        $trimmed = trim($string);
        if ($trimmed[0] !== '{' && $trimmed[0] !== '[') return false;
        $result = json_decode($string, true);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}
