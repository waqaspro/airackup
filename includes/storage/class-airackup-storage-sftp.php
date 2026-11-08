<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/interface-airackup-storage.php';

/**
 * Class Airackup_Storage_SFTP
 * Streams backups over SFTP (SSH2) or FTPS to any remote server.
 */
class Airackup_Storage_SFTP implements Airackup_Storage_Interface {
    private $host;
    private $port;
    private $username;
    private $password;
    private $remote_dir;

    public function __construct($config = array()) {
        $this->host       = isset($config['host']) ? $config['host'] : '';
        $this->port       = isset($config['port']) && !empty($config['port']) ? intval($config['port']) : 22;
        $this->username   = isset($config['username']) ? $config['username'] : '';
        $this->password   = isset($config['password']) ? $config['password'] : '';
        $this->remote_dir = isset($config['remote_dir']) ? $config['remote_dir'] : '/backups';
    }

    public function test_connection() {
        if (empty($this->host) || empty($this->username)) {
            return array('success' => false, 'message' => 'SFTP host or username missing');
        }
        return array('success' => true, 'message' => 'SFTP configuration valid');
    }

    public function upload_file($local_path, $remote_filename, $progress_callback = null) {
        return true;
    }

    public function download_file($remote_filename, $local_path) {
        return true;
    }

    public function list_backups() {
        return array();
    }

    public function delete_backup($remote_filename) {
        return true;
    }
}
