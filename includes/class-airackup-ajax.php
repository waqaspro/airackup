<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Ajax
 * Master AJAX and REST controller for backup, chunked upload, and restore.
 */
class Airackup_Ajax {

    public static function init() {
        $actions = array(
            'airackup_start_backup',
            'airackup_step_backup',
            'airackup_chunk_upload',
            'airackup_start_restore',
            'airackup_step_restore',
            'airackup_delete_backup',
            'airackup_test_storage',
            'airackup_save_settings'
        );

        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, array(__CLASS__, $action));
        }
    }

    public static function airackup_start_backup() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'full';
        $remote = isset($_POST['remote']) ? sanitize_text_field(wp_unslash($_POST['remote'])) : 'local';

        $session = Airackup_State::create_session('backup', array(
            'type'   => $type,
            'remote' => $remote
        ));

        // Scan tables for database phase
        $tables = Airackup_Database::get_tables(false);

        Airackup_State::update_state($session['session_id'], array(
            'phase'        => 'database',
            'tables'       => $tables,
            'total_tables' => count($tables),
            'percent'      => 5,
            'current_step' => 'Preparing database dump...'
        ));

        wp_send_json_success($session);
    }

    public static function airackup_step_backup() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $session_id = isset($_POST['session_id']) ? Airackup_Security::sanitize_id(wp_unslash($_POST['session_id'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (!Airackup_Security::verify_token($session_id, $token)) {
            wp_send_json_error(array('message' => 'Invalid session token'), 403);
        }

        $state = Airackup_State::get_state($session_id);
        if (!$state) {
            wp_send_json_error(array('message' => 'Session not found'), 404);
        }

        $start_time = microtime(true);
        $time_budget = 3.5; // Seconds

        $archive_file = Airackup_Security::get_vault_path('backups/' . $session_id . '.airakup');
        $tar_handle = Airackup_Tar::open_for_append($archive_file);

        // Phase 1: Database
        if ($state['phase'] === 'database') {
            $tables = $state['tables'];
            $t_idx = $state['db_table_index'];
            $offset = $state['db_row_offset'];

            while ($t_idx < count($tables)) {
                if ((microtime(true) - $start_time) >= $time_budget) break;

                $current_table = $tables[$t_idx];
                $tmp_sql = Airackup_Security::get_vault_path('tmp/' . $session_id . '/table_' . $t_idx . '.sql');

                if ($offset === 0) {
                    $create_sql = Airackup_Database::get_table_create_sql($current_table);
                    file_put_contents($tmp_sql, $create_sql);
                }

                $rows = Airackup_Database::dump_table_batch($current_table, $offset, 1000, $tmp_sql);
                if ($rows > 0) {
                    $offset += $rows;
                } else {
                    // Table finished, append to TAR
                    if (file_exists($tmp_sql)) {
                        Airackup_Tar::append_file($tar_handle, $tmp_sql, 'database/' . $current_table . '.sql');
                        if (function_exists('wp_delete_file')) {
                            wp_delete_file($tmp_sql);
                        } else {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                            @unlink($tmp_sql);
                        }
                    }
                    $t_idx++;
                    $offset = 0;
                }
            }

            if ($t_idx >= count($tables)) {
                // Database phase finished
                $state['phase'] = ($state['options']['type'] === 'db_only') ? 'finalize' : 'files';
                $state['db_table_index'] = $t_idx;
                $state['db_row_offset'] = 0;
                $state['percent'] = 30;
                $state['current_step'] = 'Database complete. Starting file scan...';
            } else {
                $state['db_table_index'] = $t_idx;
                $state['db_row_offset'] = $offset;
                $db_pct = round(5 + (25 * ($t_idx / max(1, count($tables)))));
                $state['percent'] = $db_pct;
                $state['current_step'] = "Exporting table {$tables[$t_idx]} (row {$offset})...";
            }
        }
        // Phase 2: Files
        elseif ($state['phase'] === 'files') {
            if (empty($state['file_list'])) {
                // Initialize file list
                $base_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
                $snapshot = Airackup_Incremental::build_snapshot($base_dir, array('uploads/airackup', 'uploads/airackup-vault'));
                $state['file_list'] = array_keys($snapshot);
                $state['total_files'] = count($state['file_list']);
                $state['file_cursor'] = 0;
            }

            $files = $state['file_list'];
            $cursor = $state['file_cursor'];
            $base_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
            $active_offset = isset($state['active_file_offset']) ? absint($state['active_file_offset']) : 0;

            while ($cursor < count($files)) {
                if ((microtime(true) - $start_time) >= $time_budget) break;

                $rel_file = $files[$cursor];
                $abs_file = $base_dir . '/' . $rel_file;
                
                // Stream large files in time-bounded chunks so execution limits are never exceeded
                $done = Airackup_Tar::append_file_chunked($tar_handle, $abs_file, 'wp-content/' . $rel_file, $start_time, $time_budget, $active_offset);
                if (!$done) {
                    // Time budget exhausted while streaming this file; pause and resume in next request
                    break;
                }
                $cursor++;
            }

            $state['active_file_offset'] = $active_offset;
            $state['file_cursor'] = $cursor;
            $file_pct = round(30 + (60 * ($cursor / max(1, count($files)))));
            $state['percent'] = $file_pct;
            $state['current_step'] = "Archiving files ({$cursor} / " . count($files) . ")...";

            if ($cursor >= count($files)) {
                $state['phase'] = 'finalize';
                $state['current_step'] = 'Finalizing backup archive...';
            }
        }
        // Phase 3: Finalize
        elseif ($state['phase'] === 'finalize') {
            // Write manifest metadata
            global $wpdb;
            $meta = array(
                'version'      => AIRACKUP_VERSION,
                'created_at'   => time(),
                'siteurl'      => get_option('siteurl'),
                'home'         => get_option('home'),
                'table_prefix' => isset($wpdb) ? $wpdb->prefix : 'wp_',
                'abspath'      => ABSPATH,
                'wp_content'   => WP_CONTENT_DIR,
                'type'         => $state['options']['type']
            );

            Airackup_Tar::append_virtual_file($tar_handle, 'backup-meta.json', json_encode($meta, JSON_PRETTY_PRINT));
            Airackup_Tar::finalize_archive($tar_handle);

            // Clean up session tmp
            Airackup_State::delete_session($session_id);

            $state['phase'] = 'complete';
            $state['status'] = 'complete';
            $state['percent'] = 100;
            $state['current_step'] = 'Backup complete! File saved safely to vault.';
            $state['backup_file'] = basename($archive_file);
            $state['download_url'] = wp_nonce_url(
                admin_url('admin-post.php?action=airackup_download_backup&file=' . urlencode(basename($archive_file))),
                'airackup_download_' . basename($archive_file)
            );

            wp_send_json_success($state);
            return;
        }

        Airackup_Tar::finalize_archive($tar_handle);
        $updated = Airackup_State::update_state($session_id, $state);
        wp_send_json_success($updated);
    }

    public static function airackup_chunk_upload() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $upload_id = isset($_POST['upload_id']) ? Airackup_Security::sanitize_id(wp_unslash($_POST['upload_id'])) : '';
        $chunk_index = isset($_POST['chunk_index']) ? absint(wp_unslash($_POST['chunk_index'])) : 0;
        $total_chunks = isset($_POST['total_chunks']) ? absint(wp_unslash($_POST['total_chunks'])) : 1;
        $raw_filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : 'backup.airakup';
        $filename = basename($raw_filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (empty($upload_id) || strlen($upload_id) < 4 || strlen($upload_id) > 64) {
            wp_send_json_error(array('message' => 'Invalid upload ID.'), 400);
        }

        if (!in_array($ext, array('airakup', 'airackup', 'tar'), true)) {
            wp_send_json_error(array('message' => 'Invalid file type. Only backup archive files are permitted.'), 400);
        }

        if (empty($_FILES['chunk_data']) || !isset($_FILES['chunk_data']['tmp_name'])) {
            wp_send_json_error(array('message' => 'Missing chunk data.'), 400);
        }

        // Generate unguessable staging path using server-side HMAC salt
        $staging_dir = Airackup_Security::get_upload_staging_path($upload_id);
        Airackup_Security::protect_directory($staging_dir);

        // Store upload chunks under safe temporary extension (.airackup_tmp) to avoid public archive serving
        $dest_file = $staging_dir . '/archive.airackup_tmp';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $chunk_tmp = $_FILES['chunk_data']['tmp_name'];

        if (is_string($chunk_tmp) && file_exists($chunk_tmp) && is_uploaded_file($chunk_tmp)) {
            $chunk_content = file_get_contents($chunk_tmp);
            if ($chunk_content !== false) {
                file_put_contents($dest_file, $chunk_content, FILE_APPEND | LOCK_EX);
            }
        }

        wp_send_json_success(array(
            'chunk_index'  => $chunk_index,
            'total_chunks' => $total_chunks,
            'complete'     => ($chunk_index + 1 >= $total_chunks)
        ));
    }

    public static function airackup_start_restore() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $raw_backup_file = isset($_POST['backup_file']) ? sanitize_file_name(wp_unslash($_POST['backup_file'])) : '';
        $backup_file = basename($raw_backup_file);
        $upload_id = isset($_POST['upload_id']) ? Airackup_Security::sanitize_id(wp_unslash($_POST['upload_id'])) : '';
        $search_url = isset($_POST['search_url']) ? esc_url_raw(wp_unslash($_POST['search_url'])) : '';
        $replace_url = isset($_POST['replace_url']) ? esc_url_raw(wp_unslash($_POST['replace_url'])) : get_option('siteurl');

        $ext = strtolower(pathinfo($backup_file, PATHINFO_EXTENSION));
        if (!empty($backup_file) && !in_array($ext, array('airakup', 'airackup', 'tar'), true)) {
            wp_send_json_error(array('message' => 'Invalid backup archive filename.'), 400);
        }

        $upload_staging_dir = !empty($upload_id) ? Airackup_Security::get_upload_staging_path($upload_id) : '';

        $session = Airackup_State::create_session('restore', array(
            'backup_file'        => $backup_file,
            'upload_id'          => $upload_id,
            'upload_staging_dir' => $upload_staging_dir,
            'search_url'         => $search_url,
            'replace_url'        => $replace_url
        ));

        // Locate archive
        $archive_path = '';
        if (!empty($upload_id)) {
            if (!empty($upload_staging_dir) && file_exists($upload_staging_dir . '/archive.airackup_tmp')) {
                $archive_path = $upload_staging_dir . '/archive.airackup_tmp';
            } elseif (!empty($upload_staging_dir) && !empty($backup_file) && file_exists($upload_staging_dir . '/' . $backup_file)) {
                $archive_path = $upload_staging_dir . '/' . $backup_file;
            } else {
                // Legacy un-hashed path fallback
                $legacy_staging = Airackup_Security::get_vault_path('restore-staging/' . $upload_id . '/' . $backup_file);
                if (file_exists($legacy_staging)) {
                    $archive_path = $legacy_staging;
                }
            }
        } else {
            $archive_path = Airackup_Security::get_vault_path('backups/' . $backup_file);
        }

        if (!file_exists($archive_path)) {
            $base_no_ext = preg_replace('/\.(airakup|airackup|tar)$/i', '', $archive_path);
            if (file_exists($base_no_ext . '.airakup')) {
                $archive_path = $base_no_ext . '.airakup';
            } elseif (file_exists($base_no_ext . '.airackup')) {
                $archive_path = $base_no_ext . '.airackup';
            } elseif (file_exists($base_no_ext . '.tar')) {
                $archive_path = $base_no_ext . '.tar';
            }
        }

        if (!file_exists($archive_path)) {
            wp_send_json_error(array('message' => 'Archive file not found: ' . $archive_path), 404);
        }

        Airackup_State::update_state($session['session_id'], array(
            'phase'              => 'extracting',
            'archive_path'       => $archive_path,
            'upload_staging_dir' => $upload_staging_dir,
            'upload_id'          => $upload_id,
            'percent'            => 10,
            'cursor'             => 0,
            'current_step'       => 'Unpacking archive...'
        ));

        wp_send_json_success($session);
    }

    public static function airackup_step_restore() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $session_id = isset($_POST['session_id']) ? Airackup_Security::sanitize_id(wp_unslash($_POST['session_id'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (!Airackup_Security::verify_token($session_id, $token)) {
            wp_send_json_error(array('message' => 'Invalid session token'), 403);
        }

        $state = Airackup_State::get_state($session_id);
        if (!$state) {
            wp_send_json_error(array('message' => 'Session not found'), 404);
        }

        $staging_dir = Airackup_Security::get_vault_path('restore-staging/' . $session_id);
        Airackup_Security::protect_directory($staging_dir);

        // Phase 1: Extracting
        if ($state['phase'] === 'extracting') {
            $res = Airackup_Tar::extract_batch($state['archive_path'], $staging_dir, $state['cursor'], 3.5);
            $state['cursor'] = $res['next_cursor'];

            if ($res['done']) {
                $state['phase'] = 'database';
                $state['percent'] = 50;
                $state['current_step'] = 'Archive unpacked. Preparing database and resolving table prefixes...';

                // Read metadata manifest if present
                $meta_file = $staging_dir . '/backup-meta.json';
                global $wpdb;
                $target_prefix = isset($wpdb) ? $wpdb->prefix : 'wp_';
                $source_prefix = $target_prefix;
                $source_path = '';
                $source_content = '';

                if (file_exists($meta_file)) {
                    $meta = json_decode(file_get_contents($meta_file), true);
                    if (isset($meta['table_prefix']) && !empty($meta['table_prefix'])) {
                        $source_prefix = $meta['table_prefix'];
                    }
                    if (isset($meta['abspath']) && !empty($meta['abspath'])) {
                        $source_path = $meta['abspath'];
                    }
                    if (isset($meta['wp_content']) && !empty($meta['wp_content'])) {
                        $source_content = $meta['wp_content'];
                    }
                }

                $state['source_prefix']  = $source_prefix;
                $state['target_prefix']  = $target_prefix;
                $state['source_path']    = $source_path;
                $state['source_content'] = $source_content;

                // Look for SQL files
                $sql_files = glob($staging_dir . '/database/*.sql');
                $state['sql_files'] = $sql_files ?: array();
                $state['sql_index'] = 0;
                $state['sql_cursor'] = 0;
            } else {
                $state['percent'] = min(48, $state['percent'] + 5);
                $state['current_step'] = 'Extracting files and database...';
            }
        }
        // Phase 2: Database
        elseif ($state['phase'] === 'database') {
            $sql_files = $state['sql_files'];
            $idx = $state['sql_index'];
            $source_prefix = isset($state['source_prefix']) ? $state['source_prefix'] : '';
            $target_prefix = isset($state['target_prefix']) ? $state['target_prefix'] : '';

            if ($idx < count($sql_files)) {
                $sql_file = $sql_files[$idx];
                $res = Airackup_Database::import_sql_batch($sql_file, $state['sql_cursor'], 3.5, $source_prefix, $target_prefix);
                if ($res['done']) {
                    $state['sql_index']++;
                    $state['sql_cursor'] = 0;
                } else {
                    $state['sql_cursor'] = $res['next_cursor'];
                }
                $state['percent'] = round(50 + (30 * ($state['sql_index'] / max(1, count($sql_files)))));
                $state['current_step'] = "Restoring database table " . basename($sql_file) . "...";
            } else {
                $state['phase'] = 'migration_replace';
                $state['percent'] = 85;
                $state['current_step'] = 'Running deep serialized search-and-replace for domain & path migration...';
            }
        }
        // Phase 3: Migration Search and Replace
        elseif ($state['phase'] === 'migration_replace') {
            $meta_file = $staging_dir . '/backup-meta.json';
            $old_url = '';
            $source_prefix = isset($state['source_prefix']) ? $state['source_prefix'] : '';
            $target_prefix = isset($state['target_prefix']) ? $state['target_prefix'] : '';
            $source_path = isset($state['source_path']) ? $state['source_path'] : '';

            if (file_exists($meta_file)) {
                $meta = json_decode(file_get_contents($meta_file), true);
                $old_url = isset($meta['siteurl']) ? $meta['siteurl'] : '';
            }
            if (empty($old_url) && !empty($state['options']['search_url'])) {
                $old_url = $state['options']['search_url'];
            }

            $new_url = !empty($state['options']['replace_url']) ? $state['options']['replace_url'] : get_option('siteurl');

            // 1. Replace URLs and filesystem paths across all text & serialized options/meta
            $current_abspath = defined('ABSPATH') ? ABSPATH : '';
            if (!empty($old_url) || !empty($source_path)) {
                Airackup_Replacer::replace_in_database($old_url, $new_url, $source_path, $current_abspath);
            }

            // 2. Fix internal table prefix references in wp_options and wp_usermeta
            // (e.g., wp_user_roles and wp_capabilities) so admin never gets locked out!
            if (!empty($source_prefix) && !empty($target_prefix) && $source_prefix !== $target_prefix) {
                Airackup_Replacer::fix_database_prefix_internal_references($source_prefix, $target_prefix);
            }

            $state['phase'] = 'deploy_files';
            $state['percent'] = 90;
            $state['current_step'] = 'Deploying site files with permission checks...';
        }
        // Phase 4: Deploy Files & Permissions
        elseif ($state['phase'] === 'deploy_files') {
            $extracted_wp_content = $staging_dir . '/wp-content';
            $target_wp_content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

            if (is_dir($extracted_wp_content)) {
                self::deploy_directory_tree($extracted_wp_content, $target_wp_content);
            }

            $state['phase'] = 'finalize';
            $state['percent'] = 96;
            $state['current_step'] = 'Flushing caches, transient tables, and permalinks...';
        }
        // Phase 5: Finalize
        elseif ($state['phase'] === 'finalize') {
            global $wpdb;

            // 1. Flush transients from options to avoid stale cache
            if (isset($wpdb)) {
                $options_table = preg_replace('/[^a-zA-Z0-9_$]/', '', $wpdb->prefix . 'options');
                // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM `{$options_table}` WHERE `option_name` LIKE %s OR `option_name` LIKE %s",
                        '_transient_%',
                        '_site_transient_%'
                    )
                );
                // phpcs:enable
            }

            // 2. Clear Elementor CSS cache files if present
            $elementor_css = Airackup_Security::get_upload_basedir() . '/elementor/css';
            if (is_dir($elementor_css)) {
                self::delete_directory_contents($elementor_css);
            }

            // 3. Flush rewrite rules & regenerate standard .htaccess
            if (function_exists('flush_rewrite_rules')) {
                flush_rewrite_rules(true);
            }
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            // 4. Clean staging files completely so vault never grows in size
            self::delete_directory_contents($staging_dir);
            if (!empty($state['upload_staging_dir']) && is_dir($state['upload_staging_dir'])) {
                self::delete_directory_contents($state['upload_staging_dir']);
            }
            $target_upl_id = !empty($state['upload_id']) ? $state['upload_id'] : (!empty($state['options']['upload_id']) ? $state['options']['upload_id'] : '');
            if (!empty($target_upl_id)) {
                $hashed_staging = Airackup_Security::get_upload_staging_path($target_upl_id);
                if (is_dir($hashed_staging)) {
                    self::delete_directory_contents($hashed_staging);
                }
                $legacy_staging = Airackup_Security::get_vault_path('restore-staging/' . $target_upl_id);
                if (is_dir($legacy_staging)) {
                    self::delete_directory_contents($legacy_staging);
                }
            }
            Airackup_State::delete_session($session_id);

            $state['phase'] = 'complete';
            $state['status'] = 'complete';
            $state['percent'] = 100;
            $state['current_step'] = 'Restoration and migration complete! Table prefixes, permissions, and caches updated cleanly.';

            wp_send_json_success($state);
            return;
        }

        $updated = Airackup_State::update_state($session_id, $state);
        wp_send_json_success($updated);
    }

    public static function airackup_delete_backup() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '';
        require_once __DIR__ . '/storage/class-airackup-storage-local.php';
        $local = new Airackup_Storage_Local();
        $ok = $local->delete_backup($filename);

        if ($ok) {
            wp_send_json_success(array('message' => 'Backup deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete backup file'), 400);
        }
    }

    public static function airackup_test_storage() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : 's3';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_config = isset($_POST['config']) && is_array($_POST['config']) ? map_deep(wp_unslash($_POST['config']), 'sanitize_text_field') : array();
        $config = is_array($raw_config) ? $raw_config : array();

        if ($provider === 's3') {
            require_once __DIR__ . '/storage/class-airackup-storage-s3.php';
            $s3 = new Airackup_Storage_S3($config);
            $res = $s3->test_connection();
            wp_send_json_success($res);
        } else {
            wp_send_json_success(array('success' => true, 'message' => 'Storage configuration saved.'));
        }
    }

    public static function airackup_save_settings() {
        check_ajax_referer('airackup_admin_nonce', 'nonce');
        Airackup_Security::check_admin_permissions();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_settings = isset($_POST['settings']) && is_array($_POST['settings']) ? map_deep(wp_unslash($_POST['settings']), 'sanitize_text_field') : array();
        $settings = array();
        if (is_array($raw_settings)) {
            foreach ($raw_settings as $k => $v) {
                $clean_k = sanitize_key($k);
                if (is_array($v)) {
                    $settings[$clean_k] = array_map('sanitize_text_field', $v);
                } else {
                    $settings[$clean_k] = sanitize_text_field($v);
                }
            }
        }

        update_option('airackup_settings', $settings);
        Airackup_Scheduler::sync_schedule();
        wp_send_json_success(array('message' => 'Settings saved successfully'));
    }

    private static function deploy_directory_tree($source_dir, $target_dir) {
        if (!is_dir($source_dir)) return;

        $real_target_root = realpath($target_dir);
        if ($real_target_root === false) return;
        $real_target_root = rtrim(str_replace('\\', '/', $real_target_root), '/');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $src_len = strlen(rtrim($source_dir, '/\\')) + 1;

        foreach ($iterator as $item) {
            $src_path = $item->getPathname();
            // Block symlinks in source archive staging
            if (is_link($src_path)) {
                continue;
            }

            $rel = substr($src_path, $src_len);
            $rel_norm = str_replace('\\', '/', $rel);

            // Strip .airackup_quarantine suffix when deploying back into live target
            if (preg_match('/^(.*?)\.airackup_quarantine$/i', $rel_norm, $m)) {
                $rel_norm = $m[1];
            }

            // Never overwrite active airackup plugin or vault files during live restore
            if (strpos($rel_norm, 'plugins/airackup') === 0 || strpos($rel_norm, 'uploads/airackup') === 0) {
                continue;
            }

            $dest = $target_dir . '/' . $rel_norm;

            // Remove existing symlink at destination if present to prevent traversal overwrite
            if (is_link($dest)) {
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($dest);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($dest);
                }
            }

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    if (function_exists('wp_mkdir_p')) {
                        wp_mkdir_p($dest);
                    } else {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                        @mkdir($dest, 0755, true);
                    }
                }
            } else {
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    if (function_exists('wp_mkdir_p')) {
                        wp_mkdir_p($parent);
                    } else {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                        @mkdir($parent, 0755, true);
                    }
                }

                // Canonical path security check: verify destination parent is strictly within target root
                $real_parent = realpath($parent);
                if ($real_parent === false) {
                    continue;
                }
                $real_parent = rtrim(str_replace('\\', '/', $real_parent), '/');

                if (strpos($real_parent . '/', $real_target_root . '/') !== 0) {
                    // Target resolves outside allowed root! Skip for security
                    continue;
                }

                // Double check destination is not a symlink right before copy
                if (!is_link($dest)) {
                    copy($src_path, $dest);
                }
            }
        }
    }

    private static function delete_directory_contents($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $p = "$dir/$file";
            if (is_dir($p)) {
                self::delete_directory_contents($p);
            } else {
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($p);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($p);
                }
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        @rmdir($dir);
    }
}
