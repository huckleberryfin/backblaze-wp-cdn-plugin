<?php
/**
 * Plugin Name: Backblaze Auto Upload
 * Description: Automatically uploads media files to Backblaze B2 and serves them via CDN
 * Version: 1.0
 * Author: Dimitri Nain
 */

if (!defined('ABSPATH')) exit;

// Load classes
require_once plugin_dir_path(__FILE__) . 'includes/class-uploader.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-media-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-bulk-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-menu.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-analytics.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-webp-converter.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-webp-admin.php';

// Initialize plugin
class BackblazeAutoUpload {
    public function __construct() {
        new BB_Settings();
        new BB_Media_Handler();
        new BB_Bulk_Sync();
        new BB_Admin_Menu();
        new BB_Analytics();
        new BB_WebP_Converter();
        new BB_WebP_Admin();
    }
}

new BackblazeAutoUpload();