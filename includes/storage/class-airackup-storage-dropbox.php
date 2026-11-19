<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/interface-airackup-storage.php';

/**
 * Class Airackup_Storage_Dropbox
 * Dropbox API v2 storage driver.
 * Supports direct Dropbox Access Token (recommended) and OAuth2 App Key/Secret.
 */
class Airackup_Storage_Dropbox implements Airackup_Storage_Interface {
    private $access_token;
    private $app_key;
    private $app_secret;

    public function __construct($config = array()) {
        $this->access_token = isset($config['access_token']) ? trim($config['access_token']) : '';
        $this->app_key      = isset($config['app_key']) ? trim($config['app_key']) : '';
        $this->app_secret   = isset($config['app_secret']) ? trim($config['app_secret']) : '';
    }

    public function get_auth_url($redirect_uri) {
        if (empty($this->app_key)) {
            return '';
        }
        $params = array(
            'response_type'     => 'code',
            'client_id'         => $this->app_key,
            'redirect_uri'      => $redirect_uri,
            'token_access_type' => 'offline'
        );
        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($params);
    }

    public function test_connection() {
        if (empty($this->access_token)) {
            return array('success' => false, 'message' => 'Dropbox access token missing. Please paste your Dropbox access token.');
        }

        if (function_exists('wp_remote_post')) {
            $response = wp_remote_post('https://api.dropboxapi.com/2/users/get_current_account', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json'
                ),
                'body'    => 'null',
                'timeout' => 15
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && isset($body['email'])) {
                return array('success' => true, 'message' => 'Connected to Dropbox (' . $body['email'] . ')');
            } elseif (isset($body['error_summary'])) {
                return array('success' => false, 'message' => 'Dropbox error: ' . $body['error_summary']);
            }
        }

        return array('success' => true, 'message' => 'Dropbox token saved.');
    }

    public function upload_file($local_path, $remote_filename, $progress_callback = null) {
        if (empty($this->access_token) || !file_exists($local_path)) return false;

        $content = file_get_contents($local_path);
        $endpoint = 'https://content.dropboxapi.com/2/files/upload';

        if (function_exists('wp_remote_post')) {
            $res = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $this->access_token,
                    'Dropbox-API-Arg' => json_encode(array(
                        'path'       => '/' . ltrim($remote_filename, '/'),
                        'mode'       => 'overwrite',
                        'autorename' => false,
                        'mute'       => false
                    )),
                    'Content-Type'    => 'application/octet-stream'
                ),
                'body'    => $content,
                'timeout' => 120
            ));
            return (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200);
        }
        return true;
    }

    public function download_file($remote_filename, $local_path) {
        if (empty($this->access_token)) {
            return false;
        }

        $endpoint = 'https://content.dropboxapi.com/2/files/download';
        if (function_exists('wp_remote_post')) {
            $res = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $this->access_token,
                    'Dropbox-API-Arg' => json_encode(array(
                        'path' => '/' . ltrim($remote_filename, '/')
                    ))
                ),
                'timeout'  => 180,
                'stream'   => true,
                'filename' => $local_path
            ));
            return (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200);
        }
        return false;
    }

    public function list_backups() {
        if (empty($this->access_token)) {
            return array();
        }

        $endpoint = 'https://api.dropboxapi.com/2/files/list_folder';
        if (function_exists('wp_remote_post')) {
            $res = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json'
                ),
                'body'    => json_encode(array('path' => '')),
                'timeout' => 20
            ));

            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
                return array();
            }

            $data = json_decode(wp_remote_retrieve_body($res), true);
            $backups = array();
            if (!empty($data['entries']) && is_array($data['entries'])) {
                foreach ($data['entries'] as $entry) {
                    if (isset($entry['.tag']) && $entry['.tag'] === 'file') {
                        $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, array('airakup', 'airackup', 'tar'), true)) {
                            $backups[] = array(
                                'filename' => $entry['name'],
                                'size'     => isset($entry['size']) ? intval($entry['size']) : 0,
                                'date'     => isset($entry['client_modified']) ? strtotime($entry['client_modified']) : time(),
                                'storage'  => 'dropbox'
                            );
                        }
                    }
                }
            }
            return $backups;
        }

        return array();
    }

    public function delete_backup($remote_filename) {
        if (empty($this->access_token)) {
            return false;
        }

        $endpoint = 'https://api.dropboxapi.com/2/files/delete_v2';
        if (function_exists('wp_remote_post')) {
            $res = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json'
                ),
                'body'    => json_encode(array(
                    'path' => '/' . ltrim($remote_filename, '/')
                )),
                'timeout' => 20
            ));
            return (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200);
        }
        return false;
    }
}
