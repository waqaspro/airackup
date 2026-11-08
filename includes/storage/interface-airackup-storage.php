<?php
if (!defined('ABSPATH')) exit;

/**
 * Interface Airackup_Storage_Interface
 * Unified contract for local and cloud storage providers.
 */
interface Airackup_Storage_Interface {
    public function test_connection();
    public function upload_file($local_path, $remote_filename, $progress_callback = null);
    public function download_file($remote_filename, $local_path);
    public function list_backups();
    public function delete_backup($remote_filename);
}
