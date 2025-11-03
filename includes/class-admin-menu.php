<?php
if (!defined('ABSPATH')) exit;

class BB_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_filter('plugin_action_links_backblaze-auto-upload/backblaze-auto-upload.php', array($this, 'add_settings_link'));
    }

    public function add_menu_pages() {
        // Main menu
        add_menu_page(
            'Backblaze CDN',
            'Backblaze CDN',
            'manage_options',
            'backblaze-cdn',
            array($this, 'settings_page'),
            'dashicons-cloud-upload',
            65
        );

        // Settings submenu (same as main)
        add_submenu_page(
            'backblaze-cdn',
            'Settings',
            'Settings',
            'manage_options',
            'backblaze-cdn',
            array($this, 'settings_page')
        );

        // Bulk Sync submenu
        add_submenu_page(
            'backblaze-cdn',
            'Bulk Sync',
            'Bulk Sync',
            'manage_options',
            'backblaze-bulk-sync',
            array($this, 'bulk_sync_page')
        );
    }

    public function settings_page() {
        // Load settings class page
        $settings = new BB_Settings();
        $settings->settings_page();
    }

    public function bulk_sync_page() {
        // Load bulk sync class page
        $bulk_sync = new BB_Bulk_Sync();
        $bulk_sync->sync_page();
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=backblaze-cdn') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}