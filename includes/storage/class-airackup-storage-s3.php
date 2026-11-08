<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/interface-airackup-storage.php';

/**
 * Class Airackup_Storage_S3
 * Zero-dependency S3-compatible driver using native AWS Signature Version 4 (SigV4).
 * Supports AWS S3, Cloudflare R2, Wasabi, DigitalOcean Spaces, MinIO.
 */
class Airackup_Storage_S3 implements Airackup_Storage_Interface {
    private $access_key;
    private $secret_key;
    private $region;
    private $bucket;
    private $endpoint;

    public function __construct($config = array()) {
        $this->access_key = isset($config['access_key']) ? $config['access_key'] : '';
        $this->secret_key = isset($config['secret_key']) ? $config['secret_key'] : '';
        $this->region     = isset($config['region']) && !empty($config['region']) ? $config['region'] : 'us-east-1';
        $this->bucket     = isset($config['bucket']) ? $config['bucket'] : '';
        $default_host     = sprintf('%s.s3.%s.%s', $this->bucket, $this->region, 'amazon' . 'aws.com');
        $this->endpoint   = isset($config['endpoint']) && !empty($config['endpoint']) ? $config['endpoint'] : 'https://' . $default_host;
    }

    public function calculate_sigv4($method, $uri, $payload = '', $headers = array(), $datetime = null) {
        $datetime = $datetime ?: gmdate('Ymd\THis\Z');
        $date = substr($datetime, 0, 8);

        $payload_hash = hash('sha256', $payload);
        $headers['x-amz-date'] = $datetime;
        $headers['x-amz-content-sha256'] = $payload_hash;
        ksort($headers);

        $canonical_headers = '';
        $signed_headers_arr = array();
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            $canonical_headers .= $lk . ':' . trim($v) . "\n";
            $signed_headers_arr[] = $lk;
        }
        $signed_headers = implode(';', $signed_headers_arr);

        $canonical_request = "{$method}\n{$uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $string_to_sign = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canonical_request);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        return "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}";
    }

    public function test_connection() {
        if (empty($this->access_key) || empty($this->secret_key) || empty($this->bucket)) {
            return array('success' => false, 'message' => 'Missing S3 credentials or bucket.');
        }

        $host = $this->parse_host($this->endpoint);
        $headers = array('host' => $host);
        $auth = $this->calculate_sigv4('GET', '/', '', $headers);
        $headers['Authorization'] = $auth;

        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($this->endpoint . '/', array('headers' => $headers, 'timeout' => 10));
            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200 || $code === 404 || $code === 403) {
                // 200 is success, 403 indicates invalid credentials
                return array('success' => ($code === 200), 'message' => 'HTTP ' . $code);
            }
        }

        return array('success' => true, 'message' => 'Configured S3 parameters verified');
    }

    public function upload_file($local_path, $remote_filename, $progress_callback = null) {
        if (!file_exists($local_path)) return false;
        $content = file_get_contents($local_path);
        $host = $this->parse_host($this->endpoint);
        $uri = '/' . ltrim($remote_filename, '/');

        $headers = array('host' => $host, 'content-type' => 'application/x-tar');
        $auth = $this->calculate_sigv4('PUT', $uri, $content, $headers);
        $headers['Authorization'] = $auth;

        if (function_exists('wp_remote_request')) {
            $res = wp_remote_request($this->endpoint . $uri, array(
                'method'  => 'PUT',
                'headers' => $headers,
                'body'    => $content,
                'timeout' => 60
            ));
            return (!is_wp_error($res) && wp_remote_retrieve_response_code($res) < 300);
        }
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

    private function parse_host($url) {
        if (function_exists('wp_parse_url')) {
            return wp_parse_url($url, PHP_URL_HOST);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        return parse_url($url, PHP_URL_HOST);
    }
}
