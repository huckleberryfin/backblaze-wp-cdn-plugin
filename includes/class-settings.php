<?php
if (!defined('ABSPATH')) exit;

class BB_Settings {
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }



    public function register_settings() {
        register_setting('backblaze_settings', 'bb_bucket');
        register_setting('backblaze_settings', 'bb_endpoint');
        register_setting('backblaze_settings', 'bb_cdn_url');
        register_setting('backblaze_settings', 'bb_key_id');
        register_setting('backblaze_settings', 'bb_app_key');
        
        if (isset($_POST['test_upload']) && check_admin_referer('backblaze_test')) {
            add_action('admin_notices', array($this, 'display_test_results'));
        }
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Backblaze CDN Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('backblaze_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>Bucket Name</th>
                        <td><input type="text" name="bb_bucket" value="<?php echo esc_attr(get_option('bb_bucket', 'deck-uploads')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>S3 Endpoint</th>
                        <td><input type="text" name="bb_endpoint" value="<?php echo esc_attr(get_option('bb_endpoint', 's3.us-east-005.backblazeb2.com')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>CDN URL</th>
                        <td><input type="text" name="bb_cdn_url" value="<?php echo esc_attr(get_option('bb_cdn_url', 'https://cdn.deckandco.com/file/deck-uploads')); ?>" class="regular-text">
                        <p class="description">Full CDN URL without trailing slash</p></td>
                    </tr>
                    <tr>
                        <th>Backblaze Key ID</th>
                        <td><input type="text" name="bb_key_id" value="<?php echo esc_attr(get_option('bb_key_id')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Backblaze Application Key</th>
                        <td><input type="password" name="bb_app_key" value="<?php echo esc_attr(get_option('bb_app_key')); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Test Upload</h2>
            <form method="post">
                <?php wp_nonce_field('backblaze_test'); ?>
                <p>Click the button below to test if uploads are working correctly.</p>
                <input type="submit" name="test_upload" class="button button-secondary" value="Run Upload Test">
            </form>
        </div>
        <?php
    }

    public function display_test_results() {
        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';
        
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
        unlink($temp_file);
        
        if ($result['success']) {
            $cdn_url = $uploader->get_cdn_url() . '/' . $test_path;
            echo '<div class="notice notice-success is-dismissible">';
            echo '<h3>✓ Upload Successful!</h3>';
            echo '<p><strong>File uploaded to:</strong> ' . esc_html($test_path) . '</p>';
            echo '<p><strong>CDN URL:</strong> <a href="' . esc_url($cdn_url) . '" target="_blank">' . esc_html($cdn_url) . '</a></p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<h3>✗ Upload Failed</h3>';
            echo '<p><strong>HTTP Code:</strong> ' . $result['http_code'] . '</p>';
            echo '<p><strong>Response:</strong> ' . esc_html($result['response']) . '</p>';
            echo '</div>';
        }
    }
}