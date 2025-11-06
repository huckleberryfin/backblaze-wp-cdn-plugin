<?php
if (!defined('ABSPATH')) exit;

class BB_Media_Handler {
    private $uploader;

    public function __construct() {
        add_filter('wp_handle_upload', array($this, 'upload_to_backblaze'));
        add_filter('wp_generate_attachment_metadata', array($this, 'upload_thumbnails'), 10, 2);
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'filter_image_src'), 10, 4);
        add_filter('wp_calculate_image_srcset', array($this, 'filter_srcset'), 10, 5);
        add_filter('upload_dir', array($this, 'filter_upload_dir'), 10, 1);
        add_filter('stylesheet_uri', array($this, 'prevent_css_cdn'), 10, 2);
        add_filter('style_loader_src', array($this, 'prevent_css_cdn'), 10, 2);
        add_filter('the_content', array($this, 'replace_image_urls_in_content'), 999);
        add_action('init', array($this, 'start_output_buffering'));
    }

    public function start_output_buffering() {
        // Disabled: Output buffer replacement doesn't have attachment context
        // Individual attachment filters handle URL replacement with proper _bb_uploaded checks
        // if (!is_admin()) {
        //     ob_start(array($this, 'replace_urls_in_output'));
        // }
    }

    public function replace_urls_in_output($buffer) {
        $cdn_url = get_option('bb_cdn_url');
        $site_url = get_site_url();

        if (!$cdn_url) {
            return $buffer;
        }

        // Replace image URLs in src and srcset attributes, but skip CSS and JS files
        // This handles img tags, picture sources, and other image elements
        $buffer = preg_replace_callback(
            '/(' . preg_quote($site_url, '/') . ')\/wp-content\/uploads\/([^"\'>\s]*\.(jpg|jpeg|png|gif|webp|svg))(?=["\'\s>])/i',
            function($matches) use ($cdn_url) {
                return $cdn_url . '/' . $matches[2];
            },
            $buffer
        );

        // Also handle srcset attributes with multiple URLs
        $buffer = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            function($matches) use ($site_url, $cdn_url) {
                $srcset = $matches[1];
                $srcset = preg_replace_callback(
                    '/' . preg_quote($site_url, '/') . '\/wp-content\/uploads\/([^"\'\s,]*\.(jpg|jpeg|png|gif|webp|svg))/i',
                    function($inner) use ($cdn_url) {
                        return $cdn_url . '/' . $inner[1];
                    },
                    $srcset
                );
                return 'srcset="' . $srcset . '"';
            },
            $buffer
        );

        return $buffer;
    }

    public function upload_to_backblaze($upload) {
        $key_id = get_option('bb_key_id');
        $app_key = get_option('bb_app_key');

        if (!$key_id || !$app_key) {
            return $upload;
        }

        require_once plugin_dir_path(__FILE__) . 'class-uploader.php';

        $this->uploader = new BB_Uploader(
            get_option('bb_bucket'),
            get_option('bb_endpoint'),
            get_option('bb_cdn_url'),
            $key_id,
            $app_key
        );

        $file_path = $upload['file'];
        $wp_upload_dir = wp_upload_dir();
        $relative_path = str_replace($wp_upload_dir['basedir'] . '/', '', $file_path);

        $result = $this->uploader->upload_file($file_path, $relative_path);

        if ($result['success']) {
            $upload['url'] = $this->uploader->get_cdn_url() . '/' . $relative_path;
            // Store success flag - will be saved after attachment is created
            $upload['bb_uploaded'] = true;
        }

        return $upload;
    }

    public function upload_thumbnails($metadata, $attachment_id) {
        $key_id = get_option('bb_key_id');
        $app_key = get_option('bb_app_key');

        if (!$key_id || !$app_key || !isset($metadata['sizes'])) {
            return $metadata;
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
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $base_dir = dirname($wp_upload_dir['basedir'] . '/' . $file);
        $base_path = dirname($file);

        $all_success = true;

        // Upload each thumbnail
        foreach ($metadata['sizes'] as $size => $data) {
            $thumbnail_file = $base_dir . '/' . $data['file'];
            $thumbnail_path = $base_path . '/' . $data['file'];

            if (file_exists($thumbnail_file)) {
                $result = $uploader->upload_file($thumbnail_file, $thumbnail_path);
                if (!$result['success']) {
                    $all_success = false;
                }
            }
        }

        // Mark as uploaded to CDN if all thumbnails succeeded
        if ($all_success) {
            update_post_meta($attachment_id, '_bb_uploaded', 1);
        }

        return $metadata;
    }

    public function filter_attachment_url($url, $attachment_id) {
        // Don't replace URLs for Elementor CSS files
        if (strpos($url, '/elementor/css/') !== false) {
            return $url;
        }

        // Only use CDN if file was uploaded to Backblaze
        if (!get_post_meta($attachment_id, '_bb_uploaded', true)) {
            return $url;
        }

        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url) {
            return $url;
        }

        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file) {
            return $cdn_url . '/' . $file;
        }
        return $url;
    }

    public function filter_image_src($image, $attachment_id, $size, $icon) {
        // Don't replace URLs for Elementor CSS files
        if ($image && isset($image[0]) && strpos($image[0], '/elementor/css/') !== false) {
            return $image;
        }
        
        if (!$image) {
            return $image;
        }

        // Only use CDN if file was uploaded to Backblaze
        if (!get_post_meta($attachment_id, '_bb_uploaded', true)) {
            return $image;
        }

        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url) {
            return $image;
        }

        $wp_upload_dir = wp_upload_dir();
        $base_url = $wp_upload_dir['baseurl'];

        if (isset($image[0])) {
            $image[0] = str_replace($base_url, $cdn_url, $image[0]);
        }

        return $image;
    }

    public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url) {
            return $sources;
        }

        // Only use CDN if file was verified and uploaded to Backblaze
        if (!get_post_meta($attachment_id, '_bb_uploaded', true)) {
            return $sources;
        }

        $excluded_extensions = $this->get_excluded_extensions();
        $wp_upload_dir = wp_upload_dir();
        $base_url = $wp_upload_dir['baseurl'];

        foreach ($sources as $width => $source) {
            // Check if URL has excluded extension
            if ($this->should_exclude_url($source['url'], $excluded_extensions)) {
                continue;
            }

            $sources[$width]['url'] = str_replace($base_url, $cdn_url, $source['url']);
        }

        return $sources;
    }

    private function get_excluded_extensions() {
        $excluded = get_option('bb_excluded_extensions', 'css,js');
        return array_map('trim', explode(',', strtolower($excluded)));
    }

    private function should_exclude_url($url, $excluded_extensions) {
        // Check Elementor paths
        if (strpos($url, '/elementor/css/') !== false) {
            return true;
        }

        // Check for excluded file extensions
        foreach ($excluded_extensions as $ext) {
            if (empty($ext)) continue;
            if (preg_match('/\.' . preg_quote($ext) . '(\?|$)/i', $url)) {
                return true;
            }
        }

        return false;
    }

    public function filter_upload_dir($uploads) {
        // Only modify for actual image uploads, not for Elementor CSS generation
        if (defined('DOING_AJAX') || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'elementor') !== false)) {
            return $uploads;
        }
        
        return $uploads;
    }

    public function prevent_css_cdn($src, $handle = '') {
        // Prevent CDN URL substitution for excluded file types
        $excluded_extensions = $this->get_excluded_extensions();

        if ($this->should_exclude_url($src, $excluded_extensions)) {
            $cdn_url = get_option('bb_cdn_url');
            $site_url = get_site_url();
            // Ensure excluded files use site URL, not CDN
            if (strpos($src, $cdn_url) !== false) {
                return str_replace($cdn_url, $site_url, $src);
            }
        }
        return $src;
    }

    public function replace_image_urls_in_content($content) {
        $cdn_url = get_option('bb_cdn_url');
        if (!$cdn_url) {
            return $content;
        }

        // Get the current site URL dynamically
        $site_url = get_site_url();

        // Replace image URLs in wp-content/uploads, but NOT CSS/JS files
        // Match image file extensions only
        $pattern = preg_quote($site_url, '/') . '\/wp-content\/uploads\/([^"\'>\s]*\.(jpg|jpeg|png|gif|webp|svg))';
        $content = preg_replace_callback('/' . $pattern . '/i', function($matches) use ($cdn_url) {
            return $cdn_url . '/' . $matches[1];
        }, $content);

        return $content;
    }
}