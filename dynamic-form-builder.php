<?php
/**
 * Plugin Name: Dynamic Form & Document Generator
 * Description: Create dynamic forms, integrate with WooCommerce, generate PDF documents
 * Version: 1.1.0
 * Author: Sahar
 */

if (!defined('ABSPATH')) exit;

// Plugin constants
define('DFB_VERSION', '1.1.0');
define('DFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DFB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include files
require_once DFB_PLUGIN_DIR . 'includes/database.php';
require_once DFB_PLUGIN_DIR . 'admin/admin-init.php';
require_once DFB_PLUGIN_DIR . 'includes/shortcodes.php';
require_once DFB_PLUGIN_DIR . 'includes/woocommerce.php';
require_once DFB_PLUGIN_DIR . 'includes/template-engine.php';
require_once DFB_PLUGIN_DIR . 'includes/pdf.php';

// Optional includes (avoid fatal if file missing during early development)
$dfb_form_handler = DFB_PLUGIN_DIR . 'includes/form-handler.php';
if (file_exists($dfb_form_handler)) {
    require_once $dfb_form_handler;
}

// Activation hook
register_activation_hook(__FILE__, 'dfb_activate_plugin');