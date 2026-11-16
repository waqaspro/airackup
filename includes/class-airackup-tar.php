<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Tar
 * Streaming POSIX ustar TAR writer & reader.
 * Operates in constant low memory (<16MB) using 64KB buffers.
 */
class Airackup_Tar {

    public static function open_for_append($archive_path) {
        $exists = file_exists($archive_path);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($archive_path, 'c+b');
        if (!$handle) return false;

        if ($exists) {
            $size = filesize($archive_path);
            if ($size >= 1024) {
                // Seek back before the trailing 1024 zero bytes of an existing TAR archive
                fseek($handle, -1024, SEEK_END);
            } else {
                fseek($handle, 0, SEEK_END);
            }
        }
        return $handle;
    }

    public static function append_file($handle, $source_file, $tar_path) {
        $dummy_offset = 0;
        return self::append_file_chunked($handle, $source_file, $tar_path, 0, PHP_INT_MAX, $dummy_offset);
    }

    public static function append_file_chunked($handle, $source_file, $tar_path, $start_time = 0, $time_budget = 4.0, &$file_offset = 0) {
        if (!is_file($source_file) || !is_readable($source_file)) {
            $file_offset = 0;
            return false;
        }

        $size = filesize($source_file);
        $mtime = filemtime($source_file);
        $tar_path = str_replace('\\', '/', $tar_path);

        // If starting from byte 0, write TAR 512-byte POSIX ustar header
        if ($file_offset === 0) {
            $header = self::build_header($tar_path, $size, $mtime);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($handle, $header);
        }

        // Handle empty 0-byte file immediately
        if ($size === 0) {
            $file_offset = 0;
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $src = fopen($source_file, 'rb');
        if (!$src) {
            $file_offset = 0;
            return false;
        }

        if ($file_offset > 0) {
            fseek($src, $file_offset);
        }

        $interrupted = false;

        // Stream file in 64KB chunks while checking execution time budget on every chunk
        while (!feof($src) && $file_offset < $size) {
            $bytes_to_read = min(65536, $size - $file_offset);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $buffer = fread($src, $bytes_to_read);
            if ($buffer === false || strlen($buffer) === 0) {
                break;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($handle, $buffer);
            $file_offset += strlen($buffer);

            if ($start_time > 0 && $file_offset < $size && (microtime(true) - $start_time) >= $time_budget) {
                $interrupted = true;
                break;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($src);

        if ($interrupted && $file_offset < $size) {
            // Execution budget reached mid-file; yield to AJAX runner to resume in the next request
            return false;
        }

        // File write complete: pad to POSIX 512-byte boundary
        $remainder = $size % 512;
        if ($remainder > 0) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($handle, str_repeat("\0", 512 - $remainder));
        }

        $file_offset = 0;
        return true;
    }

    public static function append_virtual_file($handle, $tar_path, $content) {
        $size = strlen($content);
        $tar_path = str_replace('\\', '/', $tar_path);
        $header = self::build_header($tar_path, $size, time());
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($handle, $header);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($handle, $content);

        $remainder = $size % 512;
        if ($remainder > 0) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($handle, str_repeat("\0", 512 - $remainder));
        }
        return true;
    }

    public static function finalize_archive($handle) {
        // POSIX standard: two 512-byte zero blocks at archive tail
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($handle, str_repeat("\0", 1024));
        fflush($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        return true;
    }

    public static function extract_batch($archive_path, $destination_dir, $offset_cursor = 0, $time_budget = 4.0) {
        $start_time = microtime(true);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($archive_path, 'rb');
        if (!$handle) {
            return array('success' => false, 'error' => 'Cannot open archive');
        }

        fseek($handle, $offset_cursor);
        $done = false;

        while (!feof($handle)) {
            if ((microtime(true) - $start_time) >= $time_budget) {
                break;
            }

            $current_pos = ftell($handle);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $header_raw = fread($handle, 512);
            if (strlen($header_raw) < 512 || $header_raw === str_repeat("\0", 512)) {
                $done = true;
                break;
            }

            $header = self::parse_header($header_raw);
            if (!$header || empty($header['filename'])) {
                $done = true;
                break;
            }

            // Security: Validate archive path against traversal, null bytes, drive letters, and symlinks
            $is_allowed_type = ($header['type'] === '0' || $header['type'] === "\0" || $header['type'] === '' || $header['type'] === '5');
            $safe_rel_path = self::sanitize_archive_path($header['filename']);

            if (!$is_allowed_type || $safe_rel_path === false) {
                // Safely skip entry payload + padding without writing to disk
                if ($header['type'] !== '5' && $header['size'] > 0) {
                    $pad = $header['size'] % 512;
                    $skip_bytes = $header['size'] + ($pad > 0 ? (512 - $pad) : 0);
                    fseek($handle, $skip_bytes, SEEK_CUR);
                }
                continue;
            }

            $base_dest = rtrim(str_replace('\\', '/', $destination_dir), '/');
            $target_file = $base_dest . '/' . $safe_rel_path;

            // Security: If extracting an executable file (PHP, PHTML, PHAR, INC, etc.) in staging,
            // append .airackup_quarantine so it can never be executed directly on Nginx or any web server.
            if ($header['type'] !== '5') {
                if (preg_match('/\.(php[0-9]*|phtml|phar|inc|cgi|pl|py|sh)$/i', $safe_rel_path)) {
                    $target_file .= '.airackup_quarantine';
                }
            }

            $target_dir = dirname($target_file);
            if (!is_dir($target_dir)) {
                if (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p($target_dir);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                    @mkdir($target_dir, 0755, true);
                }
            }

            // Confine extraction strictly inside destination_dir
            $real_base = realpath($base_dest);
            $real_target_dir = realpath($target_dir);
            if ($real_base === false || $real_target_dir === false || strpos(str_replace('\\', '/', $real_target_dir) . '/', str_replace('\\', '/', $real_base) . '/') !== 0) {
                if ($header['type'] !== '5' && $header['size'] > 0) {
                    $pad = $header['size'] % 512;
                    $skip_bytes = $header['size'] + ($pad > 0 ? (512 - $pad) : 0);
                    fseek($handle, $skip_bytes, SEEK_CUR);
                }
                continue;
            }

            if ($header['type'] === '5') {
                if (!is_dir($target_file)) {
                    if (function_exists('wp_mkdir_p')) {
                        wp_mkdir_p($target_file);
                    } else {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                        @mkdir($target_file, 0755, true);
                    }
                }
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                $out = fopen($target_file, 'wb');
                $bytes_to_read = $header['size'];
                while ($bytes_to_read > 0 && !feof($handle)) {
                    $chunk_size = min(65536, $bytes_to_read);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                    $buf = fread($handle, $chunk_size);
                    if ($buf === false || strlen($buf) === 0) break;
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                    fwrite($out, $buf);
                    $bytes_to_read -= strlen($buf);
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);

                // Skip trailing 512-byte alignment padding
                $pad = $header['size'] % 512;
                if ($pad > 0) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                    fread($handle, 512 - $pad);
                }
            }
        }

        $next_cursor = ftell($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        return array(
            'success' => true,
            'next_cursor' => $next_cursor,
            'done' => $done
        );
    }

    private static function build_header($filename, $size, $mtime) {
        list($prefix, $name) = self::split_ustar_path($filename);

        $header = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            sprintf("%07o\0", 0644),
            sprintf("%07o\0", 0),
            sprintf("%07o\0", 0),
            sprintf("%011o\0", $size),
            sprintf("%011o\0", $mtime),
            '        ',
            '0',
            '',
            'ustar',
            '00',
            'user',
            'group',
            '',
            '',
            $prefix,
            ''
        );

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        $checksum_field = sprintf("%06o\0 ", $checksum);
        $header = substr_replace($header, $checksum_field, 148, 8);

        return $header;
    }

    private static function split_ustar_path($path) {
        $path = str_replace('\\', '/', $path);
        if (strlen($path) <= 100) {
            return array('', $path);
        }

        $len = strlen($path);
        for ($i = max(0, $len - 100); $i < min(155, $len); $i++) {
            if ($path[$i] === '/') {
                $prefix = substr($path, 0, $i);
                $name = substr($path, $i + 1);
                if (strlen($name) <= 100 && strlen($prefix) <= 155) {
                    return array($prefix, $name);
                }
            }
        }

        return array('', substr($path, -100));
    }

    private static function parse_header($raw) {
        if (strlen($raw) < 512) return false;
        
        $filename = strtok(substr($raw, 0, 100), "\0");
        $size_str = strtok(substr($raw, 124, 12), "\0");
        $size = octdec(trim($size_str));
        $type = substr($raw, 156, 1);
        $prefix = strtok(substr($raw, 345, 155), "\0");

        if (!empty($prefix)) {
            $filename = trim($prefix, '/') . '/' . ltrim($filename, '/');
        }

        return array(
            'filename' => trim($filename),
            'size'     => $size,
            'type'     => $type
        );
    }

    public static function sanitize_archive_path($path) {
        if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
            return false;
        }
        $normalized = str_replace('\\', '/', $path);
        if (strpos($normalized, '/') === 0 || preg_match('/^[a-zA-Z]:/', $normalized)) {
            return false;
        }
        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            return false;
        }
        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return implode('/', $parts);
    }
}
