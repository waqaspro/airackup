/**
 * Airackup Admin Main JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Robust Tab Switching with History State Retention
        function switchTab(tabId, updateUrl) {
            if (!$('.airackup-tab-btn[data-tab="' + tabId + '"]').length) {
                tabId = 'backups';
            }
            $('.airackup-tab-btn').removeClass('active');
            $('.airackup-tab-btn[data-tab="' + tabId + '"]').addClass('active');

            $('.airackup-tab-content').hide();
            $('#tab-' + tabId).show();

            if (updateUrl !== false && window.history && window.history.replaceState) {
                var currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('tab', tabId);
                window.history.replaceState(null, '', currentUrl.toString());
            }
        }

        $('.airackup-tab-btn').on('click', function(e) {
            e.preventDefault();
            var tabId = $(this).data('tab');
            switchTab(tabId, true);
        });

        // Sync tab on initial page load if URL parameter is present
        try {
            var urlParams = new URLSearchParams(window.location.search);
            var initialTab = urlParams.get('tab');
            if (initialTab && $('.airackup-tab-btn[data-tab="' + initialTab + '"]').length) {
                switchTab(initialTab, false);
            }
        } catch (e) {}

        var modal = $('#airackup-progress-modal');
        var modalTitle = $('#airackup-modal-title');
        var fill = $('#airackup-progress-fill');
        var percentText = $('#airackup-percent-text');
        var statusText = $('#airackup-status-text');
        var logBox = $('#airackup-log');
        var closeBtn = $('#airackup-btn-close-modal');

        function appendLog(msg) {
            var time = new Date().toLocaleTimeString();
            logBox.append('\n[' + time + '] ' + msg);
            logBox.scrollTop(logBox[0].scrollHeight);
        }

        function showModal(title) {
            modalTitle.text(title);
            fill.css('width', '0%');
            percentText.text('0%');
            statusText.text('Initializing...');
            logBox.text('[Ready] Starting operation...');
            closeBtn.hide();
            modal.addClass('active');
        }

        closeBtn.on('click', function() {
            modal.removeClass('active');
            window.location.reload();
        });

        // 1. Create Backup Button
        $('#airackup-btn-create-backup').on('click', function(e) {
            e.preventDefault();
            var backupType = $('#airackup-backup-type-select').val();
            showModal('Creating Site Backup');
            appendLog('Starting Airackup backup worker (Type: ' + backupType + ')...');

            var worker = new AirackupBackupWorker({
                endpoint: airackup_vars.ajax_url,
                nonce: airackup_vars.nonce
            });

            worker.start({ type: backupType }, 
                function onTick(state) {
                    fill.css('width', state.percent + '%');
                    percentText.text(state.percent + '%');
                    statusText.text(state.current_step);
                    appendLog(state.current_step);
                },
                function onComplete(state) {
                    fill.css('width', '100%');
                    percentText.text('100%');
                    statusText.text('Backup completed successfully!');
                    appendLog('Backup finished. Archive: ' + state.backup_file);
                    closeBtn.show().text('Done & Refresh');
                    if (state.download_url) {
                        $('#airackup-btn-download-modal')
                            .attr('href', state.download_url)
                            .data('url', state.download_url)
                            .data('filename', state.backup_file)
                            .show();
                    }
                },
                function onError(err) {
                    statusText.text('Error: ' + err);
                    appendLog('ERROR: ' + err);
                    closeBtn.show().text('Close');
                }
            );
        });

        // Safe In-Memory Blob Downloader (bypasses Chrome's "Insecure download blocked" on HTTP sites)
        $(document).on('click', '.airackup-action-download, #airackup-btn-download-modal', function(e) {
            var btn = $(this);
            var downloadUrl = btn.data('url') || btn.attr('href');
            var rawFilename = btn.data('filename') || 'backup.airakup';
            var filename = rawFilename.replace(/\.(tar|airackup)$/i, '.airakup');

            if (window.location.protocol === 'http:') {
                e.preventDefault();
                var origHtml = btn.html();
                btn.css('pointer-events', 'none').html('<span class="dashicons dashicons-update"></span> Downloading...');

                fetch(downloadUrl, { credentials: 'same-origin' })
                    .then(function(res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.blob();
                    })
                    .then(function(blob) {
                        var blobUrl = window.URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = blobUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(function() {
                            document.body.removeChild(a);
                            window.URL.revokeObjectURL(blobUrl);
                        }, 1000);
                        btn.css('pointer-events', '').html(origHtml);
                    })
                    .catch(function(err) {
                        btn.css('pointer-events', '').html(origHtml);
                        window.location.href = downloadUrl;
                    });
            }
        });

        // 2. Restore from server table
        $('.airackup-action-restore').on('click', function() {
            var filename = $(this).data('file');
            if (!confirm('Are you sure you want to restore "' + filename + '"? This will overwrite the current database and files.')) {
                return;
            }

            var searchUrl = $('#airackup-search-url').val();
            var replaceUrl = $('#airackup-replace-url').val();

            showModal('Restoring Site from ' + filename);
            appendLog('Starting Airackup restore worker...');

            var worker = new AirackupRestoreWorker({
                endpoint: airackup_vars.ajax_url,
                nonce: airackup_vars.nonce
            });

            worker.start({
                backup_file: filename,
                search_url: searchUrl,
                replace_url: replaceUrl
            },
            function onTick(state) {
                fill.css('width', state.percent + '%');
                percentText.text(state.percent + '%');
                statusText.text(state.current_step);
                appendLog(state.current_step);
            },
            function onComplete(state) {
                fill.css('width', '100%');
                percentText.text('100%');
                statusText.text('Site restored successfully!');
                appendLog('Restore completed! Caches flushed.');
                closeBtn.show().text('Finished & Reload Site');
            },
            function onError(err) {
                statusText.text('Error: ' + err);
                appendLog('ERROR: ' + err);
                closeBtn.show().text('Close');
            });
        });

        // 3. Delete Backup
        $('.airackup-action-delete').on('click', function() {
            var filename = $(this).data('file');
            if (!confirm('Permanently delete backup "' + filename + '"?')) return;

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_delete_backup',
                nonce: airackup_vars.nonce,
                filename: filename
            }, function(res) {
                if (res.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + (res.data ? res.data.message : 'Delete failed'));
                }
            });
        });

        // 4. Drag & Drop Chunked Upload
        var dropzone = $('#airackup-dropzone');
        var fileInput = $('#airackup-file-input');

        $('#airackup-btn-browse').on('click', function() {
            fileInput.trigger('click');
        });

        dropzone.on('dragover', function(e) {
            e.preventDefault();
            dropzone.addClass('dragover');
        });

        dropzone.on('dragleave drop', function(e) {
            e.preventDefault();
            dropzone.removeClass('dragover');
        });

        dropzone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) handleFile(files[0]);
        });

        fileInput.on('change', function() {
            if (this.files.length > 0) handleFile(this.files[0]);
        });

        function handleFile(file) {
            var lower = file.name.toLowerCase();
            if (!lower.endsWith('.airakup') && !lower.endsWith('.airackup') && !lower.endsWith('.tar')) {
                alert('Please upload a valid Airackup archive (.airakup, .airackup, or .tar).');
                return;
            }

            showModal('Uploading ' + file.name);
            appendLog('Preparing 2MB chunk slicer for ' + file.name + ' (' + Math.round(file.size / 1048576) + ' MB)...');

            var uploader = new AirackupChunkUploader({
                endpoint: airackup_vars.ajax_url,
                nonce: airackup_vars.nonce
            });

            uploader.upload(file,
                function onProgress(pct, loaded, total, currentChunk, totalChunks) {
                    fill.css('width', (pct * 0.4) + '%'); // 0-40% for upload phase
                    percentText.text(Math.round(pct * 0.4) + '%');
                    statusText.text('Uploading chunk ' + currentChunk + ' / ' + totalChunks + ' (' + pct + '%)');
                },
                function onComplete(res) {
                    appendLog('All chunks assembled successfully on server.');
                    appendLog('Now beginning site restoration...');

                    var searchUrl = $('#airackup-search-url').val();
                    var replaceUrl = $('#airackup-replace-url').val();

                    var restoreWorker = new AirackupRestoreWorker({
                        endpoint: airackup_vars.ajax_url,
                        nonce: airackup_vars.nonce
                    });

                    restoreWorker.start({
                        upload_id: res.uploadId,
                        backup_file: res.filename,
                        search_url: searchUrl,
                        replace_url: replaceUrl
                    },
                    function onRestoreTick(state) {
                        var totalPct = Math.round(40 + (state.percent * 0.6));
                        fill.css('width', totalPct + '%');
                        percentText.text(totalPct + '%');
                        statusText.text(state.current_step);
                        appendLog(state.current_step);
                    },
                    function onRestoreComplete() {
                        fill.css('width', '100%');
                        percentText.text('100%');
                        statusText.text('Site restored successfully!');
                        appendLog('Restoration & serialized migration complete!');
                        closeBtn.show().text('Finished & Reload Site');
                    },
                    function onRestoreError(err) {
                        statusText.text('Restore error: ' + err);
                        appendLog('ERROR: ' + err);
                        closeBtn.show().text('Close');
                    });
                },
                function onError(err) {
                    statusText.text('Upload error: ' + err);
                    appendLog('ERROR: ' + err);
                    closeBtn.show().text('Close');
                }
            );
        }

        // 5. Test Cloud Storage Connection
        $('.airackup-test-conn').on('click', function() {
            var btn = $(this);
            var provider = btn.data('provider');
            btn.text('Testing...').prop('disabled', true);

            var config = {
                access_key: $('#s3-access-key').val(),
                secret_key: $('#s3-secret-key').val(),
                bucket: $('#s3-bucket').val(),
                region: $('#s3-region').val(),
                endpoint: $('#s3-endpoint').val()
            };

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_test_storage',
                nonce: airackup_vars.nonce,
                provider: provider,
                config: config
            }, function(res) {
                btn.text('Test Connection').prop('disabled', false);
                if (res.success && res.data && res.data.success) {
                    alert('Connection successful! ' + res.data.message);
                } else {
                    alert('Connection test failed: ' + (res.data ? res.data.message : 'Unknown error'));
                }
            });
        });

        // 6. Cloud Storage Actions
        // Connect Google Service Account
        $('#airackup-btn-connect-gdrive-sa').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var saJson = $('#gdrive-service-json').val().trim();
            if (!saJson) {
                alert('Please paste your Google Cloud Service Account JSON key first.');
                return;
            }
            try {
                var parsed = JSON.parse(saJson);
                if (!parsed.client_email) throw new Error('Missing client_email in JSON');
            } catch (err) {
                alert('Invalid JSON key format. Please ensure you copied the full service account JSON file: ' + err.message);
                return;
            }

            var origHtml = btn.html();
            btn.css('pointer-events', 'none').text('Verifying & Connecting...');

            var settings = {
                gdrive: {
                    service_account_json: saJson,
                    client_id: $('#gdrive-client-id').val(),
                    client_secret: $('#gdrive-client-secret').val()
                }
            };

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_save_settings',
                nonce: airackup_vars.nonce,
                settings: settings
            }, function(res) {
                alert('Google Drive connected successfully via Service Account!');
                window.location.reload();
            }).fail(function() {
                alert('Failed to save Google Drive settings.');
                btn.css('pointer-events', '').html(origHtml);
            });
        });

        // Authorize with Google OAuth
        $('#airackup-btn-auth-gdrive-oauth').on('click', function(e) {
            e.preventDefault();
            var clientId = $('#gdrive-client-id').val().trim();
            var clientSecret = $('#gdrive-client-secret').val().trim();
            var redirectUri = $('#airackup-gdrive-redirect-uri').text().trim();

            if (!clientId || !clientSecret) {
                alert('Please enter your Google Client ID and Client Secret from Google Cloud Console.');
                return;
            }

            var btn = $(this);
            var origHtml = btn.html();
            btn.css('pointer-events', 'none').text('Saving & Redirecting to Google...');

            var settings = {
                gdrive: {
                    client_id: clientId,
                    client_secret: clientSecret
                }
            };

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_save_settings',
                nonce: airackup_vars.nonce,
                settings: settings
            }, function(res) {
                var authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' + $.param({
                    response_type: 'code',
                    client_id: clientId,
                    redirect_uri: redirectUri,
                    scope: 'https://www.googleapis.com/auth/drive.file',
                    access_type: 'offline',
                    prompt: 'consent'
                });
                window.location.href = authUrl;
            }).fail(function() {
                alert('Failed to save settings.');
                btn.css('pointer-events', '').html(origHtml);
            });
        });

        // Connect Dropbox Token
        $('#airackup-btn-connect-dropbox-token').on('click', function(e) {
            e.preventDefault();
            var token = $('#dropbox-access-token').val().trim();
            if (!token) {
                alert('Please paste your generated Dropbox Access Token first.');
                return;
            }

            var btn = $(this);
            var origHtml = btn.html();
            btn.css('pointer-events', 'none').text('Verifying & Connecting...');

            var settings = {
                dropbox: {
                    access_token: token
                }
            };

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_save_settings',
                nonce: airackup_vars.nonce,
                settings: settings
            }, function(res) {
                alert('Dropbox connected successfully!');
                window.location.reload();
            }).fail(function() {
                alert('Failed to save Dropbox token.');
                btn.css('pointer-events', '').html(origHtml);
            });
        });

        // Save All Storage Settings
        $('#airackup-btn-save-storage').on('click', function() {
            var settings = {
                s3: {
                    access_key: $('#s3-access-key').val(),
                    secret_key: $('#s3-secret-key').val(),
                    bucket: $('#s3-bucket').val(),
                    region: $('#s3-region').val(),
                    endpoint: $('#s3-endpoint').val()
                },
                gdrive: {
                    service_account_json: $('#gdrive-service-json').val(),
                    client_id: $('#gdrive-client-id').val(),
                    client_secret: $('#gdrive-client-secret').val()
                },
                dropbox: {
                    access_token: $('#dropbox-access-token').val()
                },
                sftp: {
                    host: $('#sftp-host').val(),
                    port: $('#sftp-port').val(),
                    username: $('#sftp-user').val(),
                    password: $('#sftp-pass').val()
                }
            };

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_save_settings',
                nonce: airackup_vars.nonce,
                settings: settings
            }, function(res) {
                alert('Storage settings saved successfully!');
            });
        });

        // 7. Save Schedule Settings
        $('#airackup-btn-save-schedule').on('click', function() {
            var settings = {
                schedule_frequency: $('#airackup-setting-freq').val(),
                retention_count: $('#airackup-setting-retention').val(),
                enable_incremental: $('#airackup-setting-incremental').is(':checked') ? 1 : 0
            };

            $.post(airackup_vars.ajax_url, {
                action: 'airackup_save_settings',
                nonce: airackup_vars.nonce,
                settings: settings
            }, function(res) {
                alert('Schedule settings saved successfully!');
            });
        });
    });
})(jQuery);
