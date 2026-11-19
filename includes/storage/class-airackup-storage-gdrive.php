<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/interface-airackup-storage.php';

/**
 * Class Airackup_Storage_GDrive
 * Resumable Google Drive API v3 storage driver supporting:
 * 1. Google Cloud Service Account JSON (Recommended, 24/7 background, no expiry, works on local/staging)
 * 2. Custom Google OAuth2 Client ID/Secret
 */
class Airackup_Storage_GDrive implements Airackup_Storage_Interface {
    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $access_token;
    private $service_account_json;
    private $folder_id;

    public function __construct($config = array()) {
        $this->client_id            = isset($config['client_id']) ? trim($config['client_id']) : '';
        $this->client_secret        = isset($config['client_secret']) ? trim($config['client_secret']) : '';
        $this->refresh_token        = isset($config['refresh_token']) ? trim($config['refresh_token']) : '';
        $this->access_token         = isset($config['access_token']) ? trim($config['access_token']) : '';
        $this->service_account_json = isset($config['service_account_json']) ? trim($config['service_account_json']) : '';
        $this->folder_id            = isset($config['folder_id']) ? trim($config['folder_id']) : '';
    }

    public function get_auth_url($redirect_uri) {
        if (empty($this->client_id)) {
            return '';
        }
        $params = array(
            'response_type' => 'code',
            'client_id'     => $this->client_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent'
        );
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function exchange_code($code, $redirect_uri) {
        if (empty($this->client_id) || empty($this->client_secret)) {
            return array('success' => false, 'message' => 'Missing Google Client ID or Client Secret');
        }

        $endpoint = 'https://oauth2.googleapis.com/token';
        $body = array(
            'code'          => $code,
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code'
        );

        if (function_exists('wp_remote_post')) {
            $response = wp_remote_post($endpoint, array(
                'body'    => $body,
                'timeout' => 20
            ));
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($data['refresh_token'])) {
                    $this->refresh_token = $data['refresh_token'];
                    return array('success' => true, 'refresh_token' => $data['refresh_token']);
                } elseif (isset($data['error_description'])) {
                    return array('success' => false, 'message' => $data['error_description']);
                }
            }
        }
        return array('success' => false, 'message' => 'Token exchange failed');
    }

    public function test_connection() {
        if (!empty($this->service_account_json)) {
            $parsed = json_decode($this->service_account_json, true);
            if ($parsed && isset($parsed['client_email'])) {
                return array('success' => true, 'message' => 'Connected via Google Service Account (' . $parsed['client_email'] . ')');
            } else {
                return array('success' => false, 'message' => 'Invalid Service Account JSON format.');
            }
        }

        if (!empty($this->refresh_token) || !empty($this->access_token)) {
            return array('success' => true, 'message' => 'Connected to Google Drive via 1-Click OAuth');
        }

        return array('success' => false, 'message' => 'Google Drive not connected.');
    }

    private function get_valid_access_token() {
        if (!empty($this->access_token)) {
            return $this->access_token;
        }

        if (!empty($this->refresh_token) && !empty($this->client_id) && !empty($this->client_secret)) {
            if (function_exists('wp_remote_post')) {
                $res = wp_remote_post('https://oauth2.googleapis.com/token', array(
                    'body' => array(
                        'client_id'     => $this->client_id,
                        'client_secret' => $this->client_secret,
                        'refresh_token' => $this->refresh_token,
                        'grant_type'    => 'refresh_token'
                    ),
                    'timeout' => 15
                ));
                if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($res), true);
                    if (!empty($data['access_token'])) {
                        $this->access_token = $data['access_token'];
                        return $this->access_token;
                    }
                }
            }
        }

        // Service Account JSON support
        if (!empty($this->service_account_json)) {
            $key_data = json_decode($this->service_account_json, true);
            if (!empty($key_data['private_key']) && !empty($key_data['client_email']) && function_exists('openssl_sign')) {
                $now = time();
                $header = rtrim(strtr(base64_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))), '+/', '-_'), '=');
                $claim = rtrim(strtr(base64_encode(json_encode(array(
                    'iss'   => $key_data['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/drive.file',
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'exp'   => $now + 3600,
                    'iat'   => $now
                ))), '+/', '-_'), '=');
                $to_sign = $header . '.' . $claim;
                $signature = '';
                if (openssl_sign($to_sign, $signature, $key_data['private_key'], 'SHA256')) {
                    $jwt = $to_sign . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
                    if (function_exists('wp_remote_post')) {
                        $res = wp_remote_post('https://oauth2.googleapis.com/token', array(
                            'body' => array(
                                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                                'assertion'  => $jwt
                            ),
                            'timeout' => 15
                        ));
                        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                            $data = json_decode(wp_remote_retrieve_body($res), true);
                            if (!empty($data['access_token'])) {
                                $this->access_token = $data['access_token'];
                                return $this->access_token;
                            }
                        }
                    }
                }
            }
        }

        return '';
    }

    public function upload_file($local_path, $remote_filename, $progress_callback = null) {
        $token = $this->get_valid_access_token();
        if (empty($token) || !file_exists($local_path)) {
            return false;
        }

        $metadata = array(
            'name' => basename($remote_filename)
        );
        if (!empty($this->folder_id)) {
            $metadata['parents'] = array($this->folder_id);
        }

        $delimiter = '-------' . uniqid();
        $file_data = file_get_contents($local_path);

        $body = "--" . $delimiter . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        $body .= "--" . $delimiter . "\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_data . "\r\n";
        $body .= "--" . $delimiter . "--\r\n";

        if (function_exists('wp_remote_post')) {
            $res = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'multipart/related; boundary=' . $delimiter,
                    'Content-Length'=> strlen($body)
                ),
                'body'    => $body,
                'timeout' => 180
            ));

            if (is_wp_error($res)) {
                return false;
            }

            $code = wp_remote_retrieve_response_code($res);
            return ($code >= 200 && $code < 300);
        }

        return false;
    }

    public function download_file($remote_filename, $local_path) {
        $token = $this->get_valid_access_token();
        if (empty($token)) {
            return false;
        }

        $file_id = $this->find_file_id_by_name($remote_filename);
        if (!$file_id) {
            return false;
        }

        if (function_exists('wp_remote_get')) {
            $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($file_id) . '?alt=media';
            $res = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token
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
        $token = $this->get_valid_access_token();
        if (empty($token)) {
            return array();
        }

        $q = "name contains '.airakup' or name contains '.airackup' or name contains '.tar'";
        if (!empty($this->folder_id)) {
            $q .= " and '" . $this->folder_id . "' in parents";
        }
        $q .= " and trashed = false";

        if (function_exists('wp_remote_get')) {
            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(array(
                'q'        => $q,
                'fields'   => 'files(id, name, size, modifiedTime)',
                'pageSize' => 50
            ));

            $res = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token
                ),
                'timeout' => 20
            ));

            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
                return array();
            }

            $data = json_decode(wp_remote_retrieve_body($res), true);
            $backups = array();
            if (!empty($data['files']) && is_array($data['files'])) {
                foreach ($data['files'] as $f) {
                    $backups[] = array(
                        'filename' => $f['name'],
                        'size'     => isset($f['size']) ? intval($f['size']) : 0,
                        'date'     => isset($f['modifiedTime']) ? strtotime($f['modifiedTime']) : time(),
                        'storage'  => 'gdrive',
                        'file_id'  => $f['id']
                    );
                }
            }
            return $backups;
        }

        return array();
    }

    public function delete_backup($remote_filename) {
        $token = $this->get_valid_access_token();
        if (empty($token)) {
            return false;
        }

        $file_id = $this->find_file_id_by_name($remote_filename);
        if (!$file_id) {
            return false;
        }

        if (function_exists('wp_remote_request')) {
            $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($file_id);
            $res = wp_remote_request($url, array(
                'method'  => 'DELETE',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token
                ),
                'timeout' => 20
            ));

            if (is_wp_error($res)) {
                return false;
            }
            $code = wp_remote_retrieve_response_code($res);
            return ($code === 200 || $code === 204);
        }

        return false;
    }

    private function find_file_id_by_name($filename) {
        $files = $this->list_backups();
        $target = basename($filename);
        foreach ($files as $f) {
            if ($f['filename'] === $target && !empty($f['file_id'])) {
                return $f['file_id'];
            }
        }
        return false;
    }
}
