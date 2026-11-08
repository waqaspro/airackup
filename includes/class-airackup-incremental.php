<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Incremental
 * Builds baseline file snapshot catalog and computes delta changes for fast incremental backups.
 */
class Airackup_Incremental {

    public static function get_default_exclusions() {
        return array(
            // Own plugin storage & staging
            'uploads/airackup-vault',
            'airackup-vault',
            // Third-party backup plugin storage
            'uploads/ai1wm-backups',
            'ai1wm-backups',
            'uploads/updraftplus',
            'updraftplus',
            'uploads/backupbuddy_backups',
            'backupbuddy_backups',
            'uploads/pb_backupbuddy',
            'uploads/wp-clone',
            'wp-clone',
            'uploads/wp-snapshots',
            'wp-snapshots',
            'uploads/snapshots',
            'snapshots',
            'uploads/backups',
            'backups',
            'uploads/wpress',
            // WordPress core update staging
            'upgrade-temp-backup',
            'upgrade',
            // Cache folders
            'cache',
            'w3tc-cache',
            'wp-cache',
            'et-cache',
            // VCS & dependencies
            '.git',
            '.svn',
            'node_modules'
        );
    }

    public static function is_excluded_file($rel) {
        $basename = basename($rel);
        $lower = strtolower($basename);

        // Exclude all archive formats (prevents recursively backing up backups)
        $archive_extensions = array('.airakup', '.airackup', '.tar', '.gz', '.tgz', '.zip', '.wpress', '.rar', '.7z', '.sql');
        foreach ($archive_extensions as $ext) {
            if (substr($lower, -strlen($ext)) === $ext) {
                return true;
            }
        }

        // Exclude server logs & debug files
        if (substr($lower, -4) === '.log' || $lower === 'debug.log' || substr($lower, -4) === '.tmp') {
            return true;
        }

        return false;
    }

    public static function build_snapshot($base_dir, $custom_exclusions = array()) {
        $snapshot = array();
        if (!is_dir($base_dir)) return $snapshot;

        $exclusions = array_merge(self::get_default_exclusions(), $custom_exclusions);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $base_len = strlen(rtrim($base_dir, '/\\')) + 1;

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $rel = str_replace('\\', '/', substr($path, $base_len));

            // Check folder exclusions
            $excluded = false;
            foreach ($exclusions as $ex) {
                $ex_norm = trim($ex, '/');
                if (strpos($rel, $ex_norm) === 0 || strpos($rel, '/' . $ex_norm) !== false) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) continue;

            // Check file-level extension exclusions (archives, logs, sql dumps)
            if ($item->isFile()) {
                if (self::is_excluded_file($rel)) {
                    continue;
                }

                $snapshot[$rel] = array(
                    'size'  => $item->getSize(),
                    'mtime' => $item->getMTime()
                );
            }
        }

        return $snapshot;
    }

    public static function detect_changes($current_snapshot, $baseline_snapshot) {
        $added = array();
        $modified = array();
        $deleted = array();

        foreach ($current_snapshot as $path => $meta) {
            if (!isset($baseline_snapshot[$path])) {
                $added[] = $path;
            } else {
                if ($baseline_snapshot[$path]['size'] !== $meta['size'] ||
                    $baseline_snapshot[$path]['mtime'] !== $meta['mtime']) {
                    $modified[] = $path;
                }
            }
        }

        foreach ($baseline_snapshot as $path => $meta) {
            if (!isset($current_snapshot[$path])) {
                $deleted[] = $path;
            }
        }

        return array(
            'added'    => $added,
            'modified' => $modified,
            'deleted'  => $deleted
        );
    }

    public static function save_catalog($catalog_file, $snapshot) {
        return file_put_contents($catalog_file, json_encode($snapshot), LOCK_EX) !== false;
    }

    public static function load_catalog($catalog_file) {
        if (!file_exists($catalog_file)) return array();
        $data = file_get_contents($catalog_file);
        return json_decode($data, true) ?: array();
    }
}
