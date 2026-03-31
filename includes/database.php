<?php
if (!defined('ABSPATH')) exit;

function dfb_activate_plugin() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Table 1: Forms
    $table_forms = $wpdb->prefix . 'dfb_forms';
    $sql_forms = "CREATE TABLE IF NOT EXISTS $table_forms (
        id INT NOT NULL AUTO_INCREMENT,
        form_name VARCHAR(255) NOT NULL,
        woo_product_id INT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY woo_product_unique (woo_product_id)
    ) $charset_collate;";
    
    // Table 2: Questions
    $table_questions = $wpdb->prefix . 'dfb_questions';
    $sql_questions = "CREATE TABLE IF NOT EXISTS $table_questions (
        id INT NOT NULL AUTO_INCREMENT,
        form_id INT NOT NULL,
        question_title VARCHAR(255) NOT NULL,
        question_description TEXT,
        video_url VARCHAR(500),
        image_url VARCHAR(500),
        input_type VARCHAR(50) NOT NULL,
        input_options TEXT,
        is_required TINYINT(1) DEFAULT 1,
        question_order INT DEFAULT 0,
        PRIMARY KEY (id),
        KEY form_id (form_id)
    ) $charset_collate;";
    
    // Table 3: Document Templates
    $table_templates = $wpdb->prefix . 'dfb_templates';
    $sql_templates = "CREATE TABLE IF NOT EXISTS $table_templates (
        id INT NOT NULL AUTO_INCREMENT,
        form_id INT NOT NULL,
        template_content LONGTEXT,
        PRIMARY KEY (id),
        KEY form_id (form_id)
    ) $charset_collate;";
    
    // Table 4: Form Responses
    $table_responses = $wpdb->prefix . 'dfb_responses';
    $sql_responses = "CREATE TABLE IF NOT EXISTS $table_responses (
        id INT NOT NULL AUTO_INCREMENT,
        form_id INT NOT NULL,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        user_email VARCHAR(100),
        user_name VARCHAR(255),
        answers LONGTEXT,
        order_id INT DEFAULT NULL,
        document_path VARCHAR(500),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY form_id (form_id),
        KEY order_id (order_id),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_forms);
    dbDelta($sql_questions);
    dbDelta($sql_templates);
    dbDelta($sql_responses);
}

/**
 * Ensure schema updates are applied on existing installs.
 */
function dfb_maybe_upgrade_schema() {
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return;
    }

    $installed_version = get_option('dfb_db_version', '');
    if ($installed_version === DFB_VERSION) {
        return;
    }

    dfb_activate_plugin();
    update_option('dfb_db_version', DFB_VERSION);
}
add_action('init', 'dfb_maybe_upgrade_schema');

/**
 * Backward-compatible schema patch for existing installs.
 * Some sites may have old tables without newer columns.
 */
function dfb_ensure_responses_table_columns() {
    global $wpdb;

    $table = $wpdb->prefix . 'dfb_responses';

    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($table_exists !== $table) {
        return;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    if (!is_array($columns) || empty($columns)) {
        return;
    }

    if (!in_array('user_id', $columns, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER form_id");
        $wpdb->query("ALTER TABLE {$table} ADD KEY user_id (user_id)");
    }

    if (!in_array('order_id', $columns, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN order_id INT DEFAULT NULL AFTER answers");
        $wpdb->query("ALTER TABLE {$table} ADD KEY order_id (order_id)");
    }

    if (!in_array('document_path', $columns, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN document_path VARCHAR(500) DEFAULT NULL AFTER order_id");
    }
}
add_action('init', 'dfb_ensure_responses_table_columns', 25);