<?php
if (!defined('ABSPATH')) exit;

class BB_CRON_Handler {
    const CRON_HOOK = 'bb_verify_sync_cron';
    const CRON_INTERVAL = 'every_minute';

    public function __construct() {
        add_action(self::CRON_HOOK, array($this, 'run_background_sync'));
        register_activation_hook(plugin_dir_path(__FILE__) . '../backblaze-auto-upload.php', array($this, 'schedule_cron'));
        register_deactivation_hook(plugin_dir_path(__FILE__) . '../backblaze-auto-upload.php', array($this, 'unschedule_cron'));

        // Register custom cron interval
        add_filter('cron_schedules', array($this, 'add_custom_interval'));
    }

    public function add_custom_interval($schedules) {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 60,
                'display' => 'Every Minute'
            );
        }
        return $schedules;
    }

    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public function unschedule_cron() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Run background verification sync
     * Called by WP-CRON every minute
     */
    public function run_background_sync() {
        // Get the verification status
        $status = get_option('bb_verify_sync_status', array(
            'running' => false,
            'total_files' => 0,
            'verified_files' => 0,
            'failed_files' => 0,
            'last_batch_offset' => 0,
            'started_at' => null,
            'last_run_at' => null
        ));

        // If not running, start it
        if (!$status['running']) {
            // Check if we have credentials
            $key_id = get_option('bb_key_id');
            $app_key = get_option('bb_app_key');

            if (!$key_id || !$app_key) {
                return; // Can't run without credentials
            }

            // Get total count of attachments to verify
            global $wpdb;
            $total = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
            );

            if ($total === null) {
                $total = 0;
            }

            $status['running'] = true;
            $status['total_files'] = $total;
            $status['verified_files'] = 0;
            $status['failed_files'] = 0;
            $status['last_batch_offset'] = 0;
            $status['started_at'] = current_time('mysql');
            $status['last_run_at'] = current_time('mysql');

            update_option('bb_verify_sync_status', $status);

            // Log start
            error_log("BB_CRON: Starting verification sync of {$total} files");
            return;
        }

        // Process next batch
        $batch_size = 10; // Small batch size for CRON (to avoid timeouts)
        $this->process_batch($status, $batch_size);
    }

    /**
     * Process a batch of files for verification
     */
    private function process_batch(&$status, $batch_size) {
        global $wpdb;

        // Get attachments that haven't been verified yet
        $attachments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_parent FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND (SELECT meta_value FROM {$wpdb->postmeta}
                      WHERE post_id = ID AND meta_key = '_bb_uploaded') IS NULL
                 LIMIT %d OFFSET %d",
                $batch_size,
                $status['last_batch_offset']
            )
        );

        if (empty($attachments)) {
            // All files processed
            $status['running'] = false;
            $status['completed_at'] = current_time('mysql');
            update_option('bb_verify_sync_status', $status);

            error_log("BB_CRON: Verification completed. Verified: {$status['verified_files']}, Failed: {$status['failed_files']}");
            return;
        }

        // Initialize uploader
        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            get_option('bb_key_id'),
            get_option('bb_app_key')
        );

        // Check each attachment
        foreach ($attachments as $attachment) {
            $file = get_post_meta($attachment->ID, '_wp_attached_file', true);

            if (!$file) {
                $status['failed_files']++;
                continue;
            }

            // Check if file exists on B2
            if ($uploader->file_exists_on_b2($file)) {
                update_post_meta($attachment->ID, '_bb_uploaded', 1);
                $status['verified_files']++;
            } else {
                $status['failed_files']++;
            }
        }

        // Update progress
        $status['last_batch_offset'] += count($attachments);
        $status['last_run_at'] = current_time('mysql');
        update_option('bb_verify_sync_status', $status);

        // Log progress
        error_log("BB_CRON: Processed batch. Progress: {$status['verified_files']}/{$status['total_files']} verified");
    }

    /**
     * Get current sync status
     */
    public static function get_status() {
        return get_option('bb_verify_sync_status', array(
            'running' => false,
            'total_files' => 0,
            'verified_files' => 0,
            'failed_files' => 0,
            'started_at' => null,
            'completed_at' => null,
            'last_run_at' => null
        ));
    }

    /**
     * Start verification sync
     */
    public static function start_sync() {
        $status = array(
            'running' => true,
            'total_files' => 0,
            'verified_files' => 0,
            'failed_files' => 0,
            'last_batch_offset' => 0,
            'started_at' => current_time('mysql'),
            'last_run_at' => null,
            'completed_at' => null
        );
        update_option('bb_verify_sync_status', $status);

        // Trigger the cron immediately
        do_action('bb_verify_sync_cron');
    }

    /**
     * Stop verification sync
     */
    public static function stop_sync() {
        $status = get_option('bb_verify_sync_status', array());
        $status['running'] = false;
        update_option('bb_verify_sync_status', $status);
    }
}
