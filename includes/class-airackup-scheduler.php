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
        $settings = get_option('airackup_settings', array());
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
        $settings = get_option('airackup_settings', array());
        $type = isset($settings['backup_type']) ? $settings['backup_type'] : 'full';

        // Spawn headless asynchronous backup session
        $session = Airackup_State::create_session('scheduled_' . $type, array('type' => $type));
        // Prune older backups
        self::prune_old_backups();
    }

    public static function prune_old_backups() {
        $settings = get_option('airackup_settings', array());
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
