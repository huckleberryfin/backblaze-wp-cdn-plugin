<?php
if (!defined('ABSPATH')) exit;

class BB_Bulk_Sync {
    
    public function __construct() {
        add_action('wp_ajax_bb_bulk_sync', array($this, 'ajax_sync_batch'));
    }

    public function sync_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Sync Media to CDN</h1>
            
            <div id="sync-status" style="margin: 20px 0;">
                <p><strong>Total files:</strong> <span id="total-files">Calculating...</span></p>
<p><strong>Successfully synced:</strong> <span id="synced-files">-</span></p>
<p><strong>Failed:</strong> <span id="failed-files" style="color: red;">-</span></p>
<p><strong>Remaining:</strong> <span id="remaining-files">-</span></p>
            </div>

            <div style="margin: 20px 0;">
                <label>
                    <input type="checkbox" id="force-reupload" value="1">
                    Force re-upload (upload even if already marked as synced)
                </label>
            </div>

            <button id="start-sync" class="button button-primary button-large">Start Bulk Sync</button>
            <button id="stop-sync" class="button button-secondary" style="display:none;">Stop Sync</button>
            <button id="reset-sync" class="button button-secondary" style="margin-left: 10px;">Reset All Sync Status</button>

            <div id="sync-progress" style="margin-top: 20px; display:none;">
                <div style="background: #f0f0f1; border: 1px solid #ccc; border-radius: 4px; height: 30px; position: relative; overflow: hidden;">
                    <div id="progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    <span id="progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; color: #000;">0%</span>
                </div>
            </div>

            <div id="sync-log" style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ccc; border-radius: 4px; max-height: 400px; overflow-y: auto; display:none;">
                <h3>Sync Log</h3>
                <div id="log-content" style="font-family: monospace; font-size: 12px;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const ajaxNonce = '<?php echo wp_create_nonce('wp_ajax'); ?>';
            let isRunning = false;
            let shouldStop = false;
            let totalFiles = 0;
            let processedFiles = 0;

            // Get file counts on load
            $.post(ajaxurl, {
                action: 'bb_bulk_sync',
                task: 'get_counts',
                _ajax_nonce: ajaxNonce
            }, function(response) {
                if (response.success) {
                    totalFiles = response.data.total;
                    $('#total-files').text(totalFiles);
                    $('#synced-files').text(response.data.synced);
                    $('#remaining-files').text(response.data.remaining);
                    $('#failed-files').text(response.data.failed);
                }
            });

            $('#start-sync').on('click', function() {
                if (isRunning) return;
                
                isRunning = true;
                shouldStop = false;
                processedFiles = 0;
                
                $(this).hide();
                $('#stop-sync').show();
                $('#sync-progress').show();
                $('#sync-log').show();
                $('#log-content').html('');
                
                const forceReupload = $('#force-reupload').is(':checked');
                log('🚀 Starting bulk sync...', '#2271b1');
                processBatch(forceReupload);
            });

            $('#stop-sync').on('click', function() {
                shouldStop = true;
                $(this).prop('disabled', true).text('Stopping...');
                log('⏸ Stopping sync...', 'orange');
            });

            $('#reset-sync').on('click', function() {
                if (!confirm('This will reset all sync status and allow re-uploading all files. Continue?')) {
                    return;
                }
                
                $(this).prop('disabled', true).text('Resetting...');
                
                $.post(ajaxurl, {
                    action: 'bb_bulk_sync',
                    task: 'reset_status',
                    _ajax_nonce: ajaxNonce
                }, function(response) {
                    if (response.success) {
                        alert('Sync status reset! ' + response.data.count + ' files marked for re-sync.');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $('#reset-sync').prop('disabled', false).text('Reset All Sync Status');
                    }
                });
            });

            function processBatch(forceReupload) {
                if (shouldStop) {
                    isRunning = false;
                    $('#start-sync').show();
                    $('#stop-sync').hide().prop('disabled', false).text('Stop Sync');
                    log('✓ Sync stopped by user', 'green');
                    return;
                }

                log('📤 Processing batch...', '#666');

                $.post(ajaxurl, {
                    action: 'bb_bulk_sync',
                    task: 'sync_batch',
                    force: forceReupload ? 1 : 0,
                    batch_size: 5,
                    _ajax_nonce: ajaxNonce
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        processedFiles += data.processed;
                        
                        // Update progress
                        const percentage = totalFiles > 0 ? Math.round((processedFiles / totalFiles) * 100) : 0;
                        $('#progress-bar').css('width', percentage + '%');
                        $('#progress-text').text(percentage + '%');
                        
                        // Update remaining count
                        const remaining = totalFiles - processedFiles;
                        $('#remaining-files').text(remaining);
                        $('#synced-files').text(processedFiles);
                        
                        // Log results with running count
                        data.results.forEach(function(result, index) {
                            const currentFile = processedFiles - data.processed + index + 1;
                            const progressText = '[' + currentFile + '/' + totalFiles + ']';
                            
                            if (result.success) {
                                log(progressText + ' ✓ ' + result.file, 'green');
                            } else {
                                log(progressText + ' ✗ ' + result.file + ' - ' + result.error, 'red');
                            }
                        });
                        
                        // Continue if more files to process
                        if (data.has_more && !shouldStop) {
                            setTimeout(() => processBatch(forceReupload), 0);
                        } else {
                            isRunning = false;
                            $('#start-sync').show();
                            $('#stop-sync').hide();
                            log('✓ Sync complete! Processed ' + processedFiles + ' files.', 'green');
                            
                            // Refresh counts without full page reload
                            $.post(ajaxurl, {
                                action: 'bb_bulk_sync',
                                task: 'get_counts',
                                _ajax_nonce: ajaxNonce
                            }, function(response) {
                                if (response.success) {
                                    $('#synced-files').text(response.data.synced);
                                    $('#remaining-files').text(response.data.remaining);
                                }
                            });
                        }
                    } else {
                        isRunning = false;
                        $('#start-sync').show();
                        $('#stop-sync').hide();
                        log('✗ Error: ' + (response.data || 'Unknown error'), 'red');
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    log('⚠ Connection error: ' + textStatus + ' - Retrying in 3 seconds...', 'orange');
                    setTimeout(() => processBatch(forceReupload), 3000);
                });
            }

            function log(message, color) {  
                const timestamp = new Date().toLocaleTimeString();
                $('#log-content').append(
                    '<div style="color: ' + color + '">[' + timestamp + '] ' + message + '</div>'
                );
                $('#log-content').scrollTop($('#log-content')[0].scrollHeight);
            }
        });
        </script>
        <?php
    }

    public function ajax_sync_batch() {
        check_ajax_referer('wp_ajax');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Rate limiting: max 10 requests per minute per IP
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_limit_key = 'bb_sync_rate_' . md5($ip);
        $request_count = get_transient($rate_limit_key);

        if ($request_count >= 10) {
            wp_send_json_error('Rate limit exceeded. Please wait before making another request.');
        }

        set_transient($rate_limit_key, ($request_count ?: 0) + 1, 60);

        $task = sanitize_text_field($_POST['task'] ?? '');

        if ($task === 'get_counts') {
            $this->get_file_counts();
        } elseif ($task === 'sync_batch') {
            $this->sync_batch();
        } elseif ($task === 'reset_status') {
            $this->reset_sync_status();
        } else {
            wp_send_json_error('Invalid task');
        }
    }

 private function get_file_counts() {
    $args = array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids'
    );

    $attachments = get_posts($args);
    $total = count($attachments);
    $synced = 0;
    $failed = 0;

    foreach ($attachments as $attachment_id) {
        $status = get_post_meta($attachment_id, '_bb_uploaded', true);
        if ($status === '1') {
            $synced++;
        } elseif ($status === 'failed') {
            $failed++;
        }
    }

    wp_send_json_success(array(
        'total' => $total,
        'synced' => $synced,
        'failed' => $failed,
        'remaining' => $total - $synced - $failed
    ));
}

    private function sync_batch() {
        $batch_size = intval($_POST['batch_size'] ?? 10);
        $force = intval($_POST['force'] ?? 0);

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $batch_size,
            'fields' => 'ids'
        );

   if (!$force) {
    $args['meta_query'] = array(
        array(
            'key' => '_bb_uploaded',
            'compare' => 'NOT EXISTS'
        )
    );
}

        $attachments = get_posts($args);
        $results = array();

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';
        
        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            get_option('bb_key_id'),
            get_option('bb_app_key')
        );

        foreach ($attachments as $attachment_id) {
            $file = get_attached_file($attachment_id);
            $wp_upload_dir = wp_upload_dir();
            $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $file);

            if (!file_exists($file)) {
                // Mark as "failed" so we skip it next time
                update_post_meta($attachment_id, '_bb_uploaded', 'failed');
                
                $results[] = array(
                    'success' => false,
                    'file' => $relative_path,
                    'error' => 'File not found locally'
                );
                continue;
            }

            // Upload main file
            $result = $uploader->upload_file($file, $relative_path);

            if ($result['success']) {
                // Upload thumbnails
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (isset($metadata['sizes'])) {
                    $base_dir = dirname($file);
                    $base_path = dirname($relative_path);

                    foreach ($metadata['sizes'] as $size => $data) {
                        $thumb_file = $base_dir . '/' . $data['file'];
                        $thumb_path = $base_path . '/' . $data['file'];
                        
                        if (file_exists($thumb_file)) {
                            $uploader->upload_file($thumb_file, $thumb_path);
                        }
                    }
                }

                update_post_meta($attachment_id, '_bb_uploaded', 1);
                
                $results[] = array(
                    'success' => true,
                    'file' => $relative_path
                );
            } else {
                $results[] = array(
                    'success' => false,
                    'file' => $relative_path,
                    'error' => 'Upload failed: ' . $result['response']
                );
            }
        }

        wp_send_json_success(array(
            'processed' => count($attachments),
            'has_more' => count($attachments) === $batch_size,
            'results' => $results
        ));
    }

    private function reset_sync_status() {
        global $wpdb;

        // Properly use $wpdb->delete() instead of raw query
        $count = $wpdb->delete(
            $wpdb->postmeta,
            array('meta_key' => '_bb_uploaded'),
            array('%s')
        );

        if ($count === false) {
            wp_send_json_error('Failed to reset sync status: ' . $wpdb->last_error);
        }

        wp_send_json_success(array(
            'count' => $count
        ));
    }
}