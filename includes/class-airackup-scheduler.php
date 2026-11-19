<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Scheduler
 * Schedules automated backups and enforces auto-retention pruning.
 */
class Airackup_Scheduler {

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));
        add_action('airackup_cron_backup_hook', array(__CLASS__, 'run_scheduled_backup'));

        self::sync_schedule();
    }

    public static function add_cron_intervals($schedules) {
        $schedules['weekly'] = array(
            'interval' => 7 * 86400,
            'display'  => 'Once Weekly'
        );
        $schedules['monthly'] = array(
            'interval' => 30 * 86400,
            'display'  => 'Once Monthly'
        );
        return $schedules;
    }

    public static function sync_schedule() {
        $settings = function_exists('get_option') ? get_option('airackup_settings', array()) : array();
        $freq = isset($settings['schedule_frequency']) ? $settings['schedule_frequency'] : 'disabled';

        $timestamp = wp_next_scheduled('airackup_cron_backup_hook');

        if ($freq === 'disabled') {
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'airackup_cron_backup_hook');
            }
        } else {
            if (!$timestamp) {
                wp_schedule_event(time() + 60, $freq, 'airackup_cron_backup_hook');
            }
        }
    }

    public static function run_scheduled_backup() {
        $settings = function_exists('get_option') ? get_option('airackup_settings', array()) : array();
        $type = isset($settings['backup_type']) ? $settings['backup_type'] : 'full';

        // Load required components
        require_once __DIR__ . '/class-airackup-security.php';
        require_once __DIR__ . '/class-airackup-tar.php';
        require_once __DIR__ . '/class-airackup-state.php';
        require_once __DIR__ . '/class-airackup-database.php';
        require_once __DIR__ . '/class-airackup-incremental.php';

        // 1. Initialize backup session and vault storage
        $session = Airackup_State::create_session('scheduled_' . $type, array('type' => $type));
        $session_id = $session['session_id'];

        $archive_file = Airackup_Security::get_vault_path('backups/' . $session_id . '.airakup');
        $tar_handle = Airackup_Tar::open_for_append($archive_file);
        if (!$tar_handle) {
            return false;
        }

        // 2. Headless Database Dump
        $tables = Airackup_Database::get_tables(false);
        $tmp_dir = Airackup_Security::get_vault_path('tmp/' . $session_id);
        Airackup_Security::protect_directory($tmp_dir);

        foreach ($tables as $t_idx => $table) {
            $tmp_sql = $tmp_dir . '/table_' . $t_idx . '.sql';
            $create_sql = Airackup_Database::get_table_create_sql($table);
            file_put_contents($tmp_sql, $create_sql);

            $offset = 0;
            while (true) {
                $rows = Airackup_Database::dump_table_batch($table, $offset, 1000, $tmp_sql);
                if ($rows > 0) {
                    $offset += $rows;
                } else {
                    break;
                }
            }

            if (file_exists($tmp_sql)) {
                Airackup_Tar::append_file($tar_handle, $tmp_sql, 'database/' . $table . '.sql');
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($tmp_sql);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($tmp_sql);
                }
            }
        }

        // 3. Headless Files Backup (if full site backup)
        if ($type === 'full') {
            $base_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
            $snapshot = Airackup_Incremental::build_snapshot($base_dir, array('uploads/airackup', 'uploads/airackup-vault'));
            foreach ($snapshot as $rel_file => $hash) {
                $abs_file = $base_dir . '/' . $rel_file;
                Airackup_Tar::append_file($tar_handle, $abs_file, 'wp-content/' . $rel_file);
            }
        }

        // 4. Archive Manifest Metadata
        global $wpdb;
        $meta = array(
            'version'      => defined('AIRACKUP_VERSION') ? AIRACKUP_VERSION : '1.0.5',
            'created_at'   => time(),
            'siteurl'      => function_exists('get_option') ? get_option('siteurl') : 'http://example.com',
            'home'         => function_exists('get_option') ? get_option('home') : 'http://example.com',
            'table_prefix' => isset($wpdb) ? $wpdb->prefix : 'wp_',
            'type'         => $type,
            'trigger'      => 'scheduled_cron'
        );
        Airackup_Tar::append_virtual_file($tar_handle, 'backup-meta.json', json_encode($meta, JSON_PRETTY_PRINT));
        Airackup_Tar::finalize_archive($tar_handle);

        // 5. Remote Cloud Storage sync if enabled
        $storage_dest = isset($settings['storage_destination']) ? $settings['storage_destination'] : 'local';
        if ($storage_dest === 's3' && !empty($settings['s3'])) {
            require_once __DIR__ . '/storage/class-airackup-storage-s3.php';
            $s3 = new Airackup_Storage_S3($settings['s3']);
            $s3->upload_file($archive_file, basename($archive_file));
        } elseif ($storage_dest === 'dropbox' && !empty($settings['dropbox'])) {
            require_once __DIR__ . '/storage/class-airackup-storage-dropbox.php';
            $dropbox = new Airackup_Storage_Dropbox($settings['dropbox']);
            $dropbox->upload_file($archive_file, basename($archive_file));
        } elseif ($storage_dest === 'gdrive' && !empty($settings['gdrive'])) {
            require_once __DIR__ . '/storage/class-airackup-storage-gdrive.php';
            $gdrive = new Airackup_Storage_GDrive($settings['gdrive']);
            $gdrive->upload_file($archive_file, basename($archive_file));
        }

        // 6. Clean session tmp
        Airackup_State::delete_session($session_id);

        // 7. Prune older backups according to retention policy
        self::prune_old_backups();

        return true;
    }

    public static function prune_old_backups() {
        $settings = function_exists('get_option') ? get_option('airackup_settings', array()) : array();
        $retention = isset($settings['retention_count']) ? intval($settings['retention_count']) : 5;
        if ($retention <= 0) return;

        require_once __DIR__ . '/storage/class-airackup-storage-local.php';
        $local = new Airackup_Storage_Local();
        $backups = $local->list_backups();

        if (count($backups) > $retention) {
            // Sort oldest first
            usort($backups, function($a, $b) {
                return $a['date'] - $b['date'];
            });

            $to_delete = count($backups) - $retention;
            for ($i = 0; $i < $to_delete; $i++) {
                $local->delete_backup($backups[$i]['filename']);
            }
        }
    }
}
