<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/interface-airackup-storage.php';

/**
 * Class Airackup_Storage_Local
 * Handles local server storage in the secured vault.
 */
class Airackup_Storage_Local implements Airackup_Storage_Interface {
    private $vault_path;

    public function __construct($path = null) {
        $this->vault_path = $path ?: Airackup_Security::get_vault_path('backups');
    }

    public function test_connection() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        return array('success' => is_dir($this->vault_path) && is_writable($this->vault_path), 'message' => 'Vault directory accessible');
    }

    public function upload_file($local_path, $remote_filename, $progress_callback = null) {
        $dest = $this->vault_path . '/' . $remote_filename;
        if ($local_path === $dest) return true;
        return copy($local_path, $dest);
    }

    public function download_file($remote_filename, $local_path) {
        $src = $this->vault_path . '/' . $remote_filename;
        return copy($src, $local_path);
    }

    public function list_backups() {
        $files1 = glob($this->vault_path . '/*.airakup') ?: array();
        $files2 = glob($this->vault_path . '/*.airackup') ?: array();
        $files3 = glob($this->vault_path . '/*.tar') ?: array();

        // Auto-migrate any orphan .tar files to .airakup so user never sees .tar
        foreach ($files3 as $tar_file) {
            $base = preg_replace('/\.tar$/i', '', $tar_file);
            if (!file_exists($base . '.airakup') && !file_exists($base . '.airackup')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                if (@rename($tar_file, $base . '.airakup')) {
                    $files1[] = $base . '.airakup';
                }
            } elseif (file_exists($tar_file) && (file_exists($base . '.airakup') || file_exists($base . '.airackup'))) {
                // Safely clean up redundant leftover .tar
                $this->remove_file($tar_file);
            }
        }

        $all_files = array_merge($files1, $files2);
        $seen = array();
        $backups = array();

        foreach ($all_files as $f) {
            if (!file_exists($f)) continue;
            $base = preg_replace('/\.(airakup|airackup)$/i', '', basename($f));
            if (isset($seen[$base])) continue;
            $seen[$base] = true;

            $backups[] = array(
                'filename' => basename($f),
                'size'     => filesize($f),
                'date'     => filemtime($f),
                'storage'  => 'local'
            );
        }

        // Sort newest first
        usort($backups, function($a, $b) {
            return $b['date'] - $a['date'];
        });

        return $backups;
    }

    public function delete_backup($remote_filename) {
        $file = $this->vault_path . '/' . basename($remote_filename);
        $ok = false;
        if (file_exists($file)) {
            $ok = $this->remove_file($file);
        }
        $base = preg_replace('/\.(airakup|airackup|tar)$/i', '', $file);
        if (file_exists($base . '.airakup')) {
            $this->remove_file($base . '.airakup');
        }
        if (file_exists($base . '.airackup')) {
            $this->remove_file($base . '.airackup');
        }
        if (file_exists($base . '.tar')) {
            $this->remove_file($base . '.tar');
        }
        return $ok || !file_exists($file);
    }

    private function remove_file($filepath) {
        if (function_exists('wp_delete_file')) {
            return wp_delete_file($filepath);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return @unlink($filepath);
    }
}
