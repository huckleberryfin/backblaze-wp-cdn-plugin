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
                }
            }
        }

        return $metadata;
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
        // Check if WebP file exists locally
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return false;
        }

        $webp_file = $this->get_webp_path($file);
        return file_exists($webp_file);
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

        foreach ($attachments as $attachment_id) {
            $file = get_attached_file($attachment_id);

            if (!file_exists($file)) {
                $skipped++;
                continue;
            }

            $webp_path = $this->get_webp_path($file);

            // Skip if WebP already exists
            if (file_exists($webp_path)) {
                $skipped++;
                continue;
            }

            $mime_type = get_post_mime_type($attachment_id);
            $result = $this->create_webp_image($file, $webp_path, $mime_type);

            if ($result) {
                // Upload to Backblaze
                $this->upload_webp_to_backblaze($webp_path);

                // Convert thumbnails
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (isset($metadata['sizes'])) {
                    $base_dir = dirname($file);

                    foreach ($metadata['sizes'] as $size => $data) {
                        $thumb_path = $base_dir . '/' . $data['file'];
                        $thumb_webp_path = $this->get_webp_path($thumb_path);

                        if (file_exists($thumb_path) && !file_exists($thumb_webp_path)) {
                            $this->create_webp_image($thumb_path, $thumb_webp_path, $data['mime-type']);
                            $this->upload_webp_to_backblaze($thumb_webp_path);
                        }
                    }
                }

                $converted++;
            }
        }

        return array(
            'success' => true,
            'converted' => $converted,
            'skipped' => $skipped,
            'has_more' => count($attachments) === $batch_size
        );
    }

    public function get_webp_stats() {
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => array('image/jpeg', 'image/jpg', 'image/png'),
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $attachments = get_posts($args);
        $total = count($attachments);
        $converted = 0;
        $total_original_size = 0;
        $total_webp_size = 0;

        foreach ($attachments as $attachment_id) {
            $file = get_attached_file($attachment_id);
            if (!$file || !file_exists($file)) {
                continue;
            }

            $webp_path = $this->get_webp_path($file);

            if (file_exists($webp_path)) {
                $converted++;
                $total_original_size += filesize($file);
                $total_webp_size += filesize($webp_path);
            }
        }

        $savings_percent = $total_original_size > 0
            ? round((($total_original_size - $total_webp_size) / $total_original_size) * 100, 1)
            : 0;

        return array(
            'total_images' => $total,
            'converted' => $converted,
            'remaining' => $total - $converted,
            'original_size' => $total_original_size,
            'webp_size' => $total_webp_size,
            'savings_bytes' => $total_original_size - $total_webp_size,
            'savings_percent' => $savings_percent
        );
    }
}
