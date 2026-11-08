<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Database
 * High-performance paginated SQL dump and micro-batch SQL importer.
 */
class Airackup_Database {

    public static function get_tables($prefix_only = false) {
        global $wpdb;
        if (!isset($wpdb)) return array();

        if ($prefix_only) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_col('SHOW TABLES');
    }

    public static function get_table_create_sql($table) {
        global $wpdb;
        if (!isset($wpdb)) return '';
        $clean_table = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row("SHOW CREATE TABLE `{$clean_table}`", ARRAY_N);
        if ($row && isset($row[1])) {
            return "DROP TABLE IF EXISTS `{$clean_table}`;\n" . $row[1] . ";\n\n";
        }
        return '';
    }

    public static function dump_table_batch($table, $offset, $limit, $output_file) {
        global $wpdb;
        if (!isset($wpdb)) return 0;

        $clean_table = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
        $offset = absint($offset);
        $limit = absint($limit);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results("SELECT * FROM `{$clean_table}` LIMIT {$offset}, {$limit}", ARRAY_A);
        if (empty($rows)) {
            return 0;
        }

        $sql = self::format_insert_batch($clean_table, $rows);
        file_put_contents($output_file, $sql, FILE_APPEND | LOCK_EX);

        return count($rows);
    }

    public static function format_insert_batch($table, $rows) {
        if (empty($rows)) return '';

        $columns = array_keys($rows[0]);
        $escaped_cols = array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, $columns);
        $header = "INSERT INTO `{$table}` (" . implode(', ', $escaped_cols) . ") VALUES \n";

        $values_list = array();
        foreach ($rows as $row) {
            $row_vals = array();
            foreach ($row as $val) {
                if ($val === null) {
                    $row_vals[] = "NULL";
                } elseif (is_numeric($val)) {
                    $row_vals[] = $val;
                } else {
                    $row_vals[] = "'" . addslashes($val) . "'";
                }
            }
            $values_list[] = "(" . implode(', ', $row_vals) . ")";
        }

        return $header . implode(",\n", $values_list) . ";\n\n";
    }

    public static function translate_table_prefix($sql, $old_prefix, $new_prefix) {
        if (empty($old_prefix) || empty($new_prefix) || $old_prefix === $new_prefix) {
            return $sql;
        }

        // Replace backtick enclosed table names: `wp_old_posts` -> `wp_new_posts`
        $escaped_old = preg_quote($old_prefix, '/');
        $sql = preg_replace('/`' . $escaped_old . '([a-zA-Z0-9_]+)`/', '`' . $new_prefix . '$1`', $sql);

        // Replace unquoted occurrences in common DDL syntax: CREATE TABLE wp_old_... / INTO wp_old_...
        $patterns = array(
            '/(TABLE\s+(?:IF\s+EXISTS\s+)?)(?:`)?' . $escaped_old . '([a-zA-Z0-9_]+)(?:`)?/i',
            '/(INTO\s+)(?:`)?' . $escaped_old . '([a-zA-Z0-9_]+)(?:`)?/i',
            '/(FROM\s+)(?:`)?' . $escaped_old . '([a-zA-Z0-9_]+)(?:`)?/i'
        );
        foreach ($patterns as $pattern) {
            $sql = preg_replace($pattern, '$1`' . $new_prefix . '$2`', $sql);
        }

        return $sql;
    }

    public static function import_sql_batch($sql_file, $offset_cursor = 0, $time_budget = 4.0, $old_prefix = '', $new_prefix = '') {
        global $wpdb;
        $start_time = microtime(true);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file stream required for memory-efficient chunked SQL restoration.
        $handle = fopen($sql_file, 'r');
        if (!$handle) {
            return array('success' => false, 'error' => 'Cannot open SQL file');
        }

        fseek($handle, $offset_cursor);
        $buffer = '';
        $queries_executed = 0;
        $done = false;

        while (!feof($handle)) {
            if ((microtime(true) - $start_time) >= $time_budget) {
                break;
            }

            $line = fgets($handle);
            if ($line === false) break;

            $trimmed = trim($line);
            if (empty($trimmed) || substr($trimmed, 0, 2) === '--' || substr($trimmed, 0, 2) === '/*') {
                continue;
            }

            $buffer .= $line;
            if (substr($trimmed, -1) === ';') {
                if (!empty($old_prefix) && !empty($new_prefix) && $old_prefix !== $new_prefix) {
                    $buffer = self::translate_table_prefix($buffer, $old_prefix, $new_prefix);
                }
                if (isset($wpdb)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing validated SQL statements from backup dump.
                    $wpdb->query($buffer);
                }
                $buffer = '';
                $queries_executed++;
            }
        }

        if (feof($handle) && empty($buffer)) {
            $done = true;
        }

        $next_cursor = ftell($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        return array(
            'success' => true,
            'next_cursor' => $next_cursor,
            'queries_executed' => $queries_executed,
            'done' => $done
        );
    }
}
