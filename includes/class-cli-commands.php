<?php
if (!defined('ABSPATH')) exit;

/**
 * Backblaze Auto Upload WP-CLI Commands
 * Usage:
 *   wp bb-sync start [--force] [--batch-size=5]
 *   wp bb-sync status
 *   wp bb-sync retry [--count=5]
 *   wp bb-sync reset
 */

if (!class_exists('WP_CLI')) {
    return;
}

class BB_CLI_Commands extends WP_CLI_Command {

    /**
     * Start bulk sync of media to Backblaze with progress bar
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force re-upload all files even if already synced
     *
     * [--batch-size=<num>]
     * : Number of files to process per batch (default: 5)
     *
     * ## EXAMPLES
     *
     *     wp bb-sync start
     *     wp bb-sync start --force
     *     wp bb-sync start --batch-size=10
     *
     * @when after_wp_load
     */
    public function start($args, $assoc_args) {
        $force = isset($assoc_args['force']);
        $batch_size = intval($assoc_args['batch-size'] ?? 5);

        // Verify settings are configured
        if (!$this->verify_configuration()) {
            return;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            get_option('bb_key_id'),
            get_option('bb_app_key')
        );

        // Get file counts
        $attachments = $this->get_attachments_for_sync($force);
        $attachment_count = count($attachments);

        if ($attachment_count === 0) {
            WP_CLI::success('No files to sync.');
            return;
        }

        // Calculate total actual files (including thumbnails)
        $total_files = $this->get_total_file_count($attachments);

        WP_CLI::line("");
        WP_CLI::line("Starting bulk sync: $attachment_count attachments ($total_files total files including thumbnails)");
        WP_CLI::line("Batch size: $batch_size | Force re-upload: " . ($force ? 'Yes' : 'No'));
        WP_CLI::line("");

        $progress = WP_CLI\Utils\make_progress_bar('Uploading files', $total_files);

        $processed_attachments = 0;
        $processed_files = 0;
        $successful = 0;
        $failed = 0;
        $failed_files = array();

        foreach (array_chunk($attachments, $batch_size) as $batch) {
            foreach ($batch as $attachment_id) {
                $file = get_attached_file($attachment_id);
                $wp_upload_dir = wp_upload_dir();
                $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $file);

                if (!file_exists($file)) {
                    update_post_meta($attachment_id, '_bb_uploaded', 'failed');
                    $failed++;
                    $failed_files[] = $relative_path . ' (file not found)';

                    // Progress for main file
                    $progress->tick();
                    $processed_files++;

                    // Progress for missing thumbnails
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    if (isset($metadata['sizes'])) {
                        foreach ($metadata['sizes'] as $size => $data) {
                            $progress->tick();
                            $processed_files++;
                        }
                    }

                    $processed_attachments++;
                    continue;
                }

                // Upload main file
                $result = $uploader->upload_file($file, $relative_path);
                $progress->tick();
                $processed_files++;

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

                            $progress->tick();
                            $processed_files++;
                        }
                    }

                    update_post_meta($attachment_id, '_bb_uploaded', 1);
                    $successful++;
                } else {
                    update_post_meta($attachment_id, '_bb_uploaded', 'failed');
                    $failed++;
                    $failed_files[] = $relative_path . ' (' . $result['http_code'] . ')';

                    // Progress for thumbnails that weren't attempted
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    if (isset($metadata['sizes'])) {
                        foreach ($metadata['sizes'] as $size => $data) {
                            $progress->tick();
                            $processed_files++;
                        }
                    }
                }

                $processed_attachments++;
            }

            // Clear object cache periodically to avoid memory bloat
            wp_cache_flush();
        }

        $progress->finish();

        WP_CLI::line("");
        WP_CLI::success(sprintf(
            'Sync complete! Attachments processed: %d | Files uploaded: %d | Failed: %d',
            $processed_attachments,
            $processed_files,
            $failed
        ));

        if (!empty($failed_files) && $failed <= 10) {
            WP_CLI::line("");
            WP_CLI::line("Failed files:");
            foreach ($failed_files as $file) {
                WP_CLI::line("  ✗ $file");
            }
        } elseif ($failed > 10) {
            WP_CLI::line("");
            WP_CLI::warning("$failed files failed. Run 'wp bb-sync retry' to retry failed uploads.");
        }
    }

    /**
     * Get current sync status and statistics
     *
     * ## EXAMPLES
     *
     *     wp bb-sync status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        if (!$this->verify_configuration()) {
            return;
        }

        $query_args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $attachments = get_posts($query_args);
        $attachment_total = count($attachments);
        $synced_count = 0;
        $failed_count = 0;
        $pending_count = 0;

        // Calculate total files (including thumbnails)
        $total_files = 0;
        $synced_files = 0;
        $failed_files = 0;
        $pending_files = 0;

        foreach ($attachments as $attachment_id) {
            $file_count = $this->get_file_count_for_attachment($attachment_id);
            $total_files += $file_count;

            $status = get_post_meta($attachment_id, '_bb_uploaded', true);
            if ($status === '1') {
                $synced_count++;
                $synced_files += $file_count;
            } elseif ($status === 'failed') {
                $failed_count++;
                $failed_files += $file_count;
            } else {
                $pending_count++;
                $pending_files += $file_count;
            }
        }

        WP_CLI::line("");
        WP_CLI::line("Backblaze Sync Status:");
        WP_CLI::line("─────────────────────────────────────");
        WP_CLI::line("(Showing actual files including thumbnails)");
        WP_CLI::line("─────────────────────────────────────");

        $this->print_stat('Total Files', $total_files);
        $this->print_stat('Synced', $synced_files, 'green');
        $this->print_stat('Failed', $failed_files, 'red');
        $this->print_stat('Pending', $pending_files, 'yellow');

        if ($total_files > 0) {
            $percentage = round(($synced_files / $total_files) * 100, 2);
            WP_CLI::line("─────────────────────────────────────");
            WP_CLI::line(sprintf("Completion: %.2f%%", $percentage));
            WP_CLI::line("");
            WP_CLI::line("Attachment Posts: $synced_count/$attachment_total synced");
        }

        WP_CLI::line("");
    }

    /**
     * Retry failed uploads
     *
     * ## OPTIONS
     *
     * [--count=<num>]
     * : Number of failed files to retry (default: all)
     *
     * ## EXAMPLES
     *
     *     wp bb-sync retry
     *     wp bb-sync retry --count=5
     *
     * @when after_wp_load
     */
    public function retry($args, $assoc_args) {
        if (!$this->verify_configuration()) {
            return;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            get_option('bb_key_id'),
            get_option('bb_app_key')
        );

        $count_limit = intval($assoc_args['count'] ?? -1);

        // Get failed attachments
        $query_args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => $count_limit > 0 ? $count_limit : -1,
            'meta_query' => array(
                array(
                    'key' => '_bb_uploaded',
                    'value' => 'failed',
                    'compare' => '='
                )
            )
        );

        $attachments = get_posts($query_args);
        $total = count($attachments);

        if ($total === 0) {
            WP_CLI::success('No failed files to retry.');
            return;
        }

        WP_CLI::line("");
        WP_CLI::line("Retrying $total failed uploads...");
        WP_CLI::line("");

        $progress = WP_CLI\Utils\make_progress_bar('Retrying uploads', $total);

        $successful = 0;
        $still_failed = 0;

        foreach ($attachments as $attachment_id) {
            $file = get_attached_file($attachment_id);
            $wp_upload_dir = wp_upload_dir();
            $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $file);

            if (!file_exists($file)) {
                $progress->tick();
                $still_failed++;
                continue;
            }

            $result = $uploader->upload_file($file, $relative_path);

            if ($result['success']) {
                update_post_meta($attachment_id, '_bb_uploaded', 1);
                $successful++;
            } else {
                $still_failed++;
            }

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::line("");
        WP_CLI::success(sprintf(
            'Retry complete! Recovered: %d | Still failed: %d',
            $successful,
            $still_failed
        ));
    }

    /**
     * Reset all sync status (useful for troubleshooting)
     *
     * ## EXAMPLES
     *
     *     wp bb-sync reset
     *
     * @when after_wp_load
     */
    public function reset($args, $assoc_args) {
        global $wpdb;

        WP_CLI::confirm('This will reset all sync status and mark all files as unsynced. Continue?');

        $count = $wpdb->delete(
            $wpdb->postmeta,
            array('meta_key' => '_bb_uploaded'),
            array('%s')
        );

        if ($count === false) {
            WP_CLI::error('Failed to reset sync status: ' . $wpdb->last_error);
        }

        WP_CLI::success("Reset complete! Marked $count attachments for re-sync.");
    }

    /**
     * Verify existing files on B2 CDN and mark them as synced
     *
     * Scans B2 CDN for existing image files and marks them as uploaded
     * in the database. This is useful when deploying to production where
     * images already exist on B2 from previous syncs.
     *
     * ## OPTIONS
     *
     * [--batch-size=<num>]
     * : Number of files to check per batch (default: 10)
     *
     * ## EXAMPLES
     *
     *     wp bb-sync verify
     *     wp bb-sync verify --batch-size=20
     *
     * @when after_wp_load
     */
    public function verify($args, $assoc_args) {
        if (!$this->verify_configuration()) {
            return;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        $batch_size = intval($assoc_args['batch-size'] ?? 10);

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            get_option('bb_key_id'),
            get_option('bb_app_key')
        );

        // Get all attachments
        $query_args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $attachments = get_posts($query_args);
        $attachment_count = count($attachments);

        if ($attachment_count === 0) {
            WP_CLI::success('No attachments to verify.');
            return;
        }

        // Calculate total files (including thumbnails)
        $total_files = $this->get_total_file_count($attachments);

        WP_CLI::line("");
        WP_CLI::line("Verifying files on B2 CDN: $attachment_count attachments ($total_files total files)");
        WP_CLI::line("Batch size: $batch_size");
        WP_CLI::line("");

        $progress = WP_CLI\Utils\make_progress_bar('Verifying files', $total_files);

        $verified = 0;
        $not_found = 0;
        $not_found_files = array();
        $wp_upload_dir = wp_upload_dir();

        foreach (array_chunk($attachments, $batch_size) as $batch) {
            foreach ($batch as $attachment_id) {
                $file = get_attached_file($attachment_id);

                if (!$file) {
                    // File doesn't exist locally, skip
                    $progress->tick();
                    $not_found++;
                    continue;
                }

                $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $file);

                // Check main file
                $exists_on_b2 = $uploader->file_exists_on_b2($relative_path);
                $progress->tick();

                if ($exists_on_b2) {
                    $verified++;
                } else {
                    $not_found++;
                    $not_found_files[] = $relative_path;
                }

                // Check thumbnails
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (isset($metadata['sizes'])) {
                    $base_dir = dirname($file);
                    $base_path = dirname($relative_path);

                    foreach ($metadata['sizes'] as $size => $data) {
                        $thumb_path = $base_path . '/' . $data['file'];

                        if ($uploader->file_exists_on_b2($thumb_path)) {
                            $verified++;
                        } else {
                            $not_found++;
                            $not_found_files[] = $thumb_path;
                        }

                        $progress->tick();
                    }
                }
            }

            wp_cache_flush();
        }

        $progress->finish();

        WP_CLI::line("");

        if ($verified > 0) {
            // Mark verified attachments as synced
            WP_CLI::line("Marking verified files as synced in database...");

            foreach ($attachments as $attachment_id) {
                $file = get_attached_file($attachment_id);
                if (!$file) continue;

                $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $file);

                // Check if main file exists
                if ($uploader->file_exists_on_b2($relative_path)) {
                    update_post_meta($attachment_id, '_bb_uploaded', 1);
                }
            }
        }

        WP_CLI::success(sprintf(
            'Verification complete! Found on B2: %d files | Not found: %d files',
            $verified,
            $not_found
        ));

        if (!empty($not_found_files) && count($not_found_files) <= 20) {
            WP_CLI::line("");
            WP_CLI::line("Files not found on B2:");
            foreach (array_slice($not_found_files, 0, 20) as $file) {
                WP_CLI::line("  ✗ $file");
            }
            if (count($not_found_files) > 20) {
                WP_CLI::line("  ... and " . (count($not_found_files) - 20) . " more");
            }
        }
    }



    /**
     * Run URL replacement test suite
     *
     * ## EXAMPLES
     *
     *     wp bb-sync test-urls
     *
     * @when after_wp_load
     */
    public function test_urls($args, $assoc_args) {
        require_once plugin_dir_path(__FILE__) . 'class-test-suite.php';

        WP_CLI::line("Running URL replacement tests...");
        WP_CLI::line("");

        $results = BB_Test_Suite::run_all_tests();
        $report = BB_Test_Suite::generate_report($results);

        WP_CLI::line($report);
    }

    /**
     * Test Backblaze connection
     *
     * ## EXAMPLES
     *
     *     wp bb-sync test
     *
     * @when after_wp_load
     */
    public function test($args, $assoc_args) {
        if (!$this->verify_configuration()) {
            return;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        WP_CLI::line("Testing Backblaze connection...");

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            get_option('bb_key_id'),
            get_option('bb_app_key')
        );

        $test_content = "Test upload at " . date('Y-m-d H:i:s');
        $test_filename = 'test-' . time() . '.txt';
        $test_path = 'backblaze-test/' . $test_filename;

        $temp_file = sys_get_temp_dir() . '/' . $test_filename;
        file_put_contents($temp_file, $test_content);

        $result = $uploader->upload_file($temp_file, $test_path);
        @unlink($temp_file);

        if ($result['success']) {
            $cdn_url = $uploader->get_cdn_url() . '/' . $test_path;
            WP_CLI::success("Connection successful!");
            WP_CLI::line("Test file uploaded to: $test_path");
            WP_CLI::line("CDN URL: $cdn_url");
        } else {
            WP_CLI::error("Connection failed!");
            WP_CLI::line("HTTP Code: " . $result['http_code']);
            WP_CLI::line("Error: " . $result['response']);
        }
    }

    /**
     * Helper: Verify Backblaze configuration
     */
    private function verify_configuration() {
        $required_options = array('bb_bucket', 'bb_endpoint', 'bb_cdn_url', 'bb_key_id', 'bb_app_key');

        foreach ($required_options as $option) {
            if (!get_option($option)) {
                WP_CLI::error("Backblaze CDN is not configured. Please set $option in WordPress admin.");
                return false;
            }
        }

        return true;
    }

    /**
     * Helper: Get attachments for sync
     */
    private function get_attachments_for_sync($force = false) {
        $query_args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        if (!$force) {
            $query_args['meta_query'] = array(
                array(
                    'key' => '_bb_uploaded',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        return get_posts($query_args);
    }

    /**
     * Helper: Count actual files for a single attachment (main + thumbnails)
     */
    private function get_file_count_for_attachment($attachment_id) {
        $count = 1; // Main file
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            $count += count($metadata['sizes']);
        }

        return $count;
    }

    /**
     * Helper: Get total file count for all attachments
     */
    private function get_total_file_count($attachment_ids) {
        $total = 0;
        foreach ($attachment_ids as $attachment_id) {
            $total += $this->get_file_count_for_attachment($attachment_id);
        }
        return $total;
    }

    /**
     * Helper: Print formatted status line
     */
    private function print_stat($label, $value, $color = '') {
        $line = sprintf("%-15s: %d", $label, $value);

        if ($color === 'green') {
            WP_CLI::line(WP_CLI::colorize("%g$line%n"));
        } elseif ($color === 'red') {
            WP_CLI::line(WP_CLI::colorize("%r$line%n"));
        } elseif ($color === 'yellow') {
            WP_CLI::line(WP_CLI::colorize("%y$line%n"));
        } else {
            WP_CLI::line($line);
        }
    }
}

if (class_exists('WP_CLI')) {
    WP_CLI::add_command('bb-sync', 'BB_CLI_Commands');
}
