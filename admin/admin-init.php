<?php
if (!defined('ABSPATH')) exit;

// Add admin menu
add_action('admin_menu', 'dfb_add_admin_menu');

function dfb_add_admin_menu() {
    // Main menu
    add_menu_page(
        'Form Builder',           // Page title
        'Form Builder',           // Menu title
        'manage_options',         // Capability
        'dfb-forms',              // Menu slug
        'dfb_forms_list_page',    // Callback function
        'dashicons-feedback',     // Icon
        30                        // Position
    );
    
    // Submenu - All Forms
    add_submenu_page(
        'dfb-forms',
        'All Forms',
        'All Forms',
        'manage_options',
        'dfb-forms',
        'dfb_forms_list_page'
    );
    
    // Submenu - Add New Form
    add_submenu_page(
        'dfb-forms',
        'Add New Form',
        'Add New Form',
        'manage_options',
        'dfb-add-form',
        'dfb_add_form_page'
    );
    
    // Submenu - Settings
    add_submenu_page(
        'dfb-forms',
        'Settings',
        'Settings',
        'manage_options',
        'dfb-settings',
        'dfb_settings_page'
    );

    add_submenu_page(
        'dfb-forms',
        'Documents',
        'Documents',
        'manage_options',
        'dfb-documents',
        'dfb_documents_page'
    );
}

// Load admin pages
require_once DFB_PLUGIN_DIR . 'admin/forms-list.php';
require_once DFB_PLUGIN_DIR . 'admin/add-form.php';
$dfb_settings_page = DFB_PLUGIN_DIR . 'admin/settings.php';
if (file_exists($dfb_settings_page)) {
    require_once $dfb_settings_page;
}

$dfb_documents_page = DFB_PLUGIN_DIR . 'admin/documents.php';
if (file_exists($dfb_documents_page)) {
    require_once $dfb_documents_page;
}

if (!function_exists('dfb_settings_page')) {
    function dfb_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        echo '<div class="wrap"><h1>Settings</h1><p>Settings page will be available soon.</p></div>';
    }
}

// Enqueue admin styles and scripts
add_action('admin_enqueue_scripts', 'dfb_admin_scripts');

function dfb_admin_scripts($hook) {
    // Only load on our plugin pages
    if (strpos($hook, 'dfb-') === false) return;
    
    wp_enqueue_style('dfb-admin-css', DFB_PLUGIN_URL . 'assets/css/admin.css', [], DFB_VERSION);
    wp_enqueue_script('dfb-admin-js', DFB_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], DFB_VERSION, true);
    
    // WordPress media uploader
    wp_enqueue_media();
    
    // Pass AJAX URL to JavaScript
    wp_localize_script('dfb-admin-js', 'dfbAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('dfb_nonce')
    ]);
}