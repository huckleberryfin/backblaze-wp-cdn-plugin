<?php
if (!defined('ABSPATH')) exit;

class BB_WebP_Converter {

    public function __construct() {
        // Hook into WordPress image upload process
        add_filter('wp_handle_upload', array($this, 'convert_to_webp'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'convert_thumbnails_to_webp'), 20, 2);

        // Filter URLs to serve WebP when supported
        add_filter('wp_get_attachment_url', array($this, 'maybe_serve_webp'), 20, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'maybe_serve_webp_src'), 20, 4);

        // Add picture element support for better fallback
        add_filter('wp_get_attachment_image', array($this, 'add_picture_element'), 20, 5);
    }

    public function is_webp_enabled() {
        return get_option('bb_webp_enabled', 0) == 1;
    }

    public function get_webp_quality() {
        return intval(get_option('bb_webp_quality', 80));
    }

    public function convert_to_webp($upload, $context = 'upload') {
        if (!$this->is_webp_enabled()) {
            return $upload;
        }

        $file_path = $upload['file'];
        $file_type = $upload['type'];

        // Only convert image types we support
        if (!in_array($file_type, array('image/jpeg', 'image/jpg', 'image/png'))) {
            return $upload;
        }

        // Check if GD library has WebP support
        if (!function_exists('imagewebp')) {
            return $upload;
        }

        $webp_path = $this->get_webp_path($file_path);
        $result = $this->create_webp_image($file_path, $webp_path, $file_type);

        if ($result) {
            // Upload WebP version to Backblaze
            $this->upload_webp_to_backblaze($webp_path);

            // Mark attachment with WebP metadata (called from wp_generate_attachment_metadata hook)
            // We'll set metadata when attachment ID is available
        }

        return $upload;
    }

    public function convert_thumbnails_to_webp($metadata, $attachment_id) {
        if (!$this->is_webp_enabled() || !isset($metadata['sizes'])) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $base_dir = dirname($upload_dir['basedir'] . '/' . $file);
        $any_converted = false;

        foreach ($metadata['sizes'] as $size => $data) {
            $thumbnail_path = $base_dir . '/' . $data['file'];

            if (!file_exists($thumbnail_path)) {
                continue;
            }

            // Create WebP version
            $webp_path = $this->get_webp_path($thumbnail_path);
            $file_type = $data['mime-type'];

            if (in_array($file_type, array('image/jpeg', 'image/jpg', 'image/png'))) {
                $result = $this->create_webp_image($thumbnail_path, $webp_path, $file_type);

                if ($result) {
                    // Upload WebP thumbnail to Backblaze
                    $this->upload_webp_to_backblaze($webp_path);

                    // Store WebP info in metadata
                    $metadata['sizes'][$size]['webp_file'] = basename($webp_path);
                    $any_converted = true;
                }
            }
        }

        // Mark attachment as having WebP conversion if any were created
        if ($any_converted) {
            $this->mark_webp_converted($attachment_id);
        }

        return $metadata;
    }

    private function mark_webp_converted($attachment_id) {
        update_post_meta($attachment_id, '_bb_has_webp', 1);
        // Clear stats cache since conversion status changed
        delete_transient('bb_webp_stats');
    }

    private function create_webp_image($source_path, $dest_path, $mime_type) {
        $quality = $this->get_webp_quality();

        // Load image based on type
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = @imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source_path);
                // Preserve transparency
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
            default:
                return false;
        }

        if (!$image) {
            return false;
        }

        // Convert to WebP
        $result = @imagewebp($image, $dest_path, $quality);
        imagedestroy($image);

        return $result;
    }

    private function get_webp_path($original_path) {
        $path_info = pathinfo($original_path);
        return $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
    }

    private function upload_webp_to_backblaze($webp_path) {
        if (!file_exists($webp_path)) {
            return false;
        }

        $key_id = get_option('bb_key_id');
        $app_key = get_option('bb_app_key');

        if (!$key_id || !$app_key) {
            return false;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            $key_id,
            $app_key
        );

        $wp_upload_dir = wp_upload_dir();
        $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $webp_path);

        return $uploader->upload_file($webp_path, $relative_path);
    }

    public function maybe_serve_webp($url, $attachment_id) {
        if (!$this->is_webp_enabled() || !$this->browser_supports_webp()) {
            return $url;
        }

        // Only process CDN URLs
        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url || strpos($url, $cdn_url) === false) {
            return $url;
        }

        // Check if WebP version exists
        $webp_url = $this->convert_url_to_webp($url);

        if ($this->webp_exists($attachment_id, $url)) {
            return $webp_url;
        }

        return $url;
    }

    public function maybe_serve_webp_src($image, $attachment_id, $size, $icon) {
        if (!$image || !$this->is_webp_enabled() || !$this->browser_supports_webp()) {
            return $image;
        }

        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url || !isset($image[0]) || strpos($image[0], $cdn_url) === false) {
            return $image;
        }

        if ($this->webp_exists($attachment_id, $image[0])) {
            $image[0] = $this->convert_url_to_webp($image[0]);
        }

        return $image;
    }

    public function add_picture_element($html, $attachment_id, $size, $icon, $attr) {
        if (!$this->is_webp_enabled()) {
            return $html;
        }

        // Extract src from img tag
        if (!preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $html, $matches)) {
            return $html;
        }

        $original_src = $matches[1];
        $cdn_url = get_option('bb_cdn_url');

        // Only process CDN images
        if (!$cdn_url || strpos($original_src, $cdn_url) === false) {
            return $html;
        }

        // Check if WebP exists
        if (!$this->webp_exists($attachment_id, $original_src)) {
            return $html;
        }

        $webp_src = $this->convert_url_to_webp($original_src);

        // Wrap in picture element with WebP source
        $picture = '<picture>';
        $picture .= '<source type="image/webp" srcset="' . esc_attr($webp_src) . '">';
        $picture .= $html;
        $picture .= '</picture>';

        return $picture;
    }

    private function convert_url_to_webp($url) {
        return preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $url);
    }

    private function webp_exists($attachment_id, $url) {
        // Check postmeta first (faster, no CDN lookup)
        if (get_post_meta($attachment_id, '_bb_has_webp', true)) {
            return true;
        }

        // Fall back to CDN check if metadata not set
        $webp_url = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $url);
        return $this->file_exists_on_cdn($webp_url);
    }

    private function browser_supports_webp() {
        // Check Accept header
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false) {
            return true;
        }

        // Check for known WebP-supporting browsers via User-Agent
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $ua = $_SERVER['HTTP_USER_AGENT'];

            // Chrome, Edge, Firefox, Opera support WebP
            if (preg_match('/(Chrome|CriOS|Edge|Firefox|OPR)/i', $ua)) {
                return true;
            }

            // Safari 14+ supports WebP
            if (preg_match('/Version\/(\d+).*Safari/i', $ua, $matches)) {
                return intval($matches[1]) >= 14;
            }
        }

        return false;
    }

    public function bulk_convert_existing_images($batch_size = 10, $offset = 0) {
        if (!$this->is_webp_enabled()) {
            return array(
                'success' => false,
                'message' => 'WebP conversion is not enabled'
            );
        }

        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/jpg', 'image/png'),
            'post_status' => 'inherit',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids'
        );

        $attachments = get_posts($args);
        $converted = 0;
        $skipped = 0;
        $details = array();
        $cdn_url = get_option('bb_cdn_url');

        foreach ($attachments as $attachment_id) {
            $file = get_attached_file($attachment_id);
            $filename = basename($file);
            $title = get_the_title($attachment_id);
            $mime_type = get_post_mime_type($attachment_id);

            // Build CDN URL for the original image
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file);
            $image_cdn_url = rtrim($cdn_url, '/') . '/' . $relative_path;
            $webp_cdn_url = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $image_cdn_url);

            // Check if WebP already exists on CDN
            if ($this->file_exists_on_cdn($webp_cdn_url)) {
                // WebP already exists - mark it as converted in database
                $this->mark_webp_converted($attachment_id);

                $converted++;
                $details[] = array(
                    'file' => $filename,
                    'title' => $title,
                    'status' => 'converted',
                    'reason' => 'WebP version already exists on CDN (marked as converted)'
                );
                continue;
            }

            // Download image from CDN to temporary location
            $temp_file = $this->download_from_cdn($image_cdn_url);

            if (!$temp_file) {
                $skipped++;
                $details[] = array(
                    'file' => $filename,
                    'title' => $title,
                    'status' => 'skipped',
                    'reason' => 'Failed to download from CDN'
                );
                continue;
            }

            // Create temporary WebP file
            $temp_webp = wp_tempnam() . '.webp';
            $result = $this->create_webp_image($temp_file, $temp_webp, $mime_type);

            if ($result) {
                // Upload WebP to Backblaze
                $this->upload_webp_to_backblaze_by_path($temp_webp, $webp_cdn_url);

                // Mark attachment with WebP metadata
                $this->mark_webp_converted($attachment_id);

                $converted++;
                $details[] = array(
                    'file' => $filename,
                    'title' => $title,
                    'status' => 'converted',
                    'reason' => 'Successfully created WebP on CDN'
                );
            } else {
                $skipped++;
                $details[] = array(
                    'file' => $filename,
                    'title' => $title,
                    'status' => 'skipped',
                    'reason' => 'WebP conversion failed (check server GD library)'
                );
            }

            // Clean up temporary files
            @unlink($temp_file);
            @unlink($temp_webp);
        }

        return array(
            'success' => true,
            'converted' => $converted,
            'skipped' => $skipped,
            'has_more' => count($attachments) === $batch_size,
            'details' => $details
        );
    }

    private function file_exists_on_cdn($cdn_url) {
        $response = wp_remote_head($cdn_url, array(
            'timeout' => 5,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        return $http_code === 200;
    }

    private function download_from_cdn($cdn_url) {
        $response = wp_remote_get($cdn_url, array(
            'timeout' => 30,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        $temp_file = wp_tempnam();
        $file = fopen($temp_file, 'w');
        if (!$file) {
            return false;
        }

        fwrite($file, $body);
        fclose($file);

        return $temp_file;
    }

    private function upload_webp_to_backblaze_by_path($webp_path, $cdn_url) {
        if (!file_exists($webp_path)) {
            return false;
        }

        $key_id = get_option('bb_key_id');
        $app_key = get_option('bb_app_key');

        if (!$key_id || !$app_key) {
            return false;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        // Extract relative path from CDN URL
        $cdn_url_base = get_option('bb_cdn_url');
        $relative_path = str_replace(rtrim($cdn_url_base, '/') . '/', '', $cdn_url);

        $uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            $cdn_url_base,
            $key_id,
            $app_key
        );

        return $uploader->upload_file($webp_path, $relative_path);
    }

    public function get_webp_stats() {
        // Check cache first
        $cached_stats = get_transient('bb_webp_stats');
        if ($cached_stats !== false) {
            return $cached_stats;
        }

        global $wpdb;

        // Count total JPEG/PNG images
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='attachment'
             AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
             AND post_status='inherit'"
        );

        // Count converted images (have _bb_has_webp postmeta)
        $converted = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type='attachment'
             AND p.post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
             AND p.post_status='inherit'
             AND pm.meta_key='_bb_has_webp'
             AND pm.meta_value='1'"
        );

        $total = intval($total);
        $converted = intval($converted);
        $remaining = $total - $converted;

        $stats = array(
            'total_images' => $total,
            'converted' => $converted,
            'remaining' => $remaining,
            'original_size' => 0,
            'webp_size' => 0,
            'savings_bytes' => 0,
            'savings_percent' => 0
        );

        // Cache for 1 hour
        set_transient('bb_webp_stats', $stats, 3600);

        return $stats;
    }

    public function refresh_webp_stats() {
        // Refresh stats by querying database (fast and accurate)
        global $wpdb;

        // Count total JPEG/PNG images
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='attachment'
             AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
             AND post_status='inherit'"
        );

        // Count converted images (have _bb_has_webp postmeta)
        $converted = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type='attachment'
             AND p.post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
             AND p.post_status='inherit'
             AND pm.meta_key='_bb_has_webp'
             AND pm.meta_value='1'"
        );

        $total = intval($total);
        $converted = intval($converted);
        $remaining = $total - $converted;

        $stats = array(
            'total_images' => $total,
            'converted' => $converted,
            'remaining' => $remaining,
            'original_size' => 0,
            'webp_size' => 0,
            'savings_bytes' => 0,
            'savings_percent' => 0
        );

        // Cache for 1 hour
        set_transient('bb_webp_stats', $stats, 3600);

        return $stats;
    }
}
